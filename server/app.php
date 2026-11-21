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
    case 'info': { // overenie adresy servera v aplikácii (bez prihlásenia)
        json_out(['ok' => true, 'app' => 'cloud', 'name' => cfg('app_name', 'Cloud'), 'version' => APP_VERSION, 'https' => is_https()]);
    }

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
        if (totp_enabled($u)) { // dvojstupňové overenie: aplikácia sa opýta na kód a pošle ho s heslom znova
            $code = trim((string)($in['code'] ?? ''));
            if ($code === '') json_out(['ok' => false, 'need_code' => true, 'error' => 'Zadaj overovací kód z aplikácie (alebo záložný kód).'], 428);
            if (!totp_check($u, $code)) { record_failed_attempt($email); json_out(['ok' => false, 'need_code' => true, 'error' => 'Nesprávny overovací kód.'], 428); }
        }
        $token = bin2hex(random_bytes(32));
        $device = mb_substr(trim((string)($in['device'] ?? 'Android')) ?: 'Android', 0, 100);
        q('INSERT INTO ' . P . 'app_tokens (user_id, token_hash, name, ip) VALUES (?,?,?,?)', [$u['id'], hash('sha256', $token), $device, client_ip()]);
        q('UPDATE ' . P . 'users SET last_login = NOW() WHERE id = ?', [$u['id']]);
        log_action((int)$u['id'], 'app_login', $device);
        notify((int)$u['id'], 'device', 'Prihlásenie v aplikácii', $device . ' · IP ' . client_ip(), 'profile.php#sessions');
        if (setting('notify_new_device', '1') === '1') {
            send_mail($u['email'], 'Prihlásenie v aplikácii – ' . cfg('app_name'), 'Nové prihlásenie v mobilnej aplikácii',
                '<p>Ahoj ' . e($u['name']) . ', do tvojho účtu sa práve prihlásila mobilná aplikácia.</p>'
                . '<p><b>Zariadenie:</b> ' . e($device) . '<br><b>IP adresa:</b> ' . e(client_ip()) . '<br><b>Čas:</b> ' . date('d.m.Y H:i') . '</p>'
                . '<p>Ak si to nebol(a) ty, zmeň si heslo – tým sa odhlásia všetky zariadenia aj aplikácie.</p>',
                'Skontrolovať zariadenia', public_url() . '/profile.php#sessions');
        }
        json_out(['ok' => true, 'token' => $token, 'user' => ['name' => $u['name'], 'email' => $u['email']]]);
    }

    case 'web': { // WebView: token → bežná session, potom presmerovanie do disku
        $u = bearer_user();
        if (!$u) { header('Location: ' . url('login.php')); exit; }
        setcookie('cloud_app', '1', ['expires' => 0, 'path' => app_base_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['cloud_app'] = '1';
        if (empty($_SESSION['uid']) || (int)$_SESSION['uid'] !== (int)$u['id']) login_user($u, true, 'aplikácia');
        $to = preg_match('#^[a-z0-9_\-]+\.php(\?[\w=&%\-]*)?(\#[\w/\-%]*)?$#i', (string)($_GET['to'] ?? '')) ? $_GET['to'] : 'index.php';
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

    case 'notifications': { // nové upozornenia pre Android (od posledného známeho ID)
        $u = bearer_user();
        if (!$u) json_out(['ok' => false, 'error' => 'Neplatné prihlásenie.'], 401);
        $after = max(0, (int)($_GET['after'] ?? 0));
        $items = $after > 0 ? array_reverse(notifications_list((int)$u['id'], 20, $after)) : [];
        $last = (int)q('SELECT COALESCE(MAX(id),0) FROM ' . P . 'notifications WHERE user_id = ?', [$u['id']])->fetchColumn();
        json_out(['ok' => true, 'items' => array_values(array_filter($items, fn($i) => !$i['read'])), 'last' => $last, 'unread' => notifications_unread((int)$u['id'])]);
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
