<?php
declare(strict_types=1);
/**
 * انتقال یک‌باره از Supabase به این بک‌اند (اجرا از مرورگر؛ تکه‌تکه تا سقف ۳۰ ثانیه هاست رد نشود).
 *
 * قبل از اجرا در config.php این بخش را موقتاً اضافه کنید و بعد از پایان کار حذفش کنید و همین فایل را از هاست پاک کنید:
 *   'migrate' => [
 *       'key'          => 'یک-رشتهٔ-تصادفی-بلند',
 *       'supabase_url' => 'https://tersvbytrecqzuzkthfa.supabase.co',
 *       'service_key'  => 'کلید service_role (فقط برای همین انتقال)',
 *       'skip'         => ['push_subscriptions', 'secrets'],   // فقط برای نسخهٔ آزمایشی (اختیاری)
 *   ],
 * سپس باز کنید:  https://<سایت>/backend/api/migrate.php?key=<همان key>
 *
 * - دوباره‌اجراپذیر است (INSERT ... ON DUPLICATE KEY UPDATE).
 * - رمزها: Supabase هش رمز را از API نمی‌دهد. اگر فایل private/auth_users.csv را بگذارید
 *   (خروجی:  select id, email, encrypted_password from auth.users;  ستون‌ها با هدر)، رمزهای فعلی حفظ می‌شوند؛
 *   وگرنه برای کاربران رمز تصادفی ساخته می‌شود و مدیر باید رمز جدید بدهد.
 * - کلیدهای مخفی (app_secrets) مستقیم از Supabase به این دیتابیس می‌روند (رمزگذاری‌شده با app_key) و از جای دیگری عبور نمی‌کنند.
 */
require __DIR__ . '/../lib/bootstrap.php';
use Rise\{Config, Db, Schema, Secrets, Storage};

$mk = (string) Config::get('migrate.key', '');
if ($mk === '' || !hash_equals($mk, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
$SB = rtrim((string) Config::get('migrate.supabase_url', ''), '/');
$SK = (string) Config::get('migrate.service_key', '');
if ($SB === '' || $SK === '') { http_response_code(500); exit('migrate.supabase_url / migrate.service_key are not set in config.php'); }

const ORDER = [
    'users', 'profiles', 'invites', 'prompts', 'profile_contacts', 'translator_levels', 'documents', 'segments', 'segment_revisions',
    'segment_comments', 'notifications', 'document_events', 'priority_history', 'app_config', 'glossary',
    'news_sources', 'news_rules', 'news_items', 'document_images', 'push_subscriptions', 'secrets', 'storage:avatars', 'storage:doc-images', 'storage:source-files', 'verify',
];

function sb(string $method, string $path, ?array $json = null, array $hdr = []): array
{
    global $SB, $SK;
    $ch = curl_init($SB . $path);
    $h = array_merge(['apikey: ' . $SK, 'Authorization: Bearer ' . $SK], $hdr);
    if ($json !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25]);
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("Supabase unreachable: $e"); }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}

function fetchRows(string $table, int $offset, int $limit): array
{
    $order = ['app_config' => 'key', 'app_secrets' => 'name', 'translator_levels' => 'user_id'][$table] ?? 'id'; // کلید اصلی = ترتیب پایدار
    $r = sb('GET', "/rest/v1/$table?select=*&order=$order",
        null, ['Range-Unit: items', 'Range: ' . $offset . '-' . ($offset + $limit - 1)]);
    if ($r['code'] >= 300) throw new RuntimeException("$table: HTTP {$r['code']} " . substr($r['body'], 0, 200));
    return json_decode($r['body'], true) ?: [];
}

