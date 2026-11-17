<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 *  WebAuthn (passkeys / odtlačok prsta / Face ID / Windows Hello)
 *  Bez závislostí: vlastný CBOR dekóder + overenie podpisu cez OpenSSL.
 *  Podporované algoritmy: ES256 (-7), RS256 (-257)
 * ------------------------------------------------------------------ */

function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string {
    $s = strtr($s, '-_', '+/');
    $s .= str_repeat('=', (4 - strlen($s) % 4) % 4);
    $r = base64_decode($s, true);
    if ($r === false) throw new RuntimeException('Neplatné base64url dáta.');
    return $r;
}

final class Cbor {
    public static function decode(string $data, int &$offset = 0) {
        if ($offset >= strlen($data)) throw new RuntimeException('CBOR: koniec dát');
        $ib = ord($data[$offset++]);
        $mt = $ib >> 5;
        $ai = $ib & 0x1f;
        $val = self::readLen($data, $offset, $ai);
        switch ($mt) {
            case 0: return $val;
            case 1: return -1 - $val;
            case 2:
            case 3:
                $s = substr($data, $offset, $val);
                if (strlen($s) !== $val) throw new RuntimeException('CBOR: krátky reťazec');
                $offset += $val;
                return $s;
            case 4:
                $a = [];
                for ($i = 0; $i < $val; $i++) $a[] = self::decode($data, $offset);
                return $a;
            case 5:
                $m = [];
                for ($i = 0; $i < $val; $i++) { $k = self::decode($data, $offset); $m[$k] = self::decode($data, $offset); }
                return $m;
            case 6: return self::decode($data, $offset); // tag – ignoruj
            case 7:
                return match ($ai) { 20 => false, 21 => true, 22, 23 => null, default => $val };
        }
        throw new RuntimeException('CBOR: nepodporovaný typ');
    }
    private static function readLen(string $d, int &$o, int $ai): int {
        if ($ai < 24) return $ai;
        $n = match ($ai) { 24 => 1, 25 => 2, 26 => 4, 27 => 8, default => throw new RuntimeException('CBOR: neurčitá dĺžka nie je podporovaná') };
        $b = substr($d, $o, $n); $o += $n;
        $v = 0;
        for ($i = 0; $i < $n; $i++) $v = ($v << 8) | ord($b[$i]);
        return $v;
    }
}

