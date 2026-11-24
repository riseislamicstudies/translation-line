<?php
declare(strict_types=1);
namespace Rise;

/**
 * فایل‌های آپلودی (جایگزین Supabase Storage). همه زیر privateDir()/files/<bucket>/<path> ذخیره می‌شوند
 * که بیرون از وب (یا با .htaccess مسدود) است؛ دانلود فقط با لینک امضاشدهٔ کوتاه‌مدت از api/file.php.
 * باکت avatars عمومی است.
 */
final class Storage
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    public const BUCKETS = ['avatars', 'doc-images', 'source-files'];

    public static function root(): string { return Config::privateDir() . '/files'; }

    private static function safe(string $bucket, string $path): string
    {
        if (!in_array($bucket, self::BUCKETS, true)) throw new ApiError('Bucket not found', 404);
        if ($path === '' || strlen($path) > 255 || preg_match('#(^/|\.\.|//|\\\\|\0)#', $path)) throw new ApiError('Invalid key', 400);
        return self::root() . "/$bucket/$path";
    }

    /** بررسی مجوز (معادل سیاست‌های storage.objects) */
    public static function authorize(string $bucket, string $path, string $op): void
    {
        if (Ctx::isService()) return;
        $uid = Ctx::requireUser();
        $role = Ctx::role();
        $deny = static fn() => throw new ApiError('new row violates row-level security policy', 403, '42501');
        if ($bucket === 'doc-images') {
            if (!preg_match('#^(' . self::UUID . ')/' . '[0-9a-f-]{36}\.(jpg|png|webp)$#', $path, $m)) $deny();
            $doc = $m[1];
            $ok = $op === 'read' ? Access::canViewImages($doc) : Access::canAttachImages($doc);
            if (!$ok) $deny();
        } elseif ($bucket === 'source-files') {
            $ok = match ($op) {
                'read' => in_array($role, ['admin', 'reviewer', 'supervisor'], true),
                'delete' => in_array($role, ['admin', 'reviewer'], true),
                default => in_array($role, ['admin', 'reviewer'], true)
                    || (in_array($role, ['writer', 'journal_writer'], true) && preg_match('#^' . self::UUID . '/source\.docx$#', $path)),
            };
            if (!$ok) $deny();
        } else { // avatars: نوشتن فقط توسط سرور (manage-users / writer-signup)
            if ($op !== 'read') $deny();
        }
    }

    public static function put(string $bucket, string $path, string $bytes, bool $overwrite = true): void
    {
        $f = self::safe($bucket, $path);
        if (!$overwrite && is_file($f)) throw new ApiError('The resource already exists', 409);
        if ($bucket === 'doc-images') {
            if (strlen($bytes) > 6 * 1024 * 1024) throw new ApiError('The object exceeded the maximum allowed size', 413);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new ApiError('mime type not supported', 415);
        }
        if (strlen($bytes) > 25 * 1024 * 1024) throw new ApiError('The object exceeded the maximum allowed size', 413);
        $dir = dirname($f);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('cannot create storage dir');
        $tmp = $f . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) === false) throw new \RuntimeException('cannot write file');
        rename($tmp, $f);
    }

    public static function get(string $bucket, string $path): ?string
    {
        $f = self::safe($bucket, $path);
        return is_file($f) ? (string) file_get_contents($f) : null;
    }

    public static function exists(string $bucket, string $path): bool { return is_file(self::safe($bucket, $path)); }

    public static function remove(string $bucket, string $path): void
    {
        $f = self::safe($bucket, $path);
        if (is_file($f)) @unlink($f);
    }

    public static function path(string $bucket, string $path): string { return self::safe($bucket, $path); }

    // ----- لینک امضاشده (جایگزین createSignedUrls) -----
    private static function sigKey(): string
    {
        $k = (string) Config::get('app_key', '');
        if ($k === '') throw new \RuntimeException('app_key is not set in config.php');
        return $k;
    }

    public static function sign(string $bucket, string $path, int $ttl = 3600): string
    {
        $exp = time() + max(60, min($ttl, 86400));
        $sig = hash_hmac('sha256', "$bucket|$path|$exp", self::sigKey());
        return 'api/file.php?b=' . rawurlencode($bucket) . '&p=' . rawurlencode($path) . '&e=' . $exp . '&s=' . $sig;
    }

    public static function verify(string $bucket, string $path, int $exp, string $sig): bool
    {
        return $exp >= time() && hash_equals(hash_hmac('sha256', "$bucket|$path|$exp", self::sigKey()), $sig);
    }
}
