<?php
declare(strict_types=1);
// اجرا از Cron Job هاست (هر ۱ تا ۵ دقیقه):   /usr/bin/php /home/USER/.../backend/cron.php
// (مسیر دقیق php را از پشتیبانی هاست بپرسید؛ اگر Cron فقط URL می‌پذیرد از api/worker.php?key=... استفاده کنید.)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/bootstrap.php';
echo json_encode(Rise\Worker::tick(50)), "\n";
