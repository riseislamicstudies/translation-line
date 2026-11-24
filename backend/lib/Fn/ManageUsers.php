<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Auth, Ctx, Db, Schema, Storage};

/**
 * manage-users (فقط ادمین): list | create | set_password | update_email | disable | enable | set_avatar | delete
 * تغییر نقش و نام لازم نیست از اینجا برود؛ ادمین مستقیم profiles را به‌روز می‌کند.
 */
final class ManageUsers
{
    public const AUTH = true;

    private const ROLES = ['none', 'admin', 'reviewer', 'translator', 'supervisor', 'writer', 'journal_writer', 'news_editor'];
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const EMAIL_RE = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    /** String(x ?? '') مثل جاوااسکریپت */
    private static function str(mixed $v): string { return is_scalar($v) ? (string) $v : ''; }

    private static function err(string $e, int $status = 400, ?string $detail = null): ApiError
    {
        return new ApiError($e, $status, null, $detail);
    }

    public static function handle(array $body, array $ctx): array
    {
        $uid = Ctx::uid();
        if ($uid === null) throw self::err('missing_authorization', 401);
        if (Ctx::role() !== 'admin') throw self::err('forbidden', 403);

        $action = $body['action'] ?? null;

        if ($action === 'list') {
            $rows = Db::all('SELECT id, email, last_login_at, created_at, banned_until FROM auth_users ORDER BY created_at LIMIT 200');
            return ['users' => array_map(static fn(array $u) => [
                'id' => $u['id'],
                'email' => $u['email'],
                'last_sign_in_at' => $u['last_login_at'] === null ? null : Schema::iso($u['last_login_at']),
                'created_at' => Schema::iso($u['created_at']),
                'banned_until' => $u['banned_until'] === null ? null : Schema::iso($u['banned_until']),
            ], $rows)];
        }

        if ($action === 'create') {
            $email = mb_strtolower(trim(self::str($body['email'] ?? '')));
            $password = self::str($body['password'] ?? '');
            $fullName = trim(self::str($body['full_name'] ?? ''));
            $role = self::str($body['role'] ?? 'none');
            if (!preg_match(self::EMAIL_RE, $email)) throw self::err('invalid_email');
            if (mb_strlen($password) < 8) throw self::err('password_too_short', 400, 'at least 8 characters');
            if (!in_array($role, self::ROLES, true)) throw self::err('invalid_role');
            $name = $fullName !== '' ? $fullName : explode('@', $email)[0];
            try {
                $id = Auth::createUser($email, $password, $name);
            } catch (ApiError $e) {
                throw self::err('create_failed', 400, $e->getMessage());
            } catch (\PDOException $e) {
                throw self::err('create_failed', 400, 'database error');
            }
            try {
                Db::exec('UPDATE profiles SET role = ?, full_name = ? WHERE id = ?', [$role, $name, $id]);
            } catch (\PDOException $e) {
                throw new ApiError('profile_update_failed', 500, null, 'database error');
            }
            return ['status' => 'created', 'user_id' => $id];
        }

        if ($action === 'set_password') {
            $userId = self::str($body['user_id'] ?? '');
            $password = self::str($body['password'] ?? '');
            if (!preg_match(self::UUID_RE, $userId)) throw self::err('invalid_user_id');
            if (mb_strlen($password) < 8) throw self::err('password_too_short', 400, 'at least 8 characters');
            $userId = strtolower($userId);
            if (!Db::val('SELECT 1 FROM auth_users WHERE id = ?', [$userId])) throw self::err('update_failed', 400, 'User not found');
            Auth::setPassword($userId, $password);
            return ['status' => 'updated'];
        }

        if ($action === 'set_avatar') {
            $userId = self::str($body['user_id'] ?? '');
            if (!preg_match(self::UUID_RE, $userId)) throw self::err('invalid_user_id');
            return self::applyAvatar(strtolower($userId), self::str($body['avatar'] ?? ''), self::str($body['original'] ?? ''));
        }

        if (in_array($action, ['update_email', 'disable', 'enable', 'delete'], true)) {
            $userId = self::str($body['user_id'] ?? '');
            if (!preg_match(self::UUID_RE, $userId)) throw self::err('invalid_user_id');
            $userId = strtolower($userId);
            $self = $userId === $uid;
            $exists = Db::val('SELECT 1 FROM auth_users WHERE id = ?', [$userId]) !== null;

            if ($action === 'update_email') {
                $email = mb_strtolower(trim(self::str($body['email'] ?? '')));
                if (!preg_match(self::EMAIL_RE, $email)) throw self::err('invalid_email');
                if (!$exists) throw self::err('update_failed', 400, 'User not found');
                try { Auth::setEmail($userId, $email); }
                catch (ApiError $e) { throw self::err('update_failed', 400, $e->getMessage()); }
                return ['status' => 'updated'];
            }
            if ($action === 'enable') {
                if (!$exists) throw self::err('update_failed', 400, 'User not found');
                Auth::ban($userId, false);
                return ['status' => 'enabled'];
            }
            // disable و delete: هرگز روی خود شخص و هرگز روی آخرین ادمین
            if ($self) throw self::err('cannot_on_self', 400, 'you cannot disable or delete your own account');
            $target = Db::val('SELECT role FROM profiles WHERE id = ?', [$userId]);
            if ($target === 'admin' && (int) Db::val("SELECT COUNT(*) FROM profiles WHERE role = 'admin'") <= 1) {
                throw self::err('last_admin', 400, 'this is the only admin');
            }
            if ($action === 'disable') {
                if (!$exists) throw self::err('update_failed', 400, 'User not found');
                Auth::ban($userId, true);
                Db::exec("UPDATE profiles SET role = 'none' WHERE id = ?", [$userId]);
                return ['status' => 'disabled'];
            }
            // delete
            if (!$exists) throw self::err('delete_failed', 400, 'User not found');
            try {
                Auth::deleteUser($userId);
            } catch (\PDOException $e) {
                $drv = (int) (($e->errorInfo ?? [])[1] ?? 0);
                $hist = in_array($drv, [1451, 1452], true) || preg_match('/foreign key|violates|database error/i', $e->getMessage()) === 1;
                throw self::err($hist ? 'has_history' : 'delete_failed', $hist ? 409 : 400, $e->getMessage());
            }
            foreach (['jpg', 'png'] as $x) { try { Storage::remove('avatars', "$userId.$x"); } catch (\Throwable) {} }
            return ['status' => 'deleted'];
        }

        throw self::err('unknown_action');
    }

