<?php
// php backend/tools/gen_schema_meta.php  → backend/lib/schema_meta.php
// از روی sql/schema.sql نوع ستون‌ها را درمی‌آورد تا API خروجی را درست تایپ کند (bool/int/json/ts).
$sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\)\s*ENGINE=/s', $sql, $m, PREG_SET_ORDER);
$out = [];
foreach ($m as $t) {
    [$_, $name, $body] = $t;
    $cols = []; $hidden = []; $pk = [];
    // خطوط را جدا کن؛ کاماهای داخل پرانتز (ENUM/CHECK/AS) مهم نیست چون هر تعریف در یک خط است
    foreach (preg_split('/\R/', $body) as $line) {
        $line = trim(preg_replace('/--.*$/', '', $line));
        $line = rtrim($line, ',');
        if ($line === '') continue;
        if (preg_match('/^PRIMARY KEY \((.+)\)/i', $line, $p)) { $pk = array_map(fn($x) => trim($x, '` '), explode(',', $p[1])); continue; }
        if (preg_match('/^(UNIQUE KEY|KEY|CONSTRAINT)\b/i', $line)) continue;
        if (!preg_match('/^`?(\w+)`?\s+([A-Za-z]+)(?:\(([^)]*)\))?(.*)$/', $line, $c)) continue;
        [$_, $col, $type, $arg, $rest] = $c;
        $type = strtoupper($type);
        $isGen = (bool) preg_match('/\sAS\s*\(/i', $rest);
        $k = match (true) {
            $type === 'TINYINT' && $arg === '1' => 'bool',
            in_array($type, ['INT', 'BIGINT', 'SMALLINT', 'TINYINT'], true) => 'int',
            $type === 'JSON' => 'json',
            $type === 'DATETIME' => 'ts',
            default => 'str',
        };
        $cols[$col] = $k;
        if ($isGen) $hidden[] = $col;
    }
    $uuidPk = count($pk) === 1 && $pk[0] === 'id' && preg_match('/^\s*`?id`?\s+CHAR\(36\)/mi', $body);
    $out[$name] = ['cols' => $cols, 'hidden' => $hidden, 'pk' => $pk, 'uuid_pk' => (bool) $uuidPk];
}
file_put_contents(__DIR__ . '/../lib/schema_meta.php', "<?php\n// تولید خودکار توسط tools/gen_schema_meta.php — دستی ویرایش نکنید.\nreturn " . var_export($out, true) . ";\n");
echo count($out), " tables\n";
