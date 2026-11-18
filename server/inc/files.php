<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Knižnica pre súbory: prístupové práva, kôš, verzie, ukladanie, ZIP, sťahovanie
 * ------------------------------------------------------------------ */

const PERM_LEVEL = ['view' => 1, 'edit' => 2, 'owner' => 3];

/** Voľný názov v zložke: „foto.jpg“ → „foto (2).jpg“ (aby sa cudzie nahrávanie neprepisovalo). */
function free_name(int $owner, ?int $folder, string $name): string {
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $stem = $ext !== '' ? substr($name, 0, -strlen($ext) - 1) : $name;
    for ($i = 1; $i < 1000; $i++) {
        $try = $i === 1 ? $name : $stem . ' (' . $i . ')' . ($ext !== '' ? '.' . $ext : '');
        if (!q('SELECT 1 FROM ' . P . 'files WHERE user_id = ? AND folder_id <=> ? AND name = ? AND deleted_at IS NULL', [$owner, $folder, $try])->fetchColumn()) return $try;
    }
    return $stem . ' ' . bin2hex(random_bytes(3)) . ($ext !== '' ? '.' . $ext : '');
}
function clean_name(string $n): string {
    $n = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '', $n));
    $n = mb_substr($n, 0, 255);
    if ($n === '' || $n === '.' || $n === '..') throw new RuntimeException('Neplatný názov.');
    return $n;
}
function is_thumbable(string $mime): bool {
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
        || (str_starts_with($mime, 'video/') && ffmpeg_bin() !== null);
}
/** Cesta k ffmpeg (miniatúry videí), ak je na serveri nainštalovaný a PHP smie spúšťať programy. */
function ffmpeg_bin(string $tool = 'ffmpeg'): ?string {
    static $cache = [];
    if (array_key_exists($tool, $cache)) return $cache[$tool];
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) return $cache[$tool] = null;
    $found = null;
    foreach (['/usr/bin/' . $tool, '/usr/local/bin/' . $tool, '/opt/homebrew/bin/' . $tool] as $p) { if (@is_executable($p)) { $found = $p; break; } }
    if (!$found) { $out = []; @exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null', $out); $found = isset($out[0]) && $out[0] !== '' ? $out[0] : null; }
    return $cache[$tool] = $found;
}
function data_path(string $sub = ''): string { return rtrim(cfg('storage_path'), '/') . ($sub !== '' ? '/' . $sub : ''); }
function tmp_file(): string {
    $d = data_path('tmp');
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d . '/' . bin2hex(random_bytes(10));
}
/** Veľkosť jednej časti pri nahrávaní – podľa limitov servera (max. 8 MB). */
function chunk_size(): int {
    $toB = function ($v) { $v = trim((string)$v); $n = (float)$v; $u = strtolower(substr($v, -1));
        return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n))); };
    $lim = array_filter([$toB(ini_get('upload_max_filesize')), $toB(ini_get('post_max_size'))], fn($x) => $x > 0);
    $min = $lim ? min($lim) : 2097152;
    return (int)max(262144, min(8 * 1048576, $min - 65536));
}

/* ================== Prístupové práva ==================
 * Vracia: ['row' => riadok|null, 'owner' => id vlastníka, 'perm' => owner|edit|view, 'share_root' => id zdieľanej položky]
 */
function folder_access(int $uid, ?int $id, bool $withDeleted = false): array {
    if (!$id) return ['row' => null, 'owner' => $uid, 'perm' => 'owner', 'share_root' => null];
    $f = q('SELECT * FROM ' . P . 'folders WHERE id = ?', [$id])->fetch();
    if (!$f || (!$withDeleted && $f['deleted_at'])) throw new RuntimeException('Zložka neexistuje.');
    if ((int)$f['user_id'] === $uid) return ['row' => $f, 'owner' => $uid, 'perm' => 'owner', 'share_root' => null];
    if ($f['deleted_at']) throw new RuntimeException('Zložka neexistuje.');
    // hľadaj zdieľanie na tejto zložke alebo jej predkovi
    $cur = $f; $guard = 0;
    while ($cur && $guard++ < 100) {
        $perm = q('SELECT permission FROM ' . P . "user_shares WHERE item_type = 'd' AND item_id = ? AND target_id = ?", [$cur['id'], $uid])->fetchColumn();
        if ($perm) return ['row' => $f, 'owner' => (int)$f['user_id'], 'perm' => $perm, 'share_root' => (int)$cur['id']];
        $cur = $cur['parent_id'] ? q('SELECT id, parent_id FROM ' . P . 'folders WHERE id = ?', [$cur['parent_id']])->fetch() : null;
    }
    throw new RuntimeException('Zložka neexistuje alebo k nej nemáš prístup.');
}
function file_access(int $uid, int $id, bool $withDeleted = false): array {
    $f = q('SELECT * FROM ' . P . 'files WHERE id = ?', [$id])->fetch();
    if (!$f || (!$withDeleted && $f['deleted_at'])) throw new RuntimeException('Súbor neexistuje.');
    if ((int)$f['user_id'] === $uid) return ['row' => $f, 'owner' => $uid, 'perm' => 'owner', 'share_root' => null];
    if ($f['deleted_at']) throw new RuntimeException('Súbor neexistuje.');
    $perm = q('SELECT permission FROM ' . P . "user_shares WHERE item_type = 'f' AND item_id = ? AND target_id = ?", [$id, $uid])->fetchColumn();
    if ($perm) return ['row' => $f, 'owner' => (int)$f['user_id'], 'perm' => $perm, 'share_root' => null];
    if ($f['folder_id']) {
        $a = folder_access($uid, (int)$f['folder_id']);
        return ['row' => $f, 'owner' => $a['owner'], 'perm' => $a['perm'], 'share_root' => $a['share_root']];
    }
    throw new RuntimeException('Súbor neexistuje alebo k nemu nemáš prístup.');
}
function need(array $acc, string $perm): array {
    if (PERM_LEVEL[$acc['perm']] < PERM_LEVEL[$perm]) {
        throw new RuntimeException($perm === 'owner' ? 'Túto akciu môže urobiť iba vlastník.' : 'Na túto akciu nemáš oprávnenie (iba na čítanie).');
    }
    return $acc;
}

