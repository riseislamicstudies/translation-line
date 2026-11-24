<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Config, Ctx, Db, Http, Llm, LlmError, Rpc, Secrets};

/**
 * ai-content (پورت Edge Function):
 *   {action:'curate', limit?}                  news_editor/admin/service: نمره‌دهی به خبرهای ‌news_edit که هنوز گلچین نشده‌اند
 *   {action:'seo_candidates', document_id}     گام ۱ سئو: کلیدواژه‌های پیشنهادی + اعداد واقعی Search Console
 *   {action:'seo', document_id, keyword?}      گام ۲ سئو: پیشنهاد فیلدها + چک‌لیست + پیشنهادهای بهبود
 *   {action:'gsc_test'}                        فقط admin
 *
 * هر اکشن هم‌زمان است و بودجهٔ کل درخواست ~24.5 ثانیه است (یک تماس LLM با timeout ≤ 22 ثانیه).
 * curate تا ۱۵ خبر را در یک تماس نمره می‌دهد؛ مرورگر خودش تا ۶ بار پشت‌سرهم صدا می‌زند (remaining)، پس job لازم نیست.
 * خطاهای غیرمنتظره مثل اصلی: HTTP 200 با {ok:false, error}.
 * تلاش دوبارهٔ askJson فقط تا جایی که بودجهٔ زمانی اجازه بدهد انجام می‌شود (بدون sleep طولانی).
 * Deviation: ردیف‌های GSC تا ۱۵ دقیقه در private/gsc_cache.json کش می‌شوند (گام‌های ۱ و ۲ سئو پشت‌سرهم صدا زده می‌شوند).
 */
final class AiContent
{
    public const AUTH = true;
    private const DEFAULT_CRITERIA = 'Prefer news that is important, new, factual and relevant to Islamic studies, Iran and the Muslim world, religion, culture and thought. Penalise celebrity gossip, crime reports, sports results, advertising and clickbait.';
    private const BUDGET = 24.5;      // ثانیه از شروع درخواست
    private const MAX_CALL = 22;      // سقف timeout یک تماس LLM
    private const MIN_CALL = 6;       // کمتر از این بودجه → تماس نزن
    private const GSC_CACHE_TTL = 900;

    private static float $t0 = 0.0;

    public static function handle(array $body, array $ctx): array
    {
        self::$t0 = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $action = $body['action'] ?? null;
        if (!is_string($action)) throw new ApiError('bad_request', 400);
        $role = Ctx::isService() ? 'service' : Ctx::role();
        try {
            if ($action === 'curate') return self::curate($role, $body);
            if ($action === 'seo') return self::seo($role, $body);
            if ($action === 'seo_candidates') return self::seoCandidates($role, $body);
            if ($action === 'gsc_test') return self::gscTest($role);
        } catch (ApiError $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException) { Http::logLine('ai-content: ' . $e->getMessage()); return ['ok' => false, 'error' => 'database_error']; }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        throw new ApiError('unknown_action', 400);
    }

    // ------------------------------------------------------------------ helpers
    private static function isUuid(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    /** معادل Number(x) در JS (برای مقدارهای JSON) */
    public static function jsNum(mixed $v): float
    {
        if ($v === null) return 0.0;
        if (is_bool($v)) return $v ? 1.0 : 0.0;
        if (is_int($v) || is_float($v)) return (float) $v;
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') return 0.0;
            return is_numeric($t) ? (float) $t : NAN;
        }
        return NAN;
    }

    private static function remaining(): float { return self::BUDGET - (microtime(true) - self::$t0); }

    /** timeout مجاز برای تماس بعدی LLM یا null اگر بودجه تمام شده */
    private static function callTimeout(): ?int
    {
        $r = self::remaining();
        return $r < self::MIN_CALL ? null : (int) min(self::MAX_CALL, floor($r));
    }

