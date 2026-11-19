<?php
/* Zložky zamknuté heslom.
 * Obsah zamknutej zložky (aj podzložiek) je dostupný iba po odomknutí v aktuálnej relácii prehliadača
 * (alebo aplikácie). Platí pre vlastníka aj pre tých, s ktorými je zložka zdieľaná. */

const LOCK_TTL = 7200; // odomknutie platí 2 hodiny (alebo do odhlásenia / ručného zamknutia)

class FolderLocked extends RuntimeException {
    public function __construct(public int $folderId, public string $folderName) {
        parent::__construct('Zložka „' . $folderName . '“ je zamknutá heslom.');
    }
}

/** všetky zamknuté zložky: id => [name, user_id] (zvyčajne prázdne → nulová réžia) */
function folder_locks(bool $refresh = false): array {
    static $l = null;
    if ($l === null || $refresh) {
        try { $l = q('SELECT id, name, user_id FROM ' . P . 'folders WHERE lock_hash IS NOT NULL')->fetchAll(PDO::FETCH_UNIQUE); }
        catch (Throwable $e) { $l = []; } // pred migráciou
    }
    return $l;
}
function folder_is_locked(int $id): bool { return isset(folder_locks()[$id]); }
function folder_unlocked(int $id): bool { return (int)($_SESSION['folder_unlock'][$id] ?? 0) > time(); }

/** Prvá zamknutá (a neodomknutá) zložka na ceste od $id ku koreňu (vrátane $id). */
function locked_ancestor(?int $id, bool $strict = false): ?array {
    if (!$id || (!$strict && !empty($GLOBALS['CLOUD_LOCK_BYPASS']))) return null;
    $L = folder_locks();
    if (!$L) return null;
    static $parent = [];
    $guard = 0;
    while ($id && $guard++ < 200) {
        if (isset($L[$id]) && ($strict || !folder_unlocked($id))) return ['id' => $id, 'name' => (string)$L[$id]['name']];
        if (!array_key_exists($id, $parent)) $parent[$id] = ((int)q('SELECT parent_id FROM ' . P . 'folders WHERE id = ?', [$id])->fetchColumn()) ?: null;
        $id = $parent[$id];
    }
    return null;
}
function lock_guard(?int $id): void {
    if ($l = locked_ancestor($id)) throw new FolderLocked($l['id'], $l['name']);
}
/** Z výpisu (hľadanie, nedávne, fotky…) vyhodí obsah zamknutých zložiek. Samotná zamknutá zložka ostane viditeľná. */
function filter_locked(array &$folders, array &$files): void {
    if (!folder_locks()) return;
    $folders = array_values(array_filter($folders, fn($d) => !locked_ancestor($d['parent_id'] ? (int)$d['parent_id'] : null)));
    $files = array_values(array_filter($files, fn($f) => !locked_ancestor($f['folder_id'] ? (int)$f['folder_id'] : null)));
}
/** Zamknuté podstromy pod danými zložkami (pre verejné odkazy a WebDAV, kde sa heslo zadať nedá). */
function locked_subtrees(int $owner, array $ids): array {
    $L = folder_locks();
    if (!$L) return [];
    $out = [];
    foreach ($ids as $id) if (isset($L[$id])) $out = array_merge($out, descendants($owner, (int)$id, true));
    return array_values(array_unique($out));
}

/** Overenie hesla zložky s ochranou proti hádaniu (5 pokusov / 10 min v relácii). */
function folder_lock_verify(int $id, string $password): bool {
    $now = time();
    $fails = array_filter((array)($_SESSION['lock_fail'] ?? []), fn($t) => $t > $now - 600);
    if (count($fails) >= 5) throw new RuntimeException('Príliš veľa nesprávnych pokusov. Skús to o 10 minút.');
    $h = (string)q('SELECT lock_hash FROM ' . P . 'folders WHERE id = ?', [$id])->fetchColumn();
    if ($h !== '' && password_verify($password, $h)) { $_SESSION['lock_fail'] = []; return true; }
    $fails[] = $now;
    $_SESSION['lock_fail'] = array_values($fails);
    return false;
}
