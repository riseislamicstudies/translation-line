<?php
declare(strict_types=1);
namespace Rise;

final class Http
{
    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return [];
        $j = json_decode($raw, true);
        if (!is_array($j)) throw new ApiError('invalid_json', 400);
        return $j;
    }

    public static function json(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** اجرای یک هندلر API: POST با بدنهٔ JSON → {data: ...} یا {error: {...}} */
    public static function run(callable $fn, bool $needsAuth = true): never
    {
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') self::json(['error' => ['message' => 'method_not_allowed']], 405);
        if ($needsAuth) Auth::authenticate(); else Auth::authenticate(false);
        self::json(['data' => $fn(self::body())]);
    }

    public static function fail(\Throwable $e): never
    {
        if ($e instanceof ApiError) {
            self::json(['error' => ['message' => $e->getMessage(), 'code' => $e->pgCode, 'details' => $e->details]], $e->http);
        }
        if ($e instanceof \PDOException) {
            $info = $e->errorInfo ?: [];
            $drv = (int) ($info[1] ?? 0);
            self::logLine('PDO ' . $e->getMessage());
            // 1062 تکراری، 1451/1452 کلید خارجی، 4025/3819 قید CHECK
            if ($drv === 1062) self::json(['error' => ['message' => 'duplicate key value violates unique constraint', 'code' => '23505']], 409);
            if ($drv === 1451 || $drv === 1452) self::json(['error' => ['message' => 'violates foreign key constraint', 'code' => '23503']], 409);
            if ($drv === 4025 || $drv === 3819) self::json(['error' => ['message' => 'violates check constraint', 'code' => '23514']], 400);
            $err = ['message' => 'database_error', 'code' => 'XX000'];
            if (Config::get('debug') === true) $err['details'] = $e->getMessage();
            self::json(['error' => $err], 500);
        }
        $line = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        self::logLine($line);
        $err = ['message' => 'server_error', 'code' => 'XX000'];
        if (Config::get('debug') === true) $err['details'] = $line;
        self::json(['error' => $err], 500);
    }

    public static function logLine(string $line): void
    {
        try {
            $f = Config::privateDir() . '/error.log';
            if (is_file($f) && filesize($f) > 2_000_000) @rename($f, $f . '.1');
            @file_put_contents($f, gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) { /* لاگ نباید خودش خطا بدهد */ }
    }

    /** پاسخ را ببند و ادامهٔ کار (مثل ارسال push) را بعد از آن انجام بده. */
    public static function finishResponse(): void
    {
        if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return; }
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
        @ob_end_flush(); @flush();
    }
}
