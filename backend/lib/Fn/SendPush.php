<?php
declare(strict_types=1);
namespace Rise\Fn;

use Rise\{ApiError, Ctx, Db, Jobs, Push, Secrets};

/**
 * send-push: ارسال Web Push برای اعلان‌های درون‌برنامه.
 *  - مسیر اصلی: Notify.php برای هر اعلان یک کار 'push' در صف می‌گذارد؛ handler آن در registerJobs() است.
 *  - handle(): {action:'test'} (کاربر واردشده → اعلان آزمایشی برای دستگاه‌های خودش)،
 *    یا هدر x-push-secret (= PUSH_HOOK_SECRET) + {notification_id} برای اجرای دستی.
 */
final class SendPush
{
    public const AUTH = false;

    public static function registerJobs(): void
    {
        Jobs::register('push', static function (array $job): array {
            $nid = (string) ($job['payload']['notification_id'] ?? $job['ref_id'] ?? '');
            try { self::forNotification($nid); } catch (\Throwable $e) { \Rise\Http::logLine('push job: ' . $e->getMessage()); }
            return ['done' => true];
        });
    }

    public static function handle(array $body, array $ctx): array
    {
        $vapid = Push::vapid();
        if (!$vapid) throw new ApiError('vapid_not_configured', 500);

        $hook = self::header($ctx, 'x-push-secret');
        if ($hook !== '') {
            $hs = Secrets::get('PUSH_HOOK_SECRET');
            if ($hs === '' || !hash_equals($hs, $hook)) throw new ApiError('forbidden', 403);
            return self::forNotification((string) ($body['notification_id'] ?? ''), $vapid);
        }
        $uid = Ctx::uid();
        if ($uid === null) throw new ApiError(self::header($ctx, 'authorization') === '' && self::header($ctx, 'x-rise-token') === '' ? 'missing_authorization' : 'invalid_token', 401);
        if (($body['action'] ?? null) !== 'test') throw new ApiError('bad_request', 400);
        return self::deliver($uid, ['title' => 'سکوی رایز', 'body' => 'اعلان آزمایشی: این دستگاه اعلان دریافت می‌کند.', 'tag' => 'test', 'url' => './'], $vapid);
    }

    private static function header(array $ctx, string $name): string
    {
        foreach (($ctx['headers'] ?? []) as $k => $v) if (strcasecmp((string) $k, $name) === 0) return is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        return '';
    }

    /** بار اعلان را بساز و بفرست. هرگز برای شکست تحویل استثنا نمی‌اندازد. */
    private static function forNotification(string $nid, ?array $vapid = null): array
    {
        $n = $nid === '' ? null : Db::one('SELECT user_id, type, document_id, payload FROM notifications WHERE id = ?', [$nid]);
        if (!$n) return ['ok' => true, 'skipped' => 'no_notification'];
        if ($n['type'] === 'claimed') return ['ok' => true, 'skipped' => 'quiet_type'];
        $n['payload'] = is_string($n['payload']) ? (json_decode($n['payload'], true) ?: []) : ($n['payload'] ?? []);
        $text = self::notifText($n['type'], $n['payload']);
        if ($text === '') return ['ok' => true, 'skipped' => 'no_text'];
        // احترام به تنظیم «اعلان نمی‌خواهم»
        $notify = Db::val('SELECT notify FROM profiles WHERE id = ?', [$n['user_id']]);
        if ($notify === null || (int) $notify !== 1) return ['ok' => true, 'skipped' => 'notify_off'];
        $vapid ??= Push::vapid();
        if (!$vapid) return ['ok' => true, 'skipped' => 'vapid_not_configured'];
        return self::deliver($n['user_id'], ['title' => 'سکوی رایز', 'body' => $text, 'tag' => $n['type'] . '-' . ($n['document_id'] ?? ''), 'url' => './'], $vapid);
    }

    private static function deliver(string $userId, array $payload, array $vapid): array
    {
        $subs = Db::all('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?', [$userId]);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sent = 0; $removed = 0; $errors = [];
        foreach ($subs as $s) {
            $r = Push::send($s, $json, $vapid, 86400);
            if ($r['status'] >= 200 && $r['status'] < 300) $sent++;
            elseif ($r['status'] === 404 || $r['status'] === 410) { Db::exec('DELETE FROM push_subscriptions WHERE id = ?', [$s['id']]); $removed++; }
            else $errors[] = trim(($r['status'] ?: '') . ' ' . mb_substr($r['body'] !== '' ? $r['body'] : ($r['error'] ?? ''), 0, 120));
        }
        return ['ok' => true, 'devices' => count($subs), 'sent' => $sent, 'removed' => $removed, 'errors' => $errors];
    }

    /** ارقام فارسی (مثل fa() در نسخهٔ اصلی) */
    private static function fa(int|string $x): string { return strtr((string) $x, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }

    private static function notifText(string $type, array $p): string
    {
        $t = '«' . self::s($p['title'] ?? '') . '»';
        $note = self::s($p['note'] ?? '') !== '' ? ': ' . self::s($p['note']) : '';
        $by = self::s($p['by'] ?? '') !== '' ? self::s($p['by']) : 'کسی';
        return match ($type) {
            'new_in_pool' => "فایل جدید در سبد ترجمه: $t",
            'needs_review' => "فایل $t برای بازبینی محتوا آماده است.",
            'awaiting_qa' => "$t برای بررسی ناظر رسید.",
            'changes_requested' => "$t با یادداشت ناظر برگشت$note.",
            'returned_to_writer' => "$t برای اصلاح به نویسنده برگشت$note.",
            'approved' => "ترجمه $t تایید شد.",
            'comment' => "$by روی $t نظر گذاشت$note",
            'news_batch' => self::fa((int) ($p['count'] ?? 0)) . ' خبر تازه در سبد ویراستار خبر قرار گرفت.',
            'returned_to_editor' => "بازبین $t را برای اصلاح برگرداند$note.",
            'ai_failed' => "ترجمه ماشینی $t ناموفق بود$note.",
            default => '',
        };
    }

    private static function s(mixed $v): string { return is_scalar($v) ? (string) $v : ''; }
}
