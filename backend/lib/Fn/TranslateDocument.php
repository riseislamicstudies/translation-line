<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Ctx, Db, Http, Jobs, Llm, LlmError, Rpc, Secrets, Triggers};

/**
 * translate-document: ترجمهٔ ماشینی یک سند در وضعیت 'translating'.
 *
 * handle(): نقش admin/reviewer (یا سرویس)؛ شروع یک‌بارِ کار (ai_started_at) و ثبت job 'translate'؛ پاسخ 202-مانند
 *   {status:'started', document_id}. مرورگر منتظر نتیجه نمی‌ماند و وضعیت سند را می‌خواند.
 * job 'translate': سند در تکه‌های ≤ CHUNK_MAX_ITEMS بند / CHUNK_MAX_CHARS نویسه ترجمه می‌شود؛ هر برش (slice) تا deadline
 *   تکه‌ها را یکی‌یکی می‌فرستد و نتیجهٔ تکه‌ها را در jobs.progress نگه می‌دارد. پایان: apply_ai_translation و
 *   (اگر ai_review_rounds ≥ 1) AiAssist::startReview. خطای نهایی: mark_translation_failed.
 * هویت اجرا: جدیدترین job 'translate' همان سند؛ توقف/لغو (وضعیت ≠ translating) یعنی کار ساکت تمام شود.
 * هر برش ai_started_at را نو می‌کند (ضربان؛ فرانت بعد از ۴ دقیقه بدون ضربان «گیرکرده» می‌داند).
 */
final class TranslateDocument
{
    public const AUTH = true;

    private const MAX_TRIES = 4;            // مثل MAX_ATTEMPTS اصلی (برای هر تکه)
    private const STALE_AFTER = 300;        // ثانیه
    private const CALL_TIMEOUT = 60;        // یک تماس LLM؛ قفل job 90 ثانیه است
    private const CHUNK_MAX_ITEMS = 30;
    private const CHUNK_MAX_CHARS = 9000;
    public const PLACEHOLDER = '{{PARAGRAPHS_JSON}}';

    public static function registerJobs(): void
    {
        Jobs::register('translate', [self::class, 'runSlice']);
        Jobs::onFail('translate', static function (array $job, \Throwable $e): void {
            self::fail((string) $job['ref_id'], $e->getMessage());
        });
    }

    public static function handle(array $body, array $ctx): array
    {
        if (!Ctx::is('admin', 'reviewer')) throw new ApiError('forbidden', 403);
        $id = $body['document_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw new ApiError('document_id_required', 400);
        }
        $id = strtolower($id);
        $started = Db::tx(function () use ($id) {
            $d = Db::one('SELECT status, ai_started_at FROM documents WHERE id = ? FOR UPDATE', [$id]);
            if (!$d || $d['status'] !== 'translating') return false;
            if ($d['ai_started_at'] !== null && strtotime($d['ai_started_at'] . ' UTC') > time() - self::STALE_AFTER) return false;
            Triggers::updateDocument($id, ['ai_started_at' => self::iso()]);
            return true;
        });
        if (!$started) throw new ApiError('not_ready_or_already_running', 409);
        Jobs::enqueue('translate', $id, []);
        return ['status' => 'started', 'document_id' => $id];
    }

    private static function iso(): string { return gmdate('Y-m-d\TH:i:s') . '.000Z'; }

    private static function fail(string $id, string $msg): void
    {
        Rpc::call('mark_translation_failed', ['p_document_id' => $id, 'p_error' => $msg]);
    }

    /** آیا این job هنوز اجرای جاری است؟ */
    private static function current(array $job): bool
    {
        $st = Db::val('SELECT status FROM documents WHERE id = ?', [$job['ref_id']]);
        if ($st !== 'translating') return false;
        $max = (int) Db::val("SELECT MAX(id) FROM jobs WHERE kind = 'translate' AND ref_id = ?", [$job['ref_id']]);
        return $max === (int) $job['id'];
    }

