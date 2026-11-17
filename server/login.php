<?php
require __DIR__ . '/inc/core.php';
if (current_user()) redirect('index.php');

$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if (too_many_attempts()) {
        flash('danger', 'Príliš veľa neúspešných pokusov. Skús to o 15 minút.');
    } else {
        $u = q('SELECT * FROM ' . P . 'users WHERE email = ?', [$email])->fetch();
        if ($u && password_verify($pass, $u['password'])) {
            if (empty($u['email_verified']) && isset($u['email_verified'])) {
                $_SESSION['unverified_email'] = $u['email'];
                flash('warning', 'Najprv over svoj e-mail – klikni na odkaz, ktorý sme ti poslali.');
            } elseif (!$u['is_active']) {
                flash('warning', 'Účet zatiaľ nie je aktívny. Počkaj na schválenie administrátorom.');
            } else {
                if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
                    q('UPDATE ' . P . 'users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                }
                login_user($u, !empty($_POST['remember']), 'heslo');
                $hasKey = (int)q('SELECT COUNT(*) FROM ' . P . 'webauthn WHERE user_id = ?', [$u['id']])->fetchColumn();
                if (!$hasKey) $_SESSION['offer_passkey'] = true;
                log_action((int)$u['id'], 'login', 'heslo');
                redirect('index.php');
            }
        } else {
            record_failed_attempt($email);
            flash('danger', 'Nesprávny e-mail alebo heslo.');
        }
    }
    redirect('login.php?e=' . urlencode($email));
}
$email = (string)($_GET['e'] ?? '');

$heading = 'Prihlásenie';
$subheading = 'Vitaj späť v ' . cfg('app_name');
$title = 'Prihlásenie';
$scripts = ['assets/js/passkey.js'];
require __DIR__ . '/inc/views/auth_start.php';
?>
<div id="passkeyLogin" class="d-none mb-4">
  <button type="button" class="btn btn-passkey w-100 py-3" id="btnPasskeyLogin">
    <i class="bi bi-fingerprint fs-3 d-block mb-1"></i>
    <span class="fw-semibold">Prihlásiť sa odtlačkom prsta</span>
    <span class="d-block small opacity-75">alebo Face ID / Windows Hello</span>
  </button>
  <div class="divider-text my-4"><span>alebo heslom</span></div>
</div>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-floating mb-3">
    <input type="email" class="form-control" id="email" name="email" placeholder="meno@domena.sk" value="<?= e($email) ?>" required autocomplete="username webauthn">
    <label for="email">E-mail</label>
  </div>
  <div class="form-floating mb-3 position-relative">
    <input type="password" class="form-control" id="password" name="password" placeholder="Heslo" required autocomplete="current-password">
    <label for="password">Heslo</label>
    <button type="button" class="btn btn-icon pass-toggle" data-toggle-pass="#password" tabindex="-1"><i class="bi bi-eye"></i></button>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" checked>
      <label class="form-check-label" for="remember">Zapamätať si ma</label>
    </div>
    <a href="<?= e(url('forgot.php')) ?>" class="small">Zabudnuté heslo?</a>
  </div>
  <button class="btn btn-primary btn-lg w-100 rounded-pill">Prihlásiť sa</button>
</form>
<?php if (!empty($_SESSION['unverified_email'])): ?>
<form method="post" action="<?= e(url('verify.php')) ?>" class="mt-3 text-center">
  <?= csrf_field() ?><input type="hidden" name="email" value="<?= e($_SESSION['unverified_email']) ?>">
  <button class="btn btn-link btn-sm"><i class="bi bi-envelope-arrow-up me-1"></i>Poslať overovací e-mail znova</button>
</form>
<?php unset($_SESSION['unverified_email']); endif; ?>
<?php if (setting('registration', 'open') !== 'closed'): ?>
<p class="text-center mt-4 mb-0">Nemáš účet? <a href="<?= e(url('register.php')) ?>" class="fw-semibold">Zaregistruj sa</a></p>
<?php endif; ?>
<?php require __DIR__ . '/inc/views/auth_end.php'; ?>
