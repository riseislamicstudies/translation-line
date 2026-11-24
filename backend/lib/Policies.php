<?php
declare(strict_types=1);
namespace Rise;

/**
 * سیاست‌های RLS سوپابیس، بازنویسی‌شده برای PHP.
 * خروجی هر متد یک شرط SQL است (برای WHERE) یا null = ممنوع.
 * اسم ستون‌ها با `table`.`col` می‌آیند تا در زیرپرس‌وجوها گیج نشوند.
 */
final class Policies
{
    private static function uq(): string { return Access::q((string) Ctx::uid()); }
    private static function role(): string { return Ctx::isService() ? 'admin' : Ctx::role(); }
    private static function in(string ...$r): bool { return Ctx::isService() || in_array(Ctx::role(), $r, true); }

    /** شرط SELECT برای جدول؛ null یعنی هیچ ردیفی دیده نمی‌شود */
    public static function select(string $t): ?string
    {
        $uid = self::uq();
        switch ($t) {
            case 'app_config': case 'profiles': return '1';
            case 'document_events': case 'priority_history': case 'prompts':
                return self::in('admin', 'reviewer', 'supervisor') ? '1' : null;
            case 'document_images': return Access::viewImagesCond('`document_images`.`document_id`');
            case 'documents':
                $r = self::role();
                if (Ctx::isService() || in_array($r, ['admin', 'reviewer', 'supervisor'], true)) return '1';
                if ($r === 'news_editor') return "`documents`.`source_kind` = 'news'";
                if ($r === 'translator') {
                    $senior = Access::canSeeSenior() ? '1' : '0';
                    return "((`documents`.`status` = 'in_pool' AND (`documents`.`senior_only` = 0 OR $senior = 1)) OR `documents`.`claimed_by` = $uid)";
                }
                return null; // نویسنده‌ها مستقیم نمی‌بینند؛ از RPC استفاده می‌کنند
            case 'glossary': return self::in('admin', 'reviewer', 'translator', 'supervisor') ? '1' : null;
            case 'invites': return self::in('admin') ? '1' : null;
            case 'news_items': return self::in('admin', 'news_editor') ? '1' : null;
            case 'news_rules': case 'news_sources': return self::in('admin', 'news_editor') ? '1' : null;
            case 'notifications': return Ctx::isService() ? '1' : "`notifications`.`user_id` = $uid";
            case 'profile_contacts':
                return self::in('admin', 'reviewer') ? '1' : "`profile_contacts`.`id` = $uid";
            case 'segment_comments': return Access::viewDocCond('`segment_comments`.`document_id`');
            case 'segment_revisions': return Access::viewDocCond('`segment_revisions`.`document_id`');
            case 'segments': return Access::viewDocCond('`segments`.`document_id`');
            case 'translator_levels': return self::in('admin') ? '1' : null;
        }
        return null;
    }

    /** شرط USING برای UPDATE/DELETE؛ null = ممنوع */
    public static function modify(string $t, string $op): ?string
    {
        $uid = self::uq();
        $adminOnly = static fn() => self::in('admin') ? '1' : null;
        switch ($t) {
            case 'app_config':
                if (self::in('admin')) return '1';
                if ($op === 'update' && self::in('news_editor')) return "`app_config`.`key` LIKE 'news\\\\_%'";
                return null;
            case 'documents':
                if ($op === 'update') return $adminOnly();
                // delete
                if (self::in('admin')) return '1';
                return "(`documents`.`uploaded_by` = $uid AND `documents`.`status` IN ('content_review','review_rejected'))";
            case 'glossary': case 'prompts': case 'profile_contacts': case 'translator_levels':
                return $adminOnly();
            case 'news_rules': case 'news_sources':
                return self::in('admin', 'news_editor') ? '1' : null;
            case 'notifications':
                return $op === 'update' ? "`notifications`.`user_id` = $uid" : null;
            case 'profiles':
                if ($op === 'delete') return $adminOnly();
                return self::in('admin') ? '1' : "`profiles`.`id` = $uid";
            case 'segment_comments':
                if ($op !== 'update') return null;
                return self::in('admin', 'supervisor') ? '1' : "`segment_comments`.`author_id` = $uid";
            case 'segments':
                return Access::segmentsStaffCond('`segments`.`document_id`') === '0' ? null : Access::segmentsStaffCond('`segments`.`document_id`');
        }
        return null;
    }

    /** ستون‌هایی که غیرمدیر حق تغییرشان را دارد (برای جدول‌هایی که کل ردیف مجاز نیست) */
    public static function updatableCols(string $t): ?array
    {
        if (self::in('admin') && $t !== 'notifications') return null; // همه
        return match ($t) {
            'notifications' => ['read_at'],
            'segment_comments' => ['body', 'resolved'],
            'profiles' => ['full_name', 'specialties', 'notify', 'avatar_url'],
            default => null,
        };
    }

    /** بررسی WITH CHECK برای INSERT یک ردیف (مقادیر پس از اعمال پیش‌فرض‌ها) */
    public static function checkInsert(string $t, array $row): bool
    {
        if (Ctx::isService()) return true;
        $uid = Ctx::uid();
        switch ($t) {
            case 'app_config':
                if (self::in('admin')) return true;
                return self::in('news_editor') && str_starts_with((string) ($row['key'] ?? ''), 'news_');
            case 'documents':
                return self::in('admin', 'reviewer') && ($row['uploaded_by'] ?? null) === $uid;
            case 'glossary': case 'prompts': case 'profile_contacts': case 'translator_levels': case 'profiles':
                return self::in('admin');
            case 'news_rules': case 'news_sources':
                return self::in('admin', 'news_editor');
            case 'segment_comments':
                return ($row['author_id'] ?? null) === $uid && Access::canViewDoc((string) ($row['document_id'] ?? ''));
            case 'segments':
                return (bool) Db::val('SELECT ' . Access::segmentsStaffCond('?'), [(string) ($row['document_id'] ?? '')]);
        }
        return false;
    }
}