    /** یک برش از کار. */
    public static function runSlice(array $job): array
    {
        $id = (string) $job['ref_id'];
        $pg = json_decode((string) ($job['progress'] ?? ''), true);
        $pg = is_array($pg) ? $pg : [];
        if (!self::current($job)) return ['done' => true, 'progress' => ['stopped' => true]];
        try {
            return self::slice($job, $id, $pg);
        } catch (LlmError $e) {
            if ($e->retryable) {
                return self::retryLater($id, $pg, $e->getMessage());
            }
            self::failIfCurrent($job, $e->getMessage());
            return ['done' => true, 'progress' => ['failed' => $e->getMessage()]];
        } catch (\RuntimeException $e) {
            if ($e instanceof ApiError || $e instanceof \PDOException) throw $e;
            self::failIfCurrent($job, $e->getMessage());
            return ['done' => true, 'progress' => ['failed' => $e->getMessage()]];
        }
    }

    private static function failIfCurrent(array $job, string $msg): void
    {
        Http::logLine('translate failed ' . $job['ref_id'] . ': ' . $msg);
        if (self::current($job)) self::fail((string) $job['ref_id'], $msg);
    }

    private static function retryLater(string $id, array $pg, string $msg): array
    {
        $pg['tries'] = (int) ($pg['tries'] ?? 0) + 1;
        $pg['last_error'] = $msg;
        if ($pg['tries'] >= self::MAX_TRIES) {
            self::fail($id, 'gave_up_after_retries: ' . $msg);
            return ['done' => true, 'progress' => ['failed' => $msg]];
        }
        return ['done' => false, 'progress' => $pg, 'delay' => min(30, 4 * (2 ** ($pg['tries'] - 1)))];
    }