    private const ORIG_EXT = ['jpg', 'png', 'webp'];

    /**
     * عکس پروفایل یک کاربر را بگذار (avatar = بندانگشتی، original = فایل اصلی بدون کوچک‌سازی) یا اگر avatar خالی بود پاک کن.
     * هم ادمین (set_avatar) و هم خود کاربر (تابع set-avatar) از همین استفاده می‌کنند.
     */
    public static function applyAvatar(string $userId, string $av, string $orig = ''): array
    {
        if (!Db::val('SELECT 1 FROM profiles WHERE id = ?', [$userId])) throw self::err('invalid_user_id');
        $dropOrig = static function () use ($userId): void { foreach (self::ORIG_EXT as $x) Storage::remove('avatars', "$userId.orig.$x"); };
        if ($av === '') {
            Db::exec('UPDATE profiles SET avatar_url = NULL, avatar_original_url = NULL WHERE id = ?', [$userId]);
            foreach (['jpg', 'png'] as $x) Storage::remove('avatars', "$userId.$x");
            $dropOrig();
            return ['status' => 'removed'];
        }
        [$bytes, $ext] = self::decodeAvatar($av);
        $origBytes = null; $origExt = null;
        if ($orig !== '') [$origBytes, $origExt] = self::decodeOriginal($orig);
        try { Storage::put('avatars', "$userId.$ext", $bytes, true); }
        catch (\Throwable $e) { throw self::err('upload_failed', 500, $e->getMessage()); }
        Storage::remove('avatars', $userId . '.' . ($ext === 'png' ? 'jpg' : 'png'));
        $url = self::avatarUrl("$userId.$ext");
        $origUrl = null;
        $dropOrig();
        if ($origBytes !== null) {
            try { Storage::put('avatars', "$userId.orig.$origExt", $origBytes, true); $origUrl = self::avatarUrl("$userId.orig.$origExt"); }
            catch (\Throwable) { $origUrl = null; /* نسخهٔ اصلی ذخیره نشد؛ بندانگشتی ذخیره شده است */ }
        }
        Db::exec('UPDATE profiles SET avatar_url = ?, avatar_original_url = ? WHERE id = ?', [$url, $origUrl, $userId]);
        return ['status' => 'updated', 'avatar_url' => $url, 'avatar_original_url' => $origUrl];
    }

    /** نسخهٔ اصلی عکس (تا حدود ۸ مگابایت): jpeg/png/webp؛ نوع از روی محتوا تشخیص داده می‌شود نه از ادعای مرورگر. @return array{0:string,1:string} */
    public static function decodeOriginal(string $av): array
    {
        if (strlen($av) > 11_500_000 || !preg_match('#^data:image/[a-z+.-]+;base64,([A-Za-z0-9+/=]+)$#', $av, $m)) throw self::err('invalid_avatar');
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || strlen($bytes) < 100 || strlen($bytes) > 8_400_000) throw self::err('invalid_avatar');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if ($ext === null || @getimagesizefromstring($bytes) === false) throw self::err('invalid_avatar');
        return [$bytes, $ext];
    }

    /** @return array{0:string,1:string} [bytes, ext] */
    public static function decodeAvatar(string $av): array
    {
        if (strlen($av) > 600_000 || !preg_match('#^data:(image/(?:jpeg|png));base64,([A-Za-z0-9+/=]+)$#', $av, $m)) throw self::err('invalid_avatar');
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') throw self::err('invalid_avatar');
        return [$bytes, $m[1] === 'image/png' ? 'png' : 'jpg'];
    }

    /** آدرس عمومی عکس پروفایل (باکت avatars عمومی است؛ api/file.php بدون امضا می‌دهد) */
    public static function avatarUrl(string $path): string
    {
        return trim((string) \Rise\Config::get('api_prefix', 'backend/api'), '/') . '/file.php?b=avatars&p=' . rawurlencode($path) . '&v=' . (int) (microtime(true) * 1000);
    }
}
