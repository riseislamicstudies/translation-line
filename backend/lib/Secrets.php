<?php
declare(strict_types=1);
namespace Rise;

/**
 * کلیدهای مخفی (جدول app_secrets). اگر در config.php مقدار app_key تنظیم شده باشد،
 * مقدارها با AES-256-GCM رمز می‌شوند ("enc1:" + base64). مقدارهای قدیمی بدون پیشوند هم خوانده می‌شوند.
 * ترتیب خواندن: app_secrets → config.php (secrets.NAME) → متغیر محیطی.
 */
final class Secrets
{
    private static function key(): ?string
    {
        $k = (string) Config::get('app_key', '');
        return $k === '' ? null : hash('sha256', $k, true);
    }

    private static function enc(string $v): string
    {
        $k = self::key();
        if ($k === null) return $v;
        $iv = random_bytes(12);
        $ct = openssl_encrypt($v, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc1:' . base64_encode($iv . $tag . $ct);
    }

    private static function dec(string $v): string
    {
        if (!str_starts_with($v, 'enc1:')) return $v;
        $k = self::key();
        if ($k === null) return '';
        $raw = base64_decode(substr($v, 5), true) ?: '';
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? '' : $pt;
    }

    public static function get(string $name): string
    {
        $v = Db::val('SELECT `value` FROM app_secrets WHERE name = ?', [$name]);
        if ($v !== null && $v !== '') return self::dec((string) $v);
        $c = Config::get('secrets.' . $name);
        if (is_string($c) && $c !== '') return $c;
        $e = getenv($name);
        return is_string($e) ? $e : '';
    }

    public static function has(string $name): bool { return self::get($name) !== ''; }

    public static function set(string $name, string $value, ?string $by = null): void
    {
        if ($value === '') { Db::exec('DELETE FROM app_secrets WHERE name = ?', [$name]); return; }
        Db::exec('INSERT INTO app_secrets (name, `value`, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP(3)',
            [$name, self::enc($value), $by]);
    }
}
