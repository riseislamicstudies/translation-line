<?php
declare(strict_types=1);
namespace Rise;

/**
 * ورود با ایمیل/رمز، نشست با توکن تصادفی (فقط هش SHA-256 آن در دیتابیس)،
 * و مدیریت کاربران (جایگزین auth.admin و تابع manage-users).
 * رمزهای bcrypt انتقال‌یافته از Supabase ($2a$/$2b$) با password_verify کار می‌کنند.
 */
final class Auth
{
    private const SESSION_DAYS = 30;

    /** توکن را از هدر بخوان و Ctx را پر کن. $required=false → ناشناس هم قبول. */
    public static function authenticate(bool $required = true): void
    {
        $tok = $_SERVER['HTTP_X_RISE_TOKEN'] ?? '';
        if ($tok === '' && !empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
            $tok = substr($_SERVER['HTTP_AUTHORIZATION'], 7);
        }
        $uid = $tok !== '' ? self::userForToken($tok) : null;
        if ($uid === null && $required) throw new ApiError('invalid_token', 401, '28000');
        Ctx::set($uid);
    }

    public static function userForToken(string $tok): ?string
    {
        if (strlen($tok) < 20 || strlen($tok) > 200) return null;
        $h = hash('sha256', $tok);
        $row = Db::one(
            'SELECT s.user_id, s.expires_at, s.last_seen, u.banned_until
               FROM sessions s JOIN auth_users u ON u.id = s.user_id
              WHERE s.token_hash = ? AND s.expires_at > UTC_TIMESTAMP(3)',
            [$h]
        );
        if (!$row) return null;
        if ($row['banned_until'] !== null && strtotime($row['banned_until'] . ' UTC') > time()) return null;
        // به‌روزرسانی «آخرین بازدید» حداکثر دقیقه‌ای یک‌بار
        if ($row['last_seen'] === null || strtotime($row['last_seen'] . ' UTC') < time() - 60) {
            Db::exec('UPDATE sessions SET last_seen = UTC_TIMESTAMP(3), expires_at = ? WHERE token_hash = ?',
                [gmdate('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400), $h]);
        }
        return $row['user_id'];
    }

    public static function login(string $email, string $password): array
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $email = trim(mb_strtolower($email));
        // محدودیت تلاش: ۱۰ تلاش ناموفق در ۱۰ دقیقه برای هر IP
        Db::exec('DELETE FROM login_attempts WHERE created_at < UTC_TIMESTAMP(3) - INTERVAL 1 DAY');
        $n = (int) Db::val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE', [$ip]);
        if ($n >= 10) throw new ApiError('too_many_attempts', 429, '429');

        $u = Db::one('SELECT id, email, password_hash, banned_until FROM auth_users WHERE email = ?', [$email]);
        // زمان ثابت: حتی اگر کاربر نباشد یک hash بررسی می‌شود
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsalt.............................';
        $ok = password_verify($password, $hash) && $u !== null;
        if (!$ok) {
            Db::exec('INSERT INTO login_attempts (ip, email) VALUES (?, ?)', [$ip, substr($email, 0, 255)]);
            throw new ApiError('Invalid login credentials', 400, 'invalid_credentials');
        }
        if ($u['banned_until'] !== null && strtotime($u['banned_until'] . ' UTC') > time()) {
            throw new ApiError('user_banned', 400, 'user_banned');
        }
        $tok = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::exec('INSERT INTO sessions (token_hash, user_id, expires_at, ua) VALUES (?, ?, ?, ?)',
            [hash('sha256', $tok), $u['id'], gmdate('Y-m-d H:i:s', time() + self::SESSION_DAYS * 86400), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        Db::exec('UPDATE auth_users SET last_login_at = UTC_TIMESTAMP(3) WHERE id = ?', [$u['id']]);
        Db::exec('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP(3)');
        return ['access_token' => $tok, 'expires_in' => self::SESSION_DAYS * 86400, 'user' => ['id' => $u['id'], 'email' => $u['email']]];
    }

    public static function logout(): void
    {
        $tok = $_SERVER['HTTP_X_RISE_TOKEN'] ?? '';
        if ($tok !== '') Db::exec('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $tok)]);
    }

    public static function currentUser(): ?array
    {
        $uid = Ctx::uid();
        return $uid ? Db::one('SELECT id, email FROM auth_users WHERE id = ?', [$uid]) : null;
    }

    // ---------------- مدیریت کاربران (فقط از manage-users / writer-signup) ----------------

    /** معادل auth.admin.createUser + تریگر handle_new_user (ساخت profile و profile_contacts) */
    public static function createUser(string $email, string $password, ?string $fullName = null, ?string $id = null): string
    {
        $email = trim(mb_strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('invalid_email', 400);
        if (mb_strlen($password) < 6) throw new ApiError('weak_password', 400);
        if (Db::val('SELECT 1 FROM auth_users WHERE email = ?', [$email])) throw new ApiError('email_exists', 409);
        $id ??= Db::uuid();
        Db::tx(function () use ($id, $email, $password, $fullName) {
            Db::exec('INSERT INTO auth_users (id, email, password_hash) VALUES (?, ?, ?)', [$id, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 10])]);
            Db::exec('INSERT INTO profiles (id, full_name) VALUES (?, ?)', [$id, $fullName !== null && $fullName !== '' ? $fullName : explode('@', $email)[0]]);
            Db::exec('INSERT INTO profile_contacts (id, email) VALUES (?, ?) ON DUPLICATE KEY UPDATE email = VALUES(email)', [$id, $email]);
        });
        return $id;
    }

    public static function setPassword(string $uid, string $password): void
    {
        if (mb_strlen($password) < 6) throw new ApiError('weak_password', 400);
        Db::exec('UPDATE auth_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), $uid]);
        Db::exec('DELETE FROM sessions WHERE user_id = ?', [$uid]);
    }

    public static function setEmail(string $uid, string $email): void
    {
        $email = trim(mb_strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('invalid_email', 400);
        if (Db::val('SELECT 1 FROM auth_users WHERE email = ? AND id <> ?', [$email, $uid])) throw new ApiError('email_exists', 409);
        Db::tx(function () use ($uid, $email) {
            Db::exec('UPDATE auth_users SET email = ? WHERE id = ?', [$email, $uid]);
            Db::exec('UPDATE profile_contacts SET email = ? WHERE id = ?', [$email, $uid]);
        });
    }

    public static function ban(string $uid, bool $banned): void
    {
        Db::exec('UPDATE auth_users SET banned_until = ? WHERE id = ?', [$banned ? '2999-12-31 00:00:00.000' : null, $uid]);
        if ($banned) Db::exec('DELETE FROM sessions WHERE user_id = ?', [$uid]);
    }

    public static function deleteUser(string $uid): void
    {
        Db::exec('DELETE FROM auth_users WHERE id = ?', [$uid]); // profiles و جدول‌های وابسته با CASCADE
    }
}
