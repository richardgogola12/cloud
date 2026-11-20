<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 *  Cloud – jadro: konfigurácia, izolovaná session, DB, pomocné funkcie
 * ------------------------------------------------------------------ */

define('APP_ROOT', dirname(__DIR__));
$GLOBALS['CFG'] = require APP_ROOT . '/config.php';
date_default_timezone_set(cfg('timezone', 'Europe/Bratislava'));

const P = 'cloud_'; // prefix tabuliek
define('APP_VERSION', trim((string)@file_get_contents(APP_ROOT . '/VERSION')) ?: '0.0.0');
/** Beží stránka v Android aplikácii? (User-Agent; požiadavky cez service worker ho v WebView nemajú, preto aj cookie z prihlásenia aplikácie) */
function is_android_app(): bool { return str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'CloudAndroid/') || ($_COOKIE['cloud_app'] ?? '') === '1'; }

function cfg(string $key, $default = null) { return $GLOBALS['CFG'][$key] ?? $default; }

/* ---------- Bezpečnostné hlavičky ---------- */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

/* ---------- Session izolovaná od ostatných webov na serveri ----------
 * - vlastný názov cookie
 * - cookie obmedzená na cestu aplikácie
 * - vlastný adresár na session súbory (iné weby ich nevidia ani nezmažú cez GC)
 * - väzba session na túto aplikáciu (APP_ID)
 */
function app_base_path(): string {
    // cesta podľa skutočnej požiadavky – aplikácia funguje aj cez inú adresu (napr. http://192.168.1.50/cloud)
    static $base = null;
    if ($base !== null) return $base;
    $p = null;
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['SCRIPT_NAME']) && !empty($_SERVER['SCRIPT_FILENAME'])) {
        $root = realpath(APP_ROOT);
        $dir = realpath(dirname($_SERVER['SCRIPT_FILENAME']));
        if ($root && $dir && str_starts_with($dir, $root)) {
            $sub = str_replace('\\', '/', substr($dir, strlen($root)));          // '' alebo napr. '/install'
            $sn = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            if ($sub === '' || str_ends_with($sn, $sub)) $p = substr($sn, 0, strlen($sn) - strlen($sub));
        }
    }
    if ($p === null) $p = parse_url(cfg('app_url', ''), PHP_URL_PATH) ?: '';
    $p = rtrim($p, '/');
    return $base = ($p === '' ? '/' : $p . '/');
}
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = rtrim(cfg('storage_path'), '/\\') . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string)cfg('session_lifetime', 1209600));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(cfg('session_name', 'CLOUD_SID'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_base_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $appId = hash('sha256', APP_ROOT . cfg('session_name'));
    if (!isset($_SESSION['_app'])) {
        $_SESSION['_app'] = $appId;
    } elseif ($_SESSION['_app'] !== $appId) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_app'] = $appId;
    }
    browser_win();
}
/** Identifikátor otvoreného prehliadača / aplikácie: cookie bez dátumu platnosti zmizne po ich zatvorení
 *  (session cookie pri „Zapamätať si ma“ zostáva, preto samostatne). */
