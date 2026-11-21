<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Dvojstupňové overenie (TOTP, RFC 6238) – kód z aplikácie ako
 *  Google Authenticator, Microsoft Authenticator, Aegis, 2FAS…
 *  Tajný kľúč je v DB zašifrovaný hlavným kľúčom servera.
 * ------------------------------------------------------------------ */

const TOTP_DIGITS = 6;
const TOTP_PERIOD = 30;
const TOTP_BACKUP_COUNT = 10;

function base32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function base32_decode(string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}
/** Kód pre daný časový krok. */
function totp_code(string $secretB32, int $step): string {
    $h = hash_hmac('sha1', pack('N2', 0, $step), base32_decode($secretB32), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 10 ** TOTP_DIGITS;
    return str_pad((string)$n, TOTP_DIGITS, '0', STR_PAD_LEFT);
}
function totp_new_secret(): string { return base32_encode(random_bytes(20)); }

function totp_wrap(string $plain): string {
    if (!extension_loaded('openssl')) return 'p:' . $plain;
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', crypto_master_key(), OPENSSL_RAW_DATA, $iv, $tag, 'cloud-totp');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
function totp_unwrap(string $w): string {
    if (str_starts_with($w, 'p:')) return substr($w, 2);
    $b = base64_decode(substr($w, 3), true);
    if (!str_starts_with($w, 'v1:') || $b === false || strlen($b) < 29) return '';
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', crypto_master_key(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16), 'cloud-totp');
    return $p === false ? '' : $p;
}

function totp_enabled(array $u): bool { return !empty($u['totp_secret']); }
function totp_secret_of(array $u): string { return totp_enabled($u) ? totp_unwrap((string)$u['totp_secret']) : ''; }

/** Overí 6-miestny kód (±1 krok kvôli posunu hodín) alebo záložný kód. Ten istý kód sa nedá použiť dvakrát. */
function totp_check(array $u, string $code): bool {
    $code = preg_replace('/\s+/', '', $code);
    if ($code === '') return false;
    if (preg_match('/^\d{6}$/', $code)) {
        $sec = totp_secret_of($u); if ($sec === '') return false;
        $now = intdiv(time(), TOTP_PERIOD); $last = (int)($u['totp_last'] ?? 0);
        for ($d = -1; $d <= 1; $d++) {
            $st = $now + $d;
            if ($st <= $last) continue;
            if (hash_equals(totp_code($sec, $st), $code)) {
                q('UPDATE ' . P . 'users SET totp_last = ? WHERE id = ?', [$st, $u['id']]);
                return true;
            }
        }
        return false;
    }
    // záložný kód (napr. 4f7a-92c1) – každý platí raz
    $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
    $list = json_decode((string)($u['totp_backup'] ?? '[]'), true) ?: [];
    foreach ($list as $i => $h) {
        if (password_verify($norm, $h)) {
            unset($list[$i]);
            q('UPDATE ' . P . 'users SET totp_backup = ? WHERE id = ?', [json_encode(array_values($list)), $u['id']]);
            log_action((int)$u['id'], 'totp_backup', 'použitý záložný kód, ostáva ' . count($list));
            if (count($list) <= 2) notify((int)$u['id'], 'device', 'Dochádzajú záložné kódy', 'Ostáva ' . count($list) . ' záložných kódov na prihlásenie. Vytvor si nové v Môj účet → Dvojstupňové overenie.', 'profile.php#tfa');
            return true;
        }
    }
    return false;
}
/** Nové záložné kódy – vráti ich čitateľne (zobrazia sa iba raz), do DB sa uložia hashe. */
function totp_new_backup_codes(int $uid): array {
    $codes = []; $hashes = [];
    for ($i = 0; $i < TOTP_BACKUP_COUNT; $i++) {
        $c = bin2hex(random_bytes(4));
        $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
        $hashes[] = password_hash($c, PASSWORD_DEFAULT);
    }
    q('UPDATE ' . P . 'users SET totp_backup = ? WHERE id = ?', [json_encode($hashes), $uid]);
    return $codes;
}
function totp_backup_left(array $u): int { return count(json_decode((string)($u['totp_backup'] ?? '[]'), true) ?: []); }
/** Adresa pre aplikáciu (QR kód). */
function totp_uri(array $u, string $secret): string {
    $issuer = (string)cfg('app_name', 'Cloud');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $u['email']) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}
/** Má administrátor povinné dvojstupňové overenie a ešte ho nemá zapnuté? */
function totp_required(array $u): bool {
    return setting('require_2fa_admin', '0') === '1' && ($u['role'] ?? '') === 'admin' && !totp_enabled($u);
}
