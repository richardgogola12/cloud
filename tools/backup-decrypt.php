<?php
/* Rozšifruje zálohu databázy Cloudu (cloud-db-….sql.gz.enc) na .sql.gz.
   Použitie:  php tools/backup-decrypt.php cloud-db-2026-11-21-0300.sql.gz.enc  [výstup.sql.gz]
   Heslo zálohy sa zadá z klávesnice. Výsledok obnovíš napr.:  gunzip -c zaloha.sql.gz | mysql -u cloud -p cloud */
if (PHP_SAPI !== 'cli') exit("Spúšťa sa z príkazového riadka.\n");
$src = $argv[1] ?? ''; if (!is_file($src)) { fwrite(STDERR, "Použitie: php backup-decrypt.php záloha.sql.gz.enc [výstup.sql.gz]\n"); exit(1); }
$dst = $argv[2] ?? preg_replace('/\.enc$/', '', $src);
if ($dst === $src) $dst .= '.dec';
fwrite(STDERR, 'Heslo zálohy: ');
if (DIRECTORY_SEPARATOR === '/') system('stty -echo');
$pass = rtrim((string)fgets(STDIN), "\r\n");
if (DIRECTORY_SEPARATOR === '/') system('stty echo');
fwrite(STDERR, "\n");
$in = fopen($src, 'rb');
if (fread($in, 8) !== 'CLOUDBK1') exit("Toto nie je záloha Cloudu.\n");
$key = hash_pbkdf2('sha256', $pass, fread($in, 16), 200000, 32, true);
$out = fopen($dst, 'wb'); $i = 0;
while (!feof($in)) {
    $hdr = fread($in, 32); if ($hdr === '' || $hdr === false) break;
    $len = unpack('N', substr($hdr, 28, 4))[1];
    $pt = openssl_decrypt(fread($in, $len), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($hdr, 0, 12), substr($hdr, 12, 16), pack('N', $i++));
    if ($pt === false) { fclose($out); @unlink($dst); exit($i === 1 ? "Nesprávne heslo.\n" : "Záloha je poškodená.\n"); }
    fwrite($out, $pt);
}
fclose($out);
echo "Hotovo: $dst\n";
