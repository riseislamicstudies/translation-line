<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Ctx, Db, Http, Secrets, Storage, Triggers};

/**
 * publish-wordpress (پورت Edge Function): ارسال ترجمهٔ تأییدشده به WordPress با REST API و Application Password.
 *   {action:'test'}                 admin          → {ok, site, user, can_publish}
 *   {action:'categories', tax?}     admin/supervisor/reviewer/news_editor → {ok, categories}
 *   {action:'types'}                admin          → {ok, types}
 *   {action:'authors'}              admin/supervisor → {ok, role, authors, via?}
 *   {action:'send', document_id}    admin/supervisor → {ok, post_id, url, wp_status, images_sent, images_skipped, has_featured}
 *   {action:'draft', document_id}   admin/supervisor → {ok, url} | {ok, skipped}
 * خطا: {error:'wordpress_failed', detail} با HTTP 502 (خطای WP/شبکه) یا 500؛ بقیه مثل اصلی.
 *
 * زمان: send هم‌زمان است ولی بودجهٔ ~25 ثانیه دارد. آپلود هر تصویر (آپلود + alt/caption) ذخیره‌شدنی و idempotent است
 * (document_images.wp_media_id/wp_url بعد از هر تصویر نوشته می‌شود). اگر بودجه قبل از ساخت نوشته تمام شود، خطای
 * قابل‌ادامهٔ «upload_pending: ...» برمی‌گردد (و در documents.wp_error نوشته می‌شود)؛ با دوباره زدن «ارسال» ادامه می‌دهد.
 * (مرورگر فقط یک بار صدا می‌زند و پاسخ را منتظر می‌ماند، پس job استفاده نشد.)
 * قفل نام‌دار از ساخت نوشتهٔ تکراری با دو کلیک هم‌زمان جلوگیری می‌کند.
 */
final class PublishWordpress
{
    public const AUTH = true;
    private const UA = 'RiseContentPlatform/1.0 (+https://riseislamicstudies.com)';
    private const BUDGET = 25.0;
    private static float $t0 = 0.0;

