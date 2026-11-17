<?php
/* ------------------------------------------------------------------
 *  API pre mobilnú aplikáciu (Android)
 *  - login:  e-mail + heslo → prístupový token (hlavička X-Cloud-Token)
 *  - web:    prihlási WebView do webového rozhrania pomocou tokenu
 *  - me:     údaje o používateľovi a úložisku
 *  - logout: zruší token
 * ------------------------------------------------------------------ */
require __DIR__ . '/inc/core.php';

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) $in = $_POST;

try {
    switch ($action) {
    case 'login': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Metóda nie je povolená'], 405);
        if (too_many_attempts()) throw new RuntimeException('Príliš veľa neúspešných pokusov. Skús to o 15 minút.');
        $email = mb_strtolower(trim((string)($in['email'] ?? '')));
        $u = q('SELECT * FROM ' . P . 'users WHERE email = ?', [$email])->fetch();
        if (!$u || !password_verify((string)($in['password'] ?? ''), $u['password'])) {
            record_failed_attempt($email);
            throw new RuntimeException('Nesprávny e-mail alebo heslo.');
        }
        if (isset($u['email_verified']) && !$u['email_verified']) throw new RuntimeException('Najprv over svoj e-mail (odkaz v e-maile z registrácie).');
        if (!$u['is_active']) throw new RuntimeException('Účet zatiaľ nie je aktívny.');
        $token = bin2hex(random_bytes(32));
        $device = mb_substr(trim((string)($in['device'] ?? 'Android')) ?: 'Android', 0, 100);
        q('INSERT INTO ' . P . 'app_tokens (user_id, token_hash, name, ip) VALUES (?,?,?,?)', [$u['id'], hash('sha256', $token), $device, client_ip()]);
        q('UPDATE ' . P . 'users SET last_login = NOW() WHERE id = ?', [$u['id']]);
        log_action((int)$u['id'], 'app_login', $device);
        if (setting('notify_new_device', '1') === '1') {
            send_mail($u['email'], 'Prihlásenie v aplikácii – ' . cfg('app_name'), 'Nové prihlásenie v mobilnej aplikácii',
                '<p>Ahoj ' . e($u['name']) . ', do tvojho účtu sa práve prihlásila mobilná aplikácia.</p>'
                . '<p><b>Zariadenie:</b> ' . e($device) . '<br><b>IP adresa:</b> ' . e(client_ip()) . '<br><b>Čas:</b> ' . date('d.m.Y H:i') . '</p>'
                . '<p>Ak si to nebol(a) ty, zmeň si heslo – tým sa odhlásia všetky zariadenia aj aplikácie.</p>',
                'Skontrolovať zariadenia', cfg('app_url') . '/profile.php#sessions');
        }
        json_out(['ok' => true, 'token' => $token, 'user' => ['name' => $u['name'], 'email' => $u['email']]]);
    }

    case 'web': { // WebView: token → bežná session, potom presmerovanie do disku
        $u = bearer_user();
        if (!$u) { header('Location: ' . url('login.php')); exit; }
        if (empty($_SESSION['uid']) || (int)$_SESSION['uid'] !== (int)$u['id']) login_user($u, true, 'aplikácia');
        $to = preg_match('#^[a-z0-9_\-]+\.php(\#[\w/\-%]*)?$#i', (string)($_GET['to'] ?? '')) ? $_GET['to'] : 'index.php';
        header('Location: ' . url($to));
        exit;
    }

    case 'me': {
        $u = bearer_user();
        if (!$u) json_out(['ok' => false, 'error' => 'Neplatné prihlásenie.'], 401);
        $used = used_space((int)$u['id']);
        json_out(['ok' => true, 'user' => ['name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']],
                  'quota' => ['used' => $used, 'quota' => (int)$u['quota'], 'used_h' => format_bytes($used), 'quota_h' => $u['quota'] ? format_bytes($u['quota']) : null],
                  'chunk' => chunk_size()]);
    }

    case 'logout': {
        if (bearer_user() && !empty($GLOBALS['_app_token_id'])) {
            q('UPDATE ' . P . 'app_tokens SET revoked = 1 WHERE id = ?', [$GLOBALS['_app_token_id']]);
        }
        json_out(['ok' => true]);
    }
    }
    json_out(['ok' => false, 'error' => 'Neznáma akcia'], 400);
} catch (Throwable $ex) {
    json_out(['ok' => false, 'error' => $ex instanceof PDOException ? 'Chyba databázy.' : $ex->getMessage()], 400);
}
