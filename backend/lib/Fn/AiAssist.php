<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Ctx, Db, Http, Jobs, Llm, LlmError, Rpc};

/**
 * ai-assist:
 *   {action:'review',  document_id, rounds?}   بازبینی ماشینی ترجمه (فقط پیشنهاد) → job 'ai_review'، پاسخ {status:'started', document_id, rounds}
 *   {action:'rewrite', document_id, segment_ids?, style, instruction?}  بازنویسی فارسی؛ هم‌زمان (در بودجهٔ ~24 ثانیه) → {ok, items, unchanged, partial, model}
 *   {action:'test',    task?}                  فقط admin → {ok, provider, model, reply, ms}
 * خطاهای غیرمنتظره مثل اصلی: HTTP 200 با {ok:false, error}.
 *
 * review: هر برش تکه‌های (chunk) پیش‌رو را تا deadline کار می‌کند؛ وضعیت (دور، طرح تکه‌ها، متن‌های اصلاح‌شده، یادداشت‌ها)
 * در jobs.progress می‌ماند. بعد از هر دور apply_ai_review؛ در پایان set_ai_review_state done.
 * AiAssist::startReview($docId, $rounds) را TranslateDocument بعد از ترجمه صدا می‌زند (معادل فراخوانی سرویس).
 */
final class AiAssist
{
    public const AUTH = true;
    public const PLACEHOLDER = '{{PARAGRAPHS_JSON}}';
    private const DEFAULT_GEMINI_MODEL = 'gemini-2.5-flash';
    private const STALE_REVIEW = 600;
    private const CALL_TIMEOUT = 60;
    private const REWRITE_BUDGET = 24;     // ثانیه برای کل درخواست وب
    private const MAX_TRIES = 3;

    public const DEFAULT_REVIEW_PROMPT = <<<'TXT'
You are a meticulous senior copy-editor of scholarly English (Islamic studies and the humanities). The article below was translated from Persian.

You receive the whole article as a JSON array. Each element has: "id" (paragraph number), "fa" (the Persian original, for reference only) and "en" (the English translation you must review):
{{PARAGRAPHS_JSON}}

Task: read the whole English text and list ONLY the critical language problems in it, in chronological order (the order in which they appear in the article). Give one clear correction for each problem.

What counts as a critical language problem:
- grammar and syntax errors: agreement, tense, articles, prepositions, broken or run-on sentences;
- awkward, unnatural or word-for-word phrasing that a native scholarly writer would never use;
- confusing or dangling pronouns, unmotivated shifts of person, number or gender;
- wrong or inconsistent word choice and terminology (also compare with the Persian);
- logical or conceptual confusion in the English that the Persian does not have, and clear meaning errors against the Persian.
Do NOT report: matters of personal taste, harmless style variation, punctuation preferences, or anything that is already correct.

Rules:
- A typical article has 5 to 10 such problems; report as many genuine ones as there are (up to 15). Return an empty list only if the English is truly clean.
- "problem": copy the faulty passage VERBATIM and character-for-character from the "en" text of that paragraph. Keep it as short as possible (a clause or one sentence). It must be an exact substring.
- "correction": the corrected wording of exactly that passage, ready to replace it. One correction only, no alternatives.
- "explanation": one or two short sentences IN PERSIAN saying what is wrong.
- Keep **bold**, *italic*, [^n] footnote markers and [text](url) links exactly as they are in the passage.

Return ONLY one valid JSON object, no markdown fences and no other text, exactly in this shape:
{"issues":[{"id":3,"problem":"verbatim faulty passage","correction":"the corrected passage","explanation":"توضیح کوتاه به فارسی"}]}
TXT;

    public const DEFAULT_REWRITE_PROMPT = <<<'TXT'
You are an experienced Persian news editor.

You receive a JSON array. Each element has "id" and "text" (one paragraph or heading of a Persian news item):
{{PARAGRAPHS_JSON}}

Rewrite every text in Persian according to this instruction:
{{INSTRUCTION}}

Rules:
- Keep every fact, name, number, date, title and quotation exactly as in the original. Never add information that is not in the text.
- Write natural, correct Persian (use the half-space ZWNJ properly); keep a heading short.
- If a text needs no change, return it unchanged.

Return ONLY one valid JSON object, no markdown fences and no other text, exactly in this shape:
{"items":[{"id":1,"text":"the rewritten Persian text"}]}
Return exactly one item for every input element, with the same "id" values and in the same order.
TXT;

