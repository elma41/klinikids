<?php
// Dosya yükleme (Supabase Storage'ın yerine) — dosyalar public_html/uploads/{kova}/ altında durur
//   POST   /api/storage/v1/object/{kova}/{yol}   gövde: dosyanın kendisi
//   DELETE /api/storage/v1/object/{kova}         gövde: {"prefixes": ["yol1", ...]}
declare(strict_types=1);

const BUCKETS = [
    'galeri'         => ['max' => 8388608, 'mime' => ['image/png', 'image/jpeg', 'image/webp'], 'scope' => 'admin'],
    'kurum-logolari' => ['max' => 2097152, 'mime' => ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'], 'scope' => 'admin'],
    'hekim-foto'     => ['max' => 5242880, 'mime' => ['image/png', 'image/jpeg', 'image/webp'], 'scope' => 'doctor-folder'],
    'sertifikalar'   => ['max' => 5242880, 'mime' => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'], 'scope' => 'doctor-folder'],
];
const MIME_EXT = ['image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/webp' => ['webp'], 'image/svg+xml' => ['svg'], 'application/pdf' => ['pdf']];

function storage_can(string $bucket, string $path, Ctx $c): bool {
    $b = BUCKETS[$bucket] ?? null;
    if (!$b || !$c->uid) return false;
    if ($c->is('admin')) return true;
    if ($b['scope'] === 'doctor-folder' && $c->doctorId) return explode('/', $path)[0] === $c->doctorId;
    return false;
}

function safe_path(string $path): string {
    $path = trim($path, '/');
    if ($path === '' || strlen($path) > 200) throw new ApiError('Geçersiz dosya yolu.');
    foreach (explode('/', $path) as $seg)
        if ($seg === '' || $seg === '.' || $seg === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $seg)) throw new ApiError('Geçersiz dosya yolu.');
    return $path;
}

function storage_upload(string $bucket, string $path): array {
    $c = ctx(); $path = safe_path($path);
    if (!isset(BUCKETS[$bucket])) throw new ApiError('Bucket not found', 404);
    if (!storage_can($bucket, $path, $c)) throw new ApiError('new row violates row-level security policy', 403, '42501');
    $data = file_get_contents('php://input', false, null, 0, BUCKETS[$bucket]['max'] + 1);
    if ($data === false || $data === '') throw new ApiError('Dosya boş.');
    if (strlen($data) > BUCKETS[$bucket]['max']) throw new ApiError('Dosya çok büyük (en fazla ' . round(BUCKETS[$bucket]['max'] / 1048576) . ' MB).', 413);
    // Gerçek içerik türünü dosyanın kendisinden belirle (uzantıya güvenme)
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: '';
    if ($mime === 'text/xml' || $mime === 'text/plain' || $mime === 'image/svg') $mime = str_contains(substr($data, 0, 2000), '<svg') ? 'image/svg+xml' : $mime;
    if (!in_array($mime, BUCKETS[$bucket]['mime'], true)) throw new ApiError('Bu dosya türü yüklenemez.', 415);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, MIME_EXT[$mime], true)) throw new ApiError('Dosya uzantısı içerikle uyuşmuyor.', 415);
    if ($mime === 'image/svg+xml' && preg_match('/<script|on\w+\s*=|javascript:|<foreignObject/i', $data)) throw new ApiError('Bu SVG dosyası güvenli değil.', 415);
    $full = KK_UPLOADS . "/$bucket/$path";
    if (file_exists($full)) throw new ApiError('The resource already exists', 409, 'Duplicate');
    if (!is_dir(dirname($full)) && !mkdir(dirname($full), 0755, true)) throw new ApiError('Klasör oluşturulamadı.', 500);
    if (file_put_contents($full, $data, LOCK_EX) === false) throw new ApiError('Dosya kaydedilemedi.', 500);
    @chmod($full, 0644);
    return ['Key' => "$bucket/$path", 'path' => $path, 'id' => uuid4()];
}

function storage_remove(string $bucket, array $body): array {
    $c = ctx();
    if (!isset(BUCKETS[$bucket])) throw new ApiError('Bucket not found', 404);
    $out = [];
    foreach ((array)($body['prefixes'] ?? []) as $p) {
        $p = safe_path((string)$p);
        if (!storage_can($bucket, $p, $c)) continue; // yetkisizse sessizce atla (Supabase davranışı)
        $full = KK_UPLOADS . "/$bucket/$p";
        if (is_file($full) && unlink($full)) {
            $out[] = ['name' => $p, 'bucket_id' => $bucket];
            $dir = dirname($full); // boş kalan alt klasörü temizle
            if ($dir !== KK_UPLOADS . "/$bucket" && is_dir($dir) && count(scandir($dir)) === 2) @rmdir($dir);
        }
    }
    return $out;
}