    public static function parseJson(string $text): mixed
    {
        $c = preg_replace('/\s*```$/', '', preg_replace('/^```(?:json)?\s*/i', '', trim($text)));
        $j = json_decode($c, true);
        if ($j !== null || trim($c) === 'null') return $j;
        $a = strpos($c, '{'); $b = strrpos($c, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $j = json_decode(substr($c, $a, $b - $a + 1), true);
            if ($j !== null) return $j;
        }
        throw new \RuntimeException('response_is_not_json');
    }

    /**
     * یک پرامپت → JSON اعتبارسنجی‌شده. $validate(raw): مقدار نهایی یا string (دلیل رد).
     * تا ۳ تلاش، به شرط باقی ماندن بودجهٔ زمانی.
     */
    private static function askJson(Llm $llm, string $prompt, callable $validate): mixed
    {
        $corr = ''; $last = 'unknown';
        for ($i = 1; $i <= 3; $i++) {
            $tmo = self::callTimeout();
            if ($tmo === null) { $last = $last === 'unknown' ? 'time_budget_exceeded' : $last; break; }
            try {
                $text = $llm->generate('', $corr !== '' ? "$prompt\n\nIMPORTANT CORRECTION: $corr" : $prompt, ['json' => true, 'timeout' => $tmo]);
            } catch (LlmError $e) {
                if ($e->retryable) { $last = $e->getMessage(); if (self::remaining() > self::MIN_CALL + 2) usleep(1_000_000); continue; }
                throw $e;
            }
            try { $raw = self::parseJson($text); } catch (\RuntimeException) { $last = 'response_is_not_json'; $corr = 'Your previous answer was not valid JSON.'; continue; }
            $v = $validate($raw);
            if (is_string($v)) { $last = $v; $corr = "Your previous answer was rejected: $v."; continue; }
            return $v;
        }
        throw new \RuntimeException("gave_up_after_retries: $last");
    }

    private static function cfgMap(array $keys): array
    {
        $c = [];
        foreach (Db::all('SELECT `key`, `value` FROM app_config WHERE `key` IN ' . Db::in($keys), $keys) as $r) $c[$r['key']] = (string) ($r['value'] ?? '');
        return $c;
    }

    private static function clip(string $s, int $n): string { return mb_substr($s, 0, $n); }

