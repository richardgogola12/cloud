<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Šifrovanie súborov na disku (AES-256-GCM po blokoch po 64 KB)
 *
 *  - každý súbor má vlastný náhodný kľúč; ten je zašifrovaný hlavným kľúčom
 *    a uložený v DB (stĺpec enc_key) – "envelope encryption"
 *  - hlavný kľúč: config.php ('encryption_key') alebo automaticky vytvorený súbor secret.key.php
 *  - formát súboru: "CLDE1\0" + 8 B náhodný prefix nonce, potom bloky: šifra(64 KB) + 16 B tag
 *    nonce bloku = prefix + číslo bloku; AAD = číslo bloku + príznak posledného bloku
 *    → dá sa čítať od ľubovoľného miesta (video, pokračovanie sťahovania) a odhalí sa každá zmena či skrátenie
 * ------------------------------------------------------------------ */

const ENC_MAGIC = "CLDE1\0";
const ENC_HEADER = 14;          // magic (6) + nonce prefix (8)
const ENC_BLOCK = 65536;        // veľkosť bloku otvoreného textu
const ENC_TAG = 16;

function crypto_master_file(): string { return APP_ROOT . '/secret.key.php'; }

/** Hlavný kľúč (32 B). Ak neexistuje, vytvorí sa. */
function crypto_master_key(): string {
    static $key = null;
    if ($key !== null) return $key;
    $cfg = (string)cfg('encryption_key', '');
    if ($cfg !== '') {
        $k = base64_decode($cfg, true);
        if ($k === false || strlen($k) !== 32) throw new RuntimeException('Neplatný encryption_key v config.php (musí byť base64 z 32 bajtov).');
        return $key = $k;
    }
    $f = crypto_master_file();
    if (is_file($f)) {
        $k = base64_decode((string)(include $f), true);
        if ($k === false || strlen($k) !== 32) throw new RuntimeException('Súbor secret.key.php je poškodený.');
        return $key = $k;
    }
    $k = random_bytes(32);
    $php = "<?php\n// Hlavný šifrovací kľúč Cloudu – ZÁLOHUJ HO! Bez neho sa súbory nedajú prečítať.\nreturn '" . base64_encode($k) . "';\n";
    if (@file_put_contents($f, $php, LOCK_EX) === false) throw new RuntimeException('Nedá sa vytvoriť secret.key.php – priečinok aplikácie nie je zapisovateľný.');
    @chmod($f, 0640);
    log_action(null, 'crypto_key_created', 'Vytvorený nový hlavný šifrovací kľúč');
    return $key = $k;
}
function crypto_fingerprint(): string { return strtoupper(substr(hash('sha256', crypto_master_key()), 0, 16)); }
function crypto_enabled(): bool { return setting('encrypt_files', '1') === '1' && extension_loaded('openssl'); }

/** Nový kľúč súboru: [surový kľúč, zabalený kľúč pre DB] */
function crypto_new_file_key(): array {
    $raw = random_bytes(32);
    return [$raw, crypto_wrap($raw)];
}
function crypto_wrap(string $raw): string {
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($raw, 'aes-256-gcm', crypto_master_key(), OPENSSL_RAW_DATA, $iv, $tag, 'cloud-file-key');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
function crypto_unwrap(string $wrapped): string {
    if (!str_starts_with($wrapped, 'v1:')) throw new RuntimeException('Neznámy formát kľúča súboru.');
    $b = base64_decode(substr($wrapped, 3), true);
    if ($b === false || strlen($b) !== 60) throw new RuntimeException('Poškodený kľúč súboru.');
    $raw = openssl_decrypt(substr($b, 28), 'aes-256-gcm', crypto_master_key(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16), 'cloud-file-key');
    if ($raw === false) throw new RuntimeException('Kľúč súboru sa nedá rozšifrovať – nesprávny hlavný kľúč?');
    return $raw;
}

function crypto_block_count(int $plainSize): int { return max(1, (int)ceil($plainSize / ENC_BLOCK)); }
function crypto_cipher_size(int $plainSize): int { return ENC_HEADER + $plainSize + crypto_block_count($plainSize) * ENC_TAG; }

/** Zašifruje súbor $src → $dst. */
function crypto_encrypt_file(string $src, string $dst, string $key): void {
    $size = filesize($src);
    $blocks = crypto_block_count($size);
    $prefix = random_bytes(8);
    $in = fopen($src, 'rb'); $out = fopen($dst, 'wb');
    if (!$in || !$out) throw new RuntimeException('Šifrovanie: súbor sa nedá otvoriť.');
    fwrite($out, ENC_MAGIC . $prefix);
    for ($i = 0; $i < $blocks; $i++) {
        $plain = $size ? fread($in, ENC_BLOCK) : '';
        if ($plain === false) $plain = '';
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix . pack('N', $i), $tag, pack('N', $i) . ($i === $blocks - 1 ? "\x01" : "\x00"), ENC_TAG);
        if ($ct === false) throw new RuntimeException('Šifrovanie zlyhalo.');
        fwrite($out, $ct . $tag);
    }
    fclose($in);
    if (!fflush($out) || !fclose($out)) throw new RuntimeException('Zápis zašifrovaného súboru zlyhal.');
}

/**
 * Rozšifruje rozsah bajtov [start, end] otvoreného textu a posiela ho do $sink (callable(string): bool – false = prerušiť).
 */
