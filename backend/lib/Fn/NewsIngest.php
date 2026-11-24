<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Config, Ctx, Db, Http, Rpc, Storage};

/**
 * news-ingest: دروازهٔ خبر.
 *  POST {action:'poll', source_id?, force?} | {action:'detect', url} | {action:'push', source_id?|source_name?, items:[...]} | {action:'test', source_id?|source:{...}}
 * دسترسی: هدر x-ingest-key (= Config ingest_key) یا نشست admin/news_editor.
 * cron(): منبع‌های سررسیدشده را با بودجهٔ سخت ۲۰ ثانیه تکه‌تکه می‌خواند. پیشرفت هر منبع در app_config با کلید
 *   _news_prog_<source_id> = {done:N} ذخیره می‌شود؛ هر خبر پردازش‌شده یک ردیف news_items (url_hash یکتا) می‌سازد،
 *   پس ادامه در نوبت بعد هیچ خبری را جا نمی‌اندازد و دوباره پردازش نمی‌کند.
 * گلچین هوشمند (LLM): نسخهٔ اصلی news-ingest آن را صدا نمی‌زد (کار ai-content است)، پس اینجا Rise\Llm صدا زده نمی‌شود.
 */
final class NewsIngest
{
    public const AUTH = false;

    private const UA = 'Mozilla/5.0 (compatible; SokuyeMohtavaBot/1.0)';
    private const IMG_JUNK = '/logo|icon|avatar|emoji|sprite|banner|pixel|tracking|placeholder|blank|share|badge|button/i';
    private const IMG_MAX = 4;
    private const IMG_BYTES = 6 * 1024 * 1024;
    private const BOILER = '/^(برچسب|کد خبر|منبع\s*:?$|اشتراک|لینک کوتاه|پربازدید|مطالب مرتبط|اخبار مرتبط|نظر شما|ارسال نظر|©|کلیه حقوق|تمامی حقوق)/u';
    private const WS = '[\s\x{00A0}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    private static function s(mixed $v): string { return is_scalar($v) ? (string) $v : ''; }
    private static float $deadline = 0;
    private static function left(): float { return self::$deadline - microtime(true); }

