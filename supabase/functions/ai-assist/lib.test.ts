import assert from "node:assert/strict";
import { chunk, fillTemplate, matchGlossary, parseModelJson, validateReview, validateRewrite, DEFAULT_REVIEW_PROMPT, DEFAULT_REWRITE_PROMPT } from "./lib.ts";
import { buildRequest, parseResponse, isSafeBaseUrl, LlmError } from "./llm.ts";

// templates
const p = fillTemplate(DEFAULT_REVIEW_PROMPT, [{ id: 1, fa: "سلام $&", en: "Hello" }]);
assert.ok(p.includes('"fa":"سلام $&"') && !p.includes("{{PARAGRAPHS_JSON}}"));
const w = fillTemplate(DEFAULT_REWRITE_PROMPT, [{ id: 1, text: "x" }], { INSTRUCTION: "Make it simpler" });
assert.ok(w.includes("Make it simpler") && !w.includes("{{INSTRUCTION}}"));
assert.throws(() => fillTemplate("no placeholder", []));

// chunking
const many = Array.from({ length: 60 }, (_, i) => ({ id: i, text: "x".repeat(10) }));
assert.deepEqual(chunk(many).map((c) => c.length), [25, 25, 10]);
assert.equal(chunk([{ id: 1, text: "a".repeat(9000) }, { id: 2, text: "b".repeat(9000) }]).length, 2);

// validation
const rin = [{ id: 1, fa: "الف", en: "A" }, { id: 2, fa: "ب", en: "B" }];
assert.ok(validateReview({ items: [{ id: 2, translation: "B2", note: "n" }, { id: 1, translation: "A", note: "" }] }, rin).ok);
assert.ok(!validateReview({ items: [{ id: 1, translation: "A", note: "" }] }, rin).ok);
assert.ok(!validateReview({ items: [{ id: 1, translation: "ترجمه نشده است و فارسی مانده", note: "" }, { id: 2, translation: "B", note: "" }] }, rin).ok);
assert.ok(!validateRewrite({ items: [{ id: 1, text: "" }] }, [{ id: 1, text: "x" }]).ok);
assert.deepEqual((validateRewrite({ items: [{ id: 1, text: " ok " }] }, [{ id: 1, text: "x" }]) as any).value, [{ id: 1, text: "ok" }]);
assert.deepEqual(parseModelJson("```json\n{\"items\":[]}\n```"), { items: [] });
assert.deepEqual(parseModelJson("here: {\"items\":[1]} done"), { items: [1] });

// glossary
assert.equal(matchGlossary([{ source_term: "معاد", target_term: "Resurrection" }, { source_term: "نبوت", target_term: "Prophethood" }], ["بحث معاد جسمانی"]).length, 1);

// providers
const base = { model: "m", apiKey: "K", maxTokens: 1000 };
let r = buildRequest({ ...base, provider: "openai" }, "hi", { json: true, system: "sys" });
assert.equal(r.url, "https://api.openai.com/v1/chat/completions");
let b = JSON.parse(r.body); assert.equal(b.max_completion_tokens, 1000); assert.deepEqual(b.response_format, { type: "json_object" }); assert.equal(b.messages[0].role, "system");
assert.equal(r.headers.Authorization, "Bearer K");
r = buildRequest({ ...base, provider: "openai_compat", baseUrl: "https://openrouter.ai/api/v1/" }, "hi", { json: true });
assert.equal(r.url, "https://openrouter.ai/api/v1/chat/completions"); b = JSON.parse(r.body); assert.equal(b.max_tokens, 1000);
r = buildRequest({ ...base, provider: "openai_compat", baseUrl: "https://x.ir/v1" }, "hi", { json: true, noResponseFormat: true });
assert.equal(JSON.parse(r.body).response_format, undefined);
r = buildRequest({ ...base, provider: "anthropic" }, "hi", { system: "sys" });
assert.equal(r.url, "https://api.anthropic.com/v1/messages"); assert.equal(r.headers["x-api-key"], "K"); assert.equal(r.headers["anthropic-version"], "2023-06-01");
b = JSON.parse(r.body); assert.equal(b.system, "sys"); assert.equal(b.max_tokens, 1000);
r = buildRequest({ ...base, provider: "gemini", model: "gemini-2.5-flash" }, "hi", { json: true, geminiSchema: { type: "OBJECT" }, system: "s" });
assert.ok(r.url.endsWith("gemini-2.5-flash:generateContent")); b = JSON.parse(r.body);
assert.equal(b.generationConfig.responseMimeType, "application/json"); assert.ok(b.systemInstruction);

// responses
assert.equal(parseResponse({ ...base, provider: "openai" }, 200, JSON.stringify({ choices: [{ message: { content: "ok" }, finish_reason: "stop" }] })), "ok");
assert.equal(parseResponse({ ...base, provider: "anthropic" }, 200, JSON.stringify({ content: [{ type: "text", text: "a" }, { type: "text", text: "b" }], stop_reason: "end_turn" })), "ab");
assert.equal(parseResponse({ ...base, provider: "gemini" }, 200, JSON.stringify({ candidates: [{ content: { parts: [{ text: "g" }] }, finishReason: "STOP" }] })), "g");
const err = (prov: any, st: number, body: string) => { try { parseResponse({ ...base, provider: prov }, st, body); } catch (e) { return e as LlmError; } };
assert.equal(err("openai", 429, '{"error":{"code":"insufficient_quota"}}')!.retryable, false);
assert.equal(err("openai", 429, "slow down")!.retryable, true);
assert.equal(err("anthropic", 529, "overloaded")!.retryable, true);
assert.equal(err("anthropic", 401, "bad key")!.retryable, false);
assert.equal(err("openai_compat", 200, JSON.stringify({ choices: [{ message: { content: "x" }, finish_reason: "length" }] }))!.retryable, false);
assert.equal(err("anthropic", 200, JSON.stringify({ content: [{ type: "text", text: "x" }], stop_reason: "max_tokens" }))!.retryable, false);

// base url guard
for (const u of ["http://x.com/v1", "https://localhost/v1", "https://127.0.0.1/v1", "https://10.0.0.5/v1", "https://192.168.1.1", "https://intranet/v1", "ftp://x.com"]) assert.equal(isSafeBaseUrl(u), false, u);
for (const u of ["https://openrouter.ai/api/v1", "https://api.deepseek.com", "https://api.avalai.ir/v1"]) assert.equal(isSafeBaseUrl(u), true, u);
console.log("ai-assist lib tests passed");
