<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{Config, Http, Worker};

// GET/POST ?key=<worker_key> — برای Cron هایی که فقط URL صدا می‌زنند (wget/curl). حداکثر ~۲۵ ثانیه کار می‌کند.
$key = (string) Config::get('worker_key', '');
$given = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_WORKER_KEY'] ?? '');
if ($key === '' || str_starts_with($key, 'CHANGE-ME') || !hash_equals($key, $given)) { http_response_code(403); exit('forbidden'); }
ignore_user_abort(true);
@set_time_limit(40);
try { Http::json(Worker::tick(24)); } catch (Throwable $e) { Http::fail($e); }
