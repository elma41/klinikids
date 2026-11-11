<?php
// =====================================================================
// Klinikids — arka uç çekirdeği
// Bu klasör (app/) web kökünün DIŞINDADIR; tarayıcıdan erişilemez.
// Ayarlar: app/config.php (git'e girmez, kurulum sayfası oluşturur).
// =====================================================================
declare(strict_types=1);

const KK_APP = __DIR__;
const KK_CONFIG = __DIR__ . '/config.php';
define('KK_UPLOADS', realpath(__DIR__ . '/../public_html') . '/uploads');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

final class ApiError extends Exception {
    public string $pgCode; public int $http;
    public function __construct(string $msg, int $http = 400, string $pgCode = '') {
        parent::__construct($msg); $this->http = $http; $this->pgCode = $pgCode;
    }
}

function kk_config(bool $reload = false): array {
    static $c = null;
    if ($c === null || $reload) $c = is_file(KK_CONFIG) ? (require KK_CONFIG) : [];
    return $c;
}
function kk_installed(): bool { return !empty(kk_config()['db']); }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = kk_config()['db'] ?? null;
    if (!$c) throw new ApiError('Sistem henüz kurulmadı.', 503);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql); $st->execute(array_values($params)); return $st;
}

function uuid4(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function is_uuid($v): bool { return is_string($v) && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v); }

/** ISO 8601 (ofsetli) → 'Y-m-d H:i:s' UTC */
function to_db_time($v): ?string {
    if ($v === null || $v === '') return null;
    try { $d = new DateTimeImmutable((string)$v); } catch (Exception $e) { throw new ApiError('Geçersiz tarih: ' . $v); }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
/** 'Y-m-d H:i:s' UTC → '2026-11-16T06:00:00+00:00' */
function from_db_time(?string $v): ?string {
    if ($v === null) return null;
    return str_replace(' ', 'T', substr($v, 0, 19)) . '+00:00';
}
function now_db(): string { return gmdate('Y-m-d H:i:s'); }

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/** Basit hız sınırı: $bucket için $window saniyede en fazla $max istek */
function rate_limit(string $bucket, int $max, int $window): void {
    $ip = client_ip();
    q('DELETE FROM rate_limits WHERE at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    $n = (int)q('SELECT COUNT(*) FROM rate_limits WHERE bucket=? AND ip=? AND at > ?', [$bucket, $ip, gmdate('Y-m-d H:i:s', time() - $window)])->fetchColumn();
    if ($n >= $max) throw new ApiError('Çok fazla deneme yapıldı. Lütfen biraz sonra tekrar deneyin.', 429);
    q('INSERT INTO rate_limits (bucket, ip) VALUES (?, ?)', [$bucket, $ip]);
}

function json_out($data, int $status = 200, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $k => $v) header("$k: $v");
    if ($status === 204) return;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function body_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    if (!is_array($j)) throw new ApiError('Geçersiz istek gövdesi.');
    return $j;
}

/** MySQL hatalarını panelin tanıdığı Postgres kodlarına çevirir */
function map_db_error(PDOException $e): ApiError {
    $code = (int)($e->errorInfo[1] ?? 0);
    $msg = $e->errorInfo[2] ?? $e->getMessage();
    if ($code === 1062) return new ApiError('duplicate key value violates unique constraint', 409, '23505');
    if (in_array($code, [1451, 1452], true)) return new ApiError('violates foreign key constraint', 409, '23503');
    if (in_array($code, [3819, 4025], true)) return new ApiError('Girilen değerlerden biri geçerli değil.', 400, '23514');
    if ($code === 1048) return new ApiError('Zorunlu bir alan boş bırakıldı.', 400, '23502');
    error_log('KK DB: ' . $msg);
    return new ApiError('Veritabanı hatası.', 500);
}
