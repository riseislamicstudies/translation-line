// Pure helpers for the ai-assist Edge Function (machine review of translations, machine rewrite of Persian text).
// No Deno or network APIs here, so this file can be unit-tested with plain Node.

export const PLACEHOLDER = "{{PARAGRAPHS_JSON}}";

export interface ReviewIn { id: number; fa: string; en: string }
export interface ReviewOut { id: number; translation: string; note: string }
export interface RewriteIn { id: number; text: string }
export interface RewriteOut { id: number; text: string }

export interface GlossaryEntry { source_term: string; target_term: string; note?: string | null }

export const DEFAULT_REVIEW_PROMPT = `You are a meticulous native-English copy editor for scholarly publications in Islamic studies and the humanities.

Below is an article as a JSON array. Each element has "id" (paragraph number), "fa" (the Persian original, only for checking the meaning) and "en" (the English translation you are reviewing):
${PLACEHOLDER}

Only list critical language problems in this English text. Write them in chronological order (the order in which they appear in the article) and suggest one clear correction for each.

What counts as a critical language problem:
- ungrammatical or broken sentences, dangling or ambiguous pronouns, wrong agreement, shifts of person or gender;
- awkward, confusing or unidiomatic phrasing, wrong collocations, double negatives, clumsy conflation of two terms;
- wrong word choice or a meaning that no longer follows the Persian;
- inconsistent terminology or spelling of names (follow the mandatory glossary below, if any);
- punctuation or register that would not pass a scholarly editor.
Do NOT report matters of taste, small stylistic preferences, or anything that is already acceptable. Do not pad the list: normally 5 to 10 problems for a full article, fewer for a short text, and an empty list if the text is clean.

For every problem give:
- "id": the paragraph id where it occurs;
- "title": a very short English title of the problem (3 to 7 words);
- "problem": the problematic text, copied EXACTLY, character for character, from that paragraph's "en" (keep any ** or * or [^1] markers that are inside the quoted words), short but long enough to be unique;
- "explanation": one or two short sentences IN PERSIAN saying what is wrong;
- "correction": the corrected English that replaces exactly the quoted text (same markers kept), so it can be pasted in directly.

Return ONLY one valid JSON object, no markdown fences and no other text, in this shape:
{"issues":[{"id":1,"title":"Dangling pronoun","problem":"exact quote","explanation":"توضیح کوتاه به فارسی","correction":"corrected text"}]}`;

export const DEFAULT_REWRITE_PROMPT = `You are an experienced Persian news editor.

You receive a JSON array. Each element has "id" and "text" (one paragraph or heading of a Persian news item):
${PLACEHOLDER}

Rewrite every text in Persian according to this instruction:
{{INSTRUCTION}}

Rules:
- Keep every fact, name, number, date, title and quotation exactly as in the original. Never add information that is not in the text.
- Write natural, correct Persian (use the half-space ZWNJ properly); keep a heading short.
- If a text needs no change, return it unchanged.
- Keep inline markers exactly: **bold**, *italic*, [link text](url) and footnote references such as [^1].

Return ONLY one valid JSON object, no markdown fences and no other text, exactly in this shape:
{"items":[{"id":1,"text":"the rewritten Persian text"}]}
Return exactly one item for every input element, with the same "id" values and in the same order.`;

export const REWRITE_STYLES: Record<string, { label: string; instruction: string }> = {
  polish: { label: "ویرایش و روان‌سازی", instruction: "Edit for fluency, grammar, punctuation and clarity while keeping the author's wording and length as far as possible." },
  simple: { label: "ساده‌تر", instruction: "Make it simpler and easier for a general reader: shorter sentences, common words, no change of meaning." },
  formal: { label: "رسمی‌تر", instruction: "Make the tone formal and official, suitable for an institutional news bulletin." },
  concise: { label: "خلاصه‌تر", instruction: "Make it more concise: roughly 70 percent of the original length, removing repetition and filler but no facts." },
  news: { label: "سبک خبری استاندارد", instruction: "Rewrite in standard news style: the most important fact first, short clear sentences, neutral tone, no personal opinion." },
};