/* ================== Strom zložiek ================== */
function breadcrumbs(int $uid, ?int $id): array {
    $crumbs = []; $guard = 0;
    $acc = $id ? folder_access($uid, $id) : null;
    while ($id && $guard++ < 100) {
        $f = q('SELECT id, name, parent_id FROM ' . P . 'folders WHERE id = ?', [$id])->fetch();
        if (!$f) break;
        array_unshift($crumbs, ['id' => (int)$f['id'], 'name' => $f['name']]);
        if ($acc && $acc['share_root'] && (int)$f['id'] === $acc['share_root']) break; // zdieľaný koreň
        $id = $f['parent_id'] ? (int)$f['parent_id'] : null;
    }
    return $crumbs;
}
/** zložka + všetky podzložky (bez zmazaných, ak $withDeleted = false) */
function descendants(int $owner, int $id, bool $withDeleted = false): array {
    $all = [$id]; $queue = [$id];
    while ($queue) {
        $in = implode(',', array_map('intval', $queue));
        $queue = array_map('intval', q('SELECT id FROM ' . P . "folders WHERE user_id = ? AND parent_id IN ($in)" . ($withDeleted ? '' : ' AND deleted_at IS NULL'), [$owner])->fetchAll(PDO::FETCH_COLUMN));
        $all = array_merge($all, $queue);
    }
    return $all;
}
function name_exists(int $owner, ?int $folder, string $name, string $type, int $exceptId = 0): bool {
    $sql = $type === 'd'
        ? 'SELECT id FROM ' . P . 'folders WHERE user_id = ? AND parent_id <=> ? AND name = ? AND id <> ? AND deleted_at IS NULL'
        : 'SELECT id FROM ' . P . 'files WHERE user_id = ? AND folder_id <=> ? AND name = ? AND id <> ? AND deleted_at IS NULL';
    return (bool)q($sql, [$owner, $folder, $name, $exceptId])->fetch();
}
function unique_name(int $owner, ?int $folder, string $name, string $type): string {
    if (!name_exists($owner, $folder, $name, $type)) return $name;
    $dot = $type === 'f' ? strrpos($name, '.') : false;
    $base = $dot ? substr($name, 0, $dot) : $name;
    $ext  = $dot ? substr($name, $dot) : '';
    for ($i = 1; $i < 1000; $i++) { $n = "$base ($i)$ext"; if (!name_exists($owner, $folder, $n, $type)) return $n; }
    return $base . ' ' . bin2hex(random_bytes(3)) . $ext;
}
/** vytvorí (alebo nájde) cestu podzložiek "A/B/C" pod $folder */
function ensure_path(int $owner, ?int $folder, string $relDir): ?int {
    foreach (array_filter(explode('/', trim(str_replace('\\', '/', $relDir), '/')), 'strlen') as $part) {
        $part = clean_name($part);
        $ex = q('SELECT id FROM ' . P . 'folders WHERE user_id = ? AND parent_id <=> ? AND name = ? AND deleted_at IS NULL', [$owner, $folder, $part])->fetchColumn();
        if (!$ex) {
            try { q('INSERT INTO ' . P . 'folders (user_id, parent_id, name) VALUES (?,?,?)', [$owner, $folder, $part]); $ex = db()->lastInsertId(); }
            catch (Throwable $e) { $ex = q('SELECT id FROM ' . P . 'folders WHERE user_id = ? AND parent_id <=> ? AND name = ? AND deleted_at IS NULL', [$owner, $folder, $part])->fetchColumn(); }
        }
        $folder = (int)$ex;
    }
    return $folder;
}

