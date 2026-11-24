<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{Storage};

// GET ?b=&p=&e=&s=  (لینک امضاشده)  یا avatars بدون امضا
$b = (string) ($_GET['b'] ?? ''); $p = (string) ($_GET['p'] ?? '');
try {
    $public = $b === 'avatars';
    if (!$public && !Storage::verify($b, $p, (int) ($_GET['e'] ?? 0), (string) ($_GET['s'] ?? ''))) { http_response_code(403); exit('forbidden'); }
    $f = Storage::path($b, $p);
    if (!is_file($f)) { http_response_code(404); exit('not found'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f) ?: 'application/octet-stream';
    if ($b === 'source-files') $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($f));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . ($public ? 'public, max-age=86400' : 'private, max-age=600'));
    readfile($f);
} catch (Throwable $e) { http_response_code(400); exit('bad request'); }
