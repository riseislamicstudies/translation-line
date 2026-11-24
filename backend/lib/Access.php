<?php
declare(strict_types=1);
namespace Rise;

/**
 * توابع کمکی دسترسی (معادل can_see_senior, can_view_document_content, can_view_images, can_attach_images).
 * نسخه‌های «Cond» یک تکه SQL برمی‌گردانند که در WHERE گذاشته می‌شود (معادل RLS).
 */
final class Access
{
    public static function q(string $v): string { return Db::pdo()->quote($v); }

    public static function canSeeSenior(): bool
    {
        if (Ctx::isService()) return true;
        if (Ctx::role() === 'admin') return true;
        return (bool) Db::val("SELECT 1 FROM translator_levels WHERE user_id = ? AND level = 'senior'", [Ctx::uid()]);
    }

    /** can_view_document_content(<col>) */
    public static function viewDocCond(string $col): string
    {
        if (Ctx::isService()) return '1';
        $r = Ctx::role();
        $c = match (true) {
            in_array($r, ['admin', 'reviewer', 'supervisor'], true) => '1',
            $r === 'news_editor' => "d.source_kind = 'news'",
            $r === 'translator' => 'd.claimed_by = ' . self::q((string) Ctx::uid()),
            default => '0',
        };
        return "EXISTS (SELECT 1 FROM documents d WHERE d.id = $col AND $c)";
    }

    public static function canViewDoc(string $docId): bool
    {
        return (bool) Db::val('SELECT ' . self::viewDocCond('?'), [$docId]);
    }

    /** can_view_images(<col>) */
    public static function viewImagesCond(string $col): string
    {
        if (Ctx::isService()) return '1';
        $r = Ctx::role();
        $c = match (true) {
            in_array($r, ['admin', 'reviewer', 'supervisor'], true) => '1',
            $r === 'news_editor' => "d.source_kind = 'news'",
            in_array($r, ['writer', 'journal_writer'], true) => 'd.uploaded_by = ' . self::q((string) Ctx::uid()),
            $r === 'translator' => 'd.claimed_by = ' . self::q((string) Ctx::uid()),   // مترجم، عکس‌های فایل خودش را فقط برای دیدن جانمایی می‌بیند
            default => '0',
        };
        return "EXISTS (SELECT 1 FROM documents d WHERE d.id = $col AND $c)";
    }

    public static function canViewImages(string $docId): bool
    {
        return (bool) Db::val('SELECT ' . self::viewImagesCond('?'), [$docId]);
    }

    /** can_attach_images(p_doc) */
    public static function canAttachImages(string $docId): bool
    {
        if (Ctx::isService()) return true;
        $d = Db::one('SELECT status, source_kind, uploaded_by FROM documents WHERE id = ?', [$docId]);
        if (!$d) return false;
        $r = Ctx::role(); $u = Ctx::uid();
        return (in_array($r, ['admin', 'reviewer', 'supervisor'], true) && $d['status'] !== 'published')
            || ($r === 'news_editor' && $d['source_kind'] === 'news' && in_array($d['status'], ['news_edit', 'content_review'], true))
            || (in_array($r, ['writer', 'journal_writer'], true) && $d['uploaded_by'] === $u && in_array($d['status'], ['content_review', 'review_rejected'], true));
    }

    /** سیاست segments_staff_write برای document_id داده‌شده */
    public static function segmentsStaffCond(string $col): string
    {
        if (Ctx::isService()) return '1';
        $r = Ctx::role();
        $parts = [];
        if (in_array($r, ['admin', 'reviewer'], true)) $parts[] = "d.status IN ('content_review','review_rejected')";
        if (in_array($r, ['admin', 'news_editor'], true)) $parts[] = "(d.status = 'news_edit' AND d.source_kind = 'news')";
        if (!$parts) return '0';
        return "EXISTS (SELECT 1 FROM documents d WHERE d.id = $col AND (" . implode(' OR ', $parts) . '))';
    }
}
