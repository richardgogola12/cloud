<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Údržba (iba admin): šifrovanie existujúcich súborov, kontrola integrity, osirelé súbory
 *  Všetko beží po dávkach cez AJAX, aby to neprekročilo časový limit PHP.
 * ------------------------------------------------------------------ */

/** Spoločný zoznam uložených objektov: súbory aj staršie verzie. */
function maint_rows(string $where, int $afterKey, int $limit): array {
    // kľúč = id * 2 (+1 pre verzie) – jednoduché stránkovanie cez obe tabuľky
    $fAfter = intdiv($afterKey, 2);                                  // súbory: kľúč 2*id
    $vAfter = $afterKey % 2 === 1 ? intdiv($afterKey, 2) : intdiv($afterKey, 2) - 1; // verzie: kľúč 2*id+1
    $f = q('SELECT id, user_id, name, stored_name, size, mime, enc_key, sha256, \'f\' AS kind, id AS fid FROM ' . P . "files WHERE $where AND id > ? ORDER BY id LIMIT $limit", [$fAfter])->fetchAll();
    $v = q('SELECT v.id, v.user_id, f.name, v.stored_name, v.size, v.mime, v.enc_key, v.sha256, \'v\' AS kind, v.file_id AS fid FROM ' . P . 'file_versions v JOIN ' . P . "files f ON f.id = v.file_id WHERE " . str_replace(['enc_key', 'sha256'], ['v.enc_key', 'v.sha256'], $where) . " AND v.id > ? ORDER BY v.id LIMIT $limit", [$vAfter])->fetchAll();
    foreach ($f as &$r) $r['_k'] = (int)$r['id'] * 2;
    foreach ($v as &$r) $r['_k'] = (int)$r['id'] * 2 + 1;
    unset($r);
    $all = array_merge($f, $v);
    usort($all, fn($a, $b) => $a['_k'] <=> $b['_k']);
    return array_slice($all, 0, $limit);
}
function maint_table(array $r): string { return $r['kind'] === 'f' ? P . 'files' : P . 'file_versions'; }

function maint_crypto_stats(): array {
    $one = fn($sql) => (int)q($sql)->fetchColumn();
    return [
        'enc'    => $one('SELECT COUNT(*) FROM ' . P . 'files WHERE enc_key IS NOT NULL') + $one('SELECT COUNT(*) FROM ' . P . 'file_versions WHERE enc_key IS NOT NULL'),
        'plain'  => $one('SELECT COUNT(*) FROM ' . P . 'files WHERE enc_key IS NULL') + $one('SELECT COUNT(*) FROM ' . P . 'file_versions WHERE enc_key IS NULL'),
        'nohash' => $one('SELECT COUNT(*) FROM ' . P . 'files WHERE sha256 IS NULL') + $one('SELECT COUNT(*) FROM ' . P . 'file_versions WHERE sha256 IS NULL'),
    ];
}

/** Zašifruje ďalšiu dávku nešifrovaných súborov. */
function maint_encrypt_batch(float $budget = 20.0): array {
    $t0 = microtime(true); $done = 0; $errors = [];
    $after = 0;
    while (microtime(true) - $t0 < $budget) {
        $rows = maint_rows('enc_key IS NULL', $after, 10);
        if (!$rows) break;
        foreach ($rows as $r) {
            $after = $r['_k'];
            $path = stored_path($r);
            if (!is_file($path)) { $errors[] = $r['name'] . ': chýba na disku'; continue; }
            try {
                $sha = hash_file('sha256', $path);
                $wrapped = crypto_encrypt_stored($r);
                q('UPDATE ' . maint_table($r) . ' SET enc_key = ?, sha256 = ? WHERE id = ?', [$wrapped, $sha, $r['id']]);
                @unlink(data_path('thumbs/' . $r['user_id'] . '/' . $r['stored_name'] . '.jpg')); // nešifrovaná miniatúra preč
                $done++;
            } catch (Throwable $e) { $errors[] = $r['name'] . ': ' . $e->getMessage(); }
            if (microtime(true) - $t0 >= $budget) break;
        }
    }
    $left = maint_crypto_stats()['plain'];
    // chýbajúce súbory sa nedajú zašifrovať – nepočítaj ich ako zostávajúce donekonečna
    return ['done' => $done, 'left' => $left, 'errors' => $errors, 'finished' => $done === 0];
}

/**
 * Kontrola integrity jednej dávky. mode: quick = existencia a veľkosť, deep = aj obsah (dešifrovanie + SHA-256).
 * @return array{next:int, checked:int, problems:array, finished:bool}
 */
