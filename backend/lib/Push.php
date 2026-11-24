<?php
declare(strict_types=1);
namespace Rise;

/**
 * Web Push بدون کتابخانه و بدون gmp/sodium: فقط openssl_* و hash_hkdf.
 *  - VAPID (RFC 8292): JWT با ES256
 *  - رمزگذاری بار پیام (RFC 8291 / RFC 8188 aes128gcm)، یک رکورد
 * کلیدها به‌صورت بایت خام (base64url) ذخیره می‌شوند (همان قالب کتابخانهٔ web-push):
 *   vapid_public (app_config) = ۶۵ بایت نقطهٔ فشرده‌نشده، VAPID_PRIVATE_KEY (app_secrets) = ۳۲ بایت d.
 */
final class Push
{
    // پیشوندهای DER ثابت برای منحنی prime256v1
    private const SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";
    private const SEC1_PREFIX = "\x30\x77\x02\x01\x01\x04\x20";
    private const SEC1_MID    = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00";

    public static function b64uEnc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
    public static function b64uDec(string $s): string
    {
        $s = strtr(trim($s), '+/', '-_');
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $r === false ? '' : $r;
    }

    private static function pem(string $der, string $label): string
    {
        return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
    }

    /** کلید عمومی از ۶۵ بایت خام */
    public static function publicKeyFromRaw(string $raw65): \OpenSSLAsymmetricKey
    {
        if (strlen($raw65) !== 65 || $raw65[0] !== "\x04") throw new \InvalidArgumentException('bad EC public key');
        $k = openssl_pkey_get_public(self::pem(self::SPKI_PREFIX . $raw65, 'PUBLIC KEY'));
        if (!$k) throw new \InvalidArgumentException('invalid EC public key');
        return $k;
    }

    /** کلید خصوصی از d (۳۲ بایت) و نقطهٔ عمومی (۶۵ بایت) */
    public static function privateKeyFromRaw(string $d32, string $pub65): \OpenSSLAsymmetricKey
    {
        if (strlen($d32) !== 32 || strlen($pub65) !== 65) throw new \InvalidArgumentException('bad EC private key');
        $k = openssl_pkey_get_private(self::pem(self::SEC1_PREFIX . $d32 . self::SEC1_MID . $pub65, 'EC PRIVATE KEY'));
        if (!$k) throw new \InvalidArgumentException('invalid EC private key');
        return $k;
    }

    /** @return array{0:string,1:string} [d32, pub65] */
    private static function rawFromKey(\OpenSSLAsymmetricKey $k): array
    {
        $ec = openssl_pkey_get_details($k)['ec'];
        return [str_pad($ec['d'], 32, "\0", STR_PAD_LEFT), "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)];
    }

    /** DER ECDSA → r||s (۶۴ بایت) */
    private static function derToRaw(string $der): string
    {
        $o = 2; // SEQUENCE: طول یک‌بایتی یا 0x81
        if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if ($der[$o] !== "\x02") throw new \RuntimeException('bad DER signature');
            $len = ord($der[$o + 1]);
            $int = substr($der, $o + 2, $len);
            $o += 2 + $len;
            $int = ltrim($int, "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** JWT ES256 برای VAPID */
    public static function vapidJwt(string $audience, string $subject, \OpenSSLAsymmetricKey $priv, ?int $exp = null): string
    {
        $h = self::b64uEnc('{"typ":"JWT","alg":"ES256"}');
        $p = self::b64uEnc(json_encode(['aud' => $audience, 'exp' => $exp ?? time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        if (!openssl_sign("$h.$p", $sig, $priv, OPENSSL_ALGO_SHA256)) throw new \RuntimeException('VAPID sign failed');
        return "$h.$p." . self::b64uEnc(self::derToRaw($sig));
    }

    /**
     * RFC 8291 aes128gcm. خروجی: بدنهٔ کامل درخواست (سرایند RFC 8188 + متن رمزشده).
     * $inject = ['d'=>raw32, 'pub'=>raw65, 'salt'=>raw16] فقط برای آزمون با بردار RFC.
     */
    public static function encrypt(string $plaintext, string $uaPublic65, string $authSecret16, ?array $inject = null): string
    {
        if (strlen($plaintext) > 3993) throw new \InvalidArgumentException('payload too large');
        if (strlen($authSecret16) !== 16) throw new \InvalidArgumentException('bad auth secret');
        if ($inject) {
            $asPriv = self::privateKeyFromRaw($inject['d'], $inject['pub']);
            $asPub = $inject['pub'];
            $salt = $inject['salt'];
        } else {
            $asPriv = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if (!$asPriv) throw new \RuntimeException('EC keygen failed');
            [, $asPub] = self::rawFromKey($asPriv);
            $salt = random_bytes(16);
        }
        $shared = openssl_pkey_derive(self::publicKeyFromRaw($uaPublic65), $asPriv, 32);
        if ($shared === false) throw new \RuntimeException('ECDH failed');
        $shared = str_pad($shared, 32, "\0", STR_PAD_LEFT);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic65 . $asPub, $authSecret16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $ct = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) throw new \RuntimeException('AES-GCM failed');
        return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
    }

    /** کلیدهای VAPID از تنظیمات؛ null اگر تنظیم نشده */
    public static function vapid(): ?array
    {
        $pub = (string) (Db::val('SELECT `value` FROM app_config WHERE `key` = ?', ['vapid_public']) ?? '');
        $priv = Secrets::get('VAPID_PRIVATE_KEY');
        if ($pub === '' || $priv === '') return null;
        $pubRaw = self::b64uDec($pub);
        if (str_contains($priv, 'BEGIN')) { $key = openssl_pkey_get_private($priv); if (!$key) return null; }
        else $key = self::privateKeyFromRaw(self::b64uDec($priv), $pubRaw);
        return ['pub' => $pub, 'key' => $key];
    }

    /**
     * ارسال یک پیام به یک اشتراک. هرگز استثنا نمی‌اندازد.
     * @return array{status:int, body:string, error?:string}  status 0 = خطای شبکه/رمزگذاری
     */
    public static function send(array $sub, string $payload, array $vapid, int $ttl = 86400): array
    {
        try {
            $endpoint = (string) $sub['endpoint'];
            $u = parse_url($endpoint);
            if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])) return ['status' => 0, 'body' => '', 'error' => 'bad endpoint'];
            $aud = 'https://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
            $subject = (string) Config::get('vapid_subject', 'https://riseislamicstudies.com');
            $jwt = self::vapidJwt($aud, $subject, $vapid['key']);
            $body = self::encrypt($payload, self::b64uDec((string) $sub['p256dh']), self::b64uDec((string) $sub['auth']));
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm',
                    'Content-Length: ' . strlen($body), 'TTL: ' . $ttl,
                    'Authorization: vapid t=' . $jwt . ', k=' . $vapid['pub'],
                ],
            ]);
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = $resp === false ? curl_error($ch) : '';
            curl_close($ch);
            return ['status' => $status, 'body' => is_string($resp) ? $resp : '', 'error' => $err];
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
    }
}