/** یک ردیف Supabase را وارد جدول MariaDB کن (فقط ستون‌های مشترک؛ idempotent) */
function upsert(string $table, array $row): void
{
    $vis = Schema::visibleCols($table);
    $set = [];
    foreach ($row as $c => $v) {
        if (!in_array($c, $vis, true)) continue;
        if ($table === 'profiles' && $c === 'avatar_url' && is_string($v) && preg_match('#/storage/v1/object/public/avatars/([^?]+)(\?.*)?$#', $v, $m)) {
            $v = trim((string) Config::get('api_prefix', 'backend/api'), '/') . '/file.php?b=avatars&p=' . rawurlencode(rawurldecode($m[1])) . (isset($m[2]) ? '&' . ltrim($m[2], '?') : '');
        }
        $set[$c] = Schema::toDb($table, $c, $v);
    }
    if (!$set) return;
    $cols = array_keys($set);
    $upd = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols));
    Db::exec('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ") ON DUPLICATE KEY UPDATE $upd", array_values($set));
}

function loadHashes(): array
{
    $f = Config::privateDir() . '/auth_users.csv';
    if (!is_file($f)) return [];
    $out = []; $h = null;
    $fh = fopen($f, 'r');
    while (($row = fgetcsv($fh)) !== false) {
        if ($h === null) { $h = array_map('trim', $row); continue; }
        $r = @array_combine($h, $row);
        if ($r && !empty($r['id']) && !empty($r['encrypted_password'])) $out[$r['id']] = $r['encrypted_password'];
    }
    fclose($fh);
    return $out;
}

function step(string $name, int $offset, float $deadline): array
{
    // برای نسخهٔ آزمایشی: migrate.skip = ['push_subscriptions','secrets'] تا push واقعی نرود و کلید وردپرس/AI کپی نشود
    if (in_array($name, (array) Config::get('migrate.skip', []), true)) return ['done' => true, 'msg' => 'رد شد (migrate.skip)'];
    Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    if ($name === 'users') {
        $r = sb('GET', '/auth/v1/admin/users?page=1&per_page=200');
        if ($r['code'] >= 300) throw new RuntimeException('auth users: HTTP ' . $r['code']);
        $users = json_decode($r['body'], true)['users'] ?? [];
        $hashes = loadHashes(); $n = 0; $noHash = 0;
        foreach ($users as $u) {
            $hash = $hashes[$u['id']] ?? null;
            if (!$hash) { $noHash++; $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT); }
            $ban = !empty($u['banned_until']) ? Schema::fromIso($u['banned_until']) : null;
            Db::exec('INSERT INTO auth_users (id, email, password_hash, banned_until, created_at) VALUES (?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE email = VALUES(email), banned_until = VALUES(banned_until)' . ($hashes ? ', password_hash = VALUES(password_hash)' : ''),
                [$u['id'], mb_strtolower((string) $u['email']), $hash, $ban, Schema::fromIso($u['created_at'] ?? gmdate('c'))]);
            $n++;
        }
        return ['done' => true, 'msg' => "$n کاربر" . ($noHash ? " ($noHash نفر بدون هش رمز → رمز موقت تصادفی؛ مدیر باید رمز جدید بدهد)" : ' (رمزها حفظ شد)')];
    }
    if ($name === 'secrets') {
        $rows = fetchRows('app_secrets', 0, 1000); $n = 0;
        foreach ($rows as $r) { Secrets::set((string) $r['name'], (string) $r['value'], null); $n++; }
        return ['done' => true, 'msg' => "$n کلید مخفی منتقل شد (نام‌ها: " . implode(', ', array_column($rows, 'name')) . ')'];
    }
    if (str_starts_with($name, 'storage:')) {
        $bucket = substr($name, 8);
        $queue = $offset === 0 ? [''] : json_decode((string) ($_GET['q'] ?? '[""]'), true);
        $files = 0;
        while ($queue && microtime(true) < $deadline) {
            $prefix = array_shift($queue);
            $r = sb('POST', "/storage/v1/object/list/$bucket", ['prefix' => $prefix, 'limit' => 1000, 'offset' => 0, 'sortBy' => ['column' => 'name', 'order' => 'asc']]);
            if ($r['code'] >= 300) throw new RuntimeException("list $bucket: HTTP {$r['code']}");
            foreach (json_decode($r['body'], true) ?: [] as $o) {
                $p = ($prefix === '' ? '' : $prefix . '/') . $o['name'];
                if (empty($o['id'])) { $queue[] = $p; continue; } // پوشه
                if (Storage::exists($bucket, $p)) continue;
                $d = sb('GET', "/storage/v1/object/$bucket/" . implode('/', array_map('rawurlencode', explode('/', $p))));
                if ($d['code'] >= 300) throw new RuntimeException("download $bucket/$p: HTTP {$d['code']}");
                Storage::put($bucket, $p, $d['body']); $files++;
            }
        }
        return $queue ? ['done' => false, 'offset' => $offset + 1, 'q' => $queue, 'msg' => "$files فایل تا اینجا"] : ['done' => true, 'msg' => 'همهٔ فایل‌های باکت منتقل شد'];
    }
    if ($name === 'verify') {
        $lines = [];
        foreach (ORDER as $t) {
            if ($t === 'users' || $t === 'secrets' || $t === 'verify' || str_starts_with($t, 'storage:')) continue;
            $r = sb('GET', "/rest/v1/$t?select=*", null, ['Range-Unit: items', 'Range: 0-0', 'Prefer: count=exact']);
            preg_match('#/(\d+|\*)\s*$#m', $r['headers'], $m);
            $src = $m[1] ?? '?'; $dst = (int) Db::val("SELECT COUNT(*) FROM `$t`");
            $lines[] = sprintf('%s: supabase=%s  هاست=%d %s', $t, $src, $dst, (string) $dst === (string) $src ? '✓' : '✗');
        }
        return ['done' => true, 'msg' => implode("\n", $lines)];
    }
    // جدول عادی، صفحه‌به‌صفحه
    $n = 0;
    while (microtime(true) < $deadline) {
        $rows = fetchRows($name, $offset, 500);
        foreach ($rows as $row) { upsert($name, $row); $n++; }
        $offset += count($rows);
        if (count($rows) < 500) return ['done' => true, 'msg' => "$offset ردیف"];
    }
    return ['done' => false, 'offset' => $offset, 'msg' => "$offset ردیف تا اینجا"];
}

