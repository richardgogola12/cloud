<?php
/* Profilová fotka (rozšifrovaná) – iba pre prihlásených */
require __DIR__ . '/inc/core.php';
$me = current_user();
if (!$me) { http_response_code(403); exit; }
$id = (int)($_GET['u'] ?? 0);
$u = q('SELECT id, avatar_key FROM ' . P . 'users WHERE id = ?', [$id])->fetch();
$jpg = $u ? avatar_read($u) : null;
if ($jpg === null) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($jpg));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
echo $jpg;
