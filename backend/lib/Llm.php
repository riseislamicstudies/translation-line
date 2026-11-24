<?php
declare(strict_types=1);
namespace Rise;

/**
 * خطای ارائه‌دهندهٔ LLM. retryable = «تلاش دوباره منطقی است» (429/5xx/قطع شبکه/پاسخ خالی).
 * (این کلاس در همین فایل است؛ همیشه بعد از لود شدن Rise\Llm استفاده می‌شود.)
 */
final class LlmError extends \RuntimeException
{
    public function __construct(string $message, public bool $retryable = false)
    {
        parent::__construct($message);
    }
}

/**
 * سرویس‌گیرندهٔ بی‌طرف LLM (پورت llm.ts): gemini | openai | anthropic | openai_compat.
 *
 * API عمومی برای توابع دیگر:
 *
 *   $llm = Llm::forTask('translate'|'review'|'rewrite'|'curate'|'seo', ?string $defaultGeminiModel = 'gemini-2.5-flash');
 *       تنظیمات را از app_config (ai_provider_<task>، ai_model_<task>، ai_fallback_<task>، ai_conns، ai_base_url،
 *       ai_wire_api، ai_extra_headers، ai_max_tokens) و کلیدها را از Secrets (AI_KEY_<id> یا GEMINI_API_KEY ...) می‌خواند.
 *       ai_provider_<task> = نام ارائه‌دهنده یا «conn:<id>» (اتصال ذخیره‌شده). curate/seo اگر تنظیم جدا نداشته باشند از rewrite می‌خوانند.
 *       اگر کلید یا مدل نباشد LlmError (غیرقابل‌تکرار) با همان متن اصلی `<provider>_key_not_set: ...` پرتاب می‌شود.
 *       با $requireKey=false فقط تنظیمات برمی‌گردد (بدون خطا).
 *
 *   $llm->generate(string $system, string $user, array $opts = []): string
 *       یک تماس (+ اتصال‌های fallback ذخیره‌شده، و تلاش دوباره بدون response_format برای سرورهای سازگار).
 *       $opts: json(bool) خروجی JSON بخواه · schema(array) طرح خروجی ساختاریافتهٔ Gemini · temperature(float=0.2)
 *              timeout(ثانیهٔ کل، پیش‌فرض 60) · connect_timeout(پیش‌فرض 10) · model(نام مدل برای همین تماس)
 *       خطا → LlmError(->retryable). هیچ sleep یا حلقهٔ تلاشی داخل آن نیست؛ تلاش دوباره با فراخواننده است
 *       (در job: با delay برگرداندن، نه با sleep).
 *
 *   $llm->generateJson(string $system, string $user, array $opts = []): mixed
 *       مثل generate با json=true و سپس Llm::parseJson(). متن نامعتبر → LlmError('response_is_not_json', retryable).
 *
 *   Llm::parseJson(string $text): mixed   (حصار ```json و متن اطراف را تحمل می‌کند؛ خطا → LlmError)
 *   $llm->provider() / ->model() / ->label() / ->withModel($m) / ->maxTokens()
 *   Llm::isSafeBaseUrl(string): bool · Llm::parseExtraHeaders(string): array
 *   Llm::buildRequest(array $cfg, string $system, string $user, array $opts): array{url,headers,body}   (خالص؛ برای تست)
 *   Llm::parseResponse(array $cfg, int $status, string $raw): string                                    (خالص؛ برای تست)
 */
final class Llm
{
    public const PROVIDERS = ['gemini', 'openai', 'anthropic', 'openai_compat'];
    private const SECRET_NAME = [
        'gemini' => 'GEMINI_API_KEY', 'openai' => 'OPENAI_API_KEY',
        'anthropic' => 'ANTHROPIC_API_KEY', 'openai_compat' => 'OPENAI_COMPAT_API_KEY',
    ];

    /** @param array $cfg provider, model, apiKey, baseUrl, maxTokens, wireApi, extraHeaders, label, fallbacks[] */
    private function __construct(private array $cfg) {}

    public static function fromConfig(array $cfg): self { return new self($cfg); }

    public function provider(): string { return (string) $this->cfg['provider']; }
    public function model(): string { return (string) $this->cfg['model']; }
    public function label(): string { return (string) ($this->cfg['label'] ?? $this->cfg['provider']); }
    public function maxTokens(): int { return (int) $this->cfg['maxTokens']; }
    public function config(): array { $c = $this->cfg; $c['apiKey'] = $c['apiKey'] !== '' ? '***' : ''; unset($c['fallbacks']); return $c; }
    public function withModel(string $model): self { $c = $this->cfg; $c['model'] = $model; return new self($c); }

