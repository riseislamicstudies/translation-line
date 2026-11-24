<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Auth, Db, Rpc, Storage};

/**
 * writer-signup (عمومی، با توکن دعوت). actions: check {token} → {valid}; signup {...} → {status:'created'}.
 * اضافه نسبت به نسخهٔ اصلی: محدودیت نرخ ساده برای هر IP روی جدول login_attempts (email='signup').
 */
final class WriterSignup
{
    public const AUTH = false;

    private const EMAIL_RE = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
    private const TOKEN_RE = '/^[0-9a-f]{20,64}$/';
    private const PHONE_RE = '/^[+0-9][0-9 \-()]{6,19}$/';

    private static function str(mixed $v): string { return is_scalar($v) ? (string) $v : ''; }
    private static function err(string $e, int $status = 400, ?string $detail = null): ApiError { return new ApiError($e, $status, null, $detail); }

    /** ارقام فارسی/عربی → ASCII */
    private static function toAscii(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** حداکثر ۱۰ تلاش ثبت‌نام در ساعت برای هر IP */
    private static function rateLimit(): void
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $n = (int) Db::val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = 'signup' AND created_at > UTC_TIMESTAMP(3) - INTERVAL 1 HOUR", [$ip]);
        if ($n >= 10) throw self::err('too_many_attempts', 429);
        Db::exec("INSERT INTO login_attempts (ip, email) VALUES (?, 'signup')", [$ip]);
    }

    public static function handle(array $body, array $ctx): array
    {
        $token = self::str($body['token'] ?? '');
        if (!preg_match(self::TOKEN_RE, $token)) throw self::err('invite_invalid');
        $action = self::str($body['action'] ?? 'signup');

        if ($action === 'check') {
            return ['valid' => Rpc::call('invite_is_valid', ['p_token' => $token]) === true];
        }
        if ($action !== 'signup') throw self::err('unknown_action');

        $first = mb_substr(trim(self::str($body['first_name'] ?? '')), 0, 60);
        $last = mb_substr(trim(self::str($body['last_name'] ?? '')), 0, 60);
        $email = mb_strtolower(trim(self::str($body['email'] ?? '')));
        $password = self::str($body['password'] ?? '');
        $phone = self::toAscii(trim(self::str($body['phone'] ?? '')));
        if ($first === '' || $last === '') throw self::err('name_required');
        if (!preg_match(self::EMAIL_RE, $email) || mb_strlen($email) > 120) throw self::err('invalid_email');
        if (mb_strlen($password) < 8 || mb_strlen($password) > 72) throw self::err('password_too_short', 400, '8 to 72 characters');
        if (!preg_match(self::PHONE_RE, $phone)) throw self::err('invalid_phone');
        if (empty($body['avatar'])) throw self::err('avatar_required');
        [$bytes, $ext] = ManageUsers::decodeAvatar(self::str($body['avatar']));
        $origIn = self::str($body['original'] ?? '');
        if ($origIn !== '') ManageUsers::decodeOriginal($origIn);   // اگر نامعتبر بود همین‌جا خطا بده، قبل از ساخت حساب

        self::rateLimit();

        if (Rpc::call('consume_invite', ['p_token' => $token]) !== true) throw self::err('invite_invalid');
        $giveBack = static function () use ($token): void { try { Rpc::call('release_invite', ['p_token' => $token]); } catch (\Throwable) {} };

        $fullName = "$first $last";
        try {
            $uid = Auth::createUser($email, $password, $fullName);
        } catch (ApiError $e) {
            $giveBack();
            if ($e->getMessage() === 'email_exists') throw self::err('email_taken');
            throw self::err('create_failed', 400, $e->getMessage());
        } catch (\Throwable $e) {
            $giveBack();
            throw self::err('create_failed', 400, 'database error');
        }

        $avatarUrl = null; $origUrl = null;
        try {
            Storage::put('avatars', "$uid.$ext", $bytes, true);
            $avatarUrl = ManageUsers::avatarUrl("$uid.$ext");
            if ($origIn !== '') { [$ob, $oe] = ManageUsers::decodeOriginal($origIn); Storage::put('avatars', "$uid.orig.$oe", $ob, true); $origUrl = ManageUsers::avatarUrl("$uid.orig.$oe"); }
        } catch (\Throwable) { /* عکس ذخیره نشد؛ ثبت‌نام ادامه می‌یابد (مثل اصلی) */ }

        try {
            Db::exec("UPDATE profiles SET role = 'writer', full_name = ?, avatar_url = ?, avatar_original_url = ? WHERE id = ?", [$fullName, $avatarUrl, $origUrl, $uid]);
            $inv = Db::val('SELECT id FROM invites WHERE token = ?', [$token]);
            Db::exec('INSERT INTO profile_contacts (id, phone, email, first_name, last_name, invite_id) VALUES (?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE phone = VALUES(phone), email = VALUES(email), first_name = VALUES(first_name), last_name = VALUES(last_name), invite_id = VALUES(invite_id)',
                [$uid, $phone, $email, $first, $last, $inv]);
        } catch (\Throwable $e) {
            try { Auth::deleteUser($uid); Storage::remove('avatars', "$uid.$ext"); } catch (\Throwable) {}
            $giveBack();
            throw new ApiError('profile_update_failed', 500, null, 'database error');
        }
        return ['status' => 'created'];
    }
}
