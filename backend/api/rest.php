<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{Http, Rest};

Http::run(fn(array $b) => Rest::handle($b));
