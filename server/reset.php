<?php
require __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/mailer.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $row = q('SELECT r.*, u.email, u.name FROM ' . P . 'password_resets r JOIN ' . P . 'users u ON u.id = r.user_id
              WHERE r.token_hash = ? AND r.used = 0 AND r.expires_at > NOW()', [hash('sha256', $token)])->fetch();
}
if (!$row) { flash('danger', 'Odkaz na obnovu hesla je neplatný alebo vypršal.'); redirect('forgot.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if (strlen($p1) < 8) flash('danger', 'Heslo musí mať aspoň 8 znakov.');
    elseif ($p1 !== $p2) flash('danger', 'Heslá sa nezhodujú.');
    else {
        q('UPDATE ' . P . 'users SET password = ? WHERE id = ?', [password_hash($p1, PASSWORD_DEFAULT), $row['user_id']]);
        revoke_switch_tokens((int)$row['user_id']);
        q('UPDATE ' . P . 'password_resets SET used = 1 WHERE user_id = ?', [$row['user_id']]);
        q('UPDATE ' . P . 'sessions SET revoked = 1 WHERE user_id = ?', [$row['user_id']]); // odhlás všetky zariadenia
        q('UPDATE ' . P . 'app_tokens SET revoked = 1 WHERE user_id = ?', [$row['user_id']]);
        log_action((int)$row['user_id'], 'password_reset');
        send_mail($row['email'], 'Heslo bolo zmenené – ' . cfg('app_name'), 'Heslo bolo zmenené',
            '<p>Ahoj ' . e($row['name']) . ', heslo k tvojmu účtu bolo práve zmenené (' . date('d.m.Y H:i') . ', IP ' . e(client_ip()) . ').</p>'
            . '<p>Ak si to nebol(a) ty, okamžite kontaktuj administrátora.</p>');
        flash('success', 'Heslo bolo zmenené. Teraz sa môžeš prihlásiť.');
        redirect('login.php?e=' . urlencode($row['email']));
    }
    redirect('reset.php?token=' . $token);
}

$heading = 'Nové heslo';
$subheading = $row['email'];
$title = 'Nové heslo';
require __DIR__ . '/inc/views/auth_start.php';
?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="form-floating mb-1 position-relative">
    <input type="password" class="form-control" id="password" name="password" placeholder="Nové heslo" required minlength="8" autocomplete="new-password" data-strength="#passStrength" autofocus>
    <label for="password">Nové heslo</label>
    <button type="button" class="btn btn-icon pass-toggle" data-toggle-pass="#password" tabindex="-1"><i class="bi bi-eye"></i></button>
  </div>
  <div class="progress mb-3" style="height:4px"><div class="progress-bar" id="passStrength" style="width:0"></div></div>
  <div class="form-floating mb-4">
    <input type="password" class="form-control" id="password2" name="password2" placeholder="Heslo znova" required autocomplete="new-password">
    <label for="password2">Heslo znova</label>
  </div>
  <button class="btn btn-primary btn-lg w-100 rounded-pill">Uložiť heslo</button>
</form>
<?php require __DIR__ . '/inc/views/auth_end.php'; ?>
