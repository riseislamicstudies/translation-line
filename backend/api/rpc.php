<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{Http, Rpc};

// POST {name, args:{p_...}}
Http::run(fn(array $b) => Rpc::handle((string) ($b['name'] ?? ''), (array) ($b['args'] ?? [])), false);
