<?php
// Klinikids API — tek giriş noktası. Asıl kod web kökünün dışındaki app/ klasöründe.
declare(strict_types=1);

$app = dirname(__DIR__, 2) . '/app';
foreach (['bootstrap', 'tables', 'auth', 'policy', 'mail', 'rest', 'rpc', 'storage', 'setup', 'migrate'] as $f) require $app . "/$f.php";

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^.*?/api(?=/|$)#', '', $path, 1);
$path = '/' . trim(rawurldecode($path), '/');

try {
    // Kurulum sayfası (HTML)
    if (preg_match('#^/kurulum(?:/(eposta))?$#', $path, $m)) { setup_handle($m[1] ?? ''); exit; }
    if ($path === '/health') { json_out(['ok' => true, 'installed' => kk_installed()]); exit; }

    if (!kk_installed()) throw new ApiError('Sistem henüz kurulmadı.', 503);

    // Değişiklik yapan isteklerde başka sitelerden gelen çağrıları engelle (CSRF)
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (($_SERVER['HTTP_X_KK'] ?? '') !== '1') throw new ApiError('Geçersiz istek.', 403);
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', $host)) throw new ApiError('Geçersiz istek kaynağı.', 403);
    }

    if (preg_match('#^/rest/v1/rpc/([a-z_]+)$#', $path, $m)) {
        if ($method !== 'POST') throw new ApiError('Yalnızca POST.', 405);
        rpc_handle($m[1], body_json()); exit;
    }
    if (preg_match('#^/rest/v1/([a-z_]+)$#', $path, $m)) {
        rest_handle($method, $m[1], parse_qs($_SERVER['QUERY_STRING'] ?? '')); exit;
    }
    if (preg_match('#^/auth/(login|logout|session|update)$#', $path, $m)) {
        $fn = 'auth_' . $m[1];
        if ($m[1] !== 'session' && $method !== 'POST') throw new ApiError('Yalnızca POST.', 405);
        json_out($fn($m[1] === 'session' || $m[1] === 'logout' ? [] : body_json())); exit;
    }
    if ($path === '/functions/v1/personel' && $method === 'POST') { json_out(fn_personel(body_json())); exit; }
    if ($path === '/functions/v1/dis-kaynak' && $method === 'POST') { json_out(fn_dis_kaynak(body_json())); exit; }
    if (preg_match('#^/storage/v1/object/([a-z-]+)(?:/(.+))?$#', $path, $m)) {
        if ($method === 'POST' && !empty($m[2])) { json_out(storage_upload($m[1], $m[2])); exit; }
        if ($method === 'DELETE' && empty($m[2])) { json_out(storage_remove($m[1], body_json())); exit; }
    }
    throw new ApiError('Bulunamadı.', 404);
} catch (ApiError $e) {
    json_out(['message' => $e->getMessage(), 'code' => $e->pgCode ?: (string)$e->http, 'error' => $e->getMessage()], $e->http);
} catch (PDOException $e) {
    $x = map_db_error($e);
    json_out(['message' => $x->getMessage(), 'code' => $x->pgCode, 'error' => $x->getMessage()], $x->http);
} catch (Throwable $e) {
    error_log('KK API: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['message' => 'Sunucu hatası. Lütfen tekrar deneyin.', 'code' => '500', 'error' => 'Sunucu hatası.'], 500);
}
