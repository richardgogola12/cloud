<?php
/* WebAuthn API – prihlásenie odtlačkom prsta a správa kľúčov */
require __DIR__ . '/inc/core.php';
require __DIR__ . '/inc/webauthn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Metóda nie je povolená'], 405);
csrf_check();
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$action = $_GET['action'] ?? '';
$T = P . 'webauthn';

try {
    switch ($action) {
        /* ---------- prihlásenie ---------- */
        case 'login_options':
            if (too_many_attempts()) throw new RuntimeException('Príliš veľa pokusov, skús neskôr.');
            $allow = [];
            $email = trim((string)($in['email'] ?? ''));
            if ($email !== '') {
                $allow = q("SELECT w.credential_id FROM $T w JOIN " . P . 'users u ON u.id = w.user_id WHERE u.email = ?', [$email])->fetchAll(PDO::FETCH_COLUMN);
            }
            json_out(['ok' => true, 'options' => WebAuthn::loginOptions($allow)]);

        case 'login_verify':
            if (too_many_attempts()) throw new RuntimeException('Príliš veľa pokusov, skús neskôr.');
            $cred = q("SELECT * FROM $T WHERE credential_id = ?", [(string)($in['id'] ?? '')])->fetch();
            if (!$cred) { record_failed_attempt(); throw new RuntimeException('Tento odtlačok nie je priradený k žiadnemu účtu. Prihlás sa heslom a pridaj ho v nastaveniach účtu.'); }
            try {
                $count = WebAuthn::loginVerify($in, $cred);
            } catch (Throwable $ex) { record_failed_attempt(); throw $ex; }
            if (!empty($in['userHandle']) && b64u_dec($in['userHandle']) !== (string)$cred['user_id']) throw new RuntimeException('Používateľ nesúhlasí.');
            $u = q('SELECT * FROM ' . P . 'users WHERE id = ?', [$cred['user_id']])->fetch();
            if (!$u || !$u['is_active']) throw new RuntimeException('Účet nie je aktívny.');
            if (isset($u['email_verified']) && !$u['email_verified']) throw new RuntimeException('Najprv over svoj e-mail.');
            q("UPDATE $T SET sign_count = ?, last_used = NOW() WHERE id = ?", [$count, $cred['id']]);
            login_user($u, true, 'odtlačok prsta');
            log_action((int)$u['id'], 'login', 'odtlačok: ' . $cred['name']);
            json_out(['ok' => true, 'redirect' => url('index.php')]);

        /* ---------- registrácia nového kľúča (prihlásený) ---------- */
        case 'register_options':
            $u = require_login();
            $ids = q("SELECT credential_id FROM $T WHERE user_id = ?", [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
            json_out(['ok' => true, 'options' => WebAuthn::registerOptions($u, $ids)]);

        case 'register_verify':
            $u = require_login();
            $r = WebAuthn::registerVerify($in);
            $name = mb_substr(trim((string)($in['name'] ?? '')) ?: 'Zariadenie', 0, 100);
            q("INSERT INTO $T (user_id, credential_id, public_key, sign_count, name) VALUES (?,?,?,?,?)",
              [$u['id'], $r['credential_id'], $r['public_key'], $r['sign_count'], $name]);
            unset($_SESSION['offer_passkey']);
            log_action((int)$u['id'], 'passkey_add', $name);
            json_out(['ok' => true]);

        case 'delete':
            $u = require_login();
            q("DELETE FROM $T WHERE id = ? AND user_id = ?", [(int)($in['id'] ?? 0), $u['id']]);
            log_action((int)$u['id'], 'passkey_delete', (string)($in['id'] ?? ''));
            json_out(['ok' => true]);

        case 'dismiss':
            unset($_SESSION['offer_passkey']);
            json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'Neznáma akcia'], 400);
} catch (Throwable $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()], 400);
}
