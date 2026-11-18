<?php
/* Rýchle prepínanie medzi účtami uloženými v tomto prehliadači */
require __DIR__ . '/inc/core.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();
$do = (string)($_POST['do'] ?? 'switch');
$me = current_user();

if ($do === 'add') {
    // odhlási aktuálnu reláciu, ale účet ostane v zozname na rýchle prepnutie
    if ($me) { log_action((int)$me['id'], 'logout', 'pridanie účtu'); logout_user(); }
    header('Location: ' . url('login.php?add=1'));
    exit;
}
if ($do === 'logout_all') {
    foreach (array_keys(acc_list()) as $id) forget_account((int)$id);
    if ($me) { log_action((int)$me['id'], 'logout', 'všetky účty'); logout_user(); }
    header('Location: ' . url('login.php'));
    exit;
}
if ($do === 'forget') {
    $id = (int)($_POST['uid'] ?? 0);
    if (!$me || $id !== (int)$me['id']) forget_account($id);
    header('Location: ' . url($me ? 'index.php' : 'login.php'));
    exit;
}

$id = (int)($_POST['uid'] ?? 0);
$list = acc_list();
if (!isset($list[$id]) || !acc_token_valid($id, $list[$id])) {
    forget_account($id);
    flash('warning', 'Platnosť uloženého prihlásenia vypršala – prihlás sa znova.');
    header('Location: ' . url('login.php?add=1'));
    exit;
}
$u = q('SELECT * FROM ' . P . 'users WHERE id = ?', [$id])->fetch();
if (!$u || !$u['is_active']) {
    forget_account($id);
    flash('warning', 'Tento účet nie je aktívny.');
    redirect('login.php');
}
if ($me && (int)$me['id'] === $id) redirect('index.php');
if ($me && !empty($_SESSION['sid_row'])) {
    try { q('UPDATE ' . P . 'sessions SET revoked = 1 WHERE id = ?', [$_SESSION['sid_row']]); } catch (Throwable $e) {}
}
$_SESSION = [];
login_user($u, true, 'prepnutie účtu');
log_action($id, 'login', 'prepnutie účtu');
redirect('index.php');