export function fillTemplate(template: string, segments: unknown[], extra: Record<string, string> = {}): string {
  if (!template.includes(PLACEHOLDER)) throw new Error("prompt_missing_placeholder");
  let out = template;
  for (const [k, v] of Object.entries(extra)) out = out.split(`{{${k}}}`).join(v);
  return out.split(PLACEHOLDER).join(JSON.stringify(segments));
}

export function glossaryBlock(entries: GlossaryEntry[]): string {
  if (!entries.length) return "";
  const lines = entries.map((e) => `- ${e.source_term} => ${e.target_term}` + (e.note ? ` (${String(e.note).replace(/\s+/g, " ")})` : ""));
  return "\n\nMANDATORY ORGANIZATION GLOSSARY: whenever the Persian term on the left appears (in any grammatical form) the English must use exactly the term on the right.\n" + lines.join("\n");
}

export function normFa(t: string): string {
  return (t || "")
    .replace(/ي/g, "ی").replace(/ك/g, "ک").replace(/ة/g, "ه")
    .replace(/[‌‏‎ـ]/g, " ")
    .replace(/[ً-ٰٟ]/g, "")
    .replace(/\s+/g, " ").trim().toLowerCase();
}

export function matchGlossary(entries: GlossaryEntry[], texts: string[]): GlossaryEntry[] {
  const hay = " " + texts.map(normFa).join(" \n ") + " ";
  return entries.filter((e) => { const k = normFa(e.source_term); return k.length > 0 && hay.includes(k); })
    .sort((a, b) => b.source_term.length - a.source_term.length);
}

/** Split into batches so one answer stays well inside the model's output limit. */
export function chunk<T extends { text?: string; fa?: string; en?: string }>(items: T[], maxItems = 25, maxChars = 12000): T[][] {
  const out: T[][] = []; let cur: T[] = []; let chars = 0;
  for (const it of items) {
    const n = (it.text ?? "").length + (it.fa ?? "").length + (it.en ?? "").length;
    if (cur.length && (cur.length >= maxItems || chars + n > maxChars)) { out.push(cur); cur = []; chars = 0; }
    cur.push(it); chars += n;
  }
  if (cur.length) out.push(cur);
  return out;
}

export function parseModelJson(text: string): unknown {
  const cleaned = text.trim().replace(/^```(?:json)?\s*/i, "").replace(/\s*```$/, "");
  try { return JSON.parse(cleaned); } catch {
    const a = cleaned.indexOf("{"), b = cleaned.lastIndexOf("}");
    if (a !== -1 && b > a) return JSON.parse(cleaned.slice(a, b + 1));
    throw new Error("response_is_not_json");
  }
}

type Res<T> = { ok: true; value: T[] } | { ok: false; error: string };

function items(raw: unknown): unknown[] | null {
  if (typeof raw !== "object" || raw === null) return null;
  const it = (raw as { items?: unknown }).items;
  return Array.isArray(it) ? it : null;
}

const refsOf = (t: string) => (t.match(/\[\^\d+\]/g) ?? []).slice().sort().join(",");
const ws = (t: string) => t.replace(/\s+/g, " ").trim();

/** Apply one quoted-problem -> correction pair to a paragraph; null when the quote is not found or the fix breaks markers. */
export function applyIssue(text: string, problem: string, correction: string): string | null {
  if (!problem.trim()) return null;
  let i = text.indexOf(problem), len = problem.length;
  if (i === -1) {
    // tolerate whitespace differences
    const esc = problem.trim().split(/\s+/).map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")).join("\\s+");
    const m = new RegExp(esc).exec(text);
    if (!m) return null;
    i = m.index; len = m[0].length;
  }
  const out = text.slice(0, i) + correction + text.slice(i + len);
  if (refsOf(out) !== refsOf(text)) return null;
  if (((out.match(/\*\*/g) ?? []).length % 2) === 1 && ((text.match(/\*\*/g) ?? []).length % 2) === 0) return null;
  return out;
}

