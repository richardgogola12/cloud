<?php
require __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/mailer.php';
$user = require_admin();
require_once __DIR__ . '/inc/updater.php';
require_once __DIR__ . '/inc/maintenance.php';

/* ---------------- AJAX: údržba (dávkové spracovanie) ---------------- */
if (isset($_GET['ajax'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Metóda nie je povolená'], 405);
    csrf_check();
    session_write_close();
    @set_time_limit(180);
    try {
        switch ($_GET['ajax']) {
            case 'encrypt':
                if (!crypto_enabled()) throw new RuntimeException('Šifrovanie je vypnuté.');
                $r = maint_encrypt_batch();
                if ($r['done']) log_action((int)$user['id'], 'encrypt_batch', $r['done'] . ' súborov');
                json_out(['ok' => true] + $r);
            case 'check':
                json_out(['ok' => true] + maint_check_batch(($_POST['mode'] ?? '') === 'deep' ? 'deep' : 'quick', (int)($_POST['after'] ?? 0)));
            case 'check_done':
                $n = (int)($_POST['problems'] ?? 0); $checked = (int)($_POST['checked'] ?? 0);
                log_action((int)$user['id'], 'integrity_check', ($_POST['mode'] ?? '') . ": $checked kontrolovaných, $n problémov");
                if ($n > 0) notify_admins('integrity', "Kontrola integrity: $n problémov", "Skontrolovaných $checked súborov.", 'admin.php?tab=maintenance');
                setting_set('last_integrity', date('Y-m-d H:i') . " · $checked súborov · $n problémov");
                json_out(['ok' => true]);
            case 'orphans':
                $o = maint_orphans();
                json_out(['ok' => true, 'count' => count($o['items']), 'bytes' => format_bytes($o['bytes']), 'items' => array_slice($o['items'], 0, 50)]);
            case 'orphans_delete':
                $o = maint_orphans(); $n = 0;
                foreach ($o['items'] as $i) if (@unlink(data_path('files/' . $i['path']))) $n++;
                log_action((int)$user['id'], 'orphans_delete', "$n súborov, " . format_bytes($o['bytes']));
                json_out(['ok' => true, 'deleted' => $n]);
            case 'remove_missing': // odstráni z DB záznamy, ktorých dáta na disku chýbajú
                $n = 0;
                foreach ((array)($_POST['items'] ?? []) as $it) {
                    if (!preg_match('/^([fv]):(\d+)$/', (string)$it, $m)) continue;
                    $row = q('SELECT * FROM ' . ($m[1] === 'f' ? P . 'files' : P . 'file_versions') . ' WHERE id = ?', [(int)$m[2]])->fetch();
                    if (!$row || is_file(stored_path($row))) continue; // iba skutočne chýbajúce
                    if ($m[1] === 'f') purge_file($row); else q('DELETE FROM ' . P . 'file_versions WHERE id = ?', [$row['id']]);
                    $n++;
                }
                log_action((int)$user['id'], 'remove_missing', "$n záznamov");
                json_out(['ok' => true, 'removed' => $n]);
        }
        json_out(['ok' => false, 'error' => 'Neznáma akcia'], 400);
    } catch (Throwable $ex) {
        json_out(['ok' => false, 'error' => $ex instanceof PDOException ? 'Chyba databázy.' : $ex->getMessage()], 400);
    }
}
$me = (int)$user['id'];
$U = P . 'users';

function quota_from_post(): int {
    if (!empty($_POST['unlimited'])) return 0;
    $val = max(0, (float)str_replace(',', '.', (string)($_POST['quota_val'] ?? '0')));
    $mult = ['MB' => 1048576, 'GB' => 1073741824, 'TB' => 1099511627776][$_POST['quota_unit'] ?? 'GB'] ?? 1073741824;
    return (int)round($val * $mult);
}
function quota_split(int $b): array {
    if ($b === 0) return [5, 'GB'];
    if ($b % 1099511627776 === 0) return [$b / 1099511627776, 'TB'];
    if ($b >= 1073741824) return [round($b / 1073741824, 2), 'GB'];
    return [round($b / 1048576, 2), 'MB'];
}
function rrmdir(string $d): void {
    if (!is_dir($d)) return;
    foreach (array_diff(scandir($d), ['.', '..']) as $f) { $p = "$d/$f"; is_dir($p) ? rrmdir($p) : @unlink($p); }
    @rmdir($d);
}
function admins_count(): int { return (int)q('SELECT COUNT(*) FROM ' . P . "users WHERE role='admin' AND is_active=1")->fetchColumn(); }

/* ---------------- POST akcie ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $tab = $_POST['tab'] ?? 'users';
    try {
        if ($act === 'user_save' || $act === 'user_create') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)$_POST['name']);
            $email = mb_strtolower(trim((string)$_POST['email']));
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $activeFlag = !empty($_POST['is_active']) ? 1 : 0;
            $quota = quota_from_post();
            if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Vyplň meno a platný e-mail.');
            if (q("SELECT id FROM $U WHERE email = ? AND id <> ?", [$email, $id])->fetch()) throw new RuntimeException('E-mail už používa iný účet.');

            if ($act === 'user_create') {
                $pass = (string)($_POST['password'] ?? '');
                if ($pass === '') $pass = substr(b64(random_bytes(12)), 0, 14);
                if (strlen($pass) < 8) throw new RuntimeException('Heslo musí mať aspoň 8 znakov.');
                q("INSERT INTO $U (name, email, password, role, quota, is_active, email_verified) VALUES (?,?,?,?,?,?,1)",
                  [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $quota, $activeFlag]);
                log_action($me, 'admin_user_create', $email);
                if (!empty($_POST['notify'])) {
                    send_mail($email, 'Účet v ' . cfg('app_name'), 'Bol ti vytvorený účet',
                        '<p>Ahoj ' . e($name) . ', administrátor ti vytvoril účet.</p><p>E-mail: <b>' . e($email) . '</b><br>Heslo: <b>' . e($pass) . '</b></p>'
                        . '<p>Po prihlásení si heslo zmeň v nastaveniach účtu. Kvóta: <b>' . ($quota ? format_bytes($quota) : 'bez limitu') . '</b>.</p>',
                        'Prihlásiť sa', cfg('app_url') . '/login.php');
                }
                flash('success', 'Používateľ bol vytvorený.');
            } else {
                $old = q("SELECT * FROM $U WHERE id = ?", [$id])->fetch();
                if (!$old) throw new RuntimeException('Používateľ neexistuje.');
                if ($id === $me && ($role !== 'admin' || !$activeFlag)) throw new RuntimeException('Nemôžeš si odobrať admin práva ani deaktivovať vlastný účet.');
                q("UPDATE $U SET quota_warned = IF(quota = ?, quota_warned, 0), name=?, email=?, role=?, quota=?, is_active=? WHERE id=?",
                  [$quota, $name, $email, $role, $quota, $activeFlag, $id]);
                if (!empty($_POST['password'])) {
                    if (strlen($_POST['password']) < 8) throw new RuntimeException('Heslo musí mať aspoň 8 znakov.');
                    q("UPDATE $U SET password = ? WHERE id = ?", [password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $id]);
                }
                if (!$old['is_active'] && $activeFlag) {
                    send_mail($email, 'Účet bol aktivovaný – ' . cfg('app_name'), 'Tvoj účet je aktívny',
                        '<p>Ahoj ' . e($name) . ', administrátor schválil tvoj účet. Môžeš sa prihlásiť.</p>', 'Prihlásiť sa', cfg('app_url') . '/login.php');
                }
                if ((int)$old['quota'] !== $quota) {
                    notify($id, 'quota', 'Kvóta úložiska bola zmenená', 'Nová kvóta: ' . ($quota ? format_bytes($quota) : 'bez limitu'));
                    send_mail($email, 'Zmena úložiska – ' . cfg('app_name'), 'Kvóta úložiska bola zmenená',
                        '<p>Ahoj ' . e($name) . ', tvoja kvóta úložiska je teraz <b>' . ($quota ? format_bytes($quota) : 'bez limitu') . '</b>.</p>');
                }
                log_action($me, 'admin_user_save', $email . ' kvóta=' . format_bytes($quota));
                flash('success', 'Zmeny boli uložené.');
            }
        } elseif ($act === 'user_toggle') {
            $id = (int)$_POST['id'];
            if ($id === $me) throw new RuntimeException('Nemôžeš deaktivovať vlastný účet.');
            $u = q("SELECT * FROM $U WHERE id = ?", [$id])->fetch();
            q("UPDATE $U SET is_active = 1 - is_active WHERE id = ?", [$id]);
            if ($u && !$u['is_active']) {
                send_mail($u['email'], 'Účet bol aktivovaný – ' . cfg('app_name'), 'Tvoj účet je aktívny',
                    '<p>Ahoj ' . e($u['name']) . ', administrátor schválil tvoj účet. Môžeš sa prihlásiť.</p>', 'Prihlásiť sa', cfg('app_url') . '/login.php');
            }
            log_action($me, 'admin_user_toggle', $u['email'] ?? '');
            flash('success', $u && $u['is_active'] ? 'Účet bol deaktivovaný.' : 'Účet bol aktivovaný.');
        } elseif ($act === 'user_reset') {
            $u = q("SELECT * FROM $U WHERE id = ?", [(int)$_POST['id']])->fetch();
            if (!$u) throw new RuntimeException('Používateľ neexistuje.');
            $token = bin2hex(random_bytes(32));
            q('INSERT INTO ' . P . 'password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 24 HOUR)', [$u['id'], hash('sha256', $token)]);
            $ok = send_mail($u['email'], 'Nastavenie hesla – ' . cfg('app_name'), 'Nastav si nové heslo',
                '<p>Ahoj ' . e($u['name']) . ', administrátor ti poslal odkaz na nastavenie nového hesla. Platí 24 hodín.</p>',
                'Nastaviť heslo', cfg('app_url') . '/reset.php?token=' . $token, $err);
            $ok ? flash('success', 'Odkaz na obnovu hesla bol odoslaný na ' . $u['email'] . '.') : flash('danger', 'E-mail sa nepodarilo odoslať: ' . $err);
        } elseif ($act === 'user_delete') {
            $id = (int)$_POST['id'];
            if ($id === $me) throw new RuntimeException('Nemôžeš zmazať vlastný účet.');
            $u = q("SELECT * FROM $U WHERE id = ?", [$id])->fetch();
            if ($u) {
                rrmdir(rtrim(cfg('storage_path'), '/') . '/files/' . $id);
                rrmdir(rtrim(cfg('storage_path'), '/') . '/thumbs/' . $id);
                q("DELETE FROM $U WHERE id = ?", [$id]);
                log_action($me, 'admin_user_delete', $u['email']);
                flash('success', 'Používateľ ' . $u['email'] . ' a všetky jeho súbory boli zmazané.');
            }
        } elseif ($act === 'user_verify') {
            q("UPDATE $U SET email_verified = 1 WHERE id = ?", [(int)$_POST['id']]);
            flash('success', 'E-mail bol označený ako overený.');
        } elseif ($act === 'user_logout') {
            $id = (int)$_POST['id'];
            q('UPDATE ' . P . 'sessions SET revoked = 1 WHERE user_id = ?' . ($id === $me ? ' AND id <> ' . (int)($_SESSION['sid_row'] ?? 0) : ''), [$id]);
            q('UPDATE ' . P . 'app_tokens SET revoked = 1 WHERE user_id = ?', [$id]);
            log_action($me, 'admin_user_logout', (string)$id);
            flash('success', 'Používateľ bol odhlásený zo všetkých zariadení.');
        } elseif ($act === 'bulk') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if (!$ids) throw new RuntimeException('Nevybral si žiadnych používateľov.');
            $op = $_POST['bulk_op'] ?? '';
            $in = implode(',', $ids);
            $others = implode(',', array_diff($ids, [$me])) ?: '0';
            if ($op === 'quota') {
                $quota = quota_from_post();
                $rows = q("SELECT * FROM $U WHERE id IN ($in) AND quota <> ?", [$quota])->fetchAll();
                q("UPDATE $U SET quota = ?, quota_warned = 0 WHERE id IN ($in)", [$quota]);
                foreach ($rows as $r) notify((int)$r['id'], 'quota', 'Kvóta úložiska bola zmenená', 'Nová kvóta: ' . ($quota ? format_bytes($quota) : 'bez limitu'));
                if (!empty($_POST['bulk_notify'])) foreach ($rows as $r) {
                    send_mail($r['email'], 'Zmena úložiska – ' . cfg('app_name'), 'Kvóta úložiska bola zmenená',
                        '<p>Ahoj ' . e($r['name']) . ', tvoja kvóta úložiska je teraz <b>' . ($quota ? format_bytes($quota) : 'bez limitu') . '</b>.</p>');
                }
                log_action($me, 'admin_bulk_quota', count($ids) . ' používateľov → ' . ($quota ? format_bytes($quota) : 'bez limitu'));
                flash('success', 'Kvóta bola nastavená pre ' . count($ids) . ' používateľov.');
            } elseif ($op === 'activate') {
                $rows = q("SELECT * FROM $U WHERE id IN ($in) AND is_active = 0")->fetchAll();
                q("UPDATE $U SET is_active = 1 WHERE id IN ($in)");
                foreach ($rows as $r) send_mail($r['email'], 'Účet bol aktivovaný – ' . cfg('app_name'), 'Tvoj účet je aktívny',
                    '<p>Ahoj ' . e($r['name']) . ', administrátor schválil tvoj účet. Môžeš sa prihlásiť.</p>', 'Prihlásiť sa', cfg('app_url') . '/login.php');
                flash('success', 'Aktivovaní: ' . count($rows));
            } elseif ($op === 'deactivate') {
                q("UPDATE $U SET is_active = 0 WHERE id IN ($others)");
                flash('success', 'Používatelia boli deaktivovaní' . (in_array($me, $ids, true) ? ' (okrem teba)' : '') . '.');
            } elseif ($op === 'logout') {
                q('UPDATE ' . P . "sessions SET revoked = 1 WHERE user_id IN ($others)");
                q('UPDATE ' . P . "app_tokens SET revoked = 1 WHERE user_id IN ($others)");
                flash('success', 'Vybraní používatelia boli odhlásení zo všetkých zariadení.');
            } elseif ($op === 'verify') {
                q("UPDATE $U SET email_verified = 1 WHERE id IN ($in)");
                flash('success', 'E-maily boli označené ako overené.');
            }
            log_action($me, 'admin_bulk_' . $op, count($ids) . ' používateľov');
        } elseif ($act === 'download_key') {
            log_action($me, 'download_key');
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="cloud-sifrovaci-kluc-' . date('Y-m-d') . '.txt"');
            echo "HLAVNÝ ŠIFROVACÍ KĽÚČ CLOUDU\r\n============================\r\n\r\n";
            echo "Server: " . cfg('app_url') . "\r\nOdtlačok kľúča: " . crypto_fingerprint() . "\r\nStiahnuté: " . date('d.m.Y H:i') . "\r\n\r\n";
            echo "Kľúč (base64):\r\n" . base64_encode(crypto_master_key()) . "\r\n\r\n";
            echo "Obnova: do config.php pridaj riadok\r\n    'encryption_key' => '" . base64_encode(crypto_master_key()) . "',\r\n\r\n";
            echo "Ulož tento súbor na bezpečné miesto (mimo servera). Bez kľúča sa zašifrované súbory nedajú prečítať.\r\n";
            exit;
        } elseif ($act === 'encryption_setting') {
            setting_set('encrypt_files', !empty($_POST['encrypt_files']) ? '1' : '0');
            if (!empty($_POST['encrypt_files'])) crypto_master_key(); // vytvor kľúč hneď
            log_action($me, 'encryption_setting', setting('encrypt_files'));
            flash('success', 'Nastavenie šifrovania bolo uložené.');
        } elseif ($act === 'update') {
            $want = (string)($_POST['version'] ?? '');
            $rel = null;
            foreach (gh_releases('server-v') as $r) if ($r['version'] === $want) { $rel = $r; break; }
            if (!$rel) throw new RuntimeException('Vydanie ' . $want . ' sa nenašlo.');
            if (version_compare($rel['version'], APP_VERSION, '<=')) throw new RuntimeException('Táto verzia už je nainštalovaná.');
            $changed = updater_install($rel);
            flash('success', 'Aktualizované na verziu ' . $rel['version'] . ' (' . count($changed) . ' súborov). Databáza sa upraví automaticky.');
        } elseif ($act === 'settings') {
            setting_set('registration', in_array($_POST['registration'], ['open', 'approval', 'closed'], true) ? $_POST['registration'] : 'open');
            setting_set('default_quota', (string)quota_from_post());
            setting_set('max_upload_mb', (string)max(0, (int)$_POST['max_upload_mb']));
            setting_set('notify_admin_register', !empty($_POST['notify_admin_register']) ? '1' : '0');
            setting_set('require_email_verify', !empty($_POST['require_email_verify']) ? '1' : '0');
            setting_set('notify_new_device', !empty($_POST['notify_new_device']) ? '1' : '0');
            setting_set('trash_days', (string)max(0, min(3650, (int)($_POST['trash_days'] ?? 30))));
            setting_set('max_versions', (string)max(0, min(100, (int)($_POST['max_versions'] ?? 10))));
            log_action($me, 'admin_settings');
            flash('success', 'Nastavenia boli uložené.');
        } elseif ($act === 'smtp') {
            foreach (['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_from', 'smtp_from_name'] as $k) setting_set($k, trim((string)($_POST[$k] ?? '')));
            if (($_POST['smtp_pass'] ?? '') !== '') setting_set('smtp_pass', (string)$_POST['smtp_pass']);
            log_action($me, 'admin_smtp');
            flash('success', 'SMTP nastavenia boli uložené.');
            if (!empty($_POST['test'])) {
                $err = null;
                $ok = send_mail($user['email'], 'Test SMTP – ' . cfg('app_name'), 'Test e-mailu', '<p>Ak čítaš túto správu, SMTP funguje správne. ✔</p>', null, null, $err);
                $ok ? flash('success', 'Testovací e-mail bol odoslaný na ' . $user['email'] . '.') : flash('danger', 'Test zlyhal: ' . $err);
            }
        }
    } catch (Throwable $ex) {
        flash('danger', $ex instanceof PDOException ? 'Chyba databázy.' : $ex->getMessage());
    }
    redirect('admin.php?tab=' . urlencode($tab));
}

function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', 'Ab'), '='); }

/* ---------------- dáta ---------------- */
$tab = $_GET['tab'] ?? 'dashboard';
$search = trim((string)($_GET['q'] ?? ''));
$filter = $_GET['f'] ?? '';
$stats = [
    'users'   => (int)q("SELECT COUNT(*) FROM $U")->fetchColumn(),
    'pending' => (int)q("SELECT COUNT(*) FROM $U WHERE is_active = 0")->fetchColumn(),
    'files'   => (int)q('SELECT COUNT(*) FROM ' . P . 'files')->fetchColumn(),
    'folders' => (int)q('SELECT COUNT(*) FROM ' . P . 'folders')->fetchColumn(),
    'bytes'   => (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'files')->fetchColumn(),
    'quota'   => (int)q("SELECT COALESCE(SUM(quota),0) FROM $U")->fetchColumn(),
];
$disk = @disk_free_space(cfg('storage_path')) ?: null;
$stats['versions'] = (int)q('SELECT COALESCE(SUM(size),0) FROM ' . P . 'file_versions')->fetchColumn();
$stats['bytes'] += $stats['versions'];
// história využitia (denné snímky) + dnešná živá hodnota
$hist = q('SELECT day, bytes FROM ' . P . 'usage_daily WHERE day >= (CURDATE() - INTERVAL 180 DAY) ORDER BY day')->fetchAll();
$hist = array_values(array_filter($hist, fn($h) => $h['day'] !== date('Y-m-d')));
$hist[] = ['day' => date('Y-m-d'), 'bytes' => $stats['bytes']];
// rozdelenie podľa typu
$byType = q("SELECT CASE
    WHEN deleted_at IS NOT NULL THEN 'Kôš'
    WHEN mime LIKE 'image/%' THEN 'Obrázky'
    WHEN mime LIKE 'video/%' THEN 'Videá'
    WHEN mime LIKE 'audio/%' THEN 'Hudba'
    WHEN mime = 'application/pdf' OR mime LIKE 'text/%' OR mime LIKE '%officedocument%' OR mime LIKE '%msword%' OR mime LIKE '%ms-excel%' OR mime LIKE '%opendocument%' THEN 'Dokumenty'
    WHEN mime LIKE '%zip%' OR mime LIKE '%compressed%' OR mime LIKE '%x-7z%' OR mime LIKE '%x-rar%' OR mime LIKE '%x-tar%' OR mime LIKE '%gzip%' THEN 'Archívy'
    ELSE 'Ostatné' END AS t, SUM(size) b, COUNT(*) c FROM " . P . "files GROUP BY t ORDER BY b DESC")->fetchAll();
if ($stats['versions']) $byType[] = ['t' => 'Staršie verzie', 'b' => $stats['versions'], 'c' => (int)q('SELECT COUNT(*) FROM ' . P . 'file_versions')->fetchColumn()];
usort($byType, fn($a, $b) => $b['b'] <=> $a['b']);
$typeMax = max(1, ...array_map(fn($x) => (int)$x['b'], $byType ?: [['b' => 1]]));

$where = []; $params = [];
if ($search !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filter === 'pending') $where[] = 'u.is_active = 0';
if ($filter === 'admin') $where[] = "u.role = 'admin'";
if ($filter === 'full') $where[] = 'u.quota > 0 AND COALESCE(s.used,0) >= u.quota * 0.9';
$users = q("SELECT u.*, COALESCE(s.used,0) used, COALESCE(s.cnt,0) files,
            (SELECT COUNT(*) FROM " . P . "webauthn w WHERE w.user_id = u.id) passkeys
            FROM $U u LEFT JOIN (SELECT user_id, SUM(size) used, COUNT(*) cnt FROM " . P . "files GROUP BY user_id) s ON s.user_id = u.id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY u.is_active ASC, u.created_at DESC LIMIT 500", $params)->fetchAll();
$top = q("SELECT u.name, u.email, u.quota, COALESCE(SUM(f.size),0) used FROM $U u LEFT JOIN " . P . "files f ON f.user_id = u.id GROUP BY u.id ORDER BY used DESC LIMIT 5")->fetchAll();

$logUser = (int)($_GET['lu'] ?? 0);
$logs = q('SELECT l.*, u.email FROM ' . P . "log l LEFT JOIN $U u ON u.id = l.user_id " . ($logUser ? 'WHERE l.user_id = ?' : '') . ' ORDER BY l.id DESC LIMIT 300', $logUser ? [$logUser] : [])->fetchAll();
$allUsers = q("SELECT id, name, email FROM $U ORDER BY name")->fetchAll();
$smtp = smtp_conf();
[$dqVal, $dqUnit] = quota_split((int)setting('default_quota', cfg('default_quota')));

$title = 'Administrácia';
$active = 'admin';
$scripts = ['assets/js/admin.js'];
if ($tab === 'dashboard' && count($hist) >= 2) $scripts[] = 'assets/js/admin-chart.js';
if ($tab === 'maintenance') $scripts[] = 'assets/js/admin-maint.js';
require __DIR__ . '/inc/views/layout_start.php';
$tabs = ['dashboard' => ['bi-speedometer2', 'Prehľad'], 'users' => ['bi-people', 'Používatelia'], 'settings' => ['bi-sliders', 'Nastavenia'], 'smtp' => ['bi-envelope', 'E-mail (SMTP)'], 'log' => ['bi-journal-text', 'Záznamy'], 'maintenance' => ['bi-shield-check', 'Údržba'], 'update' => ['bi-cloud-arrow-down', 'Aktualizácia']];
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <h1 class="h4 fw-bold mb-0 me-auto"><i class="bi bi-shield-lock me-2 text-primary"></i>Administrácia</h1>
  <button class="btn btn-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#userModal" data-user="new"><i class="bi bi-person-plus me-1"></i><span class="d-none d-sm-inline">Nový používateľ</span></button>
</div>
<ul class="nav nav-pills admin-tabs mb-4 flex-nowrap overflow-auto">
  <?php foreach ($tabs as $k => [$ic, $lbl]): ?>
  <li class="nav-item"><a class="nav-link text-nowrap <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><i class="bi <?= $ic ?> me-1"></i><?= $lbl ?><?= $k === 'users' && $stats['pending'] ? ' <span class="badge text-bg-warning">' . $stats['pending'] . '</span>' : '' ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'dashboard'): ?>
<div class="row g-3 mb-4">
  <?php foreach ([['bi-people', 'Používatelia', $stats['users'], $stats['pending'] ? $stats['pending'] . ' čaká na schválenie' : 'všetci aktívni'],
                  ['bi-files', 'Súbory', number_format($stats['files'], 0, ',', ' '), $stats['folders'] . ' zložiek'],
                  ['bi-hdd', 'Využité miesto', format_bytes($stats['bytes']), 'pridelené ' . format_bytes($stats['quota'])],
                  ['bi-device-hdd', 'Voľné na serveri', $disk ? format_bytes($disk) : '—', 'disk úložiska']] as [$ic, $lbl, $val, $sub]): ?>
  <div class="col-6 col-xl-3"><div class="card card-soft h-100"><div class="card-body">
    <div class="stat-icon mb-2"><i class="bi <?= $ic ?>"></i></div>
    <div class="small text-body-secondary"><?= $lbl ?></div><div class="fs-4 fw-bold"><?= $val ?></div><div class="small text-body-secondary"><?= e($sub) ?></div>
  </div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="card card-soft h-100"><div class="card-body">
    <div class="d-flex align-items-baseline mb-1"><h2 class="h6 fw-bold mb-0 me-auto">Využitie úložiska v čase</h2><span class="small text-body-secondary">posledných 180 dní</span></div>
    <div class="fs-4 fw-bold mb-2"><?= format_bytes($stats['bytes']) ?></div>
    <?php if (count($hist) < 2): ?>
      <div class="text-body-secondary small py-5 text-center"><i class="bi bi-graph-up fs-2 d-block mb-2"></i>Graf sa začne kresliť od zajtra – každý deň sa uloží jeden bod.</div>
    <?php else: ?>
      <div style="position:relative;height:240px"><canvas id="usageChart" aria-label="Graf využitia úložiska v čase" role="img"></canvas></div>
      <details class="small mt-2"><summary class="text-body-secondary">Zobraziť ako tabuľku</summary>
        <table class="table table-sm mt-2 mb-0"><thead><tr><th>Deň</th><th class="text-end">Využité</th></tr></thead><tbody>
        <?php foreach (array_reverse($hist) as $h): ?><tr><td><?= date('d.m.Y', strtotime($h['day'])) ?></td><td class="text-end"><?= format_bytes($h['bytes']) ?></td></tr><?php endforeach; ?>
        </tbody></table></details>
      <script>window.USAGE = <?= json_encode(array_map(fn($h) => ['d' => $h['day'], 'b' => (int)$h['bytes']], $hist)) ?>;</script>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-4"><div class="card card-soft h-100"><div class="card-body">
    <h2 class="h6 fw-bold mb-3">Podľa typu súborov</h2>
    <?php foreach ($byType as $t): ?>
      <div class="mb-2">
        <div class="d-flex justify-content-between small"><span><?= e($t['t']) ?> <span class="text-body-secondary">· <?= number_format((int)$t['c'], 0, ',', ' ') ?></span></span><span class="fw-semibold"><?= format_bytes($t['b']) ?></span></div>
        <div class="type-bar"><span style="width:<?= round($t['b'] / $typeMax * 100, 1) ?>%"></span></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$byType): ?><div class="small text-body-secondary">Zatiaľ žiadne súbory.</div><?php endif; ?>
  </div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card card-soft h-100"><div class="card-body">
    <h2 class="h6 fw-bold mb-3">Najväčší používatelia</h2>
    <?php foreach ($top as $t): $p = $t['quota'] ? min(100, $t['used'] / $t['quota'] * 100) : 0; ?>
      <div class="mb-3"><div class="d-flex justify-content-between small"><span class="text-truncate me-2"><?= e($t['name']) ?></span><span class="text-body-secondary text-nowrap"><?= format_bytes($t['used']) ?> / <?= $t['quota'] ? format_bytes($t['quota']) : '∞' ?></span></div>
      <div class="progress mt-1" style="height:6px"><div class="progress-bar <?= $p >= 90 ? 'bg-danger' : ($p >= 75 ? 'bg-warning' : '') ?>" style="width:<?= $p ?>%"></div></div></div>
    <?php endforeach; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card card-soft h-100"><div class="card-body">
    <h2 class="h6 fw-bold mb-3">Posledná aktivita</h2>
    <ul class="list-unstyled small mb-0">
    <?php foreach (array_slice($logs, 0, 8) as $l): ?>
      <li class="d-flex gap-2 py-1 border-bottom"><span class="text-body-secondary text-nowrap"><?= date('d.m. H:i', strtotime($l['created_at'])) ?></span><span class="badge text-bg-light border"><?= e($l['action']) ?></span><span class="text-truncate"><?= e($l['email'] ?? '—') ?> <?= e($l['detail']) ?></span></li>
    <?php endforeach; ?>
    </ul>
  </div></div></div>
</div>

<?php elseif ($tab === 'users'): ?>
<form class="row g-2 mb-3" method="get">
  <input type="hidden" name="tab" value="users">
  <div class="col-12 col-md"><input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Hľadať meno alebo e-mail…"></div>
  <div class="col-8 col-md-3"><select class="form-select select2" name="f" data-search="false">
    <option value="">Všetci používatelia</option>
    <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Čakajú na schválenie</option>
    <option value="admin" <?= $filter === 'admin' ? 'selected' : '' ?>>Administrátori</option>
    <option value="full" <?= $filter === 'full' ? 'selected' : '' ?>>Takmer plná kvóta</option>
  </select></div>
  <div class="col-4 col-md-auto"><button class="btn btn-outline-primary w-100"><i class="bi bi-funnel"></i> Filtrovať</button></div>
</form>
<form method="post" id="bulkForm" class="bulk-bar card-soft d-none mb-3">
  <?= csrf_field() ?><input type="hidden" name="act" value="bulk"><input type="hidden" name="tab" value="users">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <span class="fw-semibold me-auto"><i class="bi bi-check2-square me-1"></i><span id="bulkCount">0</span> vybraných</span>
    <button class="btn btn-sm btn-light rounded-pill" name="bulk_op" value="activate"><i class="bi bi-check-circle me-1"></i>Aktivovať</button>
    <button class="btn btn-sm btn-light rounded-pill" name="bulk_op" value="deactivate"><i class="bi bi-pause-circle me-1"></i>Deaktivovať</button>
    <button class="btn btn-sm btn-light rounded-pill" name="bulk_op" value="verify"><i class="bi bi-envelope-check me-1"></i>Overiť e-mail</button>
    <button class="btn btn-sm btn-light rounded-pill" name="bulk_op" value="logout"><i class="bi bi-box-arrow-right me-1"></i>Odhlásiť všade</button>
  </div>
  <div class="row g-2 align-items-start">
    <div class="col-md"><label class="form-label small fw-semibold mb-1">Nastaviť kvótu vybraným</label>
      <?php $qName = 'bulk'; $qVal = $dqVal; $qUnit = $dqUnit; $qUnl = false; require __DIR__ . '/inc/views/quota_input.php'; ?></div>
    <div class="col-md-auto pt-md-4">
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="bulk_notify" value="1" id="bulkNotify" checked><label class="form-check-label small" for="bulkNotify">Upozorniť e-mailom</label></div>
      <button class="btn btn-primary rounded-pill px-4" name="bulk_op" value="quota"><i class="bi bi-hdd me-1"></i>Nastaviť kvótu</button></div>
  </div>
</form>
<div class="card card-soft"><div class="table-responsive">
<table class="table table-hover align-middle mb-0 users-table">
  <thead><tr><th style="width:36px"><input type="checkbox" class="form-check-input" id="bulkAll" aria-label="Vybrať všetkých"></th><th>Používateľ</th><th class="d-none d-md-table-cell">Rola</th><th style="min-width:180px">Úložisko</th><th class="d-none d-lg-table-cell">Posledné prihlásenie</th><th class="text-end">Akcie</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): $p = $u['quota'] ? min(100, $u['used'] / $u['quota'] * 100) : 0; [$qv, $qu] = quota_split((int)$u['quota']); ?>
    <tr class="<?= $u['is_active'] ? '' : 'table-warning' ?>">
      <td><input type="checkbox" class="form-check-input bulk-check" name="ids[]" value="<?= (int)$u['id'] ?>" form="bulkForm" aria-label="Vybrať"></td>
      <td><div class="d-flex align-items-center gap-2"><span class="avatar avatar-sm"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
        <div class="min-w-0"><div class="fw-semibold text-truncate"><?= e($u['name']) ?><?= $u['passkeys'] ? ' <i class="bi bi-fingerprint text-primary" title="Má odtlačok"></i>' : '' ?></div><div class="small text-body-secondary text-truncate"><?= e($u['email']) ?></div>
        <?php if (!$u['is_active']): ?><span class="badge text-bg-warning">neaktívny</span><?php endif; ?>
        <?php if (isset($u['email_verified']) && !$u['email_verified']): ?><span class="badge text-bg-info">neoverený e-mail</span><?php endif; ?></div></div></td>
      <td class="d-none d-md-table-cell"><span class="badge <?= $u['role'] === 'admin' ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Používateľ' ?></span></td>
      <td><div class="small d-flex justify-content-between"><span><?= format_bytes($u['used']) ?></span><span class="text-body-secondary"><?= $u['quota'] ? format_bytes($u['quota']) : '∞' ?></span></div>
        <div class="progress mt-1" style="height:6px"><div class="progress-bar <?= $p >= 90 ? 'bg-danger' : ($p >= 75 ? 'bg-warning' : '') ?>" style="width:<?= $u['quota'] ? $p : 0 ?>%"></div></div>
        <div class="small text-body-secondary"><?= (int)$u['files'] ?> súborov</div></td>
      <td class="d-none d-lg-table-cell small text-body-secondary"><?= $u['last_login'] ? date('d.m.Y H:i', strtotime($u['last_login'])) : 'nikdy' ?></td>
      <td class="text-end text-nowrap">
        <button class="btn btn-icon" title="Upraviť" data-bs-toggle="modal" data-bs-target="#userModal"
          data-user='<?= e(json_encode(['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'is_active' => (int)$u['is_active'], 'unlimited' => !$u['quota'], 'qv' => $qv, 'qu' => $qu], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button>
        <div class="dropdown d-inline-block">
          <button class="btn btn-icon" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <?php if ((int)$u['id'] !== $me): ?>
            <li><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="user_toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="dropdown-item"><i class="bi <?= $u['is_active'] ? 'bi-pause-circle' : 'bi-check-circle' ?> me-2"></i><?= $u['is_active'] ? 'Deaktivovať' : 'Aktivovať / schváliť' ?></button></form></li>
            <?php endif; ?>
            <li><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="user_reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="dropdown-item"><i class="bi bi-envelope-arrow-up me-2"></i>Poslať odkaz na nové heslo</button></form></li>
            <?php if (isset($u['email_verified']) && !$u['email_verified']): ?>
            <li><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="user_verify"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="dropdown-item"><i class="bi bi-envelope-check me-2"></i>Označiť e-mail ako overený</button></form></li>
            <?php endif; ?>
            <li><form method="post" data-confirm="Odhlásiť <?= e($u['email']) ?> zo všetkých zariadení?"><?= csrf_field() ?><input type="hidden" name="act" value="user_logout"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Odhlásiť zo všetkých zariadení</button></form></li>
            <li><a class="dropdown-item" href="?tab=log&lu=<?= (int)$u['id'] ?>"><i class="bi bi-journal-text me-2"></i>Aktivita</a></li>
            <?php if ((int)$u['id'] !== $me): ?>
            <li><hr class="dropdown-divider"></li>
            <li><form method="post" data-confirm="Zmazať používateľa <?= e($u['email']) ?> a VŠETKY jeho súbory? Akcia je nevratná."><?= csrf_field() ?><input type="hidden" name="act" value="user_delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="dropdown-item text-danger"><i class="bi bi-trash3 me-2"></i>Zmazať používateľa</button></form></li>
            <?php endif; ?>
          </ul>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">Žiadni používatelia</td></tr><?php endif; ?>
  </tbody>
</table></div></div>

<?php elseif ($tab === 'settings'): ?>
<div class="card card-soft" style="max-width:720px"><div class="card-body p-4">
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="act" value="settings"><input type="hidden" name="tab" value="settings">
  <div class="mb-4">
    <label class="form-label fw-semibold">Registrácia nových používateľov</label>
    <select class="form-select select2" name="registration" data-search="false">
      <?php foreach (['open' => 'Otvorená – účet je hneď aktívny', 'approval' => 'So schválením administrátorom', 'closed' => 'Vypnutá – účty vytvára len admin'] as $k => $v): ?>
      <option value="<?= $k ?>" <?= setting('registration', 'open') === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Predvolená kvóta pre nových používateľov</label>
    <?php $qName = 'default'; $qVal = $dqVal; $qUnit = $dqUnit; $qUnl = (int)setting('default_quota', cfg('default_quota')) === 0; require __DIR__ . '/inc/views/quota_input.php'; ?>
  </div>
  <div class="mb-4">
    <label class="form-label fw-semibold">Maximálna veľkosť jedného súboru</label>
    <div class="input-group" style="max-width:260px"><input type="number" min="0" class="form-control" name="max_upload_mb" value="<?= e(setting('max_upload_mb', '512')) ?>"><span class="input-group-text">MB</span></div>
    <div class="form-text">0 = bez limitu aplikácie. Server limituje aj cez <code>upload_max_filesize</code> (<?= e(ini_get('upload_max_filesize')) ?>) a <code>post_max_size</code> (<?= e(ini_get('post_max_size')) ?>).</div>
  </div>
  <div class="form-check form-switch mb-4">
    <input class="form-check-input" type="checkbox" role="switch" name="notify_admin_register" id="nar" value="1" <?= setting('notify_admin_register', '1') === '1' ? 'checked' : '' ?>>
    <label class="form-check-label" for="nar">Posielať adminom e-mail pri novej registrácii</label>
  </div>
  <div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" role="switch" name="require_email_verify" id="rev" value="1" <?= setting('require_email_verify', '1') === '1' ? 'checked' : '' ?>>
    <label class="form-check-label" for="rev">Vyžadovať overenie e-mailu pri registrácii</label>
  </div>
  <div class="form-check form-switch mb-4">
    <input class="form-check-input" type="checkbox" role="switch" name="notify_new_device" id="nnd" value="1" <?= setting('notify_new_device', '1') === '1' ? 'checked' : '' ?>>
    <label class="form-check-label" for="nnd">Upozorniť používateľa e-mailom pri prihlásení z nového zariadenia</label>
  </div>
  <div class="row g-3 mb-4">
    <div class="col-sm-6"><label class="form-label fw-semibold">Kôš – automatické vysypanie po</label>
      <div class="input-group"><input type="number" min="0" max="3650" class="form-control" name="trash_days" value="<?= e(setting('trash_days', '30')) ?>"><span class="input-group-text">dňoch</span></div>
      <div class="form-text">0 = nikdy automaticky.</div></div>
    <div class="col-sm-6"><label class="form-label fw-semibold">Počet uchovaných verzií súboru</label>
      <input type="number" min="0" max="100" class="form-control" name="max_versions" value="<?= e(setting('max_versions', '10')) ?>">
      <div class="form-text">0 = verzie sa neuchovávajú. Verzie sa rátajú do kvóty.</div></div>
  </div>
  <button class="btn btn-primary rounded-pill px-4">Uložiť nastavenia</button>
</form>
</div></div>

<?php elseif ($tab === 'smtp'): ?>
<div class="card card-soft" style="max-width:720px"><div class="card-body p-4">
<form method="post" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="act" value="smtp"><input type="hidden" name="tab" value="smtp">
  <div class="row g-3">
    <div class="col-md-8"><label class="form-label">SMTP server</label><input class="form-control" name="smtp_host" value="<?= e($smtp['smtp_host']) ?>" placeholder="smtp.domena.sk"></div>
    <div class="col-6 col-md-2"><label class="form-label">Port</label><input type="number" class="form-control" name="smtp_port" value="<?= e($smtp['smtp_port']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label">Šifrovanie</label>
      <select class="form-select select2" name="smtp_secure" data-search="false">
        <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => 'Žiadne'] as $k => $v): ?><option value="<?= $k ?>" <?= ((string)$smtp['smtp_secure'] ?: 'none') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label">Používateľ</label><input class="form-control" name="smtp_user" value="<?= e($smtp['smtp_user']) ?>" autocomplete="off"></div>
    <div class="col-md-6"><label class="form-label">Heslo</label><input type="password" class="form-control" name="smtp_pass" placeholder="<?= $smtp['smtp_pass'] ? '•••••••• (nezmenené)' : '' ?>" autocomplete="new-password"></div>
    <div class="col-md-6"><label class="form-label">Odosielateľ (e-mail)</label><input type="email" class="form-control" name="smtp_from" value="<?= e($smtp['smtp_from']) ?>"></div>
    <div class="col-md-6"><label class="form-label">Meno odosielateľa</label><input class="form-control" name="smtp_from_name" value="<?= e($smtp['smtp_from_name']) ?>"></div>
  </div>
  <div class="d-flex flex-wrap gap-2 mt-4">
    <button class="btn btn-primary rounded-pill px-4">Uložiť</button>
    <button class="btn btn-outline-primary rounded-pill px-4" name="test" value="1"><i class="bi bi-send me-1"></i>Uložiť a poslať test na <?= e($user['email']) ?></button>
  </div>
  <p class="form-text mt-3 mb-0">Používa sa na: obnovu hesla, uvítací e-mail, schválenie účtu, upozornenie na plné úložisko, zmenu kvóty a hesla, notifikácie adminom.</p>