    // ------------------------------------------------------------------ تنظیمات
    public static function forTask(string $task, ?string $defaultGeminiModel = 'gemini-2.5-flash', bool $requireKey = true): self
    {
        if (!in_array($task, ['translate', 'review', 'rewrite', 'curate', 'seo'], true)) throw new \InvalidArgumentException("bad task $task");
        $defaultGeminiModel = $defaultGeminiModel ?? 'gemini-2.5-flash';
        if ($task === 'curate' || $task === 'seo') {
            $own = Db::all('SELECT `value` FROM app_config WHERE `key` IN (?, ?)', ["ai_provider_$task", "ai_model_$task"]);
            $has = false;
            foreach ($own as $r) if (trim((string) $r['value']) !== '') $has = true;
            if (!$has) $task = 'rewrite';
        }
        $cfg = self::loadConfig($task, $defaultGeminiModel);
        if ($requireKey) {
            if ($cfg['apiKey'] === '') throw new LlmError($cfg['provider'] . '_key_not_set: save the key in the app (Admin > AI service)', false);
            if ($cfg['model'] === '') throw new LlmError($cfg['provider'] . '_model_not_set: enter the model name in the app (Admin > AI service)', false);
        }
        return new self($cfg);
    }

    private static function loadConfig(string $task, string $defaultGeminiModel): array
    {
        $keys = ["ai_provider_$task", "ai_model_$task", "ai_fallback_$task", 'ai_conns', 'ai_base_url', 'ai_max_tokens', 'ai_wire_api', 'ai_extra_headers'];
        $c = [];
        foreach (Db::all('SELECT `key`, `value` FROM app_config WHERE `key` IN ' . Db::in($keys), $keys) as $r) $c[$r['key']] = (string) $r['value'];
        $conns = [];
        $j = json_decode($c['ai_conns'] ?? '[]', true);
        if (is_array($j)) {
            foreach ($j as $x) {
                if (is_array($x) && isset($x['id'], $x['provider']) && is_string($x['id']) && preg_match('/^[a-z0-9]{2,12}$/', $x['id']) && in_array($x['provider'], self::PROVIDERS, true)) $conns[] = $x;
            }
        }
        $maxTokens = (int) ($c['ai_max_tokens'] ?? 0);
        if ($maxTokens <= 0) $maxTokens = (int) (Secrets::get('GEMINI_MAX_OUTPUT_TOKENS') ?: 32768) ?: 32768;
        $wire = fn(?string $w) => $w === 'responses' ? 'responses' : 'chat';
        $fromConn = function (array $cn, string $model) use ($c, $maxTokens, $defaultGeminiModel, $wire): array {
            $m = $model !== '' ? $model : trim((string) ($cn['model'] ?? ''));
            if ($m === '' && $cn['provider'] === 'gemini') $m = $defaultGeminiModel;
            $base = (string) ($cn['base_url'] ?? '');
            if ($base === '') $base = $c['ai_base_url'] ?? '';
            $w = (string) ($cn['wire_api'] ?? '');
            if ($w === '') $w = $c['ai_wire_api'] ?? '';
            $h = (string) ($cn['headers'] ?? '');
            if ($h === '') $h = $c['ai_extra_headers'] ?? '';
            return [
                'provider' => $cn['provider'], 'model' => $m, 'apiKey' => Secrets::get('AI_KEY_' . $cn['id']),
                'baseUrl' => $base !== '' ? $base : null, 'maxTokens' => $maxTokens, 'wireApi' => $wire($w),
                'extraHeaders' => self::parseExtraHeaders($h), 'label' => (string) (($cn['label'] ?? '') !== '' ? $cn['label'] : $cn['id']),
            ];
        };
        $sel = trim($c["ai_provider_$task"] ?? '');
        $taskModel = trim($c["ai_model_$task"] ?? '');
        $cn = null;
        if (str_starts_with($sel, 'conn:')) foreach ($conns as $x) if ($x['id'] === substr($sel, 5)) { $cn = $x; break; }
        if ($cn) {
            $cfg = $fromConn($cn, $taskModel);
        } else {
            $provider = in_array($sel, self::PROVIDERS, true) ? $sel : 'gemini';
            $model = $taskModel;
            if ($model === '' && $provider === 'gemini') $model = $defaultGeminiModel;
            $base = $c['ai_base_url'] ?? '';
            $cfg = [
                'provider' => $provider, 'model' => $model, 'apiKey' => Secrets::get(self::SECRET_NAME[$provider]),
                'baseUrl' => $base !== '' ? $base : null, 'maxTokens' => $maxTokens, 'wireApi' => $wire($c['ai_wire_api'] ?? ''),
                'extraHeaders' => self::parseExtraHeaders($c['ai_extra_headers'] ?? ''), 'label' => $provider,
            ];
        }
        $fb = [];
        foreach (array_filter(array_map('trim', explode(',', $c["ai_fallback_$task"] ?? ''))) as $id) {
            if ($id === substr($sel, 5)) continue;
            $f = null;
            foreach ($conns as $x) if ($x['id'] === $id) { $f = $x; break; }
            if (!$f) continue;
            $fc = $fromConn($f, '');
            if ($fc['apiKey'] !== '' && $fc['model'] !== '') $fb[] = $fc;   // fallback نیاز به کلید و مدل خودش دارد
        }
        $cfg['fallbacks'] = $fb;
        return $cfg;
    }

