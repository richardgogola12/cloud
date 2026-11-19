<?php
/* ------------------------------------------------------------------
 *  Sieťový disk (WebDAV) – Môj disk ako písmeno disku vo Windows,
 *  v macOS Finderi, Linuxe alebo v správcovi súborov v mobile.
 *  Adresa: https://server/cloud/dav.php   (meno = e-mail, heslo = heslo pre sieťový disk)
 * ------------------------------------------------------------------ */
define('CLOUD_NO_SESSION', true);
require __DIR__ . '/inc/core.php';

@set_time_limit(0);
ignore_user_abort(false);
header('X-Robots-Tag: noindex');
const DAV_NS = 'DAV:';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

function dav_status(int $code, string $body = ''): never {
    http_response_code($code);
    if ($body !== '') { header('Content-Type: text/plain; charset=utf-8'); echo $body; }
    exit;
}

/* ---------- cesta požiadavky (funguje s dav.php/…, aj s prepisom /dav/…) ---------- */
$rawPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$script = (string)($_SERVER['SCRIPT_NAME'] ?? '/dav.php');
if (str_starts_with($rawPath, $script)) { $prefix = $script; }
else { $prefix = rtrim(app_base_path(), '/') . '/dav'; if (!str_starts_with($rawPath, $prefix)) $prefix = $script; }
$rel = substr($rawPath, strlen($prefix));
$segs = array_values(array_filter(array_map('rawurldecode', explode('/', (string)$rel)), fn($s) => $s !== '' && $s !== '.'));
if (in_array('..', $segs, true)) dav_status(400);

/** URL (href) položky podľa segmentov cesty */
function dav_href(array $segs, bool $dir): string {
    global $prefix;
    $h = $prefix . '/' . implode('/', array_map('rawurlencode', $segs));
    if ($dir && !str_ends_with($h, '/')) $h .= '/';
    return $h;
}

/* ---------- OPTIONS bez prihlásenia (klienti si najprv overia schopnosti) ---------- */
function dav_options_headers(): void {
    header('DAV: 1, 2');
    header('MS-Author-Via: DAV');
    header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, MKCOL, COPY, MOVE, LOCK, UNLOCK');
}
if ($method === 'OPTIONS') { dav_options_headers(); header('Content-Length: 0'); dav_status(200); }

/* ---------- prihlásenie: HTTP Basic, e-mail + heslo pre sieťový disk ---------- */
function dav_auth(): array {
    $user = $_SERVER['PHP_AUTH_USER'] ?? null; $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($user === null && stripos($hdr, 'basic ') === 0) {
        $dec = base64_decode(substr($hdr, 6), true);
        if ($dec !== false && str_contains($dec, ':')) [$user, $pass] = explode(':', $dec, 2);
    }
    $deny = function (string $msg = 'Prihlásenie') {
        header('WWW-Authenticate: Basic realm="' . cfg('app_name', 'Cloud') . ' – sieťový disk", charset="UTF-8"');
        dav_status(401, $msg);
    };
    if ($user === null || $pass === null || $user === '') $deny();
    if (too_many_attempts()) dav_status(429, 'Príliš veľa neúspešných pokusov. Skús to o 15 minút.');
    $u = q('SELECT * FROM ' . P . 'users WHERE email = ? AND is_active = 1', [mb_strtolower(trim($user))])->fetch();
    $ok = null;
    if ($u) {
        $h = hash('sha256', preg_replace('/[\s-]/', '', mb_strtolower($pass)));
        foreach (q('SELECT id, hash FROM ' . P . 'dav_passwords WHERE user_id = ?', [$u['id']])->fetchAll() as $t) {
            if (hash_equals($t['hash'], $h)) { $ok = (int)$t['id']; break; }
        }
    }
    if (!$ok) { record_failed_attempt('dav:' . $user); usleep(400000); $deny('Nesprávny e-mail alebo heslo pre sieťový disk.'); }
    q('UPDATE ' . P . 'dav_passwords SET last_used = NOW() WHERE id = ? AND (last_used IS NULL OR last_used < NOW() - INTERVAL 5 MINUTE)', [$ok]);
    return $u;
}
$user = dav_auth();
$uid = (int)$user['id'];
$GLOBALS['CLOUD_ACTOR'] = $uid;

