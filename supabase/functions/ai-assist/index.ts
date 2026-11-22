// Edge Function: ai-assist
//
//   { action: "review",  document_id, rounds? }    machine review of a translation (suggestions only)
//   { action: "rewrite", document_id, segment_ids?, style, instruction? }   Persian rewrite suggestions
//   { action: "test",    task? }                   admin: checks the saved provider/model/key with a tiny call
//
// review runs in the background (answers 202) and writes suggestions to segments.ai_review_text through
// the service-only RPC apply_ai_review(); the app shows them next to each paragraph. It is called by
// translate-document (service key) right after a translation, and by the "machine review" button.
// rewrite answers directly with the suggested texts; nothing is stored.
//
// Provider, model and key come from the app (Admin > AI service) or the secrets
// GEMINI_API_KEY / OPENAI_API_KEY / ANTHROPIC_API_KEY / OPENAI_COMPAT_API_KEY.
// Deploy with the normal settings (JWT verification on); the service key is accepted for internal calls.

import { createClient, type SupabaseClient } from "npm:@supabase/supabase-js@2";
import { callLlm, LlmError, loadLlmConfig, type LlmConfig, type Task } from "./llm.ts";
import {
  chunk,
  DEFAULT_REVIEW_PROMPT,
  DEFAULT_REWRITE_PROMPT,
  fillTemplate,
  glossaryBlock,
  matchGlossary,
  parseModelJson,
  PLACEHOLDER,
  REWRITE_STYLES,
  validateReview,
  validateRewrite,
} from "./lib.ts";

declare const EdgeRuntime: { waitUntil(p: Promise<unknown>): void } | undefined;

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const ALLOWED_ORIGIN = Deno.env.get("ALLOWED_ORIGIN") ?? "*";
const DEFAULT_GEMINI_MODEL = "gemini-2.5-flash";
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const REVIEW_BUDGET_MS = 135_000;
const REWRITE_BUDGET_MS = 110_000;
const STALE_REVIEW_MS = 10 * 60_000;

const corsHeaders = {
  "Access-Control-Allow-Origin": ALLOWED_ORIGIN,
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};
const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { ...corsHeaders, "Content-Type": "application/json" } });
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));
const env = (k: string) => Deno.env.get(k);

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (req.method !== "POST") return json({ error: "method_not_allowed" }, 405);

  const authHeader = req.headers.get("Authorization");
  if (!authHeader) return json({ error: "missing_authorization" }, 401);
  const token = authHeader.replace(/^Bearer\s+/i, "");

  let role = "", uid = "";
  if (token === SERVICE_KEY) {
    role = "service";
  } else {
    const userClient = createClient(SUPABASE_URL, ANON_KEY, {
      global: { headers: { Authorization: authHeader } },
      auth: { persistSession: false },
    });
    const { data: u, error } = await userClient.auth.getUser();
    if (error || !u.user) return json({ error: "invalid_token" }, 401);
    const { data: p } = await userClient.from("profiles").select("role").eq("id", u.user.id).single();
    if (!p) return json({ error: "forbidden" }, 403);
    role = p.role; uid = u.user.id;
  }

  const body = await req.json().catch(() => null);
  if (!body || typeof body.action !== "string") return json({ error: "bad_request" }, 400);
  const admin = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { persistSession: false } });

  try {
    if (body.action === "test") return await handleTest(admin, role, body);
    if (body.action === "review") return await handleReview(admin, role, uid, body);
    if (body.action === "rewrite") return await handleRewrite(admin, role, body);
  } catch (e) {
    return json({ ok: false, error: e instanceof Error ? e.message : String(e) }, 200);
  }
  return json({ error: "unknown_action" }, 400);
});

// ---------------------------------------------------------------------------------- shared
async function getConfig(admin: SupabaseClient, task: Task): Promise<LlmConfig> {
  const cfg = await loadLlmConfig(admin, task, DEFAULT_GEMINI_MODEL, env);
  if (!cfg.apiKey) throw new Error(`${cfg.provider}_key_not_set: save the key in the app (Admin > AI service)`);
  if (!cfg.model) throw new Error(`${cfg.provider}_model_not_set: enter the model name in the app (Admin > AI service)`);
  return cfg;
}

async function getPrompt(admin: SupabaseClient, key: string, fallback: string): Promise<string> {
  const { data } = await admin.from("app_config").select("value").eq("key", key).maybeSingle();
  const v = (data?.value as string | undefined) ?? "";
  return v.includes(PLACEHOLDER) ? v : fallback;
}

type Res<T> = { ok: true; value: T[] } | { ok: false; error: string };

