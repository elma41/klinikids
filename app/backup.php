<?php
// =====================================================================
// Yedekleme, geri yükleme ve aylık rapor
//   • Günlük yedek  : app/yedek/ klasörüne (web'den erişilemez), son 30 gün saklanır
//   • Aylık e-posta : her ayın 1'inde geçen ayın özeti + şifreli yedek dosyası
//   • Geri yükleme  : yönetim panelinden; yalnızca EKSİK kayıtları geri ekler, mevcutları ezmez
// Şifreleme: AES-256-GCM, anahtar yedek şifresinden PBKDF2-SHA256 ile türetilir.
// Sunucuda şifrenin kendisi değil yalnızca türetilmiş anahtar saklanır (config.php, 0600).
// =====================================================================
declare(strict_types=1);

const KK_BACKUP_DIR   = KK_APP . '/yedek';
const KK_BACKUP_MAGIC = 'KKYEDEK1';
const KK_BACKUP_DAYS  = 30;
const KK_BACKUP_SKIP  = ['sessions', 'rate_limits'];
// Yabancı anahtar sırasına göre (önce bağımsız tablolar)
const KK_BACKUP_ORDER = ['users', 'clinic_settings', 'doctors', 'services', 'doctor_services', 'staff_profiles',
    'working_hours', 'time_off', 'patients', 'appointments', 'institutions', 'myths', 'doctor_certificates',
    'faqs', 'gallery', 'legal_sections'];
const KK_TABLE_TR = ['users' => 'Kullanıcı hesapları', 'clinic_settings' => 'Klinik ayarları', 'doctors' => 'Hekimler',
    'services' => 'Hizmetler', 'doctor_services' => 'Hekim–hizmet eşleşmeleri', 'staff_profiles' => 'Personel yetkileri',
    'working_hours' => 'Çalışma saatleri', 'time_off' => 'İzinler', 'patients' => 'Hastalar', 'appointments' => 'Randevular',
    'institutions' => 'Kurumlar', 'myths' => 'Doğru bilinen yanlışlar', 'doctor_certificates' => 'Sertifikalar',
    'faqs' => 'Sık sorulan sorular', 'gallery' => 'Galeri', 'legal_sections' => 'KVKK metinleri'];

function backup_cfg(): array { return kk_config()['backup'] ?? []; }
function backup_state(): array { $j = @file_get_contents(KK_BACKUP_DIR . '/durum.json'); return ($j && is_array($x = json_decode($j, true))) ? $x : []; }
function backup_has_key(): bool { return !empty(backup_cfg()['key']); }

/** Yedeklenecek tablolar: bilinen sıra + (ileride eklenirse) diğerleri */
function backup_tables(): array {
    $all = array_map(fn($r) => array_values($r)[0], q('SHOW TABLES')->fetchAll());
    $all = array_values(array_diff($all, KK_BACKUP_SKIP));
    return array_merge(array_values(array_intersect(KK_BACKUP_ORDER, $all)), array_values(array_diff($all, KK_BACKUP_ORDER)));
}

/** Veritabanının tamamı → sıkıştırılmış JSON */
function backup_build_plain(): string {
    $data = ['format' => 'klinikids-yedek', 'surum' => 1, 'olusturma' => gmdate('c'),
             'site' => 'klinikids.com.tr', 'tablolar' => []];
    foreach (backup_tables() as $t) $data['tablolar'][$t] = q("SELECT * FROM `$t`")->fetchAll();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Yedek oluşturulamadı (JSON).');
    return gzencode($json, 9);
}

function backup_encrypt(string $plain, ?array $cfg = null): string {
    $cfg = $cfg ?? backup_cfg();
    if (empty($cfg['key'])) throw new ApiError('Önce bir yedek şifresi belirleyin.', 400);
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', hex2bin($cfg['key']), OPENSSL_RAW_DATA, $iv, $tag, KK_BACKUP_MAGIC);
    if ($ct === false) throw new RuntimeException('Şifreleme başarısız.');
    return KK_BACKUP_MAGIC . hex2bin($cfg['salt']) . pack('N', (int)$cfg['iter']) . $iv . $tag . $ct;
}

