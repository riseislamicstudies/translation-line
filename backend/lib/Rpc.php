<?php
declare(strict_types=1);
namespace Rise;

/**
 * ثبت و اجرای «تابع‌های RPC» (معادل public.* functions در Postgres).
 * هر فایل lib/Rpc/*.php یک آرایه برمی‌گرداند:  ['claim_document' => function (array $a): mixed {...}, ...]
 * آرگومان‌ها با همان نام‌های p_* فرانت می‌آیند. هر RPC داخل یک تراکنش اجرا می‌شود (مثل plpgsql).
 * خطاها: throw new ApiError('کد_خطا', http, pgCode) با همان متن RAISE EXCEPTION اصلی.
 */
final class Rpc
{
    private static ?array $map = null;

    /** RPCهایی که فقط سرویس (توابع لبه/cron) حق صدا زدنشان را دارد، نه مرورگر. */
    private const SERVICE_ONLY = [
        'apply_ai_review', 'apply_ai_translation', 'consume_invite', 'invite_is_valid', 'mark_translation_failed',
        'news_accept', 'news_curate_apply', 'news_notify', 'news_reject', 'notify_role', 'notify_translators', 'notify_user',
        'release_expired_claims', 'release_invite', 'set_ai_review_state',
    ];
    /** بدون ورود هم مجاز */
    private const ANON_OK = ['push_public_key', 'my_role'];

    public static function map(): array
    {
        if (self::$map !== null) return self::$map;
        $m = [];
        foreach (glob(RISE_LIB . '/Rpc/*.php') ?: [] as $f) {
            $r = require $f;
            if (is_array($r)) $m += $r;
        }
        return self::$map = $m;
    }

    /** ورودی مرورگر */
    public static function handle(string $name, array $args): mixed
    {
        if (in_array($name, self::SERVICE_ONLY, true)) throw new ApiError("function public.$name does not exist", 404, '42883');
        if (!in_array($name, self::ANON_OK, true)) Ctx::requireUser();
        return self::call($name, $args);
    }

    /** فراخوانی داخلی (از کد PHP دیگر؛ بدون محدودیت SERVICE_ONLY) */
    public static function call(string $name, array $args = []): mixed
    {
        $fn = self::map()[$name] ?? null;
        if (!$fn) throw new ApiError("function public.$name does not exist", 404, '42883');
        return Db::tx(fn() => $fn($args));
    }

    // ----- کمک‌های مشترک برای نوشتن RPC -----

    /** آرگومان لازم؛ نبودنش مثل خطای PostgREST */
    public static function arg(array $a, string $k): mixed
    {
        if (!array_key_exists($k, $a)) throw new ApiError("Could not find the function with parameter $k", 404, 'PGRST202');
        return $a[$k];
    }

    /** UUID معتبر یا خطای invalid input syntax */
    public static function uuid(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            throw new ApiError('invalid input syntax for type uuid', 400, '22P02');
        }
        return strtolower($v);
    }
}
