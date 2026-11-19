<?php
/* Fotky: poloha z EXIF (mapa), albumy, „v tento deň“. */

/** GPS súradnice z EXIF fotky → [lat, lng] alebo [null, null]. */
function photo_gps(string $path, string $mime): array {
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return [null, null];
    $ex = @exif_read_data($path, 'GPS');
    if (!$ex || empty($ex['GPSLatitude']) || empty($ex['GPSLongitude'])) return [null, null];
    $num = function ($v): float {
        if (is_string($v) && str_contains($v, '/')) { [$a, $b] = array_map('floatval', explode('/', $v, 2)); return $b ? $a / $b : 0.0; }
        return (float)$v;
    };
    $deg = fn(array $p): float => $num($p[0] ?? 0) + $num($p[1] ?? 0) / 60 + $num($p[2] ?? 0) / 3600;
    $lat = $deg((array)$ex['GPSLatitude']) * (strtoupper((string)($ex['GPSLatitudeRef'] ?? 'N')) === 'S' ? -1 : 1);
    $lng = $deg((array)$ex['GPSLongitude']) * (strtoupper((string)($ex['GPSLongitudeRef'] ?? 'E')) === 'W' ? -1 : 1);
    if (abs($lat) < 0.0001 && abs($lng) < 0.0001) return [null, null]; // 0,0 = bez polohy
    if (abs($lat) > 90 || abs($lng) > 180) return [null, null];
    return [round($lat, 6), round($lng, 6)];
}

/** Doplní polohu starším fotkám (stačí začiatok súboru – EXIF je na začiatku). */
function backfill_photo_meta(?int $uid, int $seconds): int {
    $t0 = microtime(true); $n = 0;
    try {
        $rows = q('SELECT * FROM ' . P . "files WHERE meta_done = 0 AND deleted_at IS NULL AND mime = 'image/jpeg'" . ($uid ? ' AND user_id = ' . (int)$uid : '') . ' ORDER BY id DESC LIMIT 2000')->fetchAll();
    } catch (Throwable $e) { return 0; }
    foreach ($rows as $r) {
        if (microtime(true) - $t0 > $seconds) break;
        $lat = $lng = null; $tmp = null;
        try { $tmp = stored_to_temp($r, 262144); [$lat, $lng] = photo_gps($tmp, 'image/jpeg'); }
        catch (Throwable $e) {}
        finally { if ($tmp) @unlink($tmp); }
        q('UPDATE ' . P . 'files SET lat = ?, lng = ?, meta_done = 1 WHERE id = ?', [$lat, $lng, $r['id']]);
        $n++;
    }
    return $n;
}
function photo_meta_pending(int $uid): int {
    try { return (int)q('SELECT COUNT(*) FROM ' . P . "files WHERE user_id = ? AND meta_done = 0 AND deleted_at IS NULL AND mime = 'image/jpeg'", [$uid])->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

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