function maint_check_batch(string $mode, int $after, float $budget = 20.0): array {
    $t0 = microtime(true); $checked = 0; $problems = [];
    $limit = $mode === 'deep' ? 10 : 200;
    $finished = false;
    while (microtime(true) - $t0 < $budget) {
        $rows = maint_rows('1=1', $after, $limit);
        if (!$rows) { $finished = true; break; }
        foreach ($rows as $r) {
            $after = $r['_k'];
            $checked++;
            $label = $r['name'] . ($r['kind'] === 'v' ? ' (stará verzia)' : '');
            $owner = q('SELECT email FROM ' . P . 'users WHERE id = ?', [$r['user_id']])->fetchColumn() ?: '?';
            $path = stored_path($r);
            $prob = null;
            if (!is_file($path)) $prob = 'Súbor chýba na disku';
            else {
                $expected = !empty($r['enc_key']) ? crypto_cipher_size((int)$r['size']) : (int)$r['size'];
                if (filesize($path) !== $expected) $prob = 'Nesprávna veľkosť na disku (' . format_bytes(filesize($path)) . ' namiesto ' . format_bytes($expected) . ')';
                elseif ($mode === 'deep') {
                    try {
                        $sha = stored_sha256($r);
                        if ($r['sha256'] && $sha !== $r['sha256']) $prob = 'Obsah sa nezhoduje s kontrolným súčtom';
                        elseif (!$r['sha256']) q('UPDATE ' . maint_table($r) . ' SET sha256 = ? WHERE id = ?', [$sha, $r['id']]);
                    } catch (Throwable $e) { $prob = $e->getMessage(); }
                }
            }
            if ($prob) $problems[] = ['kind' => $r['kind'], 'id' => (int)$r['id'], 'file' => $label, 'owner' => $owner, 'problem' => $prob, 'missing' => !is_file($path)];
            if (microtime(true) - $t0 >= $budget) break 2;
        }
    }
    return ['next' => $after, 'checked' => $checked, 'problems' => $problems, 'finished' => $finished];
}

/** Súbory na disku, ktoré nepatria žiadnemu záznamu v DB. */
function maint_orphans(): array {
    $base = data_path('files');
    $out = []; $bytes = 0;
    if (!is_dir($base)) return ['items' => [], 'bytes' => 0];
    $known = [];
    foreach (q('SELECT stored_name FROM ' . P . 'files UNION ALL SELECT stored_name FROM ' . P . 'file_versions')->fetchAll(PDO::FETCH_COLUMN) as $s) $known[$s] = true;
    foreach (scandir($base) ?: [] as $uid) {
        if (!ctype_digit($uid)) continue;
        foreach (scandir("$base/$uid") ?: [] as $f) {
            if ($f === '.' || $f === '..' || isset($known[$f])) continue;
            $p = "$base/$uid/$f";
            if (!is_file($p) || filemtime($p) > time() - 3600) continue; // čerstvé súbory môžu byť práve nahrávané
            $out[] = ['path' => "$uid/$f", 'size' => filesize($p)];
            $bytes += filesize($p);
        }
    }
    return ['items' => $out, 'bytes' => $bytes];
}

