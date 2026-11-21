<?php
/** @var array $user  @var string $active  @var string $title */
$used  = used_space((int)$user['id']);
$quota = user_quota($user);
$pct   = $quota > 0 ? min(100, round($used / $quota * 100, 1)) : 0;
$barCls = $pct >= 90 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-primary');
$sharedCount = 0;
try { $sharedCount = (int)q('SELECT COUNT(*) FROM ' . P . 'user_shares WHERE target_id = ?', [$user['id']])->fetchColumn(); } catch (Throwable $e) {}
// menu po skupinách: [nadpis skupiny alebo null, položky, zbaliteľná?]
$navGroups = [
    [null, [
        ['drive',   'index.php',         'bi-hdd-stack',     'Môj disk'],
        ['recent',  'index.php#recent',  'bi-clock-history', 'Nedávne'],
        ['starred', 'index.php#starred', 'bi-star',          'S hviezdičkou'],
        ['photos',  'index.php#photos',  'bi-images',        'Fotky'],
        ['activity', 'index.php#activity', 'bi-activity',    'Aktivita'],
    ], false],
    ['Zdieľanie', [
        ['shared',  'index.php#shared',  'bi-people',        'Zdieľané so mnou'],
        ['byme',    'index.php#byme',    'bi-share',         'Zdieľané mnou'],
    ], false],
    [null, [
        ['dupes',   'index.php#dupes',   'bi-files',         'Duplicitné súbory'],
        ['trash',   'index.php#trash',   'bi-trash3',        'Kôš'],
    ], false],
];
$more = [['profile', 'profile.php', 'bi-person-gear', 'Môj účet']];
if ($user['role'] === 'admin') $more[] = ['admin', 'admin.php', 'bi-shield-lock', 'Administrácia'];
$inApp = is_android_app();
if ($inApp) { // položky natívnej Android aplikácie
    $more[] = ['app-sync', 'cloudapp://sync', 'bi-arrow-repeat', 'Synchronizácia telefónu'];
    $more[] = ['app-background', 'cloudapp://background', 'bi-shield-check', 'Beh na pozadí'];
    $more[] = ['app-server', 'cloudapp://server', 'bi-hdd-network', 'Server'];
    $more[] = ['app-update', 'cloudapp://update', 'bi-box-arrow-in-down', 'Aktualizácia aplikácie'];
}
$navGroups[] = ['Nastavenia', $more, true];
$help = [['help', 'help.php', 'bi-book', 'Príručka'], ['tour', 'help.php#sprievodca', 'bi-signpost-split', 'Rýchly sprievodca']];
if ($user['role'] === 'admin') $help[] = ['devhelp', 'help.php?doc=dev', 'bi-code-slash', 'Vývojárska príručka'];
$navGroups[] = ['Pomoc', $help, true];
require __DIR__ . '/head.php';
?>
<body class="app">
<header class="app-topbar">
  <button class="btn btn-icon d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
  <a href="<?= e(url('index.php')) ?>" class="brand"><span class="brand-logo"><i class="bi bi-cloud-fill"></i></span><span class="d-none d-sm-inline"><?= e(cfg('app_name')) ?></span></a>
  <?php if (($active ?? '') !== 'admin' && ($active ?? '') !== 'profile'): ?>
  <form class="search-box" id="searchForm" role="search">
    <i class="bi bi-search search-ic"></i>
    <input type="text" id="searchInput" class="form-control" placeholder="Hľadať v disku" autocomplete="off" enterkeyhint="search" aria-label="Hľadať v disku">
    <button type="button" class="btn btn-icon btn-sm search-clear d-none" id="searchClear" aria-label="Vymazať"><i class="bi bi-x-lg"></i></button>
    <button type="button" class="btn btn-icon btn-sm search-filter" id="searchFilterBtn" title="Rozšírené vyhľadávanie" aria-label="Rozšírené vyhľadávanie"><i class="bi bi-sliders2"></i></button>
    <div class="search-pop shadow d-none" id="searchPop"></div>
    <div class="search-adv shadow d-none" id="searchAdv"></div>
  </form>
  <?php else: ?><div class="flex-grow-1"></div><?php endif; ?>
  <div class="dropdown">
    <button class="btn btn-icon position-relative" id="notifBell" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Upozornenia" title="Upozornenia">
      <i class="bi bi-bell"></i><?php $unr = notifications_unread((int)$user['id']); ?><span class="notif-badge<?= $unr ? '' : ' d-none' ?>" id="notifBadge"><?= $unr > 99 ? '99+' : $unr ?></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end shadow notif-menu p-0">
      <div class="d-flex align-items-center px-3 py-2 border-bottom">
        <span class="fw-semibold me-auto">Upozornenia</span>
        <button class="btn btn-link btn-sm text-decoration-none p-0" id="notifReadAll">Označiť ako prečítané</button>
      </div>
      <div id="notifList" class="notif-list"><div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div></div>
    </div>
  </div>
  <button class="btn btn-icon" id="themeToggle" title="Prepnúť tmavý režim"><i class="bi bi-moon-stars"></i></button>
  <div class="dropdown">
    <button class="btn avatar-btn" data-bs-toggle="dropdown" aria-label="Účet">
      <?= avatar_html($user) ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow" id="accountMenu">
      <li class="px-3 py-2 d-flex align-items-center gap-2"><?= avatar_html($user, 'avatar-md') ?><div class="min-w-0"><div class="fw-semibold text-truncate"><?= e($user['name']) ?></div><div class="small text-body-secondary text-truncate"><?= e($user['email']) ?></div></div></li>
      <?php if (!$inApp): $others = saved_accounts((int)$user['id']); ?>
      <li><hr class="dropdown-divider"></li>
      <?php if ($others): ?><li><h6 class="dropdown-header">Prepnúť účet</h6></li><?php endif; ?>
      <?php foreach ($others as $acc): ?>
      <li><form method="post" action="<?= e(url('switch.php')) ?>" class="acc-switch"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int)$acc['id'] ?>">
        <button class="dropdown-item d-flex align-items-center gap-2" title="Prepnúť na <?= e($acc['email']) ?>"><?= avatar_html($acc, 'avatar-sm') ?><span class="min-w-0"><span class="d-block text-truncate"><?= e($acc['name']) ?></span><span class="d-block small text-body-secondary text-truncate"><?= e($acc['email']) ?></span></span></button></form></li>
      <?php endforeach; ?>
      <li><form method="post" action="<?= e(url('switch.php')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="add"><button class="dropdown-item"><i class="bi bi-person-plus me-2"></i>Pridať ďalší účet</button></form></li>
      <?php else: /* v aplikácii rovnaké menu – účty drží aplikácia a doplní ich sem */ ?>
      <li id="appAccHr"><hr class="dropdown-divider"></li>
      <li id="appAccountsSlot" class="d-none"><h6 class="dropdown-header">Prepnúť účet</h6></li>
      <li><a class="dropdown-item" href="cloudapp://account-add"><i class="bi bi-person-plus me-2"></i>Pridať ďalší účet</a></li>
      <?php endif; ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="bi bi-person-gear me-2"></i>Môj účet</a></li>
      <?php if (!$inApp): ?><li class="d-none d-lg-block kbd-menu"><a class="dropdown-item" href="#" onclick="if (window.cloudShortcuts) cloudShortcuts(); return false;"><i class="bi bi-keyboard me-2"></i>Klávesové skratky <span class="kbd-hint">?</span></a></li><?php endif; ?>
      <?php if ($user['role'] === 'admin'): ?><li><a class="dropdown-item" href="<?= e(url('admin.php')) ?>"><i class="bi bi-shield-lock me-2"></i>Administrácia</a></li><?php endif; ?>
      <li><hr class="dropdown-divider"></li>
      <?php if ($inApp): ?>
      <li><a class="dropdown-item text-danger" href="cloudapp://logout"><i class="bi bi-box-arrow-right me-2"></i>Odhlásiť sa</a></li>
      <li class="d-none" id="appLogoutAll"><a class="dropdown-item text-danger" href="cloudapp://logout-all"><i class="bi bi-door-open me-2"></i>Odhlásiť všetky účty</a></li>
      <?php else: ?>
      <li><form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Odhlásiť sa</button></form></li>
      <?php if (!empty($others)): ?><li><form method="post" action="<?= e(url('switch.php')) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="logout_all"><button class="dropdown-item text-danger"><i class="bi bi-door-open me-2"></i>Odhlásiť všetky účty</button></form></li><?php endif; ?>
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
          <li><a class="dropdown-item" href="#" data-action="new-sheet"><i class="bi bi-file-earmark-spreadsheet me-2 ic-sheet"></i>Nová tabuľka</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="#" data-action="upload-files"><i class="bi bi-file-earmark-arrow-up me-2"></i>Nahrať súbory</a></li>
          <li><a class="dropdown-item" href="#" data-action="upload-folder"><i class="bi bi-folder-symlink me-2"></i>Nahrať zložku</a></li>
        </ul>
      </div>
      <?php else: ?>
      <a href="<?= e(url('index.php')) ?>" class="btn btn-new shadow-sm mb-3"><i class="bi bi-arrow-left me-2"></i>Späť na disk</a>
      <?php endif; ?>
      <nav class="nav flex-column side-nav">
        <?php foreach ($navGroups as $gi => [$glabel, $gitems, $collapsible]):
            $openGroup = $collapsible && in_array($active ?? '', array_column($gitems, 0), true); ?>
          <?php if ($collapsible): ?>
            <button class="side-label side-toggle <?= $openGroup ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#sideGroup<?= $gi ?>" aria-expanded="<?= $openGroup ? 'true' : 'false' ?>" data-remember="cloud-side-<?= $gi ?>">
              <span><?= e($glabel) ?></span><i class="bi bi-chevron-down"></i></button>
            <div class="collapse <?= $openGroup ? 'show' : '' ?>" id="sideGroup<?= $gi ?>">
          <?php elseif ($glabel): ?>
            <div class="side-label"><?= e($glabel) ?></div>
          <?php elseif ($gi > 0): ?>
            <div class="side-sep"></div>
          <?php endif; ?>
          <?php foreach ($gitems as [$key, $href, $icon, $label]): ?>
            <a class="nav-link <?= ($active ?? '') === $key ? 'active' : '' ?>" href="<?= e(str_starts_with($href, 'cloudapp:') ? $href : url($href)) ?>" data-nav="<?= e($key) ?>"><i class="bi <?= $icon ?>"></i><?= e($label) ?><?= $key === 'shared' && $sharedCount ? '<span class="badge rounded-pill text-bg-light ms-auto">' . $sharedCount . '</span>' : '' ?></a>
          <?php endforeach; ?>
          <?php if ($collapsible): ?></div><?php endif; ?>
        <?php endforeach; ?>
      </nav>
      <?php if (($active ?? '') === 'drive'): ?>
      <div class="side-tags mt-3">
        <div class="d-flex align-items-center px-3 mb-1">
          <span class="small fw-semibold text-body-secondary me-auto">Štítky</span>
          <button class="btn btn-icon btn-sm" id="tagManageBtn" title="Spravovať štítky"><i class="bi bi-gear"></i></button>
        </div>
        <nav class="nav flex-column side-nav" id="sideTags"></nav>
      </div>
      <?php endif; ?>
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
