<?php
require __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/mailer.php';
$user = require_login();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'avatar') {
        try {
            $f = $_FILES['avatar'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Fotka je príliš veľká.' : 'Vyber fotku.');
            avatar_save($uid, $f['tmp_name']);
            log_action($uid, 'avatar_change');
            flash('success', 'Profilová fotka bola uložená (zašifrovaná).');
        } catch (Throwable $e) { flash('danger', $e->getMessage()); }
    } elseif ($act === 'avatar_remove') {
        avatar_delete($uid);
        flash('success', 'Profilová fotka bola odstránená.');
    } elseif ($act === 'profile') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if (mb_strlen($name) < 2) flash('danger', 'Meno je príliš krátke.');
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) flash('danger', 'Neplatný e-mail.');
        elseif (q('SELECT id FROM ' . P . 'users WHERE email = ? AND id <> ?', [$email, $uid])->fetch()) flash('danger', 'Tento e-mail už používa iný účet.');
        else {
            q('UPDATE ' . P . 'users SET name = ? WHERE id = ?', [$name, $uid]);
            if ($email !== $user['email']) {
                // zmena e-mailu sa prejaví až po potvrdení z novej adresy
                q('UPDATE ' . P . 'users SET pending_email = ? WHERE id = ?', [$email, $uid]);
                $t = create_token($uid, 'email_change', $email, 24);
                $ok = send_mail($email, 'Potvrď nový e-mail – ' . cfg('app_name'), 'Potvrď zmenu e-mailu',
                    '<p>Ahoj ' . e($name) . ', požiadal(a) si o zmenu e-mailu účtu na túto adresu. Potvrď ju kliknutím (platí 24 hodín).</p>',
                    'Potvrdiť nový e-mail', public_url() . '/verify.php?token=' . $t);
                send_mail($user['email'], 'Žiadosť o zmenu e-mailu – ' . cfg('app_name'), 'Žiadosť o zmenu e-mailu',
                    '<p>Bola podaná žiadosť o zmenu e-mailu tvojho účtu na <b>' . e($email) . '</b>. Ak si to nebol(a) ty, zmeň si heslo a kontaktuj administrátora.</p>');
                $ok ? flash('info', 'Na ' . $email . ' sme poslali potvrdzovací odkaz. E-mail sa zmení po jeho potvrdení.')
                    : flash('danger', 'Potvrdzovací e-mail sa nepodarilo odoslať.');
            } else {
                flash('success', 'Údaje boli uložené.');
            }
        }
    } elseif ($act === 'password') {
        $cur = (string)($_POST['current'] ?? '');
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (!password_verify($cur, $user['password'])) flash('danger', 'Súčasné heslo nie je správne.');
        elseif (strlen($p1) < 8) flash('danger', 'Nové heslo musí mať aspoň 8 znakov.');
        elseif ($p1 !== $p2) flash('danger', 'Heslá sa nezhodujú.');
        else {
            q('UPDATE ' . P . 'users SET password = ? WHERE id = ?', [password_hash($p1, PASSWORD_DEFAULT), $uid]);
            revoke_switch_tokens($uid); remember_account($uid);
            session_regenerate_id(true);
            q('UPDATE ' . P . 'sessions SET revoked = 1 WHERE user_id = ? AND id <> ?', [$uid, (int)($_SESSION['sid_row'] ?? 0)]);
            q('UPDATE ' . P . 'app_tokens SET revoked = 1 WHERE user_id = ? AND id <> ?', [$uid, (int)($GLOBALS['_app_token_id'] ?? 0)]);
            log_action($uid, 'password_change');
            send_mail($user['email'], 'Heslo bolo zmenené – ' . cfg('app_name'), 'Heslo bolo zmenené',
                '<p>Heslo k tvojmu účtu bolo zmenené ' . date('d.m.Y H:i') . '. Ak si to nebol(a) ty, okamžite kontaktuj administrátora.</p>');
            flash('success', 'Heslo bolo zmenené. Ostatné zariadenia boli odhlásené.');
        }
    } elseif ($act === 'session_revoke') {
        q('UPDATE ' . P . 'sessions SET revoked = 1 WHERE id = ? AND user_id = ?', [(int)($_POST['sid'] ?? 0), $uid]);
        log_action($uid, 'session_revoke');
        flash('success', 'Zariadenie bolo odhlásené.');
    } elseif ($act === 'session_revoke_all') {
        q('UPDATE ' . P . 'sessions SET revoked = 1 WHERE user_id = ? AND id <> ?', [$uid, (int)($_POST['sid'] ?? 0) ?: (int)($_SESSION['sid_row'] ?? 0)]);
        log_action($uid, 'session_revoke_all');
        flash('success', 'Všetky ostatné zariadenia boli odhlásené.');
    } elseif ($act === 'app_revoke') {
        q('UPDATE ' . P . 'app_tokens SET revoked = 1 WHERE id = ? AND user_id = ?', [(int)($_POST['tid'] ?? 0), $uid]);
        log_action($uid, 'app_revoke');
        flash('success', 'Aplikácia bola odhlásená.');
    } elseif ($act === 'dav_create') { // heslo pre sieťový disk (WebDAV)
        $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 100)) ?: 'Sieťový disk';
        $abc = 'abcdefghjkmnpqrstuvwxyz23456789';
        $pw = '';
        for ($i = 0; $i < 16; $i++) $pw .= $abc[random_int(0, strlen($abc) - 1)];
        $pw = implode('-', str_split($pw, 4));
        q('INSERT INTO ' . P . 'dav_passwords (user_id, name, hash) VALUES (?,?,?)', [$uid, $name, hash('sha256', str_replace('-', '', $pw))]);
        log_action($uid, 'dav_password', $name);
        $_SESSION['dav_new'] = ['name' => $name, 'pw' => $pw];
    } elseif ($act === 'dav_delete') {
        q('DELETE FROM ' . P . 'dav_passwords WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $uid]);
        flash('success', 'Heslo pre sieťový disk bolo zrušené – zariadenie sa už nepripojí.');
    } elseif ($act === 'cancel_email') {
        q('UPDATE ' . P . 'users SET pending_email = NULL WHERE id = ?', [$uid]);
        q('UPDATE ' . P . "tokens SET used = 1 WHERE user_id = ? AND type = 'email_change'", [$uid]);
        flash('success', 'Zmena e-mailu bola zrušená.');
    }
    redirect('profile.php');
}

