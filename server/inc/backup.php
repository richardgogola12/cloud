<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Automatická záloha: databáza (šifrovaná heslom) + súbory (postupne,
 *  iba nové) na vzdialený disk cez WebDAV – napr. Cloud na Raspberry Pi,
 *  Nextcloud, NAS – alebo aspoň do storage/backups na tomto serveri.
 *  Nepotrebuje mysqldump ani exec – funguje aj na bežnom hostingu.
 * ------------------------------------------------------------------ */

const BK_MAGIC = "CLOUDBK1";
const BK_CHUNK = 1048576; // 1 MB

function bk_dir(): string { $d = data_path('backups/db'); if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function bk_secret_get(string $k): string { $v = (string)setting($k, ''); return $v === '' ? '' : totp_unwrap($v); }
function bk_secret_set(string $k, string $v): void { setting_set($k, $v === '' ? '' : totp_wrap($v)); }
function bk_cfg(): array {
    return [
        'enabled' => setting('backup_enabled', '0') === '1',
        'hour' => max(0, min(23, (int)setting('backup_hour', '3'))),
        'keep' => max(1, min(60, (int)setting('backup_keep', '7'))),
        'target' => setting('backup_target', 'local') === 'webdav' ? 'webdav' : 'local',
        'files' => setting('backup_files', '1') === '1',
        'url' => rtrim((string)setting('backup_url', ''), '/') . '/',
        'user' => (string)setting('backup_user', ''),
        'has_pass' => setting('backup_pass', '') !== '',
        'has_key' => setting('backup_key', '') !== '',
    ];
}
function bk_status(): array { return json_decode((string)setting('backup_status', '{}'), true) ?: []; }
function bk_status_set(array $patch): void { setting_set('backup_status', json_encode(array_merge(bk_status(), $patch), JSON_UNESCAPED_UNICODE)); }
function bk_list(): array { return json_decode((string)setting('backup_list', '[]'), true) ?: []; }

/* ---------- šifrovanie heslom (AES-256-GCM po 1 MB, kľúč z hesla cez PBKDF2) ---------- */
function bk_key(string $password, string $salt): string { return hash_pbkdf2('sha256', $password, $salt, 200000, 32, true); }
function bk_encrypt_file(string $src, string $dst, string $password): void {
    $salt = random_bytes(16); $key = bk_key($password, $salt);
    $in = fopen($src, 'rb'); $out = fopen($dst, 'wb');
    fwrite($out, BK_MAGIC . $salt);
    $i = 0;
    while (!feof($in)) {
        $plain = fread($in, BK_CHUNK); if ($plain === '' || $plain === false) break;
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, pack('N', $i++));
        fwrite($out, $iv . $tag . pack('N', strlen($ct)) . $ct);
    }
    fclose($in); fclose($out);
}
/** Rozšifruje zálohu; $sink dostáva rozšifrované kúsky (súbor .sql.gz). */
function bk_decrypt_file(string $src, string $password, callable $sink): void {
    $in = fopen($src, 'rb');
    if (fread($in, 8) !== BK_MAGIC) throw new RuntimeException('Toto nie je záloha Cloudu.');
    $key = bk_key($password, fread($in, 16)); $i = 0;
    while (!feof($in)) {
        $hdr = fread($in, 32); if ($hdr === '' || $hdr === false) break;
        if (strlen($hdr) < 32) throw new RuntimeException('Záloha je poškodená (neúplná).');
        $len = unpack('N', substr($hdr, 28, 4))[1];
        $pt = openssl_decrypt(fread($in, $len), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($hdr, 0, 12), substr($hdr, 12, 16), pack('N', $i++));
        if ($pt === false) throw new RuntimeException($i === 1 ? 'Nesprávne heslo zálohy.' : 'Záloha je poškodená.');
        $sink($pt);
    }
    fclose($in);
}