/** Yedek dosyasını aç: şifreliyse şifreyle çöz, değilse doğrudan; sonuç dizi */
function backup_open(string $raw, string $pass): array {
    if (str_starts_with($raw, KK_BACKUP_MAGIC)) {
        if ($pass === '') throw new ApiError('Bu yedek şifreli. Yedek şifresini yazın.', 400);
        if (strlen($raw) < 8 + 16 + 4 + 12 + 16 + 1) throw new ApiError('Yedek dosyası bozuk.', 400);
        $salt = substr($raw, 8, 16); $iter = unpack('N', substr($raw, 24, 4))[1];
        if ($iter < 10000 || $iter > 5000000) throw new ApiError('Yedek dosyası bozuk.', 400);
        $iv = substr($raw, 28, 12); $tag = substr($raw, 40, 16); $ct = substr($raw, 56);
        $key = hash_pbkdf2('sha256', $pass, $salt, $iter, 32, true);
        $raw = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, KK_BACKUP_MAGIC);
        if ($raw === false) throw new ApiError('Yedek şifresi yanlış ya da dosya bozuk.', 400);
    }
    if (str_starts_with($raw, "\x1f\x8b")) $raw = @gzdecode($raw);
    $d = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($d) || ($d['format'] ?? '') !== 'klinikids-yedek' || !is_array($d['tablolar'] ?? null))
        throw new ApiError('Bu dosya bir Klinikids yedeği değil.', 400);
    return $d;
}

function backup_filename(string $when = 'now'): string {
    $d = new DateTimeImmutable($when, new DateTimeZone('Europe/Istanbul'));
    return 'klinikids-yedek-' . $d->format('Y-m-d-His') . (backup_has_key() ? '.kkyedek' : '.json.gz');
}

/** Gece çalışan yedek: app/yedek/ klasörüne yazar, eskileri siler */
function backup_daily(): array {
    if (!is_dir(KK_BACKUP_DIR) && !mkdir(KK_BACKUP_DIR, 0700, true)) throw new RuntimeException('Yedek klasörü oluşturulamadı.');
    @file_put_contents(KK_BACKUP_DIR . '/.htaccess', "Require all denied\n");
    $plain = backup_build_plain();
    $data = backup_has_key() ? backup_encrypt($plain) : $plain;
    $name = backup_filename();
    $tmp = KK_BACKUP_DIR . "/.$name.tmp";
    if (file_put_contents($tmp, $data, LOCK_EX) === false || !rename($tmp, KK_BACKUP_DIR . "/$name")) throw new RuntimeException('Yedek yazılamadı.');
    @chmod(KK_BACKUP_DIR . "/$name", 0600);
    $silinen = 0;
    foreach (glob(KK_BACKUP_DIR . '/klinikids-yedek-*') ?: [] as $f)
        if (filemtime($f) < time() - KK_BACKUP_DAYS * 86400 && @unlink($f)) $silinen++;
    return ['dosya' => $name, 'boyut' => strlen($data), 'silinen' => $silinen];
}

function backup_local_list(): array {
    $o = [];
    foreach (glob(KK_BACKUP_DIR . '/klinikids-yedek-*') ?: [] as $f)
        $o[] = ['ad' => basename($f), 'boyut' => filesize($f), 'tarih' => gmdate('c', filemtime($f))];
    usort($o, fn($a, $b) => strcmp($b['ad'], $a['ad']));
    return $o;
}

/**
 * Geri yükleme. $apply=false → yalnızca ne ekleneceğini hesaplar (değişiklik yapmaz).
 * Mevcut kayıtlar asla değiştirilmez; yalnızca veritabanında OLMAYAN kayıtlar eklenir.
 */
