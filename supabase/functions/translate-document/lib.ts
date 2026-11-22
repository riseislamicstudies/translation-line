// Pure helpers for the translate-document Edge Function.
// No Deno or network APIs here, so the same file can be unit-tested with plain Node.

export interface SegmentIn {
  position: number;
  source_text: string;
  kind?: string;
  level?: number | null;
}

export interface ModelResult {
  paragraphs: { id: number; translation: string }[];
  glossary: { persian: string; english: string }[];
  notes: { paragraph_id: number; note: string }[];
}

export type Validation =
  | { ok: true; value: ModelResult }
  | { ok: false; error: string };

export const PLACEHOLDER = "{{PARAGRAPHS_JSON}}";

/** Gemini structured-output schema (OpenAPI subset). Guarantees valid JSON shape. */
export const RESPONSE_SCHEMA = {
  type: "OBJECT",
  properties: {
    paragraphs: {
      type: "ARRAY",
      items: {
        type: "OBJECT",
        properties: {
          id: { type: "INTEGER" },
          translation: { type: "STRING" },
        },
        required: ["id", "translation"],
        propertyOrdering: ["id", "translation"],
      },
    },
    glossary: {
      type: "ARRAY",
      items: {
        type: "OBJECT",
        properties: { persian: { type: "STRING" }, english: { type: "STRING" } },
        required: ["persian", "english"],
        propertyOrdering: ["persian", "english"],
      },
    },
    notes: {
      type: "ARRAY",
      items: {
        type: "OBJECT",
        properties: { paragraph_id: { type: "INTEGER" }, note: { type: "STRING" } },
        required: ["paragraph_id", "note"],
        propertyOrdering: ["paragraph_id", "note"],
      },
    },
  },
  required: ["paragraphs", "glossary", "notes"],
  propertyOrdering: ["paragraphs", "glossary", "notes"],
};

/** Fill the stored prompt with the paragraphs. `correction` is added on retries. */
export interface GlossaryEntry { source_term: string; target_term: string; note?: string | null }

/** Normalise Persian text for matching: Arabic letter forms, ZWNJ, diacritics, digits, case. */
export function normFa(t: string): string {
  return (t || "")
    .replace(/ي/g, "ی").replace(/ك/g, "ک").replace(/ة/g, "ه")
    .replace(/[\u200c\u200f\u200e\u0640]/g, " ")
    .replace(/[\u064b-\u065f\u0670]/g, "")
    .replace(/[۰-۹]/g, (d) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String("٠١٢٣٤٥٦٧٨٩".indexOf(d)))
    .replace(/\s+/g, " ").trim().toLowerCase();
}

/** Glossary entries whose Persian term occurs in the document (longest terms first). */
export function matchGlossary(entries: GlossaryEntry[], segments: SegmentIn[]): GlossaryEntry[] {
  const text = " " + segments.map((s) => normFa(s.source_text)).join(" \n ") + " ";
  return entries
    .filter((e) => {
      const k = normFa(e.source_term);
      return k.length > 0 && text.includes(k);
    })
    .sort((a, b) => b.source_term.length - a.source_term.length);
}

export function glossaryBlock(entries: GlossaryEntry[]): string {
  if (!entries.length) return "";
  const lines = entries.map((e) =>
    `- ${e.source_term} => ${e.target_term}` + (e.note ? ` (${String(e.note).replace(/\s+/g, " ")})` : "")
  );
  return "\n\nMANDATORY ORGANIZATION GLOSSARY: whenever the Persian term on the left appears " +
    "(in any grammatical form), translate it exactly as the English term on the right. " +
    "Do not use synonyms for these terms.\n" + lines.join("\n");
}

