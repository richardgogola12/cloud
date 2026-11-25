<?php $__ui = !empty($user['id']) ? ui_prefs($user) : [];
  $__acc = $__ui['accent'] ?? 'blue'; ?>
<!doctype html>
<html lang="sk" data-bs-theme="light" data-ver="<?= e(APP_VERSION) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e(UI_ACCENTS[$__acc] ?? '#1a73e8') ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(url()) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e(cfg('app_name')) ?>">
<title><?= e(($title ?? '') ? $title . ' · ' . cfg('app_name') : cfg('app_name')) ?></title>
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(url('assets/icons/icon-192.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('assets/icons/icon-192.png')) ?>">
<script>
  // vzhľad podľa nastavení v účte (na serveri, nič sa neukladá v zariadení); téma „auto“ = podľa systému
  window.CLOUD_UI = <?= json_encode((object)$__ui, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  (function(){var u=window.CLOUD_UI,d=document.documentElement,t=u.theme==='light'||u.theme==='dark'?u.theme:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');d.setAttribute('data-bs-theme',t);
    d.setAttribute('data-accent',u.accent||'blue');d.setAttribute('data-density',u.density||'normal');d.setAttribute('data-thumbs',u.thumbs||'m');
    if(u.anim==='off')d.setAttribute('data-anim','off');})();
</script>
<link href="<?= e(lib('bootstrap/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')) ?>" rel="stylesheet">
<link href="<?= e(lib('bootstrap-icons/bootstrap-icons.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css')) ?>" rel="stylesheet">
<link href="<?= e(lib('select2/select2.min.css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css')) ?>" rel="stylesheet">
<link href="<?= e(lib('select2/select2-bootstrap-5-theme.min.css', 'https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css')) ?>" rel="stylesheet">
<link href="<?= e(url('assets/css/app.css')) ?>?v=<?= e(APP_VERSION) ?>" rel="stylesheet">
</head>
