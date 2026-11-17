<?php
/* ------------------------------------------------------------------
 *  API pre správu súborov a zložiek
 * ------------------------------------------------------------------ */
require __DIR__ . '/inc/core.php';

$user = require_login();
$uid  = (int)$user['id'];
$action = $_GET['action'] ?? '';
$F = P . 'files';
$D = P . 'folders';
$V = P . 'file_versions';

/* ---------- pomocné ---------- */
function parse_items(?array $src = null): array {
    $out = ['f' => [], 'd' => []];
    foreach ((array)($src ?? ($_POST['items'] ?? [])) as $it) {
        if (preg_match('/^([fd]):(\d+)$/', (string)$it, $m)) $out[$m[1]][] = (int)$m[2];
    }
    return $out;
}
function quota_info(array $user): array {
    $used = used_space((int)$user['id']);
    return ['used' => $used, 'quota' => (int)$user['quota'], 'used_h' => format_bytes($used), 'quota_h' => $user['quota'] ? format_bytes($user['quota']) : null];
}
function owner_row(int $owner): array { return q('SELECT * FROM ' . P . 'users WHERE id = ?', [$owner])->fetch(); }
function check_space(int $owner, int $need): void {
    $o = owner_row($owner);
    if ($o['quota'] > 0 && used_space($owner) + $need > $o['quota']) {
        throw new RuntimeException('Nedostatok miesta' . ($owner !== (int)current_user()['id'] ? ' u vlastníka zložky' : '') . ' – kvóta ' . format_bytes($o['quota']) . '.');
    }
}
function check_quota_warning(int $owner): void {
    $u = owner_row($owner);
    if (!$u || !$u['quota']) return;
    $used = used_space($owner);
    $pct = $used / $u['quota'];
    if ($pct >= 0.9 && !$u['quota_warned']) {
        q('UPDATE ' . P . 'users SET quota_warned = 1 WHERE id = ?', [$owner]);
        send_mail($u['email'], 'Úložisko je takmer plné – ' . cfg('app_name'), 'Dochádza ti miesto',
            '<p>Ahoj ' . e($u['name']) . ', využívaš <b>' . format_bytes($used) . '</b> z <b>' . format_bytes($u['quota']) . '</b> (' . round($pct * 100) . ' %).</p>'
            . '<p>Uvoľni miesto zmazaním nepotrebných súborov (nezabudni vysypať kôš a staré verzie) alebo požiadaj administrátora o navýšenie kvóty.</p>',
            'Otvoriť disk', cfg('app_url') . '/index.php');
    } elseif ($pct < 0.8 && $u['quota_warned']) {
        q('UPDATE ' . P . 'users SET quota_warned = 0 WHERE id = ?', [$owner]);
    }
}
function share_url(string $token): string { return cfg('app_url') . '/s.php?t=' . $token; }

/* ==================================================================
 *  GET: sťahovanie, náhľad, miniatúry, ZIP
 * ================================================================== */
if (in_array($action, ['download', 'thumb', 'zip'], true)) {
    session_write_close();
    try {
        if ($action === 'download') {
            $a = file_access($uid, (int)($_GET['id'] ?? 0));
            $f = $a['row'];
            if (!empty($_GET['v'])) { // stará verzia
                $ver = q("SELECT * FROM $V WHERE id = ? AND file_id = ?", [(int)$_GET['v'], $f['id']])->fetch();
                if (!$ver) throw new RuntimeException('Verzia neexistuje.');
                $f['stored_name'] = $ver['stored_name']; $f['mime'] = $ver['mime'];
                $dot = strrpos($f['name'], '.');
                $f['name'] = ($dot ? substr($f['name'], 0, $dot) : $f['name']) . ' (' . date('Y-m-d H-i', strtotime($ver['created_at'])) . ')' . ($dot ? substr($f['name'], $dot) : '');
            }
            $path = storage_dir((int)$f['user_id']) . '/' . $f['stored_name'];
            if (!is_file($path)) throw new RuntimeException('Súbor chýba na disku.');
            send_file($path, $f['name'], $f['mime'], !empty($_GET['inline']) && safe_inline($f['mime']));
        }
        if ($action === 'thumb') {
            send_thumb(file_access($uid, (int)($_GET['id'] ?? 0))['row']);
        }
        if ($action === 'zip') {
            $items = parse_items(explode(',', (string)($_GET['items'] ?? '')));
            $folders = array_map(fn($id) => folder_access($uid, $id)['row'], $items['d']);
            $files = array_map(fn($id) => file_access($uid, $id)['row'], $items['f']);
            $name = count($folders) === 1 && !$files ? $folders[0]['name'] : 'subory-' . date('Y-m-d');
            send_zip($folders, $files, $name);
        }
    } catch (Throwable $ex) {
        http_response_code(404);
        exit(e($ex->getMessage()));
    }
}

