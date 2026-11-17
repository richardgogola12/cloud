<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Aktualizácia servera z GitHub Releases (tagy server-vX.Y.Z)
 *  - stiahne ZIP, overí obsah, zálohuje aktuálne súbory, prepíše ich
 *  - config.php, storage/, install/, .htaccess a .user.ini sa NEPREPISUJÚ
 * ------------------------------------------------------------------ */

function gh_http(string $url, ?string $saveTo = null): string {
    $headers = ['User-Agent: Cloud-Updater/' . APP_VERSION, 'Accept: application/vnd.github+json'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fp = $saveTo ? fopen($saveTo, 'wb') : null;
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 15,
                                CURLOPT_HTTPHEADER => $headers, CURLOPT_FAILONERROR => true]);
        if ($fp) curl_setopt($ch, CURLOPT_FILE, $fp); else curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fp) fclose($fp);
        if ($out === false) throw new RuntimeException('Spojenie s GitHubom zlyhalo: ' . $err);
        return $fp ? '' : (string)$out;
    }
    $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 300, 'follow_location' => 1]]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) throw new RuntimeException('Spojenie s GitHubom zlyhalo (povoľ curl alebo allow_url_fopen).');
    if ($saveTo) { file_put_contents($saveTo, $out); return ''; }
    return $out;
}

/** @return array<int, array{version:string, tag:string, name:string, notes:string, date:string, zip:?string, apk:?string}> */
function gh_releases(string $prefix): array {
    $repo = cfg('update_repo', 'richardgogola12/cloud');
    $data = json_decode(gh_http("https://api.github.com/repos/$repo/releases?per_page=40"), true);
    if (!is_array($data)) throw new RuntimeException('Neplatná odpoveď GitHubu.');
    $out = [];
    foreach ($data as $r) {
        if (!empty($r['draft']) || !empty($r['prerelease'])) continue;
        if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d+\.\d+\.\d+)$/', (string)$r['tag_name'], $m)) continue;
        $zip = $apk = null;
        foreach ($r['assets'] ?? [] as $a) {
            if (str_ends_with($a['name'], '.zip')) $zip = $a['browser_download_url'];
            if (preg_match('/^cloud-android-\d+\.apk$/', $a['name'])) $apk = $a['browser_download_url'];
        }
        $out[] = ['version' => $m[1], 'tag' => $r['tag_name'], 'name' => (string)($r['name'] ?: $r['tag_name']), 'notes' => (string)($r['body'] ?? ''),
                  'date' => substr((string)$r['published_at'], 0, 10), 'zip' => $zip, 'apk' => $apk];
    }
    usort($out, fn($a, $b) => version_compare($b['version'], $a['version']));
    return $out;
}

function updater_skip(string $rel): bool {
    if ($rel === 'config.php' || str_starts_with($rel, 'storage/') || $rel === 'storage' || str_starts_with($rel, 'install/')) return true;
    if (in_array($rel, ['.htaccess', '.user.ini'], true) && is_file(APP_ROOT . '/' . $rel)) return true; // ponechaj vlastné nastavenia
    return false;
}

/** Vytvorí zálohu aktuálnych súborov aplikácie (bez storage/ a config.php). */
function updater_backup(): string {
    $dir = data_path('backups');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $file = $dir . '/cloud-' . APP_VERSION . '-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE) !== true) throw new RuntimeException('Nepodarilo sa vytvoriť zálohu.');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
    $root = realpath(APP_ROOT);
    $storage = realpath(cfg('storage_path')) ?: '/nonexistent';
    foreach ($it as $f) {
        $real = $f->getRealPath();
        if (!$real || str_starts_with($real, $storage . DIRECTORY_SEPARATOR)) continue;
        $rel = ltrim(str_replace('\\', '/', substr($real, strlen($root))), '/');
        if ($rel === 'config.php' || str_starts_with($rel, 'storage/')) continue;
        $zip->addFile($real, $rel);
    }
    $zip->close();
    // ponechaj posledných 5 záloh
    $all = glob($dir . '/cloud-*.zip') ?: [];
    usort($all, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($all, 5) as $old) @unlink($old);
    return $file;
}

/** Nainštaluje vydanie; vráti zoznam zmenených súborov. */
function updater_install(array $rel): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Chýba rozšírenie ZipArchive.');
    if (empty($rel['zip'])) throw new RuntimeException('Vydanie neobsahuje balík pre server.');
    if (!is_writable(APP_ROOT)) throw new RuntimeException('Priečinok aplikácie nie je zapisovateľný pre PHP – aktualizuj ručne cez FTP.');
    @set_time_limit(600);
    $tmpZip = tmp_file() . '.zip';
    gh_http($rel['zip'], $tmpZip);
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) throw new RuntimeException('Stiahnutý balík je poškodený.');

    // zisti spoločný prefix (napr. "server/")
    $prefix = '';
    if ($zip->locateName('index.php') === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^([^/]+/)index\.php$#', $n, $m)) { $prefix = $m[1]; break; }
        }
    }
    foreach (['index.php', 'inc/core.php', 'VERSION'] as $must) {
        if ($zip->locateName($prefix . $must) === false) throw new RuntimeException("Balík neobsahuje $must – nie je to balík Cloud servera.");
    }
    $newVer = trim((string)$zip->getFromName($prefix . 'VERSION'));
    if ($newVer !== $rel['version']) throw new RuntimeException("Verzia v balíku ($newVer) nesúhlasí s vydaním ({$rel['version']}).");

    $backup = updater_backup();
    $changed = [];
    $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($prefix && !str_starts_with($name, $prefix)) continue;
        $relPath = substr($name, strlen($prefix));
        if ($relPath === '' || str_ends_with($relPath, '/')) continue;
        if (str_contains($relPath, '..') || str_starts_with($relPath, '/')) continue; // ochrana pred zip-slip
        if (updater_skip($relPath)) continue;
        $data = $zip->getFromIndex($i);
        if ($data === false) { $errors[] = $relPath; continue; }
        $dest = APP_ROOT . '/' . $relPath;
        if (is_file($dest) && hash('sha256', $data) === hash_file('sha256', $dest)) continue;
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) { $errors[] = $relPath; continue; }
        $tmp = $dest . '.upd-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $data) === false || !@rename($tmp, $dest)) { @unlink($tmp); $errors[] = $relPath; continue; }
        $changed[] = $relPath;
    }
    $zip->close();
    @unlink($tmpZip);
    if (function_exists('opcache_reset')) @opcache_reset();
    if ($errors) throw new RuntimeException('Niektoré súbory sa nepodarilo zapísať (' . implode(', ', array_slice($errors, 0, 5)) . '). Záloha: ' . basename($backup));
    log_action((int)(current_user()['id'] ?? 0) ?: null, 'server_update', APP_VERSION . ' → ' . $rel['version'] . ' (' . count($changed) . ' súborov)');
    return $changed;
}
