<?php
declare(strict_types=1);
namespace Rise;

/** متادیتای جدول‌ها: تبدیل نوع‌ها بین MariaDB و JSON (bool/int/json/زمان ISO). */
final class Schema
{
    private static ?array $m = null;

    private static function meta(): array
    {
        return self::$m ??= require RISE_LIB . '/schema_meta.php';
    }

    /** جدول‌هایی که از طریق API عمومی (rest.php) قابل دسترس‌اند. */
    public const EXPOSED = [
        'app_config', 'document_events', 'document_images', 'documents', 'glossary', 'invites', 'news_items',
        'news_rules', 'news_sources', 'notifications', 'priority_history', 'profile_contacts', 'profiles',
        'prompts', 'segment_comments', 'segment_revisions', 'segments', 'translator_levels',
    ];

    public static function has(string $t): bool { return isset(self::meta()[$t]); }
    public static function exposed(string $t): bool { return in_array($t, self::EXPOSED, true) && self::has($t); }
    public static function cols(string $t): array { return self::meta()[$t]['cols'] ?? []; }
    public static function visibleCols(string $t): array
    {
        $m = self::meta()[$t] ?? null;
        return $m ? array_values(array_diff(array_keys($m['cols']), $m['hidden'])) : [];
    }
    public static function hasCol(string $t, string $c): bool { return isset(self::meta()[$t]['cols'][$c]); }
    public static function isUuidPk(string $t): bool { return (bool) (self::meta()[$t]['uuid_pk'] ?? false); }
    public static function pk(string $t): array { return self::meta()[$t]['pk'] ?? []; }

    /** ردیف دیتابیس → ردیف JSON (حذف ستون‌های مخفی، bool/int/json/ISO) */
    public static function castRow(string $t, array $row): array
    {
        $m = self::meta()[$t] ?? null;
        if (!$m) return $row;
        $out = [];
        foreach ($row as $c => $v) {
            if (in_array($c, $m['hidden'], true)) continue;
            $k = $m['cols'][$c] ?? 'str';
            if ($v === null) { $out[$c] = null; continue; }
            $out[$c] = match ($k) {
                'bool' => (bool) $v,
                'int' => (int) $v,
                'json' => is_string($v) ? json_decode($v, true) : $v,
                'ts' => self::iso((string) $v),
                default => $v,
            };
        }
        return $out;
    }

    public static function castRows(string $t, array $rows): array
    {
        return array_map(fn($r) => self::castRow($t, $r), $rows);
    }

    /** مقدار ورودی JSON → مقدار مناسب ستون */
    public static function toDb(string $t, string $c, mixed $v): mixed
    {
        if ($v === null) return null;
        $k = self::meta()[$t]['cols'][$c] ?? 'str';
        return match ($k) {
            'bool' => ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0,
            'int' => is_numeric($v) ? (int) $v : throw new ApiError("invalid input for $c", 400, '22P02'),
            'json' => is_string($v) && ($d = json_decode($v, true)) !== null ? json_encode($d, JSON_UNESCAPED_UNICODE) : json_encode($v, JSON_UNESCAPED_UNICODE),
            'ts' => self::fromIso($v),
            default => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v,
        };
    }

    public static function iso(string $dt): string
    {
        // 'YYYY-MM-DD HH:MM:SS(.mmm)' (UTC) → 'YYYY-MM-DDTHH:MM:SS.mmmZ'
        $ms = '000';
        if (preg_match('/\.(\d{1,6})$/', $dt, $m)) { $ms = substr(str_pad($m[1], 3, '0'), 0, 3); $dt = substr($dt, 0, -strlen($m[0])); }
        return str_replace(' ', 'T', $dt) . '.' . $ms . 'Z';
    }

    public static function fromIso(mixed $v): string
    {
        $s = (string) $v;
        $ts = strtotime($s);
        if ($ts === false) throw new ApiError('invalid input syntax for type timestamp', 400, '22007');
        $ms = '000';
        if (preg_match('/\.(\d{1,6})/', $s, $m)) $ms = substr(str_pad($m[1], 3, '0'), 0, 3);
        return gmdate('Y-m-d H:i:s', $ts) . '.' . $ms;
    }
}