/** Ask for JSON, validate it, and retry with a correction when the answer is unusable. */
async function callJson<T>(
  cfg: LlmConfig,
  makePrompt: (correction?: string) => string,
  validate: (raw: unknown) => Res<T>,
): Promise<T[]> {
  let correction: string | undefined, last = "unknown_error";
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const text = await callLlm(cfg, makePrompt(correction), { json: true, timeoutMs: 90_000 });
      let parsed: unknown;
      try { parsed = parseModelJson(text); } catch { last = "response_is_not_json"; correction = "Your previous answer was not valid JSON."; continue; }
      const v = validate(parsed);
      if (!v.ok) { last = v.error; correction = `Your previous answer was rejected: ${v.error}.`; continue; }
      return v.value;
    } catch (e) {
      if (e instanceof LlmError && e.retryable) { last = e.message; await sleep(Math.min(15_000, 3_000 * 2 ** (attempt - 1))); continue; }
      throw e;
    }
  }
  throw new Error(`gave_up_after_retries: ${last}`);
}

const withCorrection = (p: string, c?: string) => (c ? `${p}\n\nIMPORTANT CORRECTION: ${c}` : p);
const sameText = (a: string, b: string) => a.replace(/\s+/g, " ").trim() === b.replace(/\s+/g, " ").trim();

// ---------------------------------------------------------------------------------- test
async function handleTest(admin: SupabaseClient, role: string, body: { task?: string }) {
  if (role !== "admin") return json({ error: "forbidden" }, 403);
  const task = (["translate", "review", "rewrite"].includes(body.task ?? "") ? body.task : "translate") as Task;
  const cfg = await getConfig(admin, task);
  const t0 = Date.now();
  const reply = await callLlm(cfg, "Reply with exactly the single word: OK", { timeoutMs: 40_000 });
  return json({ ok: true, provider: cfg.provider, model: cfg.model, reply: reply.trim().slice(0, 60), ms: Date.now() - t0 });
}

// ---------------------------------------------------------------------------------- review
async function handleReview(admin: SupabaseClient, role: string, uid: string, body: { document_id?: string; rounds?: number }) {
  const documentId = body.document_id;
  if (typeof documentId !== "string" || !UUID_RE.test(documentId)) return json({ error: "document_id_required" }, 400);
  const { data: doc } = await admin.from("documents")
    .select("id,status,claimed_by,ai_review_state,ai_review_at").eq("id", documentId).maybeSingle();
  if (!doc) return json({ error: "document_not_found" }, 404);

  const okRole =
    role === "service" || role === "admin" ||
    (role === "supervisor" && doc.status === "awaiting_qa") ||
    (role === "translator" && doc.claimed_by === uid && ["in_progress", "changes_requested"].includes(doc.status));
  if (!okRole) return json({ error: "forbidden" }, 403);
  if (!["in_pool", "in_progress", "changes_requested", "awaiting_qa"].includes(doc.status)) {
    return json({ error: "wrong_status", detail: doc.status }, 409);
  }
  if (doc.ai_review_state === "running" && doc.ai_review_at && Date.now() - Date.parse(doc.ai_review_at) < STALE_REVIEW_MS) {
    return json({ error: "already_running" }, 409);
  }
  const rounds = Math.min(3, Math.max(1, Math.floor(Number(body.rounds ?? 1)) || 1));

  await admin.rpc("set_ai_review_state", { p_document: documentId, p_state: "running", p_error: null });
  const job = runReview(admin, documentId, rounds);
  if (typeof EdgeRuntime !== "undefined") EdgeRuntime.waitUntil(job); else await job;
  return json({ status: "started", document_id: documentId, rounds }, 202);
}

