<?php
/* Verejný odkaz na zdieľaný súbor alebo zložku (bez prihlásenia) */
require __DIR__ . '/inc/core.php';
header('X-Robots-Tag: noindex, nofollow');

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
$share = $token ? q('SELECT s.*, u.name owner_name, u.is_active FROM ' . P . 'shares s JOIN ' . P . 'users u ON u.id = s.user_id WHERE s.token = ?', [$token])->fetch() : null;

function share_fail(string $msg): never {
    http_response_code(404);
    $title = 'Odkaz nie je dostupný'; $heading = 'Odkaz nie je dostupný'; $subheading = $msg;
    require __DIR__ . '/inc/views/auth_start.php';
    echo '<div class="text-center"><i class="bi bi-link-45deg display-3 text-body-secondary"></i></div>';
    require __DIR__ . '/inc/views/auth_end.php';
    exit;
}
if (!$share || !$share['is_active']) share_fail('Odkaz neexistuje alebo bol zrušený.');
if ($share['expires_at'] && strtotime($share['expires_at']) < time()) share_fail('Platnosť odkazu vypršala.');

$owner = (int)$share['user_id'];
$root = $share['item_type'] === 'd'
    ? q('SELECT * FROM ' . P . 'folders WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$share['item_id'], $owner])->fetch()
    : q('SELECT * FROM ' . P . 'files WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$share['item_id'], $owner])->fetch();
if (!$root) share_fail('Zdieľaná položka už neexistuje.');

/* ----- heslo ----- */
if ($share['password'] && empty($_SESSION['share_ok'][$token])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (too_many_attempts()) flash('danger', 'Príliš veľa pokusov. Skús to neskôr.');
        elseif (password_verify((string)($_POST['password'] ?? ''), $share['password'])) { $_SESSION['share_ok'][$token] = true; header('Location: ' . url('s.php?t=' . $token)); exit; }
        else { record_failed_attempt('share:' . $token); flash('danger', 'Nesprávne heslo.'); }
        header('Location: ' . url('s.php?t=' . $token)); exit;
    }
    $title = 'Chránený odkaz'; $heading = $root['name']; $subheading = 'Zdieľa ' . $share['owner_name'] . ' · chránené heslom';
    require __DIR__ . '/inc/views/auth_start.php'; ?>
    <form method="post"><?= csrf_field() ?>
      <div class="form-floating mb-3"><input type="password" class="form-control" id="pw" name="password" placeholder="Heslo" required autofocus><label for="pw">Heslo k odkazu</label></div>
      <button class="btn btn-primary btn-lg w-100 rounded-pill"><i class="bi bi-unlock me-2"></i>Otvoriť</button>
    </form>
    <?php require __DIR__ . '/inc/views/auth_end.php';
    exit;
}

/* ----- kontrola, či súbor/zložka patrí do zdieľaného stromu ----- */
$tree = $share['item_type'] === 'd' ? descendants($owner, (int)$root['id']) : [];
function shared_file(int $id): array {
    global $share, $root, $tree, $owner;
    if ($share['item_type'] === 'f') { if ($id !== (int)$root['id']) share_fail('Súbor nie je súčasťou odkazu.'); return $root; }
    $f = q('SELECT * FROM ' . P . 'files WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$id, $owner])->fetch();
    if (!$f || !in_array((int)$f['folder_id'], $tree, true)) share_fail('Súbor nie je súčasťou odkazu.');
    return $f;
}
$curId = (int)($_GET['f'] ?? 0) ?: ($share['item_type'] === 'd' ? (int)$root['id'] : 0);
if ($share['item_type'] === 'd' && !in_array($curId, $tree, true)) $curId = (int)$root['id'];

/* ----- sťahovanie ----- */
if (isset($_GET['dl']) || isset($_GET['thumb']) || isset($_GET['zip'])) {
    session_write_close();
    if (isset($_GET['thumb'])) send_thumb(shared_file((int)$_GET['thumb']));
    q('UPDATE ' . P . 'shares SET downloads = downloads + 1 WHERE id = ?', [$share['id']]);
    if (isset($_GET['zip'])) {
        if ($share['item_type'] !== 'd') share_fail('Neplatná požiadavka.');
        $d = q('SELECT * FROM ' . P . 'folders WHERE id = ?', [$curId])->fetch();
        send_zip([$d], [], $d['name']);
    }
    $f = shared_file((int)$_GET['dl']);
    send_file(storage_dir($owner) . '/' . $f['stored_name'], $f['name'], $f['mime'], !empty($_GET['inline']) && safe_inline($f['mime']));
}

/* ----- stránka ----- */
$base = 's.php?t=' . $token;
$crumbs = []; $folders = []; $files = [];
if ($share['item_type'] === 'd') {
    $id = $curId; $g = 0;
    while ($id && $g++ < 100) {
        $r = q('SELECT id, name, parent_id FROM ' . P . 'folders WHERE id = ?', [$id])->fetch();
        array_unshift($crumbs, $r);
        if ((int)$r['id'] === (int)$root['id']) break;
        $id = (int)$r['parent_id'];
    }
    $folders = q('SELECT * FROM ' . P . 'folders WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL ORDER BY name', [$owner, $curId])->fetchAll();
    $files = q('SELECT * FROM ' . P . 'files WHERE user_id = ? AND folder_id = ? AND deleted_at IS NULL ORDER BY name', [$owner, $curId])->fetchAll();
}
function ficon(string $mime, string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match (true) {
        str_starts_with($mime, 'image/') => 'bi-file-earmark-image-fill ic-image', str_starts_with($mime, 'video/') => 'bi-file-earmark-play-fill ic-video',
        str_starts_with($mime, 'audio/') => 'bi-file-earmark-music-fill ic-audio', $mime === 'application/pdf' => 'bi-file-earmark-pdf-fill ic-pdf',
        in_array($ext, ['doc', 'docx', 'odt']) => 'bi-file-earmark-word-fill ic-word', in_array($ext, ['xls', 'xlsx', 'ods', 'csv']) => 'bi-file-earmark-excel-fill ic-excel',
        in_array($ext, ['zip', 'rar', '7z', 'gz']) => 'bi-file-earmark-zip-fill ic-zip', default => 'bi-file-earmark-fill ic-file',
    };
}
$title = $root['name'];
require __DIR__ . '/inc/views/head.php';
?>
<body class="share-page">
<header class="app-topbar">
  <span class="brand"><span class="brand-logo"><i class="bi bi-cloud-fill"></i></span><span class="d-none d-sm-inline"><?= e(cfg('app_name')) ?></span></span>
  <div class="flex-grow-1"></div>
  <?php if ($share['item_type'] === 'd'): ?>
    <a class="btn btn-primary rounded-pill" href="<?= e(url($base . '&zip=1&f=' . $curId)) ?>"><i class="bi bi-file-earmark-zip me-1"></i>Stiahnuť všetko</a>
  <?php else: ?>
    <a class="btn btn-primary rounded-pill" href="<?= e(url($base . '&dl=' . $root['id'])) ?>"><i class="bi bi-download me-1"></i>Stiahnuť</a>
  <?php endif; ?>
</header>
<main class="share-main">
  <div class="small text-body-secondary mb-1"><i class="bi bi-person-circle me-1"></i>Zdieľa <?= e($share['owner_name']) ?><?= $share['expires_at'] ? ' · platí do ' . date('d.m.Y', strtotime($share['expires_at'])) : '' ?></div>

<?php if ($share['item_type'] === 'f'): $m = $root['mime']; $u = e(url($base . '&dl=' . $root['id'] . '&inline=1')); ?>
  <h1 class="h4 fw-bold text-break mb-3"><?= e($root['name']) ?> <span class="fs-6 fw-normal text-body-secondary"><?= format_bytes($root['size']) ?></span></h1>
  <div class="share-preview card-soft">
    <?php if (preg_match('#^image/(jpeg|png|gif|webp|bmp)#', $m)): ?><img src="<?= $u ?>" alt="">
    <?php elseif (str_starts_with($m, 'video/')): ?><video src="<?= $u ?>" controls playsinline></video>
    <?php elseif (str_starts_with($m, 'audio/')): ?><audio src="<?= $u ?>" controls></audio>
    <?php elseif ($m === 'application/pdf'): ?><iframe src="<?= $u ?>" title="PDF"></iframe>
    <?php else: ?>
      <div class="text-center py-5"><i class="bi <?= ficon($m, $root['name']) ?>" style="font-size:5rem"></i>
        <p class="text-body-secondary mt-2">Náhľad nie je dostupný</p>
        <a class="btn btn-primary rounded-pill px-4" href="<?= e(url($base . '&dl=' . $root['id'])) ?>"><i class="bi bi-download me-2"></i>Stiahnuť</a></div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <nav><ol class="breadcrumb drive-crumbs mb-3">
    <?php foreach ($crumbs as $i => $c): $last = $i === count($crumbs) - 1; ?>
      <li class="breadcrumb-item <?= $last ? 'active' : '' ?>"><?= $last ? e($c['name']) : '<a href="' . e(url($base . '&f=' . $c['id'])) . '">' . e($c['name']) . '</a>' ?></li>
    <?php endforeach; ?>
  </ol></nav>
  <div class="card-soft">
    <?php foreach ($folders as $d): ?>
      <a class="share-row" href="<?= e(url($base . '&f=' . $d['id'])) ?>"><i class="bi bi-folder-fill ic-folder"></i><span class="text-truncate flex-grow-1"><?= e($d['name']) ?></span><i class="bi bi-chevron-right text-body-secondary"></i></a>
    <?php endforeach; ?>
    <?php foreach ($files as $f): $inl = safe_inline($f['mime']); ?>
      <div class="share-row">
        <?php if (is_thumbable($f['mime'])): ?><img class="share-thumb" loading="lazy" src="<?= e(url($base . '&thumb=' . $f['id'])) ?>" alt=""><?php else: ?><i class="bi <?= ficon($f['mime'], $f['name']) ?>"></i><?php endif; ?>
        <span class="text-truncate flex-grow-1"><?= e($f['name']) ?><span class="d-block small text-body-secondary"><?= format_bytes($f['size']) ?></span></span>
        <?php if ($inl): ?><a class="btn btn-icon" href="<?= e(url($base . '&dl=' . $f['id'] . '&inline=1')) ?>" target="_blank" title="Zobraziť"><i class="bi bi-eye"></i></a><?php endif; ?>
        <a class="btn btn-icon" href="<?= e(url($base . '&dl=' . $f['id'])) ?>" title="Stiahnuť"><i class="bi bi-download"></i></a>
      </div>
    <?php endforeach; ?>
    <?php if (!$folders && !$files): ?><div class="text-center text-body-secondary py-5">Zložka je prázdna</div><?php endif; ?>
  </div>
<?php endif; ?>
</main>
<?php require __DIR__ . '/inc/views/scripts.php'; ?>
</body>
</html>
