<?php
declare(strict_types=1);
namespace Rise;

final class Config
{
    private static array $c = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => ['message' => 'not_configured', 'details' => 'backend/config.php is missing']]);
            exit;
        }
        try {
            $c = require $file;
        } catch (\Throwable $e) {
            self::bad('config.php has an error: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')');
        }
        if (!is_array($c)) self::bad('config.php must start with <?php and end with: return [ ... ];  (copy config.sample.php and only edit the values)');
        self::$c = $c;
    }

    private static function bad(string $why): never
    {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['message' => 'bad_config', 'details' => $why]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** get('db.host') */
    public static function get(string $path, mixed $default = null): mixed
    {
        $v = self::$c;
        foreach (explode('.', $path) as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) return $default;
            $v = $v[$p];
        }
        return $v;
    }

    /** پوشهٔ خصوصی (فایل‌های آپلودی، لاگ). ترجیحاً بیرون از public_html. */
    public static function privateDir(): string
    {
        $d = (string) self::get('private_dir', RISE_BACKEND . '/private');
        if (!is_dir($d)) @mkdir($d, 0750, true);
        // اگر پوشه داخل وب است، دسترسی مستقیم را ببند (بی‌اثر وقتی بیرون از public_html است)
        if (is_dir($d) && !is_file($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        return rtrim($d, '/');
    }
}
