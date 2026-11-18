<?php
require __DIR__ . '/inc/core.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($u = current_user()) { log_action((int)$u['id'], 'logout'); forget_account((int)$u['id']); }
    logout_user();
}
header('Location: ' . url('login.php'));
