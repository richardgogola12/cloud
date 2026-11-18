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

$mode = $share['item_type'] === 'd' ? ($share['mode'] ?? 'view') : 'view';
$canView = $mode !== 'upload';
$canUpload = in_array($mode, ['upload', 'both'], true);

/* ----- nahrávanie cez odkaz (po častiach, ako v aplikácii) ----- */
if (isset($_GET['up']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canUpload) throw new RuntimeException('Tento odkaz neumožňuje nahrávanie.');
        csrf_check();
        $step = (string)$_GET['up'];
        if ($step === 'init') {
            $name = clean_name((string)($_POST['name'] ?? ''));
            $size = max(0, (int)($_POST['size'] ?? 0));
            $folder = $mode === 'both' ? (int)($_POST['f'] ?? 0) : (int)$root['id'];
            if (!in_array($folder, $tree, true)) $folder = (int)$root['id'];
            $guest = trim((string)($_POST['guest'] ?? ''));
            if ($guest !== '' && $mode === 'upload') $folder = (int)ensure_path($owner, $folder, clean_name(mb_substr($guest, 0, 60)));
            $maxMb = (int)setting('max_upload_mb', '0');
            if ($maxMb > 0 && $size > $maxMb * 1048576) throw new RuntimeException("Maximálna veľkosť súboru je $maxMb MB.");
            $ou = q('SELECT quota FROM ' . P . 'users WHERE id = ?', [$owner])->fetch();
            if ($ou && (int)$ou['quota'] > 0 && used_space($owner) + $size > (int)$ou['quota']) throw new RuntimeException('Úložisko príjemcu je plné.');
            $id = bin2hex(random_bytes(16));
            $dir = data_path('tmp/chunks');
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            touch($dir . '/' . $id);
            q('INSERT INTO ' . P . 'uploads (id, user_id, owner_id, folder_id, relpath, name, size) VALUES (?,?,?,?,?,?,?)', [$id, $owner, $owner, $folder, 'share:' . $token, $name, $size]);
            json_out(['ok' => true, 'id' => $id, 'chunk' => chunk_size()]);
        }
        if ($step === 'chunk') {
            $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
            $up = q('SELECT * FROM ' . P . 'uploads WHERE id = ? AND owner_id = ? AND relpath = ?', [$id, $owner, 'share:' . $token])->fetch();
            if (!$up) throw new RuntimeException('Nahrávanie vypršalo. Skús to znova.');
            $path = data_path('tmp/chunks/' . $id);
            $have = is_file($path) ? filesize($path) : 0;
            if ((int)($_POST['offset'] ?? -1) !== $have) json_out(['ok' => true, 'received' => $have, 'resync' => true]);
            $chunk = $_FILES['chunk'] ?? null;
            if ((int)$up['size'] > 0) {
                if (!$chunk || $chunk['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Časť súboru sa nepreniesla.');
                if ($have + $chunk['size'] > $up['size']) throw new RuntimeException('Neplatná veľkosť dát.');
                $in = fopen($chunk['tmp_name'], 'rb'); $out = fopen($path, 'ab');
                if (!$in || !$out || !flock($out, LOCK_EX)) throw new RuntimeException('Nepodarilo sa zapísať dáta.');
                stream_copy_to_stream($in, $out); fflush($out); flock($out, LOCK_UN); fclose($out); fclose($in);
            }
            clearstatcache(true, $path);
            $have = filesize($path);
            q('UPDATE ' . P . 'uploads SET received = ? WHERE id = ?', [$have, $id]);
            if ($have < $up['size']) json_out(['ok' => true, 'received' => $have]);
            $folder = $up['folder_id'] ? (int)$up['folder_id'] : null;
            $r = store_file($owner, $folder, free_name($owner, $folder, $up['name']), $path, false);
            q('DELETE FROM ' . P . 'uploads WHERE id = ?', [$id]);
            q('UPDATE ' . P . 'shares SET uploads = uploads + 1 WHERE id = ?', [$share['id']]);
            $_SESSION['share_up'][$token][] = $r['name'];
            json_out(['ok' => true, 'done' => true, 'received' => $have, 'name' => $r['name']]);
        }
        if ($step === 'done') { // jedno upozornenie vlastníkovi za celú dávku
            $names = $_SESSION['share_up'][$token] ?? [];
            unset($_SESSION['share_up'][$token]);
            if ($names) {
                $guest = trim((string)($_POST['guest'] ?? ''));
                $who = $guest !== '' ? mb_substr($guest, 0, 60) : 'Niekto';
                $cnt = count($names);
                $txt = $who . ' nahral(a) ' . $cnt . ' ' . ($cnt === 1 ? 'súbor' : ($cnt < 5 ? 'súbory' : 'súborov')) . ' do „' . $root['name'] . '“: ' . implode(', ', array_slice($names, 0, 5)) . ($cnt > 5 ? '…' : '');
                notify($owner, 'upload', 'Nové súbory cez odkaz', $txt, 'index.php#folder/' . (int)$root['id']);
                log_action($owner, 'share_upload', $txt);
            }
            json_out(['ok' => true]);
        }
        throw new RuntimeException('Neplatná požiadavka.');
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/* ----- sťahovanie ----- */
if (!$canView && (isset($_GET['dl']) || isset($_GET['thumb']) || isset($_GET['zip']))) share_fail('Tento odkaz slúži iba na nahrávanie súborov.');
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
    send_stored($f, $f['name'], !empty($_GET['inline']) && safe_inline($f['mime']));
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
  <?php if (!$canView): ?>
  <?php elseif ($share['item_type'] === 'd'): ?>
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
<?php elseif (!$canView): ?>
  <h1 class="h4 fw-bold text-break mb-1"><i class="bi bi-cloud-arrow-up me-2 text-primary"></i>Nahraj súbory do „<?= e($root['name']) ?>“</h1>
  <p class="text-body-secondary mb-3">Súbory uvidí iba <?= e($share['owner_name']) ?>. Ostatné súbory v zložke nie sú viditeľné.</p>
  <?php require __DIR__ . '/inc/views/share_upload.php'; ?>
<?php else: ?>
  <?php if ($canUpload) require __DIR__ . '/inc/views/share_upload.php'; ?>
  <nav><ol class="breadcrumb drive-crumbs mb-3">
    <?php foreach ($crumbs as $i => $c): $last = $i === count($crumbs) - 1; ?>
      <li class="breadcrumb-item <?= $last ? 'active' : '' ?>"><?= $last ? '<span class="crumb-text" title="' . e($c['name']) . '">' . e($c['name']) . '</span>' : '<a href="' . e(url($base . '&f=' . $c['id'])) . '"><span class="crumb-text" title="' . e($c['name']) . '">' . e($c['name']) . '</span></a>' ?></li>
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
