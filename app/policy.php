<?php
// Yetki kuralları — Supabase'teki satır düzeyi güvenlik (RLS) kurallarının birebir karşılığı.
// Dönüş: null = tüm satırlar, [sql, params] = koşullu (tablo takma adı "t"), false = hiçbiri
declare(strict_types=1);

const PUBLIC_ACTIVE = ['doctors', 'services', 'faqs', 'gallery', 'institutions', 'legal_sections', 'myths', 'doctor_certificates'];
const ADMIN_WRITE  = ['doctors', 'services', 'doctor_services', 'faqs', 'gallery', 'institutions', 'legal_sections', 'myths', 'staff_profiles'];

function policy_read(string $t, Ctx $c) {
    switch ($t) {
        case 'clinic_settings': case 'doctor_services': case 'working_hours': return null;
        case 'time_off':
            if ($c->is('admin', 'receptionist')) return null;
            if ($c->role === 'doctor' && $c->doctorId) return ['t.doctor_id = ?', [$c->doctorId]];
            return false;
        case 'patients':
            if ($c->is('admin', 'receptionist')) return null;
            if ($c->role === 'doctor' && $c->doctorId)
                return ['EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = t.id AND a.doctor_id = ?)', [$c->doctorId]];
            return false;
        case 'appointments':
            if ($c->is('admin', 'receptionist')) return null;
            if ($c->role === 'doctor' && $c->doctorId) return ['t.doctor_id = ?', [$c->doctorId]];
            return false;
        case 'staff_profiles':
            if ($c->is('admin')) return null;
            if ($c->uid) return ['t.user_id = ?', [$c->uid]];
            return false;
    }
    if (in_array($t, PUBLIC_ACTIVE, true)) return $c->isStaff() ? null : ['t.is_active = 1', []];
    return false;
}

/** Güncelleme/silme için hangi satırlara dokunulabilir (USING) */
function policy_write(string $t, string $op, Ctx $c) {
    if (in_array($t, ADMIN_WRITE, true)) return $c->is('admin') ? null : false;
    switch ($t) {
        case 'clinic_settings': return ($op === 'update' && $c->is('admin')) ? null : false;
        case 'doctor_certificates':
            if ($c->is('admin')) return null;
            return ($c->role === 'doctor' && $c->doctorId) ? ['t.doctor_id = ?', [$c->doctorId]] : false;
        case 'working_hours': case 'time_off':
            if ($c->is('admin', 'receptionist')) return null;
            return ($c->role === 'doctor' && $c->doctorId) ? ['t.doctor_id = ?', [$c->doctorId]] : false;
        case 'patients': return $c->is('admin', 'receptionist') ? null : false;
        case 'appointments':
            if ($c->is('admin', 'receptionist')) return null;
            return ($op === 'update' && $c->role === 'doctor' && $c->doctorId) ? ['t.doctor_id = ?', [$c->doctorId]] : false;
    }
    return false;
}

/** Yeni/değişen satır kurala uyuyor mu (WITH CHECK) */
function policy_check(string $t, string $op, array $row, Ctx $c): bool {
    $w = policy_write($t, $op, $c);
    if ($w === false) return false;
    if ($w === null) return true;
    // Doktor kapsamlı kurallar: satır doktorun kendisine ait olmalı
    return isset($row['doctor_id']) && $row['doctor_id'] === $c->doctorId;
}

function rls_denied(string $t): ApiError {
    return new ApiError("new row violates row-level security policy for table \"$t\"", 403, '42501');
}
