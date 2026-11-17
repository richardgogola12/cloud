<?php
declare(strict_types=1);
/* ------------------------------------------------------------------
 *  Profilová fotka – uložená vždy zašifrovaná (AES-256-GCM, vlastný kľúč
 *  zabalený hlavným kľúčom), vydáva sa iba prihláseným cez avatar.php
 * ------------------------------------------------------------------ */

function avatar_path(int $uid): string {
    $d = data_path('avatars');
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d . '/' . $uid . '.bin';
}

/** Uloží novú fotku z nahratého súboru (orezá na štvorec 320×320). */
function avatar_save(int $uid, string $tmp): void {
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('Na serveri chýba rozšírenie GD.');
    if (!extension_loaded('openssl')) throw new RuntimeException('Na serveri chýba rozšírenie OpenSSL.');
    if (!is_file($tmp) || filesize($tmp) > 15 * 1024 * 1024) throw new RuntimeException('Fotka je príliš veľká (max. 15 MB).');
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) throw new RuntimeException('Nahraj obrázok JPG, PNG, GIF alebo WebP.');
    $img = @imagecreatefromstring((string)file_get_contents($tmp));
    if (!$img) throw new RuntimeException('Obrázok sa nedá načítať.');
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (@exif_read_data($tmp))['Orientation'] ?? 1;
        if ($o == 3) $img = imagerotate($img, 180, 0);
        elseif ($o == 6) $img = imagerotate($img, -90, 0);
        elseif ($o == 8) $img = imagerotate($img, 90, 0);
    }
    $w = imagesx($img); $h = imagesy($img); $s = min($w, $h);
    $size = 320;
    $t = imagecreatetruecolor($size, $size);
    imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));
    imagecopyresampled($t, $img, 0, 0, (int)(($w - $s) / 2), (int)(($h - $s) / 2), $size, $size, $s, $s);
    ob_start(); imagejpeg($t, null, 86); $jpg = (string)ob_get_clean();
    imagedestroy($img); imagedestroy($t);

    [$key, $wrapped] = crypto_new_file_key();
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($jpg, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'avatar:' . $uid);
    if ($ct === false) throw new RuntimeException('Šifrovanie fotky zlyhalo.');
    $path = avatar_path($uid);
    if (file_put_contents($path . '.tmp', 'CLAV1' . $iv . $tag . $ct) === false || !rename($path . '.tmp', $path)) throw new RuntimeException('Fotku sa nepodarilo uložiť.');
    q('UPDATE ' . P . 'users SET avatar_key = ?, avatar_v = avatar_v + 1 WHERE id = ?', [$wrapped, $uid]);
}

function avatar_delete(int $uid): void {
    @unlink(avatar_path($uid));
    q('UPDATE ' . P . 'users SET avatar_key = NULL, avatar_v = avatar_v + 1 WHERE id = ?', [$uid]);
}

/** Rozšifrovaný JPEG alebo null. */
function avatar_read(array $u): ?string {
    if (empty($u['avatar_key'])) return null;
    $raw = @file_get_contents(avatar_path((int)$u['id']));
    if ($raw === false || strlen($raw) < 34 || !str_starts_with($raw, 'CLAV1')) return null;
    $key = crypto_unwrap((string)$u['avatar_key']);
    $jpg = openssl_decrypt(substr($raw, 33), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 5, 12), substr($raw, 17, 16), 'avatar:' . (int)$u['id']);
    return $jpg === false ? null : $jpg;
}

function avatar_url(array $u): ?string {
    return !empty($u['avatar_key']) ? url('avatar.php?u=' . (int)$u['id'] . '&v=' . (int)($u['avatar_v'] ?? 0)) : null;
}

/** Kruhový avatar: fotka, inak prvé písmeno mena. */
function avatar_html(array $u, string $cls = ''): string {
    $name = (string)($u['name'] ?? '?');
    $initial = e(mb_strtoupper(mb_substr($name, 0, 1)));
    $src = avatar_url($u);
    if ($src) return '<span class="avatar has-img ' . e($cls) . '"><img src="' . e($src) . '" alt="" loading="lazy"></span>';
    return '<span class="avatar ' . e($cls) . '">' . $initial . '</span>';
}