/* ---------- export databázy bez mysqldump ---------- */
function bk_dump_db(string $gzPath): array {
    $gz = gzopen($gzPath, 'wb6'); $rows = 0; $tables = 0;
    $pdo = db();
    gzwrite($gz, "-- Cloud " . APP_VERSION . " – záloha databázy " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $list = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
    foreach ($list as [$t]) {
        if (!str_starts_with($t, P)) continue;
        $tables++;
        $create = $pdo->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$create;\n");
        $st = $pdo->query('SELECT * FROM `' . $t . '`', PDO::FETCH_NUM);
        $buf = []; $cols = null;
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $buf[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            $rows++;
            if (count($buf) >= 200) { gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n"); $buf = []; }
        }
        if ($buf) gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $buf) . ";\n");
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    return ['tables' => $tables, 'rows' => $rows];
}

/* ---------- WebDAV ---------- */
function bk_dav(string $method, string $url, ?string $file = null, array $extra = []): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Na serveri chýba PHP rozšírenie curl.');
    $c = curl_init($url);
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => setting('backup_user', '') . ':' . bk_secret_get('backup_pass'),
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC, // hneď s menom a heslom (pri PUT by sa telo po výzve na prihlásenie nedalo poslať znova) CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 1800, CURLOPT_USERAGENT => 'Cloud-Backup/' . APP_VERSION,
            CURLOPT_HTTPHEADER => array_merge(['Expect:'], $extra), CURLOPT_FOLLOWLOCATION => false];
    if ($file !== null) { $fh = fopen($file, 'rb'); $opt[CURLOPT_UPLOAD] = true; $opt[CURLOPT_INFILE] = $fh; $opt[CURLOPT_INFILESIZE] = filesize($file); }
    curl_setopt_array($c, $opt);
    $body = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE); $err = curl_error($c);
    curl_close($c); if (isset($fh)) fclose($fh);
    if ($body === false) throw new RuntimeException('Vzdialený disk je nedostupný: ' . $err);
    return [$code, (string)$body];
}
function bk_dav_url(string $path): string { return rtrim((string)setting('backup_url', ''), '/') . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/')))); }
function bk_dav_mkdir(string $path): void {
    static $made = [];
    if (isset($made[$path])) return;
    [$code] = bk_dav('MKCOL', bk_dav_url($path) . '/');
    if ($code >= 400 && $code !== 405 && $code !== 301) {
        if ($code === 401 || $code === 403) throw new RuntimeException('Vzdialený disk odmietol prihlásenie (HTTP ' . $code . ') – skontroluj meno a heslo.');
        if ($code !== 409) throw new RuntimeException('Na vzdialenom disku sa nedá vytvoriť priečinok „' . $path . '“ (HTTP ' . $code . ').');
    }
    $made[$path] = true;
}
function bk_dav_put(string $path, string $file): void {
    [$code] = bk_dav('PUT', bk_dav_url($path), $file);
    if ($code < 200 || $code >= 300) throw new RuntimeException('Nahratie „' . $path . '“ na vzdialený disk zlyhalo (HTTP ' . $code . ').');
}
/** Skúška pripojenia: vytvorí priečinok, nahrá a zmaže malý súbor. */
function bk_test(): string {
    $t = tmp_file(); file_put_contents($t, 'Cloud ' . date('c'));
    try { bk_dav_mkdir('cloud-zaloha'); bk_dav_put('cloud-zaloha/test.txt', $t); bk_dav('DELETE', bk_dav_url('cloud-zaloha/test.txt')); }
    finally { @unlink($t); }
    return 'Pripojenie funguje – zálohy pôjdu do priečinka „cloud-zaloha“.';
}

/* ---------- záloha ---------- */
/** Záloha databázy (a uloženie na cieľ). Vráti meno súboru. */
function bk_run_db(): string {
    $cfg = bk_cfg();
    $pass = bk_secret_get('backup_key');
    if ($pass === '') throw new RuntimeException('Najprv nastav heslo zálohy.');
    @set_time_limit(1800);
    $name = 'cloud-db-' . date('Y-m-d-Hi') . '.sql.gz.enc';
    $gz = tmp_file(); $dst = bk_dir() . '/' . $name;
    try {
        $info = bk_dump_db($gz);
        bk_encrypt_file($gz, $dst, $pass);
    } finally { @unlink($gz); }
    $size = (int)filesize($dst);
    if ($cfg['target'] === 'webdav') { bk_dav_mkdir('cloud-zaloha'); bk_dav_mkdir('cloud-zaloha/databaza'); bk_dav_put('cloud-zaloha/databaza/' . $name, $dst); }
    // posledných N záloh, staršie preč (tu aj na vzdialenom disku)
    $list = bk_list();
    array_unshift($list, ['name' => $name, 'size' => $size, 'time' => date('Y-m-d H:i:s'), 'target' => $cfg['target'], 'tables' => $info['tables'], 'rows' => $info['rows']]);
    foreach (array_slice($list, $cfg['keep']) as $old) {
        @unlink(bk_dir() . '/' . basename($old['name']));
        if (($old['target'] ?? '') === 'webdav') try { bk_dav('DELETE', bk_dav_url('cloud-zaloha/databaza/' . basename($old['name']))); } catch (Throwable $e) {}
    }
    setting_set('backup_list', json_encode(array_slice($list, 0, $cfg['keep']), JSON_UNESCAPED_UNICODE));
    // na tomto serveri necháme iba poslednú (ak ide záloha inam), aby nezaberala miesto
    if ($cfg['target'] === 'webdav') foreach (glob(bk_dir() . '/cloud-db-*.enc') ?: [] as $f) if (basename($f) !== $name) @unlink($f);
    return $name;
}
/** Súbory: nahrá tie, ktoré na vzdialenom disku ešte nie sú (postupne, max. $seconds). Vráti [nahrané, zostáva]. */
function bk_run_files(int $seconds): array {
    $t0 = microtime(true); $done = 0;
    $F = P . 'files'; $V = P . 'file_versions'; $B = P . 'backup_files';
    while (microtime(true) - $t0 < $seconds) {
        $rows = q("SELECT x.user_id, x.stored_name, x.size FROM (SELECT user_id, stored_name, size FROM $F UNION ALL SELECT user_id, stored_name, size FROM $V) x
                   LEFT JOIN $B b ON b.stored_name = x.stored_name WHERE b.stored_name IS NULL LIMIT 50")->fetchAll();
        if (!$rows) break;
        foreach ($rows as $r) {
            $p = storage_dir((int)$r['user_id']) . '/' . $r['stored_name'];
            if (is_file($p)) {
                bk_dav_mkdir('cloud-zaloha'); bk_dav_mkdir('cloud-zaloha/subory'); bk_dav_mkdir('cloud-zaloha/subory/' . (int)$r['user_id']);
                bk_dav_put('cloud-zaloha/subory/' . (int)$r['user_id'] . '/' . $r['stored_name'], $p);
            }
            q("INSERT IGNORE INTO $B (stored_name, user_id, size) VALUES (?,?,?)", [$r['stored_name'], $r['user_id'], $r['size']]);
            $done++;
            if (microtime(true) - $t0 >= $seconds) break;
        }
    }
    $left = (int)q("SELECT COUNT(*) FROM (SELECT stored_name FROM $F UNION ALL SELECT stored_name FROM $V) x LEFT JOIN $B b ON b.stored_name = x.stored_name WHERE b.stored_name IS NULL")->fetchColumn();
    return [$done, $left];
}
/** Spustí sa najviac raz za 10 minút (pri návšteve webu): v nastavenú hodinu záloha databázy, potom postupne súbory. */
function backup_tick(bool $force = false): void {
    $cfg = bk_cfg();
    if (!$cfg['enabled'] && !$force) return;
    $run = (int)setting('backup_running', '0');
    if ($run > time() - 1800) return; // už beží
    setting_set('backup_running', (string)time());
    try {
        $st = bk_status();
        $due = $force || (($st['last_day'] ?? '') !== date('Y-m-d') && (int)date('G') >= $cfg['hour']);
        if ($due) {
            $name = bk_run_db();
            bk_status_set(['last_day' => date('Y-m-d'), 'last_ok' => date('Y-m-d H:i:s'), 'last_name' => $name, 'error' => null, 'error_at' => null]);
            log_action(null, 'backup', $name);
        }
        if ($cfg['target'] === 'webdav' && $cfg['files']) {
            [$done, $left] = bk_run_files($force ? 240 : 120);
            bk_status_set(['files_left' => $left, 'files_at' => date('Y-m-d H:i:s')] + ($done ? ['files_last' => $done] : []));
        }
    } catch (Throwable $e) {
        $st = bk_status();
        bk_status_set(['error' => $e->getMessage(), 'error_at' => date('Y-m-d H:i:s'), 'last_day' => date('Y-m-d')]);
        if (($st['notified_day'] ?? '') !== date('Y-m-d')) {
            bk_status_set(['notified_day' => date('Y-m-d')]);
            notify_admins('integrity', 'Záloha zlyhala', $e->getMessage(), 'admin.php?tab=backup');
        }
    } finally {
        setting_set('backup_running', '0');
    }
}
