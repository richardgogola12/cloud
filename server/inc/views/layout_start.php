<?php
/** @var array $user  @var string $active  @var string $title */
$used  = used_space((int)$user['id']);
$quota = user_quota($user);
$pct   = $quota > 0 ? min(100, round($used / $quota * 100, 1)) : 0;
$barCls = $pct >= 90 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-primary');
$sharedCount = 0;
try { $sharedCount = (int)q('SELECT COUNT(*) FROM ' . P . 'user_shares WHERE target_id = ?', [$user['id']])->fetchColumn(); } catch (Throwable $e) {}
$nav = [
    ['drive',   'index.php',            'bi-hdd-stack',    'Môj disk'],
    ['shared',  'index.php#shared',     'bi-people',       'Zdieľané so mnou'],
    ['photos',  'index.php#photos',     'bi-images',       'Fotky'],
    ['recent',  'index.php#recent',     'bi-clock-history','Nedávne'],
    ['starred', 'index.php#starred',    'bi-star',         'S hviezdičkou'],
    ['trash',   'index.php#trash',      'bi-trash3',       'Kôš'],
    ['profile', 'profile.php',          'bi-person-gear',  'Môj účet'],
];
if ($user['role'] === 'admin') $nav[] = ['admin', 'admin.php', 'bi-shield-lock', 'Administrácia'];
$inApp = is_android_app();
if ($inApp) { // položky natívnej Android aplikácie
    $nav[] = ['app-sync', 'cloudapp://sync', 'bi-arrow-repeat', 'Synchronizácia telefónu'];
    $nav[] = ['app-update', 'cloudapp://update', 'bi-box-arrow-in-down', 'Aktualizácia aplikácie'];
}
require __DIR__ . '/head.php';
?>
<body class="app">
<header class="app-topbar">
  <button class="btn btn-icon d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
  <a href="<?= e(url('index.php')) ?>" class="brand"><span class="brand-logo"><i class="bi bi-cloud-fill"></i></span><span class="d-none d-sm-inline"><?= e(cfg('app_name')) ?></span></a>
  <?php if (($active ?? '') !== 'admin' && ($active ?? '') !== 'profile'): ?>
  <form class="search-box" id="searchForm" role="search" onsubmit="return false">
    <i class="bi bi-search"></i>
    <input type="search" id="searchInput" class="form-control" placeholder="Hľadať v disku" autocomplete="off">
  </form>
  <?php else: ?><div class="flex-grow-1"></div><?php endif; ?>
  <button class="btn btn-icon" id="themeToggle" title="Prepnúť tmavý režim"><i class="bi bi-moon-stars"></i></button>
  <div class="dropdown">
    <button class="btn avatar-btn" data-bs-toggle="dropdown" aria-label="Účet">
      <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow">
      <li class="px-3 py-2"><div class="fw-semibold"><?= e($user['name']) ?></div><div class="small text-body-secondary"><?= e($user['email']) ?></div></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="bi bi-person-gear me-2"></i>Môj účet</a></li>
      <?php if ($user['role'] === 'admin'): ?><li><a class="dropdown-item" href="<?= e(url('admin.php')) ?>"><i class="bi bi-shield-lock me-2"></i>Administrácia</a></li><?php endif; ?>
      <li><hr class="dropdown-divider"></li>
      <?php if ($inApp): ?>
      <li><a class="dropdown-item text-danger" href="cloudapp://logout"><i class="bi bi-box-arrow-right me-2"></i>Odhlásiť sa z aplikácie</a></li>
      <?php else: ?>
      <li><form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Odhlásiť sa</button></form></li>
      <?php endif; ?>
    </ul>
  </div>
</header>

<div class="app-body">
  <aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="sidebar">
    <div class="offcanvas-header d-lg-none">
      <span class="brand"><span class="brand-logo"><i class="bi bi-cloud-fill"></i></span><?= e(cfg('app_name')) ?></span>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
      <?php if (($active ?? '') === 'drive'): ?>
      <div class="dropdown mb-3">
        <button class="btn btn-new shadow-sm" data-bs-toggle="dropdown"><i class="bi bi-plus-lg me-2"></i>Nový</button>
        <ul class="dropdown-menu shadow">
          <li><a class="dropdown-item" href="#" data-action="new-folder"><i class="bi bi-folder-plus me-2"></i>Nová zložka</a></li>
          <li><a class="dropdown-item" href="#" data-action="new-text"><i class="bi bi-file-earmark-text me-2"></i>Nový textový súbor</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="#" data-action="upload-files"><i class="bi bi-file-earmark-arrow-up me-2"></i>Nahrať súbory</a></li>
          <li><a class="dropdown-item" href="#" data-action="upload-folder"><i class="bi bi-folder-symlink me-2"></i>Nahrať zložku</a></li>
        </ul>
      </div>
      <?php else: ?>
      <a href="<?= e(url('index.php')) ?>" class="btn btn-new shadow-sm mb-3"><i class="bi bi-arrow-left me-2"></i>Späť na disk</a>
      <?php endif; ?>
      <nav class="nav flex-column side-nav">
        <?php foreach ($nav as [$key, $href, $icon, $label]): ?>
          <a class="nav-link <?= ($active ?? '') === $key ? 'active' : '' ?>" href="<?= e(str_starts_with($href, 'cloudapp:') ? $href : url($href)) ?>" data-nav="<?= e($key) ?>"><i class="bi <?= $icon ?>"></i><?= e($label) ?><?= $key === 'shared' && $sharedCount ? '<span class="badge rounded-pill text-bg-light ms-auto">' . $sharedCount . '</span>' : '' ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="quota-box mt-auto">
        <div class="d-flex align-items-center gap-2 mb-2 small fw-semibold"><i class="bi bi-cloud"></i>Úložisko</div>
        <?php if ($quota > 0): ?>
          <div class="progress" style="height:6px"><div class="progress-bar <?= $barCls ?>" id="quotaBar" style="width:<?= $pct ?>%"></div></div>
          <div class="small text-body-secondary mt-2" id="quotaText">Využité <?= format_bytes($used) ?> z <?= format_bytes($quota) ?></div>
        <?php else: ?>
          <div class="small text-body-secondary" id="quotaText">Využité <?= format_bytes($used) ?> (bez limitu)</div>
        <?php endif; ?>
        <div class="small text-body-secondary opacity-50 mt-2">verzia <?= e(APP_VERSION) ?></div>
      </div>
    </div>
  </aside>
  <main class="app-main">
    <?= flashes() ?>