</form>
</div></div>

<?php elseif ($tab === 'maintenance'):
  $cs = maint_crypto_stats();
  $keyErr = null; $fp = '';
  try { if (crypto_enabled() || $cs['enc'] > 0) $fp = crypto_fingerprint(); } catch (Throwable $ex) { $keyErr = $ex->getMessage(); }
  $keyFromConfig = (string)cfg('encryption_key', '') !== '';
?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card card-soft h-100"><div class="card-body p-4">
    <h2 class="h6 fw-bold mb-3"><i class="bi bi-lock-fill me-2 text-success"></i>Šifrovanie súborov</h2>
    <p class="small text-body-secondary">Súbory, staršie verzie aj miniatúry sú na disku servera uložené zašifrované (AES-256-GCM). Ani niekto s prístupom k FTP či hostingu ich bez kľúča neprečíta. Každý súbor má vlastný kľúč, chránený hlavným kľúčom.</p>
    <?php if ($keyErr): ?><div class="alert alert-danger small"><?= e($keyErr) ?></div><?php endif; ?>
    <form method="post" class="mb-3">
      <?= csrf_field() ?><input type="hidden" name="act" value="encryption_setting"><input type="hidden" name="tab" value="maintenance">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" name="encrypt_files" id="encOn" value="1" <?= crypto_enabled() ? 'checked' : '' ?> onchange="this.form.submit()">
        <label class="form-check-label fw-semibold" for="encOn">Šifrovať nové súbory</label>
      </div>
    </form>
    <div class="d-flex flex-wrap gap-3 small mb-3">
      <span><i class="bi bi-lock-fill text-success me-1"></i>Zašifrované: <b id="encCount"><?= $cs['enc'] ?></b></span>
      <span><i class="bi bi-unlock text-warning me-1"></i>Nezašifrované: <b id="plainCount"><?= $cs['plain'] ?></b></span>
    </div>
    <?php if ($cs['plain'] > 0 && crypto_enabled()): ?>
      <button class="btn btn-primary rounded-pill" id="encryptBtn"><i class="bi bi-lock me-1"></i>Zašifrovať existujúce súbory</button>
      <div class="progress mt-3 d-none" id="encProgress" style="height:8px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0"></div></div>
      <div class="small text-body-secondary mt-2" id="encStatus"></div>
    <?php endif; ?>
    <?php if ($fp): ?>
    <hr>
    <div class="small mb-2"><b>Hlavný kľúč</b> · odtlačok <code><?= e($fp) ?></code> · <?= $keyFromConfig ? 'z config.php' : 'súbor <code>' . e(str_replace(APP_ROOT . '/', '', crypto_master_file())) . '</code>' ?></div>
    <div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle me-1"></i><b>Kľúč si zálohuj mimo servera.</b> Ak ho stratíš (napr. pri sťahovaní na nový hosting bez neho), zašifrované súbory sa už nebudú dať otvoriť.</div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="download_key"><input type="hidden" name="tab" value="maintenance">
      <button class="btn btn-outline-secondary btn-sm rounded-pill"><i class="bi bi-key me-1"></i>Stiahnuť záložný kľúč</button></form>
    <?php endif; ?>
  </div></div></div>

  <div class="col-lg-6"><div class="card card-soft h-100"><div class="card-body p-4">
    <h2 class="h6 fw-bold mb-3"><i class="bi bi-clipboard2-check me-2 text-primary"></i>Kontrola integrity</h2>
    <p class="small text-body-secondary">Overí, či ku každému súboru existujú dáta na disku a či sa nezmenili. <b>Rýchla</b> kontrola porovná veľkosti, <b>hĺbková</b> prečíta a overí obsah každého súboru (trvá dlhšie).</p>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <button class="btn btn-primary rounded-pill" data-check="quick"><i class="bi bi-lightning me-1"></i>Rýchla kontrola</button>
      <button class="btn btn-outline-primary rounded-pill" data-check="deep"><i class="bi bi-search me-1"></i>Hĺbková kontrola</button>
      <button class="btn btn-outline-secondary rounded-pill" id="orphanBtn"><i class="bi bi-trash2 me-1"></i>Nepoužívané súbory</button>
    </div>
    <div class="small text-body-secondary mb-2">Posledná kontrola: <?= e(setting('last_integrity', 'zatiaľ nebola')) ?></div>
    <div class="progress d-none mb-2" id="chkProgress" style="height:8px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%"></div></div>
    <div id="chkStatus" class="small"></div>
    <div id="chkResults" class="mt-2"></div>
  </div></div></div>
