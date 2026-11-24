<?php
declare(strict_types=1);
namespace Rise;

/** یک «تیک» کار پس‌زمینه: صف کارها + کارهای زمان‌بندی‌شدهٔ هر Fn::cron() + نگهداری. از cron.php و api/worker.php صدا زده می‌شود. */
final class Worker
{
    public static function tick(int $budgetSec = 25): array
    {
        $t0 = microtime(true);
        $out = ['jobs' => Jobs::runDue(max(5, $budgetSec - 5))];
        // کارهای زمان‌بندی‌شده فقط در یک فرایند هم‌زمان
        if (Db::lock('cron', 0)) {
            try {
                self::every('release_claims', 15, function () { Ctx::asService(fn() => Rpc::call('release_expired_claims')); });
                self::every('prune', 1440, function () {
                    Jobs::prune();
                    Db::exec('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP(3)');
                });
                foreach (glob(RISE_LIB . '/Fn/*.php') ?: [] as $f) {
                    $cls = 'Rise\\Fn\\' . basename($f, '.php');
                    if (class_exists($cls) && method_exists($cls, 'cron')) {
                        try { Ctx::asService(fn() => $cls::cron()); } catch (\Throwable $e) { Http::logLine("cron $cls: " . $e->getMessage()); }
                    }
                }
            } finally { Db::unlock('cron'); }
        }
        $out['ms'] = (int) ((microtime(true) - $t0) * 1000);
        return $out;
    }

    /** اجرای $fn حداکثر هر $minutes دقیقه (آخرین اجرا در app_config با کلید _cron_*) */
    private static function every(string $name, int $minutes, callable $fn): void
    {
        $k = '_cron_' . $name;
        $last = Db::val('SELECT `value` FROM app_config WHERE `key` = ?', [$k]);
        if ($last !== null && (int) $last > time() - $minutes * 60 + 5) return;
        $fn();
        Db::exec('INSERT INTO app_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP(3)', [$k, (string) time()]);
    }
}
