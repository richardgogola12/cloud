<?php
/* Ukážkové dáta pre snímky do príručky: používateľka Jana Nováková s pekne usporiadaným diskom.
   Spúšťa sa v GitHub Actions po end-to-end testoch (rovnaký server a databáza). */
const BASE = 'http://127.0.0.1:8080/';

class C {
    public string $jar; public ?string $csrf = null;
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    function req(string $m, string $p, array $o = []): array {
        $ch = curl_init(BASE . $p); $h = [];
        if (!empty($o['ajax'])) { $h[] = 'X-Requested-With: XMLHttpRequest'; $h[] = 'Accept: application/json'; if ($this->csrf) $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 120]);
        if (isset($o['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($o['multipart']) ? $o['form'] : http_build_query($o['form']));
        $b = (string)curl_exec($ch); curl_close($ch);
        return ['body' => $b, 'json' => json_decode($b, true)];
    }
    function api(string $a, array $f = []): array {
        $flat = [];
        foreach ($f as $k => $v) { if (is_array($v)) foreach ($v as $i => $x) $flat["{$k}[$i]"] = $x; else $flat[$k] = $v; }
        $r = $this->req('POST', 'api.php?action=' . $a, ['ajax' => true, 'form' => $flat]);
        if (empty($r['json']['ok'])) fwrite(STDERR, "  ! $a: " . substr($r['body'], 0, 200) . "\n");
        return $r['json'] ?? [];
    }
    function login(string $email, string $pass): void {
        if (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->req('GET', 'login.php')['body'], $m)) $this->csrf = $m[1];
        $this->req('POST', 'login.php', ['form' => ['_csrf' => $this->csrf, 'email' => $email, 'password' => $pass]]);
        if (preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $this->req('GET', 'index.php')['body'], $m)) $this->csrf = $m[1];
    }
    function up(int $folder, string $name, string $data): int {
        $i = $this->api('upload_init', ['folder' => $folder, 'name' => $name, 'size' => strlen($data), 'relpath' => '', 'resume' => '']);
        $off = (int)($i['received'] ?? 0);
        while (true) {
            $tmp = tempnam(sys_get_temp_dir(), 'c'); file_put_contents($tmp, substr($data, $off, 1000000));
            $r = $this->req('POST', 'api.php?action=upload_chunk', ['ajax' => true, 'multipart' => true, 'form' => ['id' => $i['id'], 'offset' => (string)$off, 'chunk' => new CURLFile($tmp, 'application/octet-stream', 'chunk')]]);
            unlink($tmp);
            $j = $r['json'] ?? [];
            if (empty($j['ok'])) { fwrite(STDERR, "  ! upload $name: " . substr($r['body'], 0, 200) . "\n"); return 0; }
            if (!empty($j['done'])) return (int)($j['file']['id'] ?? 0);
            $off = (int)$j['received'];
        }
    }
}

/* ---------- obsah súborov ---------- */
function photo(int $seed, int $w = 1600, int $h = 1067): string {
    mt_srand($seed);
    $im = imagecreatetruecolor($w, $h);
    $pal = [[[255, 179, 102], [255, 94, 98], [72, 52, 117]], [[135, 206, 250], [176, 224, 230], [240, 248, 255]], [[25, 25, 112], [255, 140, 0], [255, 215, 0]],
            [[255, 204, 153], [255, 153, 204], [153, 102, 204]], [[102, 178, 255], [204, 229, 255], [255, 255, 255]]][$seed % 5];
    $hor = (int)($h * (0.55 + mt_rand(0, 10) / 100));
    for ($y = 0; $y < $hor; $y++) { // obloha
        $t = $y / $hor; $a = $t < .5 ? $pal[2] : $pal[1]; $b = $t < .5 ? $pal[1] : $pal[0]; $u = $t < .5 ? $t * 2 : ($t - .5) * 2;
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int)($a[0] + ($b[0] - $a[0]) * $u), (int)($a[1] + ($b[1] - $a[1]) * $u), (int)($a[2] + ($b[2] - $a[2]) * $u)));
    }
    $sx = mt_rand((int)($w * .15), (int)($w * .85)); $sy = (int)($hor * (.35 + mt_rand(0, 30) / 100));
    imagefilledellipse($im, $sx, $sy, 160, 160, imagecolorallocatealpha($im, 255, 250, 220, 20));
    for ($l = 0; $l < 3; $l++) { // hory
        $pts = [0, $h]; $x = 0; $base = $hor - 40 + $l * 35;
        while ($x <= $w) { $pts[] = $x; $pts[] = $base - mt_rand(40, 230 - $l * 50); $x += mt_rand(120, 260); }
        $pts[] = $w; $pts[] = $base; $pts[] = $w; $pts[] = $h;
        $c = [[70, 90, 120], [50, 80, 90], [40, 70, 60]][$l];
        imagefilledpolygon($im, $pts, imagecolorallocate($im, $c[0] + mt_rand(0, 20), $c[1] + mt_rand(0, 20), $c[2] + mt_rand(0, 20)));
    }
    for ($y = $hor + 40; $y < $h; $y++) { // more / jazero
        $t = ($y - $hor) / ($h - $hor);
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int)(30 + 40 * (1 - $t)), (int)(100 + 60 * (1 - $t)), (int)(150 + 60 * (1 - $t))));
    }
    for ($i = 0; $i < 40; $i++) { $y = mt_rand($hor + 50, $h - 10); $x = mt_rand(0, $w); imageline($im, $x, $y, $x + mt_rand(30, 120), $y, imagecolorallocatealpha($im, 255, 255, 255, 70)); }
    ob_start(); imagejpeg($im, null, 86); return ob_get_clean();
}
function pdf(string $title): string {
    $txt = '(' . str_replace(['(', ')'], '', iconv('UTF-8', 'ASCII//TRANSLIT', $title)) . ')';
    $stream = "BT /F1 24 Tf 72 720 Td $txt Tj ET";
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $out = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $i => $o) { $off[] = strlen($out); $out .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
    $x = strlen($out);
    $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
    return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF\n";
}
function office(string $kind, string $text): string { // jednoduchý .docx / .xlsx (stačí na ikonu a hľadanie v obsahu)
    $f = tempnam(sys_get_temp_dir(), 'o'); $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
    $t = htmlspecialchars($text, ENT_XML1);
    if ($kind === 'docx') {
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . $t . '</w:t></w:r></w:p></w:body></w:document>');
    } else {
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hárok1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
        $z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1"><si><t>' . $t . '</t></si></sst>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>');
    }
    $z->close(); $d = file_get_contents($f); unlink($f); return $d;
}

