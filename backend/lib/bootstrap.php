<?php
declare(strict_types=1);
/**
 * بارگذاری مشترک همهٔ نقطه‌های ورود API.
 * - autoload کلاس‌های Rise\* از پوشهٔ lib
 * - خواندن config.php (فایل بیرون از Git؛ نمونه: config.sample.php)
 * - پاسخ JSON یکدست و بدون نشت جزئیات داخلی
 */
define('RISE_LIB', __DIR__);
define('RISE_BACKEND', dirname(__DIR__));
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Rise\\', 5) !== 0) return;
    $file = RISE_LIB . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (is_file($file)) require $file;
});

// هر خطای کشف‌نشده → JSON 500 (جزئیات فقط در لاگ خصوصی)
set_exception_handler(static function (\Throwable $e): void {
    \Rise\Http::fail($e);
});
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    throw new \ErrorException($str, 0, $no, $file, $line);
});
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        \Rise\Http::logLine('FATAL ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    }
});
ini_set('display_errors', '0');

\Rise\Config::load(RISE_BACKEND . '/config.php');
