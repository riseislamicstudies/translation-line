// Provider-neutral LLM client used by translate-document and ai-assist.
// Providers: gemini, openai, anthropic, openai_compat (any OpenAI-style /chat/completions endpoint).
// Pure request-building / response-parsing functions are exported separately so they can be unit-tested.

export type Provider = "gemini" | "openai" | "anthropic" | "openai_compat";
export const PROVIDERS: Provider[] = ["gemini", "openai", "anthropic", "openai_compat"];

export interface LlmConfig {
  provider: Provider;
  model: string;
  apiKey: string;
  baseUrl?: string;      // openai_compat only, e.g. https://openrouter.ai/api/v1
  maxTokens: number;
  wireApi?: "chat" | "responses";           // openai / openai_compat: /chat/completions (default) or /responses
  extraHeaders?: Record<string, string>;    // optional extra request headers (openai_compat only)
  label?: string;                           // name of the saved connection (for messages)
  fallbacks?: LlmConfig[];                  // tried in order when this one fails
}

export interface CallOpts {
  system?: string;
  json?: boolean;                 // ask for a JSON answer where the provider supports it
  geminiSchema?: unknown;         // optional structured-output schema (Gemini only)
  temperature?: number;
  timeoutMs?: number;
  noResponseFormat?: boolean;     // retry without response_format for picky compatible servers
}

export class LlmError extends Error {
  retryable: boolean;
  constructor(message: string, retryable: boolean) {
    super(message);
    this.retryable = retryable;
  }
}

export const SECRET_NAME: Record<Provider, string> = {
  gemini: "GEMINI_API_KEY",
  openai: "OPENAI_API_KEY",
  anthropic: "ANTHROPIC_API_KEY",
  openai_compat: "OPENAI_COMPAT_API_KEY",
};

/** https only, and never a private or local address (the address is typed in by a person). */
export function isSafeBaseUrl(raw: string): boolean {
  let u: URL;
  try { u = new URL(raw); } catch { return false; }
  if (u.protocol !== "https:") return false;
  const h = u.hostname.toLowerCase();
  if (h === "localhost" || h.endsWith(".local") || h.endsWith(".internal") || !h.includes(".")) return false;
  if (/^\d+\.\d+\.\d+\.\d+$/.test(h)) {
    const [a, b] = h.split(".").map(Number);
    if (a === 10 || a === 127 || a === 0 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168)) return false;
  }
  if (h.startsWith("[")) return false;
  return true;
}

export interface BuiltRequest { url: string; headers: Record<string, string>; body: string }