/* ---------- používatelia ---------- */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cloud', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ins = $pdo->prepare("INSERT INTO cloud_users (name, email, password, role, quota, is_active) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE name = VALUES(name)");
$ins->execute(['Jana Nováková', 'jana@demo.sk', password_hash('Demo12345', PASSWORD_DEFAULT), 'user', 15 * 1024 ** 3]);
$ins->execute(['Peter Kováč', 'peter@demo.sk', password_hash('Demo12345', PASSWORD_DEFAULT), 'user', 15 * 1024 ** 3]);
$petId = (int)$pdo->query("SELECT id FROM cloud_users WHERE email = 'peter@demo.sk'")->fetchColumn();
$janaId = (int)$pdo->query("SELECT id FROM cloud_users WHERE email = 'jana@demo.sk'")->fetchColumn();

$p = new C(); $p->login('peter@demo.sk', 'Demo12345');
$pv = $p->api('mkdir', ['parent' => 0, 'name' => 'Spoločný výlet – Tatry'])['id'] ?? 0;
for ($i = 0; $i < 3; $i++) $p->up($pv, 'Tatry_' . ($i + 1) . '.jpg', photo(40 + $i));
$p->up($pv, 'Plán túry.pdf', pdf('Plan tury - Tatry'));

$j = new C(); $j->login('jana@demo.sk', 'Demo12345');
$mk = fn(int $parent, string $name) => (int)($j->api('mkdir', ['parent' => $parent, 'name' => $name])['id'] ?? 0);
$dok = $mk(0, 'Dokumenty'); $zml = $mk($dok, 'Zmluvy'); $fak = $mk($dok, 'Faktúry 2026');
$dov = $mk(0, 'Dovolenka Chorvátsko 2026'); $proj = $mk(0, 'Projekty'); $kup = $mk($proj, 'Rekonštrukcia kúpeľne');
$rod = $mk(0, 'Rodina'); $skola = $mk(0, 'Škola'); $sukr = $mk(0, 'Súkromné');
$f = [];
$f['cv'] = $j->up(0, 'Životopis Jana Nováková.docx', office('docx', 'Životopis Jana Nováková – skúsenosti, vzdelanie'));
$f['rozpocet'] = $j->up(0, 'Rodinný rozpočet 2026.xlsx', office('xlsx', 'Rodinný rozpočet 2026 – príjmy a výdavky'));
$j->up(0, 'Nákupný zoznam.txt', "Mlieko\nChlieb\nJablká\nKáva\nSyr\n");
$f['najom'] = $j->up($zml, 'Nájomná zmluva – byt.pdf', pdf('Najomna zmluva - byt'));
$j->up($zml, 'Poistenie domácnosti.pdf', pdf('Poistenie domacnosti'));
for ($m = 1; $m <= 10; $m++) $j->up($fak, sprintf('Faktúra 2026-%03d – elektrina.pdf', $m), pdf("Faktura 2026-$m elektrina"));
$photos = [];
foreach (['Pláž v Makarskej', 'Západ slnka', 'Prístav Split', 'Výlet loďou', 'Plitvické jazerá', 'Staré mesto Dubrovník', 'Rodina na pláži', 'Večer na terase'] as $i => $n) $photos[] = $j->up($dov, $n . '.jpg', photo(10 + $i));
$j->up($kup, 'Návrh kúpeľne.pdf', pdf('Navrh kupelne'));
$f['naklady'] = $j->up($kup, 'Rozpočet rekonštrukcie.xlsx', office('xlsx', 'Rozpočet rekonštrukcie – obklady, sanita, práca'));
$j->up($kup, 'Poznámky z obhliadky.txt', "Obhliadka 12. 10.\n- vymeniť batériu\n- nové obklady do stropu\n- odtokový žľab\n");
$fam = [];
foreach (['Narodeniny babky', 'Vianoce 2024', 'Výlet na hrad', 'Prvý deň v škole'] as $i => $n) $fam[] = $j->up($rod, $n . '.jpg', photo(30 + $i));
$j->up($skola, 'Rozvrh hodín.pdf', pdf('Rozvrh hodin'));
$j->up($skola, 'Referát – Slnečná sústava.docx', office('docx', 'Referát Slnečná sústava planéty'));
$j->up($sukr, 'Heslá a PIN kódy.txt', "(ukážka)\n");
// nová verzia dokumentu (história verzií)
$j->up($zml, 'Nájomná zmluva – byt.pdf', pdf('Najomna zmluva - byt (dodatok)'));

