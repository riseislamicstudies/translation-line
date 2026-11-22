// Edge Function: translate-document
//
// Called by the web app right after a reviewer approves a document
// (documents.status = 'translating'). It answers 202 immediately and does the
// slow work (Gemini call, validation, retries) in the background. The result is
// written through the service-only RPC apply_ai_translation(), which moves the
// document to 'in_pool'; the notification trigger then tells the translators.
//
// Provider, model and key: chosen in the app (Admin > AI service; keys in app_secrets) or, as a
// fallback, the secrets GEMINI_API_KEY / OPENAI_API_KEY / ANTHROPIC_API_KEY / OPENAI_COMPAT_API_KEY.
// After a successful translation the function asks ai-assist (if deployed) to run the automatic
// machine-review rounds configured in the app (ai_review_rounds).
// Secrets (supabase secrets set ...):
//   GEMINI_API_KEY               optional if the key is saved in the app
//   GEMINI_MAX_OUTPUT_TOKENS     optional, default 32768
//   ALLOWED_ORIGIN               optional, default "*" (set to your app's URL)
// SUPABASE_URL, SUPABASE_ANON_KEY and SUPABASE_SERVICE_ROLE_KEY are provided by Supabase.

import { createClient, type SupabaseClient } from "npm:@supabase/supabase-js@2";
import {
  buildPrompt,
  matchGlossary,
  parseModelJson,
  RESPONSE_SCHEMA,
  validateResult,
} from "./lib.ts";
import { callLlm, LlmError, loadLlmConfig, type LlmConfig } from "./llm.ts";

declare const EdgeRuntime: { waitUntil(p: Promise<unknown>): void } | undefined;

const SUPABASE_URL = Deno.env.get("SUPABASE_URL")!;
const ANON_KEY = Deno.env.get("SUPABASE_ANON_KEY")!;
const SERVICE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const ALLOWED_ORIGIN = Deno.env.get("ALLOWED_ORIGIN") ?? "*";

const MAX_ATTEMPTS = 4;
// Optional: a second model to switch to when the main one keeps answering 429/5xx.
const FALLBACK_MODEL = Deno.env.get("GEMINI_FALLBACK_MODEL") ?? "";
const REQUEST_TIMEOUT_MS = 90_000;   // one Gemini call
const TIME_BUDGET_MS = 140_000;      // stop retrying after this; background runs have a wall-clock limit
const STALE_AFTER_MS = 5 * 60_000;   // a 'translating' job older than this may be restarted
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const corsHeaders = {
  "Access-Control-Allow-Origin": ALLOWED_ORIGIN,
  "Access-Control-Allow-Headers": "authorization, x-client-info, apikey, content-type",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
};

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...corsHeaders, "Content-Type": "application/json" },
  });
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

Deno.serve(async (req) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (req.method !== "POST") return json({ error: "method_not_allowed" }, 405);

  // 1. Who is calling? Only reviewers and admins may start a translation.
  const authHeader = req.headers.get("Authorization");
  if (!authHeader) return json({ error: "missing_authorization" }, 401);

  const userClient = createClient(SUPABASE_URL, ANON_KEY, {
    global: { headers: { Authorization: authHeader } },
    auth: { persistSession: false },
  });
  const { data: userData, error: userErr } = await userClient.auth.getUser();
  if (userErr || !userData.user) return json({ error: "invalid_token" }, 401);

  const { data: profile } = await userClient
    .from("profiles")
    .select("role")
    .eq("id", userData.user.id)
    .single();
  if (!profile || !["admin", "reviewer"].includes(profile.role)) {
    return json({ error: "forbidden" }, 403);
  }

  // 2. Which document?
  const body = await req.json().catch(() => null);
  const documentId = body?.document_id;
  if (typeof documentId !== "string" || !UUID_RE.test(documentId)) {
    return json({ error: "document_id_required" }, 400);
  }

  // 3. Start the job exactly once: the UPDATE only matches when the document is
  //    'translating' and no other run started in the last few minutes.
  const admin = createClient(SUPABASE_URL, SERVICE_KEY, { auth: { persistSession: false } });
  const staleBefore = new Date(Date.now() - STALE_AFTER_MS).toISOString();
  // This run's identity: if someone presses "stop" (or restarts the job), ai_started_at changes
  // and this run notices and quits instead of spending more Gemini requests.
  const stamp = new Date().toISOString();
  const { data: started, error: startErr } = await admin
    .from("documents")
    .update({ ai_started_at: stamp })
    .eq("id", documentId)
    .eq("status", "translating")
    .or(`ai_started_at.is.null,ai_started_at.lt.${staleBefore}`)
    .select("id")
    .maybeSingle();
  if (startErr) return json({ error: "start_failed", detail: startErr.message }, 500);
  if (!started) return json({ error: "not_ready_or_already_running" }, 409);

  // 4. Do the slow part in the background and answer right away.
  const job = runJob(admin, documentId, stamp);
  if (typeof EdgeRuntime !== "undefined") EdgeRuntime.waitUntil(job);
  else await job;

  return json({ status: "started", document_id: documentId }, 202);
});

// True while this exact run is still the current one (not stopped, deleted or restarted).
async function stillRunning(admin: SupabaseClient, documentId: string, stamp: string): Promise<boolean> {
  const { data, error } = await admin
    .from("documents")
    .select("id")
    .eq("id", documentId)
    .eq("status", "translating")
    .eq("ai_started_at", stamp)
    .maybeSingle();
  if (error) return true; // cannot tell: carry on rather than abandon the job
  return !!data;
}

