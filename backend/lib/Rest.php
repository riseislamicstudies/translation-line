<?php
declare(strict_types=1);
namespace Rise;

/**
 * موتور CRUD عمومی (جایگزین PostgREST). درخواست از لایهٔ فرانت (rise-client.js):
 * { table, op: select|insert|update|upsert|delete, columns, filters:[[op,col,val]...], or, order:[[col,asc]...],
 *   limit, offset, single: null|'single'|'maybe', values, onConflict, returning }
 * همهٔ مجوزها از Policies می‌آید (معادل RLS).
 */
final class Rest
{
    public static function handle(array $req): mixed
    {
        $t = (string) ($req['table'] ?? '');
        $op = (string) ($req['op'] ?? 'select');
        Ctx::requireUser();

        if (in_array($t, ['document_quality', 'translator_quality'], true)) return self::virtual($t, $req);
        if (!Schema::exposed($t)) throw new ApiError("relation \"$t\" does not exist", 404, '42P01');

        return match ($op) {
            'select' => self::finish($t, self::select($t, $req), $req['single'] ?? null),
            'insert' => self::insert($t, $req),
            'upsert' => self::upsert($t, $req),
            'update' => self::update($t, $req),
            'delete' => self::delete($t, $req),
            default => throw new ApiError('bad_op', 400),
        };
    }

    // ---------------------------------------------------------------- select
    private static function select(string $t, array $req): array
    {
        $cond = Policies::select($t);
        if ($cond === null) return [];
        [$cols, $hidden] = self::columns($t, $req['columns'] ?? '*');
        [$where, $params] = self::where($t, $req);
        $sql = "SELECT $cols FROM `$t` WHERE ($cond)" . $where . self::orderBy($t, $req['order'] ?? []);
        $limit = isset($req['limit']) ? max(0, (int) $req['limit']) : 5000;
        $sql .= ' LIMIT ' . min($limit, 5000);
        if (!empty($req['offset'])) $sql .= ' OFFSET ' . max(0, (int) $req['offset']);
        return Schema::castRows($t, Db::all($sql, $params));
    }

    private static function columns(string $t, string $spec): array
    {
        $vis = Schema::visibleCols($t);
        if (trim($spec) === '*' || trim($spec) === '') return ['`' . implode('`,`', $vis) . '`', []];
        $out = [];
        foreach (explode(',', $spec) as $c) {
            $c = trim($c);
            if (!in_array($c, $vis, true)) throw new ApiError("column $t.$c does not exist", 400, '42703');
            $out[] = '`' . $c . '`';
        }
        return [implode(',', $out), []];
    }

    private static function finish(string $t, array $rows, ?string $single): mixed
    {
        if ($single === null) return $rows;
        if (count($rows) === 1) return $rows[0];
        if ($single === 'maybe' && count($rows) === 0) return null;
        throw new ApiError('JSON object requested, multiple (or no) rows returned', 406, 'PGRST116');
    }

    // ---------------------------------------------------------------- filters
    private static function where(string $t, array $req): array
    {
        $sql = ''; $params = [];
        foreach ($req['filters'] ?? [] as $f) {
            [$s, $p] = self::cond($t, (string) $f[0], (string) $f[1], $f[2] ?? null);
            $sql .= " AND ($s)"; $params = array_merge($params, $p);
        }
        if (!empty($req['or'])) {
            $parts = []; $op = [];
            foreach (self::splitTop((string) $req['or']) as $piece) {
                if (!preg_match('/^([a-z_]+)\.([a-z]+)\.(.*)$/s', $piece, $m)) throw new ApiError('bad or filter', 400, '22023');
                $val = $m[3];
                if ($m[2] === 'is') $val = $val === 'null' ? null : ($val === 'true');
                elseif ($m[2] === 'in') throw new ApiError('or(in) is not supported', 400);
                [$s, $p] = self::cond($t, $m[2], $m[1], $val);
                $parts[] = "($s)"; $op = array_merge($op, $p);
            }
            if ($parts) { $sql .= ' AND (' . implode(' OR ', $parts) . ')'; $params = array_merge($params, $op); }
        }
        return [$sql, $params];
    }