    // ------------------------------------------------------------------ pure helpers
    /** معادل String.prototype.trim در JS (فاصله‌های یونیکد + BOM) */
    public static function jsTrim(string $s): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $s);
    }

    public static function esc(string $s): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
    }

    /** @param array{src:string,alt:string,caption:string,after:?int} $i */
    public static function figure(array $i): string
    {
        return '<figure class="wp-block-image"><img src="' . self::esc($i['src']) . '" alt="' . self::esc($i['alt']) . '"/>'
            . ($i['caption'] !== '' ? '<figcaption>' . self::esc($i['caption']) . '</figcaption>' : '') . '</figure>';
    }

    /**
     * بخش‌های انگلیسی → HTML وردپرس. آیتم‌های لیست پشت‌سرهم یک <ul> مشترک دارند.
     * تصویر درون‌متنی بعد از بخشی می‌آید که position آن را گفته، وگرنه آخر متن.
     * @param array<int,array{kind:string,text:string,position:int}> $segs
     */
    /**
     * متن یک بخش با نشانه‌گذاری سبک → HTML امن: **درشت**، *کج*، [متن](لینک)، [^n] ارجاع به پاورقی. \* یعنی ستارهٔ واقعی.
     */
    public static function inline(string $t): string
    {
        $h = self::esc($t);
        $h = str_replace('\\*', "\x01", $h);
        $h = preg_replace('/\[\^(\d{1,3})\]/', '<sup id="fnref-$1"><a href="#fn-$1">[$1]</a></sup>', $h) ?? $h;
        $h = preg_replace_callback('/\[([^\]\n]{1,300})\]\((https?:\/\/[^\s)]{1,500}|mailto:[^\s)]{1,200})\)/u', static fn($m) => '<a href="' . $m[2] . '">' . $m[1] . '</a>', $h) ?? $h;
        $h = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/us', '<strong>$1</strong>', $h) ?? $h;
        $h = preg_replace('/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])/u', '<em>$1</em>', $h) ?? $h;
        return str_replace("\x01", '*', str_replace("\n", '<br>', $h));
    }

    /**
     * بخش‌های انگلیسی → HTML وردپرس. آیتم‌های لیست پشت‌سرهم یک <ul> مشترک دارند؛ سطح تیتر (level)، نقل‌قول و پاورقی‌ها حفظ می‌شوند.
     * تصویر درون‌متنی بعد از بخشی می‌آید که position آن را گفته، وگرنه آخر متن.
     * @param array<int,array{kind:string,text:string,position:int,level?:?int}> $segs
     */
    public static function toHtml(array $segs, array $imgs = []): string
    {
        $out = []; $inList = false; $placed = []; $notes = [];
        $flush = static function () use (&$out, &$inList) { if ($inList) { $out[] = '</ul>'; $inList = false; } };
        foreach ($segs as $s) {
            $t = self::jsTrim((string) $s['text']);
            if ($t !== '') {
                if ($s['kind'] === 'footnote') {
                    if (preg_match('/^\[\^(\d{1,3})\]:?\s*(.*)$/us', $t, $m)) $notes[] = [$m[1], $m[2]];
                    else $notes[] = [(string) (count($notes) + 1), $t];
                } elseif ($s['kind'] === 'list_item') {
                    if (!$inList) { $out[] = '<ul>'; $inList = true; }
                    $out[] = '<li>' . self::inline($t) . '</li>';
                } else {
                    $flush();
                    if ($s['kind'] === 'heading') { $lv = min(6, max(2, ((int) ($s['level'] ?? 1)) + 1)); $out[] = "<h$lv>" . self::inline($t) . "</h$lv>"; }
                    elseif ($s['kind'] === 'quote') $out[] = '<blockquote><p>' . self::inline($t) . '</p></blockquote>';
                    else $out[] = '<p>' . self::inline($t) . '</p>';
                }
            }
            foreach ($imgs as $k => $i) {
                if (!isset($placed[$k]) && $i['after'] !== null && $i['after'] === (int) $s['position']) { $flush(); $out[] = self::figure($i); $placed[$k] = true; }
            }
        }
        $flush();
        foreach ($imgs as $k => $i) if (!isset($placed[$k])) $out[] = self::figure($i);
        if ($notes) {
            $out[] = '<hr/><ol class="footnotes">' . implode('', array_map(static fn($n) => '<li id="fn-' . $n[0] . '" value="' . (int) $n[0] . '">' . self::inline($n[1]) . ' <a href="#fnref-' . $n[0] . '">&#8617;</a></li>', $notes)) . '</ol>';
        }
        return implode("\n", $out);
    }

    /** نام فایل امن برای آپلود رسانه */
    public static function mediaFilename(?string $filename, string $mime): string
    {
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $b = preg_replace('/\.[^.]+$/', '', (string) ($filename ?? 'image'));
        $b = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $b), '-');
        $b = substr($b, 0, 60);
        return ($b === '' ? 'image' : $b) . '.' . $ext;
    }

    /** meta کلیدهای SEO */
    public static function seoMeta(array $c, array $doc): array
    {
        $m = [];
        foreach (['wp_seo_title_key' => 'pub_seo_title', 'wp_seo_desc_key' => 'pub_seo_desc', 'wp_seo_focus_key' => 'pub_seo_focus'] as $ck => $dk) {
            if (($c[$ck] ?? '') !== '' && ($doc[$dk] ?? '') !== '' && $doc[$dk] !== null) $m[$c[$ck]] = (string) $doc[$dk];
        }
        return $m;
    }

    /** "1, 2,x,0" → [1,2] */
    public static function parseCatIds(string $s): array
    {
        $o = [];
        foreach (explode(',', $s) as $x) {
            $t = self::jsTrim($x);
            if ($t !== '' && is_numeric($t)) { $n = (float) $t; if (floor($n) == $n && $n > 0) $o[] = (int) $n; }
        }
        return $o;
    }

    // ------------------------------------------------------------------ http
    private static function rem(): float { return self::BUDGET - (microtime(true) - self::$t0); }

    /** @return array{0:int,1:string} */
    private static function curl(string $url, string $method, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(1, $timeout), CURLOPT_CONNECTTIMEOUT => min(8, max(1, $timeout)),
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) throw new WpError('cannot_reach_site: ' . ($err !== '' ? $err : 'request failed'));
        return [$st, (string) $raw];
    }

    private static function timeout(int $max): int
    {
        return (int) max(3, min($max, floor(self::rem())));
    }

    /** مسیر با پیشوند "@" از شکل ?rest_route= استفاده می‌کند (برای فایروال‌هایی که /wp-json/ را می‌بندند). */
    private static function wp(string $base, string $auth, string $path, string $method = 'GET', ?array $body = null): mixed
    {
        if (str_starts_with($path, '@')) {
            $p = substr($path, 1);
            $q = strpos($p, '?');
            $url = $base . '/?rest_route=' . ($q === false ? $p : substr($p, 0, $q) . '&' . substr($p, $q + 1));
        } else {
            $url = $base . '/wp-json' . $path;
        }
        [$st, $text] = self::curl($url, $method, [
            'Authorization: ' . $auth, 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: ' . self::UA,
        ], $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), self::timeout(25));
        $data = json_decode($text, true);
        if ($st < 200 || $st >= 300) {
            $code = is_array($data) && !empty($data['code']) ? $data['code'] . ': ' : '';
            $msg = is_array($data) && isset($data['message']) ? (string) (is_scalar($data['message']) ? $data['message'] : json_encode($data['message'])) : mb_substr($text, 0, 200);
            throw new WpError("wordpress_$st: $code$msg");
        }
        if ($data === null) throw new WpError('wordpress_returned_non_json (is the REST API blocked or the address wrong?)');
        return $data;
    }

    private static function wpBinary(string $base, string $auth, string $path, string $bytes, string $mime, string $filename): array
    {
        $mime = preg_replace('/[^A-Za-z0-9.+\/_-]/', '', $mime) ?: 'application/octet-stream';
        [$st, $text] = self::curl($base . '/wp-json' . $path, 'POST', [
            'Authorization: ' . $auth, 'Content-Type: ' . $mime, 'Content-Disposition: attachment; filename="' . $filename . '"',
            'Accept: application/json', 'User-Agent: ' . self::UA,
        ], $bytes, self::timeout(60));
        $data = json_decode($text, true);
        if ($st < 200 || $st >= 300 || !is_array($data) || empty($data['id'])) {
            $code = is_array($data) && !empty($data['code']) ? $data['code'] . ': ' : '';
            $msg = is_array($data) && isset($data['message']) ? (string) (is_scalar($data['message']) ? $data['message'] : '') : mb_substr($text, 0, 200);
            throw new WpError("wordpress_media_$st: $code$msg");
        }
        return $data;
    }

    /** برچسب را با نام (دقیق، بی‌توجه به بزرگی/کوچکی) پیدا کن یا بساز. */
    private static function tagId(string $base, string $auth, string $name, string $tagKey = 'tags'): int
    {
        $found = self::wp($base, $auth, "/wp/v2/$tagKey?per_page=20&search=" . rawurlencode($name) . '&_fields=id,name');
        foreach ((array) $found as $t) {
            if (is_array($t) && isset($t['name'], $t['id']) && mb_strtolower(str_replace('&amp;', '&', (string) $t['name'])) === mb_strtolower($name)) return (int) $t['id'];
        }
        $made = self::wp($base, $auth, "/wp/v2/$tagKey", 'POST', ['name' => $name]);
        return (int) ($made['id'] ?? 0);
    }

    // ------------------------------------------------------------------ entry
    public static function handle(array $body, array $ctx): array
    {
        self::$t0 = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $action = $body['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['test', 'categories', 'send', 'draft', 'types', 'authors'], true)) throw new ApiError('bad_action', 400);
        $allowed = $action === 'categories' ? ['admin', 'supervisor', 'reviewer', 'news_editor']
            : ($action === 'authors' ? ['admin', 'supervisor'] : ($action === 'test' || $action === 'types' ? ['admin'] : ['admin', 'supervisor']));
        if (!Ctx::is(...$allowed)) throw new ApiError('forbidden', 403);

        $c = [];
        foreach (Db::all("SELECT `key`, `value` FROM app_config WHERE `key` LIKE 'wp\\_%'") as $r) $c[$r['key']] = (string) ($r['value'] ?? '');
        $password = Secrets::get('WP_APP_PASSWORD');
        $base = rtrim(self::jsTrim($c['wp_url'] ?? ''), '/');
        if (!preg_match('#^https://\S+$#i', $base)) throw new ApiError('wp_not_configured', 400, null, 'wp_url must be an https address');
        if (($c['wp_user'] ?? '') === '' || $password === '') throw new ApiError('wp_not_configured', 400, null, 'user or application password is missing');
        $auth = 'Basic ' . base64_encode($c['wp_user'] . ':' . preg_replace('/\s+/', ' ', $password));
        $restBase0 = preg_match('/^[a-z0-9_-]+$/i', $c['wp_post_type'] ?? '') ? $c['wp_post_type'] : 'posts';

        try {
            switch ($action) {
                case 'test': return self::test($base, $auth);
                case 'categories': return self::categories($base, $auth, $body, $c, $restBase0);
                case 'types': return self::types($base, $auth);
                case 'authors': return self::authors($base, $auth, $c);
            }
            return self::sendOrDraft($action, $body, $c, $base, $auth, $restBase0);
        } catch (ApiError $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($e instanceof \PDOException) { Http::logLine('publish-wordpress: ' . $e->getMessage()); throw new ApiError('wordpress_failed', 500, null, 'database_error'); }
            throw new ApiError('wordpress_failed', $e instanceof WpError ? 502 : 500, null, $e->getMessage());
        }
    }

    private static function test(string $base, string $auth): array
    {
        $user = ''; $canPublish = null; $authOk = false; $lastErr = '';
        foreach (['/wp/v2/users/me?context=edit', '/wp/v2/users/me'] as $path) {
            try {
                $me = self::wp($base, $auth, $path);
                $user = (string) ($me['name'] ?? $me['slug'] ?? ''); $authOk = true;
                if (!empty($me['capabilities'])) $canPublish = !empty($me['capabilities']['publish_posts']);
                break;
            } catch (WpError $e) { $lastErr = $e->getMessage(); }
        }
        if (!$authOk) {
            try { self::wp($base, $auth, '/wp/v2/posts?status=draft&per_page=1&_fields=id'); $authOk = true; }
            catch (WpError $e) { $lastErr = $e->getMessage(); }
        }
        if (!$authOk) throw new WpError($lastErr !== '' ? $lastErr : 'authentication_failed');
        $site = '';
        try { $s = self::wp($base, $auth, '/'); $site = is_array($s) ? (string) ($s['name'] ?? '') : ''; } catch (WpError) { /* optional */ }
        return ['ok' => true, 'site' => $site, 'user' => $user, 'can_publish' => $canPublish];
    }

    /**
     * کلید REST دستهٔ یک نوع محتوا: تنظیم wp_catkey_<kind>؛ یا «categories» برای نوشتهٔ معمولی؛
     * وگرنه از روی خود وردپرس پیدا می‌شود (اولین طبقه‌بندی سلسله‌مراتبی همان نوع محتوا، مثلاً برای نوع سفارشی اسکالریا).
     */
    private static function resolveCatKey(string $base, string $auth, string $restBase, array $c, string $kind): string
    {
        $ok = static fn(string $k) => preg_match('/^[A-Za-z0-9_-]+$/', $k) ? $k : '';
        $set = $ok((string) ($c["wp_catkey_$kind"] ?? ''));
        if ($set !== '') return $set;
        if ($restBase === 'posts') return 'categories';
        static $cache = [];
        if (isset($cache[$restBase])) return $cache[$restBase];
        $found = '';
        try {
            $types = self::wp($base, $auth, '/wp/v2/types?context=edit');
        } catch (WpError) {
            try { $types = self::wp($base, $auth, '/wp/v2/types'); } catch (WpError) { $types = []; }
        }
        $slugs = [];
        foreach ((array) $types as $t) if (is_array($t) && ($t['rest_base'] ?? '') === $restBase) $slugs = (array) ($t['taxonomies'] ?? []);
        if ($slugs) {
            try { $tax = self::wp($base, $auth, '/wp/v2/taxonomies?context=edit'); }
            catch (WpError) { try { $tax = self::wp($base, $auth, '/wp/v2/taxonomies'); } catch (WpError) { $tax = []; } }
            $fallback = '';
            foreach ($slugs as $sl) {
                $t = is_array($tax) ? ($tax[$sl] ?? null) : null;
                if (!is_array($t) || empty($t['rest_base'])) continue;
                if (!empty($t['hierarchical'])) { $found = (string) $t['rest_base']; break; }
                if ($fallback === '' && !preg_match('/tag/i', (string) $sl)) $fallback = (string) $t['rest_base'];
            }
            if ($found === '') $found = $fallback;
        }
        return $cache[$restBase] = $ok($found);
    }

    private static function categories(string $base, string $auth, array $body, array $c, string $restBase0): array
    {
        $tax = is_string($body['tax'] ?? null) && preg_match('/^[a-z0-9_-]+$/i', $body['tax']) ? $body['tax'] : '';
        if ($tax === '') {
            $kind = is_string($body['kind'] ?? null) && preg_match('/^[a-z_]+$/', $body['kind']) ? $body['kind'] : '';
            if ($kind !== '') {
                $kt = (string) ($c["wp_type_$kind"] ?? '');
                $restBase = preg_match('/^[a-z0-9_-]+$/i', $kt) && $kt !== '' ? $kt : $restBase0;
                $tax = self::resolveCatKey($base, $auth, $restBase, $c, $kind);
                if ($tax === '') return ['ok' => true, 'categories' => [], 'tax' => null];
            } else $tax = 'categories';
        }
        return ['ok' => true, 'tax' => $tax, 'categories' => self::wp($base, $auth, "/wp/v2/$tax?per_page=100&_fields=id,name")];
    }

    private static function types(string $base, string $auth): array
    {
        try { $t = self::wp($base, $auth, '/wp/v2/types?context=edit'); } catch (WpError) { $t = self::wp($base, $auth, '/wp/v2/types'); }
        $out = [];
        foreach ((array) $t as $x) {
            if (!is_array($x) || empty($x['rest_base']) || ($x['slug'] ?? '') === 'attachment' || preg_match('/^wp_|nav_menu/', (string) ($x['slug'] ?? ''))) continue;
            $out[] = ['slug' => $x['slug'] ?? null, 'name' => $x['name'] ?? null, 'rest_base' => $x['rest_base'], 'taxonomies' => $x['taxonomies'] ?? []];
        }
        return ['ok' => true, 'types' => $out];
    }

    private static function authors(string $base, string $auth, array $c): array
    {
        $roleSlug = preg_match('/^[a-z0-9_-]+$/i', $c['wp_author_role'] ?? '') ? $c['wp_author_role'] : 'scholar';
        $sort = static function (array &$a) { usort($a, static fn($x, $y) => strcmp(mb_strtolower((string) $x['name']), mb_strtolower((string) $y['name']))); };
        $custom = null;
        foreach (["/rise/v1/scholars?role=$roleSlug", "@/rise/v1/scholars?role=$roleSlug"] as $p) {
            try { $custom = self::wp($base, $auth, $p); if (is_array($custom) && array_is_list($custom)) break; $custom = null; } catch (WpError) { /* next route */ }
        }
        if (is_array($custom)) {
            $all = array_map(static fn($x) => ['id' => (int) ($x['id'] ?? 0), 'name' => (string) ($x['name'] ?? ''), 'slug' => (string) ($x['slug'] ?? '')], $custom);
            $sort($all);
            return ['ok' => true, 'role' => $roleSlug, 'authors' => $all, 'via' => 'custom'];
        }
        $all = [];
        for ($page = 1; $page <= 10; $page++) {
            $q = "/wp/v2/users?roles=$roleSlug&per_page=100&page=$page&_fields=id,name,slug";
            $us = null; $lastErr = null;
            foreach ([$q . '&context=edit', '@' . $q . '&context=edit', '@' . $q, $q] as $p) {
                try { $us = self::wp($base, $auth, $p); break; } catch (WpError $e) { $lastErr = $e; }
            }
            if ($us === null) { if ($page > 1) break; throw $lastErr; }
            if (!is_array($us) || !array_is_list($us)) break;
            foreach ($us as $x) $all[] = ['id' => $x['id'] ?? null, 'name' => $x['name'] ?? '', 'slug' => $x['slug'] ?? ''];
            if (count($us) < 100) break;
        }
        $sort($all);
        return ['ok' => true, 'role' => $roleSlug, 'authors' => $all];
    }

    // ------------------------------------------------------------------ send / draft
    private static function setDoc(string $id, array $f): void
    {
        Db::tx(static fn() => Triggers::updateDocument($id, $f));
    }

    private static function sendOrDraft(string $action, array $body, array $c, string $base, string $auth, string $restBase0): array
    {
        $id = $body['document_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) throw new ApiError('document_id_required', 400);
        $id = strtolower($id);
        $doc = Db::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$doc) throw new ApiError('document_not_found', 404);
        $kind = (string) $doc['source_kind'];
        $kindType = $c["wp_type_$kind"] ?? '';
        $restBase = preg_match('/^[a-z0-9_-]+$/i', $kindType) ? $kindType : $restBase0;
        $isPosts = $restBase === 'posts';
        $keyOk = static fn(string $k) => preg_match('/^[A-Za-z0-9_-]+$/', $k) ? $k : '';
        $catKey = '';   // بعداً (فقط هنگام ارسال) با resolveCatKey پیدا می‌شود
        $tagKey = $keyOk($c["wp_tagkey_$kind"] ?? '') ?: ($isPosts ? 'tags' : '');

        if ($action === 'draft') {
            if (empty($doc['wp_post_id'])) return ['ok' => true, 'skipped' => true];
            $r = self::wp($base, $auth, "/wp/v2/$restBase/" . (int) $doc['wp_post_id'], 'POST', ['status' => 'draft']);
            self::setDoc($id, ['wp_status' => 'draft', 'wp_error' => null]);
            return ['ok' => true, 'url' => $r['link'] ?? null];
        }

        if (!in_array($doc['status'], ['approved', 'published'], true)) throw new ApiError('not_ready', 409, null, 'only approved translations can be sent');
        $segs = Db::all('SELECT kind, `level`, current_text, `position` FROM segments WHERE document_id = ? ORDER BY `position`', [$id]);
        if (!$segs) throw new ApiError('no_text', 409);
        if (!Db::lock('wp_send_' . $id, 0)) throw new ApiError('wordpress_failed', 409, null, 'send_in_progress');
        try {
            return self::send($id, $doc, $segs, $c, $base, $auth, $restBase, $catKey, $tagKey);
        } finally {
            Db::unlock('wp_send_' . $id);
        }
    }

    private static function send(string $id, array $doc, array $segs, array $c, string $base, string $auth, string $restBase, string $catKey, string $tagKey): array
    {
        $items = array_map(static fn($s) => ['kind' => (string) $s['kind'], 'text' => (string) ($s['current_text'] ?? ''), 'position' => (int) $s['position'], 'level' => $s['level'] === null ? null : (int) $s['level']], $segs);
        $title = self::jsTrim((string) ($doc['publish_title'] ?? ''));
        if ($title === '') {
            $first = $items[0] ?? null;
            if ($first && $first['kind'] === 'heading' && self::jsTrim($first['text']) !== '') { $title = self::jsTrim($first['text']); $items = array_slice($items, 1); }
            else $title = (string) $doc['title'];
        } elseif (($items[0]['kind'] ?? '') === 'heading' && mb_strtolower(self::jsTrim($items[0]['text'])) === mb_strtolower($title)) {
            $items = array_slice($items, 1);   // عنوان قبلاً اولین heading است؛ در متن تکرار نشود
        }

        // تصاویر: فقط آن‌هایی که بازبین محتوا تأیید کرده
        $imgRows = Db::all('SELECT * FROM document_images WHERE document_id = ? ORDER BY created_at', [$id]);
        $approved = array_values(array_filter($imgRows, static fn($x) => (int) $x['approved'] === 1));
        $skipped = count($imgRows) - count($approved);
        $media = [];
        $pending = 0;
        foreach ($approved as $im) if (!($im['wp_media_id'] && $im['wp_url'])) $pending++;
        $done = 0;
        foreach ($approved as $im) {
            if ($im['wp_media_id'] && $im['wp_url']) { $media[$im['id']] = ['id' => (int) $im['wp_media_id'], 'url' => (string) $im['wp_url']]; continue; }
            if (self::rem() < 8) {
                $m = "upload_pending: $done of $pending image(s) uploaded in this attempt; press send again to continue";
                self::setDoc($id, ['wp_error' => $m]);
                throw new WpError($m);
            }
            try { $bytes = Storage::get('doc-images', (string) $im['path']); } catch (\Throwable) { $bytes = null; }
            if ($bytes === null) throw new WpError('image_download_failed: ' . ($im['filename'] ?? $im['path']));
            $up = self::wpBinary($base, $auth, '/wp/v2/media', $bytes, (string) $im['mime'], self::mediaFilename($im['filename'] ?? null, (string) $im['mime']));
            $alt = self::jsTrim((string) ($im['alt_text'] ?? '')); if ($alt === '') $alt = $title;
            $cap = (string) ($im['caption'] ?? '');
            self::wp($base, $auth, '/wp/v2/media/' . (int) $up['id'], 'POST', ['alt_text' => $alt, 'caption' => $cap, 'title' => $cap !== '' ? $cap : $title]);
            $media[$im['id']] = ['id' => (int) $up['id'], 'url' => (string) ($up['source_url'] ?? '')];
            Db::exec('UPDATE document_images SET wp_media_id = ?, wp_url = ? WHERE id = ?', [$media[$im['id']]['id'], $media[$im['id']]['url'], $im['id']]);
            $done++;
        }
        $featured = null;
        foreach ($approved as $x) if ($x['kind'] === 'featured') { $featured = $x; break; }
        $inline = [];
        foreach ($approved as $x) {
            if ($x['kind'] === 'featured') continue;
            $alt = self::jsTrim((string) ($x['alt_text'] ?? ''));
            $inline[] = ['src' => $media[$x['id']]['url'], 'alt' => $alt !== '' ? $alt : $title, 'caption' => (string) ($x['caption'] ?? ''), 'after' => $x['after_position'] === null ? null : (int) $x['after_position']];
        }
        $html = self::toHtml($items, $inline);
        if ($html === '') throw new ApiError('no_text', 409);

        $when = !empty($doc['pub_at']) ? strtotime($doc['pub_at'] . ' UTC') : null;
        $future = $when !== null && $when !== false && $when > time() + 60;
        $status = ($c['wp_status'] ?? '') === 'publish' ? 'publish' : 'draft';
        if ($status === 'publish' && $future) $status = 'future';
        $post = ['title' => $title, 'content' => $html, 'status' => $status];
        if ($when && ($future || $status === 'draft')) $post['date_gmt'] = gmdate('Y-m-d\TH:i:s', $when);
        if (($doc['pub_slug'] ?? '') !== '' && $doc['pub_slug'] !== null) $post['slug'] = $doc['pub_slug'];
        if (($doc['pub_excerpt'] ?? '') !== '' && $doc['pub_excerpt'] !== null) $post['excerpt'] = $doc['pub_excerpt'];
        if ($featured) $post['featured_media'] = $media[$featured['id']]['id'];
        if (!empty($doc['pub_author_id'])) $post['author'] = (int) $doc['pub_author_id'];
        if ($catKey === '' && (!empty($doc['pub_category']) || !empty($c['wp_cat_' . $doc['source_kind']]))) $catKey = self::resolveCatKey($base, $auth, $restBase, $c, (string) $doc['source_kind']);
        $catWarn = null;
        if ($catKey === '' && !empty($doc['pub_category'])) $catWarn = 'category_taxonomy_not_found';
        if ($catKey !== '') {
            $catId = !empty($doc['pub_category']) ? [(int) $doc['pub_category']] : self::parseCatIds($c['wp_cat_' . $doc['source_kind']] ?? '');
            if ($catId) $post[$catKey] = $catId;
        }
        if ($tagKey !== '') {
            $tags = json_decode((string) ($doc['pub_tags'] ?? '[]'), true);
            $tagIds = [];
            foreach (is_array($tags) ? $tags : [] as $name) {
                if (self::rem() < 8) {
                    $m = 'upload_pending: tags not finished in this attempt; press send again to continue';
                    self::setDoc($id, ['wp_error' => $m]);
                    throw new WpError($m);
                }
                $tagIds[] = self::tagId($base, $auth, (string) $name, $tagKey);
            }
            if ($tagIds) $post[$tagKey] = $tagIds;
        }
        $meta = self::seoMeta($c, $doc);
        if ($meta) $post['meta'] = (object) $meta;

        if (self::rem() < 12) {
            $m = 'upload_pending: time budget used up before creating the post; press send again to continue';
            self::setDoc($id, ['wp_error' => $m]);
            throw new WpError($m);
        }
        try {
            $r = !empty($doc['wp_post_id'])
                ? self::wp($base, $auth, "/wp/v2/$restBase/" . (int) $doc['wp_post_id'], 'POST', $post)
                : self::wp($base, $auth, "/wp/v2/$restBase", 'POST', $post);
        } catch (\Throwable $e) {
            self::setDoc($id, ['wp_error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
        if (!is_array($r) || !isset($r['id'])) throw new WpError('wordpress_returned_unexpected_response');
        $authorWarn = null;
        if (!empty($doc['pub_author_id']) && isset($post['author'])) {
            if (!array_key_exists('author', $r)) $authorWarn = 'author_not_supported_by_post_type';
            elseif ((int) $r['author'] !== (int) $post['author']) $authorWarn = 'author_ignored_by_wordpress';
        }
        $catApplied = true;
        if ($catKey !== '' && isset($post[$catKey]) && array_key_exists($catKey, $r) && array_map('intval', (array) $r[$catKey]) != array_map('intval', (array) $post[$catKey])) $catApplied = false;
        self::setDoc($id, [
            'wp_post_id' => $r['id'], 'wp_url' => $r['link'] ?? null, 'wp_status' => $r['status'] ?? $status,
            'wp_error' => null, 'wp_sent_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
        ]);
        return ['ok' => true, 'post_id' => $r['id'], 'url' => $r['link'] ?? null, 'wp_status' => $r['status'] ?? $status,
            'images_sent' => count($approved), 'images_skipped' => $skipped, 'has_featured' => $featured !== null,
            'author_warning' => $authorWarn, 'category_warning' => $catWarn ?? ($catApplied ? null : 'category_not_applied'), 'category_key' => $catKey ?: null];
    }
}

/** خطای WordPress/شبکه → HTTP 502 با {error:'wordpress_failed', detail} */
final class WpError extends \RuntimeException {}