/* ---------- prevod cesty na zložku / súbor ---------- */
const DAV_JUNK = '/^(\._.*|\.DS_Store|desktop\.ini|Thumbs\.db|\.Spotlight-V100|\.Trashes|\.fseventsd|\.metadata_never_index.*)$/i';

/** @return array{type:string,row:?array,folder:?int}|null  type: root|d|f ; folder = ID nadradenej zložky */
function dav_resolve(int $uid, array $segs): ?array {
    if (!$segs) return ['type' => 'root', 'row' => null, 'folder' => null];
    $parent = null;
    foreach ($segs as $i => $name) {
        $last = $i === count($segs) - 1;
        $d = q('SELECT * FROM ' . P . 'folders WHERE user_id = ? AND parent_id <=> ? AND name = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$uid, $parent, $name])->fetch();
        if ($d && folder_is_locked((int)$d['id'])) return null; // zamknuté zložky cez sieťový disk nie sú dostupné
        if ($d) { if ($last) return ['type' => 'd', 'row' => $d, 'folder' => $parent]; $parent = (int)$d['id']; continue; }
        if (!$last) return null;
        $f = q('SELECT * FROM ' . P . 'files WHERE user_id = ? AND folder_id <=> ? AND name = ? AND deleted_at IS NULL LIMIT 1', [$uid, $parent, $name])->fetch();
        return $f ? ['type' => 'f', 'row' => $f, 'folder' => $parent] : null;
    }
    return null;
}
/** zložka, do ktorej patrí posledný segment (pre PUT, MKCOL, MOVE) – null = neexistuje */
function dav_parent(int $uid, array $segs): array|false {
    $p = dav_resolve($uid, array_slice($segs, 0, -1));
    if (!$p || $p['type'] === 'f') return false;
    return ['id' => $p['type'] === 'root' ? null : (int)$p['row']['id']];
}
function dav_time(?string $dt): int { return $dt ? (strtotime($dt) ?: time()) : time(); }

/* ---------- PROPFIND ---------- */
function dav_prop_xml(array $segs, ?array $row, string $type, ?array $quota): string {
    $dir = $type !== 'f';
    $name = $type === 'root' ? cfg('app_name', 'Cloud') : $row['name'];
    $mod = dav_time($row['updated_at'] ?? null);
    $created = dav_time($row['created_at'] ?? null);
    $x = '<d:response><d:href>' . htmlspecialchars(dav_href($segs, $dir), ENT_XML1) . '</d:href><d:propstat><d:prop>';
    $x .= '<d:displayname>' . htmlspecialchars($name, ENT_XML1) . '</d:displayname>';
    $x .= '<d:getlastmodified>' . gmdate('D, d M Y H:i:s', $mod) . ' GMT</d:getlastmodified>';
    $x .= '<d:creationdate>' . gmdate('Y-m-d\TH:i:s\Z', $created) . '</d:creationdate>';
    if ($dir) {
        $x .= '<d:resourcetype><d:collection/></d:resourcetype>';
        if ($quota) $x .= '<d:quota-used-bytes>' . $quota['used'] . '</d:quota-used-bytes><d:quota-available-bytes>' . $quota['free'] . '</d:quota-available-bytes>';
    } else {
        $x .= '<d:resourcetype/><d:getcontentlength>' . (int)$row['size'] . '</d:getcontentlength>';
        $x .= '<d:getcontenttype>' . htmlspecialchars($row['mime'] ?: 'application/octet-stream', ENT_XML1) . '</d:getcontenttype>';
        $x .= '<d:getetag>"' . htmlspecialchars(substr((string)($row['sha256'] ?: $row['stored_name']), 0, 32) . '-' . $mod, ENT_XML1) . '"</d:getetag>';
    }
    $x .= '<d:supportedlock><d:lockentry><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype></d:lockentry></d:supportedlock>';
    $x .= '<d:lockdiscovery/>';
    return $x . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
}
function dav_quota(int $uid, array $user): array {
    $used = used_space($uid);
    $q = (int)$user['quota'];
    $free = $q > 0 ? max(0, $q - $used) : (int)(@disk_free_space((string)cfg('storage_path')) ?: 0);
    return ['used' => $used, 'free' => $free];
}

$node = in_array($method, ['PUT', 'MKCOL'], true) ? null : dav_resolve($uid, $segs);
if ($segs && preg_match(DAV_JUNK, end($segs)) && in_array($method, ['GET', 'HEAD', 'PROPFIND'], true) && !$node) dav_status(404);

switch ($method) {

case 'PROPFIND': {
    if (!$node) dav_status(404);
    $depth = $_SERVER['HTTP_DEPTH'] ?? 'infinity';
    $quota = dav_quota($uid, $user);
    $out = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">';
    $out .= dav_prop_xml($segs, $node['row'], $node['type'], $quota);
    if ($node['type'] !== 'f' && $depth !== '0') {
        $fid = $node['type'] === 'root' ? null : (int)$node['row']['id'];
        foreach (q('SELECT * FROM ' . P . 'folders WHERE user_id = ? AND parent_id <=> ? AND deleted_at IS NULL ORDER BY name', [$uid, $fid]) as $d)
            if (!folder_is_locked((int)$d['id']))
            $out .= dav_prop_xml(array_merge($segs, [$d['name']]), $d, 'd', null);
        foreach (q('SELECT * FROM ' . P . 'files WHERE user_id = ? AND folder_id <=> ? AND deleted_at IS NULL ORDER BY name', [$uid, $fid]) as $f)
            $out .= dav_prop_xml(array_merge($segs, [$f['name']]), $f, 'f', null);
    }
    $out .= '</d:multistatus>';
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

case 'PROPPATCH': { // časy a atribúty od Windows/macOS – prijmeme, nič sa nemení
    if (!$node) dav_status(404);
    $body = (string)file_get_contents('php://input');
    $props = '';
    if (preg_match_all('/<([a-zA-Z0-9_]+:)?([a-zA-Z0-9_-]+)[^>]*\/?>/', preg_replace('/^.*?<[^>]*prop[^>]*>/s', '', $body), $m)) {
        foreach (array_unique($m[2]) as $p) if (!in_array($p, ['prop', 'set', 'remove', 'propertyupdate'], true)) $props .= '<d:' . $p . '/>';
    }
    $out = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>' . htmlspecialchars(dav_href($segs, $node['type'] !== 'f'), ENT_XML1)
        . '</d:href><d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo $out;
    exit;
}

case 'HEAD':
case 'GET': {
    if (!$node) dav_status(404);
    if ($node['type'] !== 'f') { // v prehliadači jednoduchý zoznam
        if ($method === 'HEAD') { header('Content-Type: text/html; charset=utf-8'); dav_status(200); }
        $fid = $node['type'] === 'root' ? null : (int)$node['row']['id'];
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>' . e(cfg('app_name')) . ' – sieťový disk</title><body style="font-family:system-ui;padding:20px">';
        echo '<h2>' . e($node['type'] === 'root' ? cfg('app_name') : $node['row']['name']) . '</h2><p>Toto je adresa sieťového disku (WebDAV). Pripoj ju v správcovi súborov.</p><ul>';
        if ($segs) echo '<li><a href="' . e(dav_href(array_slice($segs, 0, -1), true)) . '">..</a></li>';
        foreach (q('SELECT name FROM ' . P . 'folders WHERE user_id = ? AND parent_id <=> ? AND deleted_at IS NULL ORDER BY name', [$uid, $fid]) as $d)
            echo '<li>📁 <a href="' . e(dav_href(array_merge($segs, [$d['name']]), true)) . '">' . e($d['name']) . '</a></li>';
        foreach (q('SELECT name, size FROM ' . P . 'files WHERE user_id = ? AND folder_id <=> ? AND deleted_at IS NULL ORDER BY name', [$uid, $fid]) as $f)
            echo '<li><a href="' . e(dav_href(array_merge($segs, [$f['name']]), false)) . '">' . e($f['name']) . '</a> <small>' . format_bytes((int)$f['size']) . '</small></li>';
        echo '</ul>';
        exit;
    }
    $f = $node['row'];
    header('ETag: "' . substr((string)($f['sha256'] ?: $f['stored_name']), 0, 32) . '-' . dav_time($f['updated_at']) . '"');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', dav_time($f['updated_at'])) . ' GMT');
    if ($method === 'HEAD') {
        header('Content-Type: ' . ($f['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . (int)$f['size']);
        header('Accept-Ranges: bytes');
        exit;
    }
    send_stored($f, $f['name'], false);
}

case 'PUT': {
    if (!$segs) dav_status(405);
    $name = end($segs);
    $parent = dav_parent($uid, $segs);
    if ($parent === false) dav_status(409, 'Nadradená zložka neexistuje.');
    if (preg_match(DAV_JUNK, $name)) { file_get_contents('php://input'); dav_status(201); } // systémové súbory macOS/Windows nepotrebujeme
    $name = clean_name($name);
    if (q('SELECT 1 FROM ' . P . 'folders WHERE user_id = ? AND parent_id <=> ? AND name = ? AND deleted_at IS NULL', [$uid, $parent['id'], $name])->fetchColumn()) dav_status(405, 'Existuje zložka s rovnakým názvom.');
    $len = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : -1;
    $maxMb = (int)setting('max_upload_mb', '0');
    if ($maxMb > 0 && $len > $maxMb * 1048576) dav_status(413, "Maximálna veľkosť súboru je $maxMb MB.");
    $q = (int)$user['quota'];
    if ($q > 0 && $len > 0 && used_space($uid) + $len > $q) dav_status(507, 'Úložisko je plné.');
    $tmp = tmp_file();
    $in = fopen('php://input', 'rb'); $out = fopen($tmp, 'wb');
    if (!$in || !$out) dav_status(500);
    $n = stream_copy_to_stream($in, $out); fclose($in); fclose($out);
    if ($len >= 0 && $n !== $len) { @unlink($tmp); dav_status(400, 'Prenos bol prerušený.'); }
    if ($q > 0 && used_space($uid) + $n > $q) { @unlink($tmp); dav_status(507, 'Úložisko je plné.'); }
    // Windows najprv vytvorí prázdny súbor a hneď ho prepíše – prázdnu verziu neuchovávame
    $ex = q('SELECT * FROM ' . P . 'files WHERE user_id = ? AND folder_id <=> ? AND name = ? AND deleted_at IS NULL LIMIT 1', [$uid, $parent['id'], $name])->fetch();
    if ($ex && (int)$ex['size'] === 0 && $n > 0) { purge_file($ex); $ex = null; }
    try { store_file($uid, $parent['id'], $name, $tmp, false); }
    catch (Throwable $e) { @unlink($tmp); dav_status(500, $e->getMessage()); }
    if ($n > 0) log_action($uid, 'upload', $name . ' (' . format_bytes($n) . ', sieťový disk)');
    dav_status($ex ? 204 : 201);
}

case 'MKCOL': {
    if (!$segs) dav_status(405);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) dav_status(415);
    $parent = dav_parent($uid, $segs);
    if ($parent === false) dav_status(409);
    $name = clean_name(end($segs));
    if (name_exists($uid, $parent['id'], $name, 'd') || name_exists($uid, $parent['id'], $name, 'f')) dav_status(405, 'Už existuje.');
    q('INSERT INTO ' . P . 'folders (user_id, parent_id, name) VALUES (?,?,?)', [$uid, $parent['id'], $name]);
    log_action($uid, 'mkdir', $name . ' (sieťový disk)');
    dav_status(201);
}

case 'DELETE': { // do koša – dá sa obnoviť na webe
    if (!$node || $node['type'] === 'root') dav_status($node ? 403 : 404);
    if ($node['type'] === 'd') trash_folder($uid, (int)$node['row']['id']); else trash_file($uid, (int)$node['row']['id']);
    log_action($uid, 'delete', $node['row']['name'] . ' (sieťový disk)');
    dav_status(204);
}

case 'MOVE':
case 'COPY': {
    if (!$node || $node['type'] === 'root') dav_status($node ? 403 : 404);
    $destUrl = (string)($_SERVER['HTTP_DESTINATION'] ?? '');
    $dPath = rawurldecode((string)parse_url($destUrl, PHP_URL_PATH));
    $pfx = rawurldecode($prefix);
    if (!str_starts_with($dPath, $pfx)) dav_status(502, 'Cieľ je mimo sieťového disku.');
    $dSegs = array_values(array_filter(explode('/', substr($dPath, strlen($pfx))), fn($s) => $s !== '' && $s !== '.'));
    if (!$dSegs || in_array('..', $dSegs, true)) dav_status(403);
    $dParent = dav_parent($uid, $dSegs);
    if ($dParent === false) dav_status(409);
    $dName = clean_name(end($dSegs));
    $overwrite = strtoupper($_SERVER['HTTP_OVERWRITE'] ?? 'T') !== 'F';
    $existing = dav_resolve($uid, $dSegs);
    $isDir = $node['type'] === 'd';
    if ($existing && $existing['type'] !== 'root') {
        if ($existing['type'] === $node['type'] && (int)$existing['row']['id'] === (int)$node['row']['id']) dav_status(204); // ten istý
        if (!$overwrite) dav_status(412);
        if ($existing['type'] === 'd') trash_folder($uid, (int)$existing['row']['id']); else trash_file($uid, (int)$existing['row']['id']);
    }
    if ($isDir && $dParent['id'] && in_array($dParent['id'], descendants($uid, (int)$node['row']['id']), true)) dav_status(403, 'Zložku nemožno presunúť do seba samej.');
    if ($method === 'MOVE') {
        if ($isDir) q('UPDATE ' . P . 'folders SET parent_id = ?, name = ? WHERE id = ?', [$dParent['id'], $dName, $node['row']['id']]);
        else q('UPDATE ' . P . 'files SET folder_id = ?, name = ? WHERE id = ?', [$dParent['id'], $dName, $node['row']['id']]);
        log_action($uid, 'move', $node['row']['name'] . ' → ' . $dName . ' (sieťový disk)');
    } else {
        $copyFile = function (array $f, ?int $to, string $nm) use ($uid) { store_file($uid, $to, $nm, stored_to_temp($f), false); };
        $copyDir = function (int $src, ?int $to, string $nm) use (&$copyDir, $copyFile, $uid) {
            q('INSERT INTO ' . P . 'folders (user_id, parent_id, name) VALUES (?,?,?)', [$uid, $to, $nm]);
            $new = (int)db()->lastInsertId();
            foreach (q('SELECT * FROM ' . P . 'files WHERE user_id = ? AND folder_id = ? AND deleted_at IS NULL', [$uid, $src])->fetchAll() as $f) $copyFile($f, $new, $f['name']);
            foreach (q('SELECT id, name FROM ' . P . 'folders WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL', [$uid, $src])->fetchAll() as $d) $copyDir((int)$d['id'], $new, $d['name']);
        };
        $need = $isDir ? (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'files WHERE user_id = ? AND deleted_at IS NULL AND folder_id IN (' . implode(',', descendants($uid, (int)$node['row']['id'])) . ')', [$uid])->fetchColumn() : (int)$node['row']['size'];
        if ((int)$user['quota'] > 0 && used_space($uid) + $need > (int)$user['quota']) dav_status(507, 'Úložisko je plné.');
        if ($isDir) $copyDir((int)$node['row']['id'], $dParent['id'], $dName); else $copyFile($node['row'], $dParent['id'], $dName);
        log_action($uid, 'copy', $node['row']['name'] . ' (sieťový disk)');
    }
    dav_status($existing ? 204 : 201);
}

case 'LOCK': { // zámky iba naoko – Windows a macOS ich vyžadujú pri zápise
    $token = 'opaquelocktoken:' . bin2hex(random_bytes(16));
    file_get_contents('php://input');
    if (!$node && $segs) { // zamknutie neexistujúceho súboru = vytvorenie prázdneho
        $parent = dav_parent($uid, $segs);
        if ($parent === false) dav_status(409);
        $name = end($segs);
        if (!preg_match(DAV_JUNK, $name)) { $t = tmp_file(); touch($t); store_file($uid, $parent['id'], clean_name($name), $t, false); }
        http_response_code(201);
    } else http_response_code(200);
    $timeout = 'Second-3600';
    header('Lock-Token: <' . $token . '>');
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?><d:prop xmlns:d="DAV:"><d:lockdiscovery><d:activelock><d:locktype><d:write/></d:locktype><d:lockscope><d:exclusive/></d:lockscope>'
        . '<d:depth>' . (($_SERVER['HTTP_DEPTH'] ?? '0') === 'infinity' ? 'infinity' : '0') . '</d:depth><d:owner>' . e($user['name']) . '</d:owner><d:timeout>' . $timeout . '</d:timeout>'
        . '<d:locktoken><d:href>' . $token . '</d:href></d:locktoken><d:lockroot><d:href>' . htmlspecialchars(dav_href($segs, $node && $node['type'] !== 'f'), ENT_XML1) . '</d:href></d:lockroot></d:activelock></d:lockdiscovery></d:prop>';
    exit;
}

case 'UNLOCK':
    dav_status(204);

default:
    dav_options_headers();
    dav_status(405);
}
