<?php
/**
 * AgroBusiness Malawi — shared outbound mail helpers.
 *
 * Extracted from api.php so register.php (the standalone registration page) and
 * api.php send the same branded notifications without duplicating an SMTP client.
 * Guarded so that including it twice from one request is harmless.
 *
 * SMTP credentials come from $_ENV, populated from .env by the including page.
 * Nothing is hardcoded here.
 */

if (defined('AGRO_MAILER_LOADED')) return;
define('AGRO_MAILER_LOADED', true);

/**
 * Send an HTML + plain-text multipart email via SMTPS (port 465 / implicit TLS).
 * Falls back to PHP mail() if the socket connection fails.
 *
 * @param string $to        Primary recipient address
 * @param string $subject   Email subject
 * @param string $htmlBody  Full HTML body
 * @param string $plainBody Plain-text fallback (auto-stripped from HTML if empty)
 * @param string $cc        Optional CC address (single address)
 * @return bool
 */
function send_smtp_email(string $to, string $subject, string $htmlBody, string $plainBody = '', string $cc = ''): bool
{
    $smtpHost = trim($_ENV['Outgoing Server'] ?? 'blue.webhostingireland.ie');
    $smtpPort = (int)trim($_ENV['SMTP Port']  ?? '465');
    $smtpUser = trim($_ENV['Username']        ?? '');
    $smtpPass = trim($_ENV['Password']        ?? '');
    $fromAddr = $smtpUser ?: 'noreply@agrobusinessmw.com';
    $fromName = 'AgroBusiness Malawi';

    if ($plainBody === '') {
        $plainBody = trim(strip_tags(preg_replace(['/<br\s*\/?>/i', '/<\/p>/i'], "\n", $htmlBody)));
    }

    $boundary = 'agro_' . md5(uniqid('', true));
    $msgId    = '<' . uniqid('agro-') . '@agrobusinessmw.com>';

    $ccLine = $cc ? "Cc: {$cc}\r\n" : '';
    $rawMsg = "From: {$fromName} <{$fromAddr}>\r\n"
            . "To: {$to}\r\n"
            . $ccLine
            . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
            . "Message-ID: {$msgId}\r\n"
            . "Date: " . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
            . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $plainBody . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $htmlBody . "\r\n"
            . "--{$boundary}--";

    // Dot-stuffing: lone dots on a line must be doubled
    $rawMsg = preg_replace('/^\.$/m', '..', $rawMsg);

    $ctx = stream_context_create([
        'ssl' => [
            // Peer verification disabled for shared-hosting certs not in local CA bundle.
            // Connection is still encrypted.
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    // 8s, not 15s. Callers now flush their response before calling this, but a
    // hung connect still occupies a PHP worker, and on shared hosting workers
    // are the scarce resource. If the mail host cannot answer in 8 seconds the
    // mail() fallback below is the better bet anyway.
    $socket = @stream_socket_client(
        "ssl://{$smtpHost}:{$smtpPort}",
        $errno,
        $errstr,
        8,
        STREAM_CLIENT_CONNECT,
        $ctx
    );

    if (!$socket) {
        $headers = "From: {$fromName} <{$fromAddr}>\r\n"
                 . ($cc ? "Cc: {$cc}\r\n" : '')
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/html; charset=utf-8";
        return @mail($to, $subject, $htmlBody, $headers);
    }

    stream_set_timeout($socket, 10);

    $readResp = function () use ($socket): string {
        $last = '';
        while (true) {
            $line = fgets($socket, 1024);
            if ($line === false || $line === '') break;
            $last = $line;
            if (strlen($line) >= 4 && $line[3] !== '-') break;
        }
        return $last;
    };
    $write = function (string $cmd) use ($socket): void { fwrite($socket, $cmd . "\r\n"); };

    $readResp(); // greeting
    $helo = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $write("EHLO {$helo}");
    $readResp();

    $write('AUTH LOGIN');
    $readResp();
    $write(base64_encode($smtpUser));
    $readResp();
    $write(base64_encode($smtpPass));
    $authResp = $readResp();

    if (substr($authResp, 0, 3) !== '235') {
        $write('QUIT');
        fclose($socket);
        return false;
    }

    $write("MAIL FROM:<{$fromAddr}>");
    $readResp();
    $write("RCPT TO:<{$to}>");
    $readResp();
    if ($cc) {
        $write("RCPT TO:<{$cc}>");
        $readResp();
    }
    $write('DATA');
    $readResp();

    fwrite($socket, $rawMsg . "\r\n.\r\n");
    $dataResp = $readResp();

    $write('QUIT');
    $readResp();
    fclose($socket);

    return substr($dataResp, 0, 3) === '250';
}

/**
 * Wrap content in the branded HTML email shell.
 */
function email_html(string $bodyContent): string
{
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f5f2eb;font-family:Arial,Helvetica,sans-serif;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f2eb;">'
        . '<tr><td align="center" style="padding:40px 16px;">'
        . '<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">'
        // Header
        . '<tr><td style="background:#16a34a;padding:28px 36px;">'
        . '<p style="margin:0;font-size:11px;color:#bbf7d0;letter-spacing:0.12em;text-transform:uppercase;">AgroBusiness Malawi</p>'
        . '<h1 style="margin:4px 0 0;color:#ffffff;font-size:22px;font-weight:700;line-height:1.3;">Agricultural Platform</h1>'
        . '</td></tr>'
        // Body
        . '<tr><td style="padding:36px;">'
        . $bodyContent
        . '</td></tr>'
        // Footer
        . '<tr><td style="background:#f5f2eb;padding:20px 36px;border-top:1px solid #e5e0d8;">'
        . '<p style="margin:0;font-size:12px;color:#8B7355;text-align:center;">AgroBusiness Malawi &bull; Empowering Malawian Farmers<br>'
        . '<a href="https://agrobusinessmw.com" style="color:#16a34a;text-decoration:none;">agrobusinessmw.com</a></p>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Reusable info row for detail tables in admin notification emails. */
function email_row(string $label, string $value): string
{
    return '<tr>'
        . '<td style="padding:8px 12px;font-size:13px;color:#6b7280;width:130px;border-bottom:1px solid #f0ece4;">' . htmlspecialchars($label) . '</td>'
        . '<td style="padding:8px 12px;font-size:13px;color:#1f2937;font-weight:600;border-bottom:1px solid #f0ece4;">' . htmlspecialchars($value) . '</td>'
        . '</tr>';
}