// hviezdičky, farby, štítky
$j->api('star', ['items' => ["f:{$f['najom']}", "d:$dov", "f:{$f['rozpocet']}"], 'value' => 1]);
$j->api('color_set', ['items' => ["d:$dov"], 'color' => '#1a73e8']);
$j->api('color_set', ['items' => ["d:$proj"], 'color' => '#f9ab00']);
$j->api('color_set', ['items' => ["d:$rod"], 'color' => '#e52592']);
$t1 = (int)($j->api('tag_save', ['name' => 'Dôležité', 'scope' => 'f', 'color' => '#d93025'])['id'] ?? 0);
$t2 = (int)($j->api('tag_save', ['name' => 'Rodina', 'scope' => 'd', 'color' => '#188038'])['id'] ?? 0);
$t3 = (int)($j->api('tag_save', ['name' => 'Domácnosť', 'scope' => 'd', 'color' => '#1a73e8'])['id'] ?? 0);
$j->api('tags_apply', ['items' => ["f:{$f['najom']}", "f:{$f['cv']}"], 'add' => [$t1]]);
$j->api('tags_apply', ['items' => ["d:$rod", "d:$dov"], 'add' => [$t2]]);
$j->api('tags_apply', ['items' => ["d:$kup", "d:$dok"], 'add' => [$t3]]);
// zdieľanie
$j->api('share_user_add', ['type' => 'd', 'id' => $dov, 'users' => [$petId], 'permission' => 'edit', 'notify' => '']);
$j->api('share_link_save', ['type' => 'd', 'id' => $fak, 'expires' => date('Y-m-d', strtotime('+30 days'))]);
$p->api('share_user_add', ['type' => 'd', 'id' => $pv, 'users' => [$janaId], 'permission' => 'view', 'notify' => '']);
// album
$al = (int)($j->api('album_save', ['name' => 'Leto 2026'])['id'] ?? 0);
$j->api('album_add', ['album' => $al, 'items' => array_map(fn($x) => "f:$x", array_slice($photos, 0, 6))]);
$al2 = (int)($j->api('album_save', ['name' => 'Rodinné chvíle'])['id'] ?? 0);
$j->api('album_add', ['album' => $al2, 'items' => array_map(fn($x) => "f:$x", $fam)]);
// dátumy fotiek (a „v tento deň“ spred 2 rokov)
$day = 0;
foreach ($photos as $id) $pdo->exec("UPDATE cloud_files SET taken_at = '2026-07-" . sprintf('%02d', 10 + $day++) . " 1" . ($day % 9) . ":20:00' WHERE id = " . (int)$id);
foreach ($fam as $i => $id) $pdo->exec("UPDATE cloud_files SET taken_at = DATE_SUB(NOW(), INTERVAL " . (2 + $i) . " YEAR) WHERE id = " . (int)$id);
// kôš
$tmp = $j->up(0, 'Starý koncept.docx', office('docx', 'Starý koncept'));
$j->api('delete', ['items' => ["f:$tmp"]]);
// zamknutá zložka
$j->api('folder_lock', ['id' => $sukr, 'op' => 'set', 'new' => 'Trezor123']);
$j->api('folder_relock', ['id' => $sukr]);
// sprievodca sa na snímkach zobrazí iba tam, kde ho chceme
$pdo->exec("UPDATE cloud_users SET prefs = JSON_SET(COALESCE(prefs, '{}'), '$.tour', 'done') WHERE id IN ($janaId, $petId)");
file_put_contents(__DIR__ . '/help-demo.json', json_encode(['jana' => $janaId, 'dov' => $dov, 'dok' => $dok, 'zml' => $zml, 'fak' => $fak, 'kup' => $kup, 'sukr' => $sukr,
    'najom' => $f['najom'], 'photo' => $photos[0], 'album' => $al, 'peter_folder' => $pv]));
echo "Ukážkové dáta pripravené\n";