/* ==================================================================
 *  POST: JSON API
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Metóda nie je povolená'], 405);
csrf_check();
session_write_close();

try {
    switch ($action) {

    /* ---------------- výpis ---------------- */
    case 'list': {
        $view = $_POST['view'] ?? 'folder';
        $sort = in_array($_POST['sort'] ?? '', ['name', 'updated', 'size'], true) ? $_POST['sort'] : 'name';
        $dir  = ($_POST['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $orderF = ['name' => 'name', 'updated' => 'updated_at', 'size' => 'size'][$sort] . " $dir";
        $orderD = ($sort === 'updated' ? 'updated_at' : 'name') . " $dir";
        $vcnt = "(SELECT COUNT(*) FROM $V v WHERE v.file_id = f.id) AS vcnt";
        $folders = []; $files = []; $crumbs = []; $folderId = null; $perm = 'owner'; $root = 'drive'; $extra = [];

        if ($view === 'search') {
            $s = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string)($_POST['q'] ?? ''))) . '%';
            $folders = q("SELECT * FROM $D WHERE user_id = ? AND deleted_at IS NULL AND name LIKE ? ORDER BY $orderD LIMIT 100", [$uid, $s])->fetchAll();
            $files   = q("SELECT f.*, $vcnt FROM $F f WHERE user_id = ? AND deleted_at IS NULL AND name LIKE ? ORDER BY $orderF LIMIT 300", [$uid, $s])->fetchAll();
        } elseif ($view === 'recent') {
            $files = q("SELECT f.*, $vcnt FROM $F f WHERE user_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 60", [$uid])->fetchAll();
        } elseif ($view === 'starred') {
            $folders = q("SELECT * FROM $D WHERE user_id = ? AND starred = 1 AND deleted_at IS NULL ORDER BY $orderD", [$uid])->fetchAll();
            $files   = q("SELECT f.*, $vcnt FROM $F f WHERE user_id = ? AND starred = 1 AND deleted_at IS NULL ORDER BY $orderF", [$uid])->fetchAll();
        } elseif ($view === 'trash') {
            $folders = q("SELECT * FROM $D WHERE user_id = ? AND trash_root = 1 AND deleted_at IS NOT NULL ORDER BY deleted_at DESC", [$uid])->fetchAll();
            $files   = q("SELECT f.*, 0 vcnt FROM $F f WHERE user_id = ? AND trash_root = 1 AND deleted_at IS NOT NULL ORDER BY deleted_at DESC", [$uid])->fetchAll();
        } elseif ($view === 'photos') {
            $files = q("SELECT f.*, 0 vcnt FROM $F f WHERE user_id = ? AND deleted_at IS NULL AND mime LIKE 'image/%'
                        ORDER BY COALESCE(taken_at, created_at) DESC LIMIT 3000", [$uid])->fetchAll();
            foreach ($files as &$f) $f['taken_at'] = $f['taken_at'] ?: $f['created_at'];
            unset($f);
        } elseif ($view === 'shared') {
            $root = 'shared';
            $sh = q('SELECT * FROM ' . P . 'user_shares WHERE target_id = ?', [$uid])->fetchAll();
            $dIds = []; $fIds = [];
            foreach ($sh as $s) {
                if ($s['item_type'] === 'd') { $dIds[] = (int)$s['item_id']; $extra['perm'][$s['item_id']] = $s['permission']; }
                else { $fIds[] = (int)$s['item_id']; $extra['fperm'][$s['item_id']] = $s['permission']; }
            }
            if ($dIds) $folders = q("SELECT * FROM $D WHERE id IN (" . implode(',', $dIds) . ") AND deleted_at IS NULL ORDER BY $orderD")->fetchAll();
            if ($fIds) $files = q("SELECT f.*, $vcnt FROM $F f WHERE id IN (" . implode(',', $fIds) . ") AND deleted_at IS NULL ORDER BY $orderF")->fetchAll();
        } else {
            $folderId = (int)($_POST['folder'] ?? 0) ?: null;
            $acc = folder_access($uid, $folderId);
            $owner = $acc['owner']; $perm = $acc['perm'];
            if ($perm !== 'owner') { $root = 'shared'; $extra['default_perm'] = $perm; }
            $folders = q("SELECT d.*, (SELECT COUNT(*) FROM $F f WHERE f.folder_id = d.id AND f.deleted_at IS NULL) + (SELECT COUNT(*) FROM $D c WHERE c.parent_id = d.id AND c.deleted_at IS NULL) AS cnt
                          FROM $D d WHERE d.user_id = ? AND d.parent_id <=> ? AND d.deleted_at IS NULL ORDER BY $orderD", [$owner, $folderId])->fetchAll();
            $files   = q("SELECT f.*, $vcnt FROM $F f WHERE f.user_id = ? AND f.folder_id <=> ? AND f.deleted_at IS NULL ORDER BY $orderF", [$owner, $folderId])->fetchAll();
            $crumbs  = breadcrumbs($uid, $folderId);
        }
        json_out(['ok' => true, 'folder' => $folderId, 'crumbs' => $crumbs, 'perm' => $perm, 'root' => $root,
                  'items' => rows_out($uid, $folders, $files, $extra), 'quota' => quota_info($user),
                  'trash_days' => (int)setting('trash_days', '30')]);
    }

    case 'folders': { // pre Select2 – výber cieľovej zložky (vlastné + zdieľané s právom úprav)
        $exclude = [];
        foreach ((array)($_POST['exclude'] ?? []) as $ex) {
            try { $a = folder_access($uid, (int)$ex); $exclude = array_merge($exclude, descendants($a['owner'], (int)$ex)); } catch (Throwable $e) {}
        }
        $out = [['id' => 0, 'text' => 'Môj disk', 'shared' => false]];
        $build = function (int $owner, ?int $rootId, string $prefix) use (&$out, $exclude) {
            $rows = q('SELECT id, parent_id, name FROM ' . P . 'folders WHERE user_id = ? AND deleted_at IS NULL', [$owner])->fetchAll();
            $byParent = [];
            foreach ($rows as $r) $byParent[(int)$r['parent_id']][] = $r;
            $walk = function (int $pid, string $path) use (&$walk, &$out, $byParent, $exclude, $rootId) {
                foreach ($byParent[$pid] ?? [] as $r) {
                    if (in_array((int)$r['id'], $exclude, true)) continue;
                    $p = $path . ' / ' . $r['name'];
                    $out[] = ['id' => (int)$r['id'], 'text' => $p, 'shared' => $rootId !== null];
                    $walk((int)$r['id'], $p);
                }
            };
            if ($rootId === null) { $walk(0, $prefix); }
            else {
                $r = q('SELECT name FROM ' . P . 'folders WHERE id = ? AND deleted_at IS NULL', [$rootId])->fetchColumn();
                if ($r === false || in_array($rootId, $exclude, true)) return;
                $out[] = ['id' => $rootId, 'text' => $prefix . ' / ' . $r, 'shared' => true];
                $walk($rootId, $prefix . ' / ' . $r);
            }
        };
        $build($uid, null, 'Môj disk');
        foreach (q('SELECT s.item_id, s.owner_id FROM ' . P . "user_shares s WHERE s.target_id = ? AND s.item_type = 'd' AND s.permission = 'edit'", [$uid]) as $s) {
            $build((int)$s['owner_id'], (int)$s['item_id'], 'Zdieľané');
        }
        json_out(['ok' => true, 'results' => $out]);
    }

    /* ---------------- vytváranie ---------------- */
    case 'mkdir': {
        $parent = (int)($_POST['parent'] ?? 0) ?: null;
        $acc = need(folder_access($uid, $parent), 'edit');
        $name = clean_name((string)($_POST['name'] ?? ''));
        if (name_exists($acc['owner'], $parent, $name, 'd')) throw new RuntimeException('Zložka s týmto názvom už existuje.');
        q("INSERT INTO $D (user_id, parent_id, name) VALUES (?,?,?)", [$acc['owner'], $parent, $name]);
        log_action($uid, 'mkdir', $name);
        json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    }

    case 'mkfile': { // nový textový súbor
        $parent = (int)($_POST['parent'] ?? 0) ?: null;
        $acc = need(folder_access($uid, $parent), 'edit');
        $name = clean_name((string)($_POST['name'] ?? ''));
        if (!preg_match('/\.[a-z0-9]{1,8}$/i', $name)) $name .= '.txt';
        if (name_exists($acc['owner'], $parent, $name, 'f')) throw new RuntimeException('Súbor s týmto názvom už existuje.');
        $tmp = tmp_file(); touch($tmp);
        $r = store_file($acc['owner'], $parent, $name, $tmp, false);
        json_out(['ok' => true, 'id' => $r['id']]);
    }

    /* ---------------- nahrávanie po častiach (pokračovanie po výpadku) ---------------- */
    case 'upload_init': {
        $folder = (int)($_POST['folder'] ?? 0) ?: null;
        $acc = need(folder_access($uid, $folder), 'edit');
        $name = clean_name((string)($_POST['name'] ?? ''));
        $size = max(0, (int)($_POST['size'] ?? 0));
        $rel = trim(str_replace('\\', '/', (string)($_POST['relpath'] ?? '')), '/');
        $resume = preg_replace('/[^a-f0-9]/', '', (string)($_POST['resume'] ?? ''));
        if ($resume) {
            $up = q('SELECT * FROM ' . P . 'uploads WHERE id = ? AND user_id = ? AND size = ? AND name = ?', [$resume, $uid, $size, $name])->fetch();
            $path = data_path('tmp/chunks/' . $resume);
            if ($up && is_file($path)) {
                $real = filesize($path);
                if ($real !== (int)$up['received']) q('UPDATE ' . P . 'uploads SET received = ? WHERE id = ?', [$real, $resume]);
                json_out(['ok' => true, 'id' => $resume, 'received' => $real, 'chunk' => chunk_size()]);
            }
        }
        $maxMb = (int)setting('max_upload_mb', '0');
        if ($maxMb > 0 && $size > $maxMb * 1048576) throw new RuntimeException("Maximálna veľkosť súboru je $maxMb MB.");
        check_space($acc['owner'], $size);
        $id = bin2hex(random_bytes(16));
        $dir = data_path('tmp/chunks');
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        touch($dir . '/' . $id);
        q('INSERT INTO ' . P . 'uploads (id, user_id, owner_id, folder_id, relpath, name, size) VALUES (?,?,?,?,?,?,?)',
          [$id, $uid, $acc['owner'], $folder, $rel, $name, $size]);
        json_out(['ok' => true, 'id' => $id, 'received' => 0, 'chunk' => chunk_size()]);
    }

    case 'upload_chunk': {
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
        $up = q('SELECT * FROM ' . P . 'uploads WHERE id = ? AND user_id = ?', [$id, $uid])->fetch();
        if (!$up) throw new RuntimeException('Nahrávanie neexistuje alebo vypršalo. Skús to znova.');
        $path = data_path('tmp/chunks/' . $id);
        $have = is_file($path) ? filesize($path) : 0;
        $offset = (int)($_POST['offset'] ?? -1);
        if ($offset !== $have) json_out(['ok' => true, 'received' => $have, 'resync' => true]);
        $chunk = $_FILES['chunk'] ?? null;
        if ((int)$up['size'] > 0) {
            if (!$chunk || $chunk['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Časť súboru sa nepreniesla (kód ' . ($chunk['error'] ?? '?') . ').');
            if ($have + $chunk['size'] > $up['size']) throw new RuntimeException('Neplatná veľkosť dát.');
            $in = fopen($chunk['tmp_name'], 'rb'); $out = fopen($path, 'ab');
            if (!$in || !$out || !flock($out, LOCK_EX)) throw new RuntimeException('Nepodarilo sa zapísať dáta.');
            stream_copy_to_stream($in, $out);
            fflush($out); flock($out, LOCK_UN); fclose($out); fclose($in);
        }
        clearstatcache(true, $path);
        $have = filesize($path);
        q('UPDATE ' . P . 'uploads SET received = ? WHERE id = ?', [$have, $id]);
        if ($have < $up['size']) json_out(['ok' => true, 'received' => $have]);

        // hotovo → presuň do úložiska
        $owner = (int)$up['owner_id'];
        $folder = $up['folder_id'] ? (int)$up['folder_id'] : null;
        need(folder_access($uid, $folder), 'edit');
        check_space($owner, 0);
        if ($up['relpath'] && str_contains($up['relpath'], '/')) $folder = ensure_path($owner, $folder, dirname($up['relpath']));
        $r = store_file($owner, $folder, $up['name'], $path, false);
        q('DELETE FROM ' . P . 'uploads WHERE id = ?', [$id]);
        if (empty($r['unchanged'])) log_action($uid, 'upload', $r['name'] . ' (' . format_bytes($r['size']) . ')' . ($r['versioned'] ? ' – nová verzia' : ''));
        check_quota_warning($owner);
        json_out(['ok' => true, 'done' => true, 'received' => $have, 'file' => $r, 'quota' => quota_info($user)]);
    }

    case 'upload_cancel': {
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
        if (q('DELETE FROM ' . P . 'uploads WHERE id = ? AND user_id = ?', [$id, $uid])->rowCount()) @unlink(data_path('tmp/chunks/' . $id));
        json_out(['ok' => true]);
    }

    /* ---------------- úpravy ---------------- */
    case 'rename': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        $name = clean_name((string)($_POST['name'] ?? ''));
        if ($type === 'd') {
            $a = need(folder_access($uid, $id), 'edit'); $d = $a['row'];
            if (name_exists($a['owner'], $d['parent_id'] ? (int)$d['parent_id'] : null, $name, 'd', $id)) throw new RuntimeException('Zložka s týmto názvom už existuje.');
            q("UPDATE $D SET name = ? WHERE id = ?", [$name, $id]);
        } else {
            $a = need(file_access($uid, $id), 'edit'); $f = $a['row'];
            if (name_exists($a['owner'], $f['folder_id'] ? (int)$f['folder_id'] : null, $name, 'f', $id)) throw new RuntimeException('Súbor s týmto názvom už existuje.');
            q("UPDATE $F SET name = ? WHERE id = ?", [$name, $id]);
        }
        log_action($uid, 'rename', $name);
        json_out(['ok' => true]);
    }

    case 'move': {
        $target = (int)($_POST['target'] ?? 0) ?: null;
        $tacc = need(folder_access($uid, $target), 'edit');
        $items = parse_items();
        $moved = 0;
        db()->beginTransaction();
        foreach ($items['d'] as $id) {
            $a = need(folder_access($uid, $id), 'edit');
            if ($a['owner'] !== $tacc['owner']) throw new RuntimeException('Presun medzi rôznymi vlastníkmi nie je možný – použi kópiu.');
            if ($a['share_root'] === $id) throw new RuntimeException('Zdieľanú zložku nemôžeš presunúť.');
            if ($target && in_array($target, descendants($a['owner'], $id), true)) throw new RuntimeException('Zložku nemožno presunúť do seba samej.');
            q("UPDATE $D SET parent_id = ?, name = ? WHERE id = ?", [$target, unique_name($a['owner'], $target, $a['row']['name'], 'd'), $id]); $moved++;
        }
        foreach ($items['f'] as $id) {
            $a = need(file_access($uid, $id), 'edit');
            if ($a['owner'] !== $tacc['owner']) throw new RuntimeException('Presun medzi rôznymi vlastníkmi nie je možný – použi kópiu.');
            if (($a['row']['folder_id'] ? (int)$a['row']['folder_id'] : null) === $target) continue;
            q("UPDATE $F SET folder_id = ?, name = ? WHERE id = ?", [$target, unique_name($a['owner'], $target, $a['row']['name'], 'f'), $id]); $moved++;
        }
        db()->commit();
        log_action($uid, 'move', "$moved položiek");
        json_out(['ok' => true, 'moved' => $moved]);
    }

    case 'copy': {
        $target = (int)($_POST['target'] ?? 0) ?: null;
        $tacc = need(folder_access($uid, $target), 'edit');
        $items = parse_items();
        $rows = array_map(fn($id) => file_access($uid, $id)['row'], $items['f']);
        check_space($tacc['owner'], array_sum(array_column($rows, 'size')));
        foreach ($rows as $f) {
            $tmp = tmp_file();
            if (!copy(storage_dir((int)$f['user_id']) . '/' . $f['stored_name'], $tmp)) throw new RuntimeException('Kopírovanie zlyhalo.');
            store_file($tacc['owner'], $target, unique_name($tacc['owner'], $target, $f['name'], 'f'), $tmp, false);
        }
        log_action($uid, 'copy', count($rows) . ' súborov');
        check_quota_warning($tacc['owner']);
        json_out(['ok' => true]);
    }

    case 'delete': { // presun do koša
        $items = parse_items();
        $count = 0;
        foreach ($items['d'] as $id) {
            $a = need(folder_access($uid, $id), 'edit');
            if ($a['share_root'] === $id) throw new RuntimeException('Zdieľanú zložku môže zmazať iba vlastník. Môžeš ju odstrániť zo zdieľaných.');
            trash_folder($a['owner'], $id); $count++;
        }
        foreach ($items['f'] as $id) { $a = need(file_access($uid, $id), 'edit'); trash_file($a['owner'], $id); $count++; }
        log_action($uid, 'trash', "$count položiek");
        json_out(['ok' => true, 'count' => $count]);
    }

    case 'restore': {
        $items = parse_items();
        foreach ($items['d'] as $id) restore_item($uid, 'd', $id);
        foreach ($items['f'] as $id) restore_item($uid, 'f', $id);
        log_action($uid, 'restore', count($items['d']) + count($items['f']) . ' položiek');
        json_out(['ok' => true]);
    }

    case 'purge': { // natrvalo z koša
        $items = parse_items();
        foreach ($items['d'] as $id) {
            if (!q("SELECT id FROM $D WHERE id = ? AND user_id = ? AND trash_root = 1", [$id, $uid])->fetch()) throw new RuntimeException('Položka nie je v koši.');
            purge_folder($uid, $id);
        }
        foreach ($items['f'] as $id) {
            $f = q("SELECT * FROM $F WHERE id = ? AND user_id = ? AND trash_root = 1", [$id, $uid])->fetch();
            if (!$f) throw new RuntimeException('Položka nie je v koši.');
            purge_file($f);
        }
        q('UPDATE ' . P . 'users SET quota_warned = 0 WHERE id = ? AND quota_warned = 1', [$uid]);
        log_action($uid, 'purge', count($items['d']) + count($items['f']) . ' položiek');
        json_out(['ok' => true, 'quota' => quota_info($user)]);
    }

    case 'empty_trash': {
        $n = purge_trash($uid);
        log_action($uid, 'empty_trash', "$n položiek");
        json_out(['ok' => true, 'count' => $n, 'quota' => quota_info($user)]);
    }

    case 'star': {
        $items = parse_items();
        $val = !empty($_POST['value']) ? 1 : 0;
        foreach ($items['d'] as $id) q("UPDATE $D SET starred = ? WHERE id = ? AND user_id = ?", [$val, $id, $uid]);
        foreach ($items['f'] as $id) q("UPDATE $F SET starred = ? WHERE id = ? AND user_id = ?", [$val, $id, $uid]);
        json_out(['ok' => true]);
    }

    case 'info': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        $permTxt = ['owner' => 'Vlastník', 'edit' => 'Môžeš upravovať', 'view' => 'Iba na čítanie'];
        if ($type === 'd') {
            $a = folder_access($uid, $id); $d = $a['row'];
            $ids = implode(',', descendants($a['owner'], $id));
            $st = q("SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM $F WHERE folder_id IN ($ids) AND deleted_at IS NULL")->fetch();
            $info = ['Názov' => $d['name'], 'Typ' => 'Zložka', 'Súborov' => (int)$st['c'], 'Veľkosť' => format_bytes($st['s'])];
        } else {
            $a = file_access($uid, $id); $f = $a['row'];
            $vc = q("SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM $V WHERE file_id = ?", [$id])->fetch();
            $info = ['Názov' => $f['name'], 'Typ' => $f['mime'], 'Veľkosť' => format_bytes($f['size']) . ' (' . number_format((float)$f['size'], 0, ',', ' ') . ' B)'];
            if ($vc['c']) $info['Staršie verzie'] = $vc['c'] . ' (' . format_bytes($vc['s']) . ')';
            if ($f['taken_at']) $info['Odfotené'] = date('d.m.Y H:i', strtotime($f['taken_at']));
            $d = $f;
        }
        if ($a['perm'] !== 'owner') $info['Vlastník'] = owner_row($a['owner'])['name'];
        $info['Tvoje oprávnenie'] = $permTxt[$a['perm']];
        $info['Vytvorené'] = date('d.m.Y H:i', strtotime($d['created_at']));
        $info['Zmenené'] = date('d.m.Y H:i', strtotime($d['updated_at']));
        if ($a['perm'] === 'owner') {
            $sl = q('SELECT COUNT(*) FROM ' . P . 'shares WHERE item_type = ? AND item_id = ?', [$type, $id])->fetchColumn();
            $su = q('SELECT COUNT(*) FROM ' . P . 'user_shares WHERE item_type = ? AND item_id = ?', [$type, $id])->fetchColumn();
            $info['Zdieľanie'] = ($sl ? 'verejný odkaz' : 'bez odkazu') . ', ' . $su . ' používateľov';
        }
        json_out(['ok' => true, 'info' => $info]);
    }

    /* ---------------- textový editor ---------------- */
    case 'text': {
        $a = file_access($uid, (int)($_POST['id'] ?? 0)); $f = $a['row'];
        $txt = file_get_contents(storage_dir((int)$f['user_id']) . '/' . $f['stored_name'], false, null, 0, 2000000);
        if ($txt !== '' && !mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1250');
        json_out(['ok' => true, 'text' => $txt, 'truncated' => $f['size'] > 2000000, 'editable' => PERM_LEVEL[$a['perm']] >= 2 && $f['size'] <= 2000000, 'updated' => $f['updated_at']]);
    }

    case 'save_text': {
        $a = need(file_access($uid, (int)($_POST['id'] ?? 0)), 'edit'); $f = $a['row'];
        if ($f['size'] > 2000000) throw new RuntimeException('Súbor je príliš veľký na úpravu v prehliadači.');
        if (!empty($_POST['updated']) && $_POST['updated'] !== $f['updated_at']) throw new RuntimeException('Súbor medzitým zmenil niekto iný. Skopíruj si zmeny a otvor ho znova.');
        $content = (string)($_POST['content'] ?? '');
        check_space($a['owner'], strlen($content));
        $tmp = tmp_file();
        file_put_contents($tmp, $content);
        store_file($a['owner'], $f['folder_id'] ? (int)$f['folder_id'] : null, $f['name'], $tmp, false);
        log_action($uid, 'edit', $f['name']);
        $upd = q("SELECT updated_at FROM $F WHERE id = ?", [$f['id']])->fetchColumn();
        json_out(['ok' => true, 'updated' => $upd]);
    }

    /* ---------------- verzie ---------------- */
    case 'versions': {
        $a = file_access($uid, (int)($_POST['id'] ?? 0)); $f = $a['row'];
        $list = [['id' => 0, 'current' => true, 'size' => (int)$f['size'], 'size_h' => format_bytes($f['size']), 'date' => $f['updated_at']]];
        foreach (q("SELECT * FROM $V WHERE file_id = ? ORDER BY created_at DESC, id DESC", [$f['id']]) as $v) {
            $list[] = ['id' => (int)$v['id'], 'current' => false, 'size' => (int)$v['size'], 'size_h' => format_bytes($v['size']), 'date' => $v['created_at']];
        }
        json_out(['ok' => true, 'name' => $f['name'], 'versions' => $list, 'can_edit' => PERM_LEVEL[$a['perm']] >= 2, 'max' => (int)setting('max_versions', '10')]);
    }

    case 'version_restore': {
        $vid = (int)($_POST['vid'] ?? 0);
        $v = q("SELECT * FROM $V WHERE id = ?", [$vid])->fetch();
        if (!$v) throw new RuntimeException('Verzia neexistuje.');
        $a = need(file_access($uid, (int)$v['file_id']), 'edit'); $f = $a['row'];
        db()->beginTransaction();
        q("INSERT INTO $V (file_id, user_id, stored_name, size, mime, created_at) VALUES (?,?,?,?,?,?)", [$f['id'], $f['user_id'], $f['stored_name'], $f['size'], $f['mime'], $f['updated_at']]);
        q("UPDATE $F SET stored_name = ?, size = ?, mime = ?, updated_at = NOW() WHERE id = ?", [$v['stored_name'], $v['size'], $v['mime'], $f['id']]);
        q("DELETE FROM $V WHERE id = ?", [$vid]);
        db()->commit();
        @unlink(data_path('thumbs/' . $f['user_id'] . '/' . $f['stored_name'] . '.jpg'));
        log_action($uid, 'version_restore', $f['name']);
        json_out(['ok' => true]);
    }

    case 'version_delete': {
        $vid = (int)($_POST['vid'] ?? 0);
        $v = q("SELECT * FROM $V WHERE id = ?", [$vid])->fetch();
        if (!$v) throw new RuntimeException('Verzia neexistuje.');
        need(file_access($uid, (int)$v['file_id']), 'edit');
        @unlink(storage_dir((int)$v['user_id']) . '/' . $v['stored_name']);
        q("DELETE FROM $V WHERE id = ?", [$vid]);
        json_out(['ok' => true, 'quota' => quota_info($user)]);
    }

    /* ---------------- zdieľanie ---------------- */
    case 'share_get': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        $a = need($type === 'd' ? folder_access($uid, $id) : file_access($uid, $id), 'owner');
        $link = q('SELECT * FROM ' . P . 'shares WHERE item_type = ? AND item_id = ? ORDER BY id DESC LIMIT 1', [$type, $id])->fetch();
        $users = q('SELECT s.id, s.permission, u.name, u.email FROM ' . P . 'user_shares s JOIN ' . P . 'users u ON u.id = s.target_id
                    WHERE s.item_type = ? AND s.item_id = ? ORDER BY u.name', [$type, $id])->fetchAll();
        json_out(['ok' => true, 'name' => $a['row']['name'],
            'link' => $link ? ['url' => share_url($link['token']), 'has_password' => (bool)$link['password'], 'expires' => $link['expires_at'] ? substr($link['expires_at'], 0, 10) : '',
                               'downloads' => (int)$link['downloads'], 'expired' => $link['expires_at'] && strtotime($link['expires_at']) < time()] : null,
            'users' => $users]);
    }

    case 'share_link_save': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        need($type === 'd' ? folder_access($uid, $id) : file_access($uid, $id), 'owner');
        $exp = trim((string)($_POST['expires'] ?? ''));
        $exp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp . ' 23:59:59' : null;
        $pwMode = $_POST['password_mode'] ?? 'keep'; // keep | set | clear
        $link = q('SELECT * FROM ' . P . 'shares WHERE item_type = ? AND item_id = ? ORDER BY id DESC LIMIT 1', [$type, $id])->fetch();
        $pw = $link['password'] ?? null;
        if ($pwMode === 'set') {
            $p = (string)($_POST['password'] ?? '');
            if (strlen($p) < 4) throw new RuntimeException('Heslo k odkazu musí mať aspoň 4 znaky.');
            $pw = password_hash($p, PASSWORD_DEFAULT);
        } elseif ($pwMode === 'clear') $pw = null;
        if ($link) {
            q('UPDATE ' . P . 'shares SET password = ?, expires_at = ? WHERE id = ?', [$pw, $exp, $link['id']]);
            $token = $link['token'];
        } else {
            $token = bin2hex(random_bytes(16));
            q('INSERT INTO ' . P . 'shares (user_id, item_type, item_id, token, password, expires_at) VALUES (?,?,?,?,?,?)', [$uid, $type, $id, $token, $pw, $exp]);
            log_action($uid, 'share_link', "$type:$id");
        }
        json_out(['ok' => true, 'url' => share_url($token)]);
    }

    case 'share_link_delete': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        need($type === 'd' ? folder_access($uid, $id) : file_access($uid, $id), 'owner');
        q('DELETE FROM ' . P . 'shares WHERE item_type = ? AND item_id = ? AND user_id = ?', [$type, $id, $uid]);
        json_out(['ok' => true]);
    }

    case 'user_search': { // Select2 – hľadanie používateľov na zdieľanie
        $s = trim((string)($_POST['q'] ?? ''));
        if (mb_strlen($s) < 2) json_out(['ok' => true, 'results' => []]);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
        $rows = q('SELECT id, name, email FROM ' . P . 'users WHERE is_active = 1 AND id <> ? AND (email LIKE ? OR name LIKE ?) ORDER BY name LIMIT 10', [$uid, $like, $like])->fetchAll();
        json_out(['ok' => true, 'results' => array_map(fn($r) => ['id' => (int)$r['id'], 'text' => $r['name'] . ' (' . $r['email'] . ')', 'name' => $r['name'], 'email' => $r['email']], $rows)]);
    }

    case 'share_user_add': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        $a = need($type === 'd' ? folder_access($uid, $id) : file_access($uid, $id), 'owner');
        $perm = ($_POST['permission'] ?? 'view') === 'edit' ? 'edit' : 'view';
        $notify = !empty($_POST['notify']);
        foreach ((array)($_POST['users'] ?? []) as $tid) {
            $tid = (int)$tid;
            $t = q('SELECT * FROM ' . P . 'users WHERE id = ? AND is_active = 1', [$tid])->fetch();
            if (!$t || $tid === $uid) continue;
            q('INSERT INTO ' . P . 'user_shares (owner_id, item_type, item_id, target_id, permission) VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE permission = VALUES(permission)', [$uid, $type, $id, $tid, $perm]);
            if ($notify) {
                send_mail($t['email'], $user['name'] . ' s tebou zdieľa „' . $a['row']['name'] . '“', 'Niekto s tebou zdieľa ' . ($type === 'd' ? 'zložku' : 'súbor'),
                    '<p><b>' . e($user['name']) . '</b> s tebou zdieľa ' . ($type === 'd' ? 'zložku' : 'súbor') . ' <b>' . e($a['row']['name']) . '</b>'
                    . ' (' . ($perm === 'edit' ? 'môžeš upravovať' : 'iba na čítanie') . ').</p><p>Nájdeš ju v sekcii <b>Zdieľané so mnou</b>.</p>',
                    'Otvoriť', cfg('app_url') . '/index.php#shared');
            }
            log_action($uid, 'share_user', "$type:$id → " . $t['email']);
        }
        json_out(['ok' => true]);
    }

    case 'share_user_update': {
        $s = q('SELECT * FROM ' . P . 'user_shares WHERE id = ? AND owner_id = ?', [(int)($_POST['sid'] ?? 0), $uid])->fetch();
        if (!$s) throw new RuntimeException('Zdieľanie neexistuje.');
        q('UPDATE ' . P . 'user_shares SET permission = ? WHERE id = ?', [($_POST['permission'] ?? '') === 'edit' ? 'edit' : 'view', $s['id']]);
        json_out(['ok' => true]);
    }

    case 'share_user_remove': {
        q('DELETE FROM ' . P . 'user_shares WHERE id = ? AND owner_id = ?', [(int)($_POST['sid'] ?? 0), $uid]);
        json_out(['ok' => true]);
    }

    case 'share_leave': { // príjemca si odstráni zdieľanú položku
        $items = parse_items();
        foreach ($items['d'] as $id) q('DELETE FROM ' . P . "user_shares WHERE item_type = 'd' AND item_id = ? AND target_id = ?", [$id, $uid]);
        foreach ($items['f'] as $id) q('DELETE FROM ' . P . "user_shares WHERE item_type = 'f' AND item_id = ? AND target_id = ?", [$id, $uid]);
        json_out(['ok' => true]);
    }
    }
    json_out(['ok' => false, 'error' => 'Neznáma akcia'], 400);
} catch (Throwable $ex) {
    if (db()->inTransaction()) db()->rollBack();
    $msg = $ex instanceof PDOException ? 'Chyba databázy.' : $ex->getMessage();
    if ($ex instanceof PDOException) error_log('[cloud] ' . $ex->getMessage());
    json_out(['ok' => false, 'error' => $msg], 400);
}
