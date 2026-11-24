<?php
declare(strict_types=1);
use Rise\{Access, ApiError, Ctx, Db, Notify, Rpc, Schema, Triggers};

// RPCهای محتوا: نظرها، تصاویر، دعوت‌نامه‌ها، واژه‌نامه، اخبار، نوتیف و نویسنده (writer).
// هر تغییر documents.* از Triggers::updateDocument می‌گذرد (updated_at + تریگر تغییر وضعیت).

if (!class_exists('RiseContentH')) {
    final class RiseContentH
    {
        public static function fail(string $msg): never { throw new ApiError($msg, 400); }
        public static function denied(string $msg = 'not_allowed'): never { throw new ApiError($msg, 403, '42501'); }

        /** trim()/btrim() پستگرس فقط فاصله را برمی‌دارد */
        public static function trim(mixed $s): string { return trim((string) $s, ' '); }

        public static function bool(mixed $v, bool $nullAs = false): bool
        {
            if ($v === null) return $nullAs;
            return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 't';
        }

        /** left(s, n) */
        public static function left(?string $s, int $n): ?string { return $s === null ? null : mb_substr($s, 0, $n); }

        /** nullif(left(trim(coalesce(s,'')), n), '') */
        public static function clean(mixed $s, int $n): ?string
        {
            $v = mb_substr(self::trim($s ?? ''), 0, $n);
            return $v === '' ? null : $v;
        }

        public static function arr(mixed $v): ?array
        {
            if (is_string($v)) $v = json_decode($v, true);
            return is_array($v) ? $v : null;
        }

        public static function iso(int $off = 0): string { return gmdate('Y-m-d\TH:i:s', time() + $off) . '.000Z'; }

        /** مقدار ->> به صورت متن (null اگر نبود) */
        public static function text(mixed $e, string $k): ?string
        {
            if (!is_array($e) || !array_key_exists($k, $e) || $e[$k] === null) return null;
            $v = $e[$k];
            if (is_bool($v)) return $v ? 'true' : 'false';
            if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
            return (string) $v;
        }

        /** تعداد کلمات: sum(array_length(regexp_split_to_array(trim(text), '\s+'), 1)) */
        public static function words(array $items): int
        {
            $wc = 0;
            foreach ($items as $e) {
                $t = self::text($e, 'text');
                if ($t !== null) $wc += count(preg_split('/\s+/u', self::trim($t)) ?: ['']);
            }
            return $wc;
        }

        /** درج segments از آرایهٔ {kind,text} (ordinality از ۱) */
        public static function insertSegments(string $doc, array $items): void
        {
            $pos = 0;
            foreach ($items as $e) {
                $pos++;
                $kind = self::text($e, 'kind') ?? 'paragraph';
                if (!in_array($kind, ['heading', 'paragraph', 'list_item', 'footnote', 'quote'], true)) {
                    throw new ApiError('invalid input value for enum segment_kind: "' . $kind . '"', 400, '22P02');
                }
                $text = self::text($e, 'text');
                if ($text === null) throw new ApiError('null value in column "source_text" of relation "segments" violates not-null constraint', 400, '23502');
                $lv = self::text($e, 'level');
                Db::insert('segments', ['id' => Db::uuid(), 'document_id' => $doc, 'position' => $pos, 'kind' => $kind, 'source_text' => $text, 'level' => ($lv !== null && ctype_digit($lv) && (int) $lv >= 1 && (int) $lv <= 6) ? (int) $lv : null]);
            }
        }

        public static function image(string $id): ?array
        {
            return Db::one('SELECT * FROM document_images WHERE id = ?', [$id]);
        }

        /** حذف سند خبری و آزادکردن news_item مربوط با دلیل رد */
        public static function dropNewsDoc(string $doc, ?string $reason): void
        {
            Db::exec("UPDATE news_items SET status = 'rejected', reason = ?, document_id = NULL WHERE document_id = ?", [$reason, $doc]);
            Db::exec('DELETE FROM documents WHERE id = ?', [$doc]);
        }

        public static function isNewsEdit(string $doc): bool
        {
            return Db::val("SELECT 1 FROM documents WHERE id = ? AND status = 'news_edit' AND source_kind = 'news'", [$doc]) !== null;
        }

        public static function needWriter(): void
        {
            if (!in_array(Ctx::role(), ['writer', 'journal_writer'], true)) self::denied();
        }
    }
}

