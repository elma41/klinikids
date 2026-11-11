<?php
// Randevu e-postaları
//   • Siteden yeni randevu  → kliniğe bildirim
//   • Randevu onaylanınca   → hastaya "Randevunuz onaylandı" (e-posta yazdıysa)
//   • Hasta linkten iptal   → kliniğe bildirim
// Gönderim yöntemi config.php → 'mail' => ['driver' => 'smtp' | 'resend' | 'none', ...]
// E-postalar yanıt tarayıcıya gönderildikten SONRA atılır; randevu kaydını asla bekletmez/engellemez.
declare(strict_types=1);

$GLOBALS['KK_MAIL_QUEUE'] = [];

function mail_queue(string $apptId, string $event): void { $GLOBALS['KK_MAIL_QUEUE'][] = [$apptId, $event]; }

/** Kuyruktaki e-postaları istek bitince gönder */
function mail_flush(): void {
    if (!$GLOBALS['KK_MAIL_QUEUE']) return;
    $jobs = $GLOBALS['KK_MAIL_QUEUE']; $GLOBALS['KK_MAIL_QUEUE'] = [];
    register_shutdown_function(function () use ($jobs) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        foreach ($jobs as [$id, $ev]) {
            try { mail_send_event($id, $ev); } catch (Throwable $e) { error_log('KK mail: ' . $e->getMessage()); }
        }
    });
}

