<?php
declare(strict_types=1);
namespace Rise;

/**
 * کاربر جاری درخواست (معادل auth.uid() و my_role() در Postgres).
 * در کارهای داخلی (cron، توابع لبه) با Ctx::asService() دسترسی سرویس داده می‌شود.
 */
final class Ctx
{
    private static ?string $uid = null;
    private static ?string $role = null;
    private static bool $service = false;

    public static function set(?string $uid): void { self::$uid = $uid; self::$role = null; self::$service = false; }
    public static function uid(): ?string { return self::$uid; }
    public static function isService(): bool { return self::$service; }

    /** اجرای تابع با دسترسی سرویس (بدون کاربر؛ مثل service_role) */
    public static function asService(callable $fn): mixed
    {
        $old = [self::$uid, self::$role, self::$service];
        self::$uid = null; self::$role = null; self::$service = true;
        try { return $fn(); } finally { [self::$uid, self::$role, self::$service] = $old; }
    }

    /** my_role(): نقش کاربر جاری یا 'none' */
    public static function role(): string
    {
        if (self::$uid === null) return 'none';
        return self::$role ??= (string) (Db::val('SELECT role FROM profiles WHERE id = ?', [self::$uid]) ?? 'none');
    }
    public static function forgetRole(): void { self::$role = null; }

    public static function requireUser(): string
    {
        if (self::$uid === null) throw new ApiError('not_authenticated', 401, '28000');
        return self::$uid;
    }

    /** نقش جاری باید یکی از $roles باشد (سرویس همیشه مجاز) */
    public static function need(string ...$roles): void
    {
        if (self::$service) return;
        self::requireUser();
        if (!in_array(self::role(), $roles, true)) throw new ApiError('not_allowed', 403, '42501');
    }

    public static function is(string ...$roles): bool
    {
        return self::$service || in_array(self::role(), $roles, true);
    }
}
