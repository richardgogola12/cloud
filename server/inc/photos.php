<?php
/* Fotky: albumy, „v tento deň“. (Poloha z fotiek sa nezisťuje ani neukladá.) */

/* ---------- albumy ---------- */
function album_get(int $uid, int $id): array {
    $a = q('SELECT * FROM ' . P . 'albums WHERE id = ? AND user_id = ?', [$id, $uid])->fetch();
    if (!$a) throw new RuntimeException('Album neexistuje.');
    return $a;
}
function albums_list(int $uid): array {
    $rows = q('SELECT a.*, (SELECT COUNT(*) FROM ' . P . 'album_items ai JOIN ' . P . 'files f ON f.id = ai.file_id AND f.deleted_at IS NULL WHERE ai.album_id = a.id) cnt,
                      COALESCE(a.cover_id, (SELECT ai.file_id FROM ' . P . 'album_items ai JOIN ' . P . 'files f ON f.id = ai.file_id AND f.deleted_at IS NULL
                                            WHERE ai.album_id = a.id ORDER BY COALESCE(f.taken_at, f.created_at) DESC LIMIT 1)) cover
               FROM ' . P . 'albums a WHERE a.user_id = ? ORDER BY a.updated_at DESC', [$uid])->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $cv = $r['cover'] ? q('SELECT id, updated_at, mime FROM ' . P . 'files WHERE id = ?', [$r['cover']])->fetch() : null;
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'count' => (int)$r['cnt'],
                  'cover' => $cv ? ['id' => (int)$cv['id'], 'v' => $cv['updated_at']] : null, 'updated' => $r['updated_at']];
    }
    return $out;
}

/** „V tento deň“: fotky z rovnakého dňa (±1 deň) v predchádzajúcich rokoch. */
function photo_memories(int $uid, ?string $today = null): array {
    $t = $today ? strtotime($today) : time();
    $days = [];
    foreach ([-1, 0, 1] as $o) $days[] = date('m-d', $t + $o * 86400);
    $ph = implode(',', array_fill(0, 3, '?'));
    $rows = q('SELECT f.*, 0 vcnt FROM ' . P . "files f WHERE f.user_id = ? AND f.deleted_at IS NULL AND (f.mime LIKE 'image/%' OR f.mime LIKE 'video/%')
               AND f.taken_at IS NOT NULL AND YEAR(f.taken_at) < ? AND DATE_FORMAT(f.taken_at, '%m-%d') IN ($ph)
               ORDER BY f.taken_at DESC LIMIT 200", array_merge([$uid, (int)date('Y', $t)], $days))->fetchAll();
    $folders = []; filter_locked($folders, $rows);
    $byYear = [];
    foreach ($rows as $r) $byYear[(int)substr($r['taken_at'], 0, 4)][] = $r;
    krsort($byYear);
    $out = [];
    foreach ($byYear as $y => $list) $out[] = ['year' => $y, 'ago' => (int)date('Y', $t) - $y, 'items' => rows_out($uid, [], array_slice($list, 0, 30))];
    return $out;
}
