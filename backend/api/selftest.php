<?php
declare(strict_types=1);
/**
 * خودآزمایی روی خود هاست (بعد از ساخت جدول‌ها و config.php):
 *   https://<سایت>/backend/api/selftest.php?key=<worker_key از config.php>
 * یک کاربر آزمایشی می‌سازد، چند عملیات واقعی را اجرا می‌کند و همه‌چیز را پاک می‌کند. بعد از اطمینان این فایل را از هاست حذف کنید.
 */
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{ApiError, Auth, Config, Ctx, Db, Jobs, Policies, Quality, Rest, Rpc, Schema, Secrets, Storage};

$k = (string) Config::get('worker_key', '');
if ($k === '' || str_starts_with($k, 'CHANGE-ME') || !hash_equals($k, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(40);

$fail = 0;
function t(string $name, callable $fn): mixed
{
    global $fail;
    try { $r = $fn(); echo "✓ $name" . (is_string($r) && $r !== '' ? " — $r" : '') . "\n"; return $r; }
    catch (Throwable $e) {
        $fail++;
        $m = $e->getMessage();
        if ($e instanceof PDOException) $m = 'SQL: ' . $m;
        echo "✗ $name — " . get_class($e) . ': ' . $m . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        return null;
    }
}

t('نسخهٔ PHP و MariaDB', fn() => PHP_VERSION . ' / ' . Db::val('SELECT VERSION()'));
t('app_key تنظیم شده', function () { if (strlen((string) Config::get('app_key', '')) < 32 || str_starts_with((string) Config::get('app_key'), 'CHANGE-ME')) throw new Exception('app_key را در config.php بگذارید (۳۲+ نویسه)'); });
t('همهٔ جدول‌ها وجود دارند', function () {
    $missing = [];
    foreach (array_keys(require RISE_LIB . '/schema_meta.php') as $tb) { try { Db::val("SELECT 1 FROM `$tb` LIMIT 1"); } catch (Throwable) { $missing[] = $tb; } }
    if ($missing) throw new Exception('جدول ناموجود: ' . implode(', ', $missing) . ' (sql/schema.sql را اجرا کنید)');
});
t('رمزگذاری کلید مخفی (Secrets)', function () { Secrets::set('SELFTEST_X', 'abc123'); $ok = Secrets::get('SELFTEST_X') === 'abc123'; Secrets::set('SELFTEST_X', ''); if (!$ok) throw new Exception('mismatch'); });
t('ذخیرهٔ فایل (Storage) و لینک امضاشده', function () {
    $p = 'selftest-' . bin2hex(random_bytes(3)) . '.jpg';
    Storage::put('avatars', $p, 'x');
    $ok = Storage::get('avatars', $p) === 'x';
    $u = Storage::sign('avatars', $p); parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
    $v = Storage::verify('avatars', $p, (int) $q['e'], (string) $q['s']);
    Storage::remove('avatars', $p);
    if (!$ok || !$v) throw new Exception('read/verify failed');
    return Config::privateDir();
});

$email = 'selftest+' . bin2hex(random_bytes(3)) . '@example.invalid';
$uid = t('ساخت کاربر آزمایشی', fn() => Auth::createUser($email, 'Selftest-' . bin2hex(random_bytes(4)), 'Self Test'));
if ($uid) {
    $pw = 'Pw-' . bin2hex(random_bytes(5));
    t('تغییر رمز و ورود', function () use ($uid, $email, $pw) {
        Auth::setPassword($uid, $pw);
        $r = Auth::login($email, $pw);
        $_SERVER['HTTP_X_RISE_TOKEN'] = $r['access_token'];
        Auth::authenticate(true);
        if (Ctx::uid() !== $uid) throw new Exception('token did not resolve to user');
    });
    t('نقش و RPCهای پایه', function () use ($uid) {
        Db::exec("UPDATE profiles SET role = 'admin' WHERE id = ?", [$uid]); Ctx::forgetRole();
        if (Rpc::handle('my_role', []) !== 'admin') throw new Exception('my_role');
        Rpc::handle('pool_count', []); Rpc::handle('can_see_senior', []);
    });
    $did = Db::uuid();
    t('Rest: ساخت سند و خواندن', function () use ($did) {
        Rest::handle(['table' => 'documents', 'op' => 'insert', 'values' => ['id' => $did, 'title' => 'آزمایش', 'word_count' => 3, 'source_kind' => 'scholaria'], 'returning' => '*', 'single' => 'single']);
        $r = Rest::handle(['table' => 'documents', 'op' => 'select', 'columns' => 'id,title,status,senior_only', 'filters' => [['eq', 'id', $did]], 'single' => 'single']);
        if ($r['status'] !== 'content_review' || $r['senior_only'] !== false) throw new Exception('unexpected row ' . json_encode($r));
    });
    t('Rest: بخش‌ها (segments) و ترتیب', function () use ($did) {
        Rest::handle(['table' => 'segments', 'op' => 'insert', 'values' => [['document_id' => $did, 'position' => 1, 'kind' => 'heading', 'source_text' => 'a'], ['document_id' => $did, 'position' => 2, 'kind' => 'paragraph', 'source_text' => 'b']]]);
        $r = Rest::handle(['table' => 'segments', 'op' => 'select', 'columns' => 'position,kind', 'filters' => [['eq', 'document_id', $did]], 'order' => [['position', false]]]);
        if (count($r) !== 2 || $r[0]['position'] !== 2) throw new Exception(json_encode($r));
    });
    t('تریگر تغییر وضعیت (رویداد + اعلان)', function () use ($did) {
        Rest::handle(['table' => 'documents', 'op' => 'update', 'values' => ['status' => 'review_rejected', 'review_note' => 'x'], 'filters' => [['eq', 'id', $did]]]);
        $n = (int) Db::val('SELECT COUNT(*) FROM document_events WHERE document_id = ?', [$did]);
        if ($n < 1) throw new Exception('document_events not written');
    });
    t('Rest: upsert (app_config) و or/like', function () {
        Rest::handle(['table' => 'app_config', 'op' => 'upsert', 'values' => ['key' => 'selftest_key', 'value' => '1'], 'onConflict' => 'key']);
        Rest::handle(['table' => 'app_config', 'op' => 'upsert', 'values' => ['key' => 'selftest_key', 'value' => '2'], 'onConflict' => 'key']);
        $r = Rest::handle(['table' => 'app_config', 'op' => 'select', 'columns' => 'key,value', 'or' => 'key.like.selftest\_%,key.eq.zzz']);
        if (count($r) !== 1 || $r[0]['value'] !== '2') throw new Exception(json_encode($r));
        Rest::handle(['table' => 'app_config', 'op' => 'delete', 'filters' => [['eq', 'key', 'selftest_key']]]);
    });
    t('RPC: دعوت‌نامه و واژه‌نامه', function () {
        $r = Rpc::handle('create_invite', ['p_days' => 1, 'p_max' => 1, 'p_label' => 'selftest']);
        if (!$r) throw new Exception('no invite result');
        Rpc::handle('glossary_import', ['p_rows' => [['source' => 'selftest-term', 'target' => 'آزمون']]]);
    });
    t('نماهای کیفیت (Quality)', function () { Quality::documents(); Quality::translators(); });
    t('صف کار (Jobs)', function () { Jobs::enqueue('push', null, ['notification_id' => null]); Jobs::runDue(2); });
    t('پاک‌سازی', function () use ($uid, $did) {
        Ctx::set(null);
        Db::exec('DELETE FROM jobs WHERE kind = ? AND ref_id IS NULL', ['push']);
        Db::exec('DELETE FROM documents WHERE id = ?', [$did]);
        Db::exec("DELETE FROM glossary WHERE source_term = 'selftest-term'");
        Db::exec("DELETE FROM invites WHERE label = 'selftest'");
        Db::exec('DELETE FROM notifications WHERE user_id = ?', [$uid]);
        Auth::deleteUser($uid);
    });
}
echo "\n" . ($fail === 0 ? 'همه آزمون‌ها موفق بود ✓' : "$fail آزمون ناموفق ✗ — این خروجی را برای رفع اشکال بفرستید") . "\n";