    /** "Name: value" در هر خط؛ فقط نام‌های ساده، و هرگز چیزی که احراز هویت/مسیر را عوض کند. */
    public static function parseExtraHeaders(?string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
            $i = strpos($line, ':');
            if ($i === false || $i < 1) continue;
            $k = trim(substr($line, 0, $i));
            $v = trim(substr($line, $i + 1));
            if (!preg_match('/^[A-Za-z0-9-]{1,60}$/', $k) || $v === '' || strlen($v) > 300 || preg_match('/[\r\n]/', $v)) continue;
            if (preg_match('/^(authorization|host|content-type|content-length|x-api-key|apikey|cookie)$/i', $k)) continue;
            $out[$k] = $v;
        }
        return $out;
    }

    /** فقط https و هرگز آدرس خصوصی/محلی (آدرس را آدم تایپ می‌کند). */
    public static function isSafeBaseUrl(string $raw): bool
    {
        $u = parse_url($raw);
        if (!is_array($u) || strtolower((string) ($u['scheme'] ?? '')) !== 'https' || empty($u['host'])) return false;
        if (isset($u['user']) || isset($u['pass'])) return false;
        $h = strtolower((string) $u['host']);
        if ($h === 'localhost' || str_ends_with($h, '.local') || str_ends_with($h, '.internal') || !str_contains($h, '.')) return false;
        if (str_starts_with($h, '[') || str_contains($h, ':')) return false;
        // برچسب آخر باید حرف داشته باشد (رد IP و شکل‌های عددی/هگز مثل 0x7f.1)
        $labels = explode('.', rtrim($h, '.'));
        if (!preg_match('/^[a-z][a-z0-9-]*$/', (string) end($labels))) return false;
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $h)) return false;
        return true;
    }

    // ------------------------------------------------------------------ ساخت درخواست / تفسیر پاسخ (خالص)
    public static function buildRequest(array $cfg, string $system, string $prompt, array $o = []): array
    {
        $temp = (float) ($o['temperature'] ?? 0.2);
        $json = !empty($o['json']);
        $enc = static fn(array $b): string => (string) json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $p = $cfg['provider'];
        if ($p === 'gemini') {
            $gen = ['temperature' => $temp, 'maxOutputTokens' => (int) $cfg['maxTokens']];
            if ($json) {
                $gen['responseMimeType'] = 'application/json';
                if (!empty($o['schema'])) $gen['responseSchema'] = $o['schema'];
            }
            $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => $gen];
            if ($system !== '') $body['systemInstruction'] = ['parts' => [['text' => $system]]];
            return [
                'url' => 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode((string) $cfg['model']) . ':generateContent',
                'headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $cfg['apiKey']],
                'body' => $enc($body),
            ];
        }
        if ($p === 'anthropic') {
            $body = ['model' => $cfg['model'], 'max_tokens' => (int) $cfg['maxTokens'], 'messages' => [['role' => 'user', 'content' => $prompt]]];
            if ($system !== '') $body['system'] = $system;
            return [
                'url' => 'https://api.anthropic.com/v1/messages',
                'headers' => ['Content-Type' => 'application/json', 'x-api-key' => $cfg['apiKey'], 'anthropic-version' => '2023-06-01'],
                'body' => $enc($body),
            ];
        }
        // openai و openai_compat
        $base = $p === 'openai' ? 'https://api.openai.com/v1' : rtrim((string) ($cfg['baseUrl'] ?? ''), '/');
        $headers = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $cfg['apiKey']];
        if ($p === 'openai_compat') $headers += (array) ($cfg['extraHeaders'] ?? []);
        $noRf = !empty($o['no_response_format']);
        if (($cfg['wireApi'] ?? 'chat') === 'responses') {
            $rb = ['model' => $cfg['model'], 'input' => $system !== '' ? "$system\n\n$prompt" : $prompt, 'max_output_tokens' => (int) $cfg['maxTokens'], 'store' => false];
            if ($json && !$noRf) $rb['text'] = ['format' => ['type' => 'json_object']];
            return ['url' => "$base/responses", 'headers' => $headers, 'body' => $enc($rb)];
        }
        $messages = [];
        if ($system !== '') $messages[] = ['role' => 'system', 'content' => $system];
        $messages[] = ['role' => 'user', 'content' => $prompt];
        $body = ['model' => $cfg['model'], 'messages' => $messages];
        if ($p === 'openai') $body['max_completion_tokens'] = (int) $cfg['maxTokens']; else $body['max_tokens'] = (int) $cfg['maxTokens'];
        if ($json && !$noRf) $body['response_format'] = ['type' => 'json_object'];
        return ['url' => "$base/chat/completions", 'headers' => $headers, 'body' => $enc($body)];
    }

    /** پاسخ HTTP → متن، یا LlmError که می‌گوید تکرار منطقی است یا نه. */
    public static function parseResponse(array $cfg, int $status, string $raw): string
    {
        $name = $cfg['provider'];
        if ($status < 200 || $status >= 300) {
            $detail = mb_substr($raw, 0, 500);
            if ($status === 429 && preg_match('/per\s*day|PerDay|requests per day|insufficient_quota|exceeded your current quota|credit balance/i', $raw)) {
                throw new LlmError("{$name}_quota_exceeded: the quota or credit of this key is used up. $detail", false);
            }
            if ($status === 400 && preg_match('/credit balance|billing/i', $raw)) throw new LlmError("{$name}_billing: $detail", false);
            $retry = $status === 429 || $status === 408 || $status === 529 || $status >= 500;
            throw new LlmError("{$name}_http_{$status}: $detail", $retry);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new LlmError("{$name}_bad_json_response", true);
        $truncMsg = 'output_truncated: raise the maximum output tokens or split the document';

        if ($name === 'gemini') {
            if (!empty($data['promptFeedback']['blockReason'])) throw new LlmError('gemini_blocked: ' . $data['promptFeedback']['blockReason'], false);
            $c = $data['candidates'][0] ?? null;
            if (!$c) throw new LlmError('gemini_no_candidates', true);
            if (($c['finishReason'] ?? '') === 'MAX_TOKENS') throw new LlmError("gemini_$truncMsg", false);
            $text = '';
            foreach ((array) ($c['content']['parts'] ?? []) as $part) $text .= is_array($part) ? (string) ($part['text'] ?? '') : '';
            if (trim($text) === '') throw new LlmError('gemini_empty_response', true);
            return $text;
        }
        if ($name === 'anthropic') {
            if (($data['stop_reason'] ?? '') === 'max_tokens') throw new LlmError("anthropic_$truncMsg", false);
            $text = '';
            foreach ((array) ($data['content'] ?? []) as $b) if (is_array($b) && ($b['type'] ?? '') === 'text') $text .= (string) ($b['text'] ?? '');
            if (trim($text) === '') throw new LlmError('anthropic_empty_response', true);
            return $text;
        }
        if (($cfg['wireApi'] ?? 'chat') === 'responses') {
            if (($data['status'] ?? '') === 'incomplete' && ($data['incomplete_details']['reason'] ?? '') === 'max_output_tokens') {
                throw new LlmError("{$name}_$truncMsg", false);
            }
            if (!empty($data['error'])) throw new LlmError("{$name}_error: " . mb_substr((string) json_encode($data['error'], JSON_UNESCAPED_UNICODE), 0, 300), true);
            $text = is_string($data['output_text'] ?? null) ? $data['output_text'] : '';
            if ($text === '') {
                foreach ((array) ($data['output'] ?? []) as $it) {
                    if (!is_array($it) || ($it['type'] ?? '') !== 'message') continue;
                    foreach ((array) ($it['content'] ?? []) as $pt) {
                        if (is_array($pt) && ($pt['type'] ?? '') === 'output_text' && is_string($pt['text'] ?? null)) $text .= $pt['text'];
                    }
                }
            }
            if (trim($text) === '') throw new LlmError("{$name}_empty_response", true);
            return $text;
        }
        $ch = $data['choices'][0] ?? null;
        if (!$ch) throw new LlmError("{$name}_no_choices", true);
        $fr = $ch['finish_reason'] ?? null;
        if ($fr === 'length') throw new LlmError("{$name}_$truncMsg", false);
        if ($fr === 'content_filter') throw new LlmError("{$name}_blocked: content filter", false);
        $content = $ch['message']['content'] ?? null;
        $text = '';
        if (is_string($content)) $text = $content;
        elseif (is_array($content)) foreach ($content as $pt) $text .= is_array($pt) ? (string) ($pt['text'] ?? '') : '';
        if (trim($text) === '') throw new LlmError("{$name}_empty_response", true);
        return $text;
    }

    // ------------------------------------------------------------------ تماس
    public function generate(string $system, string $user, array $opts = []): string
    {
        $cfg = $this->cfg;
        if (!empty($opts['model'])) $cfg['model'] = (string) $opts['model'];
        try {
            return self::callOne($cfg, $system, $user, $opts);
        } catch (LlmError $e) {
            $fbs = $this->cfg['fallbacks'] ?? [];
            if (!$fbs) throw $e;
            $last = $e;
            foreach ($fbs as $fb) {
                try { return self::callOne($fb, $system, $user, $opts); } catch (LlmError $e2) { $last = $e2; }
            }
            throw new LlmError($e->getMessage() . ' | fallback: ' . $last->getMessage(), $last->retryable);
        }
    }

    public function generateJson(string $system, string $user, array $opts = []): mixed
    {
        $opts['json'] = true;
        return self::parseJson($this->generate($system, $user, $opts));
    }

    /** متن مدل → JSON؛ حصار کد و متن اطراف را تحمل می‌کند. */
    public static function parseJson(string $text): mixed
    {
        $c = trim(preg_replace('/^\x{FEFF}/u', '', $text) ?? $text);
        $c = preg_replace('/^```(?:json)?\s*/i', '', $c) ?? $c;
        $c = preg_replace('/\s*```$/', '', $c) ?? $c;
        $r = json_decode($c, true);
        if ($r !== null || trim($c) === 'null') return $r;
        $a = strpos($c, '{'); $b = strrpos($c, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $r = json_decode(substr($c, $a, $b - $a + 1), true);
            if ($r !== null) return $r;
        }
        $a = strpos($c, '['); $b = strrpos($c, ']');
        if ($a !== false && $b !== false && $b > $a) {
            $r = json_decode(substr($c, $a, $b - $a + 1), true);
            if ($r !== null) return $r;
        }
        throw new LlmError('response_is_not_json', true);
    }

    private static function callOne(array $cfg, string $system, string $user, array $o): string
    {
        if (($cfg['apiKey'] ?? '') === '') throw new LlmError($cfg['provider'] . '_key_not_set', false);
        if (($cfg['model'] ?? '') === '') throw new LlmError($cfg['provider'] . '_model_not_set', false);
        if ($cfg['provider'] === 'openai_compat' && !self::isSafeBaseUrl((string) ($cfg['baseUrl'] ?? ''))) {
            throw new LlmError('openai_compat_base_url_invalid: an https address of a public server is required', false);
        }
        [$status, $raw] = self::http($cfg, self::buildRequest($cfg, $system, $user, $o), $o);
        // بعضی سرورهای سازگار response_format را رد می‌کنند: یک بار بدون آن
        if ($cfg['provider'] === 'openai_compat' && !empty($o['json']) && $status === 400
            && preg_match('/response_format|json_object|text\.format|"format"/i', $raw)) {
            [$status, $raw] = self::http($cfg, self::buildRequest($cfg, $system, $user, $o + ['no_response_format' => true]), $o);
        }
        return self::parseResponse($cfg, $status, $raw);
    }

    /** @return array{0:int,1:string} */
    private static function http(array $cfg, array $r, array $o): array
    {
        $h = [];
        foreach ($r['headers'] as $k => $v) $h[] = "$k: $v";
        $ch = curl_init($r['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $r['body'], CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ($o['connect_timeout'] ?? 10)),
            CURLOPT_TIMEOUT_MS => max(1000, (int) round(((float) ($o['timeout'] ?? 60)) * 1000)),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) throw new LlmError($cfg['provider'] . '_request_failed: ' . ($err !== '' ? $err : "curl $errno"), true);
        return [$status, (string) $raw];
    }
}
