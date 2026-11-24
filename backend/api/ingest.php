<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{ApiError, Auth, Http};

// نقطهٔ ورود خارجی دریافت خبر (جایگزین POST .../functions/v1/news-ingest):
// POST با هدر x-ingest-key و بدنهٔ JSON مثل {"action":"push","source_name":"...","items":[...]}
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') Http::json(['error' => 'method_not_allowed'], 405);
Auth::authenticate(false);
try {
    $body = Http::body();
    Http::json(Rise\Fn\NewsIngest::handle($body, ['headers' => function_exists('getallheaders') ? getallheaders() : []]));
} catch (\Throwable $e) {
    if ($e instanceof ApiError) Http::json(['error' => $e->getMessage()], $e->http);
    Http::fail($e);
}