function mcfg(): array {
    return array_merge(['driver' => 'none', 'from' => 'Klinikids <bildirim@klinikids.com.tr>', 'clinic' => 'aysegul@klinikids.company'],
                       kk_config()['mail'] ?? []);
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tr_when(string $dbTime): string {
    $d = (new DateTimeImmutable($dbTime, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Istanbul'));
    $days = ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
    $months = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    return $d->format('j') . ' ' . $months[(int)$d->format('n')] . ' ' . $d->format('Y') . ' ' . $days[(int)$d->format('N')] . ' ' . $d->format('H:i');
}
function phone_fmt(string $p): string { return strlen($p) === 10 ? '0' . substr($p, 0, 3) . ' ' . substr($p, 3, 3) . ' ' . substr($p, 6, 2) . ' ' . substr($p, 8) : $p; }

function mail_shell(string $title, string $body): string {
    return '<!doctype html><html lang="tr"><body style="margin:0;background:#F5F7F8;font-family:Arial,Helvetica,sans-serif;color:#1B2533">'
     . '<div style="max-width:560px;margin:0 auto;padding:24px 16px"><div style="background:#fff;border-radius:16px;padding:28px 24px;border:1px solid #E6EAEE">'
     . '<div style="font-weight:800;font-size:20px;color:#1E5F5B;margin-bottom:4px">Klinikids</div>'
     . '<h2 style="margin:0 0 16px;font-size:20px">' . $title . '</h2>' . $body . '</div>'
     . '<p style="font-size:12px;color:#7A8592;text-align:center;margin-top:14px">Özel Klinikids Ağız ve Diş Sağlığı Polikliniği · Mehmet Ali Paşa Mah. Erkan Sok. RBG İş Merkezi No 7B, İzmit / Kocaeli · 0546 421 71 41</p></div></body></html>';
}
function mail_table(array $rows): string {
    $o = '<table style="width:100%;border-collapse:collapse;font-size:15px">';
    foreach ($rows as [$k, $v]) if ($v !== '' && $v !== null)
        $o .= '<tr><td style="padding:8px 0;color:#6B7684;width:110px;vertical-align:top">' . h($k) . '</td><td style="padding:8px 0;font-weight:700">' . h($v) . '</td></tr>';
    return $o . '</table>';
}

function mail_send_event(string $id, string $event): void {
    $cfg = mcfg();
    if ($cfg['driver'] === 'none') return;
    $a = q('SELECT a.*, p.full_name p_name, p.phone p_phone, p.email p_email, d.title d_title, d.full_name d_name, d.clinic d_clinic, s.name s_name
              FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN doctors d ON d.id = a.doctor_id
              LEFT JOIN services s ON s.id = a.service_id WHERE a.id = ?', [$id])->fetch();
    if (!$a) return;
    $doc = trim(($a['d_title'] ?: 'Dt.') . ' ' . $a['d_name']);
    $svc = $a['s_name'] ?: (string)$a['custom_service_name'];
    $clinic = ['cocuk' => 'Çocuk Kliniği', 'yetiskin' => 'Yetişkin Kliniği'][$a['d_clinic']] ?? 'Klinikids';
    $when = tr_when($a['starts_at']);
    $site = 'https://www.klinikids.com.tr';

    if (($event === 'insert' && $a['source'] === 'online') || $event === 'cancelled') {
        $isNew = $event === 'insert';
        $subj = $isNew ? 'Yeni online randevu' . ($a['status'] === 'pending' ? ' (onay bekliyor)' : '') . ": {$a['p_name']} · $when"
                       : "Randevu iptal edildi: {$a['p_name']} · $when";
        $body = mail_table([['Hasta', $a['p_name']], ['Telefon', phone_fmt($a['p_phone'])], ['E-posta', $a['p_email']], ['Hekim', $doc],
                            ['Klinik', $clinic], ['İşlem', $svc], ['Tarih', $when], ['Not', $a['patient_note']]]);
        if ($isNew && $a['status'] === 'pending')
            $body .= '<p style="margin-top:18px">Onaylamak için <a href="' . $site . '/admin.html" style="color:#1E5F5B;font-weight:700">yönetim paneline</a> girin. Onaylandığında hastaya otomatik e-posta gider'
                   . ($a['p_email'] ? '' : ' (hasta e-posta yazmadı, telefonla teyit edin)') . '.</p>';
        mail_send($cfg['clinic'], $subj, mail_shell($isNew ? 'Yeni randevu talebi' : 'Randevu iptal edildi', $body), $a['p_email'] ?: null);
    }
    if ($a['status'] === 'confirmed' && in_array($event, ['confirmed', 'insert'], true) && $a['p_email']) {
        $body = '<p>Merhaba ' . h($a['p_name']) . ',</p><p>Randevunuz <b>onaylandı</b>. Sizi bekliyoruz!</p>'
              . mail_table([['Hekim', $doc], ['Klinik', $clinic], ['İşlem', $svc], ['Tarih', $when]])
              . '<p style="margin-top:18px">Adres: Mehmet Ali Paşa Mah. Erkan Sok. RBG İş Merkezi No 7B, İzmit / Kocaeli</p>'
              . '<p>Gelemeyecekseniz lütfen en kısa sürede <a href="tel:+905464217141">0546 421 71 41</a> numarasından ya da bu e-postayı yanıtlayarak bize haber verin.</p>';
        mail_send($a['p_email'], "Randevunuz onaylandı · $when", mail_shell('Randevunuz onaylandı ✅', $body), $cfg['clinic']);
    }
}

function html_to_text(string $html): string {
    $t = preg_replace(['/<\/td>\s*<td[^>]*>/i', '/<br\s*\/?>/i', '/<\/(p|tr|h2|div)>/i'], [': ', "\n", "\n"], $html);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

function mail_send(string $to, string $subject, string $html, ?string $replyTo = null): void {
    $cfg = mcfg();
    if ($cfg['driver'] === 'resend') {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['resend_key'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(array_filter(['from' => $cfg['from'], 'to' => [$to], 'subject' => $subject,
                'html' => $html, 'text' => html_to_text($html), 'reply_to' => $replyTo]), JSON_UNESCAPED_UNICODE)]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($code < 200 || $code >= 300) throw new RuntimeException("Resend $code: $res");
        return;
    }
    if ($cfg['driver'] === 'smtp') { smtp_send($cfg, $to, $subject, $html, $replyTo); return; }
}

/** Küçük SMTP istemcisi (SSL 465 ya da STARTTLS 587, AUTH LOGIN) */
function smtp_send(array $cfg, string $to, string $subject, string $html, ?string $replyTo): void {
    $s = $cfg['smtp'] ?? [];
    $host = $s['host'] ?? ''; $port = (int)($s['port'] ?? 465); $secure = $s['secure'] ?? ($port === 465 ? 'ssl' : 'tls');
    $verify = ($s['verify'] ?? true) !== false; // yalnızca yerel testte kapatılır
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify]]);
    $fp = stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $err, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP sunucusuna bağlanılamadı ($host:$port). " . ($err ?: "Sunucu adı, port ya da SSL sertifikası doğrulanamadı."));
    stream_set_timeout($fp, 20);
    $read = function () use ($fp) { $o = ''; while (($l = fgets($fp, 1024)) !== false) { $o .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $o; };
    $cmd = function (string $c, array $okCodes) use ($fp, $read) {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $r = $read(); $code = (int)substr($r, 0, 3);
        if (!in_array($code, $okCodes, true)) throw new RuntimeException('SMTP: ' . trim($r));
        return $r;
    };
    $cmd('', [220]);
    $cmd('EHLO klinikids.com.tr', [250]);
    if ($secure === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS başarısız');
        $cmd('EHLO klinikids.com.tr', [250]);
    }
    $cmd('AUTH LOGIN', [334]); $cmd(base64_encode((string)$s['user']), [334]); $cmd(base64_encode((string)$s['pass']), [235]);
    $fromAddr = preg_match('/<([^>]+)>/', $cfg['from'], $m) ? $m[1] : $cfg['from'];
    $fromName = trim(preg_replace('/<[^>]+>/', '', $cfg['from'])) ?: 'Klinikids';
    $cmd("MAIL FROM:<$fromAddr>", [250]); $cmd("RCPT TO:<$to>", [250, 251]); $cmd('DATA', [354]);
    $b = 'kk' . bin2hex(random_bytes(8)); $enc = fn($x) => '=?UTF-8?B?' . base64_encode($x) . '?=';
    $msg = "From: {$enc($fromName)} <$fromAddr>\r\nTo: <$to>\r\nSubject: {$enc($subject)}\r\n"
         . ($replyTo ? "Reply-To: <$replyTo>\r\n" : '')
         . 'Date: ' . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@klinikids.com.tr>\r\nMIME-Version: 1.0\r\n"
         . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n"
         . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(html_to_text($html)))
         . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
         . "--$b--\r\n";
    $cmd($msg . '.', [250]); // base64 gövdede satır başında '.' olamaz, ekstra kaçış gerekmez
    @fwrite($fp, "QUIT\r\n"); fclose($fp);
}
