<?php
/* ==================================================================
 *  End-to-end test Cloud servera (web + požiadavky Android aplikácie)
 *  Spúšťa sa v GitHub Actions proti skutočnému PHP serveru a MySQL.
 * ================================================================== */
const BASE = 'http://127.0.0.1:8080/';
const STORAGE = '/tmp/cloud-storage';
$fails = 0; $passes = 0;

function ok(bool $cond, string $msg, string $detail = ''): bool {
    global $fails, $passes;
    if ($cond) { $passes++; echo "  ✔ $msg\n"; }
    else { $fails++; echo "  ✘ $msg" . ($detail !== '' ? "\n      → " . substr(str_replace("\n", ' ', $detail), 0, 400) : '') . "\n"; }
    return $cond;
}
function section(string $s) { echo "\n== $s ==\n"; }

/** HTTP klient s vlastným úložiskom cookies */
class Client {
    public string $jar;
    public ?string $csrf = null;
    public ?string $token = null;
    public array $last = [];
    function __construct() { $this->jar = tempnam('/tmp', 'jar'); }
    function req(string $method, string $path, array $opt = []): array {
        $ch = curl_init(BASE . ltrim($path, '/'));
        $h = $opt['headers'] ?? [];
        if ($this->token && empty($opt['no_token'])) $h[] = 'X-Cloud-Token: ' . $this->token;
        if (!empty($opt['ajax'])) { $h[] = 'X-Requested-With: XMLHttpRequest'; $h[] = 'Accept: application/json'; if ($this->csrf) $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        if (!empty($opt['app'])) $h[] = 'User-Agent: CloudAndroid/1.0.99 (Android 15; Test)';
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => !empty($opt['follow'])]);
        if (isset($opt['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['multipart'] ?? false ? $opt['form'] : http_build_query($opt['form']));
        if (isset($opt['json'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json'])); $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_HTTPHEADER, $h); }
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr((string)$raw, 0, $hs); $body = substr((string)$raw, $hs);
        return $this->last = ['code' => $code, 'headers' => $headers, 'body' => $body, 'json' => json_decode($body, true)];
    }
    function get(string $p, array $o = []) { return $this->req('GET', $p, $o); }
    function post(string $p, array $form = [], array $o = []) { return $this->req('POST', $p, $o + ['form' => $form]); }
    /** volanie JSON API webu */
    function api(string $action, array $form = []): array {
        $r = $this->req('POST', 'api.php?action=' . $action, ['ajax' => true, 'form' => $this->flat($form)]);
        return $r['json'] ?? ['ok' => false, 'error' => 'HTTP ' . $r['code'] . ': ' . substr($r['body'], 0, 300)];
    }
    function flat(array $form): array { // items[] polia
        $out = [];
        foreach ($form as $k => $v) { if (is_array($v)) foreach ($v as $i => $x) $out["{$k}[$i]"] = $x; else $out[$k] = $v; }
        return $out;
    }
    function loadCsrf(string $html): void {
        if (preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) $this->csrf = $m[1];
        elseif (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m)) $this->csrf = $m[1];
    }
}

/** nahratie po častiach presne ako Android aplikácia (multipart: id, offset, chunk) */
function upload(Client $c, int $folder, string $name, string $data, string $rel = '', int $chunk = 300000, ?string $resume = null, ?int $stopAfter = null): array {
    $init = $c->api('upload_init', ['folder' => $folder, 'name' => $name, 'size' => strlen($data), 'relpath' => $rel, 'resume' => $resume ?? '']);
    if (empty($init['ok'])) return $init + ['stage' => 'init'];
    $off = (int)$init['received']; $sent = 0;
    while (true) {
        $part = substr($data, $off, $chunk);
        $tmp = tempnam('/tmp', 'ch'); file_put_contents($tmp, $part);
        $r = $c->req('POST', 'api.php?action=upload_chunk', ['ajax' => true, 'multipart' => true,
            'form' => ['id' => $init['id'], 'offset' => (string)$off, 'chunk' => new CURLFile($tmp, 'application/octet-stream', 'chunk')]]);
        unlink($tmp);
        $j = $r['json'];
        if (empty($j['ok'])) return ['ok' => false, 'error' => $j['error'] ?? ('HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 300)), 'stage' => 'chunk', 'id' => $init['id']];
        $off = (int)$j['received']; $sent++;
        if (!empty($j['done'])) return $j + ['upload_id' => $init['id']];
        if ($stopAfter !== null && $sent >= $stopAfter) return ['ok' => true, 'partial' => true, 'id' => $init['id'], 'received' => $off];
        if ($part === '') return ['ok' => false, 'error' => 'server neukončil nahrávanie'];
    }
}
function jpeg(int $w, int $h, int $seed): string {
    $im = imagecreatetruecolor($w, $h);
    mt_srand($seed);
    for ($i = 0; $i < 60; $i++) imagefilledrectangle($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    ob_start(); imagejpeg($im, null, 92); return ob_get_clean();
}
function stored_file_of(int $fileId): ?string {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=cloud', 'root', 'root');
    $r = $pdo->query('SELECT user_id, stored_name FROM cloud_files WHERE id = ' . $fileId)->fetch(PDO::FETCH_ASSOC);
    return $r ? STORAGE . '/files/' . $r['user_id'] . '/' . $r['stored_name'] : null;
}
function no_php_errors(string $body, string $what) {
    ok(!preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $body), "$what bez PHP chýb", substr(strip_tags($body), 0, 300));
}

/* ================================================================== */
section('Verejné stránky');
$guest = new Client();
foreach (['login.php' => 'Prihlásenie', 'register.php' => 'Vytvoriť účet', 'forgot.php' => 'Zabudnuté heslo'] as $p => $t) {
    $r = $guest->get($p);
    ok($r['code'] === 200 && str_contains($r['body'], $t), "$p sa zobrazí", "HTTP {$r['code']}");
    no_php_errors($r['body'], $p);
}
$r = $guest->get('index.php');
ok($r['code'] === 302 && str_contains($r['headers'], 'login.php'), 'neprihlásený je presmerovaný na prihlásenie');

section('Registrácia a prihlásenie (web)');
$reg = new Client();
$reg->loadCsrf($reg->get('register.php')['body']);
$r = $reg->post('register.php', ['_csrf' => $reg->csrf, 'name' => 'Nový Používateľ', 'email' => 'novy@test.sk', 'password' => 'Heslo1234', 'password2' => 'Heslo1234', 'terms' => 1]);
ok($r['code'] === 302 && str_contains($r['headers'], 'index.php'), 'registrácia a automatické prihlásenie', $r['headers']);

$web = new Client();
$web->loadCsrf($web->get('login.php')['body']);
$r = $web->post('login.php', ['_csrf' => $web->csrf, 'email' => 'admin@test.sk', 'password' => 'zle-heslo']);
ok(str_contains($r['headers'], 'login.php'), 'nesprávne heslo neprihlási');
$web->loadCsrf($web->get('login.php')['body']);
$r = $web->post('login.php', ['_csrf' => $web->csrf, 'email' => 'admin@test.sk', 'password' => 'Heslo1234', 'remember' => 1]);
ok($r['code'] === 302 && str_contains($r['headers'], 'index.php'), 'prihlásenie heslom', $r['headers']);
ok(str_contains($r['headers'], 'Set-Cookie: CLOUD_TEST='), 'vlastný názov session cookie (izolácia od iných webov)', $r['headers']);
$r = $web->get('index.php');
ok($r['code'] === 200 && str_contains($r['body'], 'id="items"'), 'disk sa zobrazí');
no_php_errors($r['body'], 'index.php');
$web->loadCsrf($r['body']);
ok((bool)$web->csrf, 'CSRF token v stránke');

section('Zložky a nahrávanie (web)');
$j = $web->api('list', ['view' => 'folder', 'folder' => 0]);
ok(!empty($j['ok']), 'výpis koreňa', json_encode($j));
$j = $web->api('mkdir', ['parent' => 0, 'name' => 'Test zložka']);
ok(!empty($j['ok']), 'vytvorenie zložky', json_encode($j));
$folder = (int)($j['id'] ?? 0);
$j = $web->api('mkdir', ['parent' => 0, 'name' => 'Test zložka']);
ok(empty($j['ok']), 'duplicitná zložka sa odmietne');

$img = jpeg(1200, 900, 1);
$up = upload($web, $folder, 'fotka.jpg', $img);
ok(!empty($up['done']), 'nahratie JPEG po častiach (' . round(strlen($img) / 1024) . ' KB)', json_encode($up));
$fileId = (int)($up['file']['id'] ?? 0);
$disk = $fileId ? stored_file_of($fileId) : null;
ok($disk && is_file($disk) && substr(file_get_contents($disk, false, null, 0, 6), 0, 6) === "CLDE1\0", 'súbor je na disku zašifrovaný');
ok($disk && !str_contains(file_get_contents($disk), substr($img, 200, 64)), 'na disku nie je čitateľný obsah');

$r = $web->get("api.php?action=download&id=$fileId");
ok($r['code'] === 200 && $r['body'] === $img, 'stiahnutie vráti presne pôvodný obsah', "HTTP {$r['code']} len " . strlen($r['body']));
$r = $web->get("api.php?action=download&id=$fileId", ['headers' => ['Range: bytes=1000-1999']]);
ok($r['code'] === 206 && $r['body'] === substr($img, 1000, 1000), 'čiastočné stiahnutie (Range) zo šifrovaného súboru');
$r = $web->get("api.php?action=download&id=$fileId&inline=1");
ok(str_contains($r['headers'], 'Content-Type: image/jpeg'), 'náhľad obrázka (inline)');
$r = $web->get("api.php?action=thumb&id=$fileId");
ok($r['code'] === 200 && @imagecreatefromstring($r['body']) !== false, 'miniatúra sa vytvorí', "HTTP {$r['code']}");
$thumb = glob(STORAGE . '/thumbs/*/*.jpg');
ok($thumb && substr(file_get_contents($thumb[0]), 0, 6) === "CLDE1\0", 'miniatúra je na disku zašifrovaná');
$r = $web->get("api.php?action=thumb&id=$fileId");
ok($r['code'] === 200 && @imagecreatefromstring($r['body']) !== false, 'miniatúra z cache (rozšifrovaná)');

section('Verzie a duplicity');
$up2 = upload($web, $folder, 'fotka.jpg', $img);
ok(!empty($up2['done']) && !empty($up2['file']['unchanged']), 'rovnaký súbor znova → bez novej verzie', json_encode($up2['file'] ?? $up2));
$img2 = jpeg(1200, 900, 2);
$up3 = upload($web, $folder, 'fotka.jpg', $img2);
ok(!empty($up3['file']['versioned']), 'zmenený súbor → nová verzia', json_encode($up3['file'] ?? $up3));
$j = $web->api('versions', ['id' => $fileId]);
ok(!empty($j['ok']) && count($j['versions']) === 2, 'zoznam verzií (2)', json_encode($j));
$vid = $j['versions'][1]['id'] ?? 0;
$r = $web->get("api.php?action=download&id=$fileId&v=$vid");
ok($r['body'] === $img, 'stiahnutie staršej verzie');
$j = $web->api('version_restore', ['vid' => $vid]);
ok(!empty($j['ok']), 'obnovenie staršej verzie');
ok($web->get("api.php?action=download&id=$fileId")['body'] === $img, 'po obnovení je aktuálny pôvodný obsah');

section('Textový súbor, premenovanie, kopírovanie, presun');
$j = $web->api('mkfile', ['parent' => $folder, 'name' => 'Poznámky']);
ok(!empty($j['ok']), 'nový textový súbor', json_encode($j));
$txtId = (int)($j['id'] ?? 0);
$t = $web->api('text', ['id' => $txtId]);
$j = $web->api('save_text', ['id' => $txtId, 'content' => "Ahoj čšťžý 😀\nriadok 2", 'updated' => $t['updated'] ?? '']);
ok(!empty($j['ok']), 'uloženie textu', json_encode($j));
$t = $web->api('text', ['id' => $txtId]);
ok(($t['text'] ?? '') === "Ahoj čšťžý 😀\nriadok 2", 'načítanie textu (UTF-8)', json_encode($t));
ok(!empty($web->api('rename', ['type' => 'f', 'id' => $txtId, 'name' => 'Poznámky 2.txt'])['ok']), 'premenovanie');
$j = $web->api('copy', ['items' => ["f:$fileId"], 'target' => 0]);
ok(!empty($j['ok']), 'kópia do koreňa', json_encode($j));
$j = $web->api('mkdir', ['parent' => $folder, 'name' => 'Podzložka']);
$sub = (int)($j['id'] ?? 0);
ok(!empty($web->api('move', ['items' => ["f:$txtId"], 'target' => $sub])['ok']), 'presun do podzložky');
ok(empty($web->api('move', ['items' => ["d:$folder"], 'target' => $sub])['ok']), 'zložku nemožno presunúť do seba');
ok(!empty($web->api('star', ['items' => ["f:$fileId"], 'value' => 1])['ok']), 'hviezdička');
foreach (['search' => ['q' => 'fotka'], 'recent' => [], 'starred' => [], 'photos' => [], 'shared' => [], 'trash' => []] as $v => $extra) {
    $j = $web->api('list', ['view' => $v] + $extra);
    ok(!empty($j['ok']), "pohľad „{$v}“", json_encode($j));
}
$j = $web->api('list', ['view' => 'photos']);
ok(count($j['items'] ?? []) >= 1, 'Fotky obsahujú obrázok');
$j = $web->api('info', ['type' => 'd', 'id' => $folder]);
ok(!empty($j['ok']), 'informácie o zložke', json_encode($j));
$j = $web->api('folders');
ok(!empty($j['ok']) && count($j['results']) >= 3, 'zoznam zložiek pre presun (Select2)');

$r = $web->get('api.php?action=zip&items=' . urlencode("d:$folder"));
$zf = tempnam('/tmp', 'z'); file_put_contents($zf, $r['body']);
$z = new ZipArchive();
$zo = $z->open($zf) === true;
ok($zo && $z->getFromName('Test zložka/fotka.jpg') === $img, 'ZIP zložky obsahuje rozšifrované súbory', "HTTP {$r['code']}");
ok($zo && $z->getFromName('Test zložka/Podzložka/Poznámky 2.txt') === "Ahoj čšťžý 😀\nriadok 2", 'ZIP obsahuje aj podzložky');

section('Kôš');
ok(!empty($web->api('delete', ['items' => ["d:$sub"]])['ok']), 'zložka do koša');
$j = $web->api('list', ['view' => 'trash']);
ok(count($j['items'] ?? []) === 1, 'kôš obsahuje zložku');
$j = $web->api('list', ['view' => 'search', 'q' => 'Poznámky']);
ok(count($j['items'] ?? []) === 0, 'obsah koša sa nehľadá');
ok(!empty($web->api('restore', ['items' => ["d:$sub"]])['ok']), 'obnovenie z koša');
$j = $web->api('list', ['view' => 'folder', 'folder' => $sub]);
ok(count($j['items'] ?? []) === 1, 'obnovená zložka má obsah');
$web->api('delete', ['items' => ["d:$sub"]]);
ok(!empty($web->api('empty_trash')['ok']), 'vysypanie koša');
ok(empty($web->api('list', ['view' => 'folder', 'folder' => $sub])['ok']), 'zmazaná zložka už neexistuje');

section('Mobilná aplikácia (rovnaké požiadavky ako Android)');
$app = new Client();
$r = $app->req('POST', 'app.php?action=login', ['json' => ['email' => 'admin@test.sk', 'password' => 'Heslo1234', 'device' => 'Test Phone'], 'app' => true]);
ok(!empty($r['json']['token']), 'prihlásenie aplikácie → token', $r['body']);
$app->token = $r['json']['token'] ?? null;
$r = $app->req('POST', 'app.php?action=login', ['json' => ['email' => 'admin@test.sk', 'password' => 'zle'], 'app' => true]);
ok(empty($r['json']['ok']), 'zlé heslo v aplikácii sa odmietne');
$r = $app->get('app.php?action=me', ['ajax' => true]);
ok(!empty($r['json']['ok']), 'app: údaje o účte', $r['body']);
$j = $app->api('folders');
ok(!empty($j['ok']), 'app: zoznam zložiek (bez CSRF, s tokenom)', json_encode($j));
$j = $app->api('mkdir', ['parent' => 0, 'name' => 'Camera']);
ok(!empty($j['ok']), 'app: vytvorenie cieľovej zložky', json_encode($j));
$cam = (int)($j['id'] ?? 0);
$photo = jpeg(2000, 1500, 3) . random_bytes(500000); // ~ väčší súbor, viac častí
$up = upload($app, $cam, 'IMG_0001.jpg', $photo);
ok(!empty($up['done']), 'app: nahratie fotky (' . round(strlen($photo) / 1024) . ' KB)', json_encode($up));
$up = upload($app, $cam, 'video.mp4', random_bytes(700000), 'Výlet/Deň 1/video.mp4');
ok(!empty($up['done']), 'app: nahratie s podzložkami (relpath)', json_encode($up));
$j = $app->api('list', ['view' => 'folder', 'folder' => $cam]);
$names = array_column($j['items'] ?? [], 'name');
ok(in_array('Výlet', $names, true) && in_array('IMG_0001.jpg', $names, true), 'app: podzložka vytvorená', json_encode($names));
// pokračovanie po prerušení
$big = random_bytes(1000000);
$part = upload($app, $cam, 'prerusene.bin', $big, '', 300000, null, 1);
ok(!empty($part['partial']), 'app: prerušené nahrávanie (1 časť)');
$res = upload($app, $cam, 'prerusene.bin', $big, '', 300000, $part['id'] ?? null);
ok(!empty($res['done']) && ($res['upload_id'] ?? '') === ($part['id'] ?? 'x'), 'app: pokračovanie od miesta prerušenia', json_encode($res));
$bid = (int)($res['file']['id'] ?? 0);
$r = $app->get("api.php?action=download&id=$bid", ['ajax' => true]);
ok($r['body'] === $big, 'app: stiahnutie (Otvoriť v aplikácii) vráti správny obsah');
$r = $app->get("api.php?action=download&id=$bid");
ok($r['body'] === $big, 'app: stiahnutie aj bez hlavičky X-Requested-With');
$empty = upload($app, $cam, 'prazdny.txt', '');
ok(!empty($empty['done']), 'app: prázdny súbor', json_encode($empty));
// WebView prihlásenie
$wv = new Client(); $wv->token = $app->token;
$r = $wv->get('app.php?action=web&to=' . urlencode('index.php#shared'), ['app' => true]);
ok($r['code'] === 302 && str_contains($r['headers'], 'index.php#shared'), 'app: prihlásenie WebView tokenom', $r['headers']);
$wv->token = null;
$r = $wv->get('index.php', ['app' => true]);
ok($r['code'] === 200 && str_contains($r['body'], 'cloudapp://sync'), 'app: WebView má session a menu aplikácie');
no_php_errors($r['body'], 'index.php v aplikácii');
$r = $app->get('app.php?action=notifications&after=0', ['ajax' => true]);
ok(!empty($r['json']['ok']) && isset($r['json']['last']), 'app: upozornenia', $r['body']);
$bad = new Client(); $bad->token = str_repeat('a', 64);
$r = $bad->req('POST', 'api.php?action=folders', ['ajax' => true]);
ok($r['code'] === 401, 'neplatný token → 401');

section('Zdieľanie');
$j = $web->api('user_search', ['q' => 'beata']);
$bid2 = (int)($j['results'][0]['id'] ?? 0);
ok($bid2 > 0, 'hľadanie používateľa na zdieľanie', json_encode($j));
$j = $web->api('user_search', ['q' => 'Beata@Test.sk']);
ok((int)($j['results'][0]['id'] ?? 0) === $bid2, 'hľadanie podľa celého e-mailu (aj veľkými písmenami)', json_encode($j));
$j = $web->api('user_search', ['q' => 'nikto@nikde.sk']);
ok(!empty($j['results'][0]['disabled']), 'neznámy e-mail → vysvetlenie namiesto prázdneho výsledku', json_encode($j));
$j = $web->api('share_link_save', ['type' => 'd', 'id' => $folder]);
ok(str_starts_with((string)($j['link']['url'] ?? $j['url'] ?? ''), BASE), 'verejný odkaz vedie na adresu, na ktorej je web otvorený', json_encode($j));
ok(!empty($web->api('share_user_add', ['type' => 'd', 'id' => $folder, 'users' => [$bid2], 'permission' => 'view', 'notify' => ''])['ok']), 'zdieľanie zložky s používateľom (čítanie)');
$beata = new Client();
$r = $beata->req('POST', 'app.php?action=login', ['json' => ['email' => 'beata@test.sk', 'password' => 'Heslo1234'], 'app' => true]);
$beata->token = $r['json']['token'] ?? null;
$j = $beata->api('list', ['view' => 'shared']);
ok(count($j['items'] ?? []) === 1 && ($j['items'][0]['owner'] ?? '') === 'Admin Test', 'príjemca vidí zdieľanú zložku', json_encode($j));
$j = $beata->api('list', ['view' => 'folder', 'folder' => $folder]);
ok(!empty($j['ok']) && $j['perm'] === 'view', 'príjemca otvorí zdieľanú zložku', json_encode($j));
$r = $beata->get("api.php?action=download&id=$fileId", ['ajax' => true]);
ok($r['body'] === $img, 'príjemca stiahne súbor');
ok(empty($beata->api('delete', ['items' => ["f:$fileId"]])['ok']), 'príjemca s čítaním nemôže mazať');
$up = upload($beata, $folder, 'cudzi.txt', 'x');
ok(empty($up['done']), 'príjemca s čítaním nemôže nahrávať');
$j = $beata->api('notifications');
ok(!empty($j['ok']) && in_array('share', array_column($j['items'] ?? [], 'type'), true), 'príjemca dostal upozornenie o zdieľaní', json_encode($j));
$r = $beata->get('app.php?action=notifications&after=0', ['ajax' => true]);
ok(($r['json']['last'] ?? 0) > 0, 'upozornenie je dostupné aj pre aplikáciu');
$sh = $web->api('share_get', ['type' => 'd', 'id' => $folder]);
$sid = $sh['users'][0]['id'] ?? 0;
ok(!empty($web->api('share_user_update', ['sid' => $sid, 'permission' => 'edit'])['ok']), 'zmena oprávnenia na úpravy');
$up = upload($beata, $folder, 'od-beaty.txt', 'ahoj');
ok(!empty($up['done']), 'príjemca s úpravami môže nahrávať', json_encode($up));
$noone = new Client(); $noone->token = $beata->token;
ok(empty($noone->api('list', ['view' => 'folder', 'folder' => $cam])['ok']), 'cudzia nezdieľaná zložka je neprístupná');

section('Štítky a farby');
$j = $web->api('tag_save', ['name' => 'Projekty', 'scope' => 'd', 'color' => '#188038']);
ok(!empty($j['ok']), 'nový štítok pre zložky', json_encode($j));
$tD = (int)($j['id'] ?? 0);
$j = $web->api('tag_save', ['name' => 'Faktúry', 'scope' => 'f', 'color' => '#d93025']);
ok(!empty($j['ok']), 'nový štítok pre súbory', json_encode($j));
$tF = (int)($j['id'] ?? 0);
ok(empty($web->api('tag_save', ['name' => 'Faktúry', 'scope' => 'f'])['ok']), 'duplicitný štítok sa odmietne');
ok(!empty($web->api('tag_save', ['name' => 'Faktúry', 'scope' => 'd'])['ok']), 'rovnaký názov pre zložky je samostatný štítok');
$before = array_values(array_filter($web->api('list', ['view' => 'folder', 'folder' => $folder])['items'], fn($i) => $i['type'] === 'f' && $i['id'] === $fileId))[0]['updated'] ?? '';
$j = $web->api('tags_apply', ['items' => ["d:$folder", "f:$fileId"], 'add' => [$tD, $tF]]);
ok(($j['applied'] ?? 0) === 2, 'štítky sa priradia len k správnemu typu (zložka/súbor)', json_encode($j));
$j = $web->api('color_set', ['items' => ["f:$fileId", "d:$folder"], 'color' => '#6c4cf5']);
ok(!empty($j['ok']), 'nastavenie farby súboru a zložky');
$root = $web->api('list', ['view' => 'folder', 'folder' => 0]);
$fd = array_values(array_filter($root['items'], fn($i) => $i['type'] === 'd' && $i['id'] === $folder))[0] ?? [];
ok(($fd['color'] ?? '') === '#6c4cf5' && ($fd['tags'][0]['name'] ?? '') === 'Projekty', 'zložka má farbu a štítok', json_encode($fd));
$in = $web->api('list', ['view' => 'folder', 'folder' => $folder]);
$ff = array_values(array_filter($in['items'], fn($i) => $i['type'] === 'f' && $i['id'] === $fileId))[0] ?? [];
ok(($ff['color'] ?? '') === '#6c4cf5' && ($ff['tags'][0]['name'] ?? '') === 'Faktúry', 'súbor má farbu a štítok', json_encode($ff));
ok(($ff['updated'] ?? '') === $before, 'zmena farby nemení dátum úpravy');
$okSet = !empty($web->api('color_set', ['items' => ["f:$fileId"], 'color' => 'zlá'])['ok']);
$ff2 = array_values(array_filter($web->api('list', ['view' => 'folder', 'folder' => $folder])['items'], fn($i) => $i['id'] === $fileId && $i['type'] === 'f'))[0] ?? ['color' => 'x'];
ok($okSet && array_key_exists('color', $ff2) && $ff2['color'] === null, 'neplatná farba = bez farby', json_encode($ff2['color'] ?? 'chýba'));
$web->api('color_set', ['items' => ["f:$fileId"], 'color' => '#6c4cf5']);
$j = $web->api('list', ['view' => 'tag', 'tag' => $tF]);
ok(count($j['items'] ?? []) === 1 && ($j['crumbs'][0]['name'] ?? '') === 'Faktúry', 'zobrazenie podľa štítka', json_encode($j));
$j = $web->api('list', ['view' => 'search', 'q' => 'Faktúry']);
ok(in_array($fileId, array_column(array_filter($j['items'] ?? [], fn($i) => $i['type'] === 'f'), 'id'), true), 'hľadanie podľa názvu štítka');
$j = $web->api('tags');
$cnt = array_column($j['tags'] ?? [], 'count', 'id');
ok(($cnt[$tD] ?? 0) === 1 && ($cnt[$tF] ?? 0) === 1, 'počty položiek pri štítkoch', json_encode($j));
ok(!empty($web->api('tag_save', ['id' => $tF, 'name' => 'Faktúry 2026', 'color' => '#f9ab00'])['ok']), 'premenovanie a zmena farby štítka');
$j = $beata->api('tag_save', ['name' => 'Od Admina', 'scope' => 'd']);
$tB = (int)($j['id'] ?? 0);
ok(!empty($beata->api('tags_apply', ['items' => ["d:$folder"], 'add' => [$tB]])['ok']), 'príjemca si označí zdieľanú zložku vlastným štítkom');
$fd = array_values(array_filter($web->api('list', ['view' => 'folder', 'folder' => 0])['items'], fn($i) => $i['type'] === 'd' && $i['id'] === $folder))[0] ?? [];
ok(count($fd['tags'] ?? []) === 1, 'štítky sú osobné – vlastník nevidí štítok príjemcu', json_encode($fd['tags'] ?? []));
ok(empty($beata->api('tags_apply', ['items' => ["d:$folder"], 'add' => [$tD]])['applied'] ?? 0), 'cudzí štítok sa nedá použiť');
$web->api('share_user_update', ['sid' => $sid, 'permission' => 'view']);
ok(empty($beata->api('color_set', ['items' => ["d:$folder"], 'color' => '#000000'])['ok']), 'farbu nemôže meniť používateľ s právom iba na čítanie');
$web->api('share_user_update', ['sid' => $sid, 'permission' => 'edit']);
$web->api('tags_apply', ['items' => ["d:$folder"], 'remove' => [$tD]]);
$fd = array_values(array_filter($web->api('list', ['view' => 'folder', 'folder' => 0])['items'], fn($i) => $i['type'] === 'd' && $i['id'] === $folder))[0] ?? [];
ok(empty($fd['tags']), 'odobratie štítka');
ok(!empty($web->api('tag_delete', ['id' => $tF])['ok']), 'zmazanie štítka');
$ff = array_values(array_filter($web->api('list', ['view' => 'folder', 'folder' => $folder])['items'], fn($i) => $i['type'] === 'f' && $i['id'] === $fileId))[0] ?? [];
ok(empty($ff['tags']), 'po zmazaní štítka ho súbor už nemá');

section('Verejný odkaz');
$j = $web->api('share_link_save', ['type' => 'd', 'id' => $folder, 'password' => 'tajne', 'password_mode' => 'set', 'expires' => date('Y-m-d', strtotime('+7 days'))]);
ok(!empty($j['url']), 'vytvorenie odkazu s heslom', json_encode($j));
$path = parse_url($j['url'] ?? '', PHP_URL_PATH) . '?' . parse_url($j['url'] ?? '', PHP_URL_QUERY);
$pub = new Client();
$r = $pub->get($path);
ok(str_contains($r['body'], 'type="password"'), 'odkaz žiada heslo');
$pub->loadCsrf($r['body']);
$r = $pub->post($path, ['_csrf' => $pub->csrf, 'password' => 'zle']);
$r = $pub->get($path);
ok(str_contains($r['body'], 'type="password"'), 'zlé heslo neodomkne');
$pub->loadCsrf($r['body']);
$pub->post($path, ['_csrf' => $pub->csrf, 'password' => 'tajne']);
$r = $pub->get($path);
ok(str_contains($r['body'], 'fotka.jpg'), 'po zadaní hesla vidno obsah zložky');
no_php_errors($r['body'], 's.php');
$r = $pub->get($path . "&dl=$fileId");
ok($r['body'] === $img, 'stiahnutie cez verejný odkaz');
$r = $pub->get($path . "&dl=$bid");
ok($r['body'] !== $big && $r['code'] === 404, 'cez odkaz sa nedá stiahnuť súbor mimo zdieľanej zložky');
$r = $pub->get($path . '&zip=1');
ok(str_starts_with($r['body'], "PK"), 'ZIP cez verejný odkaz');

section('Administrácia');
foreach (['dashboard', 'users', 'settings', 'smtp', 'log', 'maintenance', 'update'] as $tab) {
    $r = $web->get("admin.php?tab=$tab");
    ok($r['code'] === 200, "admin: karta $tab", "HTTP {$r['code']}");
    no_php_errors($r['body'], "admin $tab");
}
$r = $web->get('profile.php');
ok($r['code'] === 200 && str_contains($r['body'], 'Test Phone'), 'Môj účet zobrazuje prihlásenú aplikáciu');
$r = $web->get('admin.php?tab=dashboard');
ok(str_contains($r['body'], 'Disk servera') && str_contains($r['body'], '% zaplnené'), 'admin: zaplnenie disku servera');

section('Profilová fotka');
$r = $web->req('POST', 'profile.php', ['multipart' => true, 'form' => ['_csrf' => $web->csrf, 'act' => 'avatar', 'avatar' => new CURLStringFile(jpeg(640, 400, 7), 'ja.jpg', 'image/jpeg')]]);
$pdoA = new PDO('mysql:host=127.0.0.1;dbname=cloud', 'root', 'root');
$av = $pdoA->query("SELECT id, avatar_key, avatar_v FROM cloud_users WHERE avatar_key IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ok((bool)$av, 'fotka uložená', 'HTTP ' . $r['code']);
$enc = $av ? @file_get_contents(STORAGE . '/avatars/' . $av['id'] . '.bin') : '';
ok($enc !== '' && str_starts_with($enc, 'CLAV1') && !str_contains($enc, "\xFF\xD8\xFF"), 'fotka je na disku zašifrovaná');
$r = $web->get('avatar.php?u=' . ($av['id'] ?? 0) . '&v=' . ($av['avatar_v'] ?? 0));
$img = $r['code'] === 200 ? @imagecreatefromstring($r['body']) : false;
ok($img && imagesx($img) === 320 && imagesy($img) === 320, 'fotka sa vydá rozšifrovaná a orezaná na 320×320', 'HTTP ' . $r['code']);
ok((new Client())->get('avatar.php?u=' . ($av['id'] ?? 0))['code'] === 403, 'fotka nie je dostupná bez prihlásenia');
ok(str_contains($web->get('index.php')['body'], 'avatar.php?u='), 'fotka sa zobrazuje v hornej lište');
$web->post('profile.php', ['_csrf' => $web->csrf, 'act' => 'avatar_remove']);
ok(!is_file(STORAGE . '/avatars/' . ($av['id'] ?? 0) . '.bin'), 'fotka sa dá odstrániť');
no_php_errors($r['body'], 'profile.php');

$adm = fn($a, $f = []) => ($web->req('POST', "admin.php?ajax=$a", ['ajax' => true, 'form' => $web->flat($f)]))['json'] ?? ['ok' => false, 'error' => substr($web->last['body'], 0, 300)];
// nešifrovaný súbor → zašifrovanie v údržbe
$web->post('admin.php', ['_csrf' => $web->csrf, 'act' => 'encryption_setting', 'tab' => 'maintenance']);
$plain = random_bytes(150000);
$up = upload($web, 0, 'nesifrovany.bin', $plain);
$pid = (int)($up['file']['id'] ?? 0);
ok($pid && substr(file_get_contents(stored_file_of($pid)), 0, 6) !== "CLDE1\0", 'pri vypnutom šifrovaní sa ukladá nešifrovane');
$web->post('admin.php', ['_csrf' => $web->csrf, 'act' => 'encryption_setting', 'tab' => 'maintenance', 'encrypt_files' => 1]);
$j = $adm('encrypt');
ok(!empty($j['ok']) && $j['done'] >= 1 && $j['left'] === 0, 'údržba: zašifrovanie existujúcich súborov', json_encode($j));
ok(substr(file_get_contents(stored_file_of($pid)), 0, 6) === "CLDE1\0", 'súbor je po údržbe zašifrovaný');
ok($web->get("api.php?action=download&id=$pid")['body'] === $plain, 'zašifrovaný súbor sa stiahne správne');
$j = $adm('check', ['mode' => 'quick', 'after' => 0]);
ok(!empty($j['ok']) && $j['finished'] && !$j['problems'], 'rýchla kontrola integrity – bez problémov', json_encode($j));
$j = $adm('check', ['mode' => 'deep', 'after' => 0]);
while (!empty($j['ok']) && !$j['finished']) { $p = $j['problems']; $j = $adm('check', ['mode' => 'deep', 'after' => $j['next']]); $j['problems'] = array_merge($p, $j['problems'] ?? []); }
ok(!empty($j['ok']) && !$j['problems'], 'hĺbková kontrola integrity – bez problémov', json_encode($j));
// poškodenie súboru
$f = stored_file_of($pid); $c = file_get_contents($f); $c[100] = chr(ord($c[100]) ^ 0xFF); file_put_contents($f, $c);
$all = []; $after = 0;
do { $j = $adm('check', ['mode' => 'deep', 'after' => $after]); $all = array_merge($all, $j['problems'] ?? []); $after = $j['next'] ?? 0; } while (!empty($j['ok']) && !$j['finished']);
ok(count($all) === 1 && str_contains($all[0]['problem'], 'poškodený'), 'hĺbková kontrola odhalí poškodený súbor', json_encode($all));
unlink($f);
$j = $adm('check', ['mode' => 'quick', 'after' => 0]);
ok(($j['problems'][0]['missing'] ?? false) === true, 'rýchla kontrola odhalí chýbajúci súbor', json_encode($j));
$j = $adm('remove_missing', ['items' => ['f:' . $pid]]);
ok(($j['removed'] ?? 0) === 1, 'odstránenie záznamu bez dát');
$orphan = STORAGE . '/files/1/' . str_repeat('ab', 20);
file_put_contents($orphan, 'x'); touch($orphan, time() - 7200);
$j = $adm('orphans');
ok(($j['count'] ?? 0) === 1, 'nájdenie nepoužívaného súboru', json_encode($j));
$j = $adm('orphans_delete');
ok(($j['deleted'] ?? 0) === 1 && !is_file($orphan), 'zmazanie nepoužívaného súboru');
$r = $web->post('admin.php', ['_csrf' => $web->csrf, 'act' => 'download_key', 'tab' => 'maintenance']);
ok(str_contains($r['body'], 'encryption_key'), 'stiahnutie záložného kľúča');

section('Upozornenia (web)');
$j = $web->api('notifications');
ok(!empty($j['ok']), 'zoznam upozornení', json_encode($j));
ok(!empty($web->api('notifications_read')['ok']) && ($web->api('notifications_unread')['unread'] ?? 1) === 0, 'označenie ako prečítané');

section('Tisícky súborov a zložiek');
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cloud', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$uidA = (int)$pdo->query("SELECT id FROM cloud_users WHERE email = 'admin@test.sk'")->fetchColumn();
$j = $web->api('mkdir', ['parent' => 0, 'name' => 'Hromadne']);
$bulk = (int)$j['id'];
$t0 = microtime(true);
$pdo->beginTransaction();
$insF = $pdo->prepare('INSERT INTO cloud_files (user_id, folder_id, name, stored_name, size, mime) VALUES (?,?,?,?,?,?)');
for ($i = 0; $i < 5000; $i++) $insF->execute([$uidA, $bulk, sprintf('subor_%05d.jpg', $i), bin2hex(random_bytes(20)), 1000 + $i, 'image/jpeg']);
$insD = $pdo->prepare('INSERT INTO cloud_folders (user_id, parent_id, name) VALUES (?,?,?)');
$parents = [$bulk];
for ($i = 0; $i < 1000; $i++) { // 1000 zložiek v strome
    $p = $parents[array_rand($parents)];
    $insD->execute([$uidA, $p, "Zlozka $i"]);
    $parents[] = (int)$pdo->lastInsertId();
    if ($i % 4 === 0) for ($k = 0; $k < 5; $k++) $insF->execute([$uidA, end($parents), "f$k.txt", bin2hex(random_bytes(20)), 10, 'text/plain']);
}
$pdo->commit();
echo "    (vložených 6250 súborov a 1000 zložiek za " . round(microtime(true) - $t0, 1) . " s)\n";
$t = microtime(true); $j = $web->api('list', ['view' => 'folder', 'folder' => $bulk]); $dt = microtime(true) - $t;
ok(!empty($j['ok']) && count($j['items']) >= 5000, 'výpis zložky s 5000+ položkami za ' . round($dt, 2) . ' s', json_encode(array_slice($j, 0, 2)));
ok($dt < 5, 'výpis je dostatočne rýchly (< 5 s)');
$t = microtime(true); $j = $web->api('list', ['view' => 'search', 'q' => 'subor_04']); $dt = microtime(true) - $t;
ok(!empty($j['ok']) && count($j['items']) > 0 && $dt < 3, 'hľadanie v tisíckach súborov za ' . round($dt, 2) . ' s');
$t = microtime(true); $j = $web->api('list', ['view' => 'photos']); $dt = microtime(true) - $t;
ok(!empty($j['ok']) && $dt < 5, 'Fotky s tisíckami obrázkov za ' . round($dt, 2) . ' s');
$t = microtime(true); $j = $web->api('folders'); $dt = microtime(true) - $t;
ok(!empty($j['ok']) && count($j['results']) > 1000 && $dt < 5, 'zoznam 1000+ zložiek pre presun za ' . round($dt, 2) . ' s');
$t = microtime(true); $j = $web->api('info', ['type' => 'd', 'id' => $bulk]); $dt = microtime(true) - $t;
ok(!empty($j['ok']) && $dt < 5, 'informácie o veľkej zložke (rekurzívne) za ' . round($dt, 2) . ' s', json_encode($j));
$t = microtime(true); $j = $web->api('delete', ['items' => ["d:$bulk"]]); $dt = microtime(true) - $t;
ok(!empty($j['ok']) && $dt < 10, 'presun stromu 1000 zložiek a 6250 súborov do koša za ' . round($dt, 2) . ' s', json_encode($j));
$t = microtime(true); $j = $web->api('restore', ['items' => ["d:$bulk"]]); $dt = microtime(true) - $t;
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM cloud_files WHERE user_id = $uidA AND deleted_at IS NULL AND name LIKE 'subor_%'")->fetchColumn();
ok(!empty($j['ok']) && $cnt === 5000 && $dt < 10, 'obnovenie celého stromu za ' . round($dt, 2) . ' s');
$web->api('delete', ['items' => ["d:$bulk"]]);
$t = microtime(true); $j = $web->api('empty_trash'); $dt = microtime(true) - $t;
$left = (int)$pdo->query("SELECT COUNT(*) FROM cloud_files WHERE user_id = $uidA AND name LIKE 'subor_%'")->fetchColumn();
ok(!empty($j['ok']) && $left === 0 && $dt < 30, 'natrvalé zmazanie celého stromu za ' . round($dt, 2) . ' s', json_encode($j));
$r = $web->get('index.php');
ok($r['code'] === 200, 'disk sa po hromadných operáciách zobrazí');

section('Odhlásenie');
$r = $app->req('POST', 'app.php?action=logout', ['ajax' => true]);
$r = $app->api('folders');
ok(empty($r['ok']), 'po odhlásení aplikácie token neplatí');
$web->post('logout.php', ['_csrf' => $web->csrf]);
ok($web->get('index.php')['code'] === 302, 'po odhlásení webu je disk neprístupný');

echo "\n==============================\nVýsledok: $passes OK, $fails chýb\n";
exit($fails ? 1 : 0);