/* ================== Stav servera (Raspberry Pi aj bežný Linux) ================== */
function server_health(): array {
    $read = function (string $f): ?string { return @is_readable($f) ? (@file_get_contents($f) ?: null) : null; };
    $h = ['temp' => null, 'load' => null, 'cores' => null, 'mem_total' => null, 'mem_used' => null, 'swap_total' => null, 'swap_used' => null, 'uptime' => null];
    // teplota CPU (°C) – prvá teplotná zóna, ktorá vyzerá ako CPU
    foreach (glob('/sys/class/thermal/thermal_zone*') ?: [] as $z) {
        $t = $read("$z/temp");
        if ($t !== null && is_numeric(trim($t)) && (int)$t > 0) { $h['temp'] = round((int)$t / 1000, 1); break; }
    }
    if (function_exists('sys_getloadavg')) { $l = @sys_getloadavg(); if ($l) $h['load'] = array_map(fn($x) => round($x, 2), $l); }
    if ($c = $read('/proc/cpuinfo')) $h['cores'] = max(1, substr_count($c, "\nprocessor") + (str_starts_with($c, 'processor') ? 1 : 0));
    if ($m = $read('/proc/meminfo')) {
        preg_match_all('/^(\w+):\s+(\d+) kB/m', $m, $mm);
        $mi = array_combine($mm[1], array_map(fn($v) => (int)$v * 1024, $mm[2]));
        if (isset($mi['MemTotal'])) {
            $h['mem_total'] = $mi['MemTotal'];
            $h['mem_used'] = $mi['MemTotal'] - ($mi['MemAvailable'] ?? ($mi['MemFree'] + ($mi['Cached'] ?? 0)));
        }
        if (!empty($mi['SwapTotal'])) { $h['swap_total'] = $mi['SwapTotal']; $h['swap_used'] = $mi['SwapTotal'] - ($mi['SwapFree'] ?? 0); }
    }
    if ($u = $read('/proc/uptime')) $h['uptime'] = (int)explode(' ', $u)[0];
    $model = $read('/proc/device-tree/model') ?: $read('/sys/firmware/devicetree/base/model');
    $h['model'] = $model ? trim(str_replace("\0", '', $model)) : null;
    $h['os'] = php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')';
    if ($osr = $read('/etc/os-release')) { if (preg_match('/^PRETTY_NAME="?([^"\n]+)/m', $osr, $o)) $h['os'] = $o[1] . ' · ' . php_uname('m'); }
    $h['php'] = PHP_VERSION;
    $h['web'] = $_SERVER['SERVER_SOFTWARE'] ?? null;
    try { $h['db'] = db()->query('SELECT VERSION()')->fetchColumn(); } catch (Throwable $e) { $h['db'] = null; }
    try {
        $h['db_size'] = (int)db()->query("SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '" . str_replace('_', '\\_', P) . "%'")->fetchColumn();
    } catch (Throwable $e) { $h['db_size'] = null; }
    $h['ffmpeg'] = ffmpeg_bin() !== null;
    $h['ffmpeg_why'] = $h['ffmpeg'] ? null : ffmpeg_why();
    // OPcache – bez neho PHP pri každej požiadavke znova spracúva všetky skripty
    $h['opcache'] = null;
    if (function_exists('opcache_get_status')) {
        $st = @opcache_get_status(false);
        if (is_array($st)) {
            $hits = (float)($st['opcache_statistics']['hits'] ?? 0); $miss = (float)($st['opcache_statistics']['misses'] ?? 0);
            $h['opcache'] = ['on' => !empty($st['opcache_enabled']), 'hit' => $hits + $miss > 0 ? round($hits / ($hits + $miss) * 100) : null,
                             'mem' => (int)($st['memory_usage']['used_memory'] ?? 0)];
        } else $h['opcache'] = ['on' => false, 'hit' => null, 'mem' => 0];
    }
    $h['storage_dev'] = storage_device((string)cfg('storage_path'));
    return $h;
}

/** Na akom zariadení je úložisko: sd (SD karta), usb, ssd (NVMe), hdd, alebo null. */
function storage_device(string $path): ?array {
    $real = realpath($path) ?: $path;
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES);
    if (!$mounts) return null;
    $best = null; $bestLen = -1;
    foreach ($mounts as $line) {
        $p = explode(' ', $line);
        if (count($p) < 3) continue;
        $mp = str_replace('\\040', ' ', $p[1]);
        if (($real === $mp || str_starts_with($real, rtrim($mp, '/') . '/') || $mp === '/') && strlen($mp) > $bestLen) { $best = $p; $bestLen = strlen($mp); }
    }
    if (!$best) return null;
    $dev = $best[0];
    if ($dev === '/dev/root') { // Raspberry Pi OS: zistiť skutočné zariadenie z parametrov jadra
        $cmd = (string)@file_get_contents('/proc/cmdline');
        if (preg_match('/root=(\S+)/', $cmd, $m)) {
            $dev = $m[1];
            if (str_starts_with($dev, 'PARTUUID=')) { $l = @readlink('/dev/disk/by-partuuid/' . strtolower(substr($dev, 9))); if ($l) $dev = '/dev/' . basename($l); }
        }
    }
    $base = basename($dev);
    $type = null;
    if (str_starts_with($base, 'mmcblk')) $type = 'sd';
    elseif (str_starts_with($base, 'nvme')) $type = 'ssd';
    elseif (preg_match('/^sd([a-z]+)/', $base, $m)) {
        $rot = trim((string)@file_get_contents('/sys/block/sd' . $m[1] . '/queue/rotational'));
        $type = $rot === '1' ? 'hdd' : 'usb';
    }
    return ['dev' => $dev, 'mount' => $best[1], 'fs' => $best[2], 'type' => $type];
}