return [
    // ---------- نظرها ----------
    'add_comments_batch' => function (array $a): int {
        $H = 'RiseContentH';
        $segs = $H::arr($a['p_segments'] ?? null);
        $body = $a['p_body'] ?? null;
        if ($segs === null || count($segs) < 1 || count($segs) > 500) $H::fail('invalid_input');
        if ($body === null || $H::trim($body) === '' || mb_strlen((string) $body) > 4000) $H::fail('invalid_input');
        $ids = array_map(fn($s) => Rpc::uuid($s), array_values($segs));
        $in = Db::in($ids);
        $rows = Db::all("SELECT id, document_id, `position` FROM segments WHERE id IN $in", $ids);
        if (count(array_unique(array_column($rows, 'document_id'))) !== 1) $H::fail('invalid_input');
        if (count($rows) !== count($ids)) $H::fail('invalid_input');
        $did = $rows[0]['document_id'];
        if (!Access::canViewDoc($did)) $H::denied();
        $min = min(array_map(fn($r) => (int) $r['position'], $rows));
        $batch = Db::uuid();
        foreach ($rows as $r) {
            $cid = Db::uuid();
            Db::insert('segment_comments', [
                'id' => $cid, 'segment_id' => $r['id'], 'document_id' => $did, 'author_id' => Ctx::uid(),
                'body' => $H::trim($body), 'batch_id' => $batch, 'batch_lead' => (int) $r['position'] === $min ? 1 : 0,
            ]);
            Triggers::onCommentInsert(Db::one('SELECT * FROM segment_comments WHERE id = ?', [$cid]));
        }
        return count($ids);
    },

    'resolve_comment' => function (array $a) {
        $id = Rpc::uuid(Rpc::arg($a, 'p_comment'));
        $did = Db::val('SELECT document_id FROM segment_comments WHERE id = ?', [$id]);
        if ($did === null) RiseContentH::fail('comment_not_found');
        if (!Access::canViewDoc($did)) RiseContentH::denied();
        Db::exec('UPDATE segment_comments SET resolved = ? WHERE id = ?', [RiseContentH::bool($a['p_resolved'] ?? null, true), $id]);
        return null;
    },

    // ---------- تصاویر ----------
    'attach_image' => function (array $a): array {
        $H = 'RiseContentH';
        $doc = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $path = $a['p_path'] ?? null; $mime = $a['p_mime'] ?? null; $size = $a['p_size'] ?? null;
        $isWriter = in_array(Ctx::role(), ['writer', 'journal_writer'], true);
        if (!Access::canAttachImages($doc)) $H::denied();
        if (!is_string($path) || !preg_match('#^' . preg_quote($doc, '#') . '/[0-9a-f-]{36}\.(jpg|png|webp)$#D', $path)) $H::fail('invalid_input');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !is_numeric($size) || (int) $size < 1 || (int) $size > 6291456) $H::fail('invalid_input');
        if ((int) Db::val('SELECT COUNT(*) FROM document_images WHERE document_id = ?', [$doc]) >= 12) $H::fail('too_many_images');
        $hasFeatured = Db::val("SELECT 1 FROM document_images WHERE document_id = ? AND kind = 'featured'", [$doc]) !== null;
        $id = Db::uuid();
        $now = Db::now();
        Db::insert('document_images', [
            'id' => $id, 'document_id' => $doc, 'path' => $path,
            'filename' => $H::left(isset($a['p_filename']) ? (string) $a['p_filename'] : null, 200),
            'mime' => $mime, 'size_bytes' => (int) $size, 'kind' => $hasFeatured ? 'inline' : 'featured',
            'alt_text' => $H::clean($a['p_alt'] ?? null, 300),
            'after_position' => isset($a['p_after']) ? (int) $a['p_after'] : null,
            'uploaded_by' => Ctx::uid(), 'approved' => $isWriter ? 0 : 1,
            'approved_by' => $isWriter ? null : Ctx::uid(),
            'approved_at' => $isWriter ? null : Schema::iso($now),
        ]);
        return Schema::castRow('document_images', $H::image($id));
    },

    'remove_image' => function (array $a) {
        $H = 'RiseContentH';
        $r = $H::image(Rpc::uuid(Rpc::arg($a, 'p_id')));
        if (!$r || !Access::canAttachImages($r['document_id'])) $H::denied();
        if (in_array(Ctx::role(), ['writer', 'journal_writer'], true) && $r['uploaded_by'] !== Ctx::uid()) $H::denied();
        Db::exec('DELETE FROM document_images WHERE id = ?', [$r['id']]);
        if ($r['kind'] === 'featured') {
            $next = Db::val('SELECT id FROM document_images WHERE document_id = ? AND approved = 1 ORDER BY created_at LIMIT 1', [$r['document_id']]);
            if ($next !== null) Db::exec("UPDATE document_images SET kind = 'featured' WHERE id = ?", [$next]);
        }
        return $r['path'];
    },

    'review_image' => function (array $a) {
        Ctx::need('reviewer', 'admin');
        $id = Rpc::uuid(Rpc::arg($a, 'p_id'));
        $r = RiseContentH::image($id);
        if (!$r) RiseContentH::fail('not_found');
        if (RiseContentH::bool($a['p_ok'] ?? null)) {
            Db::exec('UPDATE document_images SET approved = 1, approved_by = ?, approved_at = ? WHERE id = ?', [Ctx::uid(), Db::now(), $id]);
            return null;
        }
        Db::exec('DELETE FROM document_images WHERE id = ?', [$id]);
        return $r['path'];
    },

    'update_image' => function (array $a) {
        $H = 'RiseContentH';
        $id = Rpc::uuid(Rpc::arg($a, 'p_id'));
        $d = Db::val('SELECT document_id FROM document_images WHERE id = ?', [$id]);
        if ($d === null || !Access::canAttachImages($d)) $H::denied();
        $after = $a['p_after'] ?? null;
        Db::exec('UPDATE document_images SET alt_text = ?, caption = ?, after_position = ? WHERE id = ?',
            [$H::clean($a['p_alt'] ?? null, 300), $H::clean($a['p_caption'] ?? null, 500), $after === null ? null : (int) $after, $id]);
        return null;
    },

    'set_featured_image' => function (array $a) {
        $id = Rpc::uuid(Rpc::arg($a, 'p_id'));
        $d = Db::val('SELECT document_id FROM document_images WHERE id = ?', [$id]);
        if ($d === null || !Access::canAttachImages($d)) RiseContentH::denied();
        Db::exec("UPDATE document_images SET kind = 'inline' WHERE document_id = ? AND kind = 'featured' AND id <> ?", [$d, $id]);
        Db::exec("UPDATE document_images SET kind = 'featured' WHERE id = ?", [$id]);
        return null;
    },

    // ---------- دعوت‌نامه‌ها ----------
    'create_invite' => function (array $a): array {
        Ctx::need('admin');
        $days = $a['p_days'] ?? null; $max = $a['p_max'] ?? null;
        $id = Db::uuid();
        Db::insert('invites', [
            'id' => $id, 'token' => bin2hex(random_bytes(18)),
            'label' => RiseContentH::clean($a['p_label'] ?? null, 80),
            'created_by' => Ctx::uid(),
            'expires_at' => ($days === null || (int) $days <= 0) ? null : RiseContentH::iso((int) $days * 86400),
            'max_uses' => ($max === null || (int) $max <= 0) ? null : (int) $max,
        ]);
        return Schema::castRow('invites', Db::one('SELECT * FROM invites WHERE id = ?', [$id]));
    },

    'revoke_invite' => function (array $a) {
        Ctx::need('admin');
        Db::exec('UPDATE invites SET revoked = ? WHERE id = ?',
            [RiseContentH::bool($a['p_revoked'] ?? null, true), Rpc::uuid(Rpc::arg($a, 'p_id'))]);
        return null;
    },

    'consume_invite' => function (array $a): bool {
        $n = Db::exec('UPDATE invites SET uses = uses + 1 WHERE token = ? AND revoked = 0
                       AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP(3)) AND (max_uses IS NULL OR uses < max_uses)',
            [(string) Rpc::arg($a, 'p_token')]);
        return $n === 1;
    },

    'release_invite' => function (array $a) {
        Db::exec('UPDATE invites SET uses = GREATEST(CAST(uses AS SIGNED) - 1, 0) WHERE token = ?', [(string) Rpc::arg($a, 'p_token')]);
        return null;
    },

    'invite_is_valid' => function (array $a): bool {
        return Db::val('SELECT 1 FROM invites WHERE token = ? AND revoked = 0
                        AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP(3)) AND (max_uses IS NULL OR uses < max_uses)',
            [(string) Rpc::arg($a, 'p_token')]) !== null;
    },

    // ---------- واژه‌نامه ----------
    'glossary_import' => function (array $a): array {
        $H = 'RiseContentH';
        if (Ctx::role() !== 'admin') $H::denied('forbidden');
        $rows = $H::arr(Rpc::arg($a, 'p_rows'));
        if ($rows === null || !array_is_list($rows)) $H::fail('rows_must_be_array');
        $added = $updated = $skipped = 0;
        foreach ($rows as $r) {
            $src = $H::trim($H::text($r, 'source') ?? '');
            $tgt = $H::trim($H::text($r, 'target') ?? '');
            if ($src === '' || $tgt === '') { $skipped++; continue; }
            $note = $H::trim($H::text($r, 'note') ?? '');
            $id = Db::val('SELECT id FROM glossary WHERE source_key = MD5(LOWER(TRIM(?)))', [$src]);
            if ($id !== null) {
                Db::exec("UPDATE glossary SET target_term = ?, note = COALESCE(NULLIF(?, ''), note), is_active = 1, updated_at = ? WHERE id = ?",
                    [$tgt, $note, Db::now(), $id]);
                $updated++;
            } else {
                Db::insert('glossary', ['id' => Db::uuid(), 'source_term' => $src, 'target_term' => $tgt,
                    'note' => $note === '' ? null : $note, 'created_by' => Ctx::uid()]);
                $added++;
            }
        }
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
    },

    // ---------- اخبار ----------
    'news_accept' => function (array $a): array {
        $H = 'RiseContentH';
        $p = $H::arr($a['p_paragraphs'] ?? null);
        if ($p === null || !array_is_list($p) || count($p) < 1 || count($p) > 2000) $H::fail('invalid_input');
        $source = isset($a['p_source']) ? Rpc::uuid($a['p_source']) : null;
        $url = (string) Rpc::arg($a, 'p_url');
        $title = $a['p_title'] ?? null;
        $pub = isset($a['p_published']) ? Schema::fromIso($a['p_published']) : null;
        $item = Db::uuid();
        $ins = Db::exec("INSERT INTO news_items (id, source_id, url, url_hash, title, status, published_at) VALUES (?, ?, ?, ?, ?, 'accepted', ?)
                         ON DUPLICATE KEY UPDATE url_hash = url_hash",
            [$item, $source, $url, (string) Rpc::arg($a, 'p_hash'), $H::left($title === null ? null : (string) $title, 300), $pub]);
        if ($ins < 1) return ['result' => 'duplicate'];
        if (Db::val('SELECT 1 FROM documents WHERE source_url_key = MD5(?)', [$url]) !== null) {
            Db::exec("UPDATE news_items SET status = 'rejected', reason = 'duplicate' WHERE id = ?", [$item]);
            return ['result' => 'duplicate'];
        }
        $sname = $source === null ? null : Db::val('SELECT name FROM news_sources WHERE id = ?', [$source]);
        $doc = Db::uuid();
        Db::insert('documents', [
            'id' => $doc, 'title' => $H::left($H::trim($title ?? ''), 300), 'word_count' => $H::words($p),
            'uploaded_by' => null, 'source_kind' => 'news', 'source_url' => $url,
            'source_name' => $sname ?? 'خبر', 'source_published_at' => $pub, 'status' => 'news_edit',
        ]);
        $H::insertSegments($doc, $p);
        Db::exec('UPDATE news_items SET document_id = ? WHERE id = ?', [$doc, $item]);
        return ['result' => 'accepted', 'document_id' => $doc];
    },

    'news_curate_apply' => function (array $a) {
        $doc = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        if (!RiseContentH::isNewsEdit($doc)) return null;
        $score = $a['p_score'] ?? null;
        $reason = isset($a['p_reason']) ? (string) $a['p_reason'] : null;
        if (RiseContentH::bool($a['p_discard'] ?? null)) {
            $msg = $score === null ? null : RiseContentH::left('گلچین هوشمند (نمره ' . (int) $score . '): ' . ($reason ?? ''), 300);
            RiseContentH::dropNewsDoc($doc, $msg);
        } else {
            // greatest(0, least(100, null)) در پستگرس = ۱۰۰ (null نادیده گرفته می‌شود)
            Triggers::updateDocument($doc, [
                'news_score' => $score === null ? 100 : max(0, min(100, (int) $score)),
                'news_score_reason' => RiseContentH::left($reason, 300),
                'news_curated_at' => RiseContentH::iso(),
            ]);
        }
        return null;
    },

    'news_discard' => function (array $a) {
        $H = 'RiseContentH';
        Ctx::need('news_editor', 'admin');
        $doc = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $reason = $a['p_reason'] ?? null;
        if ($reason === null || $H::trim($reason) === '') $H::fail('note_required');
        if (!$H::isNewsEdit($doc)) $H::fail('cannot_review');
        $H::dropNewsDoc($doc, $H::left('رد توسط ویراستار: ' . $H::trim($reason), 300));
        return null;
    },

    'news_notify' => function (array $a) {
        Notify::newsBatch((int) Rpc::arg($a, 'p_count'));
        return null;
    },

    'news_reject' => function (array $a) {
        $H = 'RiseContentH';
        $title = $a['p_title'] ?? null; $reason = $a['p_reason'] ?? null;
        Db::exec('INSERT INTO news_items (id, source_id, url, url_hash, title, status, reason, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE url_hash = url_hash',
            [Db::uuid(), isset($a['p_source']) ? Rpc::uuid($a['p_source']) : null, (string) Rpc::arg($a, 'p_url'), (string) Rpc::arg($a, 'p_hash'),
             $H::left($title === null ? null : (string) $title, 300), ($a['p_status'] ?? null) === 'error' ? 'error' : 'rejected',
             $H::left($reason === null ? null : (string) $reason, 300), isset($a['p_published']) ? Schema::fromIso($a['p_published']) : null]);
        return null;
    },

    'news_reset_rejected' => function (array $a): int {
        Ctx::need('admin', 'news_editor');
        $src = isset($a['p_source']) ? Rpc::uuid($a['p_source']) : null;
        return Db::exec("DELETE FROM news_items WHERE status IN ('rejected', 'error') AND COALESCE(reason, '') <> 'duplicate'
                         AND (? IS NULL OR source_id = ?)", [$src, $src]);
    },

    'news_submit_to_review' => function (array $a): array {
        $H = 'RiseContentH';
        Ctx::need('news_editor', 'admin');
        $doc = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        if (Db::val('SELECT 1 FROM segments WHERE document_id = ? LIMIT 1', [$doc]) === null) $H::fail('empty_document');
        if (!$H::isNewsEdit($doc)) $H::fail('cannot_review');
        $note = $H::trim($a['p_note'] ?? '');
        Triggers::updateDocument($doc, [
            'status' => 'content_review', 'editor_note' => $note === '' ? null : $note,
            'news_edited_by' => Ctx::uid(), 'news_edited_at' => $H::iso(), 'review_note' => null,
        ]);
        return Schema::castRow('documents', Db::one('SELECT * FROM documents WHERE id = ?', [$doc]));
    },

    // ---------- نوتیف ----------
    'notify_user' => function (array $a) {
        Notify::user(isset($a['p_user']) ? Rpc::uuid($a['p_user']) : null, (string) Rpc::arg($a, 'p_type'),
            isset($a['p_doc']) ? Rpc::uuid($a['p_doc']) : null, RiseContentH::arr($a['p_payload'] ?? null) ?? []);
        return null;
    },

    'notify_role' => function (array $a) {
        Notify::role((string) Rpc::arg($a, 'p_role'), (string) Rpc::arg($a, 'p_type'),
            isset($a['p_doc']) ? Rpc::uuid($a['p_doc']) : null, RiseContentH::arr($a['p_payload'] ?? null) ?? [],
            isset($a['p_exclude']) ? Rpc::uuid($a['p_exclude']) : null);
        return null;
    },

    'notify_translators' => function (array $a) {
        Notify::translators((string) Rpc::arg($a, 'p_type'), isset($a['p_doc']) ? Rpc::uuid($a['p_doc']) : null,
            RiseContentH::arr($a['p_payload'] ?? null) ?? [], RiseContentH::bool($a['p_senior'] ?? null),
            isset($a['p_exclude']) ? Rpc::uuid($a['p_exclude']) : null);
        return null;
    },

    // ---------- نویسنده (writer) ----------
    'writer_document_text' => function (array $a): array {
        RiseContentH::needWriter();
        $doc = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        if (Db::val('SELECT 1 FROM documents WHERE id = ? AND uploaded_by <=> ?', [$doc, Ctx::uid()]) === null || Ctx::uid() === null) RiseContentH::denied();
        $rows = Db::all('SELECT `position`, kind, source_text FROM segments WHERE document_id = ? ORDER BY `position`', [$doc]);
        return array_map(fn($r) => ['position' => (int) $r['position'], 'kind' => $r['kind'], 'source_text' => $r['source_text']], $rows);
    },

    'writer_my_documents' => function (array $a): array {
        RiseContentH::needWriter();
        $rows = Db::all("SELECT id, title, source_filename, word_count, status, created_at,
                                CASE WHEN status = 'review_rejected' THEN review_note END AS review_note, replaced_by
                           FROM documents WHERE uploaded_by = ? ORDER BY created_at DESC LIMIT 200", [Ctx::uid()]);
        return array_map(fn($r) => [
            'id' => $r['id'], 'title' => $r['title'], 'source_filename' => $r['source_filename'], 'word_count' => (int) $r['word_count'],
            'status' => $r['status'], 'created_at' => Schema::iso((string) $r['created_at']),
            'review_note' => $r['review_note'], 'replaced_by' => $r['replaced_by'],
        ], $rows);
    },

    'writer_returned_feedback' => function (array $a): array {
        RiseContentH::needWriter();
        $doc = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = Db::one('SELECT status, review_note FROM documents WHERE id = ? AND uploaded_by = ?', [$doc, Ctx::uid()]);
        if (!$d || $d['status'] !== 'review_rejected') RiseContentH::denied();
        $segs = [];
        foreach (Db::all('SELECT id, `position`, kind, source_text FROM segments WHERE document_id = ? ORDER BY `position`', [$doc]) as $s) {
            $comments = Db::all('SELECT c.body, c.created_at, (SELECT full_name FROM profiles WHERE id = c.author_id) AS by_name
                                   FROM segment_comments c WHERE c.segment_id = ? ORDER BY c.created_at', [$s['id']]);
            $segs[] = [
                'position' => (int) $s['position'], 'kind' => $s['kind'], 'text' => $s['source_text'],
                'comments' => array_map(fn($c) => ['body' => $c['body'], 'at' => Schema::iso((string) $c['created_at']), 'by' => $c['by_name']], $comments),
            ];
        }
        return ['note' => $d['review_note'], 'segments' => $segs];
    },

    'writer_submit' => function (array $a): string {
        $H = 'RiseContentH';
        $H::needWriter();
        $title = $a['p_title'] ?? null;
        if (($a['p_id'] ?? null) === null || $title === null || $H::trim($title) === '') $H::fail('invalid_input');
        $id = Rpc::uuid($a['p_id']);
        $path = $a['p_path'] ?? null;
        if ($path !== null && $path !== $id . '/source.docx') $H::fail('invalid_input');
        $segs = $H::arr($a['p_segments'] ?? null);
        if ($segs === null || !array_is_list($segs) || count($segs) < 1 || count($segs) > 2000) $H::fail('invalid_input');
        foreach ($segs as $e) {
            $t = is_array($e) && !array_is_list($e) ? ($H::text($e, 'text') ?? '') : '';
            if ($t === '' || mb_strlen($t) > 30000 || !in_array($H::text($e, 'kind') ?? 'paragraph', ['heading', 'paragraph', 'list_item', 'footnote', 'quote'], true)) $H::fail('invalid_input');
        }
        $uid = Ctx::uid();
        if ((int) Db::val('SELECT COUNT(*) FROM documents WHERE uploaded_by = ? AND created_at > UTC_TIMESTAMP(3) - INTERVAL 1 HOUR', [$uid]) >= 20) $H::fail('rate_limited');
        $rep = isset($a['p_replaces']) ? Rpc::uuid($a['p_replaces']) : null;
        if ($rep !== null && Db::val("SELECT 1 FROM documents WHERE id = ? AND uploaded_by = ? AND status = 'review_rejected' AND replaced_by IS NULL", [$rep, $uid]) === null) {
            $H::fail('cannot_replace');
        }
        $fn = $a['p_filename'] ?? null;
        Db::insert('documents', [
            'id' => $id, 'title' => $H::left($H::trim($title), 300), 'source_filename' => $H::left($fn === null ? null : (string) $fn, 300),
            'source_path' => $path, 'word_count' => $H::words($segs), 'uploaded_by' => $uid,
            'source_kind' => Ctx::role() === 'journal_writer' ? 'journal' : 'scholaria',
        ]);
        $H::insertSegments($id, $segs);
        if ($rep !== null) Triggers::updateDocument($rep, ['replaced_by' => $id]);
        Notify::role('reviewer', 'needs_review', $id, ['title' => $H::left($H::trim($title), 300), 'by' => Db::val('SELECT full_name FROM profiles WHERE id = ?', [$uid])]);
        return $id;
    },
];
