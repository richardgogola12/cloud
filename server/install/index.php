<?php
/* Inštalátor – spusti raz: https://tvoja-domena/install/  Potom priečinok install/ ZMAŽ. */
require dirname(__DIR__) . '/inc/core.php';

$lock = rtrim(cfg('storage_path'), '/') . '/installed.lock';
$msg = []; $done = false;
if (is_file($lock)) { $msg[] = ['warning', 'Aplikácia je už nainštalovaná. Zmaž priečinok install/.']; $done = true; }

$checks = [
    'PHP 8.1+'          => version_compare(PHP_VERSION, '8.1', '>='),
    'PDO MySQL'         => extension_loaded('pdo_mysql'),
    'OpenSSL (odtlačok)'=> extension_loaded('openssl'),
    'Fileinfo'          => extension_loaded('fileinfo'),
    'GD (miniatúry)'    => extension_loaded('gd'),
    'ZipArchive (ZIP)'  => class_exists('ZipArchive'),
    'mbstring'          => extension_loaded('mbstring'),
    'Úložisko zapisovateľné' => (is_dir(cfg('storage_path')) || @mkdir(cfg('storage_path'), 0750, true)) && is_writable(cfg('storage_path')),
];

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql); // odstráň riadkové komentáre
        foreach (preg_split('/;\s*$/m', $sql) as $stmt) {
            if (trim($stmt) !== '') db()->exec($stmt);
        }
        unset($GLOBALS['_settings']);
        migrate(); // doplní tabuľky a stĺpce novších verzií
        $name = trim($_POST['name'] ?? ''); $email = mb_strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['password'] ?? '';
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) throw new RuntimeException('Vyplň meno, platný e-mail a heslo (min. 8 znakov).');
        q('INSERT INTO ' . P . "users (name, email, password, role, quota, is_active) VALUES (?,?,?,'admin',0,1)
           ON DUPLICATE KEY UPDATE password = VALUES(password), role = 'admin', is_active = 1", [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        setting_set('default_quota', (string)cfg('default_quota'));
        $sp = rtrim(cfg('storage_path'), '/');
        foreach (['files', 'thumbs', 'tmp', 'sessions'] as $d) if (!is_dir("$sp/$d")) mkdir("$sp/$d", 0750, true);
        file_put_contents("$sp/.htaccess", "Require all denied\nDeny from all\n");
        file_put_contents("$sp/index.html", '');
        file_put_contents($lock, date('c'));
        $msg[] = ['success', 'Hotovo! Admin účet bol vytvorený. ZMAŽ priečinok install/ a prihlás sa.'];
        $done = true;
    } catch (Throwable $ex) {
        $msg[] = ['danger', $ex->getMessage()];
    }
}
?>
<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inštalácia Cloud</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"></head>
<body class="bg-body-tertiary"><div class="container py-5" style="max-width:560px">
<h1 class="h3 fw-bold mb-4"><i class="bi bi-cloud-fill text-primary me-2"></i>Inštalácia Cloud</h1>
<?php foreach ($msg as [$t, $m]): ?><div class="alert alert-<?= $t ?>"><?= e($m) ?></div><?php endforeach; ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Kontrola servera</h2>
  <ul class="list-unstyled mb-0 small"><?php foreach ($checks as $k => $ok): ?><li><i class="bi <?= $ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?> me-2"></i><?= e($k) ?></li><?php endforeach; ?></ul>
  <div class="small text-body-secondary mt-2">app_url: <code><?= e(cfg('app_url')) ?></code> · upload_max_filesize: <code><?= e(ini_get('upload_max_filesize')) ?></code></div>
</div></div>
<?php if ($done): ?>
  <a href="../login.php" class="btn btn-primary btn-lg w-100 rounded-pill">Prejsť na prihlásenie</a>
<?php else: ?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body">
  <?= csrf_field() ?>
  <h2 class="h6 fw-bold mb-3">Účet administrátora</h2>
  <div class="mb-3"><label class="form-label">Meno</label><input class="form-control" name="name" required></div>
  <div class="mb-3"><label class="form-label">E-mail</label><input type="email" class="form-control" name="email" required></div>
  <div class="mb-3"><label class="form-label">Heslo (min. 8 znakov)</label><input type="password" class="form-control" name="password" minlength="8" required></div>
  <button class="btn btn-primary w-100 rounded-pill">Nainštalovať</button>
</div></form>
<?php endif; ?>
</div></body></html>
