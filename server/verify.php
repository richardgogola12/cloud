<?php
/* Overenie e-mailu (registrácia) a potvrdenie zmeny e-mailu; opätovné odoslanie overovacieho e-mailu */
require __DIR__ . '/inc/core.php';

// opätovné odoslanie
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    if (too_many_attempts()) { flash('danger', 'Príliš veľa pokusov. Skús to neskôr.'); redirect('login.php'); }
    record_failed_attempt($email);
    $u = q('SELECT * FROM ' . P . 'users WHERE email = ? AND email_verified = 0', [$email])->fetch();
    if ($u) send_verify_mail($u);
    flash('success', 'Ak účet čaká na overenie, poslali sme nový overovací e-mail.');
    redirect('login.php?e=' . urlencode($email));
}

$token = (string)($_GET['token'] ?? '');
$row = preg_match('/^[a-f0-9]{64}$/', $token)
    ? q('SELECT t.*, u.email, u.name, u.is_active FROM ' . P . 'tokens t JOIN ' . P . 'users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.used = 0 AND t.expires_at > NOW()', [hash('sha256', $token)])->fetch()
    : null;
if (!$row) { flash('danger', 'Odkaz je neplatný alebo vypršal. Môžeš si nechať poslať nový.'); redirect('login.php'); }

q('UPDATE ' . P . 'tokens SET used = 1 WHERE id = ?', [$row['id']]);
if ($row['type'] === 'verify') {
    q('UPDATE ' . P . 'users SET email_verified = 1 WHERE id = ?', [$row['user_id']]);
    log_action((int)$row['user_id'], 'email_verified', $row['email']);
    if ($row['is_active']) {
        send_mail($row['email'], 'Vitaj v ' . cfg('app_name'), 'Vitaj, ' . $row['name'] . '!',
            '<p>Tvoj e-mail je overený a účet je pripravený. Môžeš nahrávať súbory.</p>', 'Otvoriť môj disk', public_url() . '/index.php');
        flash('success', 'E-mail bol overený. Teraz sa môžeš prihlásiť.');
    } else {
        flash('success', 'E-mail bol overený. Účet ešte musí schváliť administrátor – dáme ti vedieť e-mailom.');
    }
    redirect('login.php?e=' . urlencode($row['email']));
}
if ($row['type'] === 'email_change') {
    $new = (string)$row['data'];
    if (q('SELECT id FROM ' . P . 'users WHERE email = ? AND id <> ?', [$new, $row['user_id']])->fetch()) {
        flash('danger', 'Tento e-mail medzitým začal používať iný účet.');
    } else {
        q('UPDATE ' . P . 'users SET email = ?, pending_email = NULL WHERE id = ?', [$new, $row['user_id']]);
        send_mail($row['email'], 'E-mail účtu bol zmenený – ' . cfg('app_name'), 'E-mail účtu bol zmenený',
            '<p>E-mail tvojho účtu bol zmenený na <b>' . e($new) . '</b>. Ak si to nebol(a) ty, okamžite kontaktuj administrátora.</p>');
        log_action((int)$row['user_id'], 'email_changed', $row['email'] . ' → ' . $new);
        flash('success', 'E-mail bol zmenený na ' . $new . '.');
    }
    redirect(current_user() ? 'profile.php' : 'login.php');
}
redirect('login.php');
