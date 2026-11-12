<?php
// Tablo tanımları: sütun türleri, varsayılanlar ve ilişkiler (gömülü seçimler için).
// Türler: uuid, text, int, bool, json, ts (UTC zaman), time, enum
declare(strict_types=1);

const T = [
  'clinic_settings' => ['pk' => 'id', 'cols' => [
      'id' => 'bool', 'clinic_name' => 'text', 'timezone' => 'text', 'slot_step_minutes' => 'int',
      'min_notice_minutes' => 'int', 'max_days_ahead' => 'int', 'auto_confirm' => 'bool']],
  'clinic_hours' => ['pk' => 'weekday', 'cols' => [
      'weekday' => 'int', 'is_open' => 'bool', 'open_time' => 'time', 'close_time' => 'time']],
  'clinic_closures' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'starts_on' => 'text', 'ends_on' => 'text', 'reason' => 'text', 'created_at' => 'ts']],
  'doctors' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'full_name' => 'text', 'title' => 'text', 'specialty' => 'text', 'bio' => 'text',
      'photo_url' => 'text', 'color' => 'text', 'is_active' => 'bool', 'sort_order' => 'int',
      'created_at' => 'ts', 'clinic' => 'text', 'profile' => 'json'],
      'defaults' => ['profile' => '{}']],
  'services' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'name' => 'text', 'description' => 'text', 'duration_minutes' => 'int',
      'is_active' => 'bool', 'sort_order' => 'int', 'clinic' => 'text']],
  'doctor_services' => ['pk' => null, 'cols' => ['doctor_id' => 'uuid', 'service_id' => 'uuid'],
      'rel' => ['doctors' => 'doctor_id', 'services' => 'service_id']],
  'staff_profiles' => ['pk' => 'user_id', 'cols' => [
      'user_id' => 'uuid', 'full_name' => 'text', 'role' => 'text', 'doctor_id' => 'uuid'],
      'rel' => ['doctors' => 'doctor_id']],
  'working_hours' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'doctor_id' => 'uuid', 'weekday' => 'int', 'start_time' => 'time', 'end_time' => 'time'],
      'rel' => ['doctors' => 'doctor_id']],
  'time_off' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'doctor_id' => 'uuid', 'starts_at' => 'ts', 'ends_at' => 'ts', 'reason' => 'text', 'created_at' => 'ts'],
      'rel' => ['doctors' => 'doctor_id']],
  'patients' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'full_name' => 'text', 'phone' => 'text', 'email' => 'text',
      'kvkk_consent_at' => 'ts', 'notes' => 'text', 'created_at' => 'ts']],
  'appointments' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'doctor_id' => 'uuid', 'service_id' => 'uuid', 'custom_service_name' => 'text',
      'patient_id' => 'uuid', 'starts_at' => 'ts', 'ends_at' => 'ts', 'status' => 'text', 'source' => 'text',
      'patient_note' => 'text', 'staff_note' => 'text', 'cancel_token' => 'uuid', 'created_at' => 'ts'],
      'rel' => ['patients' => 'patient_id', 'doctors' => 'doctor_id', 'services' => 'service_id'],
      'auto' => ['cancel_token']],
  'institutions' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'name' => 'text', 'logo_path' => 'text', 'sort_order' => 'int', 'is_active' => 'bool', 'created_at' => 'ts']],
  'myths' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'clinic' => 'text', 'emoji' => 'text', 'myth' => 'text', 'truth' => 'text',
      'sort_order' => 'int', 'is_active' => 'bool', 'created_at' => 'ts']],
  'doctor_certificates' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'doctor_id' => 'uuid', 'title' => 'text', 'issuer' => 'text', 'year' => 'int',
      'file_path' => 'text', 'file_type' => 'text', 'sort_order' => 'int', 'is_active' => 'bool', 'created_at' => 'ts'],
      'rel' => ['doctors' => 'doctor_id']],
  'faqs' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'clinic' => 'text', 'question' => 'text', 'answer' => 'text',
      'sort_order' => 'int', 'is_active' => 'bool', 'created_at' => 'ts']],
  'gallery' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'clinic' => 'text', 'url' => 'text', 'file_path' => 'text', 'caption' => 'text',
      'sort_order' => 'int', 'is_active' => 'bool', 'created_at' => 'ts']],
  'legal_sections' => ['pk' => 'id', 'cols' => [
      'id' => 'uuid', 'slug' => 'text', 'title' => 'text', 'body' => 'text',
      'sort_order' => 'int', 'is_active' => 'bool', 'updated_at' => 'ts'],
      'defaults' => ['body' => '']],
];

function tdef(string $t): array {
    if (!isset(T[$t])) throw new ApiError("Bilinmeyen tablo: $t", 404, '42P01');
    return T[$t];
}
function col_type(string $t, string $c): string {
    $d = tdef($t);
    if (!isset($d['cols'][$c])) throw new ApiError("Bilinmeyen sütun: $t.$c", 400, '42703');
    return $d['cols'][$c];
}

/** PHP/JSON değerini veritabanı değerine çevirir */
function to_db_val(string $type, $v) {
    if ($v === null) return null;
    switch ($type) {
        case 'bool': return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 't') ? 1 : 0;
        case 'int': if (!is_numeric($v)) throw new ApiError('Sayı bekleniyordu.', 400, '22P02'); return (int)$v;
        case 'json': return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        case 'ts': return to_db_time($v);
        case 'uuid': if (!is_uuid($v)) throw new ApiError('Geçersiz kimlik değeri.', 400, '22P02'); return strtolower((string)$v);
        case 'time':
            if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', (string)$v, $m)) throw new ApiError('Geçersiz saat.', 400, '22007');
            return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
        default: return is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
/** Veritabanı satırını JSON çıktısına çevirir */
function from_db_row(string $t, array $row): array {
    $cols = tdef($t)['cols'];
    foreach ($row as $k => $v) {
        if ($v === null || !isset($cols[$k])) continue;
        switch ($cols[$k]) {
            case 'bool': $row[$k] = (bool)(int)$v; break;
            case 'int': $row[$k] = (int)$v; break;
            case 'json':
                $j = json_decode((string)$v, true);
                $row[$k] = ($j === null || ($j === [] && str_starts_with(ltrim((string)$v), '{'))) ? new stdClass() : $j;
                break;
            case 'ts': $row[$k] = from_db_time((string)$v); break;
            case 'time': $row[$k] = substr((string)$v, 0, 8); break;
        }
    }
    return $row;
}