    // ------------------------------------------------------------------ news curation
    private static function curate(string $role, array $body): array
    {
        if (!in_array($role, ['service', 'admin', 'news_editor'], true)) throw new ApiError('forbidden', 403);
        $c = self::cfgMap(['news_curate_mode', 'news_curate_threshold', 'news_criteria']);
        $m = $c['news_curate_mode'] ?? '';
        $mode = $m === 'auto' ? 'auto' : ($m === 'score' ? 'score' : 'off');
        if ($role === 'service' && $mode === 'off') return ['ok' => true, 'skipped' => 'off'];
        $discardOk = $mode === 'auto';
        $th = self::jsNum($c['news_curate_threshold'] ?? ''); if (is_nan($th) || $th == 0) $th = 60;
        $threshold = (int) min(100, max(1, $th));
        $criteria = self::clip(trim($c['news_criteria'] ?? ''), 4000);
        if ($criteria === '') $criteria = self::DEFAULT_CRITERIA;
        $lim = floor(self::jsNum($body['limit'] ?? 12)); if (is_nan($lim) || $lim == 0) $lim = 12;
        $limit = (int) min(15, max(1, $lim));

        $docs = Db::all("SELECT id, title, source_name, created_at FROM documents WHERE status = 'news_edit' AND source_kind = 'news' AND news_curated_at IS NULL ORDER BY created_at DESC LIMIT $limit");
        if (!$docs) return ['ok' => true, 'scored' => 0, 'discarded' => 0, 'remaining' => 0];
        $ids = array_column($docs, 'id');
        $lead = [];
        foreach (Db::all('SELECT document_id, `position`, source_text FROM segments WHERE document_id IN ' . Db::in($ids) . ' AND `position` <= 4 ORDER BY `position`', $ids) as $s) {
            $lead[$s['document_id']] = trim(($lead[$s['document_id']] ?? '') . ' ' . ($s['source_text'] ?? ''));
        }
        $input = [];
        foreach ($docs as $i => $d) {
            $input[] = ['id' => $i + 1, 'title' => $d['title'], 'source' => $d['source_name'] ?? '', 'lead' => self::clip($lead[$d['id']] ?? '', 700)];
        }
        $n = count($input);

        $llm = Llm::forTask('curate');
        $prompt = "You are the news curator of a Persian-language Islamic-studies publication. Score each news item from 0 to 100 for how well it fits the editorial criteria below (100 = must publish, 0 = irrelevant). Be decisive and use the whole range.\n\n"
            . "EDITORIAL CRITERIA (written by the editors, in their own words):\n$criteria\n\n"
            . "Return ONLY JSON: {\"items\":[{\"id\":<number>,\"score\":<0-100 integer>,\"reason\":\"<one short Persian sentence, max 120 characters, saying why>\"}]}\n"
            . "Return exactly one entry for every input id.\n\n"
            . "NEWS ITEMS (JSON):\n" . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = self::askJson($llm, $prompt, static fn($raw) => self::validateCurate($raw, $n));

        $discarded = 0;
        foreach ($docs as $i => $d) {
            $r = $out[$i + 1];
            $discard = $discardOk && $r['score'] < $threshold;
            try {
                Rpc::call('news_curate_apply', ['p_doc' => $d['id'], 'p_score' => $r['score'], 'p_reason' => $r['reason'], 'p_discard' => $discard]);
            } catch (ApiError | \PDOException $e) {
                throw new \RuntimeException('save_failed: ' . ($e instanceof ApiError ? $e->getMessage() : 'database_error'));
            }
            if ($discard) $discarded++;
        }
        $count = (int) Db::val("SELECT COUNT(*) FROM documents WHERE status = 'news_edit' AND source_kind = 'news' AND news_curated_at IS NULL");
        return ['ok' => true, 'scored' => $n, 'discarded' => $discarded, 'remaining' => $count, 'mode' => $mode, 'threshold' => $threshold, 'model' => $llm->provider() . ':' . $llm->model()];
    }

    /** @return array<int,array{score:int,reason:string}>|string */
    public static function validateCurate(mixed $raw, int $n): array|string
    {
        $it = is_array($raw) ? ($raw['items'] ?? null) : null;
        if (!is_array($it) || !array_is_list($it)) return 'missing items array';
        $m = [];
        foreach ($it as $x) {
            if (!is_array($x)) continue;
            $id = array_key_exists('id', $x) ? self::jsNum($x['id']) : NAN;
            $sc = array_key_exists('score', $x) ? self::jsNum($x['score']) : NAN;
            if (is_finite($id) && floor($id) == $id && is_finite($sc) && $id >= 1 && $id <= $n) {
                $reason = $x['reason'] ?? '';
                $reason = is_scalar($reason) ? (string) $reason : '';
                $m[(int) $id] = ['score' => (int) floor(min(100, max(0, $sc)) + 0.5), 'reason' => self::clip($reason, 200)];
            }
        }
        return count($m) === $n ? $m : "expected $n items with ids 1..$n, got " . count($m);
    }

