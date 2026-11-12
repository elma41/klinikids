<?php
// Kurulum sayfası: /api/kurulum
//   1) Kurulum anahtarı + veritabanı bilgileri → app/config.php yazılır, tablolar oluşturulur,
//      mevcut veriler Supabase'ten tek seferde aktarılır (yönetici şifresi aynen korunur).
//   2) Kurulumdan sonra yalnızca yönetici: e-posta gönderim ayarları.
declare(strict_types=1);

// Kurulum anahtarının SHA-256 özeti (anahtarın kendisi kod içinde yok)
const INSTALL_KEY_HASH = '7a9a3b631f88f64bf1545f3464030af40592219b2df750280429dd9dea30a9c8';
define('SUPA_URL', getenv('KK_SUPA_URL') ?: 'https://xnjnxogvnldrbridzvqu.supabase.co'); // test için değiştirilebilir
const SUPA_KEY = 'sb_publishable_CpJXbL8ZBECdSo3uSYXqdg_5W1DStIb';

function page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
       . h($title) . '</title><style>body{font-family:system-ui,Arial,sans-serif;background:#F5F7F8;color:#1B2533;margin:0;padding:24px}'
       . '.c{max-width:560px;margin:0 auto;background:#fff;border:1px solid #E6EAEE;border-radius:16px;padding:28px}h1{font-size:22px;margin:0 0 6px}'
       . 'label{display:block;font-weight:700;font-size:14px;margin:14px 0 6px}input,select{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #CFD6DD;border-radius:10px;font:inherit}'
       . 'button{margin-top:20px;background:#1E5F5B;color:#fff;border:0;border-radius:10px;padding:12px 18px;font:inherit;font-weight:700;cursor:pointer;width:100%}'
       . '.muted{color:#6B7684;font-size:13px}.err{background:#FDECEC;color:#9B1C1C;padding:10px 12px;border-radius:10px;margin:12px 0}'
       . '.ok{background:#E8F7EE;color:#14532D;padding:10px 12px;border-radius:10px;margin:12px 0}fieldset{border:1px solid #E6EAEE;border-radius:12px;margin-top:16px}'
       . 'legend{font-weight:700;padding:0 6px}code{background:#F1F3F5;padding:2px 6px;border-radius:6px}</style></head><body><div class="c">'
       . $body . '</div></body></html>';
}

