<?php
require __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/mailer.php';
if (current_user()) redirect('index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    if (too_many_attempts()) {
        flash('danger', 'Príliš veľa pokusov. Skús to neskôr.');
        redirect('forgot.php');
    }
    record_failed_attempt($email); // obmedzí spam žiadostí
    $u = q('SELECT * FROM ' . P . 'users WHERE email = ? AND is_active = 1', [$email])->fetch();
    if ($u) {
        $token = bin2hex(random_bytes(32));
        q('UPDATE ' . P . 'password_resets SET used = 1 WHERE user_id = ? AND used = 0', [$u['id']]);
        q('INSERT INTO ' . P . 'password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)',
          [$u['id'], hash('sha256', $token)]);
        send_mail($u['email'], 'Obnova hesla – ' . cfg('app_name'), 'Obnova hesla',
            '<p>Ahoj ' . e($u['name']) . ',</p><p>dostali sme žiadosť o obnovu hesla k tvojmu účtu. Odkaz platí <b>1 hodinu</b>.</p>'
            . '<p>Ak si o obnovu nežiadal(a), tento e-mail ignoruj – heslo zostane nezmenené.</p>',
            'Nastaviť nové heslo', public_url() . '/reset.php?token=' . $token);
        log_action((int)$u['id'], 'reset_request', client_ip());
    }
    // rovnaká odpoveď vždy – neprezrádzame, či e-mail existuje
    flash('success', 'Ak účet s týmto e-mailom existuje, poslali sme naň odkaz na obnovu hesla.');
    redirect('login.php');
}

$heading = 'Zabudnuté heslo';
$subheading = 'Pošleme ti odkaz na nastavenie nového hesla';
$title = 'Zabudnuté heslo';
require __DIR__ . '/inc/views/auth_start.php';
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="form-floating mb-4">
    <input type="email" class="form-control" id="email" name="email" placeholder="E-mail" required autocomplete="email" autofocus>
    <label for="email">E-mail</label>
  </div>
  <button class="btn btn-primary btn-lg w-100 rounded-pill">Poslať odkaz</button>
</form>
<p class="text-center mt-4 mb-0"><a href="<?= e(url('login.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Späť na prihlásenie</a></p>
<?php require __DIR__ . '/inc/views/auth_end.php'; ?>
