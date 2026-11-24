<?php
declare(strict_types=1);
namespace Rise;

final class Db
{
    private static ?\PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): \PDO
    {
        if (self::$pdo) return self::$pdo;
        $h = (string) Config::get('db.host', 'localhost');
        $n = (string) Config::get('db.name');
        $dsn = "mysql:host=$h;dbname=$n;charset=utf8mb4";
        if ($p = Config::get('db.port')) $dsn .= ';port=' . (int) $p;
        self::$pdo = new \PDO($dsn, (string) Config::get('db.user'), (string) Config::get('db.pass'), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        ]);
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $v) {
            if (is_bool($v)) $st->bindValue($i, $v ? 1 : 0, \PDO::PARAM_INT);
            elseif (is_int($v)) $st->bindValue($i, $v, \PDO::PARAM_INT);
            elseif ($v === null) $st->bindValue($i, null, \PDO::PARAM_NULL);
            elseif (is_array($v)) $st->bindValue($i, json_encode($v, JSON_UNESCAPED_UNICODE), \PDO::PARAM_STR);
            else $st->bindValue($i, (string) $v, \PDO::PARAM_STR);
            $i++;
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    public static function one(string $sql, array $p = []): ?array { $r = self::q($sql, $p)->fetch(); return $r === false ? null : $r; }
    public static function val(string $sql, array $p = []): mixed { $r = self::q($sql, $p)->fetch(\PDO::FETCH_NUM); return $r === false ? null : $r[0]; }
    public static function exec(string $sql, array $p = []): int { return self::q($sql, $p)->rowCount(); }
    public static function lastId(): int { return (int) self::pdo()->lastInsertId(); }

    /** '(?,?,?)' برای IN. آرایهٔ خالی → (NULL) تا هیچ ردیفی نخورد. */
    public static function in(array $vals): string
    {
        return $vals ? '(' . implode(',', array_fill(0, count($vals), '?')) . ')' : '(NULL)';
    }

    public static function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $vals = [];
        foreach ($row as $c => $v) $vals[] = Schema::hasCol($table, $c) ? Schema::toDb($table, $c, $v) : $v;
        self::q($sql, $vals);
    }

    /** UPDATE table SET ... WHERE <where> ; $where فقط SQL با ? */
    public static function update(string $table, array $set, string $where, array $wp = []): int
    {
        if (!$set) return 0;
        $parts = []; $vals = [];
        foreach ($set as $c => $v) { $parts[] = '`' . $c . '` = ?'; $vals[] = $v; }
        return self::exec('UPDATE `' . $table . '` SET ' . implode(', ', $parts) . ' WHERE ' . $where, array_merge($vals, $wp));
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /** زمان فعلی UTC با میلی‌ثانیه، مناسب ستون DATETIME(3) */
    public static function now(): string
    {
        $t = microtime(true);
        return gmdate('Y-m-d H:i:s', (int) $t) . sprintf('.%03d', (int) (($t - floor($t)) * 1000));
    }

    /** تراکنش تودرتو (SAVEPOINT). هر استثنا → برگشت. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $outer = self::$depth === 0;
        $sp = 'sp' . self::$depth;
        if ($outer) $pdo->beginTransaction(); else $pdo->exec("SAVEPOINT $sp");
        self::$depth++;
        try {
            $r = $fn();
            self::$depth--;
            if ($outer) $pdo->commit(); else $pdo->exec("RELEASE SAVEPOINT $sp");
            return $r;
        } catch (\Throwable $e) {
            self::$depth--;
            try { if ($outer) { if ($pdo->inTransaction()) $pdo->rollBack(); } else $pdo->exec("ROLLBACK TO SAVEPOINT $sp"); } catch (\Throwable) {}
            throw $e;
        }
    }

    /** قفل نام‌دار (معادل pg_advisory_lock) برای جلوگیری از اجرای هم‌زمان کارها */
    public static function lock(string $name, int $wait = 0): bool
    {
        return (int) self::val('SELECT GET_LOCK(?, ?)', ['rise_' . $name, $wait]) === 1;
    }
    public static function unlock(string $name): void { self::val('SELECT RELEASE_LOCK(?)', ['rise_' . $name]); }
}