$sessions = q('SELECT * FROM ' . P . 'sessions WHERE user_id = ? AND revoked = 0 AND last_seen > (NOW() - INTERVAL ' . (int)ceil(cfg('session_lifetime') / 86400) . ' DAY) ORDER BY last_seen DESC', [$uid])->fetchAll();
$apps = q('SELECT * FROM ' . P . 'app_tokens WHERE user_id = ? AND revoked = 0 ORDER BY COALESCE(last_used, created_at) DESC', [$uid])->fetchAll();
$keys = q('SELECT id, name, created_at, last_used FROM ' . P . 'webauthn WHERE user_id = ? ORDER BY created_at DESC', [$uid])->fetchAll();
$davPw = q('SELECT * FROM ' . P . 'dav_passwords WHERE user_id = ? ORDER BY created_at DESC', [$uid])->fetchAll();
$davNew = $_SESSION['dav_new'] ?? null; unset($_SESSION['dav_new']);
$davUrl = (request_base_url() ?: public_url()) . '/dav.php';
$davHost = (string)parse_url($davUrl, PHP_URL_HOST);
$davHttps = str_starts_with($davUrl, 'https://');
$title = 'Môj účet';
$active = 'profile';
$scripts = ['assets/js/passkey.js', 'assets/js/profile.js'];
require __DIR__ . '/inc/views/layout_start.php';
$used = used_space($uid);
?>
<h1 class="h4 fw-bold mb-4">Môj účet</h1>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-person me-2"></i>Osobné údaje</h2>
        <div class="d-flex align-items-center gap-3 mb-4">
          <?= avatar_html($user, 'avatar-xl') ?>
          <div>
            <form method="post" enctype="multipart/form-data" class="d-inline" id="avatarForm"><?= csrf_field() ?><input type="hidden" name="act" value="avatar">
              <label class="btn btn-outline-primary rounded-pill btn-sm px-3 mb-1"><i class="bi bi-camera me-1"></i><?= empty($user['avatar_key']) ? 'Pridať fotku' : 'Zmeniť fotku' ?>
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none" onchange="this.form.submit()"></label>
            </form>
            <?php if (!empty($user['avatar_key'])): ?>
            <form method="post" class="d-inline" data-confirm="Odstrániť profilovú fotku?"><?= csrf_field() ?><input type="hidden" name="act" value="avatar_remove">
              <button class="btn btn-link btn-sm text-danger text-decoration-none mb-1">Odstrániť</button></form>
            <?php endif; ?>
            <div class="small text-body-secondary"><i class="bi bi-lock me-1"></i>Fotka sa ukladá zašifrovaná. Orezaná na štvorec.</div>
          </div>
        </div>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="act" value="profile">
          <div class="mb-3"><label class="form-label">Meno</label><input class="form-control" name="name" value="<?= e($user['name']) ?>" required></div>
          <div class="mb-3"><label class="form-label">E-mail</label><input type="email" class="form-control" name="email" value="<?= e($user['email']) ?>" required>
            <?php if (!empty($user['pending_email'])): ?><div class="form-text text-warning"><i class="bi bi-hourglass-split me-1"></i>Čaká na potvrdenie: <b><?= e($user['pending_email']) ?></b>
              <button class="btn btn-link btn-sm p-0 align-baseline" form="cancelEmail">zrušiť</button></div><?php endif; ?>
          </div>
          <button class="btn btn-primary rounded-pill px-4">Uložiť</button>
        </form>
      </div>
    </div>
    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-key me-2"></i>Zmena hesla</h2>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="act" value="password">
          <div class="mb-3"><label class="form-label">Súčasné heslo</label><input type="password" class="form-control" name="current" required autocomplete="current-password"></div>
          <div class="row g-3 mb-3">
            <div class="col-sm-6"><label class="form-label">Nové heslo</label><input type="password" class="form-control" name="password" minlength="8" required autocomplete="new-password" data-strength="#ps"></div>
            <div class="col-sm-6"><label class="form-label">Nové heslo znova</label><input type="password" class="form-control" name="password2" required autocomplete="new-password"></div>
          </div>
          <div class="progress mb-3" style="height:4px"><div class="progress-bar" id="ps" style="width:0"></div></div>
          <button class="btn btn-primary rounded-pill px-4">Zmeniť heslo</button>
        </form>
      </div>
    </div>
    <div class="card card-soft mb-4" id="sessions">
      <div class="card-body p-4">
        <div class="d-flex align-items-center mb-3">
          <h2 class="h6 fw-bold mb-0 me-auto"><i class="bi bi-laptop me-2"></i>Prihlásené zariadenia</h2>
          <?php if (count($sessions) > 1): ?>
          <form method="post" data-confirm="Odhlásiť všetky ostatné zariadenia?"><?= csrf_field() ?><input type="hidden" name="act" value="session_revoke_all">
            <button class="btn btn-sm btn-outline-danger rounded-pill">Odhlásiť ostatné</button></form>
          <?php endif; ?>
        </div>
        <ul class="list-group list-group-flush">
        <?php foreach ($sessions as $s): $cur = (int)$s['id'] === (int)($_SESSION['sid_row'] ?? 0); $ua = $s['ua'] ?? ''; ?>
          <li class="list-group-item px-0 d-flex align-items-center gap-3">
            <i class="bi <?= preg_match('/iPhone|Android.*Mobile/', $ua) ? 'bi-phone' : (preg_match('/iPad|Android/', $ua) ? 'bi-tablet' : 'bi-laptop') ?> fs-4 <?= $cur ? 'text-success' : 'text-primary' ?>"></i>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate"><?= e(ua_label($ua)) ?> <?= $cur ? '<span class="badge text-bg-success">toto zariadenie</span>' : '' ?></div>
              <div class="small text-body-secondary">IP <?= e($s['ip']) ?> · <?= e($s['method'] ?? '') ?> · aktívne <?= date('d.m.Y H:i', strtotime($s['last_seen'])) ?></div>
            </div>
            <?php if (!$cur): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="session_revoke"><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-icon text-danger" title="Odhlásiť"><i class="bi bi-box-arrow-right"></i></button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php foreach ($apps as $a): ?>
          <li class="list-group-item px-0 d-flex align-items-center gap-3">
            <i class="bi bi-android2 fs-4 text-success"></i>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate">Aplikácia · <?= e($a['name']) ?></div>
              <div class="small text-body-secondary">IP <?= e($a['ip']) ?> · prihlásená <?= date('d.m.Y', strtotime($a['created_at'])) ?><?= $a['last_used'] ? ' · aktívna ' . date('d.m.Y H:i', strtotime($a['last_used'])) : '' ?></div>
            </div>
            <form method="post" data-confirm="Odhlásiť aplikáciu na zariadení <?= e($a['name']) ?>? Zastaví sa aj synchronizácia."><?= csrf_field() ?><input type="hidden" name="act" value="app_revoke"><input type="hidden" name="tid" value="<?= (int)$a['id'] ?>">
              <button class="btn btn-icon text-danger" title="Odhlásiť"><i class="bi bi-box-arrow-right"></i></button></form>
          </li>
        <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-1"><i class="bi bi-fingerprint me-2"></i>Prihlásenie odtlačkom prsta</h2>
        <p class="small text-body-secondary">Odtlačok prsta, Face ID alebo Windows Hello. Biometria nikdy neopustí tvoje zariadenie.</p>
        <ul class="list-group list-group-flush mb-3" id="pkList">
          <?php foreach ($keys as $k): ?>
          <li class="list-group-item px-0 d-flex align-items-center gap-3">
            <i class="bi bi-phone fs-4 text-primary"></i>
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate"><?= e($k['name']) ?></div>
              <div class="small text-body-secondary">Pridané <?= date('d.m.Y', strtotime($k['created_at'])) ?><?= $k['last_used'] ? ' · použité ' . date('d.m.Y H:i', strtotime($k['last_used'])) : '' ?></div>
            </div>
            <button class="btn btn-icon text-danger" data-pk-del="<?= (int)$k['id'] ?>" title="Odstrániť"><i class="bi bi-trash3"></i></button>
          </li>
          <?php endforeach; ?>
          <?php if (!$keys): ?><li class="list-group-item px-0 text-body-secondary small">Zatiaľ nemáš pridané žiadne zariadenie.</li><?php endif; ?>
        </ul>
        <div class="input-group">
          <input class="form-control" id="pkName" maxlength="100" placeholder="Názov zariadenia" aria-label="Názov zariadenia">
          <button class="btn btn-primary" id="pkAdd"><i class="bi bi-fingerprint me-1"></i>Pridať</button>
        </div>
        <div class="small text-danger mt-2 d-none" id="pkUnsupported">Toto zariadenie alebo prehliadač nepodporuje biometrické prihlásenie (vyžaduje HTTPS).</div>
      </div>
    </div>
    <div class="card card-soft mb-4" id="columns">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-1"><i class="bi bi-layout-three-columns me-2"></i>Stĺpce v zozname</h2>
        <p class="small text-body-secondary">Vyber, čo chceš vidieť pri súboroch v zobrazení <b>Zoznam</b> – zvlášť pre každý pohľad. V mobile sa prvé tri zobrazia pod názvom. Platí na webe aj v aplikácii.</p>
        <?php $myCols = user_prefs($user)['columns'] ?? []; ?>
        <div class="cols-settings">
          <?php foreach (LIST_VIEWS as $v => $vLabel): $cur = $myCols[$v] ?? DEFAULT_COLUMNS[$v]; ?>
          <div class="cols-row" data-view="<?= e($v) ?>">
            <div class="fw-semibold small mb-1"><?= e($vLabel) ?></div>
            <div class="d-flex flex-wrap gap-1">
              <?php foreach (LIST_COLUMNS as $c => $cLabel): if ($c === 'deleted' && $v !== 'trash') continue; $id = 'col_' . $v . '_' . $c; ?>
              <input type="checkbox" class="btn-check" id="<?= e($id) ?>" data-col="<?= e($c) ?>" <?= in_array($c, $cur, true) ? 'checked' : '' ?>>
              <label class="btn btn-sm btn-outline-secondary rounded-pill" for="<?= e($id) ?>"><?= e($cLabel) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2 mt-3">
          <button class="btn btn-primary" type="button" id="colsSave"><i class="bi bi-check-lg me-1"></i>Uložiť</button>
          <button class="btn btn-outline-secondary" type="button" id="colsReset">Predvolené</button>
        </div>
      </div>
    </div>
    <script>
    (() => {
      const defs = <?= json_encode(DEFAULT_COLUMNS) ?>;
      const rows = () => [...document.querySelectorAll('.cols-row')];
      document.getElementById('colsSave').addEventListener('click', async () => {
        const val = {};
        rows().forEach(r => { val[r.dataset.view] = [...r.querySelectorAll('[data-col]')].filter(x => x.checked).map(x => x.dataset.col); });
        const res = await Cloud.api('api.php?action=prefs_save', { key: 'columns', value: JSON.stringify(val) });
        Cloud.toast(res.ok ? 'Stĺpce boli uložené' : (res.error || 'Chyba'), res.ok ? 'success' : 'danger');
      });
      document.getElementById('colsReset').addEventListener('click', () => {
        rows().forEach(r => r.querySelectorAll('[data-col]').forEach(x => { x.checked = (defs[r.dataset.view] || []).includes(x.dataset.col); }));
      });
    })();
    </script>
    <?php $dgc = digest_cfg($user); $dgn = digest_next($dgc); ?>
    <div class="card card-soft mb-4" id="digest">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-1"><i class="bi bi-envelope-paper me-2"></i>E-mailový súhrn</h2>
        <p class="small text-body-secondary">Príde ti e-mail: čo pribudlo, kto ti čo nahral alebo s tebou zdieľal, koľko miesta ostáva a fotky „v tento deň“ z minulých rokov. Ak sa za to obdobie nič nestalo, nepríde nič. Posiela sa na <?= e($user['email']) ?>.</p>
        <div class="row g-2 align-items-end mb-2" id="digestForm">
          <div class="col-12 col-sm-auto"><label class="form-label small mb-1" for="dgFreq">Ako často</label>
            <select class="form-select form-select-sm" id="dgFreq" style="min-width:170px"><?php foreach (DIGEST_FREQS as $k => $l): ?><option value="<?= $k ?>" <?= $dgc['freq'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-sm-auto" data-dg="dow"><label class="form-label small mb-1" for="dgDow">V deň</label>
            <select class="form-select form-select-sm" id="dgDow" style="min-width:130px"><?php foreach (DIGEST_DAYS as $k => $l): ?><option value="<?= $k ?>" <?= $dgc['dow'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-sm-auto" data-dg="dom"><label class="form-label small mb-1" for="dgDom">Deň v mesiaci</label>
            <select class="form-select form-select-sm" id="dgDom"><?php for ($i = 1; $i <= 28; $i++): ?><option value="<?= $i ?>" <?= $dgc['dom'] === $i ? 'selected' : '' ?>><?= $i ?>.</option><?php endfor; ?></select></div>
          <div class="col-6 col-sm-auto" data-dg="hour"><label class="form-label small mb-1" for="dgHour">O koľkej</label>
            <select class="form-select form-select-sm" id="dgHour"><?php for ($i = 0; $i <= 23; $i++): ?><option value="<?= $i ?>" <?= $dgc['hour'] === $i ? 'selected' : '' ?>><?= $i ?>:00</option><?php endfor; ?></select></div>
        </div>
        <div class="small mb-3" id="dgInfo"><?= $dgc['freq'] === 'off' ? '<span class="text-body-secondary">Súhrn je vypnutý.</span>' : 'Chodí <b>' . e(digest_when_text($dgc)) . '</b>' . ($dgn ? ' · najbližší ' . date('j. n. Y \o G:i', $dgn) : '') ?></div>
        <button class="btn btn-outline-secondary btn-sm rounded-pill" type="button" id="digestTest"><i class="bi bi-send me-1"></i>Poslať ukážku teraz</button>
      </div>
    </div>
    <script>
    (() => {
      const $ = (id) => document.getElementById(id);
      const vis = () => { const f = $('dgFreq').value;
        document.querySelector('[data-dg="dow"]').classList.toggle('d-none', !['weekly', 'biweekly'].includes(f));
        document.querySelector('[data-dg="dom"]').classList.toggle('d-none', f !== 'monthly');
        document.querySelector('[data-dg="hour"]').classList.toggle('d-none', f === 'off'); };
      vis();
      const save = async () => {
        vis();
        const v = { freq: $('dgFreq').value, dow: +$('dgDow').value, dom: +$('dgDom').value, hour: +$('dgHour').value };
        const r = await Cloud.api('api.php?action=prefs_save', { key: 'digest', value: JSON.stringify(v) });
        if (!r.ok) return Cloud.toast(r.error || 'Chyba', 'danger');
        const esc = (t) => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        $('dgInfo').innerHTML = r.cfg.freq === 'off' ? '<span class="text-body-secondary">Súhrn je vypnutý.</span>' : 'Chodí <b>' + esc(r.when) + '</b>' + (r.next ? ' · najbližší ' + esc(r.next) : '');
        Cloud.toast(r.cfg.freq === 'off' ? 'Súhrn je vypnutý' : 'Uložené – súhrn chodí ' + r.when, 'success');
      };
      ['dgFreq', 'dgDow', 'dgDom', 'dgHour'].forEach(id => $(id).addEventListener('change', save));
      $('digestTest').addEventListener('click', async (e) => {
        e.target.disabled = true;
        const r = await Cloud.api('api.php?action=digest_test', {});
        e.target.disabled = false;
        Cloud.toast(r.ok ? 'Ukážka odoslaná – pozri e-mail' : (r.error || 'Chyba'), r.ok ? 'success' : 'danger');
      });
    })();
    </script>
    <div class="card card-soft mb-4" id="dav">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-1"><i class="bi bi-hdd-network me-2"></i>Sieťový disk (WebDAV)</h2>
        <p class="small text-body-secondary">Pripoj si Môj disk ako písmeno disku vo Windows, vo Finderi na Macu alebo v správcovi súborov v mobile – súbory potom len presúvaš myšou.</p>
        <label class="form-label small text-body-secondary mb-1">Adresa</label>
        <div class="input-group mb-2"><input class="form-control" id="davUrl" value="<?= e($davUrl) ?>" readonly><button class="btn btn-outline-primary" type="button" data-copy="#davUrl"><i class="bi bi-clipboard"></i></button></div>
        <div class="small mb-3">Meno: <b><?= e($user['email']) ?></b> · heslo: vytvor nižšie (prihlasovacie heslo sa tu nepoužíva)</div>
        <?php if ($davNew): ?>
          <div class="alert alert-success">
            <div class="fw-semibold mb-1">Heslo pre „<?= e($davNew['name']) ?>“</div>
            <div class="input-group"><input class="form-control font-monospace fs-5" id="davNewPw" value="<?= e($davNew['pw']) ?>" readonly><button class="btn btn-success" type="button" data-copy="#davNewPw"><i class="bi bi-clipboard me-1"></i>Kopírovať</button></div>
            <div class="small mt-1">Zobrazí sa iba teraz. Pomlčky môžeš vynechať.</div>
          </div>
        <?php endif; ?>
        <ul class="list-group list-group-flush mb-3">
          <?php foreach ($davPw as $d): ?>
          <li class="list-group-item px-0 d-flex align-items-center gap-3">
            <i class="bi bi-key fs-5 text-primary"></i>
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= e($d['name']) ?></div>
              <div class="small text-body-secondary">Vytvorené <?= date('d.m.Y', strtotime($d['created_at'])) ?> · <?= $d['last_used'] ? 'naposledy ' . date('d.m.Y H:i', strtotime($d['last_used'])) : 'ešte nepoužité' ?></div></div>
            <form method="post" data-confirm="Zrušiť toto heslo? Zariadenie sa už nepripojí."><?= csrf_field() ?><input type="hidden" name="act" value="dav_delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-icon text-danger" title="Zrušiť"><i class="bi bi-trash3"></i></button></form>
          </li>
          <?php endforeach; ?>
        </ul>
        <form method="post" class="input-group mb-3"><?= csrf_field() ?><input type="hidden" name="act" value="dav_create">
          <input class="form-control" name="name" maxlength="100" placeholder="Pre zariadenie, napr. Notebook" aria-label="Názov zariadenia">
          <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Vytvoriť heslo</button>
        </form>
        <div class="accordion accordion-flush small" id="davHelp">
          <div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed py-2 px-0 small" type="button" data-bs-toggle="collapse" data-bs-target="#davWin"><i class="bi bi-windows me-2"></i>Windows</button></h3>
            <div id="davWin" class="accordion-collapse collapse" data-bs-parent="#davHelp"><div class="accordion-body px-0">
              <ol class="ps-3 mb-2"><li>Prieskumník → <b>Tento počítač</b> → hore <b>⋯ → Pripojiť sieťovú jednotku</b>.</li>
                <li>Vyber písmeno, do poľa Priečinok vlož adresu a zaškrtni <b>Pripojiť pomocou iných poverení</b>.</li>
                <li>Zadaj e-mail a heslo pre sieťový disk, zaškrtni <b>Zapamätať</b>.</li></ol>
              <?php if (!$davHttps): ?><div class="text-warning mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Windows sa bez https štandardne nepripojí – použi https adresu servera.</div><?php endif; ?>
              <div class="text-body-secondary">Windows má v predvolení limit 50 MB na súbor. Pre väčšie súbory zvýš <code>FileSizeLimitInBytes</code> v službe WebClient, alebo použi aplikáciu RaiDrive.</div>
            </div></div></div>
          <div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed py-2 px-0 small" type="button" data-bs-toggle="collapse" data-bs-target="#davMac"><i class="bi bi-apple me-2"></i>Mac</button></h3>
            <div id="davMac" class="accordion-collapse collapse" data-bs-parent="#davHelp"><div class="accordion-body px-0">Finder → <b>Prejsť → Pripojiť k serveru</b> (⌘K) → vlož adresu → Pripojiť → e-mail a heslo pre sieťový disk.</div></div></div>
          <div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed py-2 px-0 small" type="button" data-bs-toggle="collapse" data-bs-target="#davLin"><i class="bi bi-ubuntu me-2"></i>Linux</button></h3>
            <div id="davLin" class="accordion-collapse collapse" data-bs-parent="#davHelp"><div class="accordion-body px-0">Súbory → <b>Ďalšie miesta</b> → Pripojiť k serveru: <code><?= e(($davHttps ? 'davs' : 'dav') . substr($davUrl, $davHttps ? 5 : 4)) ?></code></div></div></div>
          <div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed py-2 px-0 small" type="button" data-bs-toggle="collapse" data-bs-target="#davMob"><i class="bi bi-phone me-2"></i>Mobil</button></h3>
            <div id="davMob" class="accordion-collapse collapse" data-bs-parent="#davHelp"><div class="accordion-body px-0">V správcovi súborov s podporou WebDAV (napr. Solid Explorer, CX File Explorer na Androide, Documents na iPhone) pridaj nové pripojenie <b>WebDAV</b>: server <code><?= e($davHost) ?></code>, cesta <code><?= e((string)parse_url($davUrl, PHP_URL_PATH)) ?></code>, <?= $davHttps ? 'HTTPS' : 'HTTP' ?>, e-mail a heslo pre sieťový disk.</div></div></div>
        </div>
      </div>
    </div>
    <div class="card card-soft mb-4">
      <div class="card-body p-4">
        <h2 class="h6 fw-bold mb-3"><i class="bi bi-cloud me-2"></i>Úložisko</h2>
        <div class="display-6 fw-bold"><?= format_bytes($used) ?></div>
        <div class="text-body-secondary mb-3"><?= $user['quota'] ? 'z ' . format_bytes($user['quota']) : 'bez obmedzenia' ?></div>
        <?php if ($user['quota']): $p = min(100, $used / $user['quota'] * 100); ?>
        <div class="progress" style="height:8px"><div class="progress-bar <?= $p >= 90 ? 'bg-danger' : 'bg-primary' ?>" style="width:<?= $p ?>%"></div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!is_android_app()): ?>
    <a class="card card-soft mb-4 text-decoration-none" href="https://github.com/<?= e(cfg('update_repo', 'richardgogola12/cloud')) ?>/releases/latest/download/cloud-android.apk">
      <div class="card-body p-4 d-flex align-items-center gap-3">
        <i class="bi bi-android2 fs-2 text-success"></i>
        <div><div class="fw-semibold text-body">Android aplikácia</div><div class="small text-body-secondary">Stiahnuť APK – synchronizácia zložiek z telefónu, odtlačok prsta</div></div>
        <i class="bi bi-download ms-auto text-primary"></i>
      </div>
    </a>
    <?php endif; ?>
    <div class="card card-soft d-none" data-install>
      <div class="card-body p-4 d-flex align-items-center gap-3" role="button">
        <i class="bi bi-phone-vibrate fs-2 text-primary"></i>
        <div><div class="fw-semibold">Nainštalovať aplikáciu</div><div class="small text-body-secondary">Pridaj Cloud na plochu ako aplikáciu</div></div>
      </div>
    </div>
  </div>
</div>
<form method="post" id="cancelEmail" class="d-none"><?= csrf_field() ?><input type="hidden" name="act" value="cancel_email"></form>
<?php require __DIR__ . '/inc/views/layout_end.php'; ?>
