<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{Auth, Ctx, Db, Http, Schema};

// POST {action: login|logout|session}
Http::run(function (array $b) {
    switch ($b['action'] ?? '') {
        case 'login':
            return Auth::login((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
        case 'logout':
            Auth::logout();
            return null;
        case 'session': // getSession: توکن معتبر است؟
            $u = Auth::currentUser();
            return $u ? ['user' => $u] : null;
        case 'profile': // پروفایل خودِ کاربر (فرانت بعد از ورود می‌خواند)
            $uid = Ctx::requireUser();
            $p = Db::one('SELECT * FROM profiles WHERE id = ?', [$uid]);
            return $p ? Schema::castRow('profiles', $p) : null;
    }
    throw new Rise\ApiError('bad_action', 400);
}, false);
