<?php
require __DIR__ . '/inc/core.php';
if (current_user()) redirect('index.php');

$email = '';
/* druhý krok: kód z aplikácie (dvojstupňové overenie) */
$tfa = $_SESSION['tfa'] ?? null;
if ($tfa && (time() - (int)$tfa['t']) > 300) { unset($_SESSION['tfa']); $tfa = null; flash('warning', 'Čas na zadanie kódu vypršal, prihlás sa znova.'); }
if ($tfa && isset($_GET['cancel'])) { unset($_SESSION['tfa']); redirect('login.php'); }
if ($tfa && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    csrf_check();
    $u = q('SELECT * FROM ' . P . 'users WHERE id = ?', [(int)$tfa['uid']])->fetch();
    if (too_many_attempts()) flash('danger', 'Príliš veľa neúspešných pokusov. Skús to o 15 minút.');
    elseif ($u && totp_check($u, (string)$_POST['code'])) {
        unset($_SESSION['tfa']);
        login_user($u, !empty($tfa['remember']), 'heslo + kód');
        log_action((int)$u['id'], 'login', 'heslo + kód');
        redirect('index.php');
    } else {
        record_failed_attempt($u['email'] ?? '');
        flash('danger', 'Nesprávny kód. Skús znova – kód sa mení každých 30 sekúnd.');
    }
    redirect('login.php');
}
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
                if (totp_enabled($u)) { // heslo sedí, ešte kód z aplikácie
                    $_SESSION['tfa'] = ['uid' => (int)$u['id'], 'remember' => !empty($_POST['remember']), 't' => time()];
                    redirect('login.php');
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

if ($tfa) {
    $heading = 'Dvojstupňové overenie';
    $subheading = 'Zadaj 6-miestny kód z overovacej aplikácie';
    $title = 'Overovací kód';
    require __DIR__ . '/inc/views/auth_start.php';
    ?>
<form method="post" novalidate id="tfaForm">
  <?= csrf_field() ?>
  <div class="text-center mb-3"><i class="bi bi-shield-lock text-primary" style="font-size:3rem"></i></div>
  <div class="form-floating mb-3">
    <input type="text" class="form-control text-center fs-4 tfa-code" id="code" name="code" placeholder="123 456" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus>
    <label for="code">Overovací kód</label>
  </div>
  <button class="btn btn-primary btn-lg w-100 rounded-pill">Overiť</button>
  <p class="small text-body-secondary mt-3 mb-1">Nemáš pri sebe telefón? Zadaj jeden zo <b>záložných kódov</b> (napr. <code>4f7a-92c1</code>), ktoré si si uložil pri zapnutí.</p>
  <p class="text-center mb-0"><a href="<?= e(url('login.php?cancel=1')) ?>" class="small">Späť na prihlásenie</a></p>
</form>
<script>document.getElementById('code').addEventListener('input', (e) => { const v = e.target.value.replace(/\s/g, ''); if (/^\d{6}$/.test(v)) document.getElementById('tfaForm').submit(); });</script>
<?php
    require __DIR__ . '/inc/views/auth_end.php';
    exit;
}

$heading = 'Prihlásenie';
$subheading = 'Vitaj späť v ' . cfg('app_name');
$title = 'Prihlásenie';
$scripts = ['assets/js/passkey.js'];
$saved = is_android_app() ? [] : saved_accounts();
require __DIR__ . '/inc/views/auth_start.php';
?>
<?php if ($saved): ?>
<div class="acc-list mb-3" id="savedAccounts">
  <div class="small text-body-secondary mb-2"><?= !empty($_GET['add']) ? 'Prepnúť na uložený účet' : 'Vyber účet' ?></div>
  <?php foreach ($saved as $acc): $acc['avatar_key'] = null; ?>
  <div class="d-flex align-items-center gap-1">
    <form method="post" action="<?= e(url('switch.php')) ?>" class="flex-grow-1 min-w-0"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int)$acc['id'] ?>">
      <button class="acc-item"><?= avatar_html($acc, 'avatar-sm') ?><span class="min-w-0"><span class="d-block fw-semibold text-truncate"><?= e($acc['name']) ?></span><span class="d-block small text-body-secondary text-truncate"><?= e($acc['email']) ?></span></span></button>
    </form>
    <form method="post" action="<?= e(url('switch.php')) ?>" class="mb-2"><?= csrf_field() ?><input type="hidden" name="do" value="forget"><input type="hidden" name="uid" value="<?= (int)$acc['id'] ?>">
      <button class="btn btn-icon" title="Odstrániť z tohto zariadenia"><i class="bi bi-x-lg"></i></button>
    </form>
  </div>
  <?php endforeach; ?>
  <div class="divider-text my-3"><span>alebo iný účet</span></div>
</div>
<?php endif; ?>
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
