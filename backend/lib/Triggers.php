<?php
declare(strict_types=1);
namespace Rise;

/**
 * معادل تریگرهای Postgres. هر کدی که documents.status یا segment_comments را تغییر می‌دهد
 * باید از این‌ها استفاده کند (موتور rest.php و RPCها خودشان صدا می‌زنند).
 */
final class Triggers
{
    /** تغییر فیلدهای یک سند + updated_at (documents_touch) + تریگر تغییر وضعیت. */
    public static function updateDocument(string $id, array $fields): void
    {
        $old = Db::one('SELECT * FROM documents WHERE id = ? FOR UPDATE', [$id]);
        if (!$old) return;
        $set = [];
        foreach ($fields as $c => $v) $set[$c] = Schema::hasCol('documents', $c) ? Schema::toDb('documents', $c, $v) : $v;
        $set['updated_at'] = Db::now();
        Db::update('documents', $set, 'id = ?', [$id]);
        $new = Db::one('SELECT * FROM documents WHERE id = ?', [$id]);
        self::onDocumentStatusChange($old, $new);
    }

    /** on_document_status_change */
    public static function onDocumentStatusChange(array $old, array $new): void
    {
        if ($new['status'] === $old['status']) return;
        $id = $new['id'];
        Db::exec('INSERT INTO document_events (document_id, actor_id, from_status, to_status) VALUES (?, ?, ?, ?)',
            [$id, Ctx::uid(), $old['status'], $new['status']]);
        $remaining = (int) Db::val("SELECT COUNT(*) FROM documents WHERE status = 'in_pool' AND (senior_only = 0 OR ? = 1)", [(int) $new['senior_only']]);
        $title = $new['title'];
        $n = $new['status']; $o = $old['status'];
        if ($n === 'news_edit') {
            Notify::role('news_editor', 'returned_to_editor', $id, ['title' => $title, 'note' => $new['review_note']]);
        } elseif ($n === 'in_pool') {
            Notify::translators('new_in_pool', $id, ['title' => $title, 'remaining' => $remaining, 'senior' => (bool) $new['senior_only']], (bool) $new['senior_only']);
        } elseif ($o === 'in_pool' && $n === 'in_progress') {
            $by = Db::val('SELECT full_name FROM profiles WHERE id = ?', [$new['claimed_by']]);
            Notify::translators('claimed', $id, ['title' => $title, 'remaining' => $remaining, 'by' => $by], (bool) $new['senior_only'], $new['claimed_by']);
        } elseif ($n === 'content_review') {
            Notify::role('reviewer', 'needs_review', $id, ['title' => $title]);
        } elseif ($n === 'review_rejected') {
            if ($new['uploaded_by'] !== null && $new['uploaded_by'] !== Ctx::uid()) {
                Notify::user($new['uploaded_by'], 'returned_to_writer', $id, ['title' => $title, 'note' => $new['review_note']]);
            }
        } elseif ($n === 'awaiting_qa') {
            Notify::role('supervisor', 'awaiting_qa', $id, ['title' => $title]);
        } elseif ($n === 'changes_requested') {
            Notify::user($new['claimed_by'], 'changes_requested', $id, ['title' => $title, 'note' => $new['qa_note']]);
        } elseif ($n === 'approved' && $o !== 'published') {
            Notify::user($new['claimed_by'], 'approved', $id, ['title' => $title]);
        } elseif ($n === 'translation_failed') {
            Notify::role('admin', 'ai_failed', $id, ['title' => $title, 'note' => $new['ai_error']]);
        }
    }

    /** on_comment_insert (فقط برای نظر راهبر دسته) */
    public static function onCommentInsert(array $c): void
    {
        if (!(int) $c['batch_lead']) return;
        $d = Db::one('SELECT id, title, claimed_by, reviewed_by, supervisor_id, uploaded_by FROM documents WHERE id = ?', [$c['document_id']]);
        if (!$d) return;
        $author = Db::val('SELECT full_name FROM profiles WHERE id = ?', [$c['author_id']]);
        $seen = [];
        foreach ([$d['claimed_by'], $d['reviewed_by'], $d['supervisor_id'], $d['uploaded_by']] as $u) {
            if (!$u || $u === $c['author_id'] || isset($seen[$u])) continue;
            $seen[$u] = true;
            $role = Db::val('SELECT role FROM profiles WHERE id = ?', [$u]);
            if ($role === null || $role === 'writer') continue;
            Notify::user($u, 'comment', $d['id'], ['title' => $d['title'], 'by' => $author, 'note' => mb_substr((string) $c['body'], 0, 140)]);
        }
    }
}