    private static function slice(array $job, string $id, array $pg): array
    {
        $prompt = Db::one('SELECT id, model, body FROM prompts WHERE is_active = 1 LIMIT 1');
        if (!$prompt) throw new \RuntimeException('no_active_prompt');
        $llm = Llm::forTask('translate', (string) $prompt['model']);   // LlmError غیرقابل‌تکرار اگر کلید/مدل نیست
        $segs = Db::all('SELECT `position`, source_text FROM segments WHERE document_id = ? ORDER BY `position`', [$id]);
        if (!$segs) throw new \RuntimeException('document_has_no_segments');
        $segs = array_map(fn($s) => ['position' => (int) $s['position'], 'source_text' => (string) $s['source_text']], $segs);
        $chunks = self::chunkSegments($segs);

        Triggers::updateDocument($id, ['ai_started_at' => self::iso()]);   // ضربان

        $k = (int) ($pg['k'] ?? 0);
        $out = (array) ($pg['out'] ?? []);        // [{id,translation}]
        $gloss = (array) ($pg['glossary'] ?? []);
        $notes = (array) ($pg['notes'] ?? []);
        $tries = (int) ($pg['tries'] ?? 0);
        $correction = isset($pg['correction']) ? (string) $pg['correction'] : null;
        $model = (string) ($pg['model'] ?? $llm->model());
        $entries = null;
        $fallbackModel = Secrets::get('GEMINI_FALLBACK_MODEL');

        while ($k < count($chunks)) {
            if (microtime(true) >= $job['deadline']) break;
            $part = $chunks[$k];
            if ($entries === null) {
                try { $entries = Db::all('SELECT source_term, target_term, note FROM glossary WHERE is_active = 1'); }
                catch (\Throwable $e) { Http::logLine('glossary skipped: ' . $e->getMessage()); $entries = []; }
            }
            $glossary = self::matchGlossary($entries, $part);
            try {
                $text = $llm->generate('', self::buildPrompt((string) $prompt['body'], $part, $correction, $glossary), [
                    'json' => true, 'schema' => self::RESPONSE_SCHEMA(), 'timeout' => self::CALL_TIMEOUT, 'model' => $model,
                ]);
            } catch (LlmError $e) {
                if (!$e->retryable) throw $e;
                $tries++;
                if ($tries >= self::MAX_TRIES) throw new \RuntimeException('gave_up_after_retries: ' . $e->getMessage());
                // پس از ۲ بار شکست، مدل پشتیبان (فقط gemini)
                if ($fallbackModel !== '' && $llm->provider() === 'gemini' && $tries >= 2) $model = $fallbackModel;
                return ['done' => false, 'delay' => min(30, 4 * (2 ** ($tries - 1))),
                    'progress' => ['k' => $k, 'out' => $out, 'glossary' => $gloss, 'notes' => $notes, 'tries' => $tries, 'correction' => $correction, 'model' => $model, 'last_error' => $e->getMessage()]];
            }
            $bad = null;
            try { $parsed = self::parseModelJson($text); }
            catch (\Throwable) { $parsed = null; $bad = 'response_is_not_json'; $correction = 'Your previous answer was not valid JSON.'; }
            if ($bad === null) {
                $v = self::validateResult($parsed, $part);
                if (!$v['ok']) { $bad = $v['error']; $correction = "Your previous answer was rejected: {$v['error']}."; }
            }
            if ($bad !== null) {
                $tries++;
                if ($tries >= self::MAX_TRIES) throw new \RuntimeException('gave_up_after_retries: ' . $bad);
                continue;   // دوباره با اصلاحیه (در همین برش اگر وقت هست)
            }
            foreach ($v['value']['paragraphs'] as $p) $out[] = $p;
            foreach ($v['value']['glossary'] as $g) $gloss[] = $g;
            foreach ($v['value']['notes'] as $n) $notes[] = $n;
            $k++; $tries = 0; $correction = null;
        }

        $progress = ['k' => $k, 'of' => count($chunks), 'out' => $out, 'glossary' => $gloss, 'notes' => $notes, 'tries' => $tries, 'correction' => $correction, 'model' => $model];
        if ($k < count($chunks)) return ['done' => false, 'progress' => $progress, 'delay' => 0];

        // همه‌چیز آماده است: ذخیره
        $seen = []; $glossU = [];
        foreach ($gloss as $g) { $key = $g['persian'] . "\0" . $g['english']; if (!isset($seen[$key])) { $seen[$key] = 1; $glossU[] = $g; } }
        if (!self::current($job)) return ['done' => true, 'progress' => ['stopped' => true]];
        try {
            Rpc::call('apply_ai_translation', [
                'p_document_id' => $id,
                'p_items' => array_map(fn($p) => ['pos' => $p['id'], 'translation' => $p['translation']], $out),
                'p_glossary' => $glossU, 'p_notes' => $notes, 'p_model' => $model, 'p_prompt_id' => $prompt['id'],
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('save_failed: ' . $e->getMessage());
        }
        self::startAutoReview($id);
        return ['done' => true, 'progress' => ['k' => $k, 'of' => count($chunks), 'saved' => true]];
    }

    /** هرگز ترجمه را ناموفق نمی‌کند. */
    private static function startAutoReview(string $id): void
    {
        try {
            $r = Db::val("SELECT `value` FROM app_config WHERE `key` = 'ai_review_rounds'");
            $rounds = min(3, max(0, (int) $r));
            if ($rounds < 1) return;
            AiAssist::startReview($id, $rounds);
        } catch (\Throwable $e) { Http::logLine('auto review skipped: ' . $e->getMessage()); }
    }

    // ------------------------------------------------------------------ توابع خالص (پورت lib.ts)
    /** @param list<array{position:int,source_text:string}> $segs */
    public static function chunkSegments(array $segs, int $maxItems = self::CHUNK_MAX_ITEMS, int $maxChars = self::CHUNK_MAX_CHARS): array
    {
        $out = []; $cur = []; $chars = 0;
        foreach ($segs as $s) {
            $n = mb_strlen($s['source_text']);
            if ($cur && (count($cur) >= $maxItems || $chars + $n > $maxChars)) { $out[] = $cur; $cur = []; $chars = 0; }
            $cur[] = $s; $chars += $n;
        }
        if ($cur) $out[] = $cur;
        return $out;
    }

    /** نرمال‌سازی فارسی برای تطبیق: حروف عربی، نیم‌فاصله، اعراب، ارقام، حروف کوچک. */
    public static function normFa(string $t): string
    {
        $t = strtr($t, ["\u{064A}" => "\u{06CC}", "\u{0643}" => "\u{06A9}", "\u{0629}" => "\u{0647}"]);
        $t = preg_replace('/[\x{200C}\x{200F}\x{200E}\x{0640}]/u', ' ', $t) ?? $t;
        $t = preg_replace('/[\x{064B}-\x{0670}\x{065F}]/u', '', $t) ?? $t;
        $t = preg_replace_callback('/[\x{06F0}-\x{06F9}]/u', fn($m) => (string) (mb_ord($m[0]) - 0x06F0), $t) ?? $t;
        $t = preg_replace_callback('/[\x{0660}-\x{0669}]/u', fn($m) => (string) (mb_ord($m[0]) - 0x0660), $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return mb_strtolower(trim($t));
    }

    public static function matchGlossary(array $entries, array $segs): array
    {
        $text = ' ' . implode(' ' . "\n" . ' ', array_map(fn($s) => self::normFa($s['source_text']), $segs)) . ' ';
        $m = array_values(array_filter($entries, function ($e) use ($text) {
            $k = self::normFa((string) $e['source_term']);
            return $k !== '' && str_contains($text, $k);
        }));
        usort($m, fn($a, $b) => mb_strlen((string) $b['source_term']) <=> mb_strlen((string) $a['source_term']));
        return $m;
    }

    public static function glossaryBlock(array $entries): string
    {
        if (!$entries) return '';
        $lines = array_map(fn($e) => "- {$e['source_term']} => {$e['target_term']}" . (!empty($e['note']) ? ' (' . preg_replace('/\s+/u', ' ', (string) $e['note']) . ')' : ''), $entries);
        return "\n\nMANDATORY ORGANIZATION GLOSSARY: whenever the Persian term on the left appears "
            . "(in any grammatical form), translate it exactly as the English term on the right. "
            . "Do not use synonyms for these terms.\n" . implode("\n", $lines);
    }

    /** اگر متن نشانه‌گذاری فرمت (درشت/کج/لینک/پاورقی) دارد، به مدل می‌گوییم آن را حفظ کند */
    public static function formatBlock(string $input): string
    {
        if (!preg_match('/\*\*|\*[^*\s]|\[\^\d|\]\(https?:/u', $input)) return '';
        return "\n\nFORMATTING MARKUP: some paragraphs contain simple inline markup. Keep it in the translation, moved to the matching English words: "
            . "**text** = bold, *text* = italic, [text](url) = link (translate the text, keep the url unchanged), [^1] = footnote reference mark. "
            . "A paragraph that starts with [^n] is the footnote number n: translate its text and keep the [^n] prefix unchanged. Never add or remove footnote marks, and never invent markup.";
    }

    public static function buildPrompt(string $template, array $segs, ?string $correction = null, array $glossary = []): string
    {
        if (!str_contains($template, self::PLACEHOLDER)) throw new \RuntimeException('prompt_missing_placeholder');
        $input = json_encode(array_map(fn($s) => ['id' => $s['position'], 'text' => $s['source_text']], $segs),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $p = str_replace(self::PLACEHOLDER, $input, $template) . self::glossaryBlock($glossary) . self::formatBlock($input);
        if ($correction) {
            $p .= "\n\nIMPORTANT CORRECTION: $correction\nReturn exactly " . count($segs) . ' paragraphs, using exactly the same ids as the input, in the same order.';
        }
        return $p;
    }

    public static function parseModelJson(string $text): mixed
    {
        try { return Llm::parseJson($text); } catch (LlmError) { throw new \RuntimeException('response_is_not_json'); }
    }

    /** @return array{ok:true,value:array}|array{ok:false,error:string} */
    public static function validateResult(mixed $raw, array $segs): array
    {
        if (!is_array($raw) || array_is_list($raw) && $raw !== []) return ['ok' => false, 'error' => 'response is not a JSON object'];
        $paras = $raw['paragraphs'] ?? null;
        if (!is_array($paras) || !array_is_list($paras)) return ['ok' => false, 'error' => '"paragraphs" is missing or not an array'];
        if (count($paras) !== count($segs)) return ['ok' => false, 'error' => 'expected ' . count($segs) . ' paragraphs but received ' . count($paras)];
        $out = [];
        foreach ($segs as $i => $s) {
            $p = $paras[$i];
            $exp = $s['position'];
            if (!is_array($p)) return ['ok' => false, 'error' => "paragraph at index $i is not an object"];
            $pid = $p['id'] ?? null;
            if (!(is_int($pid) || is_float($pid)) || $pid != $exp) {
                return ['ok' => false, 'error' => "paragraph at index $i has id " . (is_scalar($pid) ? (string) $pid : 'undefined') . " but id $exp was expected"];
            }
            if (!isset($p['translation']) || !is_string($p['translation'])) return ['ok' => false, 'error' => "paragraph $exp has no translation string"];
            $tr = $p['translation'];
            if (trim($s['source_text']) !== '' && trim($tr) === '') return ['ok' => false, 'error' => "paragraph $exp has an empty translation"];
            $arabic = preg_match_all('/[\x{0600}-\x{06FF}]/u', $tr);
            $latin = preg_match_all('/[A-Za-z]/', $tr);
            if ($arabic > 10 && $arabic > $latin) return ['ok' => false, 'error' => "paragraph $exp appears to be left untranslated"];
            $out[] = ['id' => $exp, 'translation' => $tr];
        }
        $gl = [];
        foreach (is_array($raw['glossary'] ?? null) ? $raw['glossary'] : [] as $g) {
            if (is_array($g) && is_string($g['persian'] ?? null) && is_string($g['english'] ?? null)) $gl[] = ['persian' => $g['persian'], 'english' => $g['english']];
        }
        $valid = array_column($segs, 'position');
        $notes = [];
        foreach (is_array($raw['notes'] ?? null) ? $raw['notes'] : [] as $n) {
            if (is_array($n) && is_string($n['note'] ?? null) && (is_int($n['paragraph_id'] ?? null) || is_float($n['paragraph_id'] ?? null)) && in_array((int) $n['paragraph_id'], $valid, true)) {
                $notes[] = ['paragraph_id' => (int) $n['paragraph_id'], 'note' => $n['note']];
            }
        }
        return ['ok' => true, 'value' => ['paragraphs' => $out, 'glossary' => $gl, 'notes' => $notes]];
    }

    /** طرح خروجی ساختاریافتهٔ Gemini (زیرمجموعهٔ OpenAPI). */
    public static function RESPONSE_SCHEMA(): array
    {
        $str = ['type' => 'STRING']; $int = ['type' => 'INTEGER'];
        $obj = fn(array $props) => ['type' => 'OBJECT', 'properties' => $props, 'required' => array_keys($props), 'propertyOrdering' => array_keys($props)];
        $arr = fn(array $item) => ['type' => 'ARRAY', 'items' => $item];
        $props = [
            'paragraphs' => $arr($obj(['id' => $int, 'translation' => $str])),
            'glossary' => $arr($obj(['persian' => $str, 'english' => $str])),
            'notes' => $arr($obj(['paragraph_id' => $int, 'note' => $str])),
        ];
        return $obj($props);
    }
}
