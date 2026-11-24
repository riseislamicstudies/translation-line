<?php
declare(strict_types=1);
namespace Rise;

/**
 * صف کار روی جدول jobs. هاست اشتراکی سقف ~۳۰ ثانیه برای هر درخواست دارد؛
 * کارهای سنگین (ترجمه، بازبینی AI، خبر) تکه‌تکه توسط worker.php (از Cron و از «لگد» بعد از ثبت کار) اجرا می‌شوند.
 *
 * هر handler: function(array $job): array{done:bool, progress?:array, delay?:int}
 *   done=true → تمام شد؛ done=false → بعداً ادامه بده (delay ثانیه)؛ استثنا → تلاش دوباره تا 3 بار.
 */
final class Jobs
{
    /** @var array<string, callable> */
    private static array $handlers = [];
    private static bool $kickRegistered = false;

    public static function register(string $kind, callable $handler): void { self::$handlers[$kind] = $handler; }

    public static function enqueue(string $kind, ?string $ref, array $payload = [], int $delaySec = 0): int
    {
        Db::exec('INSERT INTO jobs (kind, ref_id, payload, run_after) VALUES (?, ?, ?, ?)',
            [$kind, $ref, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT), gmdate('Y-m-d H:i:s', time() + $delaySec) . '.000']);
        $id = Db::lastId();
        self::kickAfterResponse();
        return $id;
    }

    /** بعد از پایان پاسخ فعلی، یک دور worker را در همین فرایند اجرا کن (برای push و شروع سریع ترجمه). */
    public static function kickAfterResponse(): void
    {
        if (self::$kickRegistered) return;
        self::$kickRegistered = true;
        register_shutdown_function(static function () {
            try {
                Http::finishResponse();
                self::runDue(12);
            } catch (\Throwable $e) { Http::logLine('kick: ' . $e->getMessage()); }
        });
    }

    /** کارهای آمادهٔ اجرا را تا سقف $budget ثانیه انجام بده. تعداد کارهای اجراشده را برمی‌گرداند. */
    public static function runDue(int $budgetSec = 20, ?array $kinds = null): int
    {
        self::loadHandlers();
        $deadline = microtime(true) + $budgetSec;
        $ran = 0;
        while (microtime(true) < $deadline) {
            $job = self::claim($kinds);
            if (!$job) break;
            $ran++;
            try {
                $h = self::$handlers[$job['kind']] ?? null;
                if (!$h) throw new \RuntimeException('no handler for ' . $job['kind']);
                $job['payload'] = json_decode((string) $job['payload'], true) ?: [];
                $job['deadline'] = $deadline;
                $r = Ctx::asService(fn() => $h($job));
                if (!empty($r['done'])) {
                    Db::exec("UPDATE jobs SET state='done', locked_until=NULL, progress=?, updated_at=UTC_TIMESTAMP(3) WHERE id=?",
                        [json_encode($r['progress'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE), $job['id']]);
                } else {
                    // هر برش موفق شمارندهٔ تلاش را صفر می‌کند؛ attempts فقط خطاهای پشت‌سرهم را می‌شمارد
                    Db::exec("UPDATE jobs SET state='queued', attempts=0, locked_until=NULL, progress=?, run_after=?, updated_at=UTC_TIMESTAMP(3) WHERE id=?",
                        [json_encode($r['progress'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s', time() + (int) ($r['delay'] ?? 0)) . '.000', $job['id']]);
                }
            } catch (\Throwable $e) {
                Http::logLine('job ' . $job['id'] . ' ' . $job['kind'] . ': ' . $e->getMessage());
                $fail = (int) $job['attempts'] >= 3;
                Db::exec("UPDATE jobs SET state=?, locked_until=NULL, error=?, run_after=?, updated_at=UTC_TIMESTAMP(3) WHERE id=?",
                    [$fail ? 'failed' : 'queued', mb_substr($e->getMessage(), 0, 500), gmdate('Y-m-d H:i:s', time() + 30 * (int) $job['attempts']) . '.000', $job['id']]);
                if ($fail && ($f = self::$onFail[$job['kind']] ?? null)) { try { Ctx::asService(fn() => $f($job, $e)); } catch (\Throwable) {} }
            }
        }
        return $ran;
    }

    /** @var array<string, callable> */
    private static array $onFail = [];
    public static function onFail(string $kind, callable $fn): void { self::$onFail[$kind] = $fn; }

    private static function claim(?array $kinds): ?array
    {
        return Db::tx(function () use ($kinds) {
            $w = "state = 'queued' AND run_after <= UTC_TIMESTAMP(3)";
            $p = [];
            if ($kinds) { $w .= ' AND kind IN ' . Db::in($kinds); $p = $kinds; }
            // کار «running» که قفلش منقضی شده (فرایند وسط کار مرده) دوباره برداشته می‌شود
            $job = Db::one("SELECT * FROM jobs WHERE ($w) OR (state = 'running' AND locked_until < UTC_TIMESTAMP(3)) ORDER BY run_after, id LIMIT 1 FOR UPDATE SKIP LOCKED", $p);
            if (!$job) return null;
            Db::exec("UPDATE jobs SET state='running', attempts = attempts + 1, locked_until = UTC_TIMESTAMP(3) + INTERVAL 90 SECOND, updated_at = UTC_TIMESTAMP(3) WHERE id = ?", [$job['id']]);
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        });
    }

    private static function loadHandlers(): void
    {
        static $loaded = false;
        if ($loaded) return;
        $loaded = true;
        foreach (glob(RISE_LIB . '/Fn/*.php') ?: [] as $f) {
            $cls = 'Rise\\Fn\\' . basename($f, '.php');
            if (class_exists($cls) && method_exists($cls, 'registerJobs')) $cls::registerJobs();
        }
    }

    /** حذف کارهای تمام‌شدهٔ قدیمی */
    public static function prune(): void
    {
        Db::exec("DELETE FROM jobs WHERE state IN ('done','failed') AND updated_at < UTC_TIMESTAMP(3) - INTERVAL 7 DAY");
    }
}
