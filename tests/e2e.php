<?php
/* ==================================================================
 *  End-to-end test Cloud servera (web + požiadavky Android aplikácie)
 *  Spúšťa sa v GitHub Actions proti skutočnému PHP serveru a MySQL.
 * ================================================================== */
const BASE = 'http://127.0.0.1:8080/';
define('STORAGE', getenv('CLOUD_TEST_STORAGE') ?: '/tmp/cloud-storage');
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
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
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
        $tmp = tempnam(sys_get_temp_dir(), 'ch'); file_put_contents($tmp, $part);
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
usleep(300000);
ok($disk && is_file(STORAGE . '/thumbs/' . basename(dirname($disk)) . '/' . basename($disk) . '.jpg'), 'miniatúra sa vytvorí hneď po nahratí (na pozadí)');

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
ok(stripos($r['headers'], 'Cache-Control: private, no-store') !== false, 'miniatúra sa v zariadení neukladá (no-store)');
$r = $web->get("api.php?action=download&id=$fileId&inline=1");
ok(stripos($r['headers'], 'no-store') !== false, 'náhľad súboru sa v zariadení neukladá (no-store)');

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
$zf = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($zf, $r['body']);
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
ok(!str_contains($r['body'], 'switch.php') && str_contains($r['body'], 'cloudapp://account-add') && str_contains($r['body'], 'id="appAccountsSlot"') && !str_contains($r['headers'], 'CLOUD_TEST_acc='), 'v aplikácii je menu účtu rovnaké ako na webe, účty spravuje aplikácia');
$r = $wv->get('index.php'); // požiadavka cez service worker nemá v WebView vlastný User-Agent
ok(!str_contains($r['body'], 'switch.php') && str_contains($r['body'], 'cloudapp://account-add') && str_contains($r['body'], 'cloudapp://logout') && str_contains($r['body'], 'id="accountMenu"'), 'aplikácia sa rozpozná aj bez User-Agent (cookie)');
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
$j = $web->api('list', ['view' => 'byme']);
$it = array_values(array_filter($j['items'] ?? [], fn($i) => $i['type'] === 'd' && $i['id'] === $folder))[0] ?? null;
ok($it && in_array('Beáta Testová', $it['share_info']['users'] ?? [], true), 'Zdieľané mnou: zložka aj s menom, s kým je zdieľaná', json_encode($j['items'] ?? $j));
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

section('Odkaz na nahrávanie');
$upFolder = (int)($web->api('mkdir', ['parent' => 0, 'name' => 'Schránka'])['id'] ?? 0);
$j = $web->api('share_link_save', ['type' => 'd', 'id' => $upFolder, 'mode' => 'upload']);
ok(!empty($j['url']), 'odkaz iba na nahrávanie', json_encode($j));
$upPath = parse_url($j['url'] ?? '', PHP_URL_PATH) . '?' . parse_url($j['url'] ?? '', PHP_URL_QUERY);
$guest = new Client();
$r = $guest->get($upPath);
ok(str_contains($r['body'], 'Nahraj súbory') && !str_contains($r['body'], 'Stiahnuť všetko'), 'stránka na nahrávanie bez obsahu zložky');
no_php_errors($r['body'], 's.php upload');
$guest->loadCsrf($r['body']);
$gdata = random_bytes(700000);
$init = $guest->req('POST', $upPath . '&up=init', ['ajax' => true, 'form' => ['_csrf' => $guest->csrf, 'name' => 'od-hosta.bin', 'size' => strlen($gdata), 'guest' => 'Jana']])['json'];
ok(!empty($init['ok']), 'host: začiatok nahrávania', json_encode($init));
$off = 0; $gdone = null;
while (!empty($init['ok']) && $off < strlen($gdata)) {
    $tmp = tempnam(sys_get_temp_dir(), 'g'); file_put_contents($tmp, substr($gdata, $off, 300000));
    $jr = $guest->req('POST', $upPath . '&up=chunk', ['ajax' => true, 'multipart' => true, 'form' => ['_csrf' => $guest->csrf, 'id' => $init['id'], 'offset' => (string)$off, 'chunk' => new CURLFile($tmp, 'application/octet-stream', 'chunk')]])['json'];
    unlink($tmp);
    if (empty($jr['ok'])) { $gdone = $jr; break; }
    $off = (int)$jr['received'];
    if (!empty($jr['done'])) { $gdone = $jr; break; }
}
ok(!empty($gdone['done']), 'host: súbor nahraný po častiach', json_encode($gdone));
$guest->req('POST', $upPath . '&up=done', ['ajax' => true, 'form' => ['_csrf' => $guest->csrf, 'guest' => 'Jana']]);
$sub = array_values(array_filter($web->api('list', ['folder' => $upFolder])['items'] ?? [], fn($i) => $i['type'] === 'd' && $i['name'] === 'Jana'))[0] ?? null;
$inSub = $sub ? ($web->api('list', ['folder' => $sub['id']])['items'] ?? []) : [];
ok($sub && count($inSub) === 1 && $inSub[0]['name'] === 'od-hosta.bin', 'súbor je v podzložke s menom hosťa');
$dl = $sub ? $web->get('api.php?action=download&id=' . $inSub[0]['id']) : ['body' => ''];
ok($dl['body'] === $gdata, 'nahraný obsah je zhodný');
ok($guest->get($upPath . '&dl=' . ($inSub[0]['id'] ?? 0))['code'] === 404, 'host nemôže sťahovať');
$n = $web->api('notifications');
ok(str_contains(json_encode($n['items'] ?? [], JSON_UNESCAPED_UNICODE), 'Jana nahral'), 'vlastník dostal upozornenie', json_encode($n['items'][0] ?? null, JSON_UNESCAPED_UNICODE));

section('Duplicity');
$dupData = random_bytes(5000);
upload($web, $upFolder, 'kopia-a.bin', $dupData);
upload($web, 0, 'kopia-b.bin', $dupData);
$j = $web->api('list', ['view' => 'dupes']);
$grp = array_values(array_filter($j['items'] ?? [], fn($i) => in_array($i['name'], ['kopia-a.bin', 'kopia-b.bin'], true)));
ok(count($grp) === 2 && ($grp[0]['dup']['g'] ?? 1) === ($grp[1]['dup']['g'] ?? 2) && ($grp[0]['dup']['n'] ?? 0) === 2, 'duplicitné súbory sa nájdu v jednej skupine', json_encode($grp));
ok(str_contains(json_encode($grp, JSON_UNESCAPED_UNICODE), 'Schránka'), 'pri duplicite je cesta k zložke');
$ka = array_values(array_filter($grp, fn($i) => $i['name'] === 'kopia-a.bin'))[0] ?? null;
$inf = $ka ? $web->api('info', ['type' => 'f', 'id' => $ka['id']]) : [];
ok(($inf['location']['path'] ?? '') === 'Môj disk / Schránka' && ($inf['location']['folder'] ?? 0) === $upFolder, 'informácie ukazujú umiestnenie (cestu)', json_encode($inf['location'] ?? null, JSON_UNESCAPED_UNICODE));

