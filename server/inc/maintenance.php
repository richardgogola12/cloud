<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Údržba (iba admin): šifrovanie existujúcich súborov, kontrola integrity, osirelé súbory
 *  Všetko beží po dávkach cez AJAX, aby to neprekročilo časový limit PHP.
 * ------------------------------------------------------------------ */

/** Spoločný zoznam uložených objektov: súbory aj staršie verzie. */
function maint_rows(string $where, int $afterKey, int $limit): array {
    // kľúč = id * 2 (+1 pre verzie) – jednoduché stránkovanie cez obe tabuľky
    $f = q('SELECT id, user_id, name, stored_name, size, mime, enc_key, sha256, \'f\' AS kind, id AS fid FROM ' . P . "files WHERE $where AND id * 2 > ? ORDER BY id LIMIT $limit", [$afterKey])->fetchAll();
    $v = q('SELECT v.id, v.user_id, f.name, v.stored_name, v.size, v.mime, v.enc_key, v.sha256, \'v\' AS kind, v.file_id AS fid FROM ' . P . 'file_versions v JOIN ' . P . "files f ON f.id = v.file_id WHERE " . str_replace(['enc_key', 'sha256'], ['v.enc_key', 'v.sha256'], $where) . " AND v.id * 2 + 1 > ? ORDER BY v.id LIMIT $limit", [$afterKey])->fetchAll();
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
