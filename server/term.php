<?php
/* Webový SSH terminál pre administrátora (Administrácia → Terminál).
 * Prehliadač ↔ PHP (krátke požiadavky + long-polling) ↔ inc/term_helper.py (pseudo-terminál) ↔ ssh */
require __DIR__ . '/inc/core.php';
$user = require_admin();
require_once __DIR__ . '/inc/maintenance.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Metóda nie je povolená'], 405);
csrf_check();

const TERM_UNLOCK_SEC = 900; // po overení hesla 15 min na otvorenie nových relácií

function term_dir(string $id = ''): string { return rtrim(data_path('term'), '/') . ($id !== '' ? '/' . $id : ''); }
function term_session(): string {
    $id = (string)($_POST['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $id) || empty($_SESSION['terms'][$id]) || !is_dir(term_dir($id))) throw new RuntimeException('Relácia terminálu neexistuje.');
    return $id;
}
function term_rm(string $id): void {
    $d = term_dir($id);
    foreach (['in', 'out', 'pid', 'alive', 'resize', 'close', 'ended'] as $f) @unlink("$d/$f");
    @rmdir($d);
}

$op = (string)($_GET['op'] ?? '');
try {
    switch ($op) {
        case 'unlock': { // opätovné overenie heslom do Cloudu
            if (too_many_attempts()) throw new RuntimeException('Príliš veľa neúspešných pokusov. Skús to o 15 minút.');
            if (!password_verify((string)($_POST['password'] ?? ''), (string)$user['password'])) {
                record_failed_attempt((string)$user['email']);
                log_action((int)$user['id'], 'terminal_denied', 'nesprávne heslo');
                throw new RuntimeException('Nesprávne heslo.');
            }
            $_SESSION['term_unlock'] = time();
            json_out(['ok' => true]);
        }
        case 'start': {
            if (time() - (int)($_SESSION['term_unlock'] ?? 0) > TERM_UNLOCK_SEC) json_out(['ok' => false, 'locked' => true, 'error' => 'Potvrď heslo.']);
            if (($why = term_requirements()) !== null) throw new RuntimeException(strip_tags($why));
            $login = trim((string)($_POST['user'] ?? ''));
            $host = trim((string)($_POST['host'] ?? '127.0.0.1')) ?: '127.0.0.1';
            $port = (int)($_POST['port'] ?? 22) ?: 22;
            if (!preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $login)) throw new RuntimeException('Neplatné používateľské meno.');
            if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,252}[a-z0-9])?$|^\[?[0-9a-f:.]+\]?$/i', $host)) throw new RuntimeException('Neplatný server.');
            if ($port < 1 || $port > 65535) throw new RuntimeException('Neplatný port.');
            $rows = max(5, min(200, (int)($_POST['rows'] ?? 30)));
            $cols = max(20, min(400, (int)($_POST['cols'] ?? 100)));
            // staré relácie tohto prehliadača
            term_cleanup();
            $_SESSION['terms'] = array_filter((array)($_SESSION['terms'] ?? []), fn($t, $id) => is_dir(term_dir((string)$id)), ARRAY_FILTER_USE_BOTH);
            if (count($_SESSION['terms']) >= 4) throw new RuntimeException('Otvorené sú už 4 terminály – niektorý zavri.');
            $id = bin2hex(random_bytes(16));
            $d = term_dir($id);
            if (!@mkdir($d, 0700, true)) throw new RuntimeException('Nedá sa vytvoriť priečinok pre terminál (' . $d . ').');
            touch("$d/alive"); touch("$d/in"); touch("$d/out");
            $args = [ffmpeg_bin('python3'), __DIR__ . '/inc/term_helper.py', $d, (string)$rows, (string)$cols,
                     ffmpeg_bin('ssh'), '-tt', '-o', 'StrictHostKeyChecking=accept-new', '-o', 'ServerAliveInterval=30', '-p', (string)$port, $login . '@' . trim($host, '[]')];
            $cmd = implode(' ', array_map('escapeshellarg', $args));
            $setsid = ffmpeg_bin('setsid');
            exec(($setsid ? escapeshellarg($setsid) . ' ' : 'nohup ') . $cmd . ' > /dev/null 2>&1 < /dev/null &');
            $_SESSION['terms'][$id] = time();
            setting_set('term_last', json_encode(['user' => $login, 'host' => $host, 'port' => $port]));
            log_action((int)$user['id'], 'terminal', "$login@$host:$port");
            json_out(['ok' => true, 'id' => $id]);
        }
        case 'read': { // long-polling: vráti nový výstup od pozície „off“
            $id = term_session();
            session_write_close();
            @set_time_limit(40);
            $d = term_dir($id);
            $off = max(0, (int)($_POST['off'] ?? 0));
            @touch("$d/alive");
            $t0 = microtime(true);
            while (true) {
                clearstatcache(true, "$d/out");
                $size = (int)@filesize("$d/out");
                $ended = is_file("$d/ended");
                if ($size > $off || $ended || microtime(true) - $t0 > 20 || connection_aborted()) break;
                usleep(40000);
            }
            $data = '';
            if ($size > $off) {
                $h = fopen("$d/out", 'rb'); fseek($h, $off); $data = (string)fread($h, 262144); fclose($h);
            }
            $done = $ended && $off + strlen($data) >= $size;
            if ($done) term_rm($id);
            json_out(['ok' => true, 'data' => base64_encode($data), 'off' => $off + strlen($data), 'ended' => $done]);
        }
        case 'write': {
            $id = term_session();
            session_write_close();
            $data = base64_decode((string)($_POST['data'] ?? ''), true);
            if ($data === false || strlen($data) > 65536) throw new RuntimeException('Neplatný vstup.');
            if (is_file(term_dir($id) . '/ended')) json_out(['ok' => true, 'ended' => true]);
            file_put_contents(term_dir($id) . '/in', $data, FILE_APPEND | LOCK_EX);
            json_out(['ok' => true]);
        }
        case 'resize': {
            $id = term_session();
            session_write_close();
            file_put_contents(term_dir($id) . '/resize', max(5, min(200, (int)$_POST['rows'])) . ' ' . max(20, min(400, (int)$_POST['cols'])));
            json_out(['ok' => true]);
        }
        case 'close': {
            $id = term_session();
            touch(term_dir($id) . '/close');
            unset($_SESSION['terms'][$id]);
            json_out(['ok' => true]);
        }
    }
    json_out(['ok' => false, 'error' => 'Neznáma akcia.'], 400);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()]);
}
