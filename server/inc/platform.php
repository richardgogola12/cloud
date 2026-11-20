<?php
/* Na čom Cloud beží (Raspberry Pi / Linux / Docker / Windows; Apache / nginx / IIS …) a ako sa tomu prispôsobí. */

function is_windows(): bool { return PHP_OS_FAMILY === 'Windows'; }
/** Kam zahodiť výstup programu v príkazovom riadku. */
function null_dev(): string { return is_windows() ? 'NUL' : '/dev/null'; }

/** Druh webového servera: apache, nginx, iis, litespeed, caddy, builtin alebo other. */
function web_server_kind(): string {
    $s = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    if (PHP_SAPI === 'cli-server') return 'builtin';
    if (str_contains($s, 'microsoft-iis') || isset($_SERVER['IIS_WasUrlRewritten']) || isset($_SERVER['APP_POOL_ID'])) return 'iis';
    if (str_contains($s, 'apache') || PHP_SAPI === 'apache2handler') return 'apache';
    if (str_contains($s, 'nginx')) return 'nginx';
    if (str_contains($s, 'litespeed')) return 'litespeed';
    if (str_contains($s, 'caddy')) return 'caddy';
    return 'other';
}
function web_server_label(): string {
    $soft = trim((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    $names = ['apache' => 'Apache', 'nginx' => 'nginx', 'iis' => 'IIS (Microsoft)', 'litespeed' => 'LiteSpeed', 'caddy' => 'Caddy', 'builtin' => 'vstavaný server PHP', 'other' => 'webový server'];
    $k = web_server_kind();
    if (preg_match('#^[\w.-]+/([\d.]+)#', $soft, $m) && $k !== 'builtin') return $names[$k] . ' ' . $m[1];
    return $names[$k] . ($k === 'other' && $soft !== '' ? ' (' . $soft . ')' : '');
}
/** Ako PHP beží pod webovým serverom. */
function php_mode_label(): string {
    return ['apache2handler' => 'modul Apache (mod_php)', 'fpm-fcgi' => 'PHP-FPM', 'cgi-fcgi' => 'FastCGI', 'cli-server' => 'vstavaný server', 'litespeed' => 'LSAPI', 'cli' => 'príkazový riadok'][PHP_SAPI] ?? PHP_SAPI;
}

/** Hlavné zistenie platformy (lacné – bez spúšťania programov). */
function platform_detect(): array {
    static $p = null;
    if ($p !== null) return $p;
    $read = fn(string $f): ?string => @is_readable($f) ? (trim(str_replace("\0", '', (string)@file_get_contents($f))) ?: null) : null;
    $p = ['os' => 'other', 'os_label' => php_uname('s') . ' ' . php_uname('r'), 'arch' => php_uname('m'), 'device' => null, 'docker' => false];
    if (is_windows()) {
        $p['os'] = 'windows';
        $p['os_label'] = 'Windows ' . php_uname('r') . ' (zostava ' . php_uname('v') . ')';
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $p['os'] = 'macos'; $p['os_label'] = 'macOS ' . php_uname('r');
    } elseif (PHP_OS_FAMILY === 'Linux') {
        $p['os'] = 'linux';
        if (($osr = $read('/etc/os-release')) && preg_match('/^PRETTY_NAME="?([^"\n]+)/m', $osr, $o)) $p['os_label'] = $o[1];
        $p['device'] = $read('/proc/device-tree/model') ?: $read('/sys/firmware/devicetree/base/model');
        if (!$p['device'] && ($ci = $read('/proc/cpuinfo')) && preg_match('/^Model\s*:\s*(.+)$/m', $ci, $m)) $p['device'] = trim($m[1]);
        if ($p['device'] && stripos($p['device'], 'raspberry') !== false) $p['os'] = 'raspberry';
        $p['docker'] = in_docker();
    }
    return $p;
}

/** Popis platformy pre Prehľad v administrácii: čo beží, či je úložisko chránené a čo Cloud prispôsobil. */
function platform_info(): array {
    $p = platform_detect();
    $p['web'] = web_server_kind();
    $p['web_label'] = web_server_label();
    $p['php_mode'] = php_mode_label();
    $p['icon'] = ['windows' => 'bi-windows', 'raspberry' => 'bi-cpu', 'linux' => 'bi-ubuntu', 'macos' => 'bi-apple'][$p['os']] ?? 'bi-hdd-rack';
    $p['title'] = ['windows' => 'Windows server', 'raspberry' => 'Raspberry Pi', 'linux' => 'Linux server', 'macos' => 'Mac'][$p['os']] ?? 'Server';
    $p['protect'] = storage_protection();
    $tools = [];
    foreach (['ffmpeg' => 'miniatúry a dátum videí', 'ffprobe' => 'dátum natočenia videí', 'pdftotext' => 'hľadanie v PDF'] as $t => $why) $tools[$t] = ['path' => ffmpeg_bin($t), 'why' => $why, 'hint' => tool_install_hint($t)];
    $p['tools'] = $tools;
    $p['exec'] = exec_allowed();
    // čo sa podľa platformy nastavilo samo
    $a = [];
    if ($p['os'] === 'windows') {
        $a[] = 'Programy (ffmpeg, pdftotext) sa hľadajú ako .exe v PATH a v bežných priečinkoch (C:\\ffmpeg\\bin, C:\\poppler\\Library\\bin, Program Files).';
        $a[] = 'Stav servera (RAM, procesor, čas behu, typ disku) sa číta cez Windows (CIM / PowerShell). Teplotu procesora Windows bežne neposkytuje.';
        $a[] = 'Výstup programov sa zahadzuje do NUL namiesto /dev/null.';
    } else {
        $a[] = 'Stav servera sa číta zo systému Linux (/proc, /sys)' . ($p['os'] === 'raspberry' ? ' vrátane teploty procesora a typu úložiska (SD karta / USB / SSD).' : '.');
        if ($p['docker']) $a[] = 'Beží v Docker kontajneri – rady na inštaláciu programov sú pre kontajner (docker exec), nie pre hostiteľa.';
    }
    $a[] = match ($p['web']) {
        'iis' => 'IIS: pravidlá sú v súboroch web.config (zakázané priečinky inc/ a storage/, typy súborov, veľkosť nahrávania).',
        'apache', 'litespeed' => 'Apache: pravidlá sú v súboroch .htaccess (zakázané priečinky inc/ a storage/, kompresia, hlavička pre WebDAV).',
        'nginx' => 'nginx nečíta .htaccess ani web.config – ochranu priečinkov treba mať v konfigurácii nginx (pozri nižšie).',
        'builtin' => 'Vstavaný server PHP je iba na skúšanie, nie na ostrú prevádzku.',
        default => 'Neznámy webový server – skontroluj, či nie sú verejne dostupné priečinky inc/ a storage/.',
    };
    $p['adapt'] = $a;
    return $p;
}

/** Ako nainštalovať program na tejto platforme (HTML). */
function tool_install_hint(string $tool): string {
    $pkg = ['ffmpeg' => 'ffmpeg', 'ffprobe' => 'ffmpeg', 'pdftotext' => 'poppler-utils'][$tool] ?? $tool;
    if (is_windows()) {
        return $pkg === 'ffmpeg'
            ? 'stiahni ffmpeg pre Windows (gyan.dev → „release essentials“), rozbaľ ho tak, aby bol <code>C:\\ffmpeg\\bin\\ffmpeg.exe</code>, a reštartuj webový server'
            : 'stiahni „Poppler for Windows“ (github.com/oschwartz10612/poppler-windows), rozbaľ ho tak, aby bol <code>C:\\poppler\\Library\\bin\\pdftotext.exe</code>, a reštartuj webový server';
    }
    if (in_docker()) return 'v kontajneri: <code>docker exec -u 0 &lt;kontajner&gt; sh -c "apt-get update &amp;&amp; apt-get install -y ' . e($pkg) . '"</code>';
    if (PHP_OS_FAMILY === 'Darwin') return '<code>brew install ' . e($pkg === 'poppler-utils' ? 'poppler' : $pkg) . '</code>';
    return '<code>sudo apt install ' . e($pkg) . '</code>';
}

/** Je priečinok so súbormi chránený pred priamym stiahnutím cez web? */
function storage_protection(): array {
    $root = realpath(APP_ROOT); $sp = realpath((string)cfg('storage_path'));
    if (!$root || !$sp) return ['level' => 'warning', 'text' => 'Priečinok úložiska sa nepodarilo nájsť.'];
    $norm = fn(string $x) => rtrim(str_replace('\\', '/', is_windows() ? strtolower($x) : $x), '/') . '/';
    if (!str_starts_with($norm($sp), $norm($root))) return ['level' => 'success', 'text' => 'Úložisko je mimo webového priečinka – cez web sa k nemu nedá dostať.'];
    $probe = storage_probe();
    if ($probe === true) return ['level' => 'danger', 'text' => 'Súbory v úložisku sa dajú stiahnuť priamo cez web! ' . storage_fix_hint()];
    $how = match (web_server_kind()) {
        'iis' => is_file($sp . '/web.config') || is_file(APP_ROOT . '/web.config') ? 'chránené pravidlami vo web.config' : null,
        'apache', 'litespeed' => is_file($sp . '/.htaccess') ? 'chránené pravidlami v .htaccess' : null,
        default => null,
    };
    if ($probe === false) return ['level' => 'success', 'text' => 'Úložisko je chránené – skúška priameho stiahnutia cez web bola odmietnutá' . ($how ? ' (' . $how . ').' : '.')];
    if ($how) return ['level' => 'success', 'text' => 'Úložisko je ' . $how . '.'];
    return ['level' => 'warning', 'text' => 'Nedá sa overiť, či je úložisko chránené. ' . storage_fix_hint()];
}
function storage_fix_hint(): string {
    return match (web_server_kind()) {
        'nginx' => 'Doplň do konfigurácie nginx: <code>location ~ ^/(.*/)?(inc|storage)/ { deny all; return 404; }</code> a <code>location ~ /(config|secret\.key)\.php$ { deny all; }</code>, alebo presuň úložisko mimo webového priečinka (<code>storage_path</code> v config.php).',
        'iis' => 'Skontroluj, či je v priečinku aplikácie súbor <code>web.config</code> (je súčasťou balíka), alebo presuň úložisko mimo webového priečinka (<code>storage_path</code> v config.php).',
        'apache', 'litespeed' => 'Povoľ v Apache <code>AllowOverride All</code> pre priečinok aplikácie (inak sa .htaccess ignoruje), alebo presuň úložisko mimo webového priečinka.',
        default => 'Najistejšie je presunúť úložisko mimo webového priečinka (<code>storage_path</code> v config.php).',
    };
}
/** Skúsi si cez web stiahnuť skúšobný súbor z úložiska: true = dostupný (zle), false = odmietnutý (dobre), null = nedá sa zistiť. Výsledok platí 6 hodín. */
function storage_probe(): ?bool {
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST']) || !function_exists('curl_init')) return null;
    $cached = setting('storage_probe', '');
    if ($cached !== '' && ($c = json_decode($cached, true)) && ($c['t'] ?? 0) > time() - 6 * 3600 && ($c['host'] ?? '') === $_SERVER['HTTP_HOST']) return $c['r'];
    $root = str_replace('\\', '/', (string)realpath(APP_ROOT)); $sp = str_replace('\\', '/', (string)realpath((string)cfg('storage_path')));
    $rel = ltrim(substr($sp, strlen($root)), '/');
    $name = 'probe-' . bin2hex(random_bytes(6)) . '.txt'; $token = bin2hex(random_bytes(8));
    $r = null;
    if (@file_put_contents($sp . '/' . $name, $token) !== false) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $url = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . app_base_path() . '/' . $rel . '/' . $name;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Cloud-selfcheck']);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($body !== false && $code > 0) $r = $code === 200 && trim((string)$body) === $token;
        @unlink($sp . '/' . $name);
    }
    if ($r !== null) setting_set('storage_probe', json_encode(['t' => time(), 'r' => $r, 'host' => $_SERVER['HTTP_HOST']]));
    return $r;
}

/* ---------- Windows: stav servera cez CIM (PowerShell), výsledok sa drží pár sekúnd ---------- */
function win_health(string $storagePath): array {
    static $mem = null;
    if ($mem !== null) return $mem;
    $cacheF = data_path('tmp/win-health.json');
    if (is_file($cacheF) && filemtime($cacheF) > time() - 20 && ($j = json_decode((string)@file_get_contents($cacheF), true))) return $mem = $j;
    $out = ['cores' => (int)getenv('NUMBER_OF_PROCESSORS') ?: null];
    if (exec_allowed()) {
        $drive = preg_match('/^([A-Za-z]):/', (string)realpath($storagePath), $m) ? strtoupper($m[1]) : 'C';
        $ps = '$ErrorActionPreference="SilentlyContinue";'
            . '$o=Get-CimInstance Win32_OperatingSystem;$c=Get-CimInstance Win32_ComputerSystem;'
            . '$l=(Get-CimInstance Win32_Processor|Measure-Object -Property LoadPercentage -Average).Average;'
            . '$r=[ordered]@{os=$o.Caption;mt=[int64]$o.TotalVisibleMemorySize*1024;mf=[int64]$o.FreePhysicalMemory*1024;'
            . 'up=[int64]((Get-Date)-$o.LastBootUpTime).TotalSeconds;cores=$c.NumberOfLogicalProcessors;model=("$($c.Manufacturer) $($c.Model)").Trim();cpu=$l;'
            . 'pt=[int64]$o.SizeStoredInPagingFiles*1024;pf=[int64]$o.FreeSpaceInPagingFiles*1024};'
            . 'try{$v=Get-CimInstance Win32_LogicalDisk -Filter "DeviceID=\'' . $drive . ':\'";$r.fs=$v.FileSystem}catch{};'
            . 'try{$d=Get-Partition -DriveLetter ' . $drive . '|Get-Disk|Get-PhysicalDisk|Select-Object -First 1;$r.media=[string]$d.MediaType;$r.bus=[string]$d.BusType}catch{};'
            . '$r|ConvertTo-Json -Compress';
        $enc = base64_encode(mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8'));
        $j = json_decode(run_timeout('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $enc, 8), true);
        if (is_array($j)) {
            $out = ['os' => $j['os'] ?? null, 'mem_total' => (int)($j['mt'] ?? 0) ?: null, 'mem_free' => (int)($j['mf'] ?? 0), 'uptime' => (int)($j['up'] ?? 0) ?: null,
                    'cores' => (int)($j['cores'] ?? 0) ?: $out['cores'], 'model' => trim((string)($j['model'] ?? '')) ?: null, 'cpu' => isset($j['cpu']) ? (float)$j['cpu'] : null,
                    'swap_total' => (int)($j['pt'] ?? 0) ?: null, 'swap_free' => (int)($j['pf'] ?? 0), 'drive' => $drive . ':', 'fs' => $j['fs'] ?? null,
                    'media' => $j['media'] ?? null, 'bus' => $j['bus'] ?? null];
        }
    }
    if (is_dir(dirname($cacheF))) @file_put_contents($cacheF, json_encode($out));
    return $mem = $out;
}

/** Spustí príkaz a po $sec sekundách ho ukončí (aby pomalý systémový príkaz nezablokoval stránku). Vráti výstup. */
function run_timeout(string $cmd, int $sec): string {
    if (!function_exists('proc_open')) { $o = []; @exec($cmd . ' 2>' . null_dev(), $o); return implode("\n", $o); }
    $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', null_dev(), 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($p)) return '';
    stream_set_blocking($pipes[1], false);
    $out = ''; $t0 = microtime(true);
    while (true) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk !== false && $chunk !== '') $out .= $chunk;
        $st = proc_get_status($p);
        if (!$st['running']) { $out .= (string)stream_get_contents($pipes[1]); break; }
        if (microtime(true) - $t0 > $sec) { proc_terminate($p); break; }
        usleep(50000);
    }
    fclose($pipes[1]); proc_close($p);
    return $out;
}