    private static function splitTop(string $s): array
    {
        $out = []; $depth = 0; $cur = '';
        foreach (str_split($s) as $ch) {
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        if ($cur !== '') $out[] = $cur;
        return $out;
    }

    private static function cond(string $t, string $op, string $col, mixed $val): array
    {
        if (!Schema::hasCol($t, $col) || !in_array($col, Schema::visibleCols($t), true)) throw new ApiError("column $t.$col does not exist", 400, '42703');
        $c = "`$t`.`$col`";
        $conv = static fn($v) => Schema::toDb($t, $col, $v);
        switch ($op) {
            case 'eq':  return $val === null ? ["$c IS NULL", []] : ["$c = ?", [$conv($val)]];
            case 'neq': return $val === null ? ["$c IS NOT NULL", []] : ["($c <> ? OR $c IS NULL)", [$conv($val)]];
            case 'gt':  return ["$c > ?", [$conv($val)]];
            case 'gte': return ["$c >= ?", [$conv($val)]];
            case 'lt':  return ["$c < ?", [$conv($val)]];
            case 'lte': return ["$c <= ?", [$conv($val)]];
            case 'like': case 'ilike': return ["$c LIKE ?", [(string) $val]];
            case 'is':
                if ($val === null) return ["$c IS NULL", []];
                return [$val ? "$c = 1" : "$c = 0", []];
            case 'in':
                $vals = array_map($conv, is_array($val) ? $val : []);
                return $vals ? ["$c IN " . Db::in($vals), $vals] : ['0', []];
        }
        throw new ApiError("unsupported filter $op", 400, '22023');
    }

    private static function orderBy(string $t, array $order): string
    {
        $parts = [];
        foreach ($order as $o) {
            $col = (string) $o[0]; $asc = $o[1] ?? true;
            if (!in_array($col, Schema::visibleCols($t), true)) throw new ApiError("column $t.$col does not exist", 400, '42703');
            // رفتار Postgres: ASC → NULL آخر؛ DESC → NULL اول
            $parts[] = $asc ? "(`$t`.`$col` IS NULL), `$t`.`$col` ASC" : "(`$t`.`$col` IS NOT NULL), `$t`.`$col` DESC";
        }
        return $parts ? ' ORDER BY ' . implode(', ', $parts) : '';
    }

    // ---------------------------------------------------------------- insert
    private static function rowsOf(array $req): array
    {
        $v = $req['values'] ?? [];
        if (!is_array($v)) throw new ApiError('bad values', 400);
        return array_is_list($v) ? $v : [$v];
    }

    private static function prepareRow(string $t, array $r): array
    {
        foreach ($r as $c => $_) {
            if (!Schema::hasCol($t, $c) || !in_array($c, Schema::visibleCols($t), true)) throw new ApiError("Could not find the '$c' column of '$t'", 400, 'PGRST204');
        }
        // پیش‌فرض‌های وابسته به کاربر (auth.uid())
        $uid = Ctx::uid();
        if (Schema::isUuidPk($t) && empty($r['id'])) $r['id'] = Db::uuid();
        if ($t === 'documents' && !array_key_exists('uploaded_by', $r)) $r['uploaded_by'] = $uid;
        if ($t === 'segment_comments' && !array_key_exists('author_id', $r)) $r['author_id'] = $uid;
        if ($t === 'invites' && !array_key_exists('created_by', $r)) $r['created_by'] = $uid;
        if ($t === 'glossary') {
            if (isset($r['source_term'])) $r['source_term'] = trim((string) $r['source_term']);
            if (isset($r['target_term'])) $r['target_term'] = trim((string) $r['target_term']);
        }
        return $r;
    }

    private static function insert(string $t, array $req): mixed
    {
        $ids = [];
        Db::tx(function () use ($t, $req, &$ids) {
            foreach (self::rowsOf($req) as $r) $ids[] = self::insertOne($t, $r);
        });
        return self::returning($t, $ids, $req);
    }

    private static function insertOne(string $t, array $r): array
    {
        $r = self::prepareRow($t, $r);
        if (!Policies::checkInsert($t, $r)) throw new ApiError('new row violates row-level security policy for table "' . $t . '"', 403, '42501');
        Db::insert($t, $r);
        $pk = Schema::pk($t);
        $key = [];
        foreach ($pk as $c) $key[$c] = $c === 'id' && !Schema::isUuidPk($t) ? Db::lastId() : ($r[$c] ?? null);
        if ($t === 'segment_comments') Triggers::onCommentInsert(Db::one('SELECT * FROM segment_comments WHERE id = ?', [$r['id']]));
        return $key;
    }

    private static function upsert(string $t, array $req): mixed
    {
        $ids = [];
        $conflict = !empty($req['onConflict']) ? array_map('trim', explode(',', (string) $req['onConflict'])) : Schema::pk($t);
        Db::tx(function () use ($t, $req, $conflict, &$ids) {
            foreach (self::rowsOf($req) as $r) {
                $r = self::prepareRow($t, $r);
                $w = []; $p = [];
                foreach ($conflict as $c) { $w[] = "`$c` = ?"; $p[] = Schema::toDb($t, $c, $r[$c] ?? null); }
                $exists = Db::one("SELECT 1 FROM `$t` WHERE " . implode(' AND ', $w), $p);
                if (!$exists) { $ids[] = self::insertOne($t, $r); continue; }
                $set = array_diff_key($r, array_flip($conflict));
                $filters = array_map(fn($c) => ['eq', $c, $r[$c] ?? null], $conflict);
                self::updateRows($t, $set, ['filters' => $filters], true);
                $key = []; foreach (Schema::pk($t) as $c) $key[$c] = $r[$c] ?? null;
                $ids[] = $key;
            }
        });
        return self::returning($t, $ids, $req);
    }

    // ---------------------------------------------------------------- update
    private static function update(string $t, array $req): mixed
    {
        $ids = [];
        Db::tx(function () use ($t, $req, &$ids) {
            $ids = self::updateRows($t, (array) ($req['values'] ?? []), $req, false);
        });
        return self::returning($t, $ids, $req);
    }

    /** @return array<int,array> کلیدهای اصلی ردیف‌های تغییرکرده */
    private static function updateRows(string $t, array $set, array $req, bool $fromUpsert): array
    {
        $using = Policies::modify($t, 'update');
        if ($using === null) throw new ApiError('permission denied for table ' . $t, 403, '42501');
        foreach ($set as $c => $_) {
            if (!Schema::hasCol($t, $c) || !in_array($c, Schema::visibleCols($t), true)) throw new ApiError("Could not find the '$c' column of '$t'", 400, 'PGRST204');
        }
        if (!$set) return [];
        // محدودیت ستون‌ها و تریگرهای محافظ
        if ($t === 'profiles' && array_key_exists('role', $set) && !Ctx::is('admin')) {
            $cur = Db::val('SELECT role FROM profiles WHERE id = ?', [Ctx::uid()]);
            if ($set['role'] !== $cur) throw new ApiError('only_admin_can_change_roles', 403, '42501');
            unset($set['role']);
        }
        if ($allowed = Policies::updatableCols($t)) {
            foreach ($set as $c => $_) if (!in_array($c, $allowed, true)) throw new ApiError("permission denied for column $c of $t", 403, '42501');
        }
        [$where, $wp] = self::where($t, $req);
        $pk = Schema::pk($t);
        $cols = '`' . implode('`,`', $pk) . '`' . ($t === 'documents' ? ',`status`' : '') . ($t === 'segments' ? ',`ai_text`' : '');
        $rows = Db::all("SELECT * FROM `$t` WHERE ($using)" . $where . ' FOR UPDATE', $wp);
        if (!$rows) {
            if ($fromUpsert) throw new ApiError('new row violates row-level security policy for table "' . $t . '"', 403, '42501');
            return [];
        }
        // lock_ai_text: متن ماشینی تغییرناپذیر است
        if ($t === 'segments' && array_key_exists('ai_text', $set)) {
            foreach ($rows as $o) if ($o['ai_text'] !== null && $set['ai_text'] !== $o['ai_text']) throw new ApiError('ai_text_is_immutable', 403, '42501');
        }
        // WITH CHECK ردیف جدید: برای سیاست‌های نقش‌محور، همان USING کافی است
        if ($t === 'glossary') {
            if (isset($set['source_term'])) $set['source_term'] = trim((string) $set['source_term']);
            if (isset($set['target_term'])) $set['target_term'] = trim((string) $set['target_term']);
        }
        $dbSet = [];
        foreach ($set as $c => $v) $dbSet[$c] = Schema::toDb($t, $c, $v);
        if (Schema::hasCol($t, 'updated_at') && !array_key_exists('updated_at', $set)) $dbSet['updated_at'] = Db::now();
        $keys = [];
        foreach ($rows as $old) {
            $w = []; $p = []; $k = [];
            foreach ($pk as $c) { $w[] = "`$c` = ?"; $p[] = $old[$c]; $k[$c] = $old[$c]; }
            Db::update($t, $dbSet, implode(' AND ', $w), $p);
            $keys[] = $k;
            if ($t === 'documents') {
                $new = Db::one('SELECT * FROM documents WHERE id = ?', [$old['id']]);
                Triggers::onDocumentStatusChange($old, $new);
            }
        }
        return $keys;
    }

    // ---------------------------------------------------------------- delete
    private static function delete(string $t, array $req): mixed
    {
        $using = Policies::modify($t, 'delete');
        if ($using === null) throw new ApiError('permission denied for table ' . $t, 403, '42501');
        [$where, $wp] = self::where($t, $req);
        Db::tx(function () use ($t, $using, $where, $wp) {
            Db::exec("DELETE FROM `$t` WHERE ($using)" . $where, $wp);
        });
        return null;
    }

    // ---------------------------------------------------------------- returning
    private static function returning(string $t, array $keys, array $req): mixed
    {
        $spec = $req['returning'] ?? null;
        if ($spec === null || $spec === false) return null;
        if (!$keys) return ($req['single'] ?? null) ? null : [];
        $rows = [];
        [$cols] = self::columns($t, is_string($spec) ? $spec : '*');
        foreach ($keys as $k) {
            $w = []; $p = [];
            foreach ($k as $c => $v) { $w[] = "`$c` = ?"; $p[] = $v; }
            $r = Db::one("SELECT $cols FROM `$t` WHERE " . implode(' AND ', $w), $p);
            if ($r) $rows[] = Schema::castRow($t, $r);
        }
        return self::finish($t, $rows, $req['single'] ?? null);
    }

    // ---------------------------------------------------------------- نماهای محاسبه‌ای
    private static function virtual(string $t, array $req): mixed
    {
        if (!Ctx::is('admin', 'reviewer', 'supervisor')) return [];
        $rows = $t === 'document_quality' ? Quality::documents() : Quality::translators();
        foreach ($req['filters'] ?? [] as $f) {
            [$op, $col, $val] = [$f[0], $f[1], $f[2] ?? null];
            $rows = array_values(array_filter($rows, static fn($r) => match ($op) {
                'eq' => ($r[$col] ?? null) == $val,
                'in' => in_array($r[$col] ?? null, (array) $val, true),
                'neq' => ($r[$col] ?? null) != $val,
                default => throw new ApiError("unsupported filter $op on view", 400),
            }));
        }
        foreach (array_reverse($req['order'] ?? []) as $o) {
            $col = $o[0]; $asc = $o[1] ?? true;
            usort($rows, static fn($a, $b) => ($asc ? 1 : -1) * strnatcasecmp((string) ($a[$col] ?? ''), (string) ($b[$col] ?? '')));
        }
        return self::finish($t, $rows, $req['single'] ?? null);
    }
}