export function buildRequest(cfg: LlmConfig, prompt: string, o: CallOpts = {}): BuiltRequest {
  const temp = o.temperature ?? 0.2;
  if (cfg.provider === "gemini") {
    const gen: Record<string, unknown> = { temperature: temp, maxOutputTokens: cfg.maxTokens };
    if (o.json) {
      gen.responseMimeType = "application/json";
      if (o.geminiSchema) gen.responseSchema = o.geminiSchema;
    }
    const body: Record<string, unknown> = {
      contents: [{ role: "user", parts: [{ text: prompt }] }],
      generationConfig: gen,
    };
    if (o.system) body.systemInstruction = { parts: [{ text: o.system }] };
    return {
      url: `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(cfg.model)}:generateContent`,
      headers: { "Content-Type": "application/json", "x-goog-api-key": cfg.apiKey },
      body: JSON.stringify(body),
    };
  }
  if (cfg.provider === "anthropic") {
    const body: Record<string, unknown> = {
      model: cfg.model,
      max_tokens: cfg.maxTokens,
      messages: [{ role: "user", content: prompt }],
    };
    if (o.system) body.system = o.system;
    return {
      url: "https://api.anthropic.com/v1/messages",
      headers: { "Content-Type": "application/json", "x-api-key": cfg.apiKey, "anthropic-version": "2023-06-01" },
      body: JSON.stringify(body),
    };
  }
  // openai and openai_compat
  const baseUrl = cfg.provider === "openai" ? "https://api.openai.com/v1" : (cfg.baseUrl ?? "").replace(/\/+$/, "");
  if (cfg.wireApi === "responses") {
    const rb: Record<string, unknown> = { model: cfg.model, input: o.system ? `${o.system}\n\n${prompt}` : prompt, max_output_tokens: cfg.maxTokens, store: false };
    if (o.json && !o.noResponseFormat) rb.text = { format: { type: "json_object" } };
    return {
      url: `${baseUrl}/responses`,
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${cfg.apiKey}`, ...(cfg.provider === "openai_compat" ? cfg.extraHeaders ?? {} : {}) },
      body: JSON.stringify(rb),
    };
  }
  const messages: { role: string; content: string }[] = [];
  if (o.system) messages.push({ role: "system", content: o.system });
  messages.push({ role: "user", content: prompt });
  const body: Record<string, unknown> = { model: cfg.model, messages };
  if (cfg.provider === "openai") body.max_completion_tokens = cfg.maxTokens;
  else body.max_tokens = cfg.maxTokens;
  if (o.json && !o.noResponseFormat) body.response_format = { type: "json_object" };
  return {
    url: `${baseUrl}/chat/completions`,
    headers: { "Content-Type": "application/json", Authorization: `Bearer ${cfg.apiKey}`, ...(cfg.provider === "openai_compat" ? cfg.extraHeaders ?? {} : {}) },
    body: JSON.stringify(body),
  };
}

/** Turn an HTTP answer into text, or throw a LlmError saying whether a retry makes sense. */
export function parseResponse(cfg: LlmConfig, status: number, raw: string): string {
  const name = cfg.provider;
  if (status < 200 || status >= 300) {
    const detail = raw.slice(0, 500);
    if (status === 429 && /per\s*day|PerDay|requests per day|insufficient_quota|exceeded your current quota|credit balance/i.test(raw)) {
      throw new LlmError(`${name}_quota_exceeded: the quota or credit of this key is used up. ${detail}`, false);
    }
    if (status === 400 && /credit balance|billing/i.test(raw)) throw new LlmError(`${name}_billing: ${detail}`, false);
    const retryable = status === 429 || status === 408 || status === 529 || status >= 500;
    throw new LlmError(`${name}_http_${status}: ${detail}`, retryable);
  }
  let data: any;
  try { data = JSON.parse(raw); } catch { throw new LlmError(`${name}_bad_json_response`, true); }

  if (cfg.provider === "gemini") {
    if (data.promptFeedback?.blockReason) throw new LlmError(`gemini_blocked: ${data.promptFeedback.blockReason}`, false);
    const c = data.candidates?.[0];
    if (!c) throw new LlmError("gemini_no_candidates", true);
    if (c.finishReason === "MAX_TOKENS") throw new LlmError("gemini_output_truncated: raise the maximum output tokens or split the document", false);
    const text = (c.content?.parts ?? []).map((p: { text?: string }) => p.text ?? "").join("");
    if (!text.trim()) throw new LlmError("gemini_empty_response", true);
    return text;
  }
  if (cfg.provider === "anthropic") {
    if (data.stop_reason === "max_tokens") throw new LlmError("anthropic_output_truncated: raise the maximum output tokens or split the document", false);
    const text = (data.content ?? []).filter((b: { type?: string }) => b.type === "text").map((b: { text?: string }) => b.text ?? "").join("");
    if (!text.trim()) throw new LlmError("anthropic_empty_response", true);
    return text;
  }
  if (cfg.wireApi === "responses") {
    if (data.status === "incomplete" && data.incomplete_details?.reason === "max_output_tokens") {
      throw new LlmError(`${name}_output_truncated: raise the maximum output tokens or split the document`, false);
    }
    if (data.error) throw new LlmError(`${name}_error: ${JSON.stringify(data.error).slice(0, 300)}`, true);
    let text = typeof data.output_text === "string" ? data.output_text : "";
    if (!text) {
      for (const it of (data.output ?? []) as { type?: string; content?: { type?: string; text?: string }[] }[]) {
        if (it.type !== "message") continue;
        for (const p of it.content ?? []) if (p.type === "output_text" && typeof p.text === "string") text += p.text;
      }
    }
    if (!text.trim()) throw new LlmError(`${name}_empty_response`, true);
    return text;
  }
  const ch = data.choices?.[0];
  if (!ch) throw new LlmError(`${name}_no_choices`, true);
  if (ch.finish_reason === "length") throw new LlmError(`${name}_output_truncated: raise the maximum output tokens or split the document`, false);
  if (ch.finish_reason === "content_filter") throw new LlmError(`${name}_blocked: content filter`, false);
  const text = typeof ch.message?.content === "string"
    ? ch.message.content
    : Array.isArray(ch.message?.content) ? ch.message.content.map((p: { text?: string }) => p.text ?? "").join("") : "";
  if (!text.trim()) throw new LlmError(`${name}_empty_response`, true);
  return text;
}

/** Calls the primary connection; when it fails (quota, outage, bad key ...) the saved fallback connections are tried in order. */
export async function callLlm(cfg: LlmConfig, prompt: string, o: CallOpts = {}): Promise<string> {
  try { return await callOne(cfg, prompt, o); } catch (e) {
    if (!(e instanceof LlmError) || !cfg.fallbacks?.length) throw e;
    let last: LlmError = e;
    for (const fb of cfg.fallbacks) {
      try { return await callOne(fb, prompt, o); } catch (e2) { if (e2 instanceof LlmError) last = e2; else throw e2; }
    }
    throw new LlmError(`${e.message} | fallback: ${last.message}`, last.retryable);
  }
}

async function callOne(cfg: LlmConfig, prompt: string, o: CallOpts = {}): Promise<string> {
  if (!cfg.apiKey) throw new LlmError(`${cfg.provider}_key_not_set`, false);
  if (!cfg.model) throw new LlmError(`${cfg.provider}_model_not_set`, false);
  if (cfg.provider === "openai_compat" && !isSafeBaseUrl(cfg.baseUrl ?? "")) {
    throw new LlmError("openai_compat_base_url_invalid: an https address of a public server is required", false);
  }
  const attempt = async (opts: CallOpts) => {
    const r = buildRequest(cfg, prompt, opts);
    let res: Response;
    try {
      res = await fetch(r.url, { method: "POST", headers: r.headers, body: r.body, signal: AbortSignal.timeout(o.timeoutMs ?? 90_000) });
    } catch (e) {
      throw new LlmError(`${cfg.provider}_request_failed: ${e instanceof Error ? e.message : e}`, true);
    }
    return { status: res.status, raw: await res.text() };
  };
  let { status, raw } = await attempt(o);
  // some compatible servers refuse response_format: try once without it
  if (cfg.provider === "openai_compat" && o.json && status === 400 && /response_format|json_object|text\.format|\"format\"/i.test(raw)) {
    ({ status, raw } = await attempt({ ...o, noResponseFormat: true }));
  }
  return parseResponse(cfg, status, raw);
}

// ---- settings -------------------------------------------------------------
export type Task = "translate" | "review" | "rewrite" | "curate" | "seo";

interface AdminLike { from(t: string): any }

export interface Conn { id: string; label?: string; provider: Provider; base_url?: string; wire_api?: string; model?: string; headers?: string }

/** Read the provider, model and key for one task from app_config / app_secrets (with env fallbacks).
 *  ai_provider_<task> is either a provider name (old style, one key per provider) or "conn:<id>" (a saved connection).
 *  ai_fallback_<task> = comma separated connection ids tried when the first one fails. */
export async function loadLlmConfig(
  admin: AdminLike,
  task: Task,
  defaultGeminiModel: string,
  env: (k: string) => string | undefined,
): Promise<LlmConfig> {
  const keys = [`ai_provider_${task}`, `ai_model_${task}`, `ai_fallback_${task}`, "ai_conns", "ai_base_url", "ai_max_tokens", "ai_wire_api", "ai_extra_headers"];
  const { data } = await admin.from("app_config").select("key,value").in("key", keys);
  const c: Record<string, string> = {};
  for (const r of (data ?? []) as { key: string; value: string }[]) c[r.key] = r.value;
  let conns: Conn[] = [];
  try { const j = JSON.parse(c.ai_conns || "[]"); if (Array.isArray(j)) conns = j.filter((x) => x && /^[a-z0-9]{2,12}$/.test(x.id) && PROVIDERS.includes(x.provider)); } catch { /* ignore */ }
  const maxTokens = Number(c.ai_max_tokens) > 0 ? Number(c.ai_max_tokens) : Number(env("GEMINI_MAX_OUTPUT_TOKENS") ?? "32768") || 32768;
  const secretOf = async (name: string) => {
    const { data: sec } = await admin.from("app_secrets").select("value").eq("name", name).maybeSingle();
    return (sec?.value as string | undefined) || "";
  };
  const fromConn = async (cn: Conn, model: string): Promise<LlmConfig> => ({
    provider: cn.provider, model: model || (cn.model ?? "").trim() || (cn.provider === "gemini" ? defaultGeminiModel : ""),
    apiKey: await secretOf(`AI_KEY_${cn.id}`), baseUrl: cn.base_url || c.ai_base_url || undefined, maxTokens,
    wireApi: (cn.wire_api || c.ai_wire_api) === "responses" ? "responses" : "chat",
    extraHeaders: parseExtraHeaders(cn.headers || c.ai_extra_headers), label: cn.label || cn.id,
  });
  const sel = (c[`ai_provider_${task}`] ?? "").trim();
  const taskModel = (c[`ai_model_${task}`] ?? "").trim();
  let cfg: LlmConfig;
  const cn = sel.startsWith("conn:") ? conns.find((x) => x.id === sel.slice(5)) : undefined;
  if (cn) cfg = await fromConn(cn, taskModel);
  else {
    const provider = (PROVIDERS.includes(sel as Provider) ? sel : "gemini") as Provider;
    let model = taskModel;
    if (!model && provider === "gemini") model = defaultGeminiModel;
    const apiKey = (await secretOf(SECRET_NAME[provider])) || env(SECRET_NAME[provider]) || "";
    const wireApi = c.ai_wire_api === "responses" ? "responses" : "chat";
    cfg = { provider, model, apiKey, baseUrl: c.ai_base_url || undefined, maxTokens, wireApi, extraHeaders: parseExtraHeaders(c.ai_extra_headers) };
  }
  const fb: LlmConfig[] = [];
  for (const id of (c[`ai_fallback_${task}`] ?? "").split(",").map((x) => x.trim()).filter(Boolean)) {
    const f = conns.find((x) => x.id === id); if (!f || id === sel.slice(5)) continue;
    const fc = await fromConn(f, "");
    if (fc.apiKey && fc.model) fb.push(fc);   // a fallback needs its own key and model
  }
  if (fb.length) cfg.fallbacks = fb;
  return cfg;
}

/** "Name: value" per line. Only plain header names; never anything that could replace auth or routing. */
export function parseExtraHeaders(raw: string | undefined): Record<string, string> {
  const out: Record<string, string> = {};
  for (const line of (raw ?? "").split(/\r?\n/)) {
    const i = line.indexOf(":");
    if (i < 1) continue;
    const k = line.slice(0, i).trim(), v = line.slice(i + 1).trim();
    if (!/^[A-Za-z0-9-]{1,60}$/.test(k) || !v || v.length > 300 || /[\r\n]/.test(v)) continue;
    if (/^(authorization|host|content-type|content-length|x-api-key|apikey|cookie)$/i.test(k)) continue;
    out[k] = v;
  }
  return out;
}
