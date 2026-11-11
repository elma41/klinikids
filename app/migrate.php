<?php
// Tek seferlik: dış adreslerdeki fotoğrafları (galeri, hekim fotoğrafı) kendi sunucumuza indir.
//   POST /api/functions/v1/dis-kaynak   {"limit": 8}   (yalnızca yönetici)
// Her çağrı en fazla "limit" dosya işler; "kalan" 0 olana kadar tekrar çağrılır.
declare(strict_types=1);

const EXT_HOSTS = ['aysegulaydin.gumlet.io', 'pixel-p2.s3.eu-central-1.amazonaws.com'];

function ext_download(string $url): array {
    $host = parse_url($url, PHP_URL_HOST);
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array($host, EXT_HOSTS, true)) throw new RuntimeException('İzin verilmeyen adres');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: image/webp,image/jpeg,image/png;q=0.9'], CURLOPT_MAXFILESIZE => 15 * 1048576]);
    $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($code !== 200 || !is_string($data) || $data === '') throw new RuntimeException("İndirilemedi (HTTP $code)");
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) throw new RuntimeException("Resim değil ($mime)");
    return [$data, $ext];
}

function ext_save(string $bucket, string $path, string $data): void {
    $full = KK_UPLOADS . "/$bucket/$path";
    if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0755, true)) throw new RuntimeException('Klasör oluşturulamadı');
    if (file_put_contents($full, $data, LOCK_EX) === false) throw new RuntimeException('Dosya yazılamadı');
    @chmod($full, 0644);
}

function fn_dis_kaynak(array $b): array {
    require_admin();
    @set_time_limit(120);
    $limit = max(1, min(20, (int)($b['limit'] ?? 8)));
    $done = []; $failed = [];
    $hostLike = fn() => implode(' OR ', array_map(fn($h) => "url LIKE 'https://$h/%'", EXT_HOSTS));

    // 1) Galeri
    $rows = q("SELECT id, url FROM gallery WHERE file_path IS NULL AND (" . $hostLike() . ") ORDER BY sort_order LIMIT $limit")->fetchAll();
    foreach ($rows as $r) {
        try {
            [$data, $ext] = ext_download($r['url']);
            $path = uuid4() . ".$ext";
            ext_save('galeri', $path, $data);
            q('UPDATE gallery SET file_path = ?, url = NULL WHERE id = ? AND file_path IS NULL', [$path, $r['id']]);
            $done[] = 'galeri: ' . basename(parse_url($r['url'], PHP_URL_PATH));
        } catch (Throwable $e) { $failed[] = $r['url'] . ' → ' . $e->getMessage(); }
    }
    // 2) Hekim fotoğrafları
    $left = $limit - count($rows);
    if ($left > 0) {
        $docs = q("SELECT id, full_name, photo_url url FROM doctors WHERE " . str_replace('url LIKE', 'photo_url LIKE', $hostLike()) . " LIMIT $left")->fetchAll();
        $origin = 'https://' . preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'www.klinikids.com.tr'));
        foreach ($docs as $d) {
            try {
                [$data, $ext] = ext_download($d['url']);
                $path = $d['id'] . '/' . uuid4() . ".$ext";
                ext_save('hekim-foto', $path, $data);
                q('UPDATE doctors SET photo_url = ? WHERE id = ?', ["$origin/uploads/hekim-foto/$path", $d['id']]);
                $done[] = 'hekim: ' . $d['full_name'];
            } catch (Throwable $e) { $failed[] = $d['full_name'] . ' → ' . $e->getMessage(); }
        }
    }
    $remaining = (int)q("SELECT (SELECT COUNT(*) FROM gallery WHERE file_path IS NULL AND (" . $hostLike() . "))
                              + (SELECT COUNT(*) FROM doctors WHERE " . str_replace('url LIKE', 'photo_url LIKE', $hostLike()) . ")")->fetchColumn();
    return ['tasinan' => $done, 'hata' => $failed, 'kalan' => $remaining];
}