    public const REWRITE_STYLES = [
        'polish' => ['label' => 'ویرایش و روان‌سازی', 'instruction' => "Edit for fluency, grammar, punctuation and clarity while keeping the author's wording and length as far as possible."],
        'simple' => ['label' => 'ساده‌تر', 'instruction' => 'Make it simpler and easier for a general reader: shorter sentences, common words, no change of meaning.'],
        'formal' => ['label' => 'رسمی‌تر', 'instruction' => 'Make the tone formal and official, suitable for an institutional news bulletin.'],
        'concise' => ['label' => 'خلاصه‌تر', 'instruction' => 'Make it more concise: roughly 70 percent of the original length, removing repetition and filler but no facts.'],
        'news' => ['label' => 'سبک خبری استاندارد', 'instruction' => 'Rewrite in standard news style: the most important fact first, short clear sentences, neutral tone, no personal opinion.'],
    ];

    public static function registerJobs(): void
    {
        Jobs::register('ai_review', [self::class, 'reviewSlice']);
        Jobs::onFail('ai_review', static function (array $job, \Throwable $e): void {
            self::setState((string) $job['ref_id'], 'failed', $e->getMessage());
        });
    }

    public static function handle(array $body, array $ctx): array
    {
        $action = $body['action'] ?? null;
        if (!is_string($action)) throw new ApiError('bad_request', 400);
        $role = Ctx::isService() ? 'service' : Ctx::role();
        try {
            if ($action === 'test') return self::handleTest($role, $body);
            if ($action === 'review') return self::handleReview($role, $body);
            if ($action === 'rewrite') return self::handleRewrite($role, $body);
        } catch (ApiError $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException) Http::logLine('ai-assist: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        throw new ApiError('unknown_action', 400);
    }

    private static function isUuid(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    private static function getPrompt(string $key, string $fallback): string
    {
        $v = (string) (Db::val('SELECT `value` FROM app_config WHERE `key` = ?', [$key]) ?? '');
        return str_contains($v, self::PLACEHOLDER) ? $v : $fallback;
    }

    private static function setState(string $doc, string $state, ?string $err): void
    {
        Rpc::call('set_ai_review_state', ['p_document' => $doc, 'p_state' => $state, 'p_error' => $err]);
    }

    // ------------------------------------------------------------------ test
    private static function handleTest(string $role, array $body): array
    {
        if ($role !== 'admin') throw new ApiError('forbidden', 403);
        $task = in_array($body['task'] ?? '', ['translate', 'review', 'rewrite'], true) ? $body['task'] : 'translate';
        $llm = Llm::forTask($task, self::DEFAULT_GEMINI_MODEL);
        $t0 = microtime(true);
        $reply = $llm->generate('', 'Reply with exactly the single word: OK', ['timeout' => 22]);
        return ['ok' => true, 'provider' => $llm->provider(), 'model' => $llm->model(), 'reply' => mb_substr(trim($reply), 0, 60), 'ms' => (int) ((microtime(true) - $t0) * 1000)];
    }

    // ------------------------------------------------------------------ review
    private static function handleReview(string $role, array $body): array
    {
        $id = $body['document_id'] ?? null;
        if (!self::isUuid($id)) throw new ApiError('document_id_required', 400);
        $id = strtolower($id);
        $doc = Db::one('SELECT id, status, claimed_by FROM documents WHERE id = ?', [$id]);
        if (!$doc) throw new ApiError('document_not_found', 404);
        $ok = $role === 'service' || $role === 'admin'
            || ($role === 'supervisor' && $doc['status'] === 'awaiting_qa')
            || ($role === 'translator' && $doc['claimed_by'] === Ctx::uid() && in_array($doc['status'], ['in_progress', 'changes_requested'], true));
        if (!$ok) throw new ApiError('forbidden', 403);
        $rounds = min(3, max(1, (int) floor((float) ($body['rounds'] ?? 1)) ?: 1));
        self::startReview($id, $rounds);
        return ['status' => 'started', 'document_id' => $id, 'rounds' => $rounds];
    }

    /** بدون بررسی نقش (فراخوان مسئول است): وضعیت سند، در حال اجرا نبودن، علامت running و ثبت job. */
    public static function startReview(string $id, int $rounds): void
    {
        $doc = Db::one('SELECT id, status, ai_review_state, ai_review_at FROM documents WHERE id = ?', [$id]);
        if (!$doc) throw new ApiError('document_not_found', 404);
        if (!in_array($doc['status'], ['in_pool', 'in_progress', 'changes_requested', 'awaiting_qa'], true)) {
            throw new ApiError('wrong_status', 409, null, $doc['status']);
        }
        if ($doc['ai_review_state'] === 'running' && $doc['ai_review_at'] && strtotime($doc['ai_review_at'] . ' UTC') > time() - self::STALE_REVIEW) {
            throw new ApiError('already_running', 409);
        }
        self::setState($id, 'running', null);
        Db::exec("UPDATE jobs SET state = 'done' WHERE kind = 'ai_review' AND ref_id = ? AND state = 'queued'", [$id]);   // جانشین کار قدیمی
        Jobs::enqueue('ai_review', $id, ['rounds' => max(1, min(3, $rounds))]);
    }

    public static function reviewSlice(array $job): array
    {
        $id = (string) $job['ref_id'];
        $rounds = (int) ($job['payload']['rounds'] ?? 1);
        $pg = json_decode((string) ($job['progress'] ?? ''), true);
        $pg = is_array($pg) ? $pg : [];
        try {
            return self::reviewWork($job, $id, $rounds, $pg);
        } catch (LlmError $e) {
            if ($e->retryable) {
                $t = (int) ($pg['tries'] ?? 0) + 1;
                if ($t < self::MAX_TRIES) { $pg['tries'] = $t; return ['done' => false, 'progress' => $pg, 'delay' => min(15, 3 * (2 ** ($t - 1)))]; }
                self::setState($id, 'failed', 'gave_up_after_retries: ' . $e->getMessage());
            } else {
                self::setState($id, 'failed', $e->getMessage());
            }
            return ['done' => true, 'progress' => ['failed' => $e->getMessage()]];
        } catch (\RuntimeException $e) {
            if ($e instanceof ApiError || $e instanceof \PDOException) throw $e;
            Http::logLine('review failed ' . $id . ': ' . $e->getMessage());
            self::setState($id, 'failed', $e->getMessage());
            return ['done' => true, 'progress' => ['failed' => $e->getMessage()]];
        }
    }

    private static function reviewWork(array $job, string $id, int $rounds, array $pg): array
    {
        $llm = Llm::forTask('review', self::DEFAULT_GEMINI_MODEL);
        $segs = Db::all('SELECT `position`, source_text, ai_text, current_text FROM segments WHERE document_id = ? ORDER BY `position`', [$id]);
        if (!$segs) throw new \RuntimeException('segments_read_failed');
        self::setState($id, 'running', null);   // ضربان

        $orig = []; $cur = []; $fa = [];
        foreach ($segs as $s) {
            $p = (int) $s['position'];
            $t = (string) ($s['current_text'] ?? $s['ai_text'] ?? '');
            $orig[$p] = $t; $cur[$p] = $t; $fa[$p] = (string) $s['source_text'];
        }
        foreach ((array) ($pg['cur'] ?? []) as $p => $t) $cur[(int) $p] = (string) $t;
        $notes = [];
        foreach ((array) ($pg['notes'] ?? []) as $p => $a) $notes[(int) $p] = array_values((array) $a);
        $round = (int) ($pg['round'] ?? 1);
        $plan = $pg['plan'] ?? null;
        $k = (int) ($pg['k'] ?? 0);
        $tries = (int) ($pg['tries'] ?? 0);
        $correction = isset($pg['correction']) ? (string) $pg['correction'] : null;
        $template = null; $gloss = null;

        $save = function (bool $retryDelay = false) use (&$round, &$plan, &$k, &$tries, &$correction, &$cur, &$orig, &$notes): array {
            $changed = [];
            foreach ($cur as $p => $t) if ($t !== $orig[$p]) $changed[$p] = $t;
            return ['round' => $round, 'plan' => $plan, 'k' => $k, 'tries' => $tries, 'correction' => $correction, 'cur' => (object) $changed, 'notes' => (object) $notes];
        };

        while ($round <= $rounds) {
            if ($plan === null) {
                $input = [];
                foreach ($segs as $s) {
                    $p = (int) $s['position'];
                    if (trim($cur[$p]) !== '') $input[] = ['id' => $p, 'fa' => $fa[$p], 'en' => $cur[$p]];
                }
                $plan = array_map(fn($c) => array_column($c, 'id'), self::chunk($input, 600, 60000));   // کل مقاله یک‌جا (مثل سایت جمینای)؛ فقط مقالهٔ خیلی بلند چند تکه می‌شود
                $k = 0;
            }
            while ($k < count($plan)) {
                if (microtime(true) >= $job['deadline']) return ['done' => false, 'progress' => $save(), 'delay' => 0];
                $template ??= self::getPrompt('ai_prompt_review', self::DEFAULT_REVIEW_PROMPT);
                if ($gloss === null) {
                    try { $gloss = Db::all('SELECT source_term, target_term, note FROM glossary WHERE is_active = 1'); }
                    catch (\Throwable $e) { Http::logLine('glossary skipped: ' . $e->getMessage()); $gloss = []; }
                }
                $part = array_map(fn($p) => ['id' => $p, 'fa' => $fa[$p], 'en' => $cur[$p]], $plan[$k]);
                $matched = self::matchGlossary($gloss, array_column($part, 'fa'));
                $prompt = self::withCorrection(self::fillTemplate($template, $part) . self::glossaryBlock($matched), $correction);
                try {
                    $text = $llm->generate('', $prompt, ['json' => true, 'timeout' => self::CALL_TIMEOUT]);
                } catch (LlmError $e) {
                    if (!$e->retryable) throw $e;
                    $tries++;
                    if ($tries >= self::MAX_TRIES) throw new \RuntimeException('gave_up_after_retries: ' . $e->getMessage());
                    return ['done' => false, 'progress' => $save(), 'delay' => min(15, 3 * (2 ** ($tries - 1)))];   // بدون sleep؛ پیشرفت تکه‌های قبلی حفظ می‌شود
                }
                $bad = null;
                try { $parsed = Llm::parseJson($text); } catch (LlmError) { $parsed = null; $bad = 'response_is_not_json'; $correction = 'Your previous answer was not valid JSON.'; }
                if ($bad === null && is_array($parsed) && array_key_exists('issues', $parsed)) {
                    // حالت فهرست ایرادها: فقط ایرادهای مهم زبانی با یک اصلاح؛ اصلاح روی همان عبارت اعمال می‌شود
                    $iv = self::validateIssues($parsed, array_column($part, 'id'));
                    if (!$iv['ok']) { $bad = $iv['error']; $correction = "Your previous answer was rejected: {$iv['error']}."; }
                    else {
                        foreach ($iv['value'] as $r) {
                            $before = $cur[$r['id']] ?? '';
                            $after = self::applyIssue($before, $r['problem'], $r['correction']);
                            if ($after !== null) { $cur[$r['id']] = $after; $msg = $r['explanation']; }
                            else $msg = trim($r['explanation'] . ' (پیشنهاد: «' . $r['correction'] . '»؛ عبارت دقیقاً در متن پیدا نشد و خودکار جایگزین نشد.)');
                            if ($msg !== '') { $a = $notes[$r['id']] ?? []; if (!in_array($msg, $a, true)) $a[] = $msg; $notes[$r['id']] = $a; }
                        }
                    }
                } elseif ($bad === null) {
                    $v = self::validateReview($parsed, $part);
                    if (!$v['ok']) { $bad = $v['error']; $correction = "Your previous answer was rejected: {$v['error']}."; }
                    else foreach ($v['value'] as $r) {
                        if (!self::sameText($r['translation'], $cur[$r['id']] ?? '')) $cur[$r['id']] = $r['translation'];
                        if ($r['note'] !== '') { $a = $notes[$r['id']] ?? []; if (!in_array($r['note'], $a, true)) $a[] = $r['note']; $notes[$r['id']] = $a; }
                    }
                }
                if ($bad !== null) {
                    $tries++;
                    if ($tries >= self::MAX_TRIES) throw new \RuntimeException('gave_up_after_retries: ' . $bad);
                    continue;
                }
                $k++; $tries = 0; $correction = null;
            }
            $items = [];
            foreach ($segs as $s) {
                $p = (int) $s['position'];
                $items[] = ['pos' => $p, 'text' => self::sameText($cur[$p], $orig[$p]) ? '' : $cur[$p], 'note' => implode(' | ', $notes[$p] ?? [])];
            }
            try { Rpc::call('apply_ai_review', ['p_document' => $id, 'p_items' => $items, 'p_round' => $round]); }
            catch (\Throwable $e) { throw new \RuntimeException('save_failed: ' . $e->getMessage()); }
            $round++; $plan = null; $k = 0;
        }
        self::setState($id, 'done', null);
        return ['done' => true, 'progress' => ['round' => $rounds, 'finished' => true]];
    }

    // ------------------------------------------------------------------ rewrite (هم‌زمان)
    private static function handleRewrite(string $role, array $body): array
    {
        if (!in_array($role, ['admin', 'news_editor', 'reviewer'], true)) throw new ApiError('forbidden', 403);
        $t0 = microtime(true);
        $id = $body['document_id'] ?? null;
        if (!self::isUuid($id)) throw new ApiError('document_id_required', 400);
        $doc = Db::one('SELECT id, status, source_kind FROM documents WHERE id = ?', [strtolower($id)]);
        if (!$doc) throw new ApiError('document_not_found', 404);
        $okDoc = $role === 'news_editor'
            ? ($doc['status'] === 'news_edit' && $doc['source_kind'] === 'news')
            : in_array($doc['status'], ['news_edit', 'content_review', 'review_rejected'], true);
        if (!$okDoc) throw new ApiError('wrong_status', 409, null, $doc['status']);

        $sql = 'SELECT id, `position`, source_text FROM segments WHERE document_id = ?'; $p = [$doc['id']];
        if (is_array($body['segment_ids'] ?? null) && $body['segment_ids']) {
            $ids = array_slice(array_values(array_filter($body['segment_ids'], fn($x) => self::isUuid($x))), 0, 120);
            $sql .= ' AND id IN ' . Db::in($ids); $p = array_merge($p, $ids);
        }
        $rows = array_values(array_filter(Db::all($sql . ' ORDER BY `position`', $p), fn($s) => trim((string) $s['source_text']) !== ''));
        $rows = array_slice($rows, 0, 120);
        if (!$rows) return ['ok' => false, 'error' => 'no_text_selected'];

        $style = (string) ($body['style'] ?? '');
        $instruction = self::REWRITE_STYLES[$style]['instruction'] ?? '';
        $extra = is_string($body['instruction'] ?? null) ? mb_substr(trim($body['instruction']), 0, 500) : '';
        if ($instruction === '' && $extra === '') return ['ok' => false, 'error' => 'style_required'];
        if ($extra !== '') $instruction .= ($instruction !== '' ? "\nAdditional instruction from the editor: " : '') . $extra;

        $llm = Llm::forTask('rewrite', self::DEFAULT_GEMINI_MODEL);
        $template = self::getPrompt('ai_prompt_rewrite', self::DEFAULT_REWRITE_PROMPT);
        $byPos = [];
        foreach ($rows as $r) $byPos[(int) $r['position']] = $r;
        $input = array_map(fn($r) => ['id' => (int) $r['position'], 'text' => (string) $r['source_text']], $rows);
        $budget = (float) \Rise\Config::get('ai.rewrite_budget', self::REWRITE_BUDGET);
        $items = []; $partial = false; $unchanged = 0; $lastErr = 'unknown_error';
        foreach (self::chunk($input, 12, 5000) as $part) {
            $correction = null; $done = false;
            for ($attempt = 1; $attempt <= self::MAX_TRIES; $attempt++) {
                $left = $budget - (microtime(true) - $t0);
                if ($left < 6) break;
                try {
                    $text = $llm->generate('', self::withCorrection(self::fillTemplate($template, $part, ['INSTRUCTION' => $instruction]), $correction),
                        ['json' => true, 'timeout' => min(self::CALL_TIMEOUT, $left - 1)]);
                } catch (LlmError $e) {
                    if (!$e->retryable) throw $e;
                    $lastErr = $e->getMessage();
                    continue;
                }
                try { $parsed = Llm::parseJson($text); } catch (LlmError) { $lastErr = 'response_is_not_json'; $correction = 'Your previous answer was not valid JSON.'; continue; }
                $v = self::validateRewrite($parsed, $part);
                if (!$v['ok']) { $lastErr = $v['error']; $correction = "Your previous answer was rejected: {$v['error']}."; continue; }
                foreach ($v['value'] as $o) {
                    $row = $byPos[$o['id']];
                    if (self::sameText($o['text'], (string) $row['source_text'])) $unchanged++;
                    else $items[] = ['id' => $row['id'], 'text' => $o['text']];
                }
                $done = true;
                break;
            }
            if (!$done) {
                $partial = true;
                if (!$items && !$unchanged) throw new \RuntimeException('gave_up_after_retries: ' . $lastErr);
                break;
            }
        }
        return ['ok' => true, 'items' => $items, 'unchanged' => $unchanged, 'partial' => $partial, 'model' => $llm->provider() . ':' . $llm->model()];
    }

    // ------------------------------------------------------------------ توابع خالص (پورت lib.ts)
    public static function withCorrection(string $p, ?string $c): string { return $c ? "$p\n\nIMPORTANT CORRECTION: $c" : $p; }

    public static function sameText(string $a, string $b): bool
    {
        $n = fn(string $s) => trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        return $n($a) === $n($b);
    }

    public static function fillTemplate(string $template, array $segs, array $extra = []): string
    {
        if (!str_contains($template, self::PLACEHOLDER)) throw new \RuntimeException('prompt_missing_placeholder');
        $out = $template;
        foreach ($extra as $k => $v) $out = str_replace('{{' . $k . '}}', $v, $out);
        return str_replace(self::PLACEHOLDER, (string) json_encode($segs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $out);
    }

    public static function glossaryBlock(array $entries): string
    {
        if (!$entries) return '';
        $lines = array_map(fn($e) => "- {$e['source_term']} => {$e['target_term']}" . (!empty($e['note']) ? ' (' . preg_replace('/\s+/u', ' ', (string) $e['note']) . ')' : ''), $entries);
        return "\n\nMANDATORY ORGANIZATION GLOSSARY: whenever the Persian term on the left appears (in any grammatical form) the English must use exactly the term on the right.\n" . implode("\n", $lines);
    }

    /** (نسخهٔ ai-assist: بدون تبدیل ارقام) */
    public static function normFa(string $t): string
    {
        $t = strtr($t, ["\u{064A}" => "\u{06CC}", "\u{0643}" => "\u{06A9}", "\u{0629}" => "\u{0647}"]);
        $t = preg_replace('/[\x{200C}\x{200F}\x{200E}\x{0640}]/u', ' ', $t) ?? $t;
        $t = preg_replace('/[\x{064B}-\x{0670}]/u', '', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return mb_strtolower(trim($t));
    }

    public static function matchGlossary(array $entries, array $texts): array
    {
        $hay = ' ' . implode(" \n ", array_map([self::class, 'normFa'], $texts)) . ' ';
        $m = array_values(array_filter($entries, function ($e) use ($hay) {
            $k = self::normFa((string) $e['source_term']);
            return $k !== '' && str_contains($hay, $k);
        }));
        usort($m, fn($a, $b) => mb_strlen((string) $b['source_term']) <=> mb_strlen((string) $a['source_term']));
        return $m;
    }

    /** تکه‌تکه کردن تا پاسخ در سقف خروجی مدل بماند. */
    public static function chunk(array $items, int $maxItems = 25, int $maxChars = 12000): array
    {
        $out = []; $cur = []; $chars = 0;
        foreach ($items as $it) {
            $n = mb_strlen((string) ($it['text'] ?? '')) + mb_strlen((string) ($it['fa'] ?? '')) + mb_strlen((string) ($it['en'] ?? ''));
            if ($cur && (count($cur) >= $maxItems || $chars + $n > $maxChars)) { $out[] = $cur; $cur = []; $chars = 0; }
            $cur[] = $it; $chars += $n;
        }
        if ($cur) $out[] = $cur;
        return $out;
    }

    private static function itemsById(mixed $raw, string $key): ?array
    {
        if (!is_array($raw) || !is_array($raw['items'] ?? null)) return null;
        $by = [];
        foreach ($raw['items'] as $x) {
            if (is_array($x) && (is_int($x['id'] ?? null) || is_float($x['id'] ?? null))) $by[(int) $x['id']] = $x;
        }
        return $by;
    }

    /** @return array{ok:true,value:list<array>}|array{ok:false,error:string} */
    public static function validateIssues(mixed $raw, array $ids): array
    {
        if (!is_array($raw) || !is_array($raw['issues'] ?? null)) return ['ok' => false, 'error' => '"issues" is missing or not an array'];
        $out = [];
        foreach (array_slice(array_values($raw['issues']), 0, 20) as $x) {
            if (!is_array($x)) continue;
            $id = $x['id'] ?? null;
            if (is_string($id) && ctype_digit($id)) $id = (int) $id;
            if (is_float($id)) $id = (int) $id;
            if (!is_int($id) || !in_array($id, $ids, true)) continue;
            $problem = is_string($x['problem'] ?? null) ? trim($x['problem']) : '';
            $corr = is_string($x['correction'] ?? null) ? trim($x['correction']) : '';
            if ($problem === '' || $corr === '' || self::sameText($problem, $corr)) continue;
            $out[] = ['id' => $id, 'problem' => $problem, 'correction' => $corr, 'explanation' => is_string($x['explanation'] ?? null) ? trim($x['explanation']) : ''];
        }
        return ['ok' => true, 'value' => $out];
    }

    /** عبارت معیوب را (دقیق، وگرنه با فاصله‌های نرمال‌شده) در متن پیدا و با اصلاح عوض می‌کند؛ پیدا نشد → null */
    public static function applyIssue(string $text, string $problem, string $correction): ?string
    {
        $pos = mb_strpos($text, $problem);
        if ($pos !== false) return mb_substr($text, 0, $pos) . $correction . mb_substr($text, $pos + mb_strlen($problem));
        $words = preg_split('/\s+/u', trim($problem), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words || count($words) < 2) return null;
        $re = '/' . implode('\\s+', array_map(static fn($w) => preg_quote($w, '/'), $words)) . '/u';
        if (!preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) return null;
        return substr($text, 0, $m[0][1]) . $correction . substr($text, $m[0][1] + strlen($m[0][0]));
    }

    /** @return array{ok:true,value:list<array>}|array{ok:false,error:string} */
    public static function validateReview(mixed $raw, array $input): array
    {
        $by = self::itemsById($raw, 'items');
        if ($by === null) return ['ok' => false, 'error' => '"items" is missing or not an array'];
        $out = [];
        foreach ($input as $s) {
            $r = $by[$s['id']] ?? null;
            if (!$r) return ['ok' => false, 'error' => "item with id {$s['id']} is missing"];
            if (!is_string($r['translation'] ?? null)) return ['ok' => false, 'error' => "item {$s['id']} has no translation string"];
            if (trim($s['en']) !== '' && trim($r['translation']) === '') return ['ok' => false, 'error' => "item {$s['id']} has an empty translation"];
            $arabic = preg_match_all('/[\x{0600}-\x{06FF}]/u', $r['translation']);
            $latin = preg_match_all('/[A-Za-z]/', $r['translation']);
            if ($arabic > 10 && $arabic > $latin) return ['ok' => false, 'error' => "item {$s['id']} is not in English"];
            $out[] = ['id' => $s['id'], 'translation' => trim($r['translation']), 'note' => is_string($r['note'] ?? null) ? trim($r['note']) : ''];
        }
        return ['ok' => true, 'value' => $out];
    }

    public static function validateRewrite(mixed $raw, array $input): array
    {
        $by = self::itemsById($raw, 'items');
        if ($by === null) return ['ok' => false, 'error' => '"items" is missing or not an array'];
        $out = [];
        foreach ($input as $s) {
            $r = $by[$s['id']] ?? null;
            if (!$r) return ['ok' => false, 'error' => "item with id {$s['id']} is missing"];
            if (!is_string($r['text'] ?? null)) return ['ok' => false, 'error' => "item {$s['id']} has no text string"];
            if (trim($s['text']) !== '' && trim($r['text']) === '') return ['ok' => false, 'error' => "item {$s['id']} is empty"];
            $out[] = ['id' => $s['id'], 'text' => trim($r['text'])];
        }
        return ['ok' => true, 'value' => $out];
    }
}
