<?php
declare(strict_types=1);
namespace Rise;

/** نماهای segment_quality / document_quality / translator_quality (در Postgres view بودند؛ اینجا در PHP محاسبه می‌شوند). */
final class Quality
{
    private static function words(?string $s): array
    {
        $s = trim((string) $s);
        return $s === '' ? [] : preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    }

    /** word_edit_distance(a, b): فاصلهٔ ویرایش (Levenshtein) روی کلمه‌ها */
    public static function wordEditDistance(?string $a, ?string $b): int
    {
        $wa = self::words($a); $wb = self::words($b);
        $n = count($wa); $m = count($wb);
        if ($n === 0) return $m;
        if ($m === 0) return $n;
        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $wa[$i - 1] === $wb[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }
        return $prev[$m];
    }

    public static function documents(): array
    {
        $docs = Db::all("SELECT id, title, status, claimed_by, ai_model, prompt_id FROM documents WHERE status IN ('awaiting_qa','approved','published')");
        $out = [];
        foreach ($docs as $d) {
            $segs = Db::all('SELECT ai_text, current_text, seconds_spent FROM segments WHERE document_id = ? AND ai_text IS NOT NULL', [$d['id']]);
            if (!$segs) continue;
            $unchanged = 0; $edit = 0; $final = 0; $secs = 0;
            foreach ($segs as $s) {
                if ($s['current_text'] === $s['ai_text']) $unchanged++;
                $edit += self::wordEditDistance($s['ai_text'], $s['current_text']);
                $final += max(count(self::words($s['current_text'])), 1);
                $secs += (int) $s['seconds_spent'];
            }
            $n = count($segs);
            $out[] = [
                'document_id' => $d['id'], 'title' => $d['title'], 'status' => $d['status'],
                'translator_id' => $d['claimed_by'], 'ai_model' => $d['ai_model'], 'prompt_id' => $d['prompt_id'],
                'segments' => $n,
                'unchanged_pct' => round(100.0 * $unchanged / $n, 1),
                'ter' => $final > 0 ? round($edit / $final, 3) : null,
                'seconds_total' => $secs,
            ];
        }
        return $out;
    }

    public static function translators(): array
    {
        $by = [];
        foreach (self::documents() as $d) {
            if ($d['translator_id'] === null) continue;
            $by[$d['translator_id']][] = $d;
        }
        $out = [];
        foreach ($by as $tid => $ds) {
            $name = Db::val('SELECT full_name FROM profiles WHERE id = ?', [$tid]);
            if ($name === null) continue; // join با profiles
            $ters = array_values(array_filter(array_column($ds, 'ter'), fn($x) => $x !== null));
            $out[] = [
                'translator_id' => $tid, 'full_name' => $name, 'documents' => count($ds),
                'avg_unchanged_pct' => round(array_sum(array_column($ds, 'unchanged_pct')) / count($ds), 1),
                'avg_ter' => $ters ? round(array_sum($ters) / count($ters), 3) : null,
                'seconds_total' => array_sum(array_column($ds, 'seconds_total')),
            ];
        }
        return $out;
    }
}
