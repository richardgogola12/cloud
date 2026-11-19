<?php
/* Zložky zamknuté heslom.
 * Obsah zamknutej zložky (aj podzložiek) je dostupný iba po odomknutí v aktuálnej relácii prehliadača
 * (alebo aplikácie). Platí pre vlastníka aj pre tých, s ktorými je zložka zdieľaná. */

/* Kedy sa má heslo zadať znova (nastavuje vlastník pre každú zložku):
 *  always – pri každom otvorení (po odchode zo zložky sa hneď zamkne)
 *  close  – po zatvorení prehliadača / aplikácie (platí počas relácie)
 *  logout – až po odhlásení (pamätá si to podpísaná cookie aj po zatvorení prehliadača) */
const LOCK_MODES = ['always', 'close', 'logout'];
const LOCK_ALWAYS_TTL = 1800; // istota pre „vždy“: najviac 30 min bez opätovného otvorenia

class FolderLocked extends RuntimeException {
    public function __construct(public int $folderId, public string $folderName) {
        parent::__construct('Zložka „' . $folderName . '“ je zamknutá heslom.');
    }
}

/** všetky zamknuté zložky: id => [name, user_id] (zvyčajne prázdne → nulová réžia) */
function folder_locks(bool $refresh = false): array {
    static $l = null;
    if ($l === null || $refresh) {
        try { $l = q('SELECT id, name, user_id, lock_mode, lock_hash FROM ' . P . 'folders WHERE lock_hash IS NOT NULL')->fetchAll(PDO::FETCH_UNIQUE); }
        catch (Throwable $e) {
            try { $l = q('SELECT id, name, user_id, \'close\' AS lock_mode, lock_hash FROM ' . P . 'folders WHERE lock_hash IS NOT NULL')->fetchAll(PDO::FETCH_UNIQUE); }
            catch (Throwable $e2) { $l = []; } // pred migráciou
        }
    }
    return $l;
}
function folder_is_locked(int $id): bool { return isset(folder_locks()[$id]); }
function folder_lock_mode(int $id): string { return folder_locks()[$id]['lock_mode'] ?? 'close'; }
function folder_unlocked(int $id): bool {
    if ((int)($_SESSION['folder_unlock'][$id] ?? 0) > time()) return true;
    return folder_lock_mode($id) === 'logout' && in_array($id, lock_cookie_ids(), true);
}

/* ---------- režim „až po odhlásení“: podpísaná cookie (prežije zatvorenie prehliadača, zmaže ju odhlásenie) ---------- */
function lock_cookie_name(): string { return cfg('session_name', 'CLOUD_SID') . '_fu'; }
function lock_cookie_sig(int $uid, int $id, int $exp): string {
    $h = (string)(folder_locks()[$id]['lock_hash'] ?? '');
    return substr(hash_hmac('sha256', "$uid|$id|$exp", crypto_master_key() . $h), 0, 32);
}
/** platné položky cookie pre prihláseného používateľa: [id => exp] */
function lock_cookie_entries(): array {
    $uid = (int)($_SESSION['uid'] ?? 0);
    if (!$uid) return [];
    $out = [];
    foreach (explode(',', (string)($_COOKIE[lock_cookie_name()] ?? '')) as $part) {
        if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{32})$/', $part, $m)) continue;
        [$id, $exp] = [(int)$m[1], (int)$m[2]];
        if ($exp > time() && isset(folder_locks()[$id]) && hash_equals(lock_cookie_sig($uid, $id, $exp), $m[3])) $out[$id] = $exp;
    }
    return $out;
}
function lock_cookie_ids(): array { static $ids = null; return $ids ??= array_keys(lock_cookie_entries()); }
function lock_cookie_save(array $entries): void {
    $uid = (int)($_SESSION['uid'] ?? 0);
    $parts = [];
    foreach ($entries as $id => $exp) $parts[] = "$id.$exp." . lock_cookie_sig($uid, (int)$id, (int)$exp);
    $v = implode(',', array_slice($parts, -40));
    if (headers_sent()) return;
    setcookie(lock_cookie_name(), $v, ['expires' => $v === '' ? time() - 3600 : time() + 90 * 86400, 'path' => app_base_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function lock_cookie_clear(): void {
    if (!headers_sent() && isset($_COOKIE[lock_cookie_name()])) setcookie(lock_cookie_name(), '', ['expires' => time() - 3600, 'path' => app_base_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
/** Zapíše odomknutie podľa režimu zložky (session musí byť otvorená). */
function folder_unlock_store(int $id): void {
    $mode = folder_lock_mode($id);
    $_SESSION['folder_unlock'][$id] = $mode === 'always' ? time() + LOCK_ALWAYS_TTL : time() + 10 * 365 * 86400;
    if ($mode === 'logout') { $e = lock_cookie_entries(); $e[$id] = time() + 90 * 86400; lock_cookie_save($e); }
}
function folder_unlock_forget(int $id): void {
    unset($_SESSION['folder_unlock'][$id]);
    $e = lock_cookie_entries();
    if (isset($e[$id])) { unset($e[$id]); lock_cookie_save($e); }
}
/** Režim „vždy“: odomknuté zložky, z ktorých používateľ odišiel, sa hneď zamknú. $chain = aktuálna zložka a jej predkovia. */
function relock_left_always(array $chain): void {
    $un = (array)($_SESSION['folder_unlock'] ?? []);
    $drop = [];
    foreach ($un as $id => $t) if (folder_lock_mode((int)$id) === 'always' && !in_array((int)$id, $chain, true)) $drop[] = (int)$id;
    if (!$drop) return;
    $closed = session_status() !== PHP_SESSION_ACTIVE;
    if ($closed) @session_start();
    foreach ($drop as $id) unset($_SESSION['folder_unlock'][$id]);
    if ($closed) session_write_close();
}
function folder_chain(?int $id): array {
    $out = []; $guard = 0;
    while ($id && $guard++ < 200) { $out[] = $id; $id = ((int)q('SELECT parent_id FROM ' . P . 'folders WHERE id = ?', [$id])->fetchColumn()) ?: null; }
    return $out;
}

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
