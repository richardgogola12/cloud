<?php
/* ------------------------------------------------------------------
 *  API pre správu súborov a zložiek
 * ------------------------------------------------------------------ */
require __DIR__ . '/inc/core.php';

$user = require_login();
$uid  = (int)$user['id'];
$GLOBALS['CLOUD_ACTOR'] = $uid;
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
        notify($owner, 'quota', 'Úložisko je takmer plné', 'Využívaš ' . format_bytes($used) . ' z ' . format_bytes($u['quota']) . ' (' . round($pct * 100) . ' %).', 'index.php#trash');
        send_mail($u['email'], 'Úložisko je takmer plné – ' . cfg('app_name'), 'Dochádza ti miesto',
            '<p>Ahoj ' . e($u['name']) . ', využívaš <b>' . format_bytes($used) . '</b> z <b>' . format_bytes($u['quota']) . '</b> (' . round($pct * 100) . ' %).</p>'
            . '<p>Uvoľni miesto zmazaním nepotrebných súborov (nezabudni vysypať kôš a staré verzie) alebo požiadaj administrátora o navýšenie kvóty.</p>',
            'Otvoriť disk', public_url() . '/index.php');
    } elseif ($pct < 0.8 && $u['quota_warned']) {
        q('UPDATE ' . P . 'users SET quota_warned = 0 WHERE id = ?', [$owner]);
    }
}
/** Zdieľaný odkaz: nastavená verejná adresa, inak adresa, na ktorej je web práve otvorený. */
function share_url(string $token): string {
    $base = trim((string)setting('public_url', '')) ?: request_base_url() ?: public_url();
    return rtrim($base, '/') . '/s.php?t=' . $token;
}

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
                $f['stored_name'] = $ver['stored_name']; $f['mime'] = $ver['mime']; $f['size'] = $ver['size']; $f['enc_key'] = $ver['enc_key'] ?? null;
                $dot = strrpos($f['name'], '.');
                $f['name'] = ($dot ? substr($f['name'], 0, $dot) : $f['name']) . ' (' . date('Y-m-d H-i', strtotime($ver['created_at'])) . ')' . ($dot ? substr($f['name'], $dot) : '');
            }
            if (!is_file(stored_path($f))) throw new RuntimeException('Súbor chýba na disku.');
            send_stored($f, $f['name'], !empty($_GET['inline']) && safe_inline($f['mime']));
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

/* odomknutie / zamknutie zložky v tejto relácii (potrebuje zapisovať do session) */
if (in_array($action, ['folder_unlock', 'folder_relock'], true)) {
    try {
        $id = (int)($_POST['id'] ?? 0);
        $f = q('SELECT id, name, user_id, lock_hash FROM ' . P . 'folders WHERE id = ?', [$id])->fetch();
        if (!$f || !$f['lock_hash']) throw new RuntimeException('Zložka nie je zamknutá.');
        if ($action === 'folder_relock') {
            folder_unlock_forget($id);
            session_write_close();
            json_out(['ok' => true]);
        }
        // prístup k zložke (bez ohľadu na zámok) – vlastník alebo zdieľanie
        $GLOBALS['CLOUD_LOCK_BYPASS'] = true;
        folder_access($uid, $id);
        $GLOBALS['CLOUD_LOCK_BYPASS'] = false;
        if (!folder_lock_verify($id, (string)($_POST['password'] ?? ''))) {
            session_write_close();
            log_action($uid, 'folder_unlock_fail', $f['name']);
            json_out(['ok' => false, 'error' => 'Nesprávne heslo zložky.'], 400);
        }
        folder_unlock_store($id);
        session_write_close();
        json_out(['ok' => true, 'mode' => folder_lock_mode($id)]);
    } catch (Throwable $ex) {
        session_write_close();
        json_out(['ok' => false, 'error' => $ex->getMessage()], 400);
    }
}
session_write_close();

