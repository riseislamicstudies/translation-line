<?php
declare(strict_types=1);
use Rise\{Access, ApiError, Ctx, Db, Rpc, Schema, Triggers};

// RPCهای گردش‌کار سند (ادعا، QA، انتشار، ترجمهٔ AI، ویرایش بخش‌ها).
// هر تغییر documents.* از Triggers::updateDocument می‌گذرد تا updated_at و تریگر تغییر وضعیت اجرا شود.

if (!class_exists('RiseWorkflowH')) {
    final class RiseWorkflowH
    {
        /** my_role() باید یکی از نقش‌ها باشد (سرویس بدون کاربر مثل Postgres 'none' است) */
        public static function need(string ...$roles): void
        {
            if (!in_array(Ctx::role(), $roles, true)) throw new ApiError('not_allowed', 403, '42501');
        }

        public static function fail(string $msg): never { throw new ApiError($msg, 400); }

        public static function lock(string $id): ?array
        {
            return Db::one('SELECT * FROM documents WHERE id = ? FOR UPDATE', [$id]);
        }

        /** ردیف سند به‌روز، مثل «returning *» */
        public static function ret(string $id): array
        {
            return Schema::castRow('documents', Db::one('SELECT * FROM documents WHERE id = ?', [$id]));
        }

        /** زمان فعلی (+ثانیه) به‌صورت ISO UTC برای updateDocument */
        public static function iso(int $off = 0): string
        {
            $t = microtime(true) + $off;
            return gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03dZ', (int) (($t - floor($t)) * 1000));
        }

        /** trim() پستگرس فقط فاصله را برمی‌دارد */
        public static function trim(mixed $s): string { return trim((string) $s, ' '); }

        public static function bool(mixed $v): bool { return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 't'; }

        public static function prioOk(mixed $p): bool { return is_numeric($p) && (int) $p >= 1 && (int) $p <= 5; }

        public static function arr(mixed $v): array
        {
            if (is_string($v)) $v = json_decode($v, true);
            return is_array($v) ? $v : [];
        }

        /** p_meta ->> key  (null اگر نبود/null بود) */
        public static function metaText(array $m, string $k): ?string
        {
            $v = $m[$k] ?? null;
            if ($v === null) return null;
            if (is_bool($v)) return $v ? 'true' : 'false';
            if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
            return (string) $v;
        }

        public static function bigint(?string $s): ?int
        {
            if ($s === null || $s === '') return null;
            if (!preg_match('/^\s*-?\d+\s*$/D', $s)) throw new ApiError('invalid input syntax for type bigint: "' . $s . '"', 400, '22P02');
            return (int) $s;
        }

        /** سند با شناسهٔ بخش (join segments) */
        public static function docOfSegment(string $segId): array
        {
            $d = Db::one('SELECT d.* FROM documents d JOIN segments s ON s.document_id = d.id WHERE s.id = ?', [$segId]);
            if (!$d) self::fail('segment_not_found');
            return $d;
        }

        /** شرط ویرایش بخش: مترجم مدعی یا سرپرست/ادمین در QA. src را برمی‌گرداند. */
        public static function editSource(array $d): ?string
        {
            $uid = Ctx::uid();
            if (in_array($d['status'], ['in_progress', 'changes_requested'], true) && $uid !== null && $d['claimed_by'] === $uid) return 'translator';
            if ($d['status'] === 'awaiting_qa' && in_array(Ctx::role(), ['supervisor', 'admin'], true)) return 'supervisor';
            return null;
        }

        public static function historyInsert(string $doc, int $prio): void
        {
            Db::exec('INSERT INTO priority_history (document_id, priority, changed_by) VALUES (?, ?, ?)', [$doc, $prio, Ctx::uid()]);
        }
    }
}