    // =================================================================== text helpers
    public static function normFa(string $t): string
    {
        $t = strtr($t, ["\u{064A}" => "\u{06CC}", "\u{0643}" => "\u{06A9}", "\u{0629}" => "\u{0647}"]);
        $t = preg_replace('/[\x{200C}\x{200F}\x{200E}\x{0640}]/u', ' ', $t) ?? $t;
        $t = preg_replace('/[\x{064B}-\x{0670}\x{065F}]/u', '', $t) ?? $t;
        $t = strtr($t, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $t = preg_replace('/' . self::WS . '+/u', ' ', $t) ?? $t;
        return mb_strtolower(trim($t));
    }

    public static function decodeEntities(string $s): string { return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

    public static function cleanText(string $html): string
    {
        $h = preg_replace('/<!\[CDATA\[|\]\]>/', '', $html) ?? $html;
        $h = preg_replace('/<br\s*\/?>/i', ' ', $h) ?? $h;
        $h = preg_replace('/<[^>]*>/', ' ', $h) ?? $h;
        $h = self::decodeEntities($h);
        $h = preg_replace('/[\x{00A0}\x{200B}\x{200D}\x{FEFF}]/u', ' ', $h) ?? $h;
        return trim(preg_replace('/' . self::WS . '+/u', ' ', $h) ?? $h);
    }

    private static function formEnc(string $s): string
    {
        return str_replace(' ', '+', preg_replace_callback('/[^A-Za-z0-9*\-._ ]/', static fn($m) => sprintf('%%%02X', ord($m[0])), $s) ?? $s);
    }

    /** مثل normalizeUrl نسخهٔ TS (WHATWG URL): حذف fragment و پارامترهای رهگیری، هاست کوچک، حذف / پایانی */
    public static function normalizeUrl(string $u): string
    {
        $u = trim($u);
        $p = parse_url($u);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return $u;
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        if (preg_match('/[^\x00-\x7f]/', $host) && function_exists('idn_to_ascii')) $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        $port = isset($p['port']) && !(($scheme === 'http' && $p['port'] === 80) || ($scheme === 'https' && $p['port'] === 443)) ? ':' . $p['port'] : '';
        $path = $p['path'] ?? '';
        if ($path === '') $path = '/';
        $path = preg_replace_callback('/[^\x21-\x7e]|["<>`{}]/', static fn($m) => sprintf('%%%02X', ord($m[0])), $path) ?? $path;
        $query = $p['query'] ?? null;
        if ($query !== null && $query !== '') {
            $pairs = explode('&', $query);
            $keep = []; $deleted = false;
            foreach ($pairs as $pr) {
                if ($pr === '') continue;
                $k = urldecode(explode('=', $pr, 2)[0]);
                if (preg_match('/^(utm_|fbclid|gclid|ref$|source$)/i', $k)) { $deleted = true; continue; }
                $keep[] = $pr;
            }
            if ($deleted) {
                $out = [];
                foreach ($keep as $pr) { $kv = explode('=', $pr, 2); $out[] = self::formEnc(urldecode($kv[0])) . '=' . self::formEnc(urldecode($kv[1] ?? '')); }
                $query = implode('&', $out);
            }
        }
        $s = $scheme . '://' . (isset($p['user']) ? $p['user'] . (isset($p['pass']) ? ':' . $p['pass'] : '') . '@' : '') . $host . $port . $path . ($query !== null && $query !== '' ? '?' . $query : '');
        if (strlen($path) > 1 && str_ends_with($s, '/') && ($query === null || $query === '')) $s = substr($s, 0, -1);
        return $s;
    }

    /** http(s) و نه شبکهٔ خصوصی */
    public static function isSafeUrl(string $u): bool
    {
        $p = parse_url($u);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
        $h = strtolower($p['host']);
        if ($h === 'localhost' || str_ends_with($h, '.localhost') || str_ends_with($h, '.local') || str_ends_with($h, '.internal')) return false;
        if ($h[0] === '[') return false;
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/', $h, $m)) return !self::privateV4((int) $m[1], (int) $m[2]);
        return true;
    }
    private static function privateV4(int $a, int $b): bool
    {
        return $a === 10 || $a === 127 || $a === 0 || ($a === 169 && $b === 254) || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168) || $a >= 224;
    }
    /** بررسی تکمیلی: نام میزبان نباید به آدرس خصوصی resolve شود (SSRF) */
    private static function hostResolvesSafe(string $u): bool
    {
        $h = strtolower((string) (parse_url($u, PHP_URL_HOST) ?? ''));
        if ($h === '' || preg_match('/^\d+\.\d+\.\d+\.\d+$/', $h)) return true;
        $ip = gethostbyname($h);
        if ($ip === $h || !preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/', $ip, $m)) return true;
        return !self::privateV4((int) $m[1], (int) $m[2]);
    }

    /** نسبی → مطلق (RFC 3986) */
    public static function resolveUrl(string $ref, string $base): ?string
    {
        $ref = trim($ref);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) return preg_match('#^https?://[^/?\#]+#i', $ref) ? $ref : null;
        $b = parse_url($base);
        if (!$b || empty($b['host'])) return null;
        $origin = ($b['scheme'] ?? 'http') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) return ($b['scheme'] ?? 'http') . ':' . $ref;
        if ($ref === '') return $base;
        if ($ref[0] === '?') return $origin . ($b['path'] ?? '/') . $ref;
        if ($ref[0] === '#') return $origin . ($b['path'] ?? '/') . (isset($b['query']) ? '?' . $b['query'] : '') . $ref;
        $q = ''; $frag = '';
        if (($i = strpos($ref, '#')) !== false) { $frag = substr($ref, $i); $ref = substr($ref, 0, $i); }
        if (($i = strpos($ref, '?')) !== false) { $q = substr($ref, $i); $ref = substr($ref, 0, $i); }
        $bp = $b['path'] ?? '/';
        $path = $ref !== '' && $ref[0] === '/' ? $ref : substr($bp, 0, (int) strrpos($bp, '/') + 1) . $ref;
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') { if (count($out) > 1) array_pop($out); } elseif ($seg !== '.') $out[] = $seg;
        }
        $last = basename($path);
        $res = implode('/', $out);
        if ($last === '.' || $last === '..') $res .= '/';
        if ($res === '' || $res[0] !== '/') $res = '/' . $res;
        return $origin . $res . $q . $frag;
    }

    public static function toIso(?string $d): ?string
    {
        if ($d === null || $d === '') return null;
        $t = strtotime($d);
        return $t === false ? null : gmdate('Y-m-d\TH:i:s', $t) . '.000Z';
    }

    public static function wordCount(array $ps): int
    {
        $n = 0;
        foreach ($ps as $p) $n += count(array_filter(preg_split('/' . self::WS . '+/u', (string) $p['text']) ?: [], static fn($x) => $x !== ''));
        return $n;
    }

    public static function persianRatio(array $ps): float
    {
        $all = implode(' ', array_column($ps, 'text'));
        $letters = preg_replace('/[^\p{L}]/u', '', $all) ?? '';
        if ($letters === '') return 0.0;
        return preg_match_all('/[\x{0600}-\x{06FF}]/u', $letters) / mb_strlen($letters);
    }

    public static function splitText(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n\s*\r?\n|\r?\n/', $text) ?: [] as $l) {
            $t = trim(preg_replace('/' . self::WS . '+/u', ' ', $l) ?? '');
            if ($t !== '') $out[] = ['kind' => 'paragraph', 'text' => $t];
        }
        return $out;
    }

    /** @return array{ok:bool, reason?:string} */
    public static function evaluate(string $title, array $ps, array $rules, array $st): array
    {
        if (!$ps) return ['ok' => false, 'reason' => 'متن خبر پیدا نشد'];
        if (self::persianRatio($ps) < 0.5) return ['ok' => false, 'reason' => 'متن فارسی نیست'];
        $wc = self::wordCount($ps);
        if ($wc < $st['minWords']) return ['ok' => false, 'reason' => "کوتاه‌تر از {$st['minWords']} کلمه"];
        if ($wc > $st['maxWords']) return ['ok' => false, 'reason' => "بلندتر از {$st['maxWords']} کلمه"];
        $hay = ' ' . self::normFa($title . " \n " . implode(" \n ", array_column($ps, 'text'))) . ' ';
        foreach ($rules as $r) {
            if ($r['kind'] !== 'exclude') continue;
            $k = self::normFa((string) $r['keyword']);
            if ($k !== '' && str_contains($hay, $k)) return ['ok' => false, 'reason' => 'کلمهٔ ممنوع: ' . $r['keyword']];
        }
        $inc = array_values(array_filter($rules, static fn($r) => $r['kind'] === 'include'));
        if ($inc) {
            $hit = false;
            foreach ($inc as $r) { $k = self::normFa((string) $r['keyword']); if ($k !== '' && str_contains($hay, $k)) { $hit = true; break; } }
            if (!$hit) return ['ok' => false, 'reason' => 'هیچ‌یک از کلیدواژه‌های لازم در متن نیست'];
        }
        return ['ok' => true];
    }

    // =================================================================== feeds and pages
    private static function tag(string $block, string $name): ?string
    {
        $n = preg_quote($name, '~');
        return preg_match('~<' . $n . '\b[^>]*>([\s\S]*?)</' . $n . '>~i', $block, $m) ? $m[1] : null;
    }

    /** @return list<array{url:string,title:string,published?:string,summary?:string}> */
    public static function parseFeed(string $xml, ?string $base = null): array
    {
        $out = [];
        if (!preg_match_all('~<(item|entry)\b[^>]*>([\s\S]*?)</\1>~i', $xml, $all, PREG_SET_ORDER)) return $out;
        foreach ($all as $m) {
            $b = $m[2];
            $link = self::tag($b, 'link');
            if ($link !== null) $link = self::cleanText($link);
            if ($link === null || $link === '') {
                $link = preg_match('~<link\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>~i', $b, $h) ? self::decodeEntities($h[1]) : self::cleanText(self::tag($b, 'guid') ?? '');
            }
            $title = self::cleanText(self::tag($b, 'title') ?? '');
            if ($link === '' || $title === '') continue;
            $abs = $base !== null ? self::resolveUrl($link, $base) : (preg_match('~^https?://~i', $link) ? $link : null);
            if ($abs === null) continue;
            $pub = self::cleanText(self::tag($b, 'pubDate') ?? self::tag($b, 'published') ?? self::tag($b, 'updated') ?? self::tag($b, 'dc:date') ?? '');
            $sum = self::cleanText(self::tag($b, 'content:encoded') ?? self::tag($b, 'content') ?? self::tag($b, 'description') ?? self::tag($b, 'summary') ?? '');
            $out[] = ['url' => $abs, 'title' => $title, 'published' => $pub !== '' ? $pub : null, 'summary' => $sum !== '' ? $sum : null];
        }
        return $out;
    }

    private static function patternRe(?string $pattern): ?string
    {
        if ($pattern === null || $pattern === '') return null;
        $re = '~' . str_replace('~', '\~', $pattern) . '~iu';
        return @preg_match($re, '') === false ? null : $re;
    }

    public static function parseListing(string $html, string $base, ?string $pattern, int $limit = 60): array
    {
        $re = self::patternRe($pattern);
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($base, PHP_URL_HOST)));
        $seen = []; $out = [];
        if (!preg_match_all('~<a\b[^>]*\bhref=["\']([^"\'#][^"\']*)["\'][^>]*>([\s\S]*?)</a>~i', $html, $all, PREG_SET_ORDER)) return $out;
        foreach ($all as $m) {
            if (count($out) >= $limit) break;
            $u = self::resolveUrl(self::decodeEntities($m[1]), $base);
            if ($u === null) continue;
            $p = parse_url($u);
            if (preg_replace('/^www\./', '', strtolower($p['host'] ?? '')) !== $host) continue;
            if ($re !== null ? !preg_match($re, $u) : strlen($p['path'] ?? '') < 12) continue;
            $u = self::normalizeUrl($u);
            if (isset($seen[$u]) || $u === self::normalizeUrl($base)) continue;
            $title = self::cleanText($m[2]);
            if (mb_strlen($title) < 6 && $re === null) continue;
            $seen[$u] = true;
            $out[] = ['url' => $u, 'title' => $title];
        }
        return $out;
    }

    // ---------------------------------------------------------------- DOM / selectors
    private static ?string $domHtml = null;
    private static ?\DOMDocument $domDoc = null;

    private static function dom(string $html): ?\DOMDocument
    {
        if (self::$domHtml === $html) return self::$domDoc;
        $prev = libxml_use_internal_errors(true);
        $d = new \DOMDocument();
        $ok = @$d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        self::$domHtml = $html; self::$domDoc = $ok ? $d : null;
        return self::$domDoc;
    }

    /** CSS (تگ، .کلاس، #id، فرزند/نوادهٔ «a b» و «a > b»، فهرست با ویرگول) → XPath؛ نامعتبر → null */
    public static function cssToXpath(string $sel): ?string
    {
        $alts = [];
        foreach (explode(',', $sel) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $tokens = preg_split('/\s*(>)\s*|\s+/', $part, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            $xp = ''; $axis = '//';
            foreach ($tokens as $t) {
                if ($t === '>') { $axis = '/'; continue; }
                if (!preg_match('/^([a-z*][a-z0-9-]*)?((?:[#.][\w-]+)*)$/i', $t, $m) || ($m[1] === '' && $m[2] === '')) return null;
                $xp .= $axis . ($m[1] !== '' ? strtolower($m[1]) : '*');
                preg_match_all('/([#.])([\w-]+)/', $m[2], $cs, PREG_SET_ORDER);
                foreach ($cs as $c) {
                    $xp .= $c[1] === '#' ? '[@id="' . $c[2] . '"]' : '[contains(concat(" ",normalize-space(@class)," ")," ' . $c[2] . ' ")]';
                }
                $axis = '//';
            }
            $alts[] = $xp;
        }
        return $alts ? implode(' | ', $alts) : null;
    }

    /** innerHTML نخستین عنصر منطبق با selector یا null */
    public static function selectInner(string $html, string $selector): ?string
    {
        $xp = self::cssToXpath($selector);
        if ($xp === null) return null;
        $d = self::dom($html);
        if (!$d) return null;
        $nodes = @(new \DOMXPath($d))->query($xp);
        if (!$nodes || $nodes->length === 0) return null;
        $out = '';
        foreach ($nodes->item(0)->childNodes as $c) $out .= $d->saveHTML($c);
        return $out;
    }

    public static function extractArticle(string $html, ?string $selector = null): array
    {
        $pick = static fn(string $re) => preg_match($re, $html, $m) ? trim(self::decodeEntities($m[1])) : null;
        $og = $pick('~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']~i') ?? $pick('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']~i');
        $h1 = preg_match('~<h1\b[^>]*>([\s\S]*?)</h1>~i', $html, $m1) ? $m1[1] : '';
        $tt = preg_match('~<title\b[^>]*>([\s\S]*?)</title>~i', $html, $m2) ? $m2[1] : '';
        $title = self::cleanText($h1);
        if ($title === '') $title = $og ? self::cleanText($og) : '';
        if ($title === '') $title = preg_split('/\s[|\-\x{2013}\x{2014}]\s/u', self::cleanText($tt))[0] ?? '';
        $published = $pick('~<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\']~i') ?? $pick('~<time\b[^>]*\bdatetime=["\']([^"\']+)["\']~i');

        $body = preg_replace('/<!--[\s\S]*?-->/', '', $html) ?? $html;
        $scope = null;
        if ($selector) $scope = self::selectInner($body, $selector);
        if ($scope === null) {
            $body = preg_replace('~<(script|style|noscript|svg|nav|header|footer|aside|form|iframe|button)\b[\s\S]*?</\1>~i', ' ', $body) ?? $body;
            $scope = self::selectInner($body, 'article') ?? $body;
        } else {
            $scope = preg_replace('~<(script|style|noscript|svg|iframe|button)\b[\s\S]*?</\1>~i', ' ', $scope) ?? $scope;
        }
        $paras = [];
        if (preg_match_all('~<(h[2-4]|p|li)\b[^>]*>([\s\S]*?)</\1>~i', $scope, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $t = self::cleanText($m[2]);
                $tg = strtolower($m[1]);
                if ($t === '' || preg_match(self::BOILER, $t)) continue;
                $letters = preg_match_all('/[\x{0600}-\x{06FF}]/u', $t);
                $len = mb_strlen($t);
                if ($tg[0] === 'h') { if ($len >= 4 && $len <= 160 && $t !== $title) $paras[] = ['kind' => 'heading', 'text' => $t]; continue; }
                if ($tg === 'li') { if ($len >= 40 && $letters > 20) $paras[] = ['kind' => 'list_item', 'text' => $t]; continue; }
                if ($len >= 30 && $letters >= 12) $paras[] = ['kind' => 'paragraph', 'text' => $t];
            }
        }
        $out = [];
        foreach ($paras as $i => $p) if ($i === 0 || $p['text'] !== $paras[$i - 1]['text']) $out[] = $p;
        while ($out && end($out)['kind'] === 'heading') array_pop($out);
        return ['title' => $title, 'published' => $published, 'paragraphs' => $out];
    }

    // ---------------------------------------------------------------- detection
    public static function looksLikeFeed(string $body): bool
    {
        return preg_match('~<(rss|feed|rdf:RDF)\b~i', substr($body, 0, 3000)) === 1 && count(self::parseFeed($body)) > 0;
    }

    public static function findFeedLinks(string $html, string $base): array
    {
        $out = [];
        if (!preg_match_all('~<link\b[^>]*>~i', $html, $all)) return $out;
        foreach ($all[0] as $t) {
            if (!preg_match('~type=["\']application/(rss|atom)\+xml["\']~i', $t) && !preg_match('~rel=["\']alternate["\'][^>]*type=["\']text/xml["\']~i', $t)) continue;
            if (!preg_match('~\bhref=["\']([^"\']+)["\']~i', $t, $h)) continue;
            $u = self::resolveUrl(self::decodeEntities($h[1]), $base);
            if ($u !== null && !in_array($u, $out, true)) $out[] = $u;
        }
        return $out;
    }

    private static function urlShape(array $u): ?array
    {
        $path = rawurldecode($u['path'] ?? '');
        $segs = array_values(array_filter(explode('/', $path), static fn($x) => $x !== ''));
        if (!empty($u['query'])) {
            $key = explode('=', explode('&', $u['query'])[0])[0];
            if ($key === '' || !preg_match('/^[\w-]+$/', $key)) return null;
            $last = implode('/', $segs);
            return ['shape' => "$last?$key", 'pattern' => preg_quote('/' . $last, '~') . '\?(?:[^#]*&)?' . preg_quote($key, '~') . '='];
        }
        if (!$segs) return null;
        $shape = []; $pat = [];
        foreach ($segs as $i => $s) {
            if (preg_match('/^\d+$/', $s)) { $shape[] = 'N'; $pat[] = '\d+'; }
            elseif (preg_match('/\d/', $s) && preg_match('/^[\w.%-]+$/', $s) && strlen($s) > 3) { $shape[] = 'M'; $pat[] = '[^/]*\d[^/]*'; }
            elseif (strlen($s) > 24 || preg_match('/[-_%]/', $s) || preg_match('/[^\x00-\x7f]/', $s) || ($i === count($segs) - 1 && strlen($s) > 12)) { $shape[] = 'S'; $pat[] = '[^/]+'; }
            else { $shape[] = strtolower($s); $pat[] = preg_quote($s, '~'); }
        }
        return ['shape' => implode('/', $shape), 'pattern' => '/' . implode('/', $pat)];
    }

    public static function guessLinkPatterns(string $html, string $base, int $top = 3): array
    {
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($base, PHP_URL_HOST)));
        $groups = [];
        if (preg_match_all('~<a\b[^>]*\bhref=["\']([^"\'#][^"\']*)["\'][^>]*>([\s\S]*?)</a>~i', $html, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $abs = self::resolveUrl(self::decodeEntities($m[1]), $base);
                if ($abs === null) continue;
                $u = parse_url($abs);
                if (!$u || preg_replace('/^www\./', '', strtolower($u['host'] ?? '')) !== $host) continue;
                if (self::normalizeUrl($abs) === self::normalizeUrl($base)) continue;
                $title = self::cleanText($m[2]);
                if (mb_strlen($title) < 12) continue;
                if (preg_match('~(^|/)(tag|tags|category|categories|cat|page|author|search|archive|rss|feed|login|register|contact|about|sitemap|gallery|video|photo|print|share|comment)(/|$|\?|-)~i', $u['path'] ?? '')) continue;
                $sh = self::urlShape($u);
                if (!$sh) continue;
                $groups[$sh['shape']] ??= ['pattern' => $sh['pattern'], 'urls' => []];
                $key = self::normalizeUrl($abs);
                $groups[$sh['shape']]['urls'][$key] ??= $title;
            }
        }
        $ranked = [];
        foreach ($groups as $g) {
            if (count($g['urls']) < 3) continue;
            $ranked[] = ['g' => $g, 'score' => count($g['urls']) * (preg_match('~\\\\d|\[\^/\]~', $g['pattern']) ? 1.6 : 1)];
        }
        usort($ranked, static fn($a, $b) => $b['score'] <=> $a['score']);
        $out = [];
        foreach (array_slice($ranked, 0, $top) as $r) {
            $samples = [];
            foreach (array_slice($r['g']['urls'], 0, 3, true) as $url => $title) $samples[] = ['url' => (string) $url, 'title' => $title];
            $out[] = ['pattern' => $r['g']['pattern'], 'count' => count($r['g']['urls']), 'samples' => $samples];
        }
        return $out;
    }

    private static function pText(string $frag): int
    {
        $n = 0;
        if (preg_match_all('~<p\b[^>]*>([\s\S]*?)</p>~i', $frag, $all)) foreach ($all[1] as $x) { $t = self::cleanText($x); if (mb_strlen($t) >= 30) $n += mb_strlen($t); }
        return $n;
    }

    public static function guessBodySelector(string $html): ?string
    {
        $clean = preg_replace('/<!--[\s\S]*?-->/', '', $html) ?? $html;
        $clean = preg_replace('~<(script|style|noscript|svg)\b[\s\S]*?</\1>~i', ' ', $clean) ?? $clean;
        $auto = self::wordCount(self::extractArticle($clean)['paragraphs']);
        $total = self::pText($clean);
        if ($total < 300) return null;
        $cands = [];
        if (preg_match_all('~<(div|article|section|main|td)\b([^>]*)>~i', $clean, $all, PREG_SET_ORDER)) {
            foreach ($all as $o) {
                if (count($cands) >= 80) break;
                $t = strtolower($o[1]);
                if (preg_match('~\bid=["\']([\w-]+)["\']~i', $o[2], $id)) $cands["$t#{$id[1]}"] = true;
                if (preg_match('~\bclass=["\']([^"\']+)["\']~i', $o[2], $cl)) foreach (preg_split('/\s+/', $cl[1]) as $c) if (preg_match('/^[\w-]+$/', $c)) $cands["$t.$c"] = true;
            }
        }
        $best = null;
        foreach (array_keys($cands) as $sel) {
            $inner = self::selectInner($clean, (string) $sel);
            if ($inner === null) continue;
            $pt = self::pText($inner);
            if ($pt < $total * 0.4 || $pt < 300) continue;
            $score = ($pt / max(strlen($inner), 1)) * (preg_match('/body|content|text|article|news|post|story|matn|khabar|detail/i', (string) $sel) ? 1.25 : 1);
            if (!$best || $score > $best['score']) $best = ['sel' => (string) $sel, 'score' => $score, 'words' => self::wordCount(self::extractArticle($clean, (string) $sel)['paragraphs'])];
        }
        if (!$best || $best['words'] < 40) return null;
        if (abs($auto - $best['words']) <= $best['words'] * 0.15) return null;
        return $best['sel'];
    }

    // =================================================================== network
    /** @return array{status:int, headers:array<string,string>, body:string} */
    private static function httpGet(string $url, string $accept, int $timeout, int $maxBytes): array
    {
        if (!self::isSafeUrl($url) || !self::hostResolvesSafe($url)) throw new \RuntimeException('آدرس مجاز نیست');
        $t = (int) max(3, min($timeout, self::$deadline > 0 ? self::left() + 3 : $timeout));
        $headers = []; $buf = ''; $over = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => min(8, $t), CURLOPT_TIMEOUT => $t,
            CURLOPT_USERAGENT => self::UA, CURLOPT_ENCODING => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'Accept-Language: fa,en;q=0.5'],
            CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$headers) {
                if (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $headers[strtolower(trim($k))] = trim($v); }
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, $d) use (&$buf, &$over, $maxBytes) {
                $buf .= $d;
                if (strlen($buf) > $maxBytes) { $over = true; return -1; }
                return strlen($d);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($over) throw new \RuntimeException('صفحه بیش از حد بزرگ است');
        if ($status === 0) throw new \RuntimeException($err !== '' ? $err : 'اتصال برقرار نشد');
        return ['status' => $status, 'headers' => $headers, 'body' => $buf];
    }

    private static function fetchText(string $url): string
    {
        $cur = $url;
        for ($hop = 0; $hop < 5; $hop++) {
            $r = self::httpGet($cur, 'text/html,application/xhtml+xml,application/xml,text/xml;q=0.9,*/*;q=0.5', 15, 3_000_000);
            if ($r['status'] >= 300 && $r['status'] < 400 && !empty($r['headers']['location'])) {
                $n = self::resolveUrl($r['headers']['location'], $cur);
                if ($n === null) throw new \RuntimeException('تغییر مسیر نامعتبر');
                $cur = $n; continue;
            }
            if ($r['status'] < 200 || $r['status'] >= 300) throw new \RuntimeException("پاسخ {$r['status']} از سایت");
            $cs = 'utf-8';
            if (preg_match('/charset=["\']?([\w-]+)/i', $r['headers']['content-type'] ?? '', $m)) $cs = $m[1];
            elseif (preg_match('/charset=["\']?([\w-]+)/i', substr($r['body'], 0, 2048), $m)) $cs = $m[1];
            $cs = strtolower($cs);
            if ($cs !== 'utf-8' && $cs !== 'utf8') {
                $conv = @mb_convert_encoding($r['body'], 'UTF-8', $cs);
                if (is_string($conv)) return $conv;
            }
            return $r['body'];
        }
        throw new \RuntimeException('تعداد تغییر مسیرها زیاد است');
    }

    // =================================================================== settings / rules
    private static function loadSettings(): array
    {
        $m = [];
        foreach (Db::all('SELECT `key`, `value` FROM app_config WHERE `key` IN (?, ?, ?)', ['news_min_words', 'news_max_words', 'news_max_per_run']) as $r) $m[$r['key']] = (int) $r['value'];
        return ['minWords' => $m['news_min_words'] ?? 0 ?: 60, 'maxWords' => $m['news_max_words'] ?? 0 ?: 3000, 'perRun' => $m['news_max_per_run'] ?? 0 ?: 20];
    }

    private static function loadRules(?string $sourceId): array
    {
        return array_values(array_filter(Db::all('SELECT kind, keyword, source_id FROM news_rules WHERE is_active = 1'),
            static fn($r) => $r['source_id'] === null || $r['source_id'] === $sourceId));
    }

    // =================================================================== one item

    // ---------------------------------------------------------------- images of the article
    // og:image = تصویر شاخص، تا ۳ تصویر از متن مقاله؛ خصوصی ذخیره و «تأییدنشده» (حق نشر تصمیم انسان است).
    private static function attr(string $tagHtml, string $name): ?string
    {
        return preg_match('~\b' . $name . '\s*=\s*["\']([^"\']+)["\']~i', $tagHtml, $m) ? $m[1] : null;
    }

    /** @return list<array{url:string,alt:string,og:bool}> */
    private static function imageCandidates(string $html, string $pageUrl, ?string $selector): array
    {
        $out = []; $seen = [];
        $add = function (?string $raw, string $alt, bool $og) use (&$out, &$seen, $pageUrl): void {
            if ($raw === null || $raw === '' || str_starts_with($raw, 'data:')) return;
            $u = self::resolveUrl(str_replace('&amp;', '&', $raw), $pageUrl);
            if ($u === null || !self::isSafeUrl($u) || preg_match('~\.(svg|gif|ico)(\?|$)~i', $u)) return;
            if (!$og && preg_match(self::IMG_JUNK, $u . ' ' . $alt)) return;
            $key = preg_replace('~-\d+x\d+(?=\.\w+$)~', '', explode('?', $u)[0]);
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out[] = ['url' => $u, 'alt' => trim($alt), 'og' => $og];
        };
        if (preg_match_all('~<meta\b[^>]*>~i', $html, $ms)) {
            foreach ($ms[0] as $m) {
                if (preg_match('~(property|name)\s*=\s*["\'](og:image|twitter:image)(:secure_url)?["\']~i', $m)) { $add(self::attr($m, 'content'), '', true); break; }
            }
        }
        $scope = ($selector ? self::selectInner($html, $selector) : null) ?? self::selectInner($html, 'article');
        if ($scope !== null && preg_match_all('~<img\b[^>]*>~i', $scope, $im)) {
            foreach ($im[0] as $m) {
                $add(self::attr($m, 'data-src') ?? self::attr($m, 'data-lazy-src') ?? self::attr($m, 'src'), self::decodeEntities(self::attr($m, 'alt') ?? ''), false);
                if (count($out) >= self::IMG_MAX + 2) break;
            }
        }
        return $out;
    }

    /** @return array{bytes:string,mime:string}|null */
    private static function fetchImage(string $url): ?array
    {
        $cur = $url;
        for ($hop = 0; $hop < 4; $hop++) {
            try { $r = self::httpGet($cur, 'image/jpeg,image/png,image/webp,*/*;q=0.3', 12, self::IMG_BYTES); } catch (\Throwable) { return null; }
            if ($r['status'] >= 300 && $r['status'] < 400 && !empty($r['headers']['location'])) {
                $n = self::resolveUrl($r['headers']['location'], $cur);
                if ($n === null) return null;
                $cur = $n; continue;
            }
            if ($r['status'] < 200 || $r['status'] >= 300) return null;
            $mime = strtolower(trim(explode(';', $r['headers']['content-type'] ?? '')[0]));
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
            $len = strlen($r['body']);
            if ($len < 8000 || $len > self::IMG_BYTES) return null; // فایل ریز = آیکون یا ردیاب
            return ['bytes' => $r['body'], 'mime' => $mime];
        }
        return null;
    }

    private static function importImages(string $docId, string $pageUrl, string $html, ?string $selector): int
    {
        $n = 0;
        foreach (self::imageCandidates($html, $pageUrl, $selector) as $c) {
            if ($n >= self::IMG_MAX || self::left() < 6) break;
            try {
                $img = self::fetchImage($c['url']);
                if (!$img) continue;
                $ext = $img['mime'] === 'image/png' ? 'png' : ($img['mime'] === 'image/webp' ? 'webp' : 'jpg');
                $path = $docId . '/' . Db::uuid() . '.' . $ext;
                try { Storage::put('doc-images', $path, $img['bytes']); } catch (\Throwable) { continue; }
                $name = mb_substr(rawurldecode(basename((string) parse_url($c['url'], PHP_URL_PATH))), 0, 120);
                try {
                    Db::insert('document_images', [
                        'id' => Db::uuid(), 'document_id' => $docId, 'path' => $path, 'filename' => $name !== '' ? $name : 'image', 'mime' => $img['mime'],
                        'size_bytes' => strlen($img['bytes']), 'kind' => $n === 0 ? 'featured' : 'inline',
                        'alt_text' => $c['alt'] !== '' ? mb_substr($c['alt'], 0, 300) : null, 'approved' => 0,
                    ]);
                } catch (\Throwable) { Storage::remove('doc-images', $path); continue; }
                $n++;
            } catch (\Throwable) { /* یک تصویر خراب هرگز خبر را متوقف نمی‌کند */ }
        }
        return $n;
    }

    /**
     * @param array{url:string,title?:?string,published?:?string,paragraphs?:array,summary?:?string} $it
     * @return array{url:string,title:string,decision:string,reason?:string,words?:int,paragraphs?:int,preview?:string}
     */
    private static function judge(?array $src, array $it, array $rules, array $st, bool $useDb, bool $dry): array
    {
        $url = self::normalizeUrl($it['url']);
        $hash = hash('sha256', $url);
        $base = ['url' => $url, 'title' => (string) ($it['title'] ?? ''), 'decision' => 'reject'];
        if (!self::isSafeUrl($url)) return ['decision' => 'error', 'reason' => 'آدرس مجاز نیست'] + $base;
        if ($useDb && Db::val('SELECT 1 FROM news_items WHERE url_hash = ?', [$hash]) !== null) { $base['decision'] = 'duplicate'; $base['reason'] = 'قبلاً دریافت شده'; return $base; }
        $title = (string) ($it['title'] ?? ''); $paras = $it['paragraphs'] ?? []; $published = $it['published'] ?? null;
        $fetchErr = ''; $pageHtml = '';
        $sel = $src['body_selector'] ?? null;
        if (!$paras) {
            try {
                $pageHtml = self::fetchText($url);
                $art = self::extractArticle($pageHtml, $sel);
                $paras = $art['paragraphs']; $title = $art['title'] !== '' ? $art['title'] : $title;
                $published ??= self::toIso($art['published']);
            } catch (\Throwable $e) { $fetchErr = $e->getMessage(); }
            if (!$paras && !empty($it['summary'])) $paras = self::splitText((string) $it['summary']);
        }
        $base['title'] = $title;
        $sid = $src['id'] ?? null; if ($sid === '') $sid = null;
        if (!$paras && $fetchErr !== '') {
            if ($useDb && !$dry) Rpc::call('news_reject', ['p_source' => $sid, 'p_url' => $url, 'p_hash' => $hash, 'p_title' => $title, 'p_status' => 'error', 'p_reason' => $fetchErr, 'p_published' => $published]);
            return ['url' => $url, 'title' => $title, 'decision' => 'error', 'reason' => $fetchErr];
        }
        $ev = self::evaluate($title, $paras, $rules, $st);
        $base['words'] = self::wordCount($paras); $base['paragraphs'] = count($paras); $base['preview'] = mb_substr((string) ($paras[0]['text'] ?? ''), 0, 160);
        if (!$ev['ok']) {
            if ($useDb && !$dry) Rpc::call('news_reject', ['p_source' => $sid, 'p_url' => $url, 'p_hash' => $hash, 'p_title' => $title, 'p_status' => 'rejected', 'p_reason' => $ev['reason'], 'p_published' => $published]);
            return $base + ['reason' => $ev['reason']];
        }
        if ($dry || !$useDb) return ['decision' => 'accept'] + $base;
        try {
            $r = Rpc::call('news_accept', ['p_source' => $sid, 'p_url' => $url, 'p_hash' => $hash, 'p_title' => $title !== '' ? $title : 'بدون عنوان', 'p_published' => $published, 'p_paragraphs' => $paras]);
        } catch (\Throwable $e) {
            return ['decision' => 'error', 'reason' => $e instanceof ApiError ? $e->getMessage() : 'database_error'] + $base;
        }
        if (($r['result'] ?? '') === 'accepted' && !empty($r['document_id']) && $pageHtml !== '') {
            try { self::importImages((string) $r['document_id'], $url, $pageHtml, $sel); } catch (\Throwable) { /* best effort */ }
        }
        $dup = ($r['result'] ?? '') === 'duplicate';
        return ['decision' => $dup ? 'duplicate' : 'accept'] + $base + ($dup ? ['reason' => 'قبلاً دریافت شده'] : []);
    }

    // =================================================================== one source
    private static function listItems(array $src): array
    {
        if (empty($src['feed_url'])) return [];
        $body = self::fetchText((string) $src['feed_url']);
        if ($src['kind'] === 'rss') {
            $f = self::parseFeed($body, (string) $src['feed_url']);
            if (!$f) throw new \RuntimeException('در این آدرس خبری پیدا نشد. آدرس RSS درست است؟');
            return $f;
        }
        $l = self::parseListing($body, (string) $src['feed_url'], $src['link_pattern'] ?? null);
        if (!$l) throw new \RuntimeException('پیوند خبری پیدا نشد. الگوی آدرس خبرها را تنظیم کنید.');
        return $l;
    }

    private static function progGet(string $id): int
    {
        $v = Db::val('SELECT `value` FROM app_config WHERE `key` = ?', ['_news_prog_' . $id]);
        return $v === null ? 0 : (int) (json_decode((string) $v, true)['done'] ?? 0);
    }

    private static function progSet(string $id, ?int $done): void
    {
        $k = '_news_prog_' . $id;
        if ($done === null) { Db::exec('DELETE FROM app_config WHERE `key` = ?', [$k]); return; }
        Db::exec('INSERT INTO app_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP(3)', [$k, json_encode(['done' => $done])]);
    }

    /**
     * یک تکهٔ کار برای یک منبع تا self::$deadline. اگر منبع کامل شد last_checked_at ثبت می‌شود؛
     * وگرنه پیشرفت ذخیره می‌شود و نوبت بعد ادامه می‌یابد.
     * @return array{source:string, decisions:array, error?:string, finished:bool}
     */
    private static function runSlice(array $src, array $st, bool $restart): array
    {
        $rules = self::loadRules($src['id']);
        $decisions = []; $error = null; $finished = true;
        $done = $restart ? 0 : self::progGet($src['id']);
        try {
            $items = self::listItems($src);
            $hashes = array_map(static fn($i) => hash('sha256', self::normalizeUrl($i['url'])), $items);
            $known = $hashes ? array_flip(array_column(Db::all('SELECT url_hash FROM news_items WHERE url_hash IN ' . Db::in($hashes), $hashes), 'url_hash')) : [];
            $fresh = [];
            foreach ($items as $i => $it) if (!isset($known[$hashes[$i]])) $fresh[] = $it;
            $batch = array_slice($fresh, 0, max(0, $st['perRun'] - $done));
            $n = 0;
            foreach ($batch as $it) {
                if (self::left() < 4) break;
                $decisions[] = self::judge($src, ['url' => $it['url'], 'title' => $it['title'], 'published' => self::toIso($it['published'] ?? null), 'summary' => $it['summary'] ?? null], $rules, $st, true, false);
                $n++;
            }
            $finished = $n >= count($batch);
            $done += $n;
        } catch (\Throwable $e) { $error = $e->getMessage(); $finished = true; }
        if ($finished) {
            self::progSet($src['id'], null);
            if ($error !== null) Db::exec('UPDATE news_sources SET last_checked_at = UTC_TIMESTAMP(3), last_error = ? WHERE id = ?', [mb_substr($error, 0, 1000), $src['id']]);
            else Db::exec('UPDATE news_sources SET last_checked_at = UTC_TIMESTAMP(3), last_ok_at = UTC_TIMESTAMP(3), last_error = NULL WHERE id = ?', [$src['id']]);
        } else {
            self::progSet($src['id'], $done);
        }
        $r = ['source' => (string) $src['name'], 'decisions' => $decisions, 'finished' => $finished];
        if ($error !== null) $r['error'] = $error;
        return $r;
    }

    private static function acceptedCount(array $results): int
    {
        $n = 0;
        foreach ($results as $r) foreach ($r['decisions'] as $d) if ($d['decision'] === 'accept') $n++;
        return $n;
    }

    private static function isDue(array $s): bool
    {
        return $s['last_checked_at'] === null || time() - strtotime($s['last_checked_at'] . ' UTC') >= (int) $s['check_every_min'] * 60 - 20;
    }

    // =================================================================== cron
    public static function cron(): void
    {
        self::$deadline = microtime(true) + 20;
        $rows = Db::all("SELECT * FROM news_sources WHERE is_active = 1 AND kind <> 'push'");
        $inProg = array_column(Db::all("SELECT `key` FROM app_config WHERE `key` LIKE '\\_news\\_prog\\_%'"), 'key');
        $due = array_values(array_filter($rows, static fn($s) => in_array('_news_prog_' . $s['id'], $inProg, true) || self::isDue($s)));
        // ابتدا منبع‌های نیمه‌کاره، بعد هرکدام که دیرتر بررسی شده
        usort($due, static function ($a, $b) use ($inProg) {
            $pa = in_array('_news_prog_' . $a['id'], $inProg, true) ? 0 : 1;
            $pb = in_array('_news_prog_' . $b['id'], $inProg, true) ? 0 : 1;
            return [$pa, (string) $a['last_checked_at']] <=> [$pb, (string) $b['last_checked_at']];
        });
        if (!$due) return;
        $st = self::loadSettings();
        $results = [];
        foreach ($due as $s) {
            if (self::left() < 5) break;
            $lock = 'news_' . $s['id'];
            if (!Db::lock($lock, 0)) continue;
            try { $results[] = self::runSlice($s, $st, false); }
            catch (\Throwable $e) { Http::logLine('news cron ' . $s['id'] . ': ' . $e->getMessage()); }
            finally { Db::unlock($lock); }
        }
        $n = self::acceptedCount($results);
        if ($n > 0) Rpc::call('news_notify', ['p_count' => $n]);
    }

    // =================================================================== entry
    public static function handle(array $body, array $ctx): array
    {
        $key = '';
        foreach (($ctx['headers'] ?? []) as $k => $v) if (strcasecmp((string) $k, 'x-ingest-key') === 0) $key = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        $want = (string) Config::get('ingest_key', '');
        $isAdmin = false;
        if ($key !== '' && $want !== '' && hash_equals($want, $key)) { /* اسکریپت */ }
        else {
            if (Ctx::uid() === null) throw new ApiError('missing_authorization', 401);
            if (!in_array(Ctx::role(), ['admin', 'news_editor'], true)) throw new ApiError('forbidden', 403);
            $isAdmin = true;
        }
        return Ctx::asService(fn() => self::dispatch($body, $isAdmin));
    }

    private static function dispatch(array $body, bool $isAdmin): array
    {
        self::$deadline = microtime(true) + 22;
        $action = $body['action'] ?? 'poll';
        $action = is_scalar($action) ? (string) $action : 'poll';
        $st = self::loadSettings();
        try {
            if ($action === 'detect') {
                if (!$isAdmin) throw new ApiError('forbidden', 403);
                $url = trim(self::s($body['url'] ?? ''));
                if (!preg_match('~^https?://~i', $url) || !self::isSafeUrl($url)) return ['ok' => false, 'error' => 'نشانی معتبر نیست.'];
                try {
                    $page = self::fetchText($url);
                    if (self::looksLikeFeed($page)) return ['ok' => true, 'kind' => 'rss', 'feed_url' => $url, 'note' => 'این نشانی یک خوراک RSS/Atom است.'];
                    foreach (array_slice(self::findFeedLinks($page, $url), 0, 3) as $f) {
                        try { if (self::parseFeed(self::fetchText($f), $f)) return ['ok' => true, 'kind' => 'rss', 'feed_url' => $f, 'note' => 'صفحه یک خوراک RSS معرفی کرده بود و از آن استفاده شد.']; } catch (\Throwable) { /* next */ }
                    }
                    $guesses = self::guessLinkPatterns($page, $url);
                    if (!$guesses) return ['ok' => false, 'error' => 'نه خوراک RSS پیدا شد و نه فهرستی از خبرها. نشانی صفحهٔ «آخرین اخبار» یا آرشیو خبرها را امتحان کنید.'];
                    $g = $guesses[0];
                    $selector = null; $note = "الگوی نشانی خبرها حدس زده شد ({$g['count']} پیوند هم‌شکل در صفحه).";
                    try { $selector = self::guessBodySelector(self::fetchText($g['samples'][0]['url'])); $note .= $selector ? ' محل متن خبر هم تشخیص داده شد.' : ' متن خبر خودکار استخراج می‌شود.'; }
                    catch (\Throwable) { $note .= ' باز کردن یک خبر نمونه ممکن نشد.'; }
                    return ['ok' => true, 'kind' => 'web', 'feed_url' => $url, 'link_pattern' => $g['pattern'], 'body_selector' => $selector, 'note' => $note,
                        'samples' => array_column($g['samples'], 'title'), 'alternatives' => array_column(array_slice($guesses, 1), 'pattern')];
                } catch (ApiError $e) { throw $e; }
                catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
            }

            if ($action === 'test') {
                if (!$isAdmin) throw new ApiError('forbidden', 403);
                $src = null;
                if (!empty($body['source_id'])) $src = Db::one('SELECT * FROM news_sources WHERE id = ?', [self::s($body['source_id'])]);
                elseif (!empty($body['source']) && is_array($body['source'])) {
                    $b = $body['source'];
                    $src = ['id' => '', 'name' => 'آزمایش', 'kind' => self::s($b['kind'] ?? ''), 'feed_url' => self::s($b['feed_url'] ?? '') ?: null,
                        'link_pattern' => self::s($b['link_pattern'] ?? '') ?: null, 'body_selector' => self::s($b['body_selector'] ?? '') ?: null, 'check_every_min' => 30];
                }
                if (!$src || $src['kind'] === 'push' || !in_array($src['kind'], ['rss', 'web'], true)) throw new ApiError('source_not_found', 404);
                if (empty($src['feed_url']) || !self::isSafeUrl((string) $src['feed_url'])) throw new ApiError('bad_url', 400);
                $rules = self::loadRules($src['id'] !== '' ? $src['id'] : null);
                $decisions = []; $error = null;
                try {
                    foreach (array_slice(self::listItems($src), 0, 5) as $i) {
                        if (self::left() < 4) break;
                        $decisions[] = self::judge($src, ['url' => $i['url'], 'title' => $i['title'], 'published' => self::toIso($i['published'] ?? null), 'summary' => $i['summary'] ?? null], $rules, $st, false, true);
                    }
                } catch (\Throwable $e) { $error = $e->getMessage(); }
                return ['ok' => $error === null, 'error' => $error, 'decisions' => $decisions];
            }

            if ($action === 'push') {
                $sid = !empty($body['source_id']) ? self::s($body['source_id']) : null;
                $src = null;
                if (!$sid && !empty($body['source_name'])) {
                    $nm = mb_substr(self::s($body['source_name']), 0, 120);
                    $ex = Db::one("SELECT id, body_selector FROM news_sources WHERE name = ? AND kind = 'push' LIMIT 1", [$nm]);
                    if ($ex) { $sid = $ex['id']; $src = $ex; }
                    else {
                        $sid = Db::uuid();
                        Db::exec("INSERT INTO news_sources (id, name, kind) VALUES (?, ?, 'push')", [$sid, $nm]);
                        $src = ['id' => $sid, 'body_selector' => null];
                    }
                } elseif ($sid) {
                    $src = Db::one('SELECT id, body_selector FROM news_sources WHERE id = ?', [$sid]);
                    if (!$src) throw new ApiError('source_not_found', 404);
                } else throw new ApiError('source_required', 400);
                $items = isset($body['items']) && is_array($body['items']) ? array_slice(array_values($body['items']), 0, 50) : [];
                if (!$items) throw new ApiError('no_items', 400);
                $rules = self::loadRules($sid);
                $decisions = [];
                foreach ($items as $x) {
                    $x = is_array($x) ? $x : [];
                    $paras = [];
                    if (isset($x['paragraphs']) && is_array($x['paragraphs'])) {
                        foreach ($x['paragraphs'] as $p) if (is_array($p) && !empty($p['text'])) $paras[] = ['kind' => self::s($p['kind'] ?? '') ?: 'paragraph', 'text' => self::s($p['text'])];
                    } elseif (!empty($x['text'])) $paras = self::splitText(self::s($x['text']));
                    $decisions[] = self::judge($src, ['url' => self::s($x['url'] ?? ''), 'title' => !empty($x['title']) ? self::s($x['title']) : null,
                        'published' => self::toIso(isset($x['published_at']) ? self::s($x['published_at']) : null), 'paragraphs' => $paras], $rules, $st, true, false);
                }
                $n = count(array_filter($decisions, static fn($d) => $d['decision'] === 'accept'));
                Rpc::call('news_notify', ['p_count' => $n]);
                return ['ok' => true, 'accepted' => $n, 'decisions' => $decisions];
            }

            if ($action === 'poll') {
                $force = !empty($body['force']);
                $sourceId = !empty($body['source_id']) ? self::s($body['source_id']) : null;
                $rows = $sourceId
                    ? Db::all("SELECT * FROM news_sources WHERE is_active = 1 AND kind <> 'push' AND id = ?", [$sourceId])
                    : Db::all("SELECT * FROM news_sources WHERE is_active = 1 AND kind <> 'push'");
                $due = array_values(array_filter($rows, static fn($s) => $force || $sourceId !== null || self::isDue($s)));
                $results = []; $pending = 0;
                foreach ($due as $s) {
                    if (self::left() < 5) { $pending++; continue; }
                    $lock = 'news_' . $s['id'];
                    if (!Db::lock($lock, 0)) { $pending++; continue; }   // هم‌زمان توسط cron در حال اجراست
                    try { $results[] = self::runSlice($s, $st, $force || $sourceId !== null); }
                    finally { Db::unlock($lock); }
                }
                $n = self::acceptedCount($results);
                Rpc::call('news_notify', ['p_count' => $n]);
                $out = ['ok' => true, 'accepted' => $n, 'checked' => count($results), 'results' => array_map(static function ($r) { unset($r['finished']); return $r; }, $results)];
                $partial = $pending + count(array_filter($results, static fn($r) => !$r['finished']));
                if ($partial > 0) $out['pending'] = $partial; // ادامه با cron
                return $out;
            }
            throw new ApiError('unknown_action', 400);
        } catch (ApiError $e) { throw $e; }
        catch (\Throwable $e) { Http::logLine('news-ingest: ' . $e->getMessage()); throw new ApiError($e->getMessage(), 500); }
    }
}