final class WebAuthn {
    public static function rpId(): string {
        return parse_url(cfg('app_url'), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    public static function origin(): string {
        $u = parse_url(cfg('app_url'));
        return $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    }
    public static function challenge(string $purpose): string {
        $c = random_bytes(32);
        $_SESSION['wa_' . $purpose] = ['c' => $c, 't' => time()];
        return b64u_enc($c);
    }
    private static function takeChallenge(string $purpose): string {
        $c = $_SESSION['wa_' . $purpose] ?? null;
        unset($_SESSION['wa_' . $purpose]);
        if (!$c || time() - $c['t'] > 300) throw new RuntimeException('Výzva vypršala, skús to znova.');
        return $c['c'];
    }

    private static function checkClientData(string $cdj, string $type, string $purpose): void {
        $cd = json_decode($cdj, true);
        if (!is_array($cd)) throw new RuntimeException('Neplatné clientDataJSON.');
        if (($cd['type'] ?? '') !== $type) throw new RuntimeException('Nesprávny typ operácie.');
        if (!hash_equals(self::takeChallenge($purpose), b64u_dec($cd['challenge'] ?? ''))) throw new RuntimeException('Výzva nesúhlasí.');
        if (($cd['origin'] ?? '') !== self::origin()) throw new RuntimeException('Nesprávny origin (' . ($cd['origin'] ?? '?') . '). Skontroluj app_url v config.php.');
    }

    private static function parseAuthData(string $ad): array {
        if (strlen($ad) < 37) throw new RuntimeException('Krátke authenticatorData.');
        $r = [
            'rpIdHash'  => substr($ad, 0, 32),
            'flags'     => ord($ad[32]),
            'signCount' => unpack('N', substr($ad, 33, 4))[1],
        ];
        if (!hash_equals(hash('sha256', self::rpId(), true), $r['rpIdHash'])) throw new RuntimeException('RP ID nesúhlasí.');
        if (!($r['flags'] & 0x01)) throw new RuntimeException('Chýba potvrdenie prítomnosti používateľa.');
        if (!($r['flags'] & 0x04)) throw new RuntimeException('Chýba overenie používateľa (odtlačok/PIN).');
        if ($r['flags'] & 0x40) { // attested credential data
            $o = 37 + 16; // aaguid
            $len = unpack('n', substr($ad, $o, 2))[1]; $o += 2;
            $r['credId'] = substr($ad, $o, $len); $o += $len;
            $r['cose'] = Cbor::decode($ad, $o);
        }
        return $r;
    }

    /** COSE kľúč → PEM (SPKI) */
    private static function coseToPem(array $k): string {
        $kty = $k[1] ?? null; $alg = $k[3] ?? null;
        if ($kty === 2 && $alg === -7) {
            if (($k[-1] ?? null) !== 1) throw new RuntimeException('Nepodporovaná krivka.');
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
        } elseif ($kty === 3 && $alg === -257) {
            $n = $k[-1]; $e = $k[-2];
            if (ord($n[0]) > 0x7f) $n = "\x00" . $n;
            if (ord($e[0]) > 0x7f) $e = "\x00" . $e;
            $rsa = self::der(0x30, self::der(0x02, $n) . self::der(0x02, $e));
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $der = self::der(0x30, $algId . self::der(0x03, "\x00" . $rsa));
        } else {
            throw new RuntimeException('Nepodporovaný typ kľúča.');
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
    private static function der(int $tag, string $v): string {
        $l = strlen($v);
        if ($l < 128) $len = chr($l);
        else { $b = ltrim(pack('N', $l), "\x00"); $len = chr(0x80 | strlen($b)) . $b; }
        return chr($tag) . $len . $v;
    }

    /* ----- Registrácia (pridanie odtlačku) ----- */
    public static function registerOptions(array $user, array $existingIds): array {
        return [
            'challenge' => self::challenge('reg'),
            'rp'        => ['name' => cfg('app_name'), 'id' => self::rpId()],
            'user'      => ['id' => b64u_enc((string)$user['id']), 'name' => $user['email'], 'displayName' => $user['name']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'timeout'   => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',   // odtlačok/tvár v telefóne alebo notebooku
                'residentKey'      => 'preferred',
                'requireResidentKey' => false,
                'userVerification' => 'required',
            ],
            'excludeCredentials' => array_map(fn($id) => ['type' => 'public-key', 'id' => $id], $existingIds),
        ];
    }
    /** @return array{credential_id:string, public_key:string, sign_count:int} */
    public static function registerVerify(array $in): array {
        $cdj = b64u_dec($in['clientDataJSON'] ?? '');
        self::checkClientData($cdj, 'webauthn.create', 'reg');
        $att = Cbor::decode(b64u_dec($in['attestationObject'] ?? ''));
        if (!is_array($att) || !isset($att['authData'])) throw new RuntimeException('Neplatný attestationObject.');
        $ad = self::parseAuthData($att['authData']);
        if (empty($ad['credId']) || empty($ad['cose'])) throw new RuntimeException('Chýbajú údaje o kľúči.');
        $pem = self::coseToPem($ad['cose']);
        if (!openssl_pkey_get_public($pem)) throw new RuntimeException('Neplatný verejný kľúč.');
        return ['credential_id' => b64u_enc($ad['credId']), 'public_key' => $pem, 'sign_count' => $ad['signCount']];
    }

    /* ----- Prihlásenie ----- */
    public static function loginOptions(array $allowIds = []): array {
        $o = [
            'challenge' => self::challenge('login'),
            'rpId' => self::rpId(),
            'timeout' => 60000,
            'userVerification' => 'required',
        ];
        if ($allowIds) $o['allowCredentials'] = array_map(fn($id) => ['type' => 'public-key', 'id' => $id], $allowIds);
        return $o;
    }
    /** Overí podpis; $cred = riadok z cloud_webauthn. Vráti nový signCount. */
    public static function loginVerify(array $in, array $cred): int {
        $cdj = b64u_dec($in['clientDataJSON'] ?? '');
        self::checkClientData($cdj, 'webauthn.get', 'login');
        $adRaw = b64u_dec($in['authenticatorData'] ?? '');
        $ad = self::parseAuthData($adRaw);
        $sig = b64u_dec($in['signature'] ?? '');
        $ok = openssl_verify($adRaw . hash('sha256', $cdj, true), $sig, $cred['public_key'], OPENSSL_ALGO_SHA256);
        if ($ok !== 1) throw new RuntimeException('Podpis nie je platný.');
        if ($ad['signCount'] > 0 && $cred['sign_count'] > 0 && $ad['signCount'] <= $cred['sign_count']) {
            throw new RuntimeException('Podozrenie na klonovaný kľúč (signCount).');
        }
        return $ad['signCount'];
    }
}