export function validateReview(raw: unknown, input: ReviewIn[]): Res<ReviewOut> {
  // new format: a chronological list of language problems, each with a quote and one correction
  if (typeof raw === "object" && raw !== null && Array.isArray((raw as { issues?: unknown }).issues)) {
    const cur = new Map<number, string>(input.map((s) => [s.id, s.en]));
    const notes = new Map<number, string[]>();
    let n = 0;
    for (const x of (raw as { issues: unknown[] }).issues) {
      const o = x as { id?: unknown; title?: unknown; problem?: unknown; explanation?: unknown; correction?: unknown };
      if (!o || typeof o.id !== "number" || !cur.has(o.id) || typeof o.problem !== "string" || typeof o.correction !== "string") continue;
      const next = applyIssue(cur.get(o.id)!, o.problem, o.correction.trim() === o.correction ? o.correction : o.correction.trim());
      if (next === null || ws(next) === ws(cur.get(o.id)!)) continue;
      cur.set(o.id, next); n++;
      const title = typeof o.title === "string" ? o.title.trim() : "";
      const why = typeof o.explanation === "string" ? o.explanation.trim() : "";
      const line = `«${ws(o.problem)}» ← «${ws(o.correction)}»` + (title || why ? ` — ${[title, why].filter(Boolean).join(": ")}` : "");
      notes.set(o.id, [...(notes.get(o.id) ?? []), line]);
    }
    const out: ReviewOut[] = [];
    for (const s of input) if (notes.has(s.id)) out.push({ id: s.id, translation: cur.get(s.id)!, note: notes.get(s.id)!.join("\n") });
    return { ok: true, value: out };
  }
  const arr = items(raw);
  if (!arr) return { ok: false, error: '"issues" is missing or not an array' };
  const byId = new Map<number, { translation?: unknown; note?: unknown }>();
  for (const x of arr) { const o = x as { id?: unknown }; if (o && typeof o.id === "number") byId.set(o.id, x as never); }
  const out: ReviewOut[] = [];
  for (const s of input) {
    const r = byId.get(s.id);
    if (!r) return { ok: false, error: `item with id ${s.id} is missing` };
    if (typeof r.translation !== "string") return { ok: false, error: `item ${s.id} has no translation string` };
    if (s.en.trim() !== "" && r.translation.trim() === "") return { ok: false, error: `item ${s.id} has an empty translation` };
    const arabic = (r.translation.match(/[\u0600-\u06FF]/g) ?? []).length, latin = (r.translation.match(/[A-Za-z]/g) ?? []).length;
    if (arabic > 10 && arabic > latin) return { ok: false, error: `item ${s.id} is not in English` };
    if (refsOf(r.translation) !== refsOf(s.en)) return { ok: false, error: `item ${s.id} must keep the footnote markers [^n] unchanged` };
    out.push({ id: s.id, translation: r.translation.trim(), note: typeof r.note === "string" ? r.note.trim() : "" });
  }
  return { ok: true, value: out };
}

export function validateRewrite(raw: unknown, input: RewriteIn[]): Res<RewriteOut> {
  const arr = items(raw);
  if (!arr) return { ok: false, error: '"items" is missing or not an array' };
  const byId = new Map<number, { text?: unknown }>();
  for (const x of arr) { const o = x as { id?: unknown }; if (o && typeof o.id === "number") byId.set(o.id, x as never); }
  const out: RewriteOut[] = [];
  for (const s of input) {
    const r = byId.get(s.id);
    if (!r) return { ok: false, error: `item with id ${s.id} is missing` };
    if (typeof r.text !== "string") return { ok: false, error: `item ${s.id} has no text string` };
    if (s.text.trim() !== "" && r.text.trim() === "") return { ok: false, error: `item ${s.id} is empty` };
    if (refsOf(r.text) !== refsOf(s.text)) return { ok: false, error: `item ${s.id} must keep the footnote markers [^n] unchanged` };
    out.push({ id: s.id, text: r.text.trim() });
  }
  return { ok: true, value: out };
}