function backup_restore(array $d, bool $apply): array {
    $pdo = db();
    $exist = array_map(fn($r) => array_values($r)[0], q('SHOW TABLES')->fetchAll());
    $order = array_merge(array_values(array_intersect(KK_BACKUP_ORDER, array_keys($d['tablolar']))),
                         array_values(array_diff(array_keys($d['tablolar']), KK_BACKUP_ORDER)));
    $sonuc = []; $patMap = [];
    $pdo->beginTransaction();
    try {
        foreach ($order as $t) {
            if (in_array($t, KK_BACKUP_SKIP, true) || !in_array($t, $exist, true) || !preg_match('/^[a-z_]+$/', $t)) continue;
            $cols = array_column(q("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
            $eklenen = 0; $toplam = 0;
            foreach ($d['tablolar'][$t] as $row) {
                if (!is_array($row)) continue;
                $toplam++;
                if ($t === 'patients' && isset($row['id'], $row['phone'])) {
                    // Aynı telefonla başka kimlikte kayıtlı hasta varsa yenisini açma, randevuları ona bağla
                    $cur = q('SELECT id FROM patients WHERE phone = ?', [$row['phone']])->fetchColumn();
                    if ($cur && $cur !== $row['id']) { $patMap[$row['id']] = $cur; continue; }
                }
                if ($t === 'appointments' && isset($row['patient_id'], $patMap[$row['patient_id']])) $row['patient_id'] = $patMap[$row['patient_id']];
                $row = array_intersect_key($row, array_flip($cols));
                if (!$row) continue;
                $names = array_keys($row);
                $sql = "INSERT IGNORE INTO `$t` (`" . implode('`,`', $names) . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')';
                $st = $pdo->prepare($sql);
                $st->execute(array_values(array_map(fn($v) => is_bool($v) ? (int)$v : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v), $row)));
                $eklenen += $st->rowCount();
            }
            $sonuc[] = ['tablo' => $t, 'ad' => KK_TABLE_TR[$t] ?? $t, 'yedekte' => $toplam, 'eklenecek' => $eklenen];
        }
        if ($apply) $pdo->commit(); else $pdo->rollBack();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['tarih' => $d['olusturma'] ?? null, 'tablolar' => $sonuc,
            'toplam_eklenecek' => array_sum(array_column($sonuc, 'eklenecek')), 'uygulandi' => $apply];
}

/* ------------------------- Aylık rapor ------------------------- */

const KK_AYLAR = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

/** $ym = 'YYYY-MM' (İstanbul saatiyle o ay) */
function report_month(string $ym): array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) || (int)$m[2] < 1 || (int)$m[2] > 12) throw new ApiError('Geçersiz ay.', 400);
    $tz = new DateTimeZone('Europe/Istanbul');
    $from = new DateTimeImmutable("$ym-01 00:00:00", $tz); $to = $from->modify('+1 month');
    $f = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $t = $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $st = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0];
    foreach (q('SELECT status, COUNT(*) c FROM appointments WHERE starts_at >= ? AND starts_at < ? GROUP BY status', [$f, $t]) as $r) $st[$r['status']] = (int)$r['c'];
    $src = ['online' => 0, 'phone' => 0, 'walk_in' => 0];
    foreach (q('SELECT source, COUNT(*) c FROM appointments WHERE starts_at >= ? AND starts_at < ? AND status <> \'cancelled\' GROUP BY source', [$f, $t]) as $r) $src[$r['source']] = (int)$r['c'];
    $doc = q('SELECT CONCAT(COALESCE(NULLIF(d.title,\'\'),\'Dt.\'), \' \', d.full_name) ad, d.clinic,
                     SUM(a.status <> \'cancelled\') toplam, SUM(a.status = \'completed\') gelen, SUM(a.status = \'no_show\') gelmeyen, SUM(a.status = \'cancelled\') iptal
                FROM appointments a JOIN doctors d ON d.id = a.doctor_id
               WHERE a.starts_at >= ? AND a.starts_at < ? GROUP BY d.id ORDER BY toplam DESC', [$f, $t])->fetchAll();
    $svc = q('SELECT COALESCE(s.name, NULLIF(a.custom_service_name,\'\'), \'Belirtilmemiş\') ad, COUNT(*) adet
                FROM appointments a LEFT JOIN services s ON s.id = a.service_id
               WHERE a.starts_at >= ? AND a.starts_at < ? AND a.status <> \'cancelled\' GROUP BY ad ORDER BY adet DESC', [$f, $t])->fetchAll();
    $newPat = (int)q('SELECT COUNT(*) FROM patients WHERE created_at >= ? AND created_at < ?', [$f, $t])->fetchColumn();
    $prevF = $from->modify('-1 month')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $prev = (int)q('SELECT COUNT(*) FROM appointments WHERE starts_at >= ? AND starts_at < ? AND status <> \'cancelled\'', [$prevF, $f])->fetchColumn();
    $days = ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar']; $wd = array_fill(1, 7, 0);
    foreach (q('SELECT starts_at FROM appointments WHERE starts_at >= ? AND starts_at < ? AND status <> \'cancelled\'', [$f, $t]) as $r)
        $wd[(int)(new DateTimeImmutable($r['starts_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('N')]++;
    arsort($wd);
    return ['ay' => $ym, 'baslik' => KK_AYLAR[(int)$m[2]] . ' ' . $m[1], 'durum' => $st, 'kaynak' => $src,
            'toplam' => array_sum($st) - $st['cancelled'], 'onceki_ay' => $prev, 'yeni_hasta' => $newPat,
            'hekim' => $doc, 'hizmet' => $svc, 'yogun_gun' => array_sum($wd) ? $days[array_key_first($wd)] : null];
}

function report_html(array $r, string $ekNot): string {
    $n = fn($x) => number_format((int)$x, 0, ',', '.');
    $box = fn($k, $v, $c = '#1E5F5B') => '<td style="padding:10px;border:1px solid #E6EAEE;border-radius:10px;text-align:center;width:33%"><div style="font-size:24px;font-weight:800;color:' . $c . '">' . $v . '</div><div style="font-size:12px;color:#6B7684">' . h($k) . '</div></td>';
    $diff = $r['onceki_ay'] ? round(($r['toplam'] - $r['onceki_ay']) * 100 / $r['onceki_ay']) : null;
    $o = '<p>Geçen ayın randevu özeti aşağıda. Ayrıntılı listeler için yönetim panelindeki <b>Raporlar</b> sayfasını kullanabilirsiniz.</p>'
       . '<table style="width:100%;border-collapse:separate;border-spacing:6px"><tr>'
       . $box('Toplam randevu', $n($r['toplam'])) . $box('Gelen hasta', $n($r['durum']['completed']), '#1baf7a') . $box('Yeni hasta', $n($r['yeni_hasta']), '#2a78d6')
       . '</tr><tr>' . $box('Gelmeyen', $n($r['durum']['no_show']), '#eb6834') . $box('İptal', $n($r['durum']['cancelled']), '#e34948')
       . $box('Online randevu', $n($r['kaynak']['online']), '#6250d6') . '</tr></table>';
    if ($diff !== null) $o .= '<p style="color:#6B7684;font-size:14px">Bir önceki aya göre: <b>' . ($diff >= 0 ? '+' : '') . $diff . '%</b> (' . $n($r['onceki_ay']) . ' randevu)'
        . ($r['yogun_gun'] ? ' · En yoğun gün: <b>' . h($r['yogun_gun']) . '</b>' : '') . '</p>';
    $tbl = function (string $title, array $head, array $rows) {
        if (!$rows) return '';
        $s = '<h3 style="font-size:16px;margin:20px 0 8px">' . h($title) . '</h3><table style="width:100%;border-collapse:collapse;font-size:14px"><tr>';
        foreach ($head as $i => $hd) $s .= '<th style="text-align:' . ($i ? 'right' : 'left') . ';padding:6px;border-bottom:2px solid #E6EAEE;color:#6B7684">' . h($hd) . '</th>';
        $s .= '</tr>';
        foreach ($rows as $row) { $s .= '<tr>'; foreach (array_values($row) as $i => $v) $s .= '<td style="text-align:' . ($i ? 'right' : 'left') . ';padding:6px;border-bottom:1px solid #F0F2F4">' . h($v) . '</td>'; $s .= '</tr>'; }
        return $s . '</table>';
    };
    $o .= $tbl('Hekimlere göre', ['Hekim', 'Randevu', 'Gelen', 'Gelmeyen', 'İptal'],
        array_map(fn($d) => [$d['ad'], (int)$d['toplam'], (int)$d['gelen'], (int)$d['gelmeyen'], (int)$d['iptal']], $r['hekim']));
    $o .= $tbl('İşlemlere göre', ['İşlem', 'Randevu'], array_map(fn($s) => [$s['ad'], (int)$s['adet']], array_slice($r['hizmet'], 0, 15)));
    $o .= $tbl('Randevu kaynağı', ['Kaynak', 'Randevu'], [['Online (site)', $r['kaynak']['online']], ['Telefon', $r['kaynak']['phone']], ['Klinikte', $r['kaynak']['walk_in']]]);
    return $o . '<div style="margin-top:22px;padding:12px 14px;background:#F5F7F8;border-radius:10px;font-size:13px;color:#4A5563">' . $ekNot . '</div>';
}

/** Özet tablo (hasta adı içermez) — Excel'de açılır */
function report_csv(array $r): string {
    $rows = [["Klinikids aylık rapor", $r['baslik']], [], ['Özet', 'Adet'],
        ['Toplam randevu (iptaller hariç)', $r['toplam']], ['Gelen hasta', $r['durum']['completed']], ['Gelmeyen', $r['durum']['no_show']],
        ['İptal', $r['durum']['cancelled']], ['Onay bekleyen', $r['durum']['pending']], ['Onaylı (henüz işaretlenmemiş)', $r['durum']['confirmed']],
        ['Yeni hasta', $r['yeni_hasta']], ['Önceki ay toplam', $r['onceki_ay']], ['Online', $r['kaynak']['online']], ['Telefon', $r['kaynak']['phone']], ['Klinikte', $r['kaynak']['walk_in']],
        [], ['Hekim', 'Randevu', 'Gelen', 'Gelmeyen', 'İptal']];
    foreach ($r['hekim'] as $d) $rows[] = [$d['ad'], $d['toplam'], $d['gelen'], $d['gelmeyen'], $d['iptal']];
    $rows[] = []; $rows[] = ['İşlem', 'Randevu'];
    foreach ($r['hizmet'] as $s) $rows[] = [$s['ad'], $s['adet']];
    $fp = fopen('php://temp', 'w+');
    foreach ($rows as $row) fputcsv($fp, array_map('strval', $row), ';', '"', '');
    rewind($fp); $csv = stream_get_contents($fp); fclose($fp);
    return "\xEF\xBB\xBF" . $csv; // Excel Türkçe karakterleri doğru açsın
}

/** Aylık raporu (ve şifreli yedeği) gönderir */
function report_send(string $ym, ?string $to = null): array {
    $cfg = mcfg();
    if ($cfg['driver'] === 'none') throw new ApiError('E-posta gönderimi ayarlı değil. Önce E-posta ayarlarını tamamlayın.', 400);
    $to = $to ?: $cfg['clinic'];
    $r = report_month($ym);
    $att = [['ad' => "klinikids-rapor-{$r['ay']}.csv", 'tur' => 'text/csv', 'veri' => report_csv($r)]];
    if (backup_has_key()) {
        $enc = backup_encrypt(backup_build_plain());
        if (strlen($enc) <= 18 * 1024 * 1024) {
            $att[] = ['ad' => 'klinikids-yedek-' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d') . '.kkyedek', 'tur' => 'application/octet-stream', 'veri' => $enc];
            $not = '🔐 <b>Ekteki .kkyedek dosyası</b> sitenin tam yedeğidir (randevular, hastalar, hekimler, takvim, site içerikleri). Şifrelidir; yalnızca yedek şifrenizle açılır. '
                 . 'Bu e-postayı silmeyin. Bir sorun olursa yönetim paneli → <b>Yedekleme</b> → <b>Yedekten geri yükle</b> bölümünden bu dosyayı yükleyebilirsiniz.';
        } else $not = 'Yedek dosyası e-postaya eklenemeyecek kadar büyük; yönetim panelindeki Yedekleme sayfasından indirin.';
    } else $not = '⚠️ Yedek şifresi henüz belirlenmediği için bu e-postaya yedek dosyası eklenmedi. Yönetim paneli → <b>Yedekleme</b> sayfasından bir yedek şifresi belirleyin.';
    mail_send($to, "Klinikids aylık rapor · {$r['baslik']}", mail_shell("Aylık rapor · {$r['baslik']}", report_html($r, $not)), null, $att);
    return ['gonderildi' => $to, 'ay' => $r['baslik'], 'yedek_eklendi' => count($att) > 1];
}

/** Geçen ayın 'YYYY-MM' değeri (İstanbul saatiyle) */
function report_prev_month(): string {
    return (new DateTimeImmutable('first day of last month', new DateTimeZone('Europe/Istanbul')))->format('Y-m');
}

/** Gece görevi: yedek + ayın ilk haftasında geçen ayın raporu (rapor hatası yedeği etkilemez) */
function cron_run(bool $forceReport = false): array {
    $log = ['yedek' => backup_daily()];
    $st = backup_state(); $st['son_calisma'] = gmdate('c');
    $ym = report_prev_month();
    $mark = KK_BACKUP_DIR . "/.aylik-$ym";
    $day = (int)(new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('j');
    if ($forceReport || ($day <= 7 && !is_file($mark))) { // 1'inde gönderilemezse ilk hafta her gün yeniden dener
        try {
            $log['rapor'] = report_send($ym);
            @file_put_contents($mark, gmdate('c'));
            $st['son_rapor'] = $ym; unset($st['rapor_hata']);
        } catch (Throwable $e) { $st['rapor_hata'] = $e->getMessage(); error_log('KK rapor: ' . $e->getMessage()); }
    }
    @file_put_contents(KK_BACKUP_DIR . '/durum.json', json_encode($st, JSON_UNESCAPED_UNICODE));
    return $log;
}

/**
 * Sunucuda zamanlanmış görev (cron) yoksa: her gün 03:00'ten sonraki İLK ziyarette gece görevini
 * yanıt ziyaretçiye gönderildikten SONRA arka planda çalıştırır. Günde en fazla bir kez, kilitli.
 */
function cron_maybe(): void {
    $tz = new DateTimeZone('Europe/Istanbul');
    $t3 = (new DateTimeImmutable('today 03:00', $tz))->getTimestamp();
    $due = time() >= $t3 ? $t3 : $t3 - 86400;              // en son geçmesi gereken 03:00
    $last = strtotime((string)(backup_state()['son_calisma'] ?? '')) ?: 0;
    if ($last >= $due) return;
    if (!is_dir(KK_BACKUP_DIR) && !@mkdir(KK_BACKUP_DIR, 0700, true)) return;
    $fp = @fopen(KK_BACKUP_DIR . '/.kilit', 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) return;     // başka bir istek zaten çalıştırıyor
    register_shutdown_function(function () use ($fp) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @set_time_limit(300); @ignore_user_abort(true);
        try { cron_run(); } catch (Throwable $e) { error_log('KK gece görevi: ' . $e->getMessage()); }
        flock($fp, LOCK_UN); fclose($fp);
    });
}

/* ------------------------- Panel uç noktaları ------------------------- */

function fn_yedek(array $b): array {
    $c = require_admin();
    $a = (string)($b['action'] ?? 'durum');
    if ($a === 'durum') {
        $bc = backup_cfg();
        return ['sifre_var' => backup_has_key(), 'sifre_tarihi' => $bc['set_at'] ?? null, 'son_calisma' => backup_state()['son_calisma'] ?? null,
                'son_rapor' => backup_state()['son_rapor'] ?? null, 'rapor_hata' => backup_state()['rapor_hata'] ?? null, 'yerel' => backup_local_list(), 'eposta' => mcfg()['driver'] !== 'none',
                'alici' => mcfg()['clinic'], 'gecen_ay' => report_prev_month(), 'cron_yolu' => KK_APP . '/cron.php'];
    }
    if ($a === 'sifre') {
        $p = (string)($b['sifre'] ?? '');
        if (mb_strlen($p) < 10) throw new ApiError('Yedek şifresi en az 10 karakter olmalı.', 400);
        rate_limit('yedek-sifre:' . $c->uid, 5, 3600);
        $hash = q('SELECT password_hash FROM users WHERE id = ?', [$c->uid])->fetchColumn();
        if (!$hash || !password_verify((string)($b['hesap_sifresi'] ?? ''), $hash)) throw new ApiError('Giriş şifreniz hatalı.', 400);
        $salt = random_bytes(16); $iter = 300000;
        $cfg = kk_config();
        $cfg['backup'] = array_merge($cfg['backup'] ?? [], ['salt' => bin2hex($salt), 'iter' => $iter,
            'key' => bin2hex(hash_pbkdf2('sha256', $p, $salt, $iter, 32, true)), 'set_at' => gmdate('c')]);
        write_config($cfg);
        return ['ok' => true];
    }
    if ($a === 'simdi') { return backup_daily(); }
    if ($a === 'rapor') {
        rate_limit('yedek-rapor:' . $c->uid, 6, 3600);
        $ym = (string)($b['ay'] ?? report_prev_month());
        return report_send($ym);
    }
    throw new ApiError('Bilinmeyen işlem.', 400);
}

/** GET: yedek dosyası indirme (yeni oluşturulan ya da sunucudaki günlük yedek) */
function fn_yedek_indir(): void {
    require_admin();
    $ad = (string)($_GET['ad'] ?? '');
    if ($ad !== '') {
        if (!preg_match('/^klinikids-yedek-[0-9-]+\.(kkyedek|json\.gz)$/', $ad) || !is_file(KK_BACKUP_DIR . "/$ad")) throw new ApiError('Dosya bulunamadı.', 404);
        $data = file_get_contents(KK_BACKUP_DIR . "/$ad");
    } else {
        if (!backup_has_key()) throw new ApiError('Önce bir yedek şifresi belirleyin; indirilen yedekler şifreli olmalı.', 400);
        $data = backup_encrypt(backup_build_plain()); $ad = backup_filename();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $ad . '"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    echo $data;
}

/** POST multipart: dosya + sifre + onay (0 = önizleme, 1 = uygula) */
function fn_yedek_geri(): array {
    $c = require_admin();
    rate_limit('yedek-geri:' . $c->uid, 20, 3600);
    $f = $_FILES['dosya'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new ApiError('Yedek dosyası yüklenemedi.', 400);
    if ($f['size'] > 60 * 1024 * 1024) throw new ApiError('Dosya çok büyük.', 400);
    $d = backup_open((string)file_get_contents($f['tmp_name']), (string)($_POST['sifre'] ?? ''));
    $apply = ($_POST['onay'] ?? '') === '1';
    if ($apply) { try { backup_daily(); } catch (Throwable $e) { /* geri yüklemeden önce güvenlik yedeği; olmazsa da devam */ } }
    return backup_restore($d, $apply);
}
