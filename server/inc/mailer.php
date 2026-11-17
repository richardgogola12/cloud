<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
 *  Jednoduchý SMTP klient (bez závislostí): SSL / STARTTLS, AUTH LOGIN/PLAIN
 * ------------------------------------------------------------------ */

function smtp_conf(): array {
    $c = [];
    foreach (['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name'] as $k) {
        $c[$k] = setting($k, cfg($k));
    }
    return $c;
}

class SmtpMailer {
    private $sock;
    private array $log = [];

    public function __construct(private array $c) {}

    public function getLog(): string { return implode("\n", $this->log); }

    private function read(): string {
        $data = '';
        while (($line = fgets($this->sock, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        $this->log[] = 'S: ' . trim($data);
        return $data;
    }
    private function cmd(string $cmd, array $expect, bool $hide = false): string {
        fwrite($this->sock, $cmd . "\r\n");
        $this->log[] = 'C: ' . ($hide ? '********' : $cmd);
        $r = $this->read();
        if (!in_array((int)substr($r, 0, 3), $expect, true)) {
            throw new RuntimeException('SMTP chyba po "' . ($hide ? '***' : strtok($cmd, ' ')) . '": ' . trim($r));
        }
        return $r;
    }

    public function send(string $to, string $subject, string $html, string $text = ''): void {
        $host   = $this->c['smtp_host'];
        $port   = (int)$this->c['smtp_port'];
        $secure = strtolower((string)$this->c['smtp_secure']); // tls | ssl | none
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $this->sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new RuntimeException("Nepodarilo sa pripojiť k SMTP $host:$port – $errstr");
        stream_set_timeout($this->sock, 20);

        $this->read();
        $ehloHost = parse_url(public_url(), PHP_URL_HOST) ?: 'localhost';
        $ehlo = $this->cmd('EHLO ' . $ehloHost, [250]);

        if ($secure === 'tls') {
            $this->cmd('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!stream_socket_enable_crypto($this->sock, true, $method)) throw new RuntimeException('STARTTLS zlyhal.');
            $ehlo = $this->cmd('EHLO ' . $ehloHost, [250]);
        }

        if ($this->c['smtp_user'] !== '' && $this->c['smtp_user'] !== null) {
            if (stripos($ehlo, 'PLAIN') !== false && stripos($ehlo, 'LOGIN') === false) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $this->c['smtp_user'] . "\0" . $this->c['smtp_pass']), [235], true);
            } else {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode((string)$this->c['smtp_user']), [334], true);
                $this->cmd(base64_encode((string)$this->c['smtp_pass']), [235], true);
            }
        }

        $from = $this->c['smtp_from'];
        $this->cmd('MAIL FROM:<' . $from . '>', [250]);
        $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $this->cmd('DATA', [354]);

        $boundary = 'b_' . bin2hex(random_bytes(12));
        $enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $text = $text !== '' ? $text : trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $enc((string)$this->c['smtp_from_name']) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($text))
              . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html))
              . "--$boundary--\r\n";
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $msg = preg_replace('/^\./m', '..', $msg); // dot-stuffing
        fwrite($this->sock, $msg . "\r\n.\r\n");
        $r = $this->read();
        if ((int)substr($r, 0, 3) !== 250) throw new RuntimeException('SMTP odmietol správu: ' . trim($r));
        $this->cmd('QUIT', [221]);
        fclose($this->sock);
    }
}

/** Odošle e-mail s jednotnou šablónou. Vráti true/false (chyby sa zapíšu do logu). */
function send_mail(string $to, string $subject, string $title, string $bodyHtml, ?string $btnText = null, ?string $btnUrl = null, ?string &$error = null): bool {
    $app = e(cfg('app_name'));
    $btn = $btnText && $btnUrl
        ? '<p style="text-align:center;margin:28px 0"><a href="' . e($btnUrl) . '" style="background:#1a73e8;color:#fff;text-decoration:none;padding:12px 26px;border-radius:24px;font-weight:600;display:inline-block">' . e($btnText) . '</a></p>'
          . '<p style="font-size:12px;color:#777">Ak tlačidlo nefunguje, skopíruj odkaz: <br><a href="' . e($btnUrl) . '">' . e($btnUrl) . '</a></p>'
        : '';
    $html = '<!doctype html><html><body style="margin:0;background:#f3f6fb;font-family:Segoe UI,Roboto,Arial,sans-serif">'
          . '<div style="max-width:560px;margin:30px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06)">'
          . '<div style="background:linear-gradient(135deg,#1a73e8,#6c4cf5);color:#fff;padding:22px 28px;font-size:20px;font-weight:700">☁ ' . $app . '</div>'
          . '<div style="padding:28px;color:#222;line-height:1.6"><h2 style="margin-top:0;font-size:20px">' . e($title) . '</h2>'
          . $bodyHtml . $btn . '</div>'
          . '<div style="padding:14px 28px;background:#fafbfd;color:#999;font-size:12px">Táto správa bola odoslaná automaticky z ' . $app . '.</div>'
          . '</div></body></html>';
    try {
        (new SmtpMailer(smtp_conf()))->send($to, $subject, $html);
        return true;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
        log_action(null, 'mail_error', $to . ': ' . $error);
        return false;
    }
}

function admin_emails(): array {
    return q('SELECT email FROM ' . P . "users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
}
