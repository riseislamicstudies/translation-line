-- ارتقای دیتابیس برای قابلیت‌های جدید (یک بار در phpMyAdmin اجرا کنید؛ اجرای دوباره بی‌خطر است)
-- ۱) عکس پروفایل با کیفیت اصلی
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS avatar_original_url TEXT NULL AFTER avatar_url;

-- ۲) فرمتینگ فایل ورد: سطح تیتر، پاورقی و نقل‌قول
ALTER TABLE segments MODIFY COLUMN kind ENUM('heading','paragraph','list_item','footnote','quote') NOT NULL DEFAULT 'paragraph';
ALTER TABLE segments ADD COLUMN IF NOT EXISTS `level` TINYINT NULL AFTER kind;