try {
    switch ($action) {

    /* ---------------- výpis ---------------- */
    case 'list': {
        $view = $_POST['view'] ?? 'folder';
        $sort = in_array($_POST['sort'] ?? '', ['name', 'updated', 'size', 'created', 'kind'], true) ? $_POST['sort'] : 'name';
        $dir  = ($_POST['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $orderF = ['name' => 'name', 'updated' => 'updated_at', 'size' => 'size', 'created' => 'created_at', 'kind' => "mime $dir, name"][$sort] . " $dir";
        $orderD = (['updated' => 'updated_at', 'created' => 'created_at'][$sort] ?? 'name') . " $dir";
        $vcnt = "(SELECT COUNT(*) FROM $V v WHERE v.file_id = f.id) AS vcnt";
        $folders = []; $files = []; $crumbs = []; $folderId = null; $perm = 'owner'; $root = 'drive'; $extra = [];

        if ($view === 'search') {
            [$folders, $files, $extra] = search_items($uid, $_POST, $orderD, $orderF, $vcnt);
        } elseif ($view === 'recent') {
            $files = q("SELECT f.*, $vcnt FROM $F f WHERE user_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 60", [$uid])->fetchAll();
        } elseif ($view === 'starred') {
            $folders = q("SELECT * FROM $D WHERE user_id = ? AND starred = 1 AND deleted_at IS NULL ORDER BY $orderD", [$uid])->fetchAll();
            $files   = q("SELECT f.*, $vcnt FROM $F f WHERE user_id = ? AND starred = 1 AND deleted_at IS NULL ORDER BY $orderF", [$uid])->fetchAll();
        } elseif ($view === 'trash') {
            $folders = q("SELECT * FROM $D WHERE user_id = ? AND trash_root = 1 AND deleted_at IS NOT NULL ORDER BY deleted_at DESC", [$uid])->fetchAll();
            $files   = q("SELECT f.*, 0 vcnt FROM $F f WHERE user_id = ? AND trash_root = 1 AND deleted_at IS NOT NULL ORDER BY deleted_at DESC", [$uid])->fetchAll();
        } elseif ($view === 'photos') {
            $files = q("SELECT f.*, 0 vcnt FROM $F f WHERE user_id = ? AND deleted_at IS NULL AND (mime LIKE 'image/%' OR mime LIKE 'video/%')
                        ORDER BY COALESCE(taken_at, created_at) DESC LIMIT 3000", [$uid])->fetchAll();
            foreach ($files as &$f) $f['taken_at'] = $f['taken_at'] ?: $f['created_at'];
            unset($f);
        } elseif ($view === 'activity') { // položky podľa poslednej zmeny (kto, čo, kedy)
            $ev = q('SELECT a.*, u.name actor_name FROM ' . P . 'activity a LEFT JOIN ' . P . "users u ON u.id = a.actor_id
                     WHERE (a.owner_id = ? OR a.actor_id = ?) AND a.action NOT IN ('purge') ORDER BY a.id DESC LIMIT 1500", [$uid, $uid])->fetchAll();
            $order = []; $cnt = [];
            foreach ($ev as $e) {
                $k = $e['item_type'] . (int)$e['item_id'];
                $cnt[$k] = ($cnt[$k] ?? 0) + 1;
                if (isset($order[$k])) continue;
                $lbl = ACTIVITY_LABELS[$e['action']] ?? [$e['action'], 'bi-dot', 'secondary'];
                $order[$k] = ['t' => $e['item_type'], 'id' => (int)$e['item_id'], 'action' => $e['action'], 'label' => $lbl[0], 'icon' => $lbl[1], 'color' => $lbl[2], 'date' => $e['created_at'],
                    'actor' => $e['actor_id'] === null ? 'Systém' : ((int)$e['actor_id'] === 0 ? 'Verejný odkaz' : ((int)$e['actor_id'] === $uid ? 'Ty' : ($e['actor_name'] ?? '?')))];
                if (count($order) >= 300) break;
            }
            $dIds = []; $fIds = [];
            foreach ($order as $k => $o) { $o['count'] = $cnt[$k]; $extra['act'][$o['t']][$o['id']] = $o; $o['t'] === 'd' ? $dIds[] = $o['id'] : $fIds[] = $o['id']; }
            $rowsD = $dIds ? q("SELECT * FROM $D WHERE id IN (" . implode(',', $dIds) . ') AND deleted_at IS NULL')->fetchAll() : [];
            $rowsF = $fIds ? q("SELECT f.*, $vcnt FROM $F f WHERE id IN (" . implode(',', $fIds) . ') AND deleted_at IS NULL')->fetchAll() : [];
            // iba položky, ku ktorým mám stále prístup
            $acc = function ($t, $r) use ($uid, &$extra) {
                if ((int)$r['user_id'] === $uid) return true;
                try { $a = $t === 'd' ? folder_access($uid, (int)$r['id']) : file_access($uid, (int)$r['id']); $extra[$t === 'd' ? 'perm' : 'fperm'][$r['id']] = $a['perm']; return true; } catch (Throwable $e) { return false; }
            };
            $rowsD = array_filter($rowsD, fn($r) => $acc('d', $r));
            $rowsF = array_filter($rowsF, fn($r) => $acc('f', $r));
            // zoradené od najnovšej zmeny
            $pos = array_flip(array_keys($order));
            usort($rowsD, fn($a, $b) => $pos['d' . $a['id']] <=> $pos['d' . $b['id']]);
            usort($rowsF, fn($a, $b) => $pos['f' . $a['id']] <=> $pos['f' . $b['id']]);
            $folders = array_values($rowsD); $files = array_values($rowsF);
        } elseif ($view === 'album') {
            $al = album_get($uid, (int)($_POST['album'] ?? 0));
            $files = q("SELECT f.*, 0 vcnt FROM $F f JOIN " . P . "album_items ai ON ai.file_id = f.id WHERE ai.album_id = ? AND f.deleted_at IS NULL
                        ORDER BY COALESCE(f.taken_at, f.created_at) DESC", [$al['id']])->fetchAll();
            foreach ($files as &$f) $f['taken_at'] = $f['taken_at'] ?: $f['created_at'];
            unset($f);
            $files = array_values(array_filter($files, function ($f) use ($uid) { try { file_access($uid, (int)$f['id']); return true; } catch (Throwable $e) { return false; } }));
            $crumbs = [['id' => (int)$al['id'], 'name' => $al['name']]];
            $root = 'album';
        } elseif ($view === 'tag') {
            $tag = q('SELECT * FROM ' . P . 'tags WHERE id = ? AND user_id = ?', [(int)($_POST['tag'] ?? 0), $uid])->fetch();
            if (!$tag) throw new RuntimeException('Štítok neexistuje.');
            $ids = q('SELECT item_id FROM ' . P . 'item_tags WHERE tag_id = ? AND item_type = ?', [$tag['id'], $tag['scope']])->fetchAll(PDO::FETCH_COLUMN);
            $root = 'tag';
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                if ($tag['scope'] === 'd') $folders = q("SELECT * FROM $D WHERE id IN ($in) AND deleted_at IS NULL ORDER BY $orderD")->fetchAll();
                else $files = q("SELECT f.*, $vcnt FROM $F f WHERE id IN ($in) AND deleted_at IS NULL ORDER BY $orderF")->fetchAll();
                // iba položky, ku ktorým má používateľ stále prístup (vlastné alebo zdieľané)
                $folders = array_values(array_filter($folders, function ($d) use ($uid, &$extra) {
                    if ((int)$d['user_id'] === $uid) return true;
                    try { $a = folder_access($uid, (int)$d['id']); $extra['perm'][$d['id']] = $a['perm']; return true; } catch (Throwable $e) { return false; }
                }));
                $files = array_values(array_filter($files, function ($f) use ($uid, &$extra) {
                    if ((int)$f['user_id'] === $uid) return true;
                    try { $a = file_access($uid, (int)$f['id']); $extra['fperm'][$f['id']] = $a['perm']; return true; } catch (Throwable $e) { return false; }
                }));
            }
            $crumbs = [['id' => (int)$tag['id'], 'name' => $tag['name'], 'color' => $tag['color']]];
        } elseif ($view === 'dupes') { // rovnaké súbory (rovnaký obsah podľa SHA-256)
            // malé súbory skontrolujeme hneď, veľké až po odpovedi (inak by požiadavka na pomalom serveri vypršala)
            try { backfill_hashes($uid, 3, 20 * 1048576); } catch (Throwable $e) {}
            defer_task(fn() => backfill_hashes($uid, 300));
            $groups = q("SELECT sha256, COUNT(*) c, MAX(size) sz FROM $F WHERE user_id = ? AND deleted_at IS NULL AND sha256 IS NOT NULL AND sha256 <> '' AND size > 0
                         GROUP BY sha256 HAVING COUNT(*) > 1 ORDER BY MAX(size) * (COUNT(*) - 1) DESC LIMIT 300", [$uid])->fetchAll();
            if ($groups) {
                $shas = array_column($groups, 'sha256');
                $ph = implode(',', array_fill(0, count($shas), '?'));
                $rows = q("SELECT f.*, $vcnt FROM $F f WHERE user_id = ? AND deleted_at IS NULL AND sha256 IN ($ph) ORDER BY created_at", array_merge([$uid], $shas))->fetchAll();
                $order = array_flip($shas);
                usort($rows, fn($a, $b) => [$order[$a['sha256']], $a['created_at']] <=> [$order[$b['sha256']], $b['created_at']]);
                // cesta k zložke pre každý súbor
                $paths = [];
                $all = q('SELECT id, parent_id, name FROM ' . P . 'folders WHERE user_id = ? AND deleted_at IS NULL', [$uid])->fetchAll();
                $byId = array_column($all, null, 'id');
                $pathOf = function (?int $fid) use (&$pathOf, &$paths, $byId): string {
                    if (!$fid) return 'Môj disk';
                    if (isset($paths[$fid])) return $paths[$fid];
                    $r = $byId[$fid] ?? null;
                    return $paths[$fid] = $r ? $pathOf($r['parent_id'] ? (int)$r['parent_id'] : null) . ' / ' . $r['name'] : '?';
                };
                $cnt = array_column($groups, 'c', 'sha256');
                foreach ($rows as $r) $extra['dup'][(int)$r['id']] = ['g' => substr($r['sha256'], 0, 12), 'n' => (int)$cnt[$r['sha256']], 'path' => $pathOf($r['folder_id'] ? (int)$r['folder_id'] : null)];
                $files = $rows;
            }
            $extra['nohash'] = (int)q("SELECT COUNT(*) FROM $F WHERE user_id = ? AND deleted_at IS NULL AND sha256 IS NULL", [$uid])->fetchColumn();
        } elseif ($view === 'byme') { // Zdieľané mnou: s ľuďmi aj verejným odkazom
            $dIds = []; $fIds = [];
            foreach (q('SELECT s.item_type, s.item_id, s.permission, u.name FROM ' . P . 'user_shares s JOIN ' . P . 'users u ON u.id = s.target_id WHERE s.owner_id = ? ORDER BY u.name', [$uid]) as $s) {
                $t = $s['item_type']; $id = (int)$s['item_id'];
                $extra['si'][$t][$id]['users'][] = $s['name'] . ($s['permission'] === 'edit' ? ' (úpravy)' : '');
                $t === 'd' ? $dIds[$id] = 1 : $fIds[$id] = 1;
            }
            foreach (q('SELECT item_type, item_id, expires_at, mode FROM ' . P . 'shares WHERE user_id = ?', [$uid]) as $s) {
                $t = $s['item_type']; $id = (int)$s['item_id'];
                $extra['si'][$t][$id]['link'] = $s['expires_at'] && strtotime($s['expires_at']) < time() ? 'expired' : 'on';
                $extra['si'][$t][$id]['mode'] = $s['mode'] ?? 'view';
                $t === 'd' ? $dIds[$id] = 1 : $fIds[$id] = 1;
            }
            if ($dIds) $folders = q("SELECT * FROM $D WHERE user_id = ? AND id IN (" . implode(',', array_keys($dIds)) . ") AND deleted_at IS NULL ORDER BY $orderD", [$uid])->fetchAll();
            if ($fIds) $files = q("SELECT f.*, $vcnt FROM $F f WHERE user_id = ? AND id IN (" . implode(',', array_keys($fIds)) . ") AND deleted_at IS NULL ORDER BY $orderF", [$uid])->fetchAll();
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
        // režim „vždy pýtať heslo“: zložky, z ktorých si odišiel, sa zamknú
        if (!empty($_SESSION['folder_unlock'])) relock_left_always($view === 'folder' ? folder_chain($folderId) : []);
        // obsah zamknutých zložiek sa mimo nich (hľadanie, nedávne, fotky…) neukazuje
        if ($view !== 'folder') filter_locked($folders, $files);
        // umiestnenie (cesta) vlastných položiek mimo zobrazenia zložky – stĺpec „Umiestnenie“
        if (!in_array($view, ['folder', 'photos', 'album'], true) && empty($extra['dup'])) {
            $need = [];
            foreach ($files as $f) if ((int)$f['user_id'] === $uid) $need[(int)($f['folder_id'] ?? 0)] = 1;
            foreach ($folders as $d) if ((int)$d['user_id'] === $uid) $need[(int)($d['parent_id'] ?? 0)] = 1;
            if ($need) $extra['paths'] = folder_paths($uid, array_keys($need));
        }
        // veľké zložky po častiach: najprv prvých N položiek (rýchle zobrazenie), zvyšok hneď potom
        $total = count($folders) + count($files);
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $limit = max(0, (int)($_POST['limit'] ?? 0));
        if ($offset || $limit) {
            $nd = count($folders);
            $end = $limit ? $offset + $limit : $total;
            $folders = array_slice($folders, min($offset, $nd), max(0, min($end, $nd) - min($offset, $nd)));
            $files = array_slice($files, max(0, $offset - $nd), max(0, $end - max($offset, $nd)));
        }
        // obsah zo zamknutých zložiek sa v prehliadači neukladá do cache; lock_here = zložka, ktorú možno hneď znova zamknúť
        $lockHere = $view === 'folder' ? locked_ancestor($folderId, true) : null;
        $noCache = (bool)$lockHere;
        if (!$noCache && folder_locks()) {
            foreach ($files as $x) if (locked_ancestor($x['folder_id'] ? (int)$x['folder_id'] : null, true)) { $noCache = true; break; }
            if (!$noCache) foreach ($folders as $x) if (locked_ancestor($x['parent_id'] ? (int)$x['parent_id'] : null, true)) { $noCache = true; break; }
        }
        json_out(['ok' => true, 'folder' => $folderId, 'crumbs' => $crumbs, 'perm' => $perm, 'root' => $root,
                  'items' => rows_out($uid, $folders, $files, $extra), 'total' => $total, 'offset' => $offset, 'quota' => quota_info($user),
                  'trash_days' => (int)setting('trash_days', '30'), 'nohash' => $extra['nohash'] ?? 0, 'no_cache' => $noCache, 'lock_here' => $lockHere]);
    }

    case 'folder_lock': { // nastavenie / zmena / zrušenie hesla zložky (iba vlastník)
        $id = (int)($_POST['id'] ?? 0);
        $op = (string)($_POST['op'] ?? 'set');
        $f = q('SELECT id, name, user_id, lock_hash FROM ' . P . 'folders WHERE id = ? AND deleted_at IS NULL', [$id])->fetch();
        if (!$f || (int)$f['user_id'] !== $uid) throw new RuntimeException('Heslo môže nastaviť iba vlastník zložky.');
        $new = (string)($_POST['new'] ?? '');
        $mode = in_array($_POST['mode'] ?? '', LOCK_MODES, true) ? $_POST['mode'] : null;
        if ($op === 'set') {
            if ($f['lock_hash']) throw new RuntimeException('Zložka už je zamknutá.');
        } else {
            if (!$f['lock_hash']) throw new RuntimeException('Zložka nie je zamknutá.');
            // aktuálne heslo zložky, alebo (keď ho vlastník zabudol) heslo k účtu
            $cur = (string)($_POST['password'] ?? '');
            $okAcc = $cur !== '' && password_verify($cur, (string)q('SELECT password FROM ' . P . 'users WHERE id = ?', [$uid])->fetchColumn());
            if (!$okAcc && !folder_lock_verify($id, $cur)) throw new RuntimeException('Nesprávne heslo zložky (alebo k účtu).');
        }
        if ($op === 'remove') {
            q('UPDATE ' . P . 'folders SET lock_hash = NULL WHERE id = ?', [$id]);
            activity_by_id('unlock', 'd', $id);
            log_action($uid, 'folder_lock_remove', $f['name']);
            json_out(['ok' => true]);
        }
        if ($op === 'mode') {
            if (!$mode) throw new RuntimeException('Neplatná voľba.');
            q('UPDATE ' . P . 'folders SET lock_mode = ? WHERE id = ?', [$mode, $id]);
            json_out(['ok' => true]);
        }
        if (mb_strlen($new) < 4) throw new RuntimeException('Heslo zložky musí mať aspoň 4 znaky.');
        q('UPDATE ' . P . 'folders SET lock_hash = ?' . ($mode ? ', lock_mode = ?' : '') . ' WHERE id = ?', array_merge([password_hash($new, PASSWORD_DEFAULT)], $mode ? [$mode] : [], [$id]));
        // verejné odkazy na zamknutú zložku prestanú fungovať
        $links = (int)q('SELECT COUNT(*) FROM ' . P . "shares WHERE item_type = 'd' AND item_id = ?", [$id])->fetchColumn();
        if ($op === 'set') activity_by_id('lock', 'd', $id);
        log_action($uid, $op === 'set' ? 'folder_lock' : 'folder_lock_change', $f['name']);
        json_out(['ok' => true, 'links' => $links]);
    }

    case 'suggest': { // rýchle návrhy pri písaní do vyhľadávania
        $qq = trim((string)($_POST['q'] ?? ''));
        if (mb_strlen($qq) < 1) json_out(['ok' => true, 'items' => [], 'tags' => []]);
        $vc = '0 AS vcnt';
        [$ds, $fs] = search_items($uid, ['q' => $qq, 'content' => '0'], 'updated_at DESC', 'updated_at DESC', $vc, 8);
        $items = rows_out($uid, array_slice($ds, 0, 3), array_slice($fs, 0, 8 - min(3, count($ds))));
        $tags = q('SELECT id, name, color FROM ' . P . 'tags WHERE user_id = ? AND name LIKE ? ORDER BY name LIMIT 3', [$uid, like_esc($qq)])->fetchAll();
        json_out(['ok' => true, 'items' => $items, 'tags' => $tags]);
    }

    /* ---------------- aktivita ---------------- */
    case 'activity': {
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $scope = null;
        if ($type === 'f' && $id) { file_access($uid, $id); $scope = ['f', $id]; }
        elseif ($type === 'd' && $id) { folder_access($uid, $id); $scope = ['d', $id]; }
        json_out(['ok' => true] + activity_list($uid, $scope, (int)($_POST['before'] ?? 0)));
    }
    case 'folder_rewind': { // vrátenie zložky do stavu k dátumu (najprv náhľad, potom vykonanie)
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new RuntimeException('Vyber zložku.');
        $res = folder_rewind($uid, $id, (string)($_POST['at'] ?? ''), !empty($_POST['trash_new']), !empty($_POST['apply']));
        if (!empty($_POST['apply'])) log_action($uid, 'rewind', $id . ' → ' . ($_POST['at'] ?? ''));
        json_out(['ok' => true, 'result' => $res]);
    }

    /* ---------------- fotky: albumy, mapa, spomienky ---------------- */
    case 'albums': {
        json_out(['ok' => true, 'albums' => albums_list($uid)]);
    }
    case 'album_save': {
        $name = clean_name((string)($_POST['name'] ?? ''));
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { album_get($uid, $id); q('UPDATE ' . P . 'albums SET name = ?, updated_at = NOW() WHERE id = ?', [$name, $id]); }
        else { q('INSERT INTO ' . P . 'albums (user_id, name) VALUES (?,?)', [$uid, $name]); $id = (int)db()->lastInsertId(); }
        json_out(['ok' => true, 'id' => $id]);
    }
    case 'album_delete': {
        album_get($uid, (int)($_POST['id'] ?? 0));
        q('DELETE FROM ' . P . 'albums WHERE id = ? AND user_id = ?', [(int)$_POST['id'], $uid]);
        json_out(['ok' => true]);
    }
    case 'album_add': {
        $al = album_get($uid, (int)($_POST['album'] ?? 0));
        $n = 0;
        foreach (parse_items()['f'] as $fid) {
            file_access($uid, $fid);
            $n += q('INSERT IGNORE INTO ' . P . 'album_items (album_id, file_id) VALUES (?,?)', [$al['id'], $fid])->rowCount();
        }
        q('UPDATE ' . P . 'albums SET updated_at = NOW() WHERE id = ?', [$al['id']]);
        json_out(['ok' => true, 'added' => $n, 'name' => $al['name']]);
    }
    case 'album_remove': {
        $al = album_get($uid, (int)($_POST['album'] ?? 0));
        $ids = parse_items()['f'];
        if ($ids) q('DELETE FROM ' . P . 'album_items WHERE album_id = ? AND file_id IN (' . implode(',', array_map('intval', $ids)) . ')', [$al['id']]);
        json_out(['ok' => true]);
    }
    case 'album_cover': {
        $al = album_get($uid, (int)($_POST['album'] ?? 0));
        q('UPDATE ' . P . 'albums SET cover_id = ? WHERE id = ?', [(int)($_POST['file'] ?? 0) ?: null, $al['id']]);
        json_out(['ok' => true]);
    }
    case 'photo_map': {
        $pending = photo_meta_pending($uid);
        if ($pending) defer_task(fn() => backfill_photo_meta($uid, 60)); // staršie fotky sa doplnia na pozadí
        $rows = q("SELECT f.id, f.name, f.folder_id, f.user_id, f.lat, f.lng, f.updated_at, f.taken_at, f.created_at, f.mime FROM $F f
                   WHERE f.user_id = ? AND f.deleted_at IS NULL AND f.lat IS NOT NULL ORDER BY COALESCE(f.taken_at, f.created_at) DESC LIMIT 5000", [$uid])->fetchAll();
        $none = [];
        filter_locked($none, $rows);
        json_out(['ok' => true, 'pending' => $pending, 'points' => array_map(fn($r) => [(int)$r['id'], (float)$r['lat'], (float)$r['lng'], $r['name'], $r['taken_at'] ?: $r['created_at'], $r['updated_at']], $rows)]);
    }
    case 'memories': {
        json_out(['ok' => true, 'groups' => photo_memories($uid)]);
    }

    case 'digest_preview': { // obsah súhrnu bez odoslania
        $d = digest_data($user) ?? ['uploaded' => 0, 'uploaded_size' => 0, 'by_others' => [], 'public' => 0, 'shared' => [], 'trash' => 0, 'trash_size' => 0, 'used' => used_space($uid), 'quota' => (int)$user['quota'], 'memories' => 0];
        json_out(['ok' => true, 'data' => $d, 'html' => digest_html($user, $d)]);
    }
    case 'digest_test': { // ukážka týždenného súhrnu e-mailom hneď teraz
        $d = digest_data($user) ?? ['uploaded' => 0, 'uploaded_size' => 0, 'by_others' => [], 'public' => 0, 'shared' => [], 'trash' => 0, 'trash_size' => 0, 'used' => used_space($uid), 'quota' => (int)$user['quota'], 'memories' => 0];
        $err = null;
        if (!send_mail($user['email'], 'Týždenný prehľad – ' . cfg('app_name'), 'Tvoj týždeň v ' . cfg('app_name'), digest_html($user, $d), 'Otvoriť disk', public_url() . '/index.php', $err))
            throw new RuntimeException('E-mail sa nepodarilo odoslať' . ($err ? ': ' . $err : '') . '.');
        json_out(['ok' => true]);
    }

    case 'search_meta': { // údaje pre panel rozšíreného vyhľadávania
        $types = [];
        foreach (SEARCH_TYPES as $k => $t) $types[] = ['id' => $k, 'label' => $t['label'], 'icon' => $t['icon']];
        json_out(['ok' => true, 'types' => $types, 'content' => content_index_on(),
                  'tags' => q('SELECT id, name, color FROM ' . P . 'tags WHERE user_id = ? ORDER BY name', [$uid])->fetchAll()]);
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
        $newId = (int)db()->lastInsertId(); // ID treba prečítať hneď – log_action vkladá ďalší riadok
        activity_by_id('create', 'd', $newId);
        log_action($uid, 'mkdir', $name);
        json_out(['ok' => true, 'id' => $newId]);
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
        defer_task(fn() => check_quota_warning($owner)); // až po odpovedi – ďalší súbor sa môže začať nahrávať hneď
        defer_task(fn() => check_disk_warning());
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
            if ($name !== $d['name']) activity_by_id('rename', 'd', $id, ['from' => $d['name']]);
        } else {
            $a = need(file_access($uid, $id), 'edit'); $f = $a['row'];
            if (name_exists($a['owner'], $f['folder_id'] ? (int)$f['folder_id'] : null, $name, 'f', $id)) throw new RuntimeException('Súbor s týmto názvom už existuje.');
            q("UPDATE $F SET name = ? WHERE id = ?", [$name, $id]);
            if ($name !== $f['name']) activity_by_id('rename', 'f', $id, ['from' => $f['name']]);
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
            activity_by_id('move', 'd', $id, ['from' => $a['row']['parent_id'] ? (int)$a['row']['parent_id'] : null, 'to' => $target]);
        }
        foreach ($items['f'] as $id) {
            $a = need(file_access($uid, $id), 'edit');
            if ($a['owner'] !== $tacc['owner']) throw new RuntimeException('Presun medzi rôznymi vlastníkmi nie je možný – použi kópiu.');
            if (($a['row']['folder_id'] ? (int)$a['row']['folder_id'] : null) === $target) continue;
            q("UPDATE $F SET folder_id = ?, name = ? WHERE id = ?", [$target, unique_name($a['owner'], $target, $a['row']['name'], 'f'), $id]); $moved++;
            activity_by_id('move', 'f', $id, ['from' => $a['row']['folder_id'] ? (int)$a['row']['folder_id'] : null, 'to' => $target]);
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
        $GLOBALS['CLOUD_ACT_HINT'] = 'copy';
        foreach ($rows as $f) {
            $tmp = stored_to_temp($f);
            store_file($tacc['owner'], $target, unique_name($tacc['owner'], $target, $f['name'], 'f'), $tmp, false);
        }
        log_action($uid, 'copy', count($rows) . ' súborov');
        defer_task(fn() => check_quota_warning($tacc['owner']));
        json_out(['ok' => true]);
    }

    case 'delete': { // presun do koša
        $items = parse_items();
        $count = 0;
        foreach ($items['d'] as $id) {
            $a = need(folder_access($uid, $id), 'edit');
            if ($a['share_root'] === $id) throw new RuntimeException('Zdieľanú zložku môže zmazať iba vlastník. Môžeš ju odstrániť zo zdieľaných.');
            trash_folder($a['owner'], $id); $count++;
            activity('trash', 'd', $a['row']);
        }
        foreach ($items['f'] as $id) { $a = need(file_access($uid, $id), 'edit'); trash_file($a['owner'], $id); $count++; activity('trash', 'f', $a['row']); }
        log_action($uid, 'trash', "$count položiek");
        json_out(['ok' => true, 'count' => $count]);
    }

    case 'restore': {
        $items = parse_items();
        foreach ($items['d'] as $id) { restore_item($uid, 'd', $id); activity_by_id('restore', 'd', $id); }
        foreach ($items['f'] as $id) { restore_item($uid, 'f', $id); activity_by_id('restore', 'f', $id); }
        log_action($uid, 'restore', count($items['d']) + count($items['f']) . ' položiek');
        json_out(['ok' => true]);
    }

    case 'purge': { // natrvalo z koša
        $items = parse_items();
        foreach ($items['d'] as $id) {
            if (!q("SELECT id FROM $D WHERE id = ? AND user_id = ? AND trash_root = 1", [$id, $uid])->fetch()) throw new RuntimeException('Položka nie je v koši.');
            activity_by_id('purge', 'd', $id);
            purge_folder($uid, $id);
        }
        foreach ($items['f'] as $id) {
            $f = q("SELECT * FROM $F WHERE id = ? AND user_id = ? AND trash_root = 1", [$id, $uid])->fetch();
            if (!$f) throw new RuntimeException('Položka nie je v koši.');
            activity('purge', 'f', $f);
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
        foreach ($items['d'] as $id) q("UPDATE $D SET starred = ?, updated_at = updated_at WHERE id = ? AND user_id = ?", [$val, $id, $uid]);
        foreach ($items['f'] as $id) q("UPDATE $F SET starred = ?, updated_at = updated_at WHERE id = ? AND user_id = ?", [$val, $id, $uid]);
        json_out(['ok' => true]);
    }

    case 'prefs_save': { // osobné nastavenia zobrazenia (napr. stĺpce v zozname) – platia na webe aj v aplikácii
        $key = (string)($_POST['key'] ?? '');
        if ($key === 'digest') {
            $prefs = user_prefs($user);
            $prefs['digest'] = ($_POST['value'] ?? '1') === '1';
            q('UPDATE ' . P . 'users SET prefs = ? WHERE id = ?', [json_encode($prefs, JSON_UNESCAPED_UNICODE), $uid]);
            json_out(['ok' => true]);
        }
        if ($key !== 'columns') throw new RuntimeException('Neznáme nastavenie.');
        $val = json_decode((string)($_POST['value'] ?? ''), true);
        $clean = [];
        if (is_array($val)) foreach ($val as $view => $cols) {
            if (!isset(LIST_VIEWS[$view]) || !is_array($cols)) continue;
            $clean[$view] = array_values(array_unique(array_filter($cols, fn($c) => is_string($c) && isset(LIST_COLUMNS[$c]))));
        }
        $prefs = user_prefs($user);
        $prefs['columns'] = $clean;
        q('UPDATE ' . P . 'users SET prefs = ? WHERE id = ?', [json_encode($prefs, JSON_UNESCAPED_UNICODE), $uid]);
        json_out(['ok' => true, 'columns' => $clean]);
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
        // umiestnenie (cesta k nadradenej zložke) – napr. pri duplicitách vidno, ktorá je ktorá
        $parent = $type === 'd' ? ($d['parent_id'] ? (int)$d['parent_id'] : null) : ($f['folder_id'] ? (int)$f['folder_id'] : null);
        $loc = null;
        try {
            $cr = $parent ? breadcrumbs($uid, $parent) : [];
            $loc = ['path' => implode(' / ', array_merge([$a['perm'] === 'owner' ? 'Môj disk' : 'Zdieľané so mnou'], array_column($cr, 'name'))), 'folder' => $parent ?? 0];
        } catch (Throwable $e) {}
        $info['Vytvorené'] = date('d.m.Y H:i', strtotime($d['created_at']));
        $info['Zmenené'] = date('d.m.Y H:i', strtotime($d['updated_at']));
        if ($a['perm'] === 'owner') {
            $sl = q('SELECT COUNT(*) FROM ' . P . 'shares WHERE item_type = ? AND item_id = ?', [$type, $id])->fetchColumn();
            $su = q('SELECT COUNT(*) FROM ' . P . 'user_shares WHERE item_type = ? AND item_id = ?', [$type, $id])->fetchColumn();
            $info['Zdieľanie'] = ($sl ? 'verejný odkaz' : 'bez odkazu') . ', ' . $su . ' používateľov';
        }
        json_out(['ok' => true, 'info' => $info, 'location' => $loc]);
    }

    /* ---------------- textový editor ---------------- */
    case 'text': {
        $a = file_access($uid, (int)($_POST['id'] ?? 0)); $f = $a['row'];
        $txt = stored_read($f, 2000000);
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
        $GLOBALS['CLOUD_ACT_HINT'] = 'edit';
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
        q("INSERT INTO $V (file_id, user_id, stored_name, size, mime, enc_key, sha256, created_at) VALUES (?,?,?,?,?,?,?,?)",
          [$f['id'], $f['user_id'], $f['stored_name'], $f['size'], $f['mime'], $f['enc_key'] ?? null, $f['sha256'] ?? null, $f['updated_at']]);
        q("UPDATE $F SET stored_name = ?, size = ?, mime = ?, enc_key = ?, sha256 = ?, updated_at = NOW() WHERE id = ?",
          [$v['stored_name'], $v['size'], $v['mime'], $v['enc_key'] ?? null, $v['sha256'] ?? null, $f['id']]);
        q("DELETE FROM $V WHERE id = ?", [$vid]);
        db()->commit();
        @unlink(data_path('thumbs/' . $f['user_id'] . '/' . $f['stored_name'] . '.jpg'));
        activity('version', 'f', $f, ['date' => $v['created_at']]);
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
        $users = q('SELECT s.id, s.permission, u.id AS uid, u.name, u.email, u.avatar_key, u.avatar_v FROM ' . P . 'user_shares s JOIN ' . P . 'users u ON u.id = s.target_id
                    WHERE s.item_type = ? AND s.item_id = ? ORDER BY u.name', [$type, $id])->fetchAll();
        $users = array_map(fn($r) => ['id' => (int)$r['id'], 'permission' => $r['permission'], 'name' => $r['name'], 'email' => $r['email'],
            'avatar' => avatar_url(['id' => $r['uid'], 'avatar_key' => $r['avatar_key'], 'avatar_v' => $r['avatar_v']])], $users);
        json_out(['ok' => true, 'name' => $a['row']['name'],
            'link' => $link ? ['url' => share_url($link['token']), 'has_password' => (bool)$link['password'], 'expires' => $link['expires_at'] ? substr($link['expires_at'], 0, 10) : '',
                               'downloads' => (int)$link['downloads'], 'expired' => $link['expires_at'] && strtotime($link['expires_at']) < time(),
                               'mode' => $link['mode'] ?? 'view', 'uploads' => (int)($link['uploads'] ?? 0)] : null,
            'users' => $users]);
    }

    case 'share_link_save': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        need($type === 'd' ? folder_access($uid, $id) : file_access($uid, $id), 'owner');
        $exp = trim((string)($_POST['expires'] ?? ''));
        $exp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp . ' 23:59:59' : null;
        $pwMode = $_POST['password_mode'] ?? 'keep'; // keep | set | clear
        $mode = $type === 'd' && in_array($_POST['mode'] ?? '', ['upload', 'both'], true) ? $_POST['mode'] : 'view';
        $link = q('SELECT * FROM ' . P . 'shares WHERE item_type = ? AND item_id = ? ORDER BY id DESC LIMIT 1', [$type, $id])->fetch();
        $pw = $link['password'] ?? null;
        if ($pwMode === 'set') {
            $p = (string)($_POST['password'] ?? '');
            if (strlen($p) < 4) throw new RuntimeException('Heslo k odkazu musí mať aspoň 4 znaky.');
            $pw = password_hash($p, PASSWORD_DEFAULT);
        } elseif ($pwMode === 'clear') $pw = null;
        if ($link) {
            q('UPDATE ' . P . 'shares SET password = ?, expires_at = ?, mode = ? WHERE id = ?', [$pw, $exp, $mode, $link['id']]);
            $token = $link['token'];
        } else {
            $token = bin2hex(random_bytes(16));
            q('INSERT INTO ' . P . 'shares (user_id, item_type, item_id, token, password, expires_at, mode) VALUES (?,?,?,?,?,?,?)', [$uid, $type, $id, $token, $pw, $exp, $mode]);
            activity_by_id('link', $type, $id, ['mode' => $mode]);
            log_action($uid, 'share_link', "$type:$id");
        }
        json_out(['ok' => true, 'url' => share_url($token)]);
    }

    case 'share_link_delete': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        need($type === 'd' ? folder_access($uid, $id) : file_access($uid, $id), 'owner');
        if (q('DELETE FROM ' . P . 'shares WHERE item_type = ? AND item_id = ? AND user_id = ?', [$type, $id, $uid])->rowCount()) activity_by_id('unlink', $type, $id);
        json_out(['ok' => true]);
    }

    case 'user_search': { // Select2 – hľadanie používateľov na zdieľanie (meno alebo e-mail)
        $s = trim((string)($_POST['q'] ?? ''));
        if (mb_strlen($s) < 2) json_out(['ok' => true, 'results' => []]);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($s)) . '%';
        $rows = q('SELECT id, name, email, is_active, avatar_key, avatar_v FROM ' . P . 'users WHERE id <> ? AND (LOWER(email) LIKE ? OR LOWER(name) LIKE ?) ORDER BY is_active DESC, name LIMIT 10', [$uid, $like, $like])->fetchAll();
        $out = array_map(fn($r) => ['id' => (int)$r['id'], 'text' => $r['name'] . ' (' . $r['email'] . ')', 'name' => $r['name'],
            'email' => $r['email'] . ($r['is_active'] ? '' : ' · účet čaká na schválenie'), 'avatar' => avatar_url($r)], $rows);
        if (!$out) {
            $me = mb_strtolower($user['email'] ?? '');
            if ($me !== '' && (str_contains($me, mb_strtolower($s)) || str_contains(mb_strtolower($user['name'] ?? ''), mb_strtolower($s))))
                $out[] = ['id' => '', 'text' => 'To je tvoj vlastný účet – zdieľať môžeš s inými používateľmi.', 'disabled' => true];
            elseif (filter_var($s, FILTER_VALIDATE_EMAIL))
                $out[] = ['id' => '', 'text' => 'Nikto s e-mailom ' . $s . ' tu nemá účet. Nech sa zaregistruje, alebo mu pošli verejný odkaz.', 'disabled' => true];
        }
        json_out(['ok' => true, 'results' => $out]);
    }

    case 'share_user_add': {
        $type = ($_POST['type'] ?? '') === 'd' ? 'd' : 'f';
        $id = (int)($_POST['id'] ?? 0);
        $a = need($type === 'd' ? folder_access($uid, $id) : file_access($uid, $id), 'owner');
        $perm = ($_POST['permission'] ?? 'view') === 'edit' ? 'edit' : 'view';
        $notify = !empty($_POST['notify']);
        foreach ((array)($_POST['users'] ?? []) as $tid) {
            $tid = (int)$tid;
            $t = q('SELECT * FROM ' . P . 'users WHERE id = ?', [$tid])->fetch();
            if (!$t || $tid === $uid) continue;
            q('INSERT INTO ' . P . 'user_shares (owner_id, item_type, item_id, target_id, permission) VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE permission = VALUES(permission)', [$uid, $type, $id, $tid, $perm]);
            notify($tid, 'share', $user['name'] . ' s tebou zdieľa ' . ($type === 'd' ? 'zložku' : 'súbor'),
                '„' . $a['row']['name'] . '“ – ' . ($perm === 'edit' ? 'môžeš upravovať' : 'iba na čítanie'), 'index.php#shared');
            if ($notify) {
                send_mail($t['email'], $user['name'] . ' s tebou zdieľa „' . $a['row']['name'] . '“', 'Niekto s tebou zdieľa ' . ($type === 'd' ? 'zložku' : 'súbor'),
                    '<p><b>' . e($user['name']) . '</b> s tebou zdieľa ' . ($type === 'd' ? 'zložku' : 'súbor') . ' <b>' . e($a['row']['name']) . '</b>'
                    . ' (' . ($perm === 'edit' ? 'môžeš upravovať' : 'iba na čítanie') . ').</p><p>Nájdeš ju v sekcii <b>Zdieľané so mnou</b>.</p>',
                    'Otvoriť', public_url() . '/index.php#shared');
            }
            activity('share', $type, $a['row'], ['to' => $t['name'], 'perm' => $perm]);
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
        $s = q('SELECT s.*, u.name FROM ' . P . 'user_shares s JOIN ' . P . 'users u ON u.id = s.target_id WHERE s.id = ? AND s.owner_id = ?', [(int)($_POST['sid'] ?? 0), $uid])->fetch();
        if ($s) activity_by_id('unshare', $s['item_type'], (int)$s['item_id'], ['to' => $s['name']]);
        q('DELETE FROM ' . P . 'user_shares WHERE id = ? AND owner_id = ?', [(int)($_POST['sid'] ?? 0), $uid]);
        json_out(['ok' => true]);
    }

    /* ---------------- štítky a farby ---------------- */
    case 'tags': {
        $rows = q('SELECT t.*, (SELECT COUNT(*) FROM ' . P . 'item_tags it WHERE it.tag_id = t.id) AS cnt FROM ' . P . 'tags t WHERE t.user_id = ? ORDER BY t.scope DESC, t.name', [$uid])->fetchAll();
        json_out(['ok' => true, 'tags' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'color' => $t['color'], 'scope' => $t['scope'], 'count' => (int)$t['cnt']], $rows)]);
    }

    case 'tag_save': {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 50) throw new RuntimeException('Názov štítka musí mať 1 až 50 znakov.');
        $color = valid_color((string)($_POST['color'] ?? '')) ?? '#1a73e8';
        try {
            if ($id) {
                $st = q('UPDATE ' . P . 'tags SET name = ?, color = ? WHERE id = ? AND user_id = ?', [$name, $color, $id, $uid]);
            } else {
                $scope = ($_POST['scope'] ?? '') === 'd' ? 'd' : 'f';
                q('INSERT INTO ' . P . 'tags (user_id, name, color, scope) VALUES (?,?,?,?)', [$uid, $name, $color, $scope]);
                $id = (int)db()->lastInsertId();
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') throw new RuntimeException('Štítok s týmto názvom už existuje.');
            throw $e;
        }
        json_out(['ok' => true, 'id' => $id]);
    }

    case 'tag_delete': {
        q('DELETE FROM ' . P . 'tags WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $uid]);
        json_out(['ok' => true]);
    }

    case 'tags_apply': { // pridá/odoberie štítky vybraným položkám (štítky sú osobné – stačí prístup na čítanie)
        $items = parse_items();
        $add = array_map('intval', (array)($_POST['add'] ?? []));
        $remove = array_map('intval', (array)($_POST['remove'] ?? []));
        $mine = [];
        foreach (q('SELECT id, scope FROM ' . P . 'tags WHERE user_id = ?', [$uid]) as $t) $mine[(int)$t['id']] = $t['scope'];
        $n = 0;
        foreach (['d' => $items['d'], 'f' => $items['f']] as $type => $ids) {
            foreach ($ids as $id) {
                $type === 'd' ? folder_access($uid, $id) : file_access($uid, $id);
                foreach ($add as $tid) if (($mine[$tid] ?? null) === $type) { q('INSERT IGNORE INTO ' . P . 'item_tags (tag_id, item_type, item_id) VALUES (?,?,?)', [$tid, $type, $id]); $n++; }
                foreach ($remove as $tid) if (($mine[$tid] ?? null) === $type) q('DELETE FROM ' . P . 'item_tags WHERE tag_id = ? AND item_type = ? AND item_id = ?', [$tid, $type, $id]);
            }
        }
        json_out(['ok' => true, 'applied' => $n]);
    }

    case 'color_set': {
        $items = parse_items();
        $color = valid_color((string)($_POST['color'] ?? ''));
        foreach ($items['d'] as $id) { need(folder_access($uid, $id), 'edit'); q("UPDATE $D SET color = ?, updated_at = updated_at WHERE id = ?", [$color, $id]); }
        foreach ($items['f'] as $id) { need(file_access($uid, $id), 'edit'); q("UPDATE $F SET color = ?, updated_at = updated_at WHERE id = ?", [$color, $id]); }
        json_out(['ok' => true]);
    }

    case 'notifications': {
        json_out(['ok' => true, 'items' => notifications_list($uid, 30), 'unread' => notifications_unread($uid)]);
    }

    case 'notifications_unread': {
        $last = q('SELECT MAX(id) FROM ' . P . 'notifications WHERE user_id = ?', [$uid])->fetchColumn();
        json_out(['ok' => true, 'unread' => notifications_unread($uid), 'last' => (int)$last, 'ver' => APP_VERSION]);
    }

    case 'notifications_read': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) q('UPDATE ' . P . 'notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [$id, $uid]);
        else q('UPDATE ' . P . 'notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$uid]);
        json_out(['ok' => true]);
    }

    case 'notifications_clear': {
        q('DELETE FROM ' . P . 'notifications WHERE user_id = ?', [$uid]);
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
} catch (FolderLocked $ex) {
    if (db()->inTransaction()) db()->rollBack();
    json_out(['ok' => false, 'error' => $ex->getMessage(), 'locked' => ['id' => $ex->folderId, 'name' => $ex->folderName]], 400);
} catch (Throwable $ex) {
    if (db()->inTransaction()) db()->rollBack();
    $msg = $ex instanceof PDOException ? 'Chyba databázy.' : $ex->getMessage();
    if ($ex instanceof PDOException) error_log('[cloud] ' . $ex->getMessage());
    // chyby nahrávania zapíš do záznamov (Administrácia → Záznamy), aby sa dali dohľadať
    if (str_starts_with($action, 'upload') || in_array($action, ['copy', 'save_text', 'mkfile'], true)) {
        log_action($uid, 'error_' . $action, ($ex instanceof PDOException ? 'DB: ' . $ex->getMessage() : $msg) . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine());
    }
    json_out(['ok' => false, 'error' => $msg], 400);
}