/* ================== Formát pre klienta ================== */
function owner_names(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $out = [];
    foreach (q('SELECT id, name FROM ' . P . 'users WHERE id IN (' . implode(',', $ids) . ')') as $r) $out[(int)$r['id']] = $r['name'];
    return $out;
}
function shared_flags(int $owner, array $fileIds, array $folderIds): array {
    $out = ['f' => [], 'd' => []];
    foreach (['f' => $fileIds, 'd' => $folderIds] as $t => $ids) {
        if (!$ids) continue;
        $in = implode(',', array_map('intval', $ids));
        foreach (q('SELECT item_id FROM ' . P . "shares WHERE item_type = '$t' AND item_id IN ($in) AND (expires_at IS NULL OR expires_at > NOW())
                    UNION SELECT item_id FROM " . P . "user_shares WHERE item_type = '$t' AND item_id IN ($in)") as $r) $out[$t][(int)$r['item_id']] = true;
    }
    return $out;
}
/** Štítky používateľa pre dané položky: ['f' => [id => [tag,..]], 'd' => [...]] */
function item_tags_for(int $uid, array $fileIds, array $folderIds): array {
    $out = ['f' => [], 'd' => []];
    foreach (['f' => $fileIds, 'd' => $folderIds] as $t => $ids) {
        if (!$ids) continue;
        foreach (array_chunk(array_map('intval', $ids), 1000) as $chunk) {
            $rows = q('SELECT it.item_id, tg.id, tg.name, tg.color FROM ' . P . 'item_tags it JOIN ' . P . "tags tg ON tg.id = it.tag_id
                       WHERE tg.user_id = ? AND it.item_type = '$t' AND it.item_id IN (" . implode(',', $chunk) . ') ORDER BY tg.name', [$uid])->fetchAll();
            foreach ($rows as $r) $out[$t][(int)$r['item_id']][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color']];
        }
    }
    return $out;
}
function valid_color(?string $c): ?string { return $c !== null && preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : null; }

function rows_out(int $uid, array $folders, array $files, array $extra = []): array {
    try { $tags = item_tags_for($uid, array_map(fn($f) => (int)$f['id'], $files), array_map(fn($f) => (int)$f['id'], $folders)); }
    catch (Throwable $e) { $tags = ['f' => [], 'd' => []]; }
    $owners = owner_names(array_merge(array_column($folders, 'user_id'), array_column($files, 'user_id')));
    $sh = shared_flags($uid, array_map(fn($f) => (int)$f['id'], array_filter($files, fn($f) => (int)$f['user_id'] === $uid)),
                             array_map(fn($f) => (int)$f['id'], array_filter($folders, fn($f) => (int)$f['user_id'] === $uid)));
    $out = [];
    foreach ($folders as $d) {
        $out[] = ['type' => 'd', 'id' => (int)$d['id'], 'name' => $d['name'], 'starred' => (bool)$d['starred'], 'updated' => $d['updated_at'],
                  'count' => isset($d['cnt']) ? (int)$d['cnt'] : null, 'shared' => isset($sh['d'][(int)$d['id']]),
                  'owner' => (int)$d['user_id'] === $uid ? null : ($owners[(int)$d['user_id']] ?? '?'),
                  'perm' => $extra['perm'][$d['id']] ?? ((int)$d['user_id'] === $uid ? 'owner' : ($extra['default_perm'] ?? 'view')),
                  'deleted' => $d['deleted_at'] ?? null, 'color' => $d['color'] ?? null, 'tags' => $tags['d'][(int)$d['id']] ?? [],
                  'share_info' => $extra['si']['d'][(int)$d['id']] ?? null];
    }
    foreach ($files as $f) {
        $out[] = ['type' => 'f', 'id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'mime' => $f['mime'],
                  'starred' => (bool)$f['starred'], 'updated' => $f['updated_at'], 'taken' => $f['taken_at'] ?? null,
                  'folder_id' => $f['folder_id'] ? (int)$f['folder_id'] : null,
                  'thumb' => is_thumbable($f['mime']), 'shared' => isset($sh['f'][(int)$f['id']]),
                  'versions' => isset($f['vcnt']) ? (int)$f['vcnt'] : 0,
                  'owner' => (int)$f['user_id'] === $uid ? null : ($owners[(int)$f['user_id']] ?? '?'),
                  'perm' => $extra['fperm'][$f['id']] ?? ((int)$f['user_id'] === $uid ? 'owner' : ($extra['default_perm'] ?? 'view')),
                  'deleted' => $f['deleted_at'] ?? null, 'color' => $f['color'] ?? null, 'tags' => $tags['f'][(int)$f['id']] ?? [],
                  'share_info' => $extra['si']['f'][(int)$f['id']] ?? null, 'dup' => $extra['dup'][(int)$f['id']] ?? null];
    }
    return $out;
}

/* ================== Ukladanie súborov a verzie ================== */
function photo_date(string $path, string $mime): ?string {
    if (str_starts_with($mime, 'video/') && ($probe = ffmpeg_bin('ffprobe'))) { // dátum natočenia videa
        $out = [];
        @exec(escapeshellarg($probe) . ' -v quiet -show_entries format_tags=creation_time -of default=nw=1:nk=1 ' . escapeshellarg($path) . ' 2>/dev/null', $out);
        if (!empty($out[0]) && ($t = strtotime($out[0])) && $t > 631152000) return date('Y-m-d H:i:s', $t);
        return null;
    }
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return null;
    $ex = @exif_read_data($path);
    $d = $ex['DateTimeOriginal'] ?? $ex['DateTime'] ?? null;
    if (!$d || !preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/', $d, $m) || $m[1] < 1990) return null;
    return "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
}
/**
 * Presunie hotový súbor do úložiska vlastníka (a zašifruje ho). Ak súbor s rovnakým názvom existuje, uloží sa nová verzia;
 * ak má rovnaký obsah, nič sa nemení.
 * @return array{id:int,name:string,versioned:bool,size:int,unchanged?:bool}
 */
function store_file(int $owner, ?int $folder, string $name, string $src, bool $isUploaded): array {
    $r = store_file_inner($owner, $folder, $name, $src, $isUploaded);
    if (!empty($r['id']) && empty($r['unchanged'])) {
        $fid = (int)$r['id'];
        defer_task(function () use ($fid) { // miniatúra hneď po nahratí, nie až pri prvom zobrazení
            $row = q('SELECT * FROM ' . P . 'files WHERE id = ?', [$fid])->fetch();
            if ($row && is_thumbable($row['mime'])) {
                @unlink(data_path('thumbs/' . (int)$row['user_id'] . '/' . $row['stored_name'] . '.jpg'));
                thumb_ensure($row);
            }
        });
    }
    return $r;
}
function store_file_inner(int $owner, ?int $folder, string $name, string $src, bool $isUploaded): array {
    // 1) dočasný súbor s otvoreným textom
    $plain = tmp_file();
    $ok = $isUploaded ? move_uploaded_file($src, $plain) : rename($src, $plain);
    if (!$ok) throw new RuntimeException('Nepodarilo sa uložiť súbor.');
    try {
        $size = filesize($plain);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($plain) ?: 'application/octet-stream';
        $taken = photo_date($plain, $mime);
        $sha = hash_file('sha256', $plain);

        $ex = q('SELECT * FROM ' . P . 'files WHERE user_id = ? AND folder_id <=> ? AND name = ? AND deleted_at IS NULL LIMIT 1', [$owner, $folder, $name])->fetch();
        // rovnaký obsah už existuje → nevytváraj zbytočnú verziu
        if ($ex && (int)$ex['size'] === $size) {
            $exSha = $ex['sha256'] ?? null;
            if (!$exSha) { try { $exSha = stored_sha256($ex); q('UPDATE ' . P . 'files SET sha256 = ? WHERE id = ?', [$exSha, $ex['id']]); } catch (Throwable $e) { $exSha = null; } }
            if ($exSha === $sha) return ['id' => (int)$ex['id'], 'name' => $name, 'versioned' => false, 'unchanged' => true, 'size' => $size];
        }

        // 2) uloženie (šifrované alebo nie)
        $stored = bin2hex(random_bytes(20));
        $dest = storage_dir($owner) . '/' . $stored;
        $encKey = null;
        if (crypto_enabled()) {
            [$raw, $encKey] = crypto_new_file_key();
            crypto_encrypt_file($plain, $dest, $raw);
        } elseif (!rename($plain, $dest)) {
            throw new RuntimeException('Nepodarilo sa uložiť súbor.');
        }
    } finally {
        if (is_file($plain)) @unlink($plain);
    }

    if ($ex) {
        q('INSERT INTO ' . P . 'file_versions (file_id, user_id, stored_name, size, mime, enc_key, sha256, created_at) VALUES (?,?,?,?,?,?,?,?)',
          [$ex['id'], $owner, $ex['stored_name'], $ex['size'], $ex['mime'], $ex['enc_key'] ?? null, $ex['sha256'] ?? null, $ex['updated_at']]);
        q('UPDATE ' . P . 'files SET stored_name = ?, size = ?, mime = ?, enc_key = ?, sha256 = ?, taken_at = COALESCE(?, taken_at), updated_at = NOW() WHERE id = ?',
          [$stored, $size, $mime, $encKey, $sha, $taken, $ex['id']]);
        @unlink(data_path('thumbs/' . $owner . '/' . $ex['stored_name'] . '.jpg'));
        prune_versions((int)$ex['id'], $owner);
        $id = (int)$ex['id'];
        $versioned = true;
    } else {
        q('INSERT INTO ' . P . 'files (user_id, folder_id, name, stored_name, size, mime, enc_key, sha256, taken_at) VALUES (?,?,?,?,?,?,?,?,?)',
          [$owner, $folder, $name, $stored, $size, $mime, $encKey, $sha, $taken]);
        $id = (int)db()->lastInsertId();
        $versioned = false;
    }
    if ($folder) q('UPDATE ' . P . 'folders SET updated_at = NOW() WHERE id = ?', [$folder]);
    return ['id' => $id, 'name' => $name, 'versioned' => $versioned, 'size' => $size];
}
function prune_versions(int $fileId, int $owner): void {
    $max = max(0, (int)setting('max_versions', '10'));
    $old = q('SELECT id, stored_name FROM ' . P . 'file_versions WHERE file_id = ? ORDER BY created_at DESC, id DESC LIMIT 1000 OFFSET ' . $max, [$fileId])->fetchAll();
    foreach ($old as $v) {
        @unlink(storage_dir($owner) . '/' . $v['stored_name']);
        q('DELETE FROM ' . P . 'file_versions WHERE id = ?', [$v['id']]);
    }
}

/* ================== Kôš ================== */
function trash_folder(int $owner, int $id): void {
    $ids = implode(',', descendants($owner, $id));
    q('UPDATE ' . P . "folders SET deleted_at = NOW(), trash_root = (id = ?) WHERE user_id = ? AND id IN ($ids) AND deleted_at IS NULL", [$id, $owner]);
    q('UPDATE ' . P . "files SET deleted_at = NOW(), trash_root = 0 WHERE user_id = ? AND folder_id IN ($ids) AND deleted_at IS NULL", [$owner]);
}
function trash_file(int $owner, int $id): void {
    q('UPDATE ' . P . 'files SET deleted_at = NOW(), trash_root = 1 WHERE id = ? AND user_id = ?', [$id, $owner]);
}
function restore_item(int $owner, string $type, int $id): void {
    if ($type === 'f') {
        $f = q('SELECT * FROM ' . P . 'files WHERE id = ? AND user_id = ? AND trash_root = 1', [$id, $owner])->fetch();
        if (!$f) throw new RuntimeException('Položka nie je v koši.');
        $folder = $f['folder_id'] ? (int)$f['folder_id'] : null;
        if ($folder && q('SELECT deleted_at FROM ' . P . 'folders WHERE id = ?', [$folder])->fetchColumn() !== null) $folder = null;
        if ($folder && !q('SELECT id FROM ' . P . 'folders WHERE id = ?', [$folder])->fetch()) $folder = null;
        q('UPDATE ' . P . 'files SET deleted_at = NULL, trash_root = 0, folder_id = ?, name = ? WHERE id = ?', [$folder, unique_name($owner, $folder, $f['name'], 'f'), $id]);
        return;
    }
    $d = q('SELECT * FROM ' . P . 'folders WHERE id = ? AND user_id = ? AND trash_root = 1', [$id, $owner])->fetch();
    if (!$d) throw new RuntimeException('Položka nie je v koši.');
    // zbieraj podstrom zmazaný spolu s touto zložkou (nie samostatne zmazané podpoložky)
    $all = [$id]; $queue = [$id];
    while ($queue) {
        $in = implode(',', $queue);
        $queue = array_map('intval', q('SELECT id FROM ' . P . "folders WHERE user_id = ? AND parent_id IN ($in) AND deleted_at IS NOT NULL AND trash_root = 0", [$owner])->fetchAll(PDO::FETCH_COLUMN));
        $all = array_merge($all, $queue);
    }
    $in = implode(',', $all);
    $parent = $d['parent_id'] ? (int)$d['parent_id'] : null;
    if ($parent) {
        $pd = q('SELECT deleted_at FROM ' . P . 'folders WHERE id = ?', [$parent])->fetch();
        if (!$pd || $pd['deleted_at']) $parent = null;
    }
    $newName = unique_name($owner, $parent, $d['name'], 'd'); // pred obnovením, aby nenašla sama seba
    q('UPDATE ' . P . "files SET deleted_at = NULL WHERE user_id = ? AND folder_id IN ($in) AND trash_root = 0 AND deleted_at IS NOT NULL", [$owner]);
    q('UPDATE ' . P . "folders SET deleted_at = NULL, trash_root = 0 WHERE user_id = ? AND id IN ($in)", [$owner]);
    q('UPDATE ' . P . 'folders SET parent_id = ?, name = ? WHERE id = ?', [$parent, $newName, $id]);
}
/** Natrvalo zmaže súbor (aj verzie, miniatúry, zdieľania) */
function purge_file(array $f): void { purge_files([$f]); }
/** Natrvalé zmazanie viacerých súborov naraz (dávkovo – rýchle aj pre tisícky súborov). */
function purge_files(array $files): void {
    @set_time_limit(600);
    foreach (array_chunk($files, 500) as $chunk) {
        $ids = array_map(fn($f) => (int)$f['id'], $chunk);
        $in = implode(',', $ids);
        $owners = [];
        foreach ($chunk as $f) {
            $owner = (int)$f['user_id'];
            $owners[(int)$f['id']] = $owner;
            @unlink(storage_dir($owner) . '/' . $f['stored_name']);
            @unlink(data_path('thumbs/' . $owner . '/' . $f['stored_name'] . '.jpg'));
        }
        foreach (q('SELECT file_id, stored_name FROM ' . P . "file_versions WHERE file_id IN ($in)")->fetchAll() as $v) {
            @unlink(storage_dir($owners[(int)$v['file_id']]) . '/' . $v['stored_name']);
        }
        q('DELETE FROM ' . P . "file_versions WHERE file_id IN ($in)");
        q('DELETE FROM ' . P . "shares WHERE item_type = 'f' AND item_id IN ($in)");
        q('DELETE FROM ' . P . "user_shares WHERE item_type = 'f' AND item_id IN ($in)");
        try { q('DELETE FROM ' . P . "item_tags WHERE item_type = 'f' AND item_id IN ($in)"); } catch (Throwable $e) {}
        q('DELETE FROM ' . P . "files WHERE id IN ($in)");
    }
}
function purge_folder(int $owner, int $id): void {
    $ids = descendants($owner, $id, true);
    $in = implode(',', $ids);
    purge_files(q('SELECT id, user_id, stored_name FROM ' . P . "files WHERE user_id = ? AND folder_id IN ($in)", [$owner])->fetchAll());
    q('DELETE FROM ' . P . "shares WHERE item_type = 'd' AND item_id IN ($in)");
    q('DELETE FROM ' . P . "user_shares WHERE item_type = 'd' AND item_id IN ($in)");
    try { q('DELETE FROM ' . P . "item_tags WHERE item_type = 'd' AND item_id IN ($in)"); } catch (Throwable $e) {}
    delete_folder_rows($owner, [$id]);
}

/** Zmaže riadky zložiek po úrovniach od najhlbšej – inak MySQL narazí na limit kaskády (15 úrovní). */
function delete_folder_rows(int $owner, array $roots): void {
    $levels = []; $level = array_map('intval', $roots);
    while ($level) {
        $levels[] = $level;
        $in = implode(',', $level);
        $level = array_map('intval', q('SELECT id FROM ' . P . "folders WHERE user_id = ? AND parent_id IN ($in)", [$owner])->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach (array_reverse($levels) as $lv) {
        foreach (array_chunk($lv, 500) as $chunk) {
            q('DELETE FROM ' . P . 'folders WHERE user_id = ? AND id IN (' . implode(',', $chunk) . ')', [$owner]);
        }
    }
}
function purge_trash(int $owner, ?int $olderThanDays = null): int {
    $cond = $olderThanDays !== null ? ' AND deleted_at < (NOW() - INTERVAL ' . (int)$olderThanDays . ' DAY)' : '';
    $n = 0;
    foreach (q('SELECT id FROM ' . P . "folders WHERE user_id = ? AND trash_root = 1 AND deleted_at IS NOT NULL$cond", [$owner])->fetchAll(PDO::FETCH_COLUMN) as $id) { purge_folder($owner, (int)$id); $n++; }
    $files = q('SELECT id, user_id, stored_name FROM ' . P . "files WHERE user_id = ? AND trash_root = 1 AND deleted_at IS NOT NULL$cond", [$owner])->fetchAll();
    purge_files($files); $n += count($files);
    return $n;
}

/** Doplní kontrolný súčet (SHA-256) starším súborom – potrebné pre hľadanie duplicít. */
function backfill_hashes(?int $uid, float $seconds): int {
    $t0 = microtime(true); $n = 0;
    @set_time_limit((int)$seconds + 30);
    $rows = q('SELECT * FROM ' . P . 'files WHERE sha256 IS NULL AND deleted_at IS NULL' . ($uid ? ' AND user_id = ' . (int)$uid : '') . ' ORDER BY size LIMIT 500')->fetchAll();
    foreach ($rows as $r) {
        if (microtime(true) - $t0 > $seconds) break;
        try { q('UPDATE ' . P . 'files SET sha256 = ? WHERE id = ?', [stored_sha256($r), $r['id']]); $n++; } catch (Throwable $e) {
            q("UPDATE " . P . "files SET sha256 = '' WHERE id = ?", [$r['id']]); // poškodený súbor – nezdržiavať ďalšie pokusy
        }
    }
    return $n;
}

/** Upozornenie administrátorov, keď na disku servera dochádza miesto (najviac raz za 12 h). */
function check_disk_warning(): void {
    $path = (string)cfg('storage_path');
    $tot = @disk_total_space($path); $free = @disk_free_space($path);
    if (!$tot || $free === false) return;
    $pct = 1 - $free / $tot;
    $warned = (int)setting('disk_warned_at', '0');
    if ($pct >= 0.9 || $free < 2 * 1073741824) {
        if (time() - $warned < 12 * 3600) return;
        setting_set('disk_warned_at', (string)time());
        $msg = 'Na disku servera zostáva ' . format_bytes((int)$free) . ' z ' . format_bytes((int)$tot) . ' (' . round($pct * 100) . ' % zaplnené).';
        notify_admins('disk', 'Na serveri dochádza miesto', $msg, 'admin.php');
        foreach (q("SELECT email, name FROM " . P . "users WHERE role = 'admin' AND is_active = 1")->fetchAll() as $a) {
            send_mail($a['email'], 'Na serveri dochádza miesto – ' . cfg('app_name'), 'Dochádza miesto na disku',
                '<p>' . e($msg) . '</p><p>Keď sa disk zaplní, nahrávanie prestane fungovať. Uvoľni miesto (kôš, staré verzie, duplicity) alebo pridaj väčší disk.</p>',
                'Otvoriť administráciu', public_url() . '/admin.php');
        }
    } elseif ($warned && $pct < 0.85) {
        setting_set('disk_warned_at', '0');
    }
}

/* ================== Denná údržba ================== */
function housekeeping(): void {
    setting_set('housekeeping_day', date('Y-m-d'));
    try { check_disk_warning(); } catch (Throwable $e) {}
    try { backfill_hashes(null, 120); } catch (Throwable $e) {}
    try { backfill_thumbs(120); } catch (Throwable $e) {}
    $days = (int)setting('trash_days', '30');
    if ($days > 3) { // upozornenie: o 3 dni sa zo koša natrvalo zmažú položky
        $rows = q('SELECT user_id, COUNT(*) c FROM (SELECT user_id FROM ' . P . 'files WHERE trash_root = 1 AND DATE(deleted_at) = CURDATE() - INTERVAL ' . ($days - 3) . ' DAY
                   UNION ALL SELECT user_id FROM ' . P . 'folders WHERE trash_root = 1 AND DATE(deleted_at) = CURDATE() - INTERVAL ' . ($days - 3) . ' DAY) t GROUP BY user_id')->fetchAll();
        foreach ($rows as $r) notify((int)$r['user_id'], 'trash', 'Kôš sa čoskoro vyprázdni', $r['c'] . ' položiek sa o 3 dni natrvalo zmaže. Ak ich potrebuješ, obnov ich.', 'index.php#trash');
    }
    // nová verzia servera → upozorni administrátorov
    try {
        require_once __DIR__ . '/updater.php';
        $rel = gh_releases('server-v')[0] ?? null;
        if ($rel && version_compare($rel['version'], APP_VERSION, '>') && setting('notified_version') !== $rel['version']) {
            setting_set('notified_version', $rel['version']);
            notify_admins('update', 'Dostupná aktualizácia servera ' . $rel['version'], 'Nainštalovaná je ' . APP_VERSION . '. Aktualizuješ ju jedným tlačidlom.', 'admin.php?tab=update');
        }
    } catch (Throwable $e) {}
    if ($days > 0) {
        $owners = q('SELECT DISTINCT user_id FROM ' . P . 'files WHERE trash_root = 1 AND deleted_at < (NOW() - INTERVAL ' . $days . ' DAY)
                     UNION SELECT DISTINCT user_id FROM ' . P . 'folders WHERE trash_root = 1 AND deleted_at < (NOW() - INTERVAL ' . $days . ' DAY)')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($owners as $o) purge_trash((int)$o, $days);
    }
    // nedokončené nahrávania starších ako 3 dni
    foreach (q('SELECT id FROM ' . P . 'uploads WHERE updated_at < (NOW() - INTERVAL 3 DAY)')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        @unlink(data_path('tmp/chunks/' . $id));
        q('DELETE FROM ' . P . 'uploads WHERE id = ?', [$id]);
    }
    q('DELETE FROM ' . P . 'tokens WHERE expires_at < (NOW() - INTERVAL 7 DAY)');
    q('DELETE FROM ' . P . 'password_resets WHERE expires_at < (NOW() - INTERVAL 7 DAY)');
    q('DELETE FROM ' . P . 'sessions WHERE (revoked = 1 AND last_seen < (NOW() - INTERVAL 30 DAY)) OR last_seen < (NOW() - INTERVAL ' . (int)ceil(cfg('session_lifetime', 1209600) / 86400) . ' DAY)');
    q('DELETE FROM ' . P . 'log WHERE created_at < (NOW() - INTERVAL 365 DAY)');
    // denný snímok využitia (pre graf v administrácii)
    $bytes = (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'files')->fetchColumn() + (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'file_versions')->fetchColumn();
    q('INSERT INTO ' . P . 'usage_daily (day, bytes, files, users) VALUES (CURDATE(), ?, (SELECT COUNT(*) FROM ' . P . 'files), (SELECT COUNT(*) FROM ' . P . 'users))
       ON DUPLICATE KEY UPDATE bytes = VALUES(bytes), files = VALUES(files), users = VALUES(users)', [$bytes]);
}

/* ================== Sťahovanie, miniatúry, ZIP ================== */
function send_file(string $path, string $name, string $mime, bool $inline): never {
    $size = filesize($path);
    $start = 0; $end = $size - 1;
    // PDF prehliadač v Chrome nefunguje v sandboxe; ostatné súbory izolujeme
    if (!($inline && $mime === 'application/pdf')) {
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'");
    }
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Cache-Control: private, max-age=0, must-revalidate');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    while (ob_get_level()) ob_end_clean();
    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, (int)min(1048576, $left));
        echo $chunk; flush();
        $left -= strlen($chunk);
    }
    fclose($fp);
    exit;
}
/** Pošle uložený súbor (rozšifruje ho za behu; podporuje Range pre video a pokračovanie sťahovania). */
function send_stored(array $row, string $name, bool $inline): never {
    $key = row_key($row);
    if ($key === null) send_file(stored_path($row), $name, $row['mime'], $inline);
    $size = (int)$row['size'];
    $start = 0; $end = $size - 1;
    $mime = $row['mime'];
    if (!($inline && $mime === 'application/pdf')) {
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'");
    }
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
    $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Cache-Control: private, max-age=0, must-revalidate');
    if ($size > 0 && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . max(0, $end - $start + 1));
    while (ob_get_level()) ob_end_clean();
    if ($size > 0) {
        @set_time_limit(0);
        crypto_decrypt_range(stored_path($row), $key, $size, $start, $end, function ($d) { echo $d; flush(); return !connection_aborted(); });
    }
    exit;
}

function safe_inline(string $mime): bool {
    return (bool)preg_match('#^(image/(jpeg|png|gif|webp|bmp)|video/|audio/|application/pdf$|text/plain$)#', $mime);
}
/** Snímka z videa (ffmpeg) ako JPEG. */
function make_video_thumb(string $src, string $dst, int $max): bool {
    $ff = ffmpeg_bin(); if (!$ff) return false;
    $tmp = $dst . '.v.jpg';
    foreach (['1', '0'] as $ss) {
        @exec(escapeshellarg($ff) . ' -v quiet -y -ss ' . $ss . ' -i ' . escapeshellarg($src) . ' -frames:v 1 -vf ' . escapeshellarg("scale='min($max,iw)':-2") . ' -q:v 4 -f image2 ' . escapeshellarg($tmp) . ' 2>/dev/null', $o, $rc);
        if (is_file($tmp) && filesize($tmp) > 0) return rename($tmp, $dst);
    }
    @unlink($tmp);
    return false;
}
function make_thumb(string $src, string $dst, int $max): bool {
    if (!function_exists('imagecreatefromstring') || !is_file($src) || filesize($src) > 40 * 1024 * 1024) return false;
    $img = @imagecreatefromstring(file_get_contents($src));
    if (!$img) return false;
    if (function_exists('exif_read_data')) {
        $o = (@exif_read_data($src))['Orientation'] ?? 1;
        if ($o == 3) $img = imagerotate($img, 180, 0);
        elseif ($o == 6) $img = imagerotate($img, -90, 0);
        elseif ($o == 8) $img = imagerotate($img, 90, 0);
    }
    $w = imagesx($img); $h = imagesy($img);
    $r = min(1, $max / max($w, $h));
    $nw = max(1, (int)($w * $r)); $nh = max(1, (int)($h * $r));
    $t = imagecreatetruecolor($nw, $nh);
    imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));
    imagecopyresampled($t, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($t, $dst, 80);
    imagedestroy($img); imagedestroy($t);
    return $ok;
}
/** Vytvorí (zašifrovanú) miniatúru, ak ešte nie je. Vráti cestu k nej alebo null. */
function thumb_ensure(array $f): ?string {
    if (!is_thumbable($f['mime'])) return null;
    $owner = (int)$f['user_id'];
    $tdir = data_path('thumbs/' . $owner);
    $tpath = $tdir . '/' . $f['stored_name'] . '.jpg';
    if (is_file($tpath)) return $tpath;
    if (!is_dir($tdir)) @mkdir($tdir, 0750, true);
    $video = str_starts_with($f['mime'], 'video/');
    if ((int)$f['size'] > ($video ? 600 : 40) * 1024 * 1024) return null;
    @set_time_limit(180);
    $key = row_key($f);
    $src = stored_to_temp($f);
    $plainThumb = tmp_file();
    try {
        if (!($video ? make_video_thumb($src, $plainThumb, 400) : make_thumb($src, $plainThumb, 400))) return null;
        $tmpEnc = $tpath . '.part' . bin2hex(random_bytes(3));
        if ($key !== null) crypto_encrypt_file($plainThumb, $tmpEnc, $key); // miniatúry sú šifrované kľúčom súboru
        else rename($plainThumb, $tmpEnc);
        rename($tmpEnc, $tpath);
        return $tpath;
    } finally { @unlink($src); if (is_file($plainThumb)) @unlink($plainThumb); }
}

/** Miniatúry pre nahraté súbory vopred (na pozadí), aby sa zoznam zobrazil hneď. */
function backfill_thumbs(float $seconds): int {
    $t0 = microtime(true); $n = 0;
    $last = (int)setting('thumbs_backfill_id', '0');
    $rows = q("SELECT * FROM " . P . "files WHERE id > ? AND deleted_at IS NULL AND (mime IN ('image/jpeg','image/png','image/gif','image/webp') OR mime LIKE 'video/%') ORDER BY id LIMIT 400", [$last])->fetchAll();
    foreach ($rows as $r) {
        if (microtime(true) - $t0 > $seconds) break;
        try { if (!is_file(data_path('thumbs/' . (int)$r['user_id'] . '/' . $r['stored_name'] . '.jpg')) && thumb_ensure($r)) $n++; } catch (Throwable $e) {}
        $last = (int)$r['id'];
    }
    setting_set('thumbs_backfill_id', count($rows) < 400 && microtime(true) - $t0 <= $seconds ? '0' : (string)$last);
    return $n;
}

function send_thumb(array $f): never {
    $tpath = thumb_ensure($f);
    if (!$tpath) { http_response_code(404); exit; }
    $key = row_key($f);
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=2592000' . (isset($_GET['v']) ? ', immutable' : '')); // adresa obsahuje verziu súboru
    if ($key === null) {
        header('Content-Length: ' . filesize($tpath));
        readfile($tpath);
        exit;
    }
    // veľkosť otvoreného textu miniatúry vypočítame z veľkosti šifry
    $cs = filesize($tpath) - ENC_HEADER;
    $blocks = (int)ceil($cs / (ENC_BLOCK + ENC_TAG));
    $plainSize = max(0, $cs - $blocks * ENC_TAG);
    header('Content-Length: ' . $plainSize);
    try {
        if ($plainSize > 0) crypto_decrypt_range($tpath, $key, $plainSize, 0, $plainSize - 1, function ($d) { echo $d; return true; });
    } catch (Throwable $e) { @unlink($tpath); }
    exit;
}

/** Zabalí zložky/súbory (riadky z DB) do ZIP a pošle ho. */
function send_zip(array $folders, array $files, string $zipName): never {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Na serveri chýba rozšírenie ZipArchive.');
    @set_time_limit(0); // veľké zložky s tisíckami súborov
    $tmpDir = data_path('tmp');
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0750, true);
    foreach (glob($tmpDir . '/*.zip') ?: [] as $old) if (filemtime($old) < time() - 3600) @unlink($old);
    $tmp = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE);
    $temps = [];
    $addFile = function (array $f, string $as) use ($zip, &$temps) {
        if (empty($f['enc_key'])) { $zip->addFile(stored_path($f), $as); return; }
        $t = stored_to_temp($f); $temps[] = $t; $zip->addFile($t, $as);
    };
    $add = function (array $d, string $prefix) use (&$add, $zip, $addFile) {
        $owner = (int)$d['user_id'];
        $zip->addEmptyDir(rtrim($prefix, '/'));
        foreach (q('SELECT * FROM ' . P . 'files WHERE user_id = ? AND folder_id = ? AND deleted_at IS NULL', [$owner, $d['id']]) as $f)
            $addFile($f, $prefix . $f['name']);
        foreach (q('SELECT * FROM ' . P . 'folders WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL', [$owner, $d['id']]) as $c)
            $add($c, $prefix . $c['name'] . '/');
    };
    foreach ($folders as $d) $add($d, $d['name'] . '/');
    foreach ($files as $f) $addFile($f, $f['name']);
    if ($zip->numFiles === 0) $zip->addEmptyDir('prazdne');
    $zip->close();
    foreach ($temps as $t) @unlink($t);
    register_shutdown_function(fn() => @unlink($tmp));
    send_file($tmp, $zipName . '.zip', 'application/zip', false);
}
