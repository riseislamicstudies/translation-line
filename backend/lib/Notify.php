<?php
declare(strict_types=1);
namespace Rise;

/** معادل notify_user / notify_role / notify_translators + تریگر notify_push (که صف push می‌سازد). */
final class Notify
{
    public static function user(?string $uid, string $type, ?string $doc, array $payload): void
    {
        if (!$uid) return;
        $ids = Db::all('SELECT id FROM profiles WHERE id = ? AND notify = 1', [$uid]);
        self::insertFor(array_column($ids, 'id'), $type, $doc, $payload);
    }

    public static function role(string $role, string $type, ?string $doc, array $payload, ?string $exclude = null): void
    {
        $rows = Db::all('SELECT id FROM profiles WHERE role = ? AND notify = 1' . ($exclude ? ' AND id <> ?' : ''), $exclude ? [$role, $exclude] : [$role]);
        self::insertFor(array_column($rows, 'id'), $type, $doc, $payload);
    }

    public static function translators(string $type, ?string $doc, array $payload, bool $seniorOnly, ?string $exclude = null): void
    {
        $sql = "SELECT p.id FROM profiles p WHERE p.role = 'translator' AND p.notify = 1" . ($exclude ? ' AND p.id <> ?' : '');
        if ($seniorOnly) $sql .= " AND EXISTS (SELECT 1 FROM translator_levels l WHERE l.user_id = p.id AND l.level = 'senior')";
        $rows = Db::all($sql, $exclude ? [$exclude] : []);
        self::insertFor(array_column($rows, 'id'), $type, $doc, $payload);
    }

    /** news_notify(p_count) */
    public static function newsBatch(int $count): void
    {
        if ($count > 0) self::role('news_editor', 'news_batch', null, ['count' => $count]);
    }

    private static function insertFor(array $userIds, string $type, ?string $doc, array $payload): void
    {
        foreach ($userIds as $u) {
            $id = Db::uuid();
            Db::exec('INSERT INTO notifications (id, user_id, type, document_id, payload) VALUES (?, ?, ?, ?, ?)',
                [$id, $u, $type, $doc, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)]);
            // معادل تریگر trg_notify_push: یک کار push در صف
            Jobs::enqueue('push', $id, ['notification_id' => $id]);
        }
    }
}
