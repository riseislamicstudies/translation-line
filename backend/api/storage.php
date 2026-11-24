<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{ApiError, Auth, Http, Storage};

// op=upload: POST بدنهٔ خام فایل؛ b (باکت)، p (مسیر)، upsert=0/1 در query
// op=remove|sign: POST JSON {bucket, paths:[...], expires?}
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') Http::json(['error' => ['message' => 'method_not_allowed']], 405);
try {
    Auth::authenticate(true);
    $op = (string) ($_GET['op'] ?? '');
    if ($op === 'upload') {
        $bucket = (string) ($_GET['b'] ?? ''); $path = (string) ($_GET['p'] ?? '');
        Storage::authorize($bucket, $path, 'insert');
        Storage::put($bucket, $path, file_get_contents('php://input') ?: '', ($_GET['upsert'] ?? '0') === '1');
        Http::json(['data' => ['path' => $path]]);
    }
    $b = Http::body();
    $bucket = (string) ($b['bucket'] ?? '');
    $paths = array_values(array_filter((array) ($b['paths'] ?? []), 'is_string'));
    if ($op === 'remove') {
        foreach ($paths as $p) { Storage::authorize($bucket, $p, 'delete'); Storage::remove($bucket, $p); }
        Http::json(['data' => $paths]);
    }
    if ($op === 'sign') {
        $out = [];
        foreach ($paths as $p) {
            try { Storage::authorize($bucket, $p, 'read'); $out[] = ['path' => $p, 'signedUrl' => Storage::sign($bucket, $p, (int) ($b['expires'] ?? 3600)), 'error' => null]; }
            catch (ApiError) { $out[] = ['path' => $p, 'signedUrl' => null, 'error' => 'Object not found']; }
        }
        Http::json(['data' => $out]);
    }
    throw new ApiError('bad_op', 400);
} catch (\Throwable $e) { Http::fail($e); }