function csrf_token(): string {
    $t = $_COOKIE['kk_csrf'] ?? '';
    if (!is_string($t) || strlen($t) !== 64) {
        $t = bin2hex(random_bytes(32));
        setcookie('kk_csrf', $t, ['path' => '/api/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    }
    return $t;
}
function csrf_check(): void {
    $c = $_COOKIE['kk_csrf'] ?? ''; $p = $_POST['csrf'] ?? '';
    if (!is_string($c) || !is_string($p) || $c === '' || !hash_equals($c, $p)) throw new ApiError('Oturum süresi doldu, sayfayı yenileyip tekrar deneyin.');
}

function write_config(array $cfg): void {
    $php = "<?php\n// Klinikids ayarları — bu dosya git'e girmez. Kurulum sayfası oluşturdu: " . gmdate('c') . "\nreturn " . var_export($cfg, true) . ";\n";
    $tmp = KK_CONFIG . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, KK_CONFIG)) throw new ApiError('Ayar dosyası yazılamadı (app/ klasörüne yazma izni yok).', 500);
    @chmod(KK_CONFIG, 0600);
    if (function_exists('opcache_invalidate')) @opcache_invalidate(KK_CONFIG, true);
    kk_config(true); // bu istekte de yeni ayarlar geçerli olsun
}

/** Yeni sürümde eklenen tabloları canlı veritabanına bir kez uygular (schema.sql tekrar çalıştırmaya uygundur) */
const KK_SCHEMA_V = 2;
function kk_migrate(): void {
    if ((int)(kk_config()['schema_v'] ?? 1) >= KK_SCHEMA_V) return;
    run_schema(db());
    $c = kk_config(); $c['schema_v'] = KK_SCHEMA_V; write_config($c);
}

function run_schema(PDO $pdo): void {
    $sql = file_get_contents(KK_APP . '/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);
}

function setup_handle(string $sub): void {
    try {
        if ($sub === 'eposta') { setup_mail(); return; }
        if (!kk_installed()) { setup_install(); return; }
        $c = ctx();
        page('Klinikids kurulum', '<h1>Kurulum tamamlandı ✅</h1><p>Sistem kurulu ve çalışıyor.</p>'
            . '<p><a href="/admin.html">Yönetim paneline git</a>' . ($c->is('admin') ? ' · <a href="/api/kurulum/eposta">E-posta ayarları</a>' : '') . '</p>');
    } catch (ApiError $e) {
        page('Kurulum', '<h1>Bir sorun oluştu</h1><div class="err">' . h($e->getMessage()) . '</div><p><a href="">Geri dön</a></p>');
    }
}

function setup_install(): void {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            csrf_check();
            $key = trim((string)($_POST['key'] ?? ''));
            if (!hash_equals(INSTALL_KEY_HASH, hash('sha256', $key))) { sleep(2); throw new ApiError('Kurulum anahtarı hatalı.'); }
            $db = ['host' => trim($_POST['host'] ?: 'localhost'), 'port' => (int)($_POST['port'] ?: 3306),
                   'name' => trim((string)$_POST['name']), 'user' => trim((string)$_POST['user']), 'pass' => (string)$_POST['pass']];
            try {
                $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']), $db['user'], $db['pass'],
                               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (PDOException $e) { throw new ApiError('Veritabanına bağlanılamadı. Ad, kullanıcı ve şifreyi kontrol edin.'); }
            $pdo->exec("SET time_zone = '+00:00'");
            run_schema($pdo);
            $report = import_from_supabase($pdo, $key);
            write_config(['db' => $db, 'mail' => ['driver' => 'none', 'from' => 'Klinikids <bildirim@klinikids.com.tr>', 'clinic' => 'aysegul@klinikids.company'],
                          'installed_at' => gmdate('c')]);
            page('Kurulum tamamlandı', '<h1>Kurulum tamamlandı ✅</h1><div class="ok">Tablolar oluşturuldu ve veriler aktarıldı.</div><ul>' . $report
                . '</ul><p>Yönetim paneline eski e-posta ve şifrenizle girebilirsiniz.</p><p><a href="/admin.html">Yönetim paneline git</a> · <a href="/api/kurulum/eposta">E-posta ayarları</a></p>');
            return;
        } catch (ApiError $e) { $err = $e->getMessage(); }
    }
    $t = csrf_token();
    page('Klinikids kurulum', '<h1>Klinikids kurulum</h1><p class="muted">Bu sayfa sistemi sunucunuzdaki veritabanına bağlar, tabloları oluşturur ve mevcut verileri aktarır. Bir kez kullanılır.</p>'
        . ($err ? '<div class="err">' . h($err) . '</div>' : '')
        . '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . h($t) . '">'
        . '<label>Kurulum anahtarı</label><input name="key" required placeholder="KK-......-......-......-......">'
        . '<fieldset><legend>Veritabanı (Plesk → Databases)</legend>'
        . '<label>Veritabanı adı</label><input name="name" required>'
        . '<label>Kullanıcı adı</label><input name="user" required>'
        . '<label>Şifre</label><input name="pass" type="password" required>'
        . '<label>Sunucu</label><input name="host" value="localhost"><label>Port</label><input name="port" value="3306"></fieldset>'
        . '<button>Kur ve verileri aktar</button></form>');
}

/** Supabase'teki tek seferlik dışa aktarma fonksiyonundan verileri çek ve yükle */
function import_from_supabase(PDO $pdo, string $key): string {
    if ((int)$pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn() > 0) return '<li>Veritabanında zaten veri var, aktarım atlandı.</li>';
    $ch = curl_init(SUPA_URL . '/rest/v1/rpc/kk_export');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['apikey: ' . SUPA_KEY, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['p_key' => $key])]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $d = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($d)) throw new ApiError('Supabase\'ten veri alınamadı (' . $code . '). Aktarım kodu süresi dolmuş olabilir.');

    $ts = fn($v) => $v ? to_db_time($v) : null;
    $b = fn($v) => $v ? 1 : 0;
    $j = fn($v) => json_encode($v ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ins = function (string $t, array $row) use ($pdo) {
        $cols = array_keys($row);
        $pdo->prepare("INSERT INTO `$t` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($row));
    };
    $n = [];
    $pdo->beginTransaction();
    try {
        foreach ($d['clinic_settings'] ?? [] as $r)
            $pdo->prepare('UPDATE clinic_settings SET clinic_name=?, timezone=?, slot_step_minutes=?, min_notice_minutes=?, max_days_ahead=?, auto_confirm=? WHERE id=1')
                ->execute([$r['clinic_name'], $r['timezone'], $r['slot_step_minutes'], $r['min_notice_minutes'], $r['max_days_ahead'], $b($r['auto_confirm'])]);
        foreach ($d['users'] ?? [] as $r) { $ins('users', ['id' => $r['id'], 'email' => strtolower($r['email']), 'password_hash' => $r['encrypted_password'] ?: password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
            'must_change_password' => $b($r['must_change_password'] ?? false), 'last_sign_in_at' => $ts($r['last_sign_in_at'] ?? null), 'created_at' => $ts($r['created_at']) ?? now_db()]); $n['Kullanıcı'] = ($n['Kullanıcı'] ?? 0) + 1; }
        foreach ($d['doctors'] ?? [] as $r) { $ins('doctors', ['id' => $r['id'], 'full_name' => $r['full_name'], 'title' => $r['title'], 'specialty' => $r['specialty'], 'bio' => $r['bio'],
            'photo_url' => $r['photo_url'], 'color' => $r['color'], 'is_active' => $b($r['is_active']), 'sort_order' => $r['sort_order'], 'created_at' => $ts($r['created_at']),
            'clinic' => $r['clinic'], 'profile' => $j($r['profile'])]); $n['Hekim'] = ($n['Hekim'] ?? 0) + 1; }
        foreach ($d['services'] ?? [] as $r) $ins('services', ['id' => $r['id'], 'name' => $r['name'], 'description' => $r['description'], 'duration_minutes' => $r['duration_minutes'],
            'is_active' => $b($r['is_active']), 'sort_order' => $r['sort_order'], 'clinic' => $r['clinic']]);
        $n['Hizmet'] = count($d['services'] ?? []);
        foreach ($d['doctor_services'] ?? [] as $r) $ins('doctor_services', ['doctor_id' => $r['doctor_id'], 'service_id' => $r['service_id']]);
        foreach ($d['staff_profiles'] ?? [] as $r) $ins('staff_profiles', ['user_id' => $r['user_id'], 'full_name' => $r['full_name'], 'role' => $r['role'], 'doctor_id' => $r['doctor_id']]);
        foreach ($d['working_hours'] ?? [] as $r) $ins('working_hours', ['id' => $r['id'], 'doctor_id' => $r['doctor_id'], 'weekday' => $r['weekday'], 'start_time' => $r['start_time'], 'end_time' => $r['end_time']]);
        $n['Çalışma saati'] = count($d['working_hours'] ?? []);
        foreach ($d['time_off'] ?? [] as $r) $ins('time_off', ['id' => $r['id'], 'doctor_id' => $r['doctor_id'], 'starts_at' => $ts($r['starts_at']), 'ends_at' => $ts($r['ends_at']), 'reason' => $r['reason'], 'created_at' => $ts($r['created_at'])]);
        foreach ($d['patients'] ?? [] as $r) $ins('patients', ['id' => $r['id'], 'full_name' => $r['full_name'], 'phone' => $r['phone'], 'email' => $r['email'],
            'kvkk_consent_at' => $ts($r['kvkk_consent_at']), 'notes' => $r['notes'], 'created_at' => $ts($r['created_at'])]);
        $n['Hasta'] = count($d['patients'] ?? []);
        foreach ($d['appointments'] ?? [] as $r) $ins('appointments', ['id' => $r['id'], 'doctor_id' => $r['doctor_id'], 'service_id' => $r['service_id'], 'custom_service_name' => $r['custom_service_name'],
            'patient_id' => $r['patient_id'], 'starts_at' => $ts($r['starts_at']), 'ends_at' => $ts($r['ends_at']), 'status' => $r['status'], 'source' => $r['source'],
            'patient_note' => $r['patient_note'], 'staff_note' => $r['staff_note'], 'cancel_token' => $r['cancel_token'], 'created_at' => $ts($r['created_at'])]);
        $n['Randevu'] = count($d['appointments'] ?? []);
        foreach ($d['institutions'] ?? [] as $r) $ins('institutions', ['id' => $r['id'], 'name' => $r['name'], 'logo_path' => $r['logo_path'], 'sort_order' => $r['sort_order'], 'is_active' => $b($r['is_active']), 'created_at' => $ts($r['created_at'])]);
        $n['Kurum'] = count($d['institutions'] ?? []);
        foreach ($d['myths'] ?? [] as $r) $ins('myths', ['id' => $r['id'], 'clinic' => $r['clinic'], 'emoji' => $r['emoji'], 'myth' => $r['myth'], 'truth' => $r['truth'], 'sort_order' => $r['sort_order'], 'is_active' => $b($r['is_active']), 'created_at' => $ts($r['created_at'])]);
        $n['Doğru bilinen yanlış'] = count($d['myths'] ?? []);
        foreach ($d['doctor_certificates'] ?? [] as $r) $ins('doctor_certificates', ['id' => $r['id'], 'doctor_id' => $r['doctor_id'], 'title' => $r['title'], 'issuer' => $r['issuer'], 'year' => $r['year'],
            'file_path' => $r['file_path'], 'file_type' => $r['file_type'], 'sort_order' => $r['sort_order'], 'is_active' => $b($r['is_active']), 'created_at' => $ts($r['created_at'])]);
        foreach ($d['faqs'] ?? [] as $r) $ins('faqs', ['id' => $r['id'], 'clinic' => $r['clinic'], 'question' => $r['question'], 'answer' => $r['answer'], 'sort_order' => $r['sort_order'], 'is_active' => $b($r['is_active']), 'created_at' => $ts($r['created_at'])]);
        $n['SSS'] = count($d['faqs'] ?? []);
        foreach ($d['gallery'] ?? [] as $r) $ins('gallery', ['id' => $r['id'], 'clinic' => $r['clinic'], 'url' => $r['url'], 'file_path' => $r['file_path'], 'caption' => $r['caption'], 'sort_order' => $r['sort_order'], 'is_active' => $b($r['is_active']), 'created_at' => $ts($r['created_at'])]);
        $n['Galeri'] = count($d['gallery'] ?? []);
        foreach ($d['legal_sections'] ?? [] as $r) $ins('legal_sections', ['id' => $r['id'], 'slug' => $r['slug'], 'title' => $r['title'], 'body' => $r['body'], 'sort_order' => $r['sort_order'], 'is_active' => $b($r['is_active']), 'updated_at' => $ts($r['updated_at'])]);
        $n['KVKK bölümü'] = count($d['legal_sections'] ?? []);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); error_log('KK import: ' . $e->getMessage()); throw new ApiError('Veriler aktarılırken hata: ' . $e->getMessage()); }

    // Yüklenmiş dosyaları (logo, sertifika, hekim fotoğrafı, galeri) indir
    $files = 0; $fail = 0;
    foreach ($d['files'] ?? [] as $f) {
        $bucket = $f['bucket_id']; $name = $f['name'];
        if (!isset(BUCKETS[$bucket]) || !preg_match('#^[A-Za-z0-9._/-]+$#', $name) || str_contains($name, '..')) continue;
        $dest = KK_UPLOADS . "/$bucket/$name";
        if (is_file($dest)) continue;
        $ch = curl_init(SUPA_URL . "/storage/v1/object/public/$bucket/" . implode('/', array_map('rawurlencode', explode('/', $name))));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true]);
        $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($code === 200 && $data !== false) { @mkdir(dirname($dest), 0755, true); file_put_contents($dest, $data); $files++; } else $fail++;
    }
    $n['Dosya'] = $files . ($fail ? " ($fail tanesi indirilemedi)" : '');
    $o = ''; foreach ($n as $k => $v) $o .= '<li>' . h($k) . ': <b>' . h((string)$v) . '</b></li>';
    return $o;
}

