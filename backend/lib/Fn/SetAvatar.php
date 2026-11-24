<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Ctx};

/**
 * set-avatar: هر کاربر واردشده عکس پروفایل خودش را عوض یا حذف می‌کند.
 *   {avatar: 'data:image/jpeg;base64,…' (بندانگشتی), original?: 'data:image/…' (فایل اصلی با کیفیت کامل)}  → ذخیره
 *   {avatar: ''} → حذف
 * ادمین می‌تواند با user_id برای دیگران هم بزند (مثل manage-users/set_avatar).
 */
final class SetAvatar
{
    public const AUTH = true;

    public static function handle(array $body, array $ctx): array
    {
        $uid = Ctx::uid();
        if ($uid === null) throw new ApiError('missing_authorization', 401);
        $target = $uid;
        $other = is_scalar($body['user_id'] ?? null) ? strtolower((string) $body['user_id']) : '';
        if ($other !== '' && $other !== $uid) {
            if (Ctx::role() !== 'admin') throw new ApiError('forbidden', 403);
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $other)) throw new ApiError('invalid_user_id', 400);
            $target = $other;
        }
        $av = is_scalar($body['avatar'] ?? null) ? (string) $body['avatar'] : '';
        $orig = is_scalar($body['original'] ?? null) ? (string) $body['original'] : '';
        return ManageUsers::applyAvatar($target, $av, $orig);
    }
}