return [
    'apply_ai_review' => function (array $a) {
        $doc = Rpc::uuid(Rpc::arg($a, 'p_document'));
        $round = Rpc::arg($a, 'p_round');
        $round = $round === null ? null : (int) $round;
        $n = 0;
        foreach (RiseWorkflowH::arr($a['p_items'] ?? null) as $it) {
            if (!is_array($it)) continue;
            $pos = (int) ($it['pos'] ?? 0);
            $t = $it['text'] ?? null;
            $sug = $t === null ? '' : RiseWorkflowH::trim($t);
            $sug = $sug === '' ? null : $sug;
            $cur = Db::one('SELECT COALESCE(current_text, ai_text) AS c FROM segments WHERE document_id = ? AND `position` = ?', [$doc, $pos])['c'] ?? null;
            $norm = fn(string $s) => preg_replace('/\s+/', ' ', $s);
            if ($sug === null || $norm((string) $cur) === $norm($sug)) {
                Db::exec('UPDATE segments SET ai_review_text = NULL, ai_review_note = NULL, ai_review_round = ?, ai_review_at = UTC_TIMESTAMP(3) WHERE document_id = ? AND `position` = ?', [$round, $doc, $pos]);
            } else {
                $note = RiseWorkflowH::trim($it['note'] ?? '');
                $note = $note === '' ? null : mb_substr($note, 0, 600);
                Db::exec('UPDATE segments SET ai_review_text = ?, ai_review_note = ?, ai_review_round = ?, ai_review_at = UTC_TIMESTAMP(3) WHERE document_id = ? AND `position` = ?', [$sug, $note, $round, $doc, $pos]);
                $n++;
            }
        }
        return $n;
    },

    'apply_ai_translation' => function (array $a) {
        $id = Rpc::uuid(Rpc::arg($a, 'p_document_id'));
        $items = RiseWorkflowH::arr(Rpc::arg($a, 'p_items'));
        $d = RiseWorkflowH::lock($id);
        if (!$d) RiseWorkflowH::fail('document_not_found');
        if ($d['status'] !== 'translating') RiseWorkflowH::fail('wrong_status: ' . $d['status']);
        $n = (int) Db::val('SELECT COUNT(*) FROM segments WHERE document_id = ?', [$id]);
        if (count($items) !== $n) RiseWorkflowH::fail('count_mismatch');
        foreach ($items as $i) {
            $tr = $i['translation'] ?? null;
            Db::exec('UPDATE segments SET ai_text = ?, current_text = ? WHERE document_id = ? AND `position` = ?', [$tr, $tr, $id, (int) ($i['pos'] ?? 0)]);
        }
        // revision 0 = خروجی دست‌نخوردهٔ AI، برای مقایسهٔ کیفیت
        Db::exec("INSERT INTO segment_revisions (segment_id, document_id, revision_no, `text`, source, author_id)
                  SELECT id, document_id, 0, ai_text, 'ai', NULL FROM segments WHERE document_id = ?", [$id]);
        Triggers::updateDocument($id, [
            'status' => 'in_pool',
            'ai_model' => $a['p_model'] ?? null,
            'prompt_id' => isset($a['p_prompt_id']) ? Rpc::uuid($a['p_prompt_id']) : null,
            'ai_finished_at' => RiseWorkflowH::iso(),
            'ai_error' => null,
            'glossary' => RiseWorkflowH::arr($a['p_glossary'] ?? null),
            'ai_notes' => RiseWorkflowH::arr($a['p_notes'] ?? null),
        ]);
        return null;
    },

    'cancel_translation' => function (array $a) {
        RiseWorkflowH::need('reviewer', 'admin');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = RiseWorkflowH::lock($id);
        if (!$d || !in_array($d['status'], ['translating', 'translation_failed'], true)) RiseWorkflowH::fail('cannot_cancel');
        Triggers::updateDocument($id, ['status' => 'content_review', 'ai_started_at' => null, 'ai_error' => null]);
        return RiseWorkflowH::ret($id);
    },

    'claim_document' => function (array $a) {
        RiseWorkflowH::need('translator', 'admin');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $hours = isset($a['p_hours']) ? (int) $a['p_hours'] : 24;
        $uid = Ctx::uid();
        $active = (int) Db::val("SELECT COUNT(*) FROM documents WHERE claimed_by = ? AND status IN ('in_progress','changes_requested')", [$uid]);
        if ($active >= 3) RiseWorkflowH::fail('claim_limit_reached');
        $d = RiseWorkflowH::lock($id);
        if (!$d || $d['status'] !== 'in_pool' || ((int) $d['senior_only'] === 1 && !Access::canSeeSenior())) RiseWorkflowH::fail('already_claimed');
        Triggers::updateDocument($id, [
            'status' => 'in_progress', 'claimed_by' => $uid,
            'claimed_at' => RiseWorkflowH::iso(), 'claim_expires_at' => RiseWorkflowH::iso($hours * 3600),
        ]);
        return RiseWorkflowH::ret($id);
    },

    'delete_document' => function (array $a) {
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = Db::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$d) RiseWorkflowH::fail('document_not_found');
        $r = Ctx::role();
        $ok = $r === 'admin' || ($r === 'reviewer' && in_array($d['status'], ['content_review', 'review_rejected', 'translating', 'translation_failed'], true));
        if (!$ok) throw new ApiError('not_allowed', 403, '42501');
        Db::exec('DELETE FROM documents WHERE id = ?', [$id]); // segments, revisions, comments, notifications cascade
        return $d['source_path'];
    },

    'dismiss_ai_review' => function (array $a) {
        $seg = Rpc::uuid(Rpc::arg($a, 'p_segment'));
        $d = RiseWorkflowH::docOfSegment($seg);
        if (RiseWorkflowH::editSource($d) === null) throw new ApiError('not_editable', 403, '42501');
        Db::exec('UPDATE segments SET ai_review_text = NULL, ai_review_note = NULL WHERE id = ?', [$seg]);
        return null;
    },

    'flag_segment' => function (array $a) {
        $seg = Rpc::uuid(Rpc::arg($a, 'p_segment'));
        $flag = RiseWorkflowH::bool(Rpc::arg($a, 'p_flag'));
        $d = RiseWorkflowH::docOfSegment($seg);
        if (RiseWorkflowH::editSource($d) === null) throw new ApiError('not_editable', 403, '42501');
        Db::exec('UPDATE segments SET flagged = ? WHERE id = ?', [$flag ? 1 : 0, $seg]);
        return null;
    },

    'mark_published' => function (array $a) {
        RiseWorkflowH::need('admin', 'supervisor');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = RiseWorkflowH::lock($id);
        if (!$d || $d['status'] !== 'approved') RiseWorkflowH::fail('cannot_publish');
        Triggers::updateDocument($id, ['status' => 'published', 'published_by' => Ctx::uid(), 'published_at' => RiseWorkflowH::iso(), 'unpublish_note' => null]);
        return RiseWorkflowH::ret($id);
    },

    'mark_translation_failed' => function (array $a) {
        $id = Rpc::uuid(Rpc::arg($a, 'p_document_id'));
        $d = RiseWorkflowH::lock($id);
        if (!$d || $d['status'] !== 'translating') return null;
        $e = $a['p_error'] ?? null;
        Triggers::updateDocument($id, ['status' => 'translation_failed', 'ai_error' => $e === null ? null : mb_substr((string) $e, 0, 2000)]);
        return null;
    },

    'qa_decision' => function (array $a) {
        RiseWorkflowH::need('supervisor', 'admin');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $approve = RiseWorkflowH::bool(Rpc::arg($a, 'p_approve'));
        $note = $a['p_note'] ?? null;
        $prio = isset($a['p_priority']) ? $a['p_priority'] : null;
        if ($prio !== null && !RiseWorkflowH::prioOk($prio)) RiseWorkflowH::fail('priority_invalid');
        if (!$approve && ($note === null || strlen(RiseWorkflowH::trim($note)) === 0)) RiseWorkflowH::fail('note_required');
        $d = RiseWorkflowH::lock($id);
        if (!$d || $d['status'] !== 'awaiting_qa') RiseWorkflowH::fail('cannot_decide');
        $f = [
            'status' => $approve ? 'approved' : 'changes_requested',
            'supervisor_id' => Ctx::uid(),
            'qa_note' => $note,
            'claim_expires_at' => $approve ? null : RiseWorkflowH::iso(24 * 3600),
        ];
        if ($approve && $prio !== null) $f += ['priority' => (int) $prio, 'priority_by' => Ctx::uid(), 'priority_at' => RiseWorkflowH::iso()];
        Triggers::updateDocument($id, $f);
        if ($approve && $prio !== null) RiseWorkflowH::historyInsert($id, (int) $prio);
        return RiseWorkflowH::ret($id);
    },

    'release_document' => function (array $a) {
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = RiseWorkflowH::lock($id);
        $uid = Ctx::uid();
        if (!$d || $d['status'] !== 'in_progress' || !(($uid !== null && $d['claimed_by'] === $uid) || Ctx::role() === 'admin')) RiseWorkflowH::fail('cannot_release');
        Triggers::updateDocument($id, ['status' => 'in_pool', 'claimed_by' => null, 'claimed_at' => null, 'claim_expires_at' => null]);
        return RiseWorkflowH::ret($id);
    },

    'release_expired_claims' => function (array $a) {
        $ids = Db::all("SELECT id FROM documents WHERE status = 'in_progress' AND claim_expires_at < UTC_TIMESTAMP(3) FOR UPDATE");
        foreach ($ids as $r) {
            Triggers::updateDocument($r['id'], ['status' => 'in_pool', 'claimed_by' => null, 'claimed_at' => null, 'claim_expires_at' => null]);
        }
        return count($ids);
    },

    'resubmit_for_review' => function (array $a) {
        RiseWorkflowH::need('reviewer', 'admin');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = RiseWorkflowH::lock($id);
        if (!$d || $d['status'] !== 'review_rejected') RiseWorkflowH::fail('cannot_resubmit');
        Triggers::updateDocument($id, ['status' => 'content_review']);
        return RiseWorkflowH::ret($id);
    },

    'retry_translation' => function (array $a) {
        RiseWorkflowH::need('reviewer', 'admin');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = RiseWorkflowH::lock($id);
        if (!$d || !in_array($d['status'], ['translation_failed', 'translating'], true)) RiseWorkflowH::fail('cannot_retry');
        Triggers::updateDocument($id, ['status' => 'translating', 'ai_started_at' => null, 'ai_error' => null]);
        return RiseWorkflowH::ret($id);
    },

    'review_decision' => function (array $a) {
        RiseWorkflowH::need('reviewer', 'admin');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $approve = RiseWorkflowH::bool(Rpc::arg($a, 'p_approve'));
        $note = $a['p_note'] ?? null;
        $prio = $a['p_priority'] ?? null;
        if ($approve && ($prio === null || !RiseWorkflowH::prioOk($prio))) RiseWorkflowH::fail('priority_required');
        if (!$approve && ($note === null || strlen(RiseWorkflowH::trim($note)) === 0)) RiseWorkflowH::fail('note_required');
        $d = RiseWorkflowH::lock($id);
        if (!$d || $d['status'] !== 'content_review') RiseWorkflowH::fail('cannot_review');
        $f = ['reviewed_by' => Ctx::uid(), 'ai_started_at' => null, 'ai_error' => null];
        if ($approve) {
            $f += [
                'status' => 'translating', 'review_note' => null,
                'senior_only' => RiseWorkflowH::bool($a['p_senior'] ?? false),
                'priority' => (int) $prio, 'priority_by' => Ctx::uid(), 'priority_at' => RiseWorkflowH::iso(),
            ];
        } else {
            $f += [
                'status' => ($d['source_kind'] === 'news' && $d['news_edited_by'] !== null) ? 'news_edit' : 'review_rejected',
                'review_note' => RiseWorkflowH::trim($note),
            ];
        }
        Triggers::updateDocument($id, $f);
        if ($approve) RiseWorkflowH::historyInsert($id, (int) $prio);
        return RiseWorkflowH::ret($id);
    },

    'save_segment' => function (array $a) {
        $segId = Rpc::uuid(Rpc::arg($a, 'p_segment'));
        $text = Rpc::arg($a, 'p_text');
        $text = $text === null ? null : (string) $text;
        $secs = (int) ($a['p_seconds'] ?? 0);
        $s = Db::one('SELECT * FROM segments WHERE id = ? FOR UPDATE', [$segId]);
        if (!$s) RiseWorkflowH::fail('segment_not_found');
        $d = Db::one('SELECT * FROM documents WHERE id = ?', [$s['document_id']]);
        $src = RiseWorkflowH::editSource($d);
        if ($src === null) throw new ApiError('not_editable', 403, '42501');

        $last = Db::one('SELECT `text`, revision_no + 1 AS next_no FROM segment_revisions WHERE segment_id = ? ORDER BY revision_no DESC LIMIT 1', [$segId]);
        if ($text !== ($last['text'] ?? null)) {
            Db::exec('INSERT INTO segment_revisions (segment_id, document_id, revision_no, `text`, source, author_id) VALUES (?, ?, ?, ?, ?, ?)',
                [$segId, $s['document_id'], (int) ($last['next_no'] ?? 1), $text, $src, Ctx::uid()]);
            Db::exec('UPDATE segments SET current_text = ?, last_edited_by = ?, last_edited_at = ? WHERE id = ?', [$text, Ctx::uid(), Db::now(), $segId]);
        }
        Db::exec('UPDATE segments SET seconds_spent = seconds_spent + ? WHERE id = ?', [min(max($secs, 0), 3600), $segId]);
        return Schema::castRow('segments', Db::one('SELECT * FROM segments WHERE id = ?', [$segId]));
    },

    'set_ai_review_state' => function (array $a) {
        $id = Rpc::uuid(Rpc::arg($a, 'p_document'));
        $state = Rpc::arg($a, 'p_state');
        $err = $a['p_error'] ?? null;
        $f = ['ai_review_state' => $state, 'ai_review_error' => $err === null ? null : mb_substr((string) $err, 0, 500)];
        if ($state === 'running') $f['ai_review_at'] = RiseWorkflowH::iso();
        Triggers::updateDocument($id, $f);
        return null;
    },

    'set_priority' => function (array $a) {
        RiseWorkflowH::need('supervisor', 'admin');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $prio = $a['p_priority'] ?? null;
        if (!RiseWorkflowH::prioOk($prio)) RiseWorkflowH::fail('priority_invalid');
        $d = RiseWorkflowH::lock($id);
        if (!$d || !in_array($d['status'], ['awaiting_qa', 'approved'], true)) RiseWorkflowH::fail('cannot_set_priority');
        Triggers::updateDocument($id, ['priority' => (int) $prio, 'priority_by' => Ctx::uid(), 'priority_at' => RiseWorkflowH::iso()]);
        RiseWorkflowH::historyInsert($id, (int) $prio);
        return RiseWorkflowH::ret($id);
    },

    'set_publish_meta' => function (array $a) {
        $k = Ctx::role();
        RiseWorkflowH::need('admin', 'reviewer', 'supervisor', 'news_editor');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $m = RiseWorkflowH::arr(Rpc::arg($a, 'p_meta'));
        $d = Db::one('SELECT * FROM documents WHERE id = ?', [$id]);
        if (!$d || $d['status'] === 'published' || ($k === 'news_editor' && $d['source_kind'] !== 'news')) throw new ApiError('not_allowed', 403, '42501');

        $T = fn(string $key) => RiseWorkflowH::trim(RiseWorkflowH::metaText($m, $key) ?? '');
        $slug = strtolower($T('slug'));
        if ($slug === '') $slug = null;
        elseif (!preg_match('/^[a-z0-9][a-z0-9-]{0,190}$/D', $slug)) RiseWorkflowH::fail('invalid_slug');

        $tags = [];
        $rawTags = $m['tags'] ?? [];
        foreach (is_array($rawTags) ? $rawTags : [] as $x) {
            $t = RiseWorkflowH::trim(is_bool($x) ? ($x ? 'true' : 'false') : (is_array($x) ? json_encode($x, JSON_UNESCAPED_UNICODE) : $x));
            if ($t === '') continue;
            $t = mb_substr($t, 0, 50);
            if (!in_array($t, $tags, true)) $tags[] = $t;
            if (count($tags) >= 15) break;
        }

        $cat = RiseWorkflowH::bigint(RiseWorkflowH::metaText($m, 'category'));
        $atRaw = RiseWorkflowH::metaText($m, 'publish_at');
        $at = null;
        if ($atRaw !== null && $atRaw !== '') {
            try { $at = (new DateTimeImmutable($atRaw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'); }
            catch (Throwable) { throw new ApiError('invalid input syntax for type timestamp with time zone: "' . $atRaw . '"', 400, '22007'); }
        }
        $lim = fn(string $key, int $n) => ($v = mb_substr($T($key), 0, $n)) === '' ? null : $v;

        $f = [
            'pub_slug' => $slug,
            'pub_excerpt' => $lim('excerpt', 600),
            'pub_tags' => $tags,
            'pub_category' => $cat,
            'pub_seo_title' => $lim('seo_title', 120),
            'pub_seo_desc' => $lim('seo_desc', 320),
            'pub_seo_focus' => $lim('seo_focus', 100),
            'pub_at' => $at,
        ];
        if (array_key_exists('author', $m)) $f['pub_author'] = $lim('author', 120);
        if (array_key_exists('author_id', $m)) $f['pub_author_id'] = RiseWorkflowH::bigint(RiseWorkflowH::metaText($m, 'author_id'));
        Triggers::updateDocument($id, $f);
        return null;
    },

    'set_publish_title' => function (array $a) {
        RiseWorkflowH::need('admin', 'supervisor');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = Db::one('SELECT status FROM documents WHERE id = ?', [$id]);
        if (!$d || !in_array($d['status'], ['approved', 'published'], true)) return null;
        $t = mb_substr(RiseWorkflowH::trim($a['p_title'] ?? ''), 0, 300);
        Triggers::updateDocument($id, ['publish_title' => $t === '' ? null : $t]);
        return null;
    },

    'set_senior_only' => function (array $a) {
        RiseWorkflowH::need('admin', 'reviewer', 'supervisor');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = RiseWorkflowH::lock($id);
        if (!$d || !in_array($d['status'], ['content_review', 'translating', 'in_pool'], true)) RiseWorkflowH::fail('cannot_change');
        Triggers::updateDocument($id, ['senior_only' => RiseWorkflowH::bool($a['p_val'] ?? false)]);
        return RiseWorkflowH::ret($id);
    },

    'set_translator_level' => function (array $a) {
        RiseWorkflowH::need('admin');
        $user = Rpc::uuid(Rpc::arg($a, 'p_user'));
        $level = Rpc::arg($a, 'p_level');
        if (!in_array($level, ['regular', 'senior'], true)) RiseWorkflowH::fail('invalid_input');
        Db::exec('INSERT INTO translator_levels (user_id, level) VALUES (?, ?) ON DUPLICATE KEY UPDATE level = VALUES(level), updated_at = UTC_TIMESTAMP(3)', [$user, $level]);
        return null;
    },

    'submit_to_qa' => function (array $a) {
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = RiseWorkflowH::lock($id);
        $uid = Ctx::uid();
        if (!$d || !in_array($d['status'], ['in_progress', 'changes_requested'], true) || $uid === null || $d['claimed_by'] !== $uid) RiseWorkflowH::fail('cannot_submit');
        Triggers::updateDocument($id, ['status' => 'awaiting_qa', 'claim_expires_at' => null]);
        return RiseWorkflowH::ret($id);
    },

    'unpublish_document' => function (array $a) {
        RiseWorkflowH::need('admin', 'supervisor');
        $id = Rpc::uuid(Rpc::arg($a, 'p_doc'));
        $d = RiseWorkflowH::lock($id);
        if (!$d || $d['status'] !== 'published') RiseWorkflowH::fail('cannot_unpublish');
        $n = RiseWorkflowH::trim($a['p_note'] ?? '');
        Triggers::updateDocument($id, ['status' => 'approved', 'published_by' => null, 'published_at' => null, 'unpublish_note' => $n === '' ? null : $n]);
        return RiseWorkflowH::ret($id);
    },
];
