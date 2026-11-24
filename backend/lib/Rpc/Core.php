<?php
declare(strict_types=1);
use Rise\{Access, ApiError, Ctx, Db, Quality, Rpc, Secrets};

// RPCهای پایه: نقش، دسترسی، اسرار، push. (نمونهٔ سبک نوشتن بقیهٔ RPCها)
return [
    'my_role' => fn(array $a) => Ctx::role(),
    'can_see_senior' => fn(array $a) => Access::canSeeSenior(),
    'can_view_document_content' => fn(array $a) => Access::canViewDoc(Rpc::uuid(Rpc::arg($a, 'p_doc'))),
    'can_view_images' => fn(array $a) => Access::canViewImages(Rpc::uuid(Rpc::arg($a, 'p_doc'))),
    'can_attach_images' => fn(array $a) => Access::canAttachImages(Rpc::uuid(Rpc::arg($a, 'p_doc'))),
    'word_edit_distance' => fn(array $a) => Quality::wordEditDistance($a['a'] ?? null, $a['b'] ?? null),

    'pool_count' => function (array $a) {
        $senior = Access::canSeeSenior() ? 1 : 0;
        return (int) Db::val("SELECT COUNT(*) FROM documents WHERE status = 'in_pool' AND (senior_only = 0 OR ? = 1)", [$senior]);
    },

    'has_secret' => function (array $a) {
        Ctx::need('admin');
        return Db::val('SELECT 1 FROM app_secrets WHERE name = ?', [(string) Rpc::arg($a, 'p_name')]) !== null;
    },

    'set_secret' => function (array $a) {
        Ctx::need('admin');
        $name = (string) Rpc::arg($a, 'p_name');
        $ok = in_array($name, ['GEMINI_API_KEY', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'OPENAI_COMPAT_API_KEY', 'WP_APP_PASSWORD', 'GSC_SERVICE_ACCOUNT'], true)
            || preg_match('/^AI_KEY_[a-z0-9]{2,12}$/', $name);
        if (!$ok) throw new ApiError('unknown_secret', 400);
        Secrets::set($name, trim((string) ($a['p_value'] ?? '')), Ctx::uid());
        return null;
    },

    'push_public_key' => fn(array $a) => Db::val("SELECT `value` FROM app_config WHERE `key` = 'vapid_public'"),

    'push_subscribe' => function (array $a) {
        $uid = Ctx::requireUser();
        $ep = (string) Rpc::arg($a, 'p_endpoint');
        if (!preg_match('#^https://#', $ep) || strlen($ep) > 1000) throw new ApiError('bad_endpoint', 400);
        Db::exec('INSERT INTO push_subscriptions (id, user_id, endpoint, p256dh, auth, ua) VALUES (?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), ua = VALUES(ua)',
            [Db::uuid(), $uid, $ep, (string) Rpc::arg($a, 'p_p256dh'), (string) Rpc::arg($a, 'p_auth'), isset($a['p_ua']) ? mb_substr((string) $a['p_ua'], 0, 200) : null]);
        return null;
    },

    'push_unsubscribe' => function (array $a) {
        Db::exec('DELETE FROM push_subscriptions WHERE endpoint_key = MD5(?) AND user_id <=> ?', [(string) Rpc::arg($a, 'p_endpoint'), Ctx::uid()]);
        return null;
    },
];