if (isset($_GET['step'])) {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(40);
    try {
        $out = step((string) $_GET['step'], (int) ($_GET['offset'] ?? 0), microtime(true) + 20);
        echo json_encode(['ok' => true] + $out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
header('Content-Type: text/html; charset=utf-8');
$steps = json_encode(ORDER);
$k = htmlspecialchars($mk, ENT_QUOTES);
echo <<<HTML
<!doctype html><meta charset="utf-8"><title>انتقال از Supabase</title>
<body dir="rtl" style="font-family:sans-serif;max-width:760px;margin:2em auto">
<h2>انتقال از Supabase به هاست</h2>
<p>پیش‌شرط: <code>sql/schema.sql</code> را در phpMyAdmin روی دیتابیس خالی اجرا کرده باشید. این صفحه را بعد از پایان ببندید و فایل را پاک کنید.</p>
<button id="go" style="font-size:1.1em;padding:.5em 1.2em">شروع انتقال</button>
<pre id="log" style="background:#f4f4f4;padding:1em;white-space:pre-wrap"></pre>
<script>
const steps = $steps, key = "$k", log = document.getElementById('log');
document.getElementById('go').onclick = async () => {
  for (const s of steps) {
    let offset = 0, q = null;
    for (;;) {
      log.textContent += (offset ? '' : '▶ ' + s + ' … ');
      let r;
      try { r = await (await fetch('migrate.php?key=' + encodeURIComponent(key) + '&step=' + encodeURIComponent(s) + '&offset=' + offset + (q ? '&q=' + encodeURIComponent(JSON.stringify(q)) : ''))).json(); }
      catch (e) { log.textContent += '✗ ' + e + '\\n'; return; }
      if (!r.ok) { log.textContent += '✗ ' + r.error + '\\n'; return; }
      if (r.done) { log.textContent += '✓ ' + (r.msg || '') + '\\n'; break; }
      offset = r.offset; q = r.q || null; log.textContent += '… ' + (r.msg || '') + ' ';
    }
  }
  log.textContent += '\\nتمام شد. اکنون migrate را از config.php و این فایل را از هاست حذف کنید.\\n';
};
</script></body>
HTML;
