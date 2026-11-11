<?php
// Oturum ve kullanıcı yönetimi (Supabase Auth'un yerine)
declare(strict_types=1);

const KK_COOKIE = 'kk_session';
const KK_SESSION_DAYS = 14;

final class Ctx {
    public ?string $uid = null; public ?string $email = null; public ?string $role = null;
    public ?string $doctorId = null; public bool $mustChange = false;
    public function isStaff(): bool { return $this->role !== null; }
    public function is(string ...$roles): bool { return $this->role !== null && in_array($this->role, $roles, true); }
}

function ctx(): Ctx {
    static $c = null;
    if ($c) return $c;
    $c = new Ctx();
    $tok = $_COOKIE[KK_COOKIE] ?? '';
    if (!is_string($tok) || strlen($tok) < 40) return $c;
    $h = hash('sha256', $tok);
    $row = q('SELECT s.user_id, s.last_seen, u.email, u.must_change_password, sp.role, sp.doctor_id
                FROM sessions s JOIN users u ON u.id = s.user_id
                LEFT JOIN staff_profiles sp ON sp.user_id = u.id
               WHERE s.token_hash = ? AND s.expires_at > ?', [$h, now_db()])->fetch();
    if (!$row) return $c;
    $c->uid = $row['user_id']; $c->email = $row['email']; $c->role = $row['role'];
    $c->doctorId = $row['doctor_id']; $c->mustChange = (bool)$row['must_change_password'];
    // Kayan süre: en fazla saatte bir uzat
    if (strtotime($row['last_seen'] . ' UTC') < time() - 3600) {
        q('UPDATE sessions SET last_seen = ?, expires_at = ? WHERE token_hash = ?',
          [now_db(), gmdate('Y-m-d H:i:s', time() + KK_SESSION_DAYS * 86400), $h]);
        set_session_cookie($tok);
    }
    return $c;
}

function set_session_cookie(string $tok, int $ttl = KK_SESSION_DAYS * 86400): void {
    $secure = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(KK_COOKIE, $tok, ['expires' => $ttl > 0 ? time() + $ttl : time() - 3600, 'path' => '/',
        'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
}

function user_json(string $uid): ?array {
    $u = q('SELECT id, email, must_change_password, last_sign_in_at, created_at FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u) return null;
    return ['id' => $u['id'], 'email' => $u['email'], 'last_sign_in_at' => from_db_time($u['last_sign_in_at']),
            'created_at' => from_db_time($u['created_at']),
            'user_metadata' => ['must_change_password' => (bool)$u['must_change_password']]];
}

function auth_login(array $b): array {
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $pass = (string)($b['password'] ?? '');
    rate_limit('login', 10, 900);
    $u = q('SELECT id, password_hash FROM users WHERE email = ?', [$email])->fetch();
    // Kullanıcı olmasa bile aynı sürede yanıt vermek için sahte doğrulama
    $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltsGJt3E1fSBo0n0oM9Bs5a6k0p0p1nW';
    $ok = password_verify($pass, $hash);
    if (!$u || !$ok) throw new ApiError('Invalid login credentials', 400, 'invalid_credentials');
    // Supabase'ten gelen $2a$ özetlerini güncel biçime yükselt
    if (password_needs_rehash($hash, PASSWORD_BCRYPT)) q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_BCRYPT), $u['id']]);
    $tok = bin2hex(random_bytes(32));
    q('INSERT INTO sessions (token_hash, user_id, expires_at, ip) VALUES (?, ?, ?, ?)',
      [hash('sha256', $tok), $u['id'], gmdate('Y-m-d H:i:s', time() + KK_SESSION_DAYS * 86400), client_ip()]);
    q('UPDATE users SET last_sign_in_at = ? WHERE id = ?', [now_db(), $u['id']]);
    q('DELETE FROM sessions WHERE expires_at < ?', [now_db()]);
    set_session_cookie($tok);
    return ['user' => user_json($u['id'])];
}

function auth_logout(): array {
    $tok = $_COOKIE[KK_COOKIE] ?? '';
    if (is_string($tok) && $tok !== '') q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $tok)]);
    set_session_cookie('', 0);
    return ['ok' => true];
}

function auth_session(): array {
    $c = ctx();
    return ['user' => $c->uid ? user_json($c->uid) : null];
}

