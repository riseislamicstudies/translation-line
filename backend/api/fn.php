<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{ApiError, Http};

// POST {name: 'translate-document', body: {...}} — جایگزین Edge Functions.
// هر تابع lib/Fn/<PascalCase>.php با متد handle(array $body, array $ctx) است. توکن لازم بودن را خود تابع تعیین می‌کند (public const AUTH).
$raw = file_get_contents('php://input') ?: '';
$req = json_decode($raw, true);
$name = is_array($req) ? (string) ($req['name'] ?? '') : '';
if (!preg_match('/^[a-z][a-z0-9-]{1,40}$/', $name)) Http::json(['error' => ['message' => 'bad_function']], 400);
$cls = 'Rise\\Fn\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));
if (!class_exists($cls)) Http::json(['error' => ['message' => 'function_not_found']], 404);
$needsAuth = defined("$cls::AUTH") ? constant("$cls::AUTH") : true;
// فرانت بدنهٔ اصلی را در body می‌گذارد؛ برای یکدست بودن، Http::run بدنهٔ خام را می‌خواند، پس اینجا دستی اجرا می‌کنیم.
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') Http::json(['error' => ['message' => 'method_not_allowed']], 405);
Rise\Auth::authenticate($needsAuth);
try {
    $out = $cls::handle((array) ($req['body'] ?? []), ['headers' => function_exists('getallheaders') ? getallheaders() : []]);
    Http::json($out);   // توابع لبه «خروجی خودشان» را برمی‌گردانند (بدون پوشش data)
} catch (\Throwable $e) {
    if ($e instanceof ApiError) Http::json(['error' => $e->getMessage(), 'detail' => $e->details], $e->http);
    Http::fail($e);
}