async function runReview(admin: SupabaseClient, documentId: string, rounds: number): Promise<void> {
  const t0 = Date.now();
  try {
    const cfg = await getConfig(admin, "review");
    const { data: segs, error } = await admin.from("segments")
      .select("position, source_text, ai_text, current_text").eq("document_id", documentId).order("position");
    if (error || !segs || !segs.length) throw new Error("segments_read_failed");

    let glossary: { source_term: string; target_term: string; note: string | null }[] = [];
    try {
      const g = await admin.from("glossary").select("source_term, target_term, note").eq("is_active", true);
      glossary = matchGlossary(g.data ?? [], segs.map((s) => s.source_text));
    } catch (e) { console.error("glossary skipped", e); }
    const gBlock = glossaryBlock(glossary);
    const template = await getPrompt(admin, "ai_prompt_review", DEFAULT_REVIEW_PROMPT);

    const original = new Map<number, string>();
    const cur = new Map<number, string>();
    for (const s of segs) { const t = (s.current_text ?? s.ai_text ?? "") as string; original.set(s.position, t); cur.set(s.position, t); }
    const notes = new Map<number, string[]>();
    let partial = false;

    for (let round = 1; round <= rounds && !partial; round++) {
      const input = segs
        .filter((s) => (cur.get(s.position) ?? "").trim() !== "")
        .map((s) => ({ id: s.position, fa: s.source_text as string, en: cur.get(s.position)! }));
      for (const part of chunk(input, 80, 36000)) {
        if (Date.now() - t0 > REVIEW_BUDGET_MS) { partial = true; break; }
        const out = await callJson(cfg, (c) => withCorrection(fillTemplate(template, part) + gBlock, c), (raw) => validateReview(raw, part));
        for (const r of out) {
          if (!sameText(r.translation, cur.get(r.id) ?? "")) cur.set(r.id, r.translation);
          if (r.note) { const a = notes.get(r.id) ?? []; if (!a.includes(r.note)) a.push(r.note); notes.set(r.id, a); }
        }
      }
      const items = segs.map((s) => ({
        pos: s.position,
        text: sameText(cur.get(s.position) ?? "", original.get(s.position) ?? "") ? "" : cur.get(s.position),
        note: (notes.get(s.position) ?? []).join(" | "),
      }));
      const { error: e2 } = await admin.rpc("apply_ai_review", { p_document: documentId, p_items: items, p_round: round });
      if (e2) throw new Error(`save_failed: ${e2.message}`);
    }
    await admin.rpc("set_ai_review_state", { p_document: documentId, p_state: "done", p_error: partial ? "بازبینی به‌دلیل محدودیت زمان فقط برای بخشی از متن انجام شد." : null });
  } catch (e) {
    const m = e instanceof Error ? e.message : String(e);
    console.error("review failed", documentId, m);
    await admin.rpc("set_ai_review_state", { p_document: documentId, p_state: "failed", p_error: m });
  }
}

// ---------------------------------------------------------------------------------- rewrite
async function handleRewrite(
  admin: SupabaseClient,
  role: string,
  body: { document_id?: string; segment_ids?: string[]; style?: string; instruction?: string },
) {
  if (!["admin", "news_editor", "reviewer"].includes(role)) return json({ error: "forbidden" }, 403);
  const documentId = body.document_id;
  if (typeof documentId !== "string" || !UUID_RE.test(documentId)) return json({ error: "document_id_required" }, 400);
  const { data: doc } = await admin.from("documents").select("id,status,source_kind").eq("id", documentId).maybeSingle();
  if (!doc) return json({ error: "document_not_found" }, 404);
  const okDoc = role === "news_editor"
    ? doc.status === "news_edit" && doc.source_kind === "news"
    : ["news_edit", "content_review", "review_rejected"].includes(doc.status);
  if (!okDoc) return json({ error: "wrong_status", detail: doc.status }, 409);

  let q = admin.from("segments").select("id, position, source_text").eq("document_id", documentId).order("position");
  if (Array.isArray(body.segment_ids) && body.segment_ids.length) {
    const ids = body.segment_ids.filter((x) => typeof x === "string" && UUID_RE.test(x)).slice(0, 120);
    q = q.in("id", ids);
  }
  const { data: segs, error } = await q;
  if (error) return json({ ok: false, error: error.message });
  const rows = (segs ?? []).filter((s) => (s.source_text as string).trim() !== "").slice(0, 120);
  if (!rows.length) return json({ ok: false, error: "no_text_selected" });

  let instruction = REWRITE_STYLES[body.style ?? ""]?.instruction ?? "";
  const extra = typeof body.instruction === "string" ? body.instruction.trim().slice(0, 500) : "";
  if (!instruction && !extra) return json({ ok: false, error: "style_required" });
  if (extra) instruction += (instruction ? "\nAdditional instruction from the editor: " : "") + extra;

  const cfg = await getConfig(admin, "rewrite");
  const template = await getPrompt(admin, "ai_prompt_rewrite", DEFAULT_REWRITE_PROMPT);
  const byPos = new Map(rows.map((r) => [r.position as number, r]));
  const input = rows.map((r) => ({ id: r.position as number, text: r.source_text as string }));
  const t0 = Date.now();
  const items: { id: string; text: string }[] = [];
  let partial = false, unchanged = 0;
  for (const part of chunk(input)) {
    if (Date.now() - t0 > REWRITE_BUDGET_MS) { partial = true; break; }
    const out = await callJson(cfg, (c) => withCorrection(fillTemplate(template, part, { INSTRUCTION: instruction }), c), (raw) => validateRewrite(raw, part));
    for (const o of out) {
      const row = byPos.get(o.id)!;
      if (sameText(o.text, row.source_text as string)) unchanged++;
      else items.push({ id: row.id as string, text: o.text });
    }
  }
  return json({ ok: true, items, unchanged, partial, model: `${cfg.provider}:${cfg.model}` });
}