section('Sieťový disk (WebDAV)');
$r = $web->get('profile.php');
$web->loadCsrf($r['body']);
$web->post('profile.php', ['_csrf' => $web->csrf, 'act' => 'dav_create', 'name' => 'Test PC']);
$r = $web->get('profile.php');
preg_match('/id="davNewPw" value="([a-z0-9-]+)"/', $r['body'], $m);
$davPw = $m[1] ?? '';
ok(strlen($davPw) === 19, 'vytvorenie hesla pre sieťový disk', substr($r['body'], 0, 0));
ok(!str_contains($web->get('profile.php')['body'], $davPw ?: 'xxxxx'), 'heslo sa zobrazí iba raz');
function dav(string $method, string $path, array $o = []): array {
    global $davPw;
    $ch = curl_init(BASE . 'dav.php' . $path);
    $h = $o['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60,
        CURLOPT_USERPWD => ($o['user'] ?? 'admin@test.sk') . ':' . ($o['pw'] ?? $davPw)]);
    if (isset($o['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code' => $code, 'body' => (string)$body];
}
ok(dav('PROPFIND', '/', ['pw' => 'zle-heslo', 'headers' => ['Depth: 0']])['code'] === 401, 'WebDAV: zlé heslo → 401');
$r = dav('PROPFIND', '/', ['headers' => ['Depth: 1']]);
ok($r['code'] === 207 && str_contains($r['body'], '<d:collection/>') && str_contains($r['body'], 'quota-used-bytes'), 'WebDAV: zoznam koreňa (PROPFIND)', substr($r['body'], 0, 300));
ok(dav('MKCOL', '/Sie%C5%A5ov%C3%BD%20disk')['code'] === 201, 'WebDAV: nová zložka s diakritikou (MKCOL)');
$davData = random_bytes(1500000);
ok(dav('PUT', '/Sie%C5%A5ov%C3%BD%20disk/velky.bin', ['body' => $davData])['code'] === 201, 'WebDAV: nahratie súboru (PUT)');
$r = dav('GET', '/Sie%C5%A5ov%C3%BD%20disk/velky.bin');
ok($r['code'] === 200 && $r['body'] === $davData, 'WebDAV: stiahnutie zhodné (GET, rozšifrované)');
$r = dav('GET', '/Sie%C5%A5ov%C3%BD%20disk/velky.bin', ['headers' => ['Range: bytes=100-199']]);
ok($r['code'] === 206 && $r['body'] === substr($davData, 100, 100), 'WebDAV: čiastočné stiahnutie (Range)');
$r = dav('PROPFIND', '/Sie%C5%A5ov%C3%BD%20disk/', ['headers' => ['Depth: 1']]);
ok(str_contains($r['body'], 'velky.bin') && str_contains($r['body'], '<d:getcontentlength>1500000<'), 'WebDAV: súbor v zozname s veľkosťou');
ok(dav('LOCK', '/Sie%C5%A5ov%C3%BD%20disk/velky.bin', ['body' => '<?xml version="1.0"?><d:lockinfo xmlns:d="DAV:"><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype></d:lockinfo>'])['code'] === 200, 'WebDAV: zámok (LOCK)');
ok(dav('PUT', '/Sie%C5%A5ov%C3%BD%20disk/velky.bin', ['body' => 'nova verzia'])['code'] === 204, 'WebDAV: prepis = nová verzia');
$r = dav('MOVE', '/Sie%C5%A5ov%C3%BD%20disk/velky.bin', ['headers' => ['Destination: ' . BASE . 'dav.php/Sie%C5%A5ov%C3%BD%20disk/premenovany.txt']]);
ok($r['code'] === 201 && dav('GET', '/Sie%C5%A5ov%C3%BD%20disk/premenovany.txt')['body'] === 'nova verzia', 'WebDAV: premenovanie (MOVE)');
ok(dav('COPY', '/Sie%C5%A5ov%C3%BD%20disk', ['headers' => ['Destination: ' . BASE . 'dav.php/Kopia%20disku', 'Depth: infinity']])['code'] === 201
   && dav('GET', '/Kopia%20disku/premenovany.txt')['body'] === 'nova verzia', 'WebDAV: kópia zložky (COPY)');
ok(dav('PUT', '/._premenovany.txt', ['body' => 'mac'])['code'] === 201 && dav('GET', '/._premenovany.txt')['code'] === 404, 'WebDAV: systémové súbory macOS sa ignorujú');
ok(dav('DELETE', '/Kopia%20disku')['code'] === 204 && dav('PROPFIND', '/Kopia%20disku', ['headers' => ['Depth: 0']])['code'] === 404, 'WebDAV: zmazanie (do koša)');
$tr = $web->api('list', ['view' => 'trash']);
ok(in_array('Kopia disku', array_column($tr['items'] ?? [], 'name'), true), 'WebDAV: zmazaná zložka je v koši');
ok(dav('OPTIONS', '/', ['pw' => ''])['code'] === 200, 'WebDAV: OPTIONS bez prihlásenia');

section('Rýchlosť');
$pf = (int)($web->api('mkdir', ['parent' => 0, 'name' => 'Veľa súborov'])['id'] ?? 0);
for ($i = 1; $i <= 5; $i++) upload($web, $pf, "kus-$i.txt", "obsah $i");
$a = $web->api('list', ['folder' => $pf, 'limit' => 2]);
$b = $web->api('list', ['folder' => $pf, 'offset' => 2]);
ok(count($a['items'] ?? []) === 2 && ($a['total'] ?? 0) === 5 && count($b['items'] ?? []) === 3
   && !array_intersect(array_column($a['items'], 'id'), array_column($b['items'], 'id')), 'zoznam po častiach (prvé položky hneď, zvyšok potom)', json_encode([$a['total'] ?? null, count($b['items'] ?? [])]));
$gz = $web->req('POST', 'api.php?action=list', ['ajax' => true, 'form' => ['view' => 'recent'], 'headers' => ['Accept-Encoding: gzip']]);
ok(stripos($gz['headers'], 'Content-Encoding: gzip') !== false && is_array(json_decode((string)@gzdecode($gz['body']), true)), 'odpovede API sú komprimované (gzip)');
$home = $web->get('index.php')['body'];
ok(!is_dir(__DIR__ . '/../server/assets/vendor/bootstrap') || (str_contains($home, 'assets/vendor/bootstrap/bootstrap.min.css') && !str_contains($home, 'cdn.jsdelivr.net')), 'knižnice sa načítajú z vlastného servera');

section('Stĺpce v zozname');
$j = $web->api('prefs_save', ['key' => 'columns', 'value' => json_encode(['recent' => ['kind', 'location', 'size', 'nezmysel'], 'zly' => ['size']])]);
ok(!empty($j['ok']) && ($j['columns']['recent'] ?? null) === ['kind', 'location', 'size'] && !isset($j['columns']['zly']), 'uloženie stĺpcov (neplatné sa zahodia)', json_encode($j));
ok(str_contains($web->get('index.php')['body'], '"recent":["kind","location","size"]'), 'stĺpce sa načítajú do stránky');
$j = $web->api('list', ['view' => 'recent']);
$it = array_values(array_filter($j['items'] ?? [], fn($i) => $i['name'] === 'kopia-a.bin'))[0] ?? null;
ok($it && $it['path'] === 'Môj disk / Schránka' && !empty($it['created']), 'položky majú umiestnenie a dátum vytvorenia', json_encode($it, JSON_UNESCAPED_UNICODE));
$j = $web->api('list', ['view' => 'starred', 'sort' => 'kind', 'dir' => 'asc']);
$j2 = $web->api('list', ['folder' => $pf, 'sort' => 'created', 'dir' => 'desc']);
$cr = array_column($j2['items'] ?? [], 'created'); $sorted = $cr; rsort($sorted);
ok(!empty($j['ok']) && count($cr) === 5 && $cr === $sorted, 'triedenie podľa dátumu vytvorenia a druhu', json_encode($cr));

section('Stav servera');
$r = $web->get('admin.php');
ok(str_contains($r['body'], 'Stav servera') && str_contains($r['body'], 'Pamäť RAM') && str_contains($r['body'], 'PHP OPcache'), 'admin: stav servera (aj OPcache)');
$web->loadCsrf($r['body']);
$h = $web->req('POST', 'admin.php?ajax=health', ['ajax' => true, 'form' => ['_csrf' => $web->csrf]])['json'];
ok(!empty($h['ok']) && !empty($h['h']['mem_total']), 'stav servera sa obnovuje (RAM, záťaž)', json_encode($h));
// na čom server beží – zistí sa samo a ukáže sa v Prehľade
$expOs = getenv('CLOUD_EXPECT_OS') ?: 'linux';
preg_match('/data-platform="([a-z]+)"/', $r['body'], $mPl);
ok(($mPl[1] ?? '') === $expOs, "platforma sa zistí sama ($expOs)", $mPl[1] ?? 'nič');
ok(str_contains($r['body'], 'data-web="builtin"') && str_contains($r['body'], 'Prispôsobené'), 'Prehľad ukazuje webový server a čo sa prispôsobilo');
ok(str_contains($r['body'], 'data-protect="success"'), 'úložisko je chránené (mimo webového priečinka)');
ok(($h['h']['platform'] ?? '') === $expOs && ($h['h']['cores'] ?? 0) > 0 && ($h['h']['uptime'] ?? 0) > 0, 'stav servera má jadrá a čas behu aj na tejto platforme', json_encode([$h['h']['platform'] ?? null, $h['h']['cores'] ?? null, $h['h']['uptime'] ?? null, $h['h']['cpu'] ?? null]));

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

section('Vyhľadávanie ako v Google Disku');
$pdoS = new PDO('mysql:host=127.0.0.1;dbname=cloud', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sd = (int)($web->api('mkdir', ['parent' => 0, 'name' => 'Hľadanie test'])['id'] ?? 0);
$sub2 = (int)($web->api('mkdir', ['parent' => $sd, 'name' => 'Zmluvy 2026'])['id'] ?? 0);
$mkDocx = function (string $text): string {
    $tmp = tempnam(sys_get_temp_dir(), 'dx'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . htmlspecialchars($text) . '</w:t></w:r></w:p></w:body></w:document>');
    $z->close(); $d = file_get_contents($tmp); unlink($tmp); return $d;
};
$sIds = [];
foreach ([[$sub2, 'Zmluva o nájme.docx', $mkDocx('Nájomná zmluva byt Bratislava kolibrík')], [$sd, 'Rozpočet domácnosti.csv', "polozka;suma\nnajom;500\n"],
          [$sd, 'Dovolenka more.jpg', jpeg(320, 200, 7)], [$sd, 'zaloha velka.bin', random_bytes(1200000)], [$sd, 'poznamky zmluva.txt', "obyčajný text o zmluve\npapagáj"]] as [$fo, $n, $data]) {
    $u = upload($web, $fo, $n, $data);
    $sIds[$n] = (int)($u['file']['id'] ?? 0);
}
ok(count(array_filter($sIds)) === 5, 'testovacie súbory na hľadanie nahraté', json_encode($sIds));
$names = fn(array $j) => array_column($j['items'] ?? [], 'name');
$j = $web->api('list', ['view' => 'search', 'q' => 'zmluva']);
ok(in_array('Zmluva o nájme.docx', $names($j), true) && in_array('poznamky zmluva.txt', $names($j), true), 'hľadanie podľa názvu', json_encode($names($j)));
$j = $web->api('list', ['view' => 'search', 'q' => 'nájme zmluva']);
ok($names($j) === ['Zmluva o nájme.docx'], 'všetky slová v ľubovoľnom poradí', json_encode($names($j)));
$j = $web->api('list', ['view' => 'search', 'q' => 'zmluva', 'type' => 'doc']);
ok($names($j) === ['Zmluva o nájme.docx'], 'filter typu: dokumenty', json_encode($names($j)));
$j = $web->api('list', ['view' => 'search', 'q' => 'zmluva type:text']);
ok($names($j) === ['poznamky zmluva.txt'], 'operátor type:text', json_encode($names($j)));
$j = $web->api('list', ['view' => 'search', 'q' => '', 'type' => 'image', 'where' => (string)$sd]);
ok($names($j) === ['Dovolenka more.jpg'], 'iba filter (bez textu): obrázky v zložke', json_encode($j));
$j = $web->api('list', ['view' => 'search', 'q' => 'zmluvy', 'type' => 'folder']);
ok($names($j) === ['Zmluvy 2026'] && ($j['items'][0]['type'] ?? '') === 'd', 'filter typu: zložky', json_encode($names($j)));
$j = $web->api('list', ['view' => 'search', 'q' => 'size>1mb', 'where' => (string)$sd]);
ok($names($j) === ['zaloha velka.bin'], 'operátor size>1mb', json_encode($names($j)));
$j = $web->api('list', ['view' => 'search', 'q' => '', 'where' => (string)$sd, 'size_max' => 1000]);
ok($names($j) && !in_array('zaloha velka.bin', $names($j), true) && !in_array('Zmluvy 2026', $names($j), true), 'filter veľkosti (bez zložiek)', json_encode($names($j)));
$j = $web->api('list', ['view' => 'search', 'q' => 'zmluva', 'where' => (string)$sub2]);
ok($names($j) === ['Zmluva o nájme.docx'], 'hľadanie iba v zložke (vrátane podzložiek)', json_encode($names($j)));
$j = $web->api('list', ['view' => 'search', 'q' => 'zmluva before:2000-01-01']);
ok(($j['ok'] ?? false) && !$j['items'], 'operátor before: (nič staršie)', json_encode($j));
$j = $web->api('list', ['view' => 'search', 'q' => 'zmluva', 'modified' => 'today']);
ok(count($j['items'] ?? []) >= 2, 'filter dátumu: dnes');
$web->api('star', ['items' => ['f:' . $sIds['poznamky zmluva.txt']], 'value' => 1]);
$j = $web->api('list', ['view' => 'search', 'q' => 'is:starred zmluva']);
ok($names($j) === ['poznamky zmluva.txt'], 'operátor is:starred', json_encode($names($j)));
$web->api('delete', ['items' => ['f:' . $sIds['Rozpočet domácnosti.csv']]]);
$j = $web->api('list', ['view' => 'search', 'q' => 'rozpočet']);
ok(!$names($j), 'súbor v koši sa bežne nehľadá');
$j = $web->api('list', ['view' => 'search', 'q' => 'rozpočet in:trash']);
ok($names($j) === ['Rozpočet domácnosti.csv'] && !empty($j['items'][0]['deleted']), 'operátor in:trash hľadá v koši', json_encode($names($j)));
$web->api('restore', ['items' => ['f:' . $sIds['Rozpočet domácnosti.csv']]]);
$j = $web->api('list', ['view' => 'search', 'q' => 'dovolenka']);
ok(($j['items'][0]['path'] ?? '') !== '' && str_contains($j['items'][0]['path'] ?? '', 'Hľadanie test'), 'výsledok má umiestnenie', json_encode($j['items'][0] ?? []));
$j = $web->api('suggest', ['q' => 'dovol']);
ok(($j['items'][0]['name'] ?? '') === 'Dovolenka more.jpg', 'návrhy pri písaní', json_encode($j));
$j = $web->api('search_meta');
ok(!empty($j['ok']) && count($j['types'] ?? []) >= 8 && empty($j['content']), 'údaje pre rozšírené vyhľadávanie (index obsahu predvolene vypnutý)', json_encode($j));
// zdieľané so mnou
ok(!empty($web->api('share_user_add', ['type' => 'd', 'id' => $sd, 'users' => [$bid2], 'permission' => 'view', 'notify' => ''])['ok']), 'zložka na hľadanie zdieľaná s Beátou');
$j = $beata->api('list', ['view' => 'search', 'q' => 'zmluva']);
$it = array_values(array_filter($j['items'] ?? [], fn($i) => $i['name'] === 'Zmluva o nájme.docx'))[0] ?? null;
ok($it && $it['owner'] === 'Admin Test' && $it['perm'] === 'view', 'hľadanie nájde aj súbory zdieľané so mnou (aj v podzložke)', json_encode($j));
$j = $beata->api('list', ['view' => 'search', 'q' => 'zmluva', 'owner' => 'me']);
ok(!in_array('Zmluva o nájme.docx', $names($j), true), 'filter vlastník: ja (bez cudzích)', json_encode($names($j)));
$j = $beata->api('list', ['view' => 'search', 'q' => 'zmluva owner:others']);
ok(in_array('Zmluva o nájme.docx', $names($j), true) && !array_filter($j['items'] ?? [], fn($i) => empty($i['owner'])), 'operátor owner:others', json_encode($names($j)));
$r = $beata->get("api.php?action=download&id=" . $sIds['Zmluva o nájme.docx'], ['ajax' => true]);
ok(str_starts_with($r['body'], 'PK'), 'príjemca otvorí nájdený zdieľaný súbor');
// index obsahu
$j = $web->api('list', ['view' => 'search', 'q' => 'kolibrík']);
ok(!$names($j), 'bez indexu sa obsah nehľadá');
$pdoS->exec("INSERT INTO cloud_settings (k, v) VALUES ('content_index', '1') ON DUPLICATE KEY UPDATE v = '1'");
$u = upload($web, $sd, 'recept.txt', "Bábovka: múka, cukor, vajíčka a kakao.\nPečieme 40 minút.");
$found = []; $snip = null;
for ($i = 0; $i < 20 && !$found; $i++) {
    usleep(300000);
    $j = $web->api('list', ['view' => 'search', 'q' => 'vajíčka']);
    $found = $names($j); $snip = $j['items'][0]['snip'] ?? null;
}
ok($found === ['recept.txt'], 'index obsahu: nový súbor sa nájde podľa textu vnútri', json_encode($j));
ok($snip && str_contains($snip, 'vajíčka'), 'výsledok má úryvok textu', (string)$snip);
$r = $web->post('admin.php', ['_csrf' => $web->csrf, 'act' => 'content_index', 'content_index' => '1', 'tab' => 'maintenance']);
$hit = [];
for ($i = 0; $i < 30 && !$hit; $i++) {
    usleep(400000);
    $j = $web->api('list', ['view' => 'search', 'q' => 'kolibrík']);
    $hit = $names($j);
}
ok($hit === ['Zmluva o nájme.docx'], 'index obsahu: staršie dokumenty (Word) sa doindexujú', json_encode($j));
$j = $web->api('list', ['view' => 'search', 'q' => 'kolibrík', 'content' => '0']);
ok(!$names($j), 'hľadanie v obsahu sa dá vypnúť');
$j = $beata->api('list', ['view' => 'search', 'q' => 'kolibrík']);
ok($names($j) === ['Zmluva o nájme.docx'], 'obsah zdieľaných dokumentov sa hľadá aj u príjemcu');
$j = $web->api('list', ['view' => 'search', 'q' => 'papagáj type:pdf']);
ok(!$names($j), 'obsah + filter typu');
$web->post('admin.php', ['_csrf' => $web->csrf, 'act' => 'content_index', 'tab' => 'maintenance']);
usleep(800000);
ok((int)$pdoS->query('SELECT COUNT(*) FROM cloud_file_text')->fetchColumn() === 0, 'vypnutie indexu zmaže uložený text');
ok(!$names($web->api('list', ['view' => 'search', 'q' => 'kolibrík'])), 'po vypnutí sa obsah nehľadá');
$r = $web->get('index.php');
ok(str_contains($r['body'], 'id="searchFilterBtn"') && str_contains($r['body'], 'id="searchChips"'), 'na stránke je rozšírené vyhľadávanie a čipy filtrov');
no_php_errors($web->get('admin.php?tab=maintenance')['body'], 'údržba s indexom obsahu');
$web->api('delete', ['items' => ["d:$sd"]]);

section('Zložky zamknuté heslom');
$lk = (int)($web->api('mkdir', ['parent' => 0, 'name' => 'Trezor'])['id'] ?? 0);
$lkSub = (int)($web->api('mkdir', ['parent' => $lk, 'name' => 'Vnútri'])['id'] ?? 0);
$lkFile = (int)(upload($web, $lk, 'tajny-plan.txt', 'prisne tajne')['file']['id'] ?? 0);
$lkDeep = (int)(upload($web, $lkSub, 'tajna-fotka.jpg', jpeg(200, 120, 3))['file']['id'] ?? 0);
ok($lk && $lkSub && $lkFile && $lkDeep, 'zložka s obsahom na zamknutie');
ok(!empty($web->api('share_user_add', ['type' => 'd', 'id' => $lk, 'users' => [$bid2], 'permission' => 'view', 'notify' => ''])['ok']), 'zložka zdieľaná s Beátou');
$lj = $web->api('share_link_save', ['type' => 'd', 'id' => $lk]);
$lkUrl = (string)($lj['link']['url'] ?? $lj['url'] ?? '');
ok($lkUrl !== '', 'verejný odkaz na zložku', json_encode($lj));
ok(empty($beata->api('folder_lock', ['id' => $lk, 'op' => 'set', 'new' => 'heslo1'])['ok']), 'heslo môže nastaviť iba vlastník');
ok(empty($web->api('folder_lock', ['id' => $lk, 'op' => 'set', 'new' => 'ab'])['ok']), 'príliš krátke heslo sa odmietne');
$j = $web->api('folder_lock', ['id' => $lk, 'op' => 'set', 'new' => 'Trezor123']);
ok(!empty($j['ok']) && ($j['links'] ?? 0) === 1, 'zamknutie zložky heslom', json_encode($j));
$j = $web->api('list', ['view' => 'folder', 'folder' => $lk]);
ok(empty($j['ok']) && ($j['locked']['id'] ?? 0) === $lk && ($j['locked']['name'] ?? '') === 'Trezor', 'zamknutá zložka sa bez hesla neotvorí', json_encode($j));
ok(empty($web->api('list', ['view' => 'folder', 'folder' => $lkSub])['ok']), 'ani jej podzložka');
ok($web->get("api.php?action=download&id=$lkFile")['code'] === 404, 'súbor v nej sa nedá stiahnuť');
ok($web->get("api.php?action=thumb&id=$lkDeep")['code'] === 404, 'ani miniatúra fotky v podzložke');
ok(empty($web->api('rename', ['type' => 'f', 'id' => $lkFile, 'name' => 'x.txt'])['ok']), 'ani premenovať súbor v nej');
$j = $web->api('list', ['view' => 'folder', 'folder' => 0]);
$it = array_values(array_filter($j['items'] ?? [], fn($i) => $i['type'] === 'd' && $i['id'] === $lk))[0] ?? null;
ok($it && $it['locked'] === true && $it['unlocked'] === false, 'v zozname je zložka označená zámkom', json_encode($it));
$names = fn(array $j) => array_column($j['items'] ?? [], 'name');
ok(!in_array('tajny-plan.txt', $names($web->api('list', ['view' => 'search', 'q' => 'tajn'])), true), 'hľadanie nenájde obsah zamknutej zložky');
ok(!in_array('tajny-plan.txt', $names($web->api('list', ['view' => 'recent'])), true), 'nedávne neukážu obsah zamknutej zložky');
ok(!in_array('tajna-fotka.jpg', $names($web->api('list', ['view' => 'photos'])), true), 'fotky neukážu obsah zamknutej zložky');
ok(empty($beata->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), 'zámok platí aj pre toho, s kým je zložka zdieľaná');
$r = $web->get('s.php?t=' . (explode('t=', $lkUrl)[1] ?? ''));
ok(str_contains($r['body'], 'zamkol heslom') && !str_contains($r['body'], 'tajny-plan'), 'verejný odkaz na zamknutú zložku nefunguje', substr(strip_tags($r['body']), 0, 200));
$pub = new Client();
$r = $pub->get('s.php?t=' . (explode('t=', $lkUrl)[1] ?? ''));
ok(str_contains($r['body'], 'zamkol heslom'), 'ani pre návštevníka bez prihlásenia');
$r = dav('PROPFIND', '/', ['headers' => ['Depth: 1']]);
ok($r['code'] === 207 && !str_contains($r['body'], 'Trezor'), 'sieťový disk zamknutú zložku neukáže');
ok(dav('PROPFIND', '/Trezor', ['headers' => ['Depth: 1']])['code'] === 404, 'ani priamo cez adresu');
$j = $web->api('folder_unlock', ['id' => $lk, 'password' => 'zle']);
ok(empty($j['ok']), 'nesprávne heslo zložky');
$j = $web->api('folder_unlock', ['id' => $lk, 'password' => 'Trezor123']);
ok(!empty($j['ok']), 'odomknutie heslom', json_encode($j));
$j = $web->api('list', ['view' => 'folder', 'folder' => $lk]);
ok(!empty($j['ok']) && in_array('tajny-plan.txt', $names($j), true) && !empty($j['no_cache']) && ($j['lock_here']['id'] ?? 0) === $lk, 'po odomknutí je obsah dostupný (a neukladá sa do cache)', json_encode(array_diff_key($j, ['items' => 1])));
ok(!empty($web->api('list', ['view' => 'folder', 'folder' => $lkSub])['ok']), 'aj podzložka');
ok($web->get("api.php?action=download&id=$lkFile")['body'] === 'prisne tajne', 'aj stiahnutie súboru');
$j = $web->api('list', ['view' => 'search', 'q' => 'tajn']);
ok(in_array('tajny-plan.txt', $names($j), true) && !empty($j['no_cache']), 'hľadanie po odomknutí nájde obsah');
ok(empty($beata->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), 'odomknutie platí iba v mojej relácii');
ok(!empty($web->api('folder_relock', ['id' => $lk])['ok']) && empty($web->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), 'zamknutie teraz');
ok(empty($web->api('folder_lock', ['id' => $lk, 'op' => 'change', 'password' => 'zle', 'new' => 'Nove1234'])['ok']), 'zmena hesla vyžaduje súčasné heslo');
ok(!empty($web->api('folder_lock', ['id' => $lk, 'op' => 'change', 'password' => 'Heslo1234', 'new' => 'Nove1234'])['ok']), 'zabudnuté heslo zložky sa dá zmeniť heslom k účtu');
ok(empty($web->api('folder_unlock', ['id' => $lk, 'password' => 'Trezor123'])['ok']) && !empty($web->api('folder_unlock', ['id' => $lk, 'password' => 'Nove1234'])['ok']), 'platí nové heslo');
ok(!empty($web->api('folder_lock', ['id' => $lk, 'op' => 'remove', 'password' => 'Nove1234'])['ok']), 'zrušenie hesla');
ok(!empty($beata->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), 'po zrušení hesla je zložka znova bežne dostupná');
// kedy pýtať heslo znova
$dropSession = function (Client $c) { // „zatvorenie prehliadača“: zmiznú cookies platné iba počas relácie
    $l = array_filter(file($c->jar), fn($x) => !preg_match('/^(#HttpOnly_)?[^#\t]*\t\w+\t[^\t]*\t\w+\t0\t/', $x));
    file_put_contents($c->jar, implode('', $l));
};
$bc = new Client();
$bc->loadCsrf($bc->get('login.php')['body']);
$bc->post('login.php', ['_csrf' => $bc->csrf, 'email' => 'admin@test.sk', 'password' => 'Heslo1234', 'remember' => '1']);
$bc->loadCsrf($bc->get('index.php')['body']);
ok(!empty($web->api('folder_lock', ['id' => $lk, 'op' => 'set', 'new' => 'Rezim123', 'mode' => 'always'])['ok']), 'zamknutie s voľbou „vždy“');
$j = $web->api('list', ['view' => 'folder', 'folder' => 0]);
$it = array_values(array_filter($j['items'] ?? [], fn($i) => $i['type'] === 'd' && $i['id'] === $lk))[0] ?? null;
ok(($it['lock_mode'] ?? '') === 'always', 'voľba je v údajoch zložky', json_encode($it));
ok(!empty($bc->api('folder_unlock', ['id' => $lk, 'password' => 'Rezim123'])['ok']) && !empty($bc->api('list', ['view' => 'folder', 'folder' => $lkSub])['ok']), '„vždy“: odomknutá, aj podzložka');
$bc->api('list', ['view' => 'folder', 'folder' => 0]);
ok(empty($bc->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), '„vždy“: po odchode zo zložky sa hneď zamkne');
ok(empty($web->api('folder_lock', ['id' => $lk, 'op' => 'mode', 'password' => 'zle', 'mode' => 'close'])['ok']), 'zmena voľby vyžaduje heslo');
ok(!empty($web->api('folder_lock', ['id' => $lk, 'op' => 'mode', 'password' => 'Rezim123', 'mode' => 'close'])['ok']), 'zmena voľby na „po zatvorení“');
$r = $bc->req('POST', 'api.php?action=folder_unlock', ['ajax' => true, 'form' => ['id' => $lk, 'password' => 'Rezim123']]);
ok(!empty($r['json']['ok']) && !str_contains($r['headers'], 'CLOUD_TEST_fu='), '„po zatvorení“: odomknutie iba v relácii');
$bc->api('list', ['view' => 'folder', 'folder' => 0]);
ok(!empty($bc->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), '„po zatvorení“: ostane odomknutá aj po odchode zo zložky');
$dropSession($bc); $bc->loadCsrf($bc->get('index.php')['body']);
ok(empty($bc->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), '„po zatvorení“: po zatvorení prehliadača je znova zamknutá');
$web->api('folder_lock', ['id' => $lk, 'op' => 'mode', 'password' => 'Rezim123', 'mode' => 'logout']);
$r = $bc->req('POST', 'api.php?action=folder_unlock', ['ajax' => true, 'form' => ['id' => $lk, 'password' => 'Rezim123']]);
ok(!empty($r['json']['ok']) && str_contains($r['headers'], 'CLOUD_TEST_fu='), '„až po odhlásení“: odomknutie si pamätá cookie', $r['headers']);
$dropSession($bc); $bc->loadCsrf($bc->get('index.php')['body']);
ok(!empty($bc->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), '„až po odhlásení“: ostane odomknutá aj po zatvorení prehliadača');
$r = $bc->post('logout.php', ['_csrf' => $bc->csrf]);
ok(str_contains($r['headers'], 'CLOUD_TEST_fu=;') || str_contains($r['headers'], 'CLOUD_TEST_fu=deleted'), 'odhlásenie zmaže zapamätané odomknutia', $r['headers']);
$bc->loadCsrf($bc->get('login.php')['body']);
$bc->post('login.php', ['_csrf' => $bc->csrf, 'email' => 'admin@test.sk', 'password' => 'Heslo1234']);
$bc->loadCsrf($bc->get('index.php')['body']);
ok(empty($bc->api('list', ['view' => 'folder', 'folder' => $lk])['ok']), '„až po odhlásení“: po novom prihlásení je zamknutá');
$web->api('folder_lock', ['id' => $lk, 'op' => 'remove', 'password' => 'Rezim123']);

$r = $web->get('index.php');
ok(str_contains($r['body'], 'data-ctx="lock"') && str_contains($r['body'], 'id="relockBtn"'), 'v menu je zamknutie heslom');
$web->api('delete', ['items' => ["d:$lk"]]);

section('Aktivita a vrátenie zložky do stavu k dátumu');
$pdoA = new PDO('mysql:host=127.0.0.1;dbname=cloud', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$names = fn(array $j) => array_column($j['items'] ?? [], 'name');
$rw = (int)($web->api('mkdir', ['parent' => 0, 'name' => 'Vrátenie'])['id'] ?? 0);
$rwSub = (int)($web->api('mkdir', ['parent' => $rw, 'name' => 'Pod'])['id'] ?? 0);
$fA = (int)(upload($web, $rw, 'a.txt', 'verzia jedna')['file']['id'] ?? 0);
$fB = (int)(upload($web, $rw, 'b.txt', 'bé')['file']['id'] ?? 0);
$fE = (int)(upload($web, $rw, 'e.txt', 'é')['file']['id'] ?? 0);
$fM = (int)(upload($web, $rw, 'm.txt', 'em')['file']['id'] ?? 0);
ok($rw && $rwSub && $fA && $fB && $fE && $fM, 'zložka na vrátenie');
sleep(2);
$T = $pdoA->query('SELECT NOW()')->fetchColumn();
sleep(2);
upload($web, $rw, 'a.txt', 'verzia dva – prepísaná');
$web->api('rename', ['type' => 'f', 'id' => $fB, 'name' => 'c.txt']);
$web->api('move', ['items' => ["f:$fM"], 'target' => $rwSub]);
$web->api('delete', ['items' => ["f:$fE"]]);
$fN = (int)(upload($web, $rw, 'novy.txt', 'nový')['file']['id'] ?? 0);
$share = $web->api('share_user_add', ['type' => 'd', 'id' => $rw, 'users' => [$bid2], 'permission' => 'edit', 'notify' => '']);
upload($beata, $rw, 'od-beaty-2.txt', 'ahoj');
$j = $web->api('activity');
$acts = array_map(fn($a) => $a['action'] . ':' . $a['name'], $j['items'] ?? []);
ok(!empty($j['ok']) && in_array('rename:c.txt', $acts, true) && in_array('update:a.txt', $acts, true) && in_array('move:m.txt', $acts, true)
   && in_array('trash:e.txt', $acts, true) && in_array('create:Vrátenie', $acts, true) && in_array('share:Vrátenie', $acts, true), 'aktivita celého disku', json_encode(array_slice($acts, 0, 20)));
$bAct = array_values(array_filter($j['items'] ?? [], fn($a) => $a['name'] === 'od-beaty-2.txt'))[0] ?? null;
ok($bAct && $bAct['action'] === 'upload' && $bAct['actor'] !== 'Ty' && !$bAct['mine'], 'vidno, že súbor do mojej zložky nahrala Beáta', json_encode($bAct));
$ren = array_values(array_filter($j['items'] ?? [], fn($a) => $a['action'] === 'rename' && $a['name'] === 'c.txt'))[0] ?? null;
ok(($ren['extra']['from'] ?? '') === 'b.txt' && $ren['actor'] === 'Ty', 'pri premenovaní je pôvodný názov', json_encode($ren));
$j = $web->api('activity', ['type' => 'f', 'id' => $fA]);
ok(count($j['items'] ?? []) >= 2 && $j['items'][0]['action'] === 'update', 'aktivita jedného súboru', json_encode($j));
$j = $web->api('activity', ['type' => 'd', 'id' => $rwSub]);
ok(in_array('move', array_column($j['items'] ?? [], 'action'), true), 'aktivita zložky obsahuje aj zmeny vnútri');
$j = $beata->api('activity', ['type' => 'd', 'id' => $rw]);
ok(!empty($j['ok']) && count($j['items']) > 3, 'aktivitu zdieľanej zložky vidí aj príjemca');
ok(empty($beata->api('activity', ['type' => 'f', 'id' => $lkFile ?? 999999])['ok']), 'aktivita cudzieho súboru bez prístupu sa neukáže');
$j = $web->api('folder_rewind', ['id' => $rw, 'at' => $T, 'trash_new' => 1]);
$x = $j['result'] ?? [];
ok(!empty($j['ok']) && $x['restore'] === 1 && $x['content'] === 1 && $x['renames'] === 2 && $x['trash'] >= 2, 'náhľad vrátenia: čo sa zmení', json_encode($j));
ok(in_array('a.txt', $names($web->api('list', ['view' => 'folder', 'folder' => $rw])), true) && $web->get("api.php?action=download&id=$fA")['body'] === 'verzia dva – prepísaná', 'náhľad nič nezmenil');
ok(empty($web->api('folder_rewind', ['id' => $rw, 'at' => date('Y-m-d H:i:s', time() + 3 * 86400), 'apply' => 1])['ok']), 'dátum v budúcnosti sa odmietne');
ok(empty($beata->api('folder_rewind', ['id' => $lkSub ?? 999999, 'at' => $T])['ok']), 'cudziu zložku bez prístupu vrátiť nejde');
$j = $web->api('folder_rewind', ['id' => $rw, 'at' => $T, 'trash_new' => 1, 'apply' => 1]);
ok(!empty($j['ok']), 'vrátenie zložky do stavu k dátumu', json_encode($j));
$now = $names($web->api('list', ['view' => 'folder', 'folder' => $rw]));
sort($now);
ok($now === ['Pod', 'a.txt', 'b.txt', 'e.txt', 'm.txt'], 'obsah zložky je ako v danom čase', json_encode($now));
ok($web->get("api.php?action=download&id=$fA")['body'] === 'verzia jedna', 'súbor má obsah z daného času');
ok(!$names($web->api('list', ['view' => 'folder', 'folder' => $rwSub])), 'presunutý súbor je späť na pôvodnom mieste');
ok(in_array('novy.txt', $names($web->api('list', ['view' => 'trash'])), true), 'neskôr vytvorený súbor je v koši (dá sa obnoviť)');
$j = $web->api('versions', ['id' => $fA]);
ok(count($j['versions'] ?? []) >= 2, 'prepísaný obsah ostal ako staršia verzia');
ok(($web->api('activity', ['type' => 'd', 'id' => $rw])['items'][0]['action'] ?? '') === 'rewind', 'vrátenie je v aktivite');
$j = $web->api('list', ['view' => 'activity']);
$first = $j['items'][0] ?? null;
ok(!empty($j['ok']) && $first && !empty($first['act']['label']) && !empty($first['act']['date']), 'Aktivita ako zoznam zmien (riadok = jedna zmena)', json_encode($first));
$firstOf = function (string $name) use ($j) { foreach ($j['items'] ?? [] as $i) if ($i['name'] === $name) return $i; return null; };
$aRow = $firstOf('a.txt');
ok(($aRow['act']['action'] ?? '') === 'version' && ($aRow['act']['actor'] ?? '') === 'Ty' && ($aRow['type'] ?? '') === 'f' && !empty($aRow['id']), 'riadok zmeny je zároveň súbor (dá sa otvoriť, ⋮ menu)', json_encode($aRow));
ok(count(array_filter($j['items'] ?? [], fn($i) => $i['name'] === 'a.txt')) >= 3, 'jeden súbor môže mať viac riadkov (každá zmena zvlášť)');
$nRow = $firstOf('novy.txt');
ok($nRow && !empty($nRow['deleted']), 'zmeny položiek v koši sú označené ako zmazané', json_encode($nRow));
$dates = array_map(fn($i) => $i['act']['date'], $j['items'] ?? []);
$sorted = $dates; rsort($sorted);
ok($dates === $sorted, 'zoradené od najnovšej zmeny');
$ren = null; foreach ($j['items'] ?? [] as $i) if (($i['act']['action'] ?? '') === 'rename') { $ren = $i; break; }
ok(($ren['act']['extra']['from'] ?? '') === 'b.txt', 'pri premenovaní je pôvodný názov', json_encode($ren));
$r = $web->get('index.php');
ok(str_contains($r['body'], 'data-ctx="rewind"') && str_contains($r['body'], 'data-ctx="activity"') && str_contains($r['body'], 'index.php#activity'), 'v menu je Aktivita a Vrátiť do stavu');
$web->api('delete', ['items' => ["d:$rw"]]);

section('Fotky: albumy, mapa, v tento deň, prezentácia');
$gpsJpeg = function (int $seed): string { // JPEG s GPS polohou (Bratislava) v EXIF
    $img = jpeg(320, 240, $seed);
    $rat = fn(int $n, int $d) => pack('VV', $n, $d);
    $tiff = 'II' . pack('v', 42) . pack('V', 8)
        . pack('v', 1) . pack('vvVV', 0x8825, 4, 1, 26) . pack('V', 0)
        . pack('v', 4)
        . pack('vvV', 1, 2, 2) . "N\0\0\0"
        . pack('vvVV', 2, 5, 3, 80)
        . pack('vvV', 3, 2, 2) . "E\0\0\0"
        . pack('vvVV', 4, 5, 3, 104)
        . pack('V', 0)
        . $rat(48, 1) . $rat(8, 1) . $rat(2400, 100)
        . $rat(17, 1) . $rat(6, 1) . $rat(3600, 100);
    $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\0\0" . $tiff;
    return substr($img, 0, 2) . $app1 . substr($img, 2);
};
$ph = (int)($web->api('mkdir', ['parent' => 0, 'name' => 'Dovolenka foto'])['id'] ?? 0);
$p1 = (int)(upload($web, $ph, 'more1.jpg', $gpsJpeg(11))['file']['id'] ?? 0);
$p2 = (int)(upload($web, $ph, 'more2.jpg', jpeg(320, 240, 12))['file']['id'] ?? 0);
ok($p1 && $p2, 'fotky nahraté');
// mapa je zrušená: poloha z fotiek sa nikam neukladá
$j = $web->api('photo_map');
ok(empty($j['ok']), 'mapa fotiek už neexistuje');
$ll = $pdoA->query("SELECT COUNT(*) FROM cloud_files WHERE lat IS NOT NULL OR lng IS NOT NULL")->fetchColumn();
ok((int)$ll === 0, 'poloha z GPS fotky sa neuloží do databázy');
// albumy
$j = $web->api('album_save', ['name' => 'More 2026']);
$al = (int)($j['id'] ?? 0);
ok($al > 0, 'vytvorenie albumu');
$j = $web->api('album_add', ['album' => $al, 'items' => ["f:$p1", "f:$p2"]]);
ok(!empty($j['ok']) && $j['added'] === 2, 'pridanie fotiek do albumu', json_encode($j));
ok(($web->api('album_add', ['album' => $al, 'items' => ["f:$p1"]])['added'] ?? -1) === 0, 'tá istá fotka sa nepridá dvakrát');
$j = $web->api('albums');
$a = array_values(array_filter($j['albums'] ?? [], fn($x) => $x['id'] === $al))[0] ?? null;
ok($a && $a['count'] === 2 && $a['cover'], 'zoznam albumov s počtom a obalom', json_encode($a));
$j = $web->api('list', ['view' => 'album', 'album' => $al]);
ok(!empty($j['ok']) && count($j['items']) === 2 && ($j['crumbs'][0]['name'] ?? '') === 'More 2026', 'zobrazenie albumu', json_encode(array_diff_key($j, ['items' => 1])));
ok(empty($beata->api('list', ['view' => 'album', 'album' => $al])['ok']), 'cudzí album sa nedá otvoriť');
ok(empty($beata->api('album_add', ['album' => $al, 'items' => ["f:$p1"]])['ok']), 'do cudzieho albumu sa nedá pridávať');
$web->api('album_cover', ['album' => $al, 'file' => $p2]);
ok((array_values(array_filter($web->api('albums')['albums'], fn($x) => $x['id'] === $al))[0]['cover']['id'] ?? 0) === $p2, 'nastavenie obalu albumu');
$web->api('album_remove', ['album' => $al, 'items' => ["f:$p2"]]);
ok(count($web->api('list', ['view' => 'album', 'album' => $al])['items'] ?? []) === 1 && $web->get("api.php?action=thumb&id=$p2")['code'] === 200, 'odstránenie z albumu (súbor ostane)');
$web->api('delete', ['items' => ["f:$p1"]]);
ok(count($web->api('list', ['view' => 'album', 'album' => $al])['items'] ?? []) === 0, 'súbor v koši sa v albume neukáže');
$web->api('restore', ['items' => ["f:$p1"]]);
ok(!empty($web->api('album_save', ['id' => $al, 'name' => 'Leto'])['ok']), 'premenovanie albumu');
// v tento deň
$pdoA->exec("UPDATE cloud_files SET taken_at = NOW() - INTERVAL 2 YEAR WHERE id = $p2");
$j = $web->api('memories');
$g = $j['groups'][0] ?? null;
ok($g && $g['ago'] === 2 && in_array($p2, array_column($g['items'], 'id'), true), '„V tento deň“ ukáže fotky spred rokov', json_encode($j));
ok(!empty($web->api('album_delete', ['id' => $al])['ok']) && empty($web->api('list', ['view' => 'album', 'album' => $al])['ok']), 'zmazanie albumu');
ok($web->get("api.php?action=thumb&id=$p1")['code'] === 200, 'fotky po zmazaní albumu ostali');
$r = $web->get('index.php');
ok(str_contains($r['body'], 'id="photoBar"') && str_contains($r['body'], 'id="previewPlay"') && !str_contains($r['body'], 'leaflet'), 'na stránke je lišta fotiek a prezentácia (bez mapy)');
$web->api('delete', ['items' => ["d:$ph"]]);

section('Týždenný súhrn e-mailom');
$j = $web->api('digest_preview');
ok(!empty($j['ok']) && str_contains($j['html'] ?? '', 'Využívaš') && ($j['data']['uploaded'] ?? 0) > 0, 'obsah súhrnu (nahraté súbory, miesto)', json_encode($j['data'] ?? $j));
ok(str_contains($j['html'] ?? '', 'Beáta') || str_contains($j['html'] ?? '', 'Beata') || !empty($j['data']['by_others']), 'súhrn spomína, kto mi nahral súbory', json_encode($j['data']['by_others'] ?? []));
$j = $web->api('digest_test');
ok(!empty($j['ok']) || str_contains($j['error'] ?? '', 'E-mail sa nepodarilo odoslať'), 'odoslanie ukážky (alebo zrozumiteľná chyba bez SMTP)', json_encode($j));
ok(!empty($web->api('prefs_save', ['key' => 'digest', 'value' => '0'])['ok']), 'vypnutie súhrnu');
$r = $web->get('profile.php');
ok(preg_match('/<option value="off" selected/', $r['body']) === 1 && str_contains($r['body'], 'Súhrn je vypnutý'), 'v profile je súhrn vypnutý');
$j = $web->api('prefs_save', ['key' => 'digest', 'value' => json_encode(['freq' => 'monthly', 'dom' => 15, 'hour' => 19])]);
ok(($j['cfg']['freq'] ?? '') === 'monthly' && str_contains($j['when'] ?? '', '15. dňa o 19:00') && preg_match('/^15\. /', $j['next'] ?? '') === 1, 'súhrn raz mesačne 15. o 19:00 (najbližší termín)', json_encode($j));
$j = $web->api('prefs_save', ['key' => 'digest', 'value' => json_encode(['freq' => 'biweekly', 'dow' => 3, 'hour' => 7])]);
ok(($j['when'] ?? '') === 'každý druhý streda o 7:00', 'súhrn každé 2 týždne v stredu o 7:00', json_encode($j));
$r = $web->get('profile.php');
ok(preg_match('/<option value="biweekly" selected/', $r['body']) === 1 && preg_match('/id="dgDow".*?<option value="3" selected/s', $r['body']) === 1, 'nastavenie súhrnu sa uloží a ukáže v profile');
// keď nastane termín, súhrn sa odošle sám (kontrola pri návšteve webu, najviac raz za 10 minút)
$web->api('prefs_save', ['key' => 'digest', 'value' => json_encode(['freq' => 'daily', 'hour' => 0])]);
$pdoA->exec("UPDATE cloud_users SET prefs = JSON_SET(prefs, '$.digest_last', 1000) WHERE id = $uidA");
$pdoA->exec("INSERT INTO cloud_settings (k, v) VALUES ('digest_tick', '0') ON DUPLICATE KEY UPDATE v = '0'");
$web->get('index.php');
$sent = 0;
for ($i = 0; $i < 20 && !$sent; $i++) { usleep(300000); $sent = (int)$pdoA->query("SELECT JSON_EXTRACT(prefs, '$.digest_last') FROM cloud_users WHERE id = $uidA")->fetchColumn() > 1000; }
ok($sent, 'súhrn sa v nastavený čas spracuje automaticky');
$web->api('prefs_save', ['key' => 'digest', 'value' => '1']);
ok(preg_match('/<option value="daily" selected/', $web->get('profile.php')['body']) === 1, 'zapnutie súhrnu ponechá zvolenú frekvenciu');

section('Príručky a rýchly sprievodca');
$r = $web->get('help.php');
ok($r['code'] === 200 && str_contains($r['body'], 'id="helpDoc"') && str_contains($r['body'], 'Zamknuté zložky') && str_contains($r['body'], 'Aplikácia pre Android'), 'používateľská príručka');
no_php_errors($r['body'], 'help.php');
ok(substr_count($r['body'], '<figure') >= 25, 'príručka má snímky obrazovky', (string)substr_count($r['body'], '<figure'));
ok(str_contains($web->get('index.php')['body'], 'href="/help.php"') || str_contains($web->get('index.php')['body'], 'help.php"'), 'príručka je v menu');
$r = $web->get('help.php?doc=dev');
ok($r['code'] === 200 && str_contains($r['body'], 'Databáza a migrácie') && str_contains($r['body'], 'SCHEMA_VERSION'), 'vývojárska príručka pre admina');
no_php_errors($r['body'], 'help.php?doc=dev');
ok(str_contains($web->get('index.php')['body'], 'help.php?doc=dev'), 'vývojárska príručka je v menu admina');
$bw = new Client();
$bw->loadCsrf($bw->get('login.php')['body']);
$bw->post('login.php', ['_csrf' => $bw->csrf, 'email' => 'beata@test.sk', 'password' => 'Heslo1234']);
$r = $bw->get('help.php?doc=dev');
ok($r['code'] === 403 && !str_contains($r['body'], 'Databáza a migrácie'), 'vývojárska príručka nie je pre bežného používateľa');
ok(!str_contains($bw->get('index.php')['body'], 'help.php?doc=dev'), 'bežný používateľ nemá v menu vývojársku príručku');
// sprievodca sa sám otvorí, kým ho používateľ nezavrie
$pdoA->exec("UPDATE cloud_users SET prefs = JSON_REMOVE(COALESCE(prefs, '{}'), '$.tour') WHERE email = 'beata@test.sk'");
$b0 = $bw->get('index.php')['body'];
ok(str_contains($b0, 'window.CLOUD_TOUR = true') && str_contains($b0, 'tour.js'), 'nový používateľ uvidí rýchly sprievodcu');
if (preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $b0, $mm)) $bw->csrf = $mm[1];
ok(!empty($bw->api('tour', ['value' => 'later'])['ok']) && !str_contains($bw->get('index.php')['body'], 'window.CLOUD_TOUR = true'), 'zavretý sprievodca sa v tejto relácii znova neotvorí');
ok(!empty($bw->api('tour', ['value' => 'done'])['ok']), '„Viac nezobrazovať“');
$bw->api('tour', ['value' => 'x']);
ok(!str_contains($bw->get('index.php')['body'], 'window.CLOUD_TOUR = true'), 'po „Viac nezobrazovať“ sa sprievodca už neotvorí');

section('Terminál je odstránený');
ok($web->get('term.php')['code'] === 404, 'term.php neexistuje');
ok(!str_contains($web->get('admin.php')['body'], 'tab=terminal'), 'v administrácii nie je záložka Terminál');

section('Rýchle prepínanie účtov');
$sw = new Client();
$sw->loadCsrf($sw->get('login.php')['body']);
$r = $sw->post('login.php', ['_csrf' => $sw->csrf, 'email' => 'admin@test.sk', 'password' => 'Heslo1234']);
ok(str_contains($r['headers'], 'Set-Cookie: CLOUD_TEST_acc='), 'po prihlásení sa účet uloží v prehliadači', $r['headers']);
$r = $sw->get('index.php'); $sw->loadCsrf($r['body']);
ok(str_contains($r['body'], 'Pridať ďalší účet') && !str_contains($r['body'], 'Prepnúť účet'), 'menu účtu ponúka pridať ďalší účet');
$r = $sw->post('switch.php', ['_csrf' => $sw->csrf, 'do' => 'add']);
ok(str_contains($r['headers'], 'login.php?add=1'), 'pridanie účtu odhlási a otvorí prihlásenie', $r['headers']);
$r = $sw->get('login.php?add=1');
ok(str_contains($r['body'], 'id="savedAccounts"') && str_contains($r['body'], 'admin@test.sk'), 'prihlasovacia stránka ukáže uložený účet');
$sw->loadCsrf($r['body']);
$r = $sw->post('login.php', ['_csrf' => $sw->csrf, 'email' => 'beata@test.sk', 'password' => 'Heslo1234']);
ok(str_contains($r['headers'], 'index.php'), 'prihlásenie druhého účtu', $r['headers']);
$r = $sw->get('profile.php'); $sw->loadCsrf($r['body']);
ok(str_contains($r['body'], 'value="beata@test.sk"'), 'prihlásená je Beáta');
$r = $sw->get('index.php'); $sw->loadCsrf($r['body']);
ok(str_contains($r['body'], 'Prepnúť účet') && str_contains($r['body'], 'Odhlásiť všetky účty'), 'menu ukáže druhý účet na prepnutie');
no_php_errors($r['body'], 'index s viacerými účtami');
$r = $sw->post('switch.php', ['_csrf' => $sw->csrf, 'uid' => $uidA]);
ok(str_contains($r['headers'], 'index.php'), 'prepnutie jedným klikom (bez hesla)', $r['headers']);
$r = $sw->get('profile.php'); $sw->loadCsrf($r['body']);
ok(str_contains($r['body'], 'value="admin@test.sk"'), 'po prepnutí je prihlásený admin');
$bUid = $bid2;
$r = $sw->post('switch.php', ['_csrf' => $sw->csrf, 'uid' => $bUid]);
$r = $sw->get('profile.php'); $sw->loadCsrf($r['body']);
ok(str_contains($r['body'], 'value="beata@test.sk"'), 'prepnutie späť na Beátu');
$pdoS->exec("UPDATE cloud_tokens SET used = 1 WHERE type = 'switch' AND user_id = $uidA");
$r = $sw->post('switch.php', ['_csrf' => $sw->csrf, 'uid' => $uidA]);
ok(str_contains($r['headers'], 'login.php'), 'zneplatnené uloženie (napr. po zmene hesla) vyžaduje heslo', $r['headers']);
$r = $sw->get('profile.php');
ok(str_contains($r['body'], 'value="beata@test.sk"'), 'neplatné prepnutie nezmení prihlásený účet');
$r = $sw->post('switch.php', ['_csrf' => $sw->csrf, 'uid' => 999999]);
ok(str_contains($r['headers'], 'login.php') && str_contains($sw->get('profile.php')['body'], 'value="beata@test.sk"'), 'cudzí účet bez uloženia sa nedá prepnúť');
$sw->loadCsrf($sw->get('index.php')['body']);
$r = $sw->post('switch.php', ['_csrf' => $sw->csrf, 'do' => 'logout_all']);
ok(str_contains($r['headers'], 'login.php') && $sw->get('index.php')['code'] === 302, 'odhlásenie všetkých účtov');
ok(!str_contains($sw->get('login.php')['body'], 'id="savedAccounts"'), 'po odhlásení všetkých sa uložené účty zabudnú');

section('Odhlásenie');
$r = $app->req('POST', 'app.php?action=logout', ['ajax' => true]);
$r = $app->api('folders');
ok(empty($r['ok']), 'po odhlásení aplikácie token neplatí');
$web->post('logout.php', ['_csrf' => $web->csrf]);
ok($web->get('index.php')['code'] === 302, 'po odhlásení webu je disk neprístupný');

echo "\n==============================\nVýsledok: $passes OK, $fails chýb\n";
exit($fails ? 1 : 0);
