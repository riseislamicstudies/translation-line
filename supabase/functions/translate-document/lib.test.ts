// Run with:  node --experimental-strip-types lib.test.ts
import assert from "node:assert/strict";
import { buildPrompt, parseModelJson, validateResult } from "./lib.ts";

const segs = [
  { position: 1, source_text: "عنوان" },
  { position: 2, source_text: "این یک متن آزمایشی است." },
  { position: 3, source_text: "۱۲۳" },
];

// buildPrompt: placeholder replaced, "$&" in text survives, correction appended
const p = buildPrompt("Input:\n{{PARAGRAPHS_JSON}}", [{ position: 1, source_text: "قیمت $& تومان" }], "bad ids");
assert.ok(p.includes('"id":1'));
assert.ok(p.includes("$&"));
assert.ok(p.includes("IMPORTANT CORRECTION: bad ids"));
assert.throws(() => buildPrompt("no placeholder", segs), /prompt_missing_placeholder/);

// parseModelJson: plain, fenced, wrapped in prose, garbage
assert.deepEqual(parseModelJson('{"a":1}'), { a: 1 });
assert.deepEqual(parseModelJson('```json\n{"a":1}\n```'), { a: 1 });
assert.deepEqual(parseModelJson('Here you go: {"a":1} done'), { a: 1 });
assert.throws(() => parseModelJson("nope"), /response_is_not_json/);

const good = {
  paragraphs: [
    { id: 1, translation: "Title" },
    { id: 2, translation: "This is a test text." },
    { id: 3, translation: "۱۲۳" },
  ],
  glossary: [{ persian: "متن", english: "text" }, { bad: true }],
  notes: [{ paragraph_id: 2, note: "ok" }, { paragraph_id: 99, note: "dropped" }],
};
const ok = validateResult(good, segs);
assert.equal(ok.ok, true);
if (ok.ok) {
  assert.equal(ok.value.glossary.length, 1);
  assert.equal(ok.value.notes.length, 1);
}

// wrong count
let r = validateResult({ paragraphs: good.paragraphs.slice(0, 2) }, segs);
assert.equal(r.ok, false);
// wrong order
r = validateResult({ paragraphs: [good.paragraphs[1], good.paragraphs[0], good.paragraphs[2]] }, segs);
assert.equal(r.ok, false);
// empty translation of a non-empty source
r = validateResult({ paragraphs: [{ id: 1, translation: "" }, good.paragraphs[1], good.paragraphs[2]] }, segs);
assert.equal(r.ok, false);
// left untranslated
r = validateResult({
  paragraphs: [good.paragraphs[0], { id: 2, translation: "این یک متن آزمایشی است که ترجمه نشده است" }, good.paragraphs[2]],
}, segs);
assert.equal(r.ok, false);
// not an object / missing paragraphs
assert.equal(validateResult(null, segs).ok, false);
assert.equal(validateResult({}, segs).ok, false);

console.log("all lib tests passed");

// glossary
import { glossaryBlock, matchGlossary, normFa } from "./lib.ts";
assert.equal(normFa("پیمان‌نامه ۱۲"), "پیمان نامه 12");
const gl = [
  { source_term: "پیمان نامه", target_term: "Treaty" },
  { source_term: "دریای خزر", target_term: "the Caspian Sea", note: "never Khazar" },
  { source_term: "نفت", target_term: "oil" },
];
const hit = matchGlossary(gl, [{ position: 1, source_text: "این پیمان‌نامه دربارهٔ دریای خزر است" }]);
assert.deepEqual(hit.map((e) => e.target_term), ["Treaty", "the Caspian Sea"]);
assert.ok(glossaryBlock(hit).includes("دریای خزر => the Caspian Sea (never Khazar)"));
assert.equal(glossaryBlock([]), "");
assert.ok(buildPrompt("A {{PARAGRAPHS_JSON}}", segs, undefined, hit).includes("MANDATORY ORGANIZATION GLOSSARY"));
console.log("glossary tests passed");
