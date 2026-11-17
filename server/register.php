<?php
require __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/mailer.php';
if (current_user()) redirect('index.php');

$mode = setting('registration', 'open'); // open | approval | closed
if ($mode === 'closed') { flash('info', 'Registrácia je momentálne vypnutá.'); redirect('login.php'); }

$old = ['name' => '', 'email' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name  = trim((string)($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $old = ['name' => $name, 'email' => $email];
    $err = [];
    if (mb_strlen($name) < 2) $err[] = 'Zadaj meno (aspoň 2 znaky).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'Zadaj platný e-mail.';
    if (strlen($pass) < 8) $err[] = 'Heslo musí mať aspoň 8 znakov.';
    if ($pass !== $pass2) $err[] = 'Heslá sa nezhodujú.';
    if (empty($_POST['terms'])) $err[] = 'Musíš súhlasiť s podmienkami.';
    if (!$err && q('SELECT id FROM ' . P . 'users WHERE email = ?', [$email])->fetch()) $err[] = 'Tento e-mail je už zaregistrovaný.';
    if (too_many_attempts()) $err[] = 'Príliš veľa pokusov, skús neskôr.';

    if ($err) {
        foreach ($err as $m) flash('danger', $m);
    } else {
        $active = $mode === 'open' ? 1 : 0;
        $needVerify = setting('require_email_verify', '1') === '1';
        $quota  = (int)setting('default_quota', cfg('default_quota'));
        q('INSERT INTO ' . P . 'users (name, email, password, quota, is_active, email_verified) VALUES (?,?,?,?,?,?)',
          [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $quota, $active, $needVerify ? 0 : 1]);
        $uid = (int)db()->lastInsertId();
        $u = q('SELECT * FROM ' . P . 'users WHERE id = ?', [$uid])->fetch();
        log_action($uid, 'register', $email);

        if ($needVerify) {
            send_verify_mail($u);
        } elseif ($active) {
            send_mail($email, 'Vitaj v ' . cfg('app_name'), 'Vitaj, ' . $name . '!',
                '<p>Tvoj účet bol úspešne vytvorený. Máš k dispozícii <b>' . e($quota ? format_bytes($quota) : 'neobmedzené') . '</b> miesta na súbory.</p>',
                'Otvoriť môj disk', public_url() . '/index.php');
        }
        if (!$active) {
            send_mail($email, 'Registrácia prijatá – ' . cfg('app_name'), 'Ďakujeme za registráciu',
                '<p>Tvoj účet čaká na schválenie administrátorom. Keď bude aktivovaný, pošleme ti e-mail.</p>');
        }
        notify_admins('register', 'Nový používateľ: ' . $name, $email . ($active ? '' : ' · čaká na schválenie'), 'admin.php?tab=users');
        if (setting('notify_admin_register', '1') === '1') {
            foreach (admin_emails() as $a) {
                send_mail($a, 'Nový používateľ: ' . $name, 'Nová registrácia',
                    '<p><b>' . e($name) . '</b> (' . e($email) . ') sa práve zaregistroval(a).' . ($active ? '' : ' <br>Účet <b>čaká na schválenie</b>.') . '</p>',
                    'Spravovať používateľov', public_url() . '/admin.php?tab=users');
            }
        }
        if ($needVerify) {
            $_SESSION['unverified_email'] = $email;
            flash('success', 'Účet bol vytvorený. Poslali sme ti e-mail s odkazom na overenie – po overení sa môžeš prihlásiť.');
            redirect('login.php?e=' . urlencode($email));
        }
        if ($active) {
            login_user($u, false, 'registrácia');
            $_SESSION['offer_passkey'] = true;
            flash('success', 'Účet bol vytvorený. Vitaj!');
            redirect('index.php');
        }
        flash('success', 'Registrácia prebehla. Účet čaká na schválenie administrátorom.');
        redirect('login.php');
    }
}

$heading = 'Vytvoriť účet';
$subheading = $mode === 'approval' ? 'Po registrácii účet schváli administrátor' : 'Začni ukladať súbory do cloudu';
$title = 'Registrácia';
require __DIR__ . '/inc/views/auth_start.php';
?>
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-floating mb-3">
    <input type="text" class="form-control" id="name" name="name" placeholder="Meno" value="<?= e($old['name']) ?>" required autocomplete="name">
    <label for="name">Meno a priezvisko</label>
  </div>
  <div class="form-floating mb-3">
    <input type="email" class="form-control" id="email" name="email" placeholder="E-mail" value="<?= e($old['email']) ?>" required autocomplete="email">
    <label for="email">E-mail</label>
  </div>
  <div class="form-floating mb-1 position-relative">
    <input type="password" class="form-control" id="password" name="password" placeholder="Heslo" required minlength="8" autocomplete="new-password" data-strength="#passStrength">
    <label for="password">Heslo (min. 8 znakov)</label>
    <button type="button" class="btn btn-icon pass-toggle" data-toggle-pass="#password" tabindex="-1"><i class="bi bi-eye"></i></button>
  </div>
  <div class="progress mb-3" style="height:4px"><div class="progress-bar" id="passStrength" style="width:0"></div></div>
  <div class="form-floating mb-3">
    <input type="password" class="form-control" id="password2" name="password2" placeholder="Heslo znova" required autocomplete="new-password">
    <label for="password2">Heslo znova</label>
  </div>
  <div class="form-check mb-4">
    <input class="form-check-input" type="checkbox" name="terms" id="terms" value="1" required>
    <label class="form-check-label small" for="terms">Súhlasím so spracovaním údajov a podmienkami používania</label>
  </div>
  <button class="btn btn-primary btn-lg w-100 rounded-pill">Zaregistrovať sa</button>
</form>
<p class="text-center mt-4 mb-0">Už máš účet? <a href="<?= e(url('login.php')) ?>" class="fw-semibold">Prihlás sa</a></p>
<?php require __DIR__ . '/inc/views/auth_end.php'; ?>
