<?php
/* Aktivita: kto čo nahral, zmenil, presunul, zmazal alebo zdieľal – a vrátenie zložky do stavu k dátumu. */

const ACTIVITY_DAYS = 365; // ako dlho sa história uchováva

/** Kto práve koná (nastaví api.php / dav.php / s.php); 0 = návštevník verejného odkazu. */
function actor_id(): ?int { return isset($GLOBALS['CLOUD_ACTOR']) ? (int)$GLOBALS['CLOUD_ACTOR'] : null; }

/** Zapíše udalosť. $row = riadok súboru/zložky (kvôli vlastníkovi, názvu a rodičovi). */
function activity(string $action, string $type, array $row, array $extra = [], ?int $actor = null): void {
    try {
        $folder = $type === 'd' ? ($row['parent_id'] ?? null) : ($row['folder_id'] ?? null);
        q('INSERT INTO ' . P . 'activity (owner_id, actor_id, action, item_type, item_id, name, folder_id, extra) VALUES (?,?,?,?,?,?,?,?)', [
            (int)$row['user_id'], $actor ?? actor_id(), $action, $type, (int)$row['id'], mb_substr((string)$row['name'], 0, 255),
            $folder ? (int)$folder : null, $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {} // pred migráciou / história nesmie zablokovať akciu
}
function activity_by_id(string $action, string $type, int $id, array $extra = []): void {
    $row = q('SELECT * FROM ' . P . ($type === 'd' ? 'folders' : 'files') . ' WHERE id = ?', [$id])->fetch();
    if ($row) activity($action, $type, $row, $extra);
}

const ACTIVITY_LABELS = [
    'upload' => ['nahral(a)', 'bi-cloud-arrow-up', 'success'], 'update' => ['nahral(a) novú verziu', 'bi-arrow-repeat', 'primary'],
    'copy' => ['vytvoril(a) kópiu', 'bi-copy', 'primary'], 'create' => ['vytvoril(a) zložku', 'bi-folder-plus', 'success'],
    'rename' => ['premenoval(a)', 'bi-input-cursor-text', 'primary'], 'move' => ['presunul(a)', 'bi-folder-symlink', 'primary'],
    'trash' => ['presunul(a) do koša', 'bi-trash3', 'danger'], 'restore' => ['obnovil(a) z koša', 'bi-arrow-counterclockwise', 'success'],
    'purge' => ['natrvalo zmazal(a)', 'bi-x-octagon', 'danger'], 'edit' => ['upravil(a)', 'bi-pencil-square', 'primary'],
    'version' => ['obnovil(a) staršiu verziu', 'bi-clock-history', 'primary'], 'share' => ['zdieľal(a)', 'bi-person-plus', 'info'],
    'unshare' => ['zrušil(a) zdieľanie', 'bi-person-dash', 'secondary'], 'link' => ['vytvoril(a) verejný odkaz', 'bi-link-45deg', 'info'],
    'unlink' => ['zrušil(a) verejný odkaz', 'bi-link', 'secondary'], 'lock' => ['zamkol(a) heslom', 'bi-lock', 'warning'],
    'unlock' => ['zrušil(a) heslo', 'bi-unlock', 'secondary'], 'rewind' => ['vrátil(a) do stavu k', 'bi-rewind', 'warning'],
];

/**
 * Zoznam udalostí. $scope: null = celý môj disk (aj čo robím v cudzích zložkách), ['f', id] = súbor, ['d', id] = zložka s celým obsahom.
 * Obsah zamknutých zložiek sa vynechá (ak nie sú odomknuté).
 */
function activity_list(int $uid, ?array $scope, int $before = 0, int $limit = 60): array {
    $w = []; $p = [];
    if ($scope === null) { $w[] = '(a.owner_id = ? OR a.actor_id = ?)'; $p[] = $uid; $p[] = $uid; }
    elseif ($scope[0] === 'f') { $w[] = "a.item_type = 'f' AND a.item_id = ?"; $p[] = (int)$scope[1]; }
    else {
        $acc = folder_access($uid, (int)$scope[1]);
        $ids = implode(',', array_map('intval', descendants($acc['owner'], (int)$scope[1], true)));
        $w[] = "((a.item_type = 'd' AND a.item_id IN ($ids)) OR a.folder_id IN ($ids))";
    }
    if ($before > 0) { $w[] = 'a.id < ?'; $p[] = $before; }
    $rows = q('SELECT a.*, u.name actor_name FROM ' . P . 'activity a LEFT JOIN ' . P . 'users u ON u.id = a.actor_id WHERE ' . implode(' AND ', $w)
        . ' ORDER BY a.id DESC LIMIT ' . (int)$limit, $p)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if ($r['folder_id'] && locked_ancestor((int)$r['folder_id'])) continue;
        $lbl = ACTIVITY_LABELS[$r['action']] ?? [$r['action'], 'bi-dot', 'secondary'];
        $x = $r['extra'] ? (json_decode($r['extra'], true) ?: []) : [];
        $out[] = ['id' => (int)$r['id'], 'action' => $r['action'], 'label' => $lbl[0], 'icon' => $lbl[1], 'color' => $lbl[2],
            'type' => $r['item_type'], 'item' => (int)$r['item_id'], 'name' => $r['name'], 'folder' => $r['folder_id'] ? (int)$r['folder_id'] : 0, 'extra' => $x, 'date' => $r['created_at'],
            'actor' => $r['actor_id'] === null ? 'Systém' : ((int)$r['actor_id'] === 0 ? 'Verejný odkaz' : ((int)$r['actor_id'] === $uid ? 'Ty' : ($r['actor_name'] ?? 'Neznámy používateľ'))),
            'mine' => (int)$r['actor_id'] === $uid];
    }
    return ['items' => $out, 'more' => count($rows) === $limit ? (int)end($rows)['id'] : 0];
}

/**
 * Vráti zložku (aj s obsahom) do stavu k dátumu:
 *  - obnoví z koša položky zmazané po tomto čase,
 *  - súborom zmeneným po tomto čase vráti verziu platnú v tom čase,
 *  - vráti premenovania a presuny (podľa histórie),
 *  - voliteľne presunie do koša položky vytvorené neskôr.
 * Bez $apply iba spočíta, čo by sa stalo.
 */
function folder_rewind(int $uid, int $folderId, string $at, bool $trashNew, bool $apply): array {
    $acc = need(folder_access($uid, $folderId), 'edit');
    $owner = $acc['owner'];
    $ts = strtotime($at);
    if (!$ts || $ts > time()) throw new RuntimeException('Zvoľ dátum a čas v minulosti.');
    $D = date('Y-m-d H:i:s', $ts);
    $F = P . 'files'; $DD = P . 'folders'; $V = P . 'file_versions';
    $tree = descendants($owner, $folderId, true);
    $in = implode(',', array_map('intval', $tree));
    $res = ['restore' => 0, 'content' => 0, 'renames' => 0, 'trash' => 0, 'missing' => 0, 'items' => []];
    $note = function (string $what, string $name) use (&$res) { if (count($res['items']) < 40) $res['items'][] = [$what, $name]; };
    if ($apply) db()->beginTransaction();
    try {
        // 1) zmazané po dátume → obnoviť (koreňové položky koša v strome)
        foreach (q("SELECT * FROM $DD WHERE user_id = ? AND id IN ($in) AND trash_root = 1 AND deleted_at > ?", [$owner, $D]) as $d) {
            if ((int)$d['id'] === $folderId) continue;
            $res['restore']++; $note('restore', $d['name']);
            if ($apply) { restore_item($owner, 'd', (int)$d['id']); activity_by_id('restore', 'd', (int)$d['id']); }
        }
        foreach (q("SELECT * FROM $F WHERE user_id = ? AND folder_id IN ($in) AND trash_root = 1 AND deleted_at > ?", [$owner, $D]) as $f) {
            if ($f['created_at'] > $D) continue; // vznikol až neskôr – neobnovovať
            $res['restore']++; $note('restore', $f['name']);
            if ($apply) { restore_item($owner, 'f', (int)$f['id']); activity_by_id('restore', 'f', (int)$f['id']); }
        }
        // 2) obsah zmenený po dátume → verzia platná v danom čase
        foreach (q("SELECT * FROM $F WHERE user_id = ? AND folder_id IN ($in) AND deleted_at IS NULL AND updated_at > ? AND created_at <= ?", [$owner, $D, $D]) as $f) {
            $v = q("SELECT * FROM $V WHERE file_id = ? AND created_at <= ? ORDER BY created_at DESC, id DESC LIMIT 1", [$f['id'], $D])->fetch();
            if (!$v) { $res['missing']++; continue; } // staršia verzia sa už nezachovala
            if ($v['sha256'] && $v['sha256'] === $f['sha256']) continue;
            $res['content']++; $note('content', $f['name']);
            if ($apply) {
                q("INSERT INTO $V (file_id, user_id, stored_name, size, mime, enc_key, sha256, created_at) VALUES (?,?,?,?,?,?,?,?)",
                  [$f['id'], $f['user_id'], $f['stored_name'], $f['size'], $f['mime'], $f['enc_key'] ?? null, $f['sha256'] ?? null, $f['updated_at']]);
                q("UPDATE $F SET stored_name = ?, size = ?, mime = ?, enc_key = ?, sha256 = ?, updated_at = NOW() WHERE id = ?",
                  [$v['stored_name'], $v['size'], $v['mime'], $v['enc_key'] ?? null, $v['sha256'] ?? null, $f['id']]);
                q("DELETE FROM $V WHERE id = ?", [$v['id']]);
                @unlink(data_path('thumbs/' . $f['user_id'] . '/' . $f['stored_name'] . '.jpg'));
                activity('version', 'f', $f, ['to' => $D]);
            }
        }
        // 3) premenovania a presuny po dátume (od najnovších)
        $ev = q('SELECT * FROM ' . P . "activity WHERE owner_id = ? AND action IN ('rename','move') AND created_at > ? ORDER BY id DESC", [$owner, $D])->fetchAll();
        $done = [];
        foreach ($ev as $e) {
            $x = json_decode((string)$e['extra'], true) ?: [];
            $key = $e['item_type'] . $e['item_id'];
            $tbl = $e['item_type'] === 'd' ? $DD : $F;
            $row = q("SELECT * FROM $tbl WHERE id = ? AND user_id = ? AND deleted_at IS NULL", [$e['item_id'], $owner])->fetch();
            if (!$row) continue;
            $col = $e['item_type'] === 'd' ? 'parent_id' : 'folder_id';
            $cur = $row[$col] ? (int)$row[$col] : null;
            if ($e['action'] === 'rename') {
                $inTree = $e['item_type'] === 'd' ? in_array((int)$row['id'], $tree, true) && (int)$row['id'] !== $folderId : in_array((int)$cur, $tree, true);
                if (!$inTree || empty($x['from'])) continue;
                if ($apply) {
                    $nm = unique_name($owner, $cur, (string)$x['from'], $e['item_type']);
                    q("UPDATE $tbl SET name = ? WHERE id = ?", [$nm, $row['id']]);
                }
            } else {
                $from = isset($x['from']) ? ($x['from'] ? (int)$x['from'] : null) : -1;
                if ($from === -1 || !in_array((int)$from, $tree, true)) continue; // pôvodne nebol v tejto zložke
                if ($from !== null && !q("SELECT id FROM $DD WHERE id = ? AND deleted_at IS NULL", [$from])->fetch()) continue;
                if ($e['item_type'] === 'd' && in_array((int)$from, descendants($owner, (int)$row['id']), true)) continue;
                if ($apply) q("UPDATE $tbl SET $col = ?, name = ? WHERE id = ?", [$from, unique_name($owner, $from, $row['name'], $e['item_type']), $row['id']]);
            }
            if (!isset($done[$key])) { $res['renames']++; $done[$key] = 1; $note($e['action'], $row['name']); }
        }
        // 4) vytvorené po dátume → do koša (voliteľné)
        if ($trashNew) {
            foreach (q("SELECT * FROM $DD WHERE user_id = ? AND id IN ($in) AND id <> ? AND deleted_at IS NULL AND created_at > ?", [$owner, $folderId, $D]) as $d) {
                $res['trash']++; $note('trash', $d['name']);
                if ($apply && q("SELECT deleted_at FROM $DD WHERE id = ?", [$d['id']])->fetchColumn() === null) { trash_folder($owner, (int)$d['id']); activity('trash', 'd', $d); }
            }
            foreach (q("SELECT * FROM $F WHERE user_id = ? AND folder_id IN ($in) AND deleted_at IS NULL AND created_at > ?", [$owner, $D]) as $f) {
                $res['trash']++; $note('trash', $f['name']);
                if ($apply && q("SELECT deleted_at FROM $F WHERE id = ?", [$f['id']])->fetchColumn() === null) { trash_file($owner, (int)$f['id']); activity('trash', 'f', $f); }
            }
        }
        if ($apply) {
            activity('rewind', 'd', $acc['row'], ['to' => $D] + array_diff_key($res, ['items' => 1]));
            db()->commit();
        }
    } catch (Throwable $e) {
        if ($apply && db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return $res;
}