function crypto_decrypt_range(string $path, string $key, int $plainSize, int $start, int $end, callable $sink): void {
    $fp = fopen($path, 'rb');
    if (!$fp) throw new RuntimeException('Súbor sa nedá otvoriť.');
    $head = fread($fp, ENC_HEADER);
    if (strlen($head) !== ENC_HEADER || substr($head, 0, 6) !== ENC_MAGIC) { fclose($fp); throw new RuntimeException('Súbor nie je zašifrovaný v očakávanom formáte.'); }
    $prefix = substr($head, 6, 8);
    $blocks = crypto_block_count($plainSize);
    if ($plainSize === 0) { fclose($fp); return; }
    $first = intdiv($start, ENC_BLOCK);
    $last = intdiv($end, ENC_BLOCK);
    for ($i = $first; $i <= $last; $i++) {
        $plainLen = ($i === $blocks - 1) ? $plainSize - $i * ENC_BLOCK : ENC_BLOCK;
        fseek($fp, ENC_HEADER + $i * (ENC_BLOCK + ENC_TAG));
        $chunk = '';
        $need = $plainLen + ENC_TAG;
        while (strlen($chunk) < $need && !feof($fp)) { $r = fread($fp, $need - strlen($chunk)); if ($r === false || $r === '') break; $chunk .= $r; }
        if (strlen($chunk) !== $need) { fclose($fp); throw new RuntimeException('Zašifrovaný súbor je skrátený (blok ' . $i . ').'); }
        $plain = openssl_decrypt(substr($chunk, 0, $plainLen), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $prefix . pack('N', $i),
                                 substr($chunk, $plainLen), pack('N', $i) . ($i === $blocks - 1 ? "\x01" : "\x00"));
        if ($plain === false) { fclose($fp); throw new RuntimeException('Súbor je poškodený alebo bol zmenený (blok ' . $i . ').'); }
        $from = ($i === $first) ? $start - $i * ENC_BLOCK : 0;
        $to = ($i === $last) ? $end - $i * ENC_BLOCK : $plainLen - 1;
        if ($sink(substr($plain, $from, $to - $from + 1)) === false) break;
    }
    fclose($fp);
}

/* ================== Prístup k uloženým súborom (šifrovaným aj nešifrovaným) ================== */

/** Cesta k uloženým dátam súboru alebo verzie. $row: user_id, stored_name */
function stored_path(array $row): string { return storage_dir((int)$row['user_id']) . '/' . $row['stored_name']; }

/** Kľúč súboru alebo null (nešifrovaný). */
function row_key(array $row): ?string { return !empty($row['enc_key']) ? crypto_unwrap((string)$row['enc_key']) : null; }

/** Rozšifruje (alebo skopíruje) uložený súbor do dočasného súboru s otvoreným textom. Volajúci ho musí zmazať. */
function stored_to_temp(array $row, ?int $maxBytes = null): string {
    $tmp = tmp_file();
    $key = row_key($row);
    if ($key === null) {
        if ($maxBytes !== null) file_put_contents($tmp, (string)file_get_contents(stored_path($row), false, null, 0, $maxBytes));
        elseif (!copy(stored_path($row), $tmp)) throw new RuntimeException('Kopírovanie zlyhalo.');
        return $tmp;
    }
    $size = (int)$row['size'];
    $end = $maxBytes !== null ? min($size, $maxBytes) - 1 : $size - 1;
    $out = fopen($tmp, 'wb');
    if ($end >= 0) crypto_decrypt_range(stored_path($row), $key, $size, 0, $end, fn($d) => fwrite($out, $d) !== false);
    fclose($out);
    return $tmp;
}

/** Prečíta začiatok súboru ako reťazec (napr. pre textový náhľad). */
function stored_read(array $row, int $maxBytes): string {
    $key = row_key($row);
    if ($key === null) return (string)file_get_contents(stored_path($row), false, null, 0, $maxBytes);
    $size = (int)$row['size'];
    if ($size === 0) return '';
    $buf = '';
    crypto_decrypt_range(stored_path($row), $key, $size, 0, min($size, $maxBytes) - 1, function ($d) use (&$buf) { $buf .= $d; return true; });
    return $buf;
}

/** Zašifruje existujúci nešifrovaný súbor (údržba). Vráti nový enc_key alebo null, ak je vypnuté. */
function crypto_encrypt_stored(array $row): string {
    [$raw, $wrapped] = crypto_new_file_key();
    $path = stored_path($row);
    $tmp = $path . '.enc-' . bin2hex(random_bytes(4));
    crypto_encrypt_file($path, $tmp, $raw);
    // overenie pred nahradením originálu
    $h = hash_init('sha256');
    crypto_decrypt_range($tmp, $raw, (int)$row['size'], 0, max(0, (int)$row['size'] - 1), function ($d) use ($h) { hash_update($h, $d); return true; });
    $check = hash_final($h);
    if ((int)$row['size'] > 0 && $check !== hash_file('sha256', $path)) { @unlink($tmp); throw new RuntimeException('Overenie po zašifrovaní zlyhalo.'); }
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Nahradenie súboru zlyhalo.'); }
    return $wrapped;
}

/** SHA-256 otvoreného textu uloženého súboru. */
function stored_sha256(array $row): string {
    $key = row_key($row);
    if ($key === null) return hash_file('sha256', stored_path($row));
    $h = hash_init('sha256');
    $size = (int)$row['size'];
    if ($size > 0) crypto_decrypt_range(stored_path($row), $key, $size, 0, $size - 1, function ($d) use ($h) { hash_update($h, $d); return true; });
    return hash_final($h);
}