function auth_update(array $b): array {
    $c = ctx();
    if (!$c->uid) throw new ApiError('Oturumunuz bulunamadı. Lütfen yeniden giriş yapın.', 401);
    if (isset($b['password'])) {
        $p = (string)$b['password'];
        if (mb_strlen($p) < 8) throw new ApiError('Şifre en az 8 karakter olmalı.', 422, 'weak_password');
        $must = !empty($b['data']['must_change_password']) ? 1 : 0;
        q('UPDATE users SET password_hash = ?, must_change_password = ? WHERE id = ?', [password_hash($p, PASSWORD_BCRYPT), $must, $c->uid]);
        // Diğer cihazlardaki oturumları kapat, bu oturum kalsın
        $cur = hash('sha256', (string)($_COOKIE[KK_COOKIE] ?? ''));
        q('DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?', [$c->uid, $cur]);
    } elseif (isset($b['data']['must_change_password'])) {
        q('UPDATE users SET must_change_password = ? WHERE id = ?', [$b['data']['must_change_password'] ? 1 : 0, $c->uid]);
    }
    return ['user' => user_json($c->uid)];
}

function require_admin(): Ctx {
    $c = ctx();
    if (!$c->uid) throw new ApiError('Oturumunuz bulunamadı. Lütfen yeniden giriş yapın.', 401);
    if ($c->role !== 'admin') throw new ApiError('Bu işlem için yönetici yetkisi gerekir.', 403);
    return $c;
}

/** Okunması kolay geçici şifre (karışan harfler yok): ör. Kk-7m4P-q9Xt */
function temp_password(): string {
    $A = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = ''; for ($i = 0; $i < 8; $i++) $s .= $A[random_int(0, strlen($A) - 1)];
    return 'Kk-' . substr($s, 0, 4) . '-' . substr($s, 4);
}

/** Personel hesapları (eski "personel" Edge Function'ının karşılığı) */
function fn_personel(array $b): array {
    $me = require_admin();
    $action = $b['action'] ?? '';
    if ($action === 'emails') {
        $map = [];
        foreach (q('SELECT id, email, last_sign_in_at, must_change_password FROM users')->fetchAll() as $u)
            $map[$u['id']] = ['email' => $u['email'], 'last' => from_db_time($u['last_sign_in_at']), 'pending' => (bool)$u['must_change_password']];
        return ['users' => $map];
    }
    if ($action === 'create') {
        $email = strtolower(trim((string)($b['email'] ?? '')));
        $name = trim((string)($b['full_name'] ?? ''));
        $role = (string)($b['role'] ?? '');
        $doc = $role === 'doctor' ? ($b['doctor_id'] ?? null) : null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('Geçerli bir e-posta adresi girin.');
        if (mb_strlen($name) < 2) throw new ApiError('Ad soyad girin.');
        if (!in_array($role, ['admin', 'receptionist', 'doctor'], true)) throw new ApiError('Rol seçin.');
        if ($role === 'doctor' && !is_uuid($doc)) throw new ApiError('Doktor hesabı için bağlı doktoru seçin.');
        if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) throw new ApiError('Bu e-posta adresiyle zaten bir hesap var.');
        $pw = temp_password(); $id = uuid4();
        db()->beginTransaction();
        try {
            q('INSERT INTO users (id, email, password_hash, must_change_password) VALUES (?, ?, ?, 1)', [$id, $email, password_hash($pw, PASSWORD_BCRYPT)]);
            q('INSERT INTO staff_profiles (user_id, full_name, role, doctor_id) VALUES (?, ?, ?, ?)', [$id, $name, $role, $doc]);
            db()->commit();
        } catch (Throwable $e) { db()->rollBack(); throw $e; }
        return ['ok' => true, 'email' => $email, 'password' => $pw];
    }
    if ($action === 'reset') {
        $u = q('SELECT id, email FROM users WHERE id = ?', [(string)($b['user_id'] ?? '')])->fetch();
        if (!$u) throw new ApiError('Hesap bulunamadı.', 404);
        $pw = temp_password();
        q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pw, PASSWORD_BCRYPT), $u['id']]);
        q('DELETE FROM sessions WHERE user_id = ?', [$u['id']]);
        return ['ok' => true, 'email' => $u['email'], 'password' => $pw];
    }
    if ($action === 'delete') {
        $uid = (string)($b['user_id'] ?? '');
        if ($uid === $me->uid) throw new ApiError('Kendi hesabınızı silemezsiniz.');
        q('DELETE FROM users WHERE id = ?', [$uid]); // staff_profiles ve oturumlar kendiliğinden silinir
        return ['ok' => true];
    }
    throw new ApiError('Bilinmeyen işlem.');
}