function setup_mail(): void {
    $c = ctx();
    if (!$c->is('admin')) { page('E-posta ayarları', '<h1>Yetki gerekli</h1><p>Önce <a href="/admin.html">yönetim paneline</a> yönetici hesabıyla giriş yapın, sonra bu sayfayı yenileyin.</p>'); return; }
    $cfg = kk_config(); $m = mcfg(); $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $m['driver'] = in_array($_POST['driver'] ?? '', ['none', 'smtp', 'resend'], true) ? $_POST['driver'] : 'none';
        $m['from'] = trim((string)($_POST['from'] ?? $m['from']));
        $m['clinic'] = trim((string)($_POST['clinic'] ?? $m['clinic']));
        $m['smtp'] = ['host' => trim((string)($_POST['smtp_host'] ?? '')), 'port' => (int)($_POST['smtp_port'] ?: 465),
                      'secure' => ($_POST['smtp_secure'] ?? 'ssl') === 'tls' ? 'tls' : 'ssl', 'user' => trim((string)($_POST['smtp_user'] ?? '')),
                      'pass' => ($_POST['smtp_pass'] ?? '') !== '' ? (string)$_POST['smtp_pass'] : ($m['smtp']['pass'] ?? '')];
        if (($_POST['resend_key'] ?? '') !== '') $m['resend_key'] = trim((string)$_POST['resend_key']);
        if (!filter_var($m['clinic'], FILTER_VALIDATE_EMAIL)) $msg = '<div class="err">Bildirim adresi geçerli değil.</div>';
        else {
            $cfg['mail'] = $m; write_config($cfg);
            $msg = '<div class="ok">Kaydedildi.</div>';
            if (!empty($_POST['test'])) {
                try { mail_send($m['clinic'], 'Klinikids test e-postası', mail_shell('Test e-postası ✅', '<p>E-posta ayarlarınız çalışıyor.</p>')); $msg .= '<div class="ok">Test e-postası ' . h($m['clinic']) . ' adresine gönderildi.</div>'; }
                catch (Throwable $e) { $msg .= '<div class="err">Test e-postası gönderilemedi: ' . h($e->getMessage()) . '</div>'; }
            }
        }
    }
    $t = csrf_token(); $s = $m['smtp'] ?? [];
    $sel = fn($v) => ($m['driver'] === $v ? ' selected' : '');
    page('E-posta ayarları', '<h1>E-posta ayarları</h1><p class="muted">Randevu bildirimleri ve hastaya giden onay e-postaları.</p>' . $msg
        . '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="' . h($t) . '">'
        . '<label>Gönderim yöntemi</label><select name="driver"><option value="none"' . $sel('none') . '>Kapalı</option><option value="smtp"' . $sel('smtp') . '>SMTP (ör. Yandex posta kutusu)</option><option value="resend"' . $sel('resend') . '>Resend</option></select>'
        . '<label>Bildirimlerin gideceği adres (klinik)</label><input name="clinic" value="' . h($m['clinic']) . '">'
        . '<label>Gönderen</label><input name="from" value="' . h($m['from']) . '"><p class="muted">SMTP kullanıyorsanız gönderen adres, giriş yaptığınız posta kutusuyla aynı olmalı.</p>'
        . '<fieldset><legend>SMTP</legend><label>Sunucu</label><input name="smtp_host" value="' . h($s['host'] ?? '') . '" placeholder="smtp.yandex.com.tr">'
        . '<label>Port</label><input name="smtp_port" value="' . h((string)($s['port'] ?? 465)) . '"><label>Güvenlik</label><select name="smtp_secure"><option value="ssl">SSL (465)</option><option value="tls"' . (($s['secure'] ?? '') === 'tls' ? ' selected' : '') . '>STARTTLS (587)</option></select>'
        . '<label>Kullanıcı (e-posta)</label><input name="smtp_user" value="' . h($s['user'] ?? '') . '"><label>Şifre</label><input name="smtp_pass" type="password" placeholder="' . (!empty($s['pass']) ? '•••••• (değiştirmek için yazın)' : '') . '"></fieldset>'
        . '<fieldset><legend>Resend</legend><label>API anahtarı</label><input name="resend_key" type="password" placeholder="' . (!empty($m['resend_key']) ? '•••••• (değiştirmek için yazın)' : 're_...') . '"></fieldset>'
        . '<label style="font-weight:400"><input type="checkbox" name="test" value="1" style="width:auto"> Kaydettikten sonra test e-postası gönder</label>'
        . '<button>Kaydet</button></form><p><a href="/admin.html">← Yönetim paneli</a></p>');
}
