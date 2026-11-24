<?php
// کپی کنید به config.php و مقدارها را پر کنید. config.php هرگز نباید در Git یا قابل دانلود باشد.
return [
    // فقط هنگام عیب‌یابی true کنید: جزئیات خطا در پاسخ نمایش داده می‌شود. بعد از رفع مشکل حذف/false کنید.
    'debug' => false,
    'db' => [
        'host' => 'localhost',
        'name' => 'CPANELUSER_rise',
        'user' => 'CPANELUSER_rise',
        'pass' => 'رمز-دیتابیس',
    ],
    // یک رشتهٔ تصادفی بلند (۳۲+ نویسه). برای امضای لینک فایل‌ها و رمزگذاری کلیدهای مخفی در دیتابیس.
    // ⚠️ بعد از تنظیم، عوض نکنید؛ وگرنه کلیدهای ذخیره‌شده خوانا نمی‌مانند.
    'app_key' => 'CHANGE-ME-random-64-chars',
    // پوشهٔ خصوصی فایل‌ها و لاگ؛ ترجیحاً بیرون از public_html (مثلاً /home/USER/rise-private).
    'private_dir' => __DIR__ . '/private',
    // کلید فراخوانی worker/cron از طریق HTTP و دریافت خبر از بیرون (x-ingest-key)
    'worker_key' => 'CHANGE-ME-another-random-string',
    'ingest_key' => 'CHANGE-ME-third-random-string',
    // مسیر پوشهٔ api نسبت به index.html (برای آدرس عکس‌های پروفایل)
    'api_prefix' => 'backend/api',
    // آدرس سایت (برای لینک‌ها در اعلان‌ها)
    'app_url' => 'https://panel.riseislamicstudies.com/',
    // اختیاری: کلیدهای مخفی به‌جای جدول app_secrets (فقط برای راه‌اندازی اولیه)
    'secrets' => [
        // 'GEMINI_API_KEY' => '...',
    ],
];
