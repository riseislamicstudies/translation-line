<?php
declare(strict_types=1);
namespace Rise;

/**
 * خطای قابل‌نمایش به کاربر. متن message همان کدهای RAISE EXCEPTION اصلی (مثل not_allowed) است
 * تا فرانت‌اند که روی این متن‌ها شرط می‌گذارد بدون تغییر کار کند.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(string $message, public int $http = 400, public ?string $pgCode = null, public mixed $details = null)
    {
        parent::__construct($message);
    }
}
