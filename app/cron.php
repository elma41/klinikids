<?php
// Klinikids — zamanlanmış görev (Plesk → Zamanlanmış Görevler → "PHP betiği çalıştır", her gün 03:00)
//   php app/cron.php          → günlük yedek; ayın 1'inde (kaçarsa 2–3'ünde) geçen ayın raporu + şifreli yedek e-postası
//   php app/cron.php rapor    → raporu hemen gönder (deneme için)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Yalnızca komut satırından çalışır.'); }

foreach (['bootstrap', 'tables', 'auth', 'mail', 'setup', 'backup'] as $f) require __DIR__ . "/$f.php";

try {
    if (!kk_installed()) throw new RuntimeException('Sistem kurulu değil.');
    $log = cron_run(($argv[1] ?? '') === 'rapor');
    echo gmdate('c') . ' OK ' . json_encode($log, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    $msg = gmdate('c') . ' HATA ' . $e->getMessage();
    error_log('KK cron: ' . $e->getMessage());
    fwrite(STDERR, $msg . "\n");
    // Yedek alınamadıysa kliniğe haber ver (e-posta çalışıyorsa)
    try {
        if (function_exists('mail_send') && mcfg()['driver'] !== 'none')
            mail_send(mcfg()['clinic'], 'Klinikids: gece yedeği alınamadı',
                mail_shell('Gece yedeği alınamadı ⚠️', '<p>Otomatik yedekleme sırasında bir hata oluştu:</p><p><code>' . h($e->getMessage()) . '</code></p><p>Lütfen site yöneticinize haber verin.</p>'));
    } catch (Throwable $e2) { }
    exit(1);
}