/** Odporúčania na zrýchlenie podľa stavu servera. */
function speed_tips(array $h): array {
    $t = [];
    if ($h['opcache'] === null || !$h['opcache']['on']) $t[] = ['danger', 'OPcache je vypnutý – zapni ho v php.ini (<code>opcache.enable=1</code>) a reštartuj PHP. Stránky budú 2–3× rýchlejšie.'];
    if (version_compare(PHP_VERSION, '8.2', '<')) $t[] = ['warning', 'PHP ' . e(PHP_VERSION) . ' je staršie – novšie PHP (8.2+) je citeľne rýchlejšie.'];
    if (($h['storage_dev']['type'] ?? null) === 'sd') $t[] = ['warning', 'Súbory sú na <b>SD karte</b> (' . e($h['storage_dev']['dev']) . '). USB disk alebo SSD je pri Raspberry Pi niekoľkonásobne rýchlejší a SD karta sa častým zápisom opotrebúva.'];
    if (($h['storage_dev']['type'] ?? null) === 'hdd') $t[] = ['info', 'Súbory sú na klasickom pevnom disku – SSD by zrýchlil najmä miniatúry a hľadanie duplicít.'];
    if (!$h['ffmpeg']) $t[] = ['info', 'Bez ffmpeg sa nevytvárajú miniatúry videí (<code>sudo apt install ffmpeg</code>).'];
    if ($h['mem_total'] && $h['mem_used'] / $h['mem_total'] > 0.9) $t[] = ['warning', 'Pamäť RAM je takmer plná – server môže spomaľovať.'];
    if (($h['temp'] ?? 0) >= 80) $t[] = ['danger', 'Procesor je prehriaty a spomaľuje sa – pridaj chladič alebo ventilátor.'];
    return $t;
}

function fmt_uptime(int $s): string {
    $d = intdiv($s, 86400); $hh = intdiv($s % 86400, 3600); $mi = intdiv($s % 3600, 60);
    return ($d ? $d . ' d ' : '') . $hh . ' h ' . $mi . ' min';
}

/** Prečo server nevidí ffmpeg – konkrétna príčina pre administrátora. */
function ffmpeg_why(): string {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true))
        return 'PHP má zakázanú funkciu <code>exec</code> (disable_functions v ' . e((string)php_ini_loaded_file()) . ').';
    $ob = (string)ini_get('open_basedir');
    if (is_file('/usr/bin/ffmpeg') || @file_exists('/usr/bin/ffmpeg')) {
        if (!@is_executable('/usr/bin/ffmpeg')) return $ob !== '' ? 'Súbor /usr/bin/ffmpeg existuje, ale <code>open_basedir</code> (' . e($ob) . ') bráni PHP ho použiť.' : 'Súbor /usr/bin/ffmpeg nemá právo na spustenie pre používateľa webservera.';
    }
    $out = []; @exec('ls -l /usr/bin/ffmpeg /usr/local/bin/ffmpeg 2>&1', $out);
    if ($out && str_contains(implode(' ', $out), 'ffmpeg') && !str_contains(implode(' ', $out), 'No such')) return 'ffmpeg existuje (' . e(implode('; ', $out)) . '), ale PHP ho nevie spustiť' . ($ob !== '' ? ' – skontroluj <code>open_basedir</code> (' . e($ob) . ')' : '') . '.';
    return 'Webserver ffmpeg nenašiel v /usr/bin ani /usr/local/bin. Nainštaluj ho na <b>tomto</b> serveri: <code>sudo apt install ffmpeg</code> a potom <code>sudo systemctl restart apache2</code>.';
}

/** Čo chýba pre webový terminál (null = všetko je v poriadku). */
function term_requirements(): ?string {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) return 'PHP má zakázanú funkciu <code>exec</code> (disable_functions v ' . e((string)php_ini_loaded_file()) . ').';
    if (!ffmpeg_bin('python3')) return 'Chýba <code>python3</code> – nainštaluj ho: <code>sudo apt install python3</code>.';
    if (!ffmpeg_bin('ssh')) return 'Chýba SSH klient – nainštaluj ho: <code>sudo apt install openssh-client</code>.';
    $d = data_path('term');
    if (!is_dir($d)) @mkdir($d, 0700, true);
    if (!is_dir($d) || !is_writable($d)) return 'Priečinok <code>' . e($d) . '</code> nie je zapisovateľný.';
    return null;
}
/** Upratanie starých relácií terminálu. */
function term_cleanup(): void {
    foreach (glob(rtrim(data_path('term'), '/') . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (!preg_match('#/[a-f0-9]{32}$#', $d)) continue;
        $alive = @filemtime("$d/alive") ?: @filemtime($d);
        if (is_file("$d/ended") || time() - $alive > 3600) {
            if (!is_file("$d/ended")) { @touch("$d/close"); if (time() - $alive < 7200) continue; }
            foreach (['in', 'out', 'pid', 'alive', 'resize', 'close', 'ended'] as $f) @unlink("$d/$f");
            @rmdir($d);
        }
    }
}