    // ------------------------------------------------------------------ Google Search Console
    private static function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }

    private static function curl(string $url, string $method, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) throw new \RuntimeException($err !== '' ? $err : 'request_failed');
        return [$st, (string) $raw];
    }

    /** @return array{token?:string,site:string,error?:string} */
    private static function gscToken(int $timeout = 6): array
    {
        $site = trim((string) (Db::val("SELECT `value` FROM app_config WHERE `key` = 'gsc_site'") ?? ''));
        $sec = Secrets::get('GSC_SERVICE_ACCOUNT');
        if ($site === '' || $sec === '') return ['site' => $site, 'error' => 'not_connected'];
        $sa = json_decode($sec, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) return ['site' => $site, 'error' => 'service_account_json_invalid'];
        try {
            $now = time();
            $head = self::b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = self::b64u(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3000], JSON_UNESCAPED_SLASHES));
            $key = openssl_pkey_get_private((string) $sa['private_key']);
            if (!$key || !openssl_sign("$head.$claim", $sig, $key, OPENSSL_ALGO_SHA256)) throw new \RuntimeException('private key unusable');
            [$st, $raw] = self::curl('https://oauth2.googleapis.com/token', 'POST', ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claim." . self::b64u($sig)]), $timeout);
            $j = json_decode($raw, true); $j = is_array($j) ? $j : [];
            if ($st < 200 || $st >= 300 || empty($j['access_token'])) {
                return ['site' => $site, 'error' => self::clip("google_token_$st: " . ($j['error_description'] ?? $j['error'] ?? ''), 200)];
            }
            return ['token' => (string) $j['access_token'], 'site' => $site];
        } catch (\Throwable $e) {
            return ['site' => $site, 'error' => self::clip('google_auth_failed: ' . $e->getMessage(), 200)];
        }
    }

    private static function gscCacheFile(): string { return Config::privateDir() . '/gsc_cache.json'; }

    /**
     * جستجوهای واقعی ۹۰ روز اخیر سایت (تا ۱۰۰۰، به ترتیب impressions).
     * @return array{rows:array,error?:string,site:string}
     */
    private static function gscQueries(bool $forceFresh = false): array
    {
        $site = trim((string) (Db::val("SELECT `value` FROM app_config WHERE `key` = 'gsc_site'") ?? ''));
        $f = self::gscCacheFile();
        if (!$forceFresh && $site !== '' && is_file($f) && filemtime($f) > time() - self::GSC_CACHE_TTL) {
            $c = json_decode((string) file_get_contents($f), true);
            if (is_array($c) && ($c['site'] ?? null) === $site && is_array($c['rows'] ?? null)) return ['rows' => $c['rows'], 'site' => $site];
        }
        $t0 = microtime(true);
        $t = self::gscToken(5);
        if (empty($t['token'])) return ['rows' => [], 'error' => $t['error'] ?? 'not_connected', 'site' => $t['site']];
        $day = static fn(int $d) => gmdate('Y-m-d', time() - $d * 86400);
        $tmo = max(3, (int) (9 - (microtime(true) - $t0)));
        try {
            [$st, $raw] = self::curl('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($t['site']) . '/searchAnalytics/query', 'POST',
                ['Authorization: Bearer ' . $t['token'], 'Content-Type: application/json'],
                json_encode(['startDate' => $day(92), 'endDate' => $day(2), 'dimensions' => ['query'], 'rowLimit' => 1000]), $tmo);
            $j = json_decode($raw, true); $j = is_array($j) ? $j : [];
            if ($st < 200 || $st >= 300) return ['rows' => [], 'site' => $t['site'], 'error' => self::clip("gsc_$st: " . ($j['error']['message'] ?? ''), 220)];
            $rows = [];
            foreach ((array) ($j['rows'] ?? []) as $x) {
                $rows[] = ['q' => (string) ($x['keys'][0] ?? ''), 'clicks' => $x['clicks'] ?? 0, 'impressions' => $x['impressions'] ?? 0, 'position' => $x['position'] ?? 0];
            }
            @file_put_contents($f, json_encode(['site' => $t['site'], 'rows' => $rows], JSON_UNESCAPED_UNICODE), LOCK_EX);
            return ['rows' => $rows, 'site' => $t['site']];
        } catch (\Throwable $e) {
            return ['rows' => [], 'site' => $t['site'], 'error' => self::clip('gsc_request_failed: ' . $e->getMessage(), 200)];
        }
    }

    /** @return string[] */
    public static function toks(string $s): array
    {
        $p = preg_split('/[^a-z0-9\x{0600}-\x{06FF}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($p, static fn($w) => mb_strlen($w) > 1));
    }

    /** اعداد واقعی یک کلیدواژه: همهٔ جستجوهایی که همهٔ کلمات آن را دارند. */
    public static function gscFor(array $rows, string $kw): ?array
    {
        $w = self::toks($kw);
        if (!$w) return null;
        $hit = array_values(array_filter($rows, static function ($r) use ($w) {
            $q = mb_strtolower((string) $r['q']);
            foreach ($w as $x) if (!str_contains($q, $x)) return false;
            return true;
        }));
        if (!$hit) return ['impressions' => 0, 'clicks' => 0, 'position' => null, 'queries' => 0];
        $imp = 0; $clk = 0; $wp = 0.0;
        foreach ($hit as $r) { $imp += $r['impressions']; $clk += $r['clicks']; $wp += $r['position'] * $r['impressions']; }
        return ['impressions' => $imp, 'clicks' => $clk, 'position' => $imp ? round($wp / $imp * 10) / 10 : null, 'queries' => count($hit)];
    }

    private static function gscTest(string $role): array
    {
        if ($role !== 'admin') throw new ApiError('forbidden', 403);
        $g = self::gscQueries(true);
        if (!empty($g['error'])) return ['ok' => false, 'error' => $g['error'], 'site' => $g['site']];
        return ['ok' => true, 'site' => $g['site'], 'queries' => count($g['rows']), 'top' => array_map(static fn($r) => $r['q'], array_slice($g['rows'], 0, 3))];
    }

    // ------------------------------------------------------------------ SEO
    private const SEO_ROLES = ['admin', 'supervisor', 'reviewer', 'news_editor'];

    /** @return array{doc:array,rows:array,hasEn:bool,nsegs:int}|null */
    private static function loadDocText(string $id): ?array
    {
        $doc = Db::one('SELECT id, title, status, source_kind, publish_title FROM documents WHERE id = ?', [$id]);
        if (!$doc) return null;
        $segs = Db::all('SELECT kind, source_text, current_text, `position` FROM segments WHERE document_id = ? ORDER BY `position`', [$id]);
        $hasEn = false;
        foreach ($segs as $s) if (trim((string) ($s['current_text'] ?? '')) !== '') { $hasEn = true; break; }
        $rows = [];
        foreach ($segs as $s) {
            $t = trim((string) ($hasEn ? ($s['current_text'] ?? '') : ($s['source_text'] ?? '')));
            if ($t !== '') $rows[] = ['kind' => $s['kind'], 'text' => $t];
        }
        return ['doc' => $doc, 'rows' => $rows, 'hasEn' => $hasEn, 'nsegs' => count($segs)];
    }

    private static function pickTitle(array $doc, array $rows): string
    {
        $t = trim((string) ($doc['publish_title'] ?? ''));
        if ($t !== '') return $t;
        return ($rows[0]['kind'] ?? '') === 'heading' ? $rows[0]['text'] : (string) $doc['title'];
    }

    private static function seoCandidates(string $role, array $body): array
    {
        if (!in_array($role, self::SEO_ROLES, true)) throw new ApiError('forbidden', 403);
        $id = $body['document_id'] ?? null;
        if (!self::isUuid($id)) throw new ApiError('document_id_required', 400);
        $d = self::loadDocText(strtolower($id));
        if (!$d) throw new ApiError('document_not_found', 404);
        if (!$d['rows']) return ['ok' => false, 'error' => 'no_text'];
        $title = self::pickTitle($d['doc'], $d['rows']);
        $g = self::gscQueries();
        $known = $g['rows'];
        usort($known, static fn($a, $b) => $b['impressions'] <=> $a['impressions']);
        $known = array_slice($known, 0, 40);
        $llm = Llm::forTask('seo');
        $src = $d['hasEn'] ? 'the approved English translation' : 'the Persian source (write the keywords in ENGLISH anyway)';
        $knownTxt = $known
            ? "\nThese are REAL queries that already bring visitors to this site (from Google Search Console, with impressions). Reuse or extend them when they fit the text:\n"
              . implode('; ', array_map(static fn($r) => $r['q'] . ' (' . $r['impressions'] . ')', $known)) . "\n"
            : '';
        $prompt = "You are an SEO researcher for the English edition of an Islamic-studies publication (riseislamicstudies.com), a scholarly journal read by international researchers. The text below is $src. Content type: {$d['doc']['source_kind']}.\n\n"
            . "Propose 7 candidate focus keywords (English phrases of 2-4 words) that real people could search for to find this page. Mix: 2-3 broad ones, 3-4 more specific long-tail ones. Never propose something the text does not actually cover.\n"
            . $knownTxt
            . "\nReturn ONLY JSON: {\"candidates\":[{\"keyword\":\"...\",\"reason\":\"<one short Persian sentence, max 100 characters, saying why it fits>\"}]}\n\n"
            . "TITLE: $title\n\nTEXT:\n" . self::clip(implode("\n\n", array_column($d['rows'], 'text')), 5000);
        $cands = self::askJson($llm, $prompt, static fn($raw) => self::validateCandidates($raw));
        $gerr = $g['error'] ?? null;
        return [
            'ok' => true, 'model' => $llm->provider() . ':' . $llm->model(),
            'gsc' => ['connected' => $gerr === null, 'error' => $gerr === 'not_connected' ? null : $gerr, 'site' => $g['site'], 'days' => 90, 'queries' => count($g['rows'])],
            'candidates' => array_map(static fn($c) => $c + ['gsc' => $gerr !== null ? null : self::gscFor($g['rows'], $c['keyword'])], $cands),
            'site_top' => array_map(static fn($r) => ['q' => $r['q'], 'impressions' => $r['impressions'], 'clicks' => $r['clicks'], 'position' => round($r['position'] * 10) / 10], array_slice($known, 0, 8)),
        ];
    }

    public static function validateCandidates(mixed $raw): array|string
    {
        $a = is_array($raw) ? ($raw['candidates'] ?? null) : null;
        if (!is_array($a) || !array_is_list($a)) return 'candidates array missing';
        $seen = []; $out = [];
        foreach ($a as $x) {
            $kw = is_array($x) && isset($x['keyword']) && is_scalar($x['keyword']) ? (string) $x['keyword'] : '';
            $k = self::clip(trim((string) preg_replace('/\s+/u', ' ', trim($kw))), 80);
            if ($k === '' || isset($seen[mb_strtolower($k)])) continue;
            $seen[mb_strtolower($k)] = true;
            $rs = is_array($x) && isset($x['reason']) && is_scalar($x['reason']) ? (string) $x['reason'] : '';
            $out[] = ['keyword' => $k, 'reason' => self::clip(trim($rs), 160)];
        }
        return count($out) >= 3 ? array_slice($out, 0, 8) : 'need at least 3 distinct candidates';
    }

    public static function words(string $t): array
    {
        preg_match_all('/[a-z0-9\x{2019}\'-]+/u', mb_strtolower($t), $m);
        return $m[0];
    }

    public static function slugify(string $t): string
    {
        $s = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($t));
        return substr(trim((string) $s, '-'), 0, 70);
    }

    private static function strArr(mixed $x, int $n): array
    {
        if (!is_array($x) || !array_is_list($x)) return [];
        $o = [];
        foreach ($x as $v) {
            $s = trim(is_scalar($v) ? (string) $v : (is_array($v) ? implode(',', array_map('strval', array_filter($v, 'is_scalar'))) : ''));
            if ($s !== '') $o[] = $s;
        }
        return array_slice($o, 0, $n);
    }

    public static function validateSeo(mixed $raw, string $chosen): array|string
    {
        $fk = $chosen !== '' ? $chosen : (is_array($raw) && isset($raw['focus_keyword']) && is_scalar($raw['focus_keyword']) ? trim((string) $raw['focus_keyword']) : '');
        if ($fk === '') return 'focus_keyword missing';
        $g = static fn($k) => is_array($raw) && isset($raw[$k]) && is_scalar($raw[$k]) ? trim((string) $raw[$k]) : '';
        $seoTitle = $g('seo_title'); $meta = $g('meta_description');
        if ($seoTitle === '' || $meta === '') return 'seo_title or meta_description missing';
        $slug = self::slugify($g('slug'));
        return [
            'focus_keyword' => self::clip($fk, 80),
            'secondary_keywords' => self::strArr($raw['secondary_keywords'] ?? null, 6),
            'tags' => array_map(static fn($t) => self::clip((string) preg_replace('/^#/', '', $t), 40), self::strArr($raw['tags'] ?? null, 8)),
            'seo_title' => self::clip($seoTitle, 80), 'meta_description' => self::clip($meta, 200),
            'slug' => $slug !== '' ? $slug : self::slugify($seoTitle),
            'excerpt' => self::clip($g('excerpt'), 400),
            'suggestions' => array_map(static fn($s) => self::clip($s, 200), self::strArr($raw['suggestions'] ?? null, 6)),
        ];
    }

    /** چک‌لیست قطعی (مدل هرگز خودش را نمره نمی‌دهد) */
    public static function checklist(array $p, string $bodyText, string $firstPara, int $wordCount, int $headings, string $kind, array $alts): array
    {
        $kw = mb_strtolower($p['focus_keyword']);
        $has = static fn(string $t) => str_contains(mb_strtolower($t), $kw);
        $kwCount = $kw !== '' ? count(explode($kw, mb_strtolower($bodyText))) - 1 : 0;
        $kwWords = count(preg_split('/\s+/u', $kw) ?: []);
        $density = $wordCount ? ($kwCount * $kwWords * 100) / $wordCount : 0;
        $n = count($alts);
        $stl = mb_strlen($p['seo_title']); $mdl = mb_strlen($p['meta_description']);
        $min = $kind === 'news' ? 150 : 300;
        $allAlt = true;
        foreach ($alts as $a) if (trim((string) $a) === '') { $allAlt = false; break; }
        $checks = [
            ['ok' => $stl >= 30 && $stl <= 60, 'msg' => "طول عنوان سئو $stl نویسه (مطلوب ۳۰ تا ۶۰)"],
            ['ok' => $has($p['seo_title']), 'msg' => 'کلیدواژهٔ اصلی در عنوان سئو هست'],
            ['ok' => $mdl >= 120 && $mdl <= 160, 'msg' => "طول توضیح متا $mdl نویسه (مطلوب ۱۲۰ تا ۱۶۰)"],
            ['ok' => $has($p['meta_description']), 'msg' => 'کلیدواژهٔ اصلی در توضیح متا هست'],
            ['ok' => $has(str_replace('-', ' ', $p['slug'])), 'msg' => 'کلیدواژهٔ اصلی در نامک هست'],
            ['ok' => $has($firstPara), 'msg' => 'کلیدواژهٔ اصلی در پاراگراف اول متن هست'],
            ['ok' => $density >= 0.3 && $density <= 2.5, 'msg' => 'تراکم کلیدواژه ' . number_format($density, 1, '.', '') . '٪ (مطلوب ۰٫۳ تا ۲٫۵٪)'],
            ['ok' => $wordCount >= $min, 'msg' => "طول متن $wordCount کلمه (حداقل $min)"],
            ['ok' => $wordCount < 400 || $headings >= 2, 'msg' => 'متن بلند زیرعنوان کافی دارد'],
            ['ok' => $n > 0, 'msg' => 'تصویر دارد'],
            ['ok' => $n === 0 || $allAlt, 'msg' => 'همهٔ تصاویر متن جایگزین (alt) دارند'],
        ];
        $ok = count(array_filter($checks, static fn($c) => $c['ok']));
        return ['checks' => $checks, 'score' => (int) floor($ok * 100 / count($checks) + 0.5)];
    }

    private static function seo(string $role, array $body): array
    {
        if (!in_array($role, self::SEO_ROLES, true)) throw new ApiError('forbidden', 403);
        $id = $body['document_id'] ?? null;
        if (!self::isUuid($id)) throw new ApiError('document_id_required', 400);
        $id = strtolower($id);
        $d = self::loadDocText($id);
        if (!$d) throw new ApiError('document_not_found', 404);
        if ($d['nsegs'] === 0) return ['ok' => false, 'error' => 'no_text'];
        $doc = $d['doc']; $rows = $d['rows']; $hasEn = $d['hasEn'];
        $bodyText = implode("\n\n", array_column($rows, 'text'));
        $wordCount = count(self::words($bodyText));
        $firstPara = '';
        foreach ($rows as $r) if ($r['kind'] !== 'heading') { $firstPara = $r['text']; break; }
        $headings = count(array_filter($rows, static fn($r) => $r['kind'] === 'heading'));
        $title = self::pickTitle($doc, $rows);
        $alts = array_column(Db::all('SELECT alt_text FROM document_images WHERE document_id = ?', [$id]), 'alt_text');

        $chosen = isset($body['keyword']) && is_string($body['keyword']) ? self::clip(trim((string) preg_replace('/\s+/u', ' ', trim($body['keyword']))), 80) : '';
        $realQ = '';
        if ($chosen !== '') {
            $g = self::gscQueries();
            $w = self::toks($chosen);
            $rel = array_values(array_filter($g['rows'], static function ($r) use ($w) {
                $q = mb_strtolower((string) $r['q']);
                foreach ($w as $x) if (str_contains($q, $x)) return true;
                return false;
            }));
            usort($rel, static fn($a, $b) => $b['impressions'] <=> $a['impressions']);
            $rel = array_slice($rel, 0, 15);
            if ($rel) $realQ = "\nREAL queries from Google Search Console related to this keyword (use their wording for secondary keywords and tags): " . implode('; ', array_column($rel, 'q')) . "\n";
        }
        $llm = Llm::forTask('seo');
        $src = $hasEn ? 'the approved English translation' : 'the Persian source (no English translation exists yet: write all SEO fields in ENGLISH anyway)';
        $fkLine = $chosen !== '' ? "it is FIXED by the editor: exactly \"$chosen\" (do not change it)" : 'one primary keyword phrase (2-4 words) people would really search for';
        $prompt = "You are an SEO editor for the English edition of an Islamic-studies publication (riseislamicstudies.com). The text below is $src. Content type: {$doc['source_kind']}.\n\n"
            . "Propose, for the English page:\n"
            . "- focus_keyword: $fkLine,\n"
            . "- secondary_keywords: 3-5 related phrases,\n"
            . "- tags: 4-6 WordPress tags (Title Case, 1-3 words, no hashtags),\n"
            . "- seo_title: at most 60 characters, starts with or contains the focus keyword, no clickbait,\n"
            . "- meta_description: 130-155 characters, contains the focus keyword, active voice, an invitation to read,\n"
            . "- slug: lowercase English words joined by hyphens, max 6 words, contains the focus keyword,\n"
            . "- excerpt: 1-2 sentences, max 280 characters,\n"
            . "- suggestions: up to 6 short concrete improvements to the TEXT itself so it ranks better for the focus keyword (in Persian, for the Persian-speaking editors): where the keyword is missing, subheadings to add, questions readers ask that the text could answer, internal-link ideas, image alt wording.\n"
            . "Be accurate: never invent facts that are not in the text.\n"
            . $realQ
            . "\nReturn ONLY JSON with exactly these keys.\n\nTITLE: $title\n\nTEXT:\n" . self::clip($bodyText, 7000);
        $p = self::askJson($llm, $prompt, static fn($raw) => self::validateSeo($raw, $chosen));
        $c = self::checklist($p, $bodyText, $firstPara, $wordCount, $headings, (string) $doc['source_kind'], $alts);
        return ['ok' => true, 'proposal' => $p, 'checks' => $c['checks'], 'score' => $c['score'], 'language' => $hasEn ? 'en' : 'fa-source', 'model' => $llm->provider() . ':' . $llm->model()];
    }
}