async function runJob(admin: SupabaseClient, documentId: string, stamp: string): Promise<void> {
  try {
    await translate(admin, documentId, stamp);
  } catch (e) {
    const message = e instanceof Error ? e.message : String(e);
    console.error("translate failed", documentId, message);
    // A stopped or restarted run must not mark the document as failed.
    if (!(await stillRunning(admin, documentId, stamp))) return;
    await admin.rpc("mark_translation_failed", {
      p_document_id: documentId,
      p_error: message,
    });
  }
}

async function loadGlossary(admin: SupabaseClient) {
  const { data, error } = await admin.from("glossary").select("source_term, target_term, note").eq("is_active", true);
  if (error) throw new Error(error.message);
  return (data ?? []) as { source_term: string; target_term: string; note: string | null }[];
}

async function translate(admin: SupabaseClient, documentId: string, stamp: string): Promise<void> {
  const startedAt = Date.now();
  const { data: prompt, error: promptErr } = await admin
    .from("prompts")
    .select("id, model, body")
    .eq("is_active", true)
    .maybeSingle();
  if (promptErr || !prompt) throw new Error("no_active_prompt");

  const cfg: LlmConfig = await loadLlmConfig(admin, "translate", prompt.model, (k) => Deno.env.get(k));
  if (!cfg.apiKey) throw new Error(`${cfg.provider}_key_not_set: save the key in the app (Admin > AI service)`);
  if (!cfg.model) throw new Error(`${cfg.provider}_model_not_set: enter the model name in the app (Admin > AI service)`);

  const { data: segments, error: segErr } = await admin
    .from("segments")
    .select("position, kind, level, source_text")
    .eq("document_id", documentId)
    .order("position");
  if (segErr) throw new Error(`segments_read_failed: ${segErr.message}`);
  if (!segments || segments.length === 0) throw new Error("document_has_no_segments");

  // organization glossary: only the terms that actually occur in this document go to the model
  let glossary: Awaited<ReturnType<typeof loadGlossary>> = [];
  try { glossary = matchGlossary(await loadGlossary(admin), segments); } catch (e) { console.error("glossary skipped", e); }

  let correction: string | undefined;
  let lastError = "unknown_error";

  let model: string = cfg.model;
  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
    if (Date.now() - startedAt > TIME_BUDGET_MS) break;
    if (!(await stillRunning(admin, documentId, stamp))) {
      console.log("translation stopped by the reviewer", documentId);
      return;
    }
    try {
      const text = await callLlm({ ...cfg, model }, buildPrompt(prompt.body, segments, correction, glossary), {
        json: true,
        geminiSchema: RESPONSE_SCHEMA,
        timeoutMs: REQUEST_TIMEOUT_MS,
      });

      let parsed: unknown;
      try {
        parsed = parseModelJson(text);
      } catch (e) {
        lastError = e instanceof Error ? e.message : String(e);
        correction = "Your previous answer was not valid JSON.";
        continue;
      }

      const result = validateResult(parsed, segments);
      if (!result.ok) {
        lastError = result.error;
        correction = `Your previous answer was rejected: ${result.error}.`;
        continue;
      }

      const { error } = await admin.rpc("apply_ai_translation", {
        p_document_id: documentId,
        p_items: result.value.paragraphs.map((p) => ({ pos: p.id, translation: p.translation })),
        p_glossary: result.value.glossary,
        p_notes: result.value.notes,
        p_model: model,
        p_prompt_id: prompt.id,
      });
      if (error) throw new Error(`save_failed: ${error.message}`);
      await startAutoReview(admin, documentId);
      return;
    } catch (e) {
      if (e instanceof LlmError && e.retryable) {
        lastError = e.message;
        // overloaded (503) or rate-limited (429): wait longer each time, then try the fallback model
        if (FALLBACK_MODEL && cfg.provider === "gemini" && attempt >= 2 && model !== FALLBACK_MODEL) model = FALLBACK_MODEL;
        await sleep(Math.min(30_000, 4_000 * 2 ** (attempt - 1)));
        continue;
      }
      throw e; // fatal: bad key, blocked prompt, truncated output, save failure ...
    }
  }
  throw new Error(`gave_up_after_retries: ${lastError}`);
}

// Ask ai-assist to run the automatic review rounds. Never fails the translation.
async function startAutoReview(admin: SupabaseClient, documentId: string): Promise<void> {
  try {
    const { data } = await admin.from("app_config").select("value").eq("key", "ai_review_rounds").maybeSingle();
    const rounds = Math.min(3, Math.max(0, Number(data?.value ?? 0) || 0));
    if (rounds < 1) return;
    const res = await fetch(`${SUPABASE_URL}/functions/v1/ai-assist`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${SERVICE_KEY}`, apikey: ANON_KEY },
      body: JSON.stringify({ action: "review", document_id: documentId, rounds, auto: true }),
      signal: AbortSignal.timeout(20_000),
    });
    if (!res.ok) console.error("auto review not started", res.status, (await res.text()).slice(0, 200));
  } catch (e) {
    console.error("auto review skipped", e instanceof Error ? e.message : e);
  }
}