/** Word formatting travels inside the text as light markers; the model must keep them. */
export const MARKER_RE = /\*\*|(?<!\\)\*|\[\^\d+\]|\]\(https?:\/\//;
export function formatBlock(segments: SegmentIn[]): string {
  const has = segments.some((s) => MARKER_RE.test(s.source_text) || s.kind === "footnote");
  if (!has) return "";
  return "\n\nFORMATTING MARKERS: the Persian text carries Word formatting as inline markers. Keep them in the English translation, " +
    "attached to the corresponding words: **bold**, *italic*, [link text](https://url) (translate only the link text, never the URL), " +
    "and footnote references like [^1] (keep each one exactly, right after the word it follows). \\* means a literal star. " +
    "A paragraph whose kind is \"footnote\" starts with its marker, e.g. \"[^2] text\": translate the text and keep the leading [^2] unchanged. " +
    "Never add markers that are not in the source, never drop one, and never put markdown headings, bullets or other syntax into a translation. " +
    "Keep a list item's leading \"- \" or \"1. \" prefix as it is.";
}

export function buildPrompt(
  template: string,
  segments: SegmentIn[],
  correction?: string,
  glossary: GlossaryEntry[] = [],
): string {
  if (!template.includes(PLACEHOLDER)) {
    throw new Error("prompt_missing_placeholder");
  }
  const input = JSON.stringify(
    segments.map((s) => s.kind && s.kind !== "paragraph" ? { id: s.position, kind: s.kind, text: s.source_text } : { id: s.position, text: s.source_text }),
  );
  // split/join instead of replace(): the input may contain "$&" style sequences
  let prompt = template.split(PLACEHOLDER).join(input) + formatBlock(segments) + glossaryBlock(glossary);
  if (correction) {
    prompt +=
      `\n\nIMPORTANT CORRECTION: ${correction}\n` +
      `Return exactly ${segments.length} paragraphs, using exactly the same ids as the input, in the same order.`;
  }
  return prompt;
}

/** Parse the model's text as JSON, tolerating code fences or stray text around it. */
export function parseModelJson(text: string): unknown {
  const cleaned = text.trim().replace(/^```(?:json)?\s*/i, "").replace(/\s*```$/, "");
  try {
    return JSON.parse(cleaned);
  } catch {
    const start = cleaned.indexOf("{");
    const end = cleaned.lastIndexOf("}");
    if (start !== -1 && end > start) {
      return JSON.parse(cleaned.slice(start, end + 1));
    }
    throw new Error("response_is_not_json");
  }
}

const ARABIC_SCRIPT = /[؀-ۿ]/g;
const LATIN = /[A-Za-z]/g;

/** Check the result lines up with the input, paragraph by paragraph. */
export function validateResult(raw: unknown, segments: SegmentIn[]): Validation {
  if (typeof raw !== "object" || raw === null) {
    return { ok: false, error: "response is not a JSON object" };
  }
  const obj = raw as Record<string, unknown>;
  const paragraphs = obj.paragraphs;
  if (!Array.isArray(paragraphs)) {
    return { ok: false, error: '"paragraphs" is missing or not an array' };
  }
  if (paragraphs.length !== segments.length) {
    return {
      ok: false,
      error: `expected ${segments.length} paragraphs but received ${paragraphs.length}`,
    };
  }

  const out: ModelResult["paragraphs"] = [];
  for (let i = 0; i < segments.length; i++) {
    const p = paragraphs[i] as { id?: unknown; translation?: unknown } | null;
    const expectedId = segments[i].position;
    if (!p || typeof p !== "object") {
      return { ok: false, error: `paragraph at index ${i} is not an object` };
    }
    if (p.id !== expectedId) {
      return {
        ok: false,
        error: `paragraph at index ${i} has id ${String(p.id)} but id ${expectedId} was expected`,
      };
    }
    if (typeof p.translation !== "string") {
      return { ok: false, error: `paragraph ${expectedId} has no translation string` };
    }
    let translation = p.translation;
    // markers: footnote references and link URLs must survive exactly; bold/italic are repaired instead of rejected
    const refs = (t: string) => (t.match(/\[\^\d+\]/g) ?? []).slice().sort().join(",");
    const urls = (t: string) => (t.match(/\]\((https?:\/\/[^\s)]+)\)/g) ?? []).slice().sort().join(",");
    if (refs(segments[i].source_text) !== refs(translation)) {
      return { ok: false, error: `paragraph ${expectedId} must keep the footnote markers exactly as in the source (${refs(segments[i].source_text) || "none"})` };
    }
    if (urls(segments[i].source_text) !== urls(translation)) {
      return { ok: false, error: `paragraph ${expectedId} must keep every link URL exactly as in the source` };
    }
    if ((translation.match(/\*\*/g) ?? []).length % 2 === 1) translation = translation.replace(/\*\*/g, "");
    if (segments[i].source_text.trim() !== "" && translation.trim() === "") {
      return { ok: false, error: `paragraph ${expectedId} has an empty translation` };
    }
    const arabic = (translation.match(ARABIC_SCRIPT) ?? []).length;
    const latin = (translation.match(LATIN) ?? []).length;
    if (arabic > 10 && arabic > latin) {
      return { ok: false, error: `paragraph ${expectedId} appears to be left untranslated` };
    }
    out.push({ id: expectedId, translation });
  }

  const glossary = (Array.isArray(obj.glossary) ? obj.glossary : [])
    .filter((g): g is { persian: string; english: string } =>
      !!g && typeof g.persian === "string" && typeof g.english === "string"
    )
    .map((g) => ({ persian: g.persian, english: g.english }));

  const validIds = new Set(segments.map((s) => s.position));
  const notes = (Array.isArray(obj.notes) ? obj.notes : [])
    .filter((n): n is { paragraph_id: number; note: string } =>
      !!n && typeof n.note === "string" && validIds.has(n.paragraph_id)
    )
    .map((n) => ({ paragraph_id: n.paragraph_id, note: n.note }));

  return { ok: true, value: { paragraphs: out, glossary, notes } };
}