function browser_win(): string {
    static $w = null;
    if ($w !== null) return $w;
    $n = cfg('session_name', 'CLOUD_SID') . '_win';
    $w = (string)($_COOKIE[$n] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $w)) {
        $w = bin2hex(random_bytes(12));
        $_COOKIE[$n] = $w;
        if (!headers_sent()) setcookie($n, $w, ['expires' => 0, 'path' => app_base_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    return $w;
}
function set_remember_cookie(bool $remember): void {
    // predĺži platnosť session cookie pri "Zapamätať si ma"
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => $remember ? time() + (int)cfg('session_lifetime') : 0,
        'path'     => $params['path'],
        'secure'   => $params['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
if (!defined('CLOUD_NO_SESSION')) start_session(); // WebDAV beží bez session (každá požiadavka sa overuje heslom)

/* ---------- Databáza ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/* ---------- Nastavenia v DB ---------- */
function setting(string $k, $default = null) {
    if (!isset($GLOBALS['_settings'])) {
        $GLOBALS['_settings'] = [];
        try {
            foreach (q('SELECT k, v FROM ' . P . 'settings') as $r) $GLOBALS['_settings'][$r['k']] = $r['v'];
            $GLOBALS['_settings_ok'] = true;
        } catch (Throwable $e) {}
    }
    $c = $GLOBALS['_settings'];
    return array_key_exists($k, $c) && $c[$k] !== '' && $c[$k] !== null ? $c[$k] : $default;
}
function setting_set(string $k, ?string $v): void {
    q('INSERT INTO ' . P . 'settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    setting($k); // zaisti načítanie cache
    $GLOBALS['_settings'][$k] = $v;
}

/* ---------- Pomocné ---------- */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** Adresa webu tak, ako ju otvoril prehliadač (napr. https://moj-server.ts.net/cloud). */
function request_base_url(): string {
    if (empty($_SERVER['HTTP_HOST'])) return '';
    return (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(app_base_path(), '/');
}
/** Verejná adresa do e-mailov a zdieľaných odkazov: nastavenie v administrácii, inak app_url z config.php. */
function public_url(): string {
    $u = trim((string)setting('public_url', ''));
    if ($u === '') $u = trim((string)cfg('app_url', ''));
    if ($u === '') $u = request_base_url();
    return rtrim($u, '/');
}
/** Knižnica z vlastného servera (assets/vendor), ak tam je – inak z CDN. */
function lib(string $local, string $cdn): string {
    static $has = [];
    $has[$local] ??= is_file(APP_ROOT . '/assets/vendor/' . $local);
    return $has[$local] ? url('assets/vendor/' . $local) . '?v=' . APP_VERSION : $cdn;
}
function url(string $path = ''): string { return app_base_path() . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

function flash(string $type, string $msg): void { $_SESSION['_flash'][] = [$type, $msg]; }
function flashes(): string {
    $out = '';
    foreach ($_SESSION['_flash'] ?? [] as [$t, $m]) {
        $out .= '<div class="alert alert-' . e($t) . ' alert-dismissible fade show" role="alert">' . e($m)
              . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['_flash']);
    return $out;
}

function format_bytes($b, int $dec = 1): string {
    $b = (float)$b;
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$b : number_format($b, $dec, ',', ' ')) . ' ' . $u[$i];
}

function log_action(?int $uid, string $action, string $detail = ''): void {
    try { q('INSERT INTO ' . P . 'log (user_id, action, detail, ip) VALUES (?,?,?,?)', [$uid, $action, mb_substr($detail, 0, 500), client_ip()]); }
    catch (Throwable $e) {}
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if (bearer_user()) return; // požiadavky z mobilnej aplikácie (token v hlavičke, bez cookies)
    $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        if (is_ajax()) json_out(['ok' => false, 'error' => 'Neplatný bezpečnostný token. Obnov stránku.'], 419);
        exit('Neplatný bezpečnostný token. Obnov stránku.');
    }
}
function is_ajax(): bool {
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $body = (string)json_encode($data, JSON_UNESCAPED_UNICODE);
    // väčšie odpovede (zoznamy zložiek) komprimujeme – na pomalšej linke až 10× menej dát
    if (strlen($body) > 2048 && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && function_exists('gzencode')
        && !ini_get('zlib.output_compression') && !headers_sent()) {
        $body = (string)gzencode($body, 5);
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

/**
 * Úloha po odoslaní odpovede (miniatúry, upozornenia, údržba) – používateľ nečaká.
 * Pri PHP-FPM sa spojenie ukončí cez fastcgi_finish_request, inak pomôže Content-Length.
 */
function defer_task(callable $fn): void {
    if (!isset($GLOBALS['_deferred'])) {
        $GLOBALS['_deferred'] = [];
        register_shutdown_function(function () {
            while (ob_get_level() > 0) @ob_end_flush();
            @flush();
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            ignore_user_abort(true);
            @set_time_limit(600);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            foreach ($GLOBALS['_deferred'] as $task) {
                try { $task(); } catch (Throwable $e) { error_log('[cloud] úloha na pozadí: ' . $e->getMessage()); }
            }
        });
    }
    $GLOBALS['_deferred'][] = $fn;
}

/* ---------- Autentifikácia ---------- */
/** Používateľ prihlásený tokenom mobilnej aplikácie (hlavička X-Cloud-Token) */
function bearer_user(): ?array {
    static $done = false, $u = null;
    if ($done) return $u;
    $done = true;
    $t = (string)($_SERVER['HTTP_X_CLOUD_TOKEN'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) return null;
    try {
        $row = q('SELECT t.id AS _tid, t.last_used AS _tlast, u.* FROM ' . P . 'app_tokens t JOIN ' . P . 'users u ON u.id = t.user_id
                  WHERE t.token_hash = ? AND t.revoked = 0 AND u.is_active = 1', [hash('sha256', $t)])->fetch();
    } catch (Throwable $e) { return null; }
    if (!$row) return null;
    if (!$row['_tlast'] || strtotime($row['_tlast']) < time() - 300) {
        q('UPDATE ' . P . 'app_tokens SET last_used = NOW(), ip = ? WHERE id = ?', [client_ip(), $row['_tid']]);
    }
    $GLOBALS['_app_token_id'] = (int)$row['_tid'];
    unset($row['_tid'], $row['_tlast']);
    return $u = $row;
}
function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if ($b = bearer_user()) return $u = $b;
    if (!empty($_SESSION['uid'])) {
        $u = q('SELECT * FROM ' . P . 'users WHERE id = ? AND is_active = 1', [$_SESSION['uid']])->fetch() ?: null;
        if ($u && !session_valid($u)) $u = null;
        if (!$u) { unset($_SESSION['uid'], $_SESSION['sid_row'], $_SESSION['sid_key']); }
        // účty prihlásené pred zavedením prepínania doplní do zoznamu
        elseif (!defined('CLOUD_NO_SESSION') && !is_android_app() && !headers_sent() && !isset(acc_list()[(int)$u['id']])) remember_account((int)$u['id']);
    }
    return $u;
}
/** Overí, či session nebola odhlásená z iného zariadenia; priebežne aktualizuje "naposledy aktívne". */
function session_valid(array $u): bool {
    if (empty($_SESSION['sid_row'])) { track_session($u, 'heslo'); return true; } // staršie session pred aktualizáciou
    try {
        $row = q('SELECT skey, revoked, last_seen FROM ' . P . 'sessions WHERE id = ? AND user_id = ?', [$_SESSION['sid_row'], $u['id']])->fetch();
    } catch (Throwable $e) { return true; }
    if (!$row || $row['revoked'] || !hash_equals($row['skey'], hash('sha256', (string)($_SESSION['sid_key'] ?? '')))) return false;
    if (strtotime($row['last_seen']) < time() - 300) {
        q('UPDATE ' . P . 'sessions SET last_seen = NOW(), ip = ? WHERE id = ?', [client_ip(), $_SESSION['sid_row']]);
    }
    return true;
}
function track_session(array $user, string $method): void {
    try {
        $key = bin2hex(random_bytes(32));
        q('INSERT INTO ' . P . 'sessions (user_id, skey, ua, ip, method) VALUES (?,?,?,?,?)',
          [$user['id'], hash('sha256', $key), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), client_ip(), $method]);
        $_SESSION['sid_row'] = (int)db()->lastInsertId();
        $_SESSION['sid_key'] = $key;
    } catch (Throwable $e) {}
}
function require_login(): array {
    $u = current_user();
    if (!$u) {
        if (is_ajax()) json_out(['ok' => false, 'error' => 'Nie si prihlásený.'], 401);
        redirect('login.php');
    }
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') { http_response_code(403); exit('Prístup zamietnutý.'); }
    return $u;
}
function login_user(array $user, bool $remember = false, string $method = 'heslo'): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['_app'] = hash('sha256', APP_ROOT . cfg('session_name'));
    set_remember_cookie($remember);
    q('UPDATE ' . P . 'users SET last_login = NOW() WHERE id = ?', [$user['id']]);
    track_session($user, $method);
    check_new_device($user, $method);
    if (!is_android_app()) remember_account((int)$user['id']);
}

/* ---------- Rýchle prepínanie účtov ----------
 * Cookie <session>_acc = "uid:token,uid:token" – token je v tabuľke tokens (type=switch, hash).
 * Dovoľuje prepnúť sa na iný účet prihlásený v tomto prehliadači bez zadávania hesla. */
function acc_cookie_name(): string { return cfg('session_name', 'CLOUD_SID') . '_acc'; }
function acc_list(): array {
    $out = [];
    foreach (explode(',', (string)($_COOKIE[acc_cookie_name()] ?? '')) as $part) {
        if (preg_match('/^(\d+):([a-f0-9]{64})$/', $part, $m)) $out[(int)$m[1]] = $m[2];
    }
    return array_slice($out, 0, 6, true);
}
function acc_save(array $list): void {
    $parts = [];
    foreach ($list as $uid => $t) $parts[] = $uid . ':' . $t;
    $v = implode(',', $parts);
    $_COOKIE[acc_cookie_name()] = $v;
    if (headers_sent()) return;
    setcookie(acc_cookie_name(), $v, ['expires' => $v === '' ? time() - 3600 : time() + 90 * 86400,
        'path' => app_base_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function remember_account(int $uid): void {
    try {
        $list = acc_list();
        if (isset($list[$uid]) && acc_token_valid($uid, $list[$uid])) { acc_save($list); return; }
        $t = bin2hex(random_bytes(32));
        q('INSERT INTO ' . P . 'tokens (user_id, type, token_hash, data, expires_at) VALUES (?,?,?,?, NOW() + INTERVAL 90 DAY)',
          [$uid, 'switch', hash('sha256', $t), null]);
        $list[$uid] = $t;
        acc_save($list);
    } catch (Throwable $e) {}
}
function acc_token_valid(int $uid, string $t): bool {
    return (bool)q('SELECT id FROM ' . P . "tokens WHERE user_id = ? AND type = 'switch' AND token_hash = ? AND used = 0 AND expires_at > NOW()",
        [$uid, hash('sha256', $t)])->fetchColumn();
}
function forget_account(int $uid): void {
    $list = acc_list();
    if (isset($list[$uid])) {
        try { q('UPDATE ' . P . "tokens SET used = 1 WHERE user_id = ? AND type = 'switch' AND token_hash = ?", [$uid, hash('sha256', $list[$uid])]); } catch (Throwable $e) {}
        unset($list[$uid]);
        acc_save($list);
    }
}
/** Účty uložené v tomto prehliadači (platné), bez aktuálneho. */
function saved_accounts(?int $except = null): array {
    $list = acc_list();
    if (!$list) return [];
    $ids = array_keys($list);
    $rows = q('SELECT id, name, email, avatar_key, avatar_v, is_active FROM ' . P . 'users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        if ((int)$r['id'] === $except || !$r['is_active']) continue;
        if (!acc_token_valid((int)$r['id'], $list[(int)$r['id']])) continue;
        $out[] = $r;
    }
    return $out;
}
/** Zneplatní všetky prepínacie tokeny používateľa (napr. po zmene hesla). */
function revoke_switch_tokens(int $uid): void {
    try { q('UPDATE ' . P . "tokens SET used = 1 WHERE user_id = ? AND type = 'switch'", [$uid]); } catch (Throwable $e) {}
}
function logout_user(): void {
    lock_cookie_clear(); // odomknuté zamknuté zložky sa po odhlásení znova zamknú
    if (!empty($_SESSION['sid_row'])) {
        try { q('UPDATE ' . P . 'sessions SET revoked = 1 WHERE id = ?', [$_SESSION['sid_row']]); } catch (Throwable $e) {}
    }
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}

/** Upozornenie e-mailom pri prihlásení z nového zariadenia (rozpoznané dlhodobou cookie). */
function check_new_device(array $user, string $method): void {
    try {
        $cname = cfg('session_name', 'CLOUD_SID') . '_dev';
        $tok = (string)($_COOKIE[$cname] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $tok)) $tok = bin2hex(random_bytes(32));
        setcookie($cname, $tok, ['expires' => time() + 400 * 86400, 'path' => app_base_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        $h = hash('sha256', $tok);
        if (q('SELECT id FROM ' . P . 'devices WHERE user_id = ? AND device_hash = ?', [$user['id'], $h])->fetch()) return;
        $hadDevices = (int)q('SELECT COUNT(*) FROM ' . P . 'devices WHERE user_id = ?', [$user['id']])->fetchColumn();
        q('INSERT IGNORE INTO ' . P . 'devices (user_id, device_hash, ua) VALUES (?,?,?)', [$user['id'], $h, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
        if ($hadDevices) notify((int)$user['id'], 'device', 'Prihlásenie z nového zariadenia', ua_label($_SERVER['HTTP_USER_AGENT'] ?? '') . ' · IP ' . client_ip(), 'profile.php#sessions');
        if ($hadDevices && setting('notify_new_device', '1') === '1') {
            send_mail($user['email'], 'Prihlásenie z nového zariadenia – ' . cfg('app_name'), 'Nové prihlásenie do účtu',
                '<p>Ahoj ' . e($user['name']) . ', do tvojho účtu sa práve niekto prihlásil z nového zariadenia.</p>'
                . '<p><b>Zariadenie:</b> ' . e(ua_label($_SERVER['HTTP_USER_AGENT'] ?? '')) . '<br><b>IP adresa:</b> ' . e(client_ip())
                . '<br><b>Čas:</b> ' . date('d.m.Y H:i') . '<br><b>Spôsob:</b> ' . e($method) . '</p>'
                . '<p>Ak si to bol(a) ty, nemusíš nič robiť. Ak nie, okamžite si zmeň heslo a odhlás neznáme zariadenia.</p>',
                'Skontrolovať zariadenia', public_url() . '/profile.php#sessions');
        }
    } catch (Throwable $e) { error_log('[cloud] device check: ' . $e->getMessage()); }
}

/** Čitateľný názov zariadenia z User-Agent. */
function ua_label(string $ua): string {
    $os = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad',
        str_contains($ua, 'Android') => 'Android', str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'CrOS') => 'Chromebook',
        str_contains($ua, 'Linux') => 'Linux', default => 'Neznámy systém',
    };
    $br = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera',
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet', str_contains($ua, 'Firefox') => 'Firefox',
        str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Safari') => 'Safari', default => 'Prehliadač',
    };
    return "$br · $os";
}

/* ---------- Ochrana proti hádaniu hesiel ---------- */
function too_many_attempts(): bool {
    q('DELETE FROM ' . P . 'login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    $n = (int)q('SELECT COUNT(*) FROM ' . P . 'login_attempts WHERE ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)', [client_ip()])->fetchColumn();
    return $n >= 10;
}
function record_failed_attempt(string $email = ''): void {
    q('INSERT INTO ' . P . 'login_attempts (ip, email) VALUES (?, ?)', [client_ip(), $email]);
}

/* ---------- Tokeny (overenie e-mailu, zmena e-mailu) ---------- */
function create_token(int $uid, string $type, ?string $data, int $hours): string {
    $t = bin2hex(random_bytes(32));
    q('UPDATE ' . P . 'tokens SET used = 1 WHERE user_id = ? AND type = ? AND used = 0', [$uid, $type]);
    q('INSERT INTO ' . P . 'tokens (user_id, type, token_hash, data, expires_at) VALUES (?,?,?,?, NOW() + INTERVAL ' . (int)$hours . ' HOUR)',
      [$uid, $type, hash('sha256', $t), $data]);
    return $t;
}
function send_verify_mail(array $u): bool {
    $t = create_token((int)$u['id'], 'verify', $u['email'], 48);
    return send_mail($u['email'], 'Over svoj e-mail – ' . cfg('app_name'), 'Potvrď svoju e-mailovú adresu',
        '<p>Ahoj ' . e($u['name']) . ', ďakujeme za registráciu. Na dokončenie klikni na tlačidlo nižšie (platí 48 hodín).</p>',
        'Overiť e-mail', public_url() . '/verify.php?token=' . $t);
}

/* ---------- Kvóty ---------- */
/** Využité miesto = súbory (vrátane koša) + staršie verzie */
function used_space(int $uid): int {
    $f = (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'files WHERE user_id = ?', [$uid])->fetchColumn();
    try { $v = (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'file_versions WHERE user_id = ?', [$uid])->fetchColumn(); }
    catch (Throwable $e) { $v = 0; }
    return $f + $v;
}
function user_quota(array $u): int { return (int)$u['quota']; } // 0 = neobmedzené

function storage_dir(int $uid): string {
    $d = rtrim(cfg('storage_path'), '/\\') . '/files/' . $uid;
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d;
}

function view(string $name, array $vars = []): void {
    extract($vars);
    require APP_ROOT . '/inc/views/' . $name . '.php';
}

/* ---------- Načítanie modulov, migrácie, denná údržba ---------- */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/digest.php';

setting('schema_version');
if (!empty($GLOBALS['_settings_ok']) && (int)setting('schema_version', '1') < SCHEMA_VERSION) {
    try { migrate(); } catch (Throwable $e) { error_log('[cloud] migrácia zlyhala: ' . $e->getMessage()); }
}
if (!empty($GLOBALS['_settings_ok']) && setting('housekeeping_day') !== date('Y-m-d')) {
    defer_task(function () { housekeeping(); });
}