</div>

<?php elseif ($tab === 'update'):
  $relErr = null; $srv = []; $apk = [];
  try { $srv = gh_releases('server-v'); $apk = gh_releases('android-v'); } catch (Throwable $ex) { $relErr = $ex->getMessage(); }
  $newer = array_values(array_filter($srv, fn($r) => version_compare($r['version'], APP_VERSION, '>')));
  $backups = glob(data_path('backups') . '/cloud-*.zip') ?: [];
  rsort($backups);
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card card-soft h-100"><div class="card-body p-4">
    <h2 class="h6 fw-bold mb-3"><i class="bi bi-hdd-network me-2"></i>Webová aplikácia (server)</h2>
    <div class="d-flex align-items-baseline gap-2 mb-3"><span class="text-body-secondary">Nainštalovaná verzia</span><span class="fs-4 fw-bold"><?= e(APP_VERSION) ?></span></div>
    <?php if ($relErr): ?>
      <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?= e($relErr) ?></div>
    <?php elseif (!$newer): ?>
      <div class="alert alert-success small mb-0"><i class="bi bi-check-circle me-1"></i>Máš najnovšiu verziu.<?= $srv ? ' Posledné vydanie: ' . e($srv[0]['version']) . ' (' . e($srv[0]['date']) . ').' : '' ?></div>
    <?php else: $n = $newer[0]; ?>
      <div class="alert alert-primary">
        <div class="fw-semibold mb-1"><i class="bi bi-stars me-1"></i>Dostupná verzia <?= e($n['version']) ?> <span class="small fw-normal">(<?= e($n['date']) ?>)</span></div>
        <?php foreach ($newer as $r): if (trim($r['notes']) === '') continue; ?>
          <div class="small mt-2"><b><?= e($r['version']) ?></b><div style="white-space:pre-wrap"><?= e(trim($r['notes'])) ?></div></div>
        <?php endforeach; ?>
      </div>
      <form method="post" data-confirm="Aktualizovať na verziu <?= e($n['version']) ?>? Pred aktualizáciou sa vytvorí záloha súborov. config.php a nahraté súbory zostanú nedotknuté.">
        <?= csrf_field() ?><input type="hidden" name="act" value="update"><input type="hidden" name="tab" value="update"><input type="hidden" name="version" value="<?= e($n['version']) ?>">
        <button class="btn btn-primary rounded-pill px-4"><i class="bi bi-cloud-arrow-down me-1"></i>Aktualizovať na <?= e($n['version']) ?></button>
      </form>
      <p class="form-text mt-2 mb-0">Odporúčame mať zálohu databázy. Ak sa niečo pokazí, záloha súborov je v <code>storage/backups</code>.</p>
    <?php endif; ?>
    <?php if ($backups): ?>
      <div class="small text-body-secondary mt-3">Zálohy: <?= e(implode(', ', array_map('basename', array_slice($backups, 0, 5)))) ?></div>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><div class="card card-soft h-100"><div class="card-body p-4">
    <h2 class="h6 fw-bold mb-3"><i class="bi bi-android2 me-2 text-success"></i>Android aplikácia</h2>
    <?php if ($apk): ?>
      <div class="d-flex align-items-baseline gap-2 mb-2"><span class="text-body-secondary">Najnovšia</span><span class="fs-4 fw-bold"><?= e($apk[0]['version']) ?></span><span class="small text-body-secondary"><?= e($apk[0]['date']) ?></span></div>
      <a class="btn btn-outline-success rounded-pill" href="<?= e($apk[0]['apk'] ?? '#') ?>"><i class="bi bi-download me-1"></i>Stiahnuť APK</a>
      <p class="form-text mt-2 mb-0">Nainštalovaná aplikácia sa aktualizuje sama (Menu → Aktualizácia aplikácie).</p>
    <?php else: ?>
      <p class="small text-body-secondary mb-0"><?= $relErr ? 'Nedá sa overiť.' : 'Zatiaľ žiadne vydanie.' ?></p>
    <?php endif; ?>
  </div></div></div>
