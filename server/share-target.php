<?php
/* Záloha pre PWA "Zdieľať" – ak service worker ešte nebeží, súbory spracuje server */
require __DIR__ . '/inc/core.php';
$user = current_user();
if (!$user) { flash('warning', 'Na zdieľanie súborov sa najprv prihlás.'); redirect('login.php'); }
$n = 0;
foreach ((array)($_FILES['files']['tmp_name'] ?? []) as $i => $tmp) {
    if (($_FILES['files']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
    try {
        if ($user['quota'] > 0 && used_space((int)$user['id']) + (int)$_FILES['files']['size'][$i] > $user['quota']) break;
        $name = unique_name((int)$user['id'], null, clean_name((string)$_FILES['files']['name'][$i]), 'f');
        store_file((int)$user['id'], null, $name, $tmp, true);
        $n++;
    } catch (Throwable $e) {}
}
flash($n ? 'success' : 'warning', $n ? "Uložených $n súborov do Môj disk." : 'Nepodarilo sa uložiť žiadny súbor.');
redirect('index.php');