</div>

<?php elseif ($tab === 'log'): ?>
<form class="row g-2 mb-3" method="get">
  <input type="hidden" name="tab" value="log">
  <div class="col-md-5"><select class="form-select select2" name="lu" onchange="this.form.submit()">
    <option value="0">Všetci používatelia</option>
    <?php foreach ($allUsers as $au): ?><option value="<?= (int)$au['id'] ?>" <?= $logUser === (int)$au['id'] ? 'selected' : '' ?>><?= e($au['name'] . ' – ' . $au['email']) ?></option><?php endforeach; ?>
  </select></div>
</form>
<div class="card card-soft"><div class="table-responsive">
<table class="table table-sm align-middle mb-0 small">
  <thead><tr><th>Čas</th><th>Používateľ</th><th>Akcia</th><th>Detail</th><th class="d-none d-md-table-cell">IP</th></tr></thead>
  <tbody>
  <?php foreach ($logs as $l): ?>
    <tr><td class="text-nowrap"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td><td class="text-truncate" style="max-width:180px"><?= e($l['email'] ?? '—') ?></td>
    <td><span class="badge text-bg-light border"><?= e($l['action']) ?></span></td><td class="text-break"><?= e($l['detail']) ?></td><td class="d-none d-md-table-cell text-body-secondary"><?= e($l['ip']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>

<!-- modal: používateľ -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="user_save"><input type="hidden" name="id" value="0"><input type="hidden" name="tab" value="users">
      <div class="modal-header border-0"><h5 class="modal-title">Upraviť používateľa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Meno</label><input class="form-control" name="name" required></div>
        <div class="mb-3"><label class="form-label">E-mail</label><input type="email" class="form-control" name="email" required></div>
        <div class="row g-3 mb-3">
          <div class="col-sm-6"><label class="form-label">Rola</label>
            <select class="form-select select2" name="role" data-search="false"><option value="user">Používateľ</option><option value="admin">Administrátor</option></select></div>
          <div class="col-sm-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="uActive" checked><label class="form-check-label" for="uActive">Aktívny účet</label></div></div>
        </div>
        <div class="mb-3"><label class="form-label">Kvóta úložiska</label>
          <?php $qName = 'user'; $qVal = $dqVal; $qUnit = $dqUnit; $qUnl = false; require __DIR__ . '/inc/views/quota_input.php'; ?>
        </div>
        <div class="mb-2"><label class="form-label">Heslo <span class="text-body-secondary small" data-pass-hint>(nechaj prázdne pre nezmenené)</span></label>
          <input type="text" class="form-control" name="password" minlength="8" autocomplete="new-password"></div>
        <div class="form-check d-none" data-new-only><input class="form-check-input" type="checkbox" name="notify" value="1" id="uNotify" checked><label class="form-check-label" for="uNotify">Poslať prihlasovacie údaje e-mailom</label></div>
      </div>
      <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button><button class="btn btn-primary rounded-pill px-4">Uložiť</button></div>
    </form>
  </div>
</div>
<script>window.DEFAULT_QUOTA = <?= json_encode(['qv' => $dqVal, 'qu' => $dqUnit]) ?>;</script>
<?php require __DIR__ . '/inc/views/layout_end.php'; ?>
