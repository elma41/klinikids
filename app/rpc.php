<?php
// Uzak fonksiyonlar (Supabase'teki SQL fonksiyonlarının PHP karşılıkları)
//   POST /api/rest/v1/rpc/{ad}
declare(strict_types=1);

function settings(): array {
    $s = q('SELECT * FROM clinic_settings WHERE id = 1')->fetch();
    return $s ?: ['timezone' => 'Europe/Istanbul', 'slot_step_minutes' => 15, 'min_notice_minutes' => 120, 'max_days_ahead' => 60, 'auto_confirm' => 0];
}

function rpc_handle(string $fn, array $a): void {
    $map = [
        'get_available_slots' => 'rpc_get_available_slots',
        'get_busy_blocks' => 'rpc_get_busy_blocks',
        'book_appointment' => 'rpc_book_appointment',
        'cancel_appointment_by_token' => 'rpc_cancel_by_token',
        'get_public_schedule' => 'rpc_public_schedule',
        'update_my_profile' => 'rpc_update_my_profile',
    ];
    if (!isset($map[$fn])) throw new ApiError("Fonksiyon bulunamadı: $fn", 404, 'PGRST202');
    $res = ($map[$fn])($a);
    mail_flush();
    json_out($res);
}

/** Bir günün boş saatleri (Europe/Istanbul) — sonuç: [{slot_start, slot_end}] UTC ISO */
function slots_for(string $doctorId, string $serviceId, string $date, ?array $s = null): array {
    $s = $s ?? settings();
    if (!is_uuid($doctorId) || !is_uuid($serviceId) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
    $tz = new DateTimeZone($s['timezone']);
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $max = (new DateTimeImmutable($today, $tz))->modify('+' . (int)$s['max_days_ahead'] . ' days')->format('Y-m-d');
    if ($date < $today || $date > $max) return [];
    $dur = q('SELECT sv.duration_minutes FROM services sv
                JOIN doctor_services ds ON ds.service_id = sv.id
                JOIN doctors d ON d.id = ds.doctor_id
               WHERE sv.id = ? AND d.id = ? AND sv.is_active = 1 AND d.is_active = 1', [$serviceId, $doctorId])->fetchColumn();
    if ($dur === false) return [];
    $dur = (int)$dur; $step = (int)$s['slot_step_minutes'];
    $dow = (int)(new DateTimeImmutable($date, $tz))->format('N');
    $hours = q('SELECT start_time, end_time FROM working_hours WHERE doctor_id = ? AND weekday = ? ORDER BY start_time', [$doctorId, $dow])->fetchAll();
    if (!$hours) return [];
    $dayStart = (new DateTimeImmutable("$date 00:00:00", $tz))->getTimestamp();
    $dayEnd = $dayStart + 86400 + 3600;
    $busy = q("SELECT starts_at, ends_at FROM appointments WHERE doctor_id = ? AND status IN ('pending','confirmed') AND starts_at < ? AND ends_at > ?
               UNION ALL SELECT starts_at, ends_at FROM time_off WHERE doctor_id = ? AND starts_at < ? AND ends_at > ?",
              [$doctorId, gmdate('Y-m-d H:i:s', $dayEnd), gmdate('Y-m-d H:i:s', $dayStart - 3600),
               $doctorId, gmdate('Y-m-d H:i:s', $dayEnd), gmdate('Y-m-d H:i:s', $dayStart - 3600)])->fetchAll();
    $busy = array_map(fn($b) => [strtotime($b['starts_at'] . ' UTC'), strtotime($b['ends_at'] . ' UTC')], $busy);
    $earliest = time() + (int)$s['min_notice_minutes'] * 60;
    $out = [];
    foreach ($hours as $h) {
        $st = (new DateTimeImmutable("$date {$h['start_time']}", $tz))->getTimestamp();
        $last = (new DateTimeImmutable("$date {$h['end_time']}", $tz))->getTimestamp() - $dur * 60;
        for ($t = $st; $t <= $last; $t += $step * 60) {
            $en = $t + $dur * 60;
            if ($t < $earliest) continue;
            foreach ($busy as [$bs, $be]) if ($bs < $en && $be > $t) continue 2;
            $out[] = ['slot_start' => gmdate('Y-m-d\TH:i:s', $t) . '+00:00', 'slot_end' => gmdate('Y-m-d\TH:i:s', $en) . '+00:00'];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['slot_start'], $b['slot_start']));
    return $out;
}

function rpc_get_available_slots(array $a): array {
    return slots_for((string)($a['p_doctor_id'] ?? ''), (string)($a['p_service_id'] ?? ''), (string)($a['p_date'] ?? ''));
}

function rpc_get_busy_blocks(array $a): array {
    $doc = (string)($a['p_doctor_id'] ?? '');
    if (!is_uuid($doc)) return [];
    $from = to_db_time($a['p_from'] ?? null); $to = to_db_time($a['p_to'] ?? null);
    if (!$from || !$to) return [];
    $cap = gmdate('Y-m-d H:i:s', strtotime("$from UTC") + 31 * 86400);
    if ($to > $cap) $to = $cap;
    $rows = q("SELECT starts_at b, ends_at e FROM appointments WHERE doctor_id = ? AND status IN ('pending','confirmed') AND starts_at < ? AND ends_at > ?
               UNION ALL SELECT starts_at, ends_at FROM time_off WHERE doctor_id = ? AND starts_at < ? AND ends_at > ? ORDER BY 1",
              [$doc, $to, $from, $doc, $to, $from])->fetchAll();
    return array_map(fn($r) => ['busy_start' => from_db_time($r['b']), 'busy_end' => from_db_time($r['e'])], $rows);
}

function rpc_book_appointment(array $a): array {
    if (empty($a['p_kvkk_consent'])) throw new ApiError('KVKK aydınlatma metnini onaylamanız gerekiyor.');
    $name = trim((string)($a['p_full_name'] ?? ''));
    if (mb_strlen($name) < 3) throw new ApiError('Lütfen adınızı ve soyadınızı girin.');
    if (mb_strlen($name) > 120) throw new ApiError('Ad soyad çok uzun.');
    $phone = preg_replace('/\D/', '', (string)($a['p_phone'] ?? ''));
    if (preg_match('/^90\d{10}$/', $phone)) $phone = substr($phone, 2);
    elseif (preg_match('/^0\d{10}$/', $phone)) $phone = substr($phone, 1);
    if (!preg_match('/^5\d{9}$/', $phone)) throw new ApiError('Lütfen geçerli bir cep telefonu numarası girin.');
    $email = trim((string)($a['p_email'] ?? ''));
    if ($email !== '' && !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) throw new ApiError('Lütfen geçerli bir e-posta adresi girin.');
    if (mb_strlen($email) > 200) throw new ApiError('E-posta adresi çok uzun.');
    $note = trim((string)($a['p_note'] ?? ''));
    if (mb_strlen($note) > 2000) $note = mb_substr($note, 0, 2000);
    $doc = (string)($a['p_doctor_id'] ?? ''); $svc = (string)($a['p_service_id'] ?? '');
    $startIso = (string)($a['p_starts_at'] ?? '');
    rate_limit('book', 8, 3600); // aynı IP'den saatte en fazla 8 randevu
    $s = settings();
    $startTs = strtotime($startIso);
    if ($startTs === false) throw new ApiError('Seçtiğiniz saat artık müsait değil, lütfen başka bir saat seçin.');
    $localDate = (new DateTimeImmutable('@' . $startTs))->setTimezone(new DateTimeZone($s['timezone']))->format('Y-m-d');

    $pdo = db(); $pdo->beginTransaction();
    try {
        if (!is_uuid($doc)) throw new ApiError('Seçtiğiniz saat artık müsait değil, lütfen başka bir saat seçin.');
        q('SELECT id FROM doctors WHERE id = ? FOR UPDATE', [$doc]);
        $ok = false;
        foreach (slots_for($doc, $svc, $localDate, $s) as $sl) if (strtotime($sl['slot_start']) === $startTs) { $ok = true; $endTs = strtotime($sl['slot_end']); break; }
        if (!$ok) throw new ApiError('Seçtiğiniz saat artık müsait değil, lütfen başka bir saat seçin.');
        // Hasta: aynı telefon varsa mevcut kaydı kullan (isim üzerine yazılmaz)
        $pid = q('SELECT id FROM patients WHERE phone = ? FOR UPDATE', [$phone])->fetchColumn();
        if ($pid) q('UPDATE patients SET kvkk_consent_at = ?, email = COALESCE(?, email) WHERE id = ?', [now_db(), $email ?: null, $pid]);
        else { $pid = uuid4(); q('INSERT INTO patients (id, full_name, phone, email, kvkk_consent_at) VALUES (?, ?, ?, ?, ?)', [$pid, $name, $phone, $email ?: null, now_db()]); }
        $row = ['id' => uuid4(), 'doctor_id' => $doc, 'service_id' => $svc, 'patient_id' => $pid,
                'starts_at' => gmdate('Y-m-d H:i:s', $startTs), 'ends_at' => gmdate('Y-m-d H:i:s', $endTs),
                'status' => $s['auto_confirm'] ? 'confirmed' : 'pending', 'source' => 'online',
                'patient_note' => $note !== '' ? $note : null, 'cancel_token' => uuid4()];
        try { $row = appt_guard($row, null); }
        catch (ApiError $e) { if ($e->pgCode === '23P01') throw new ApiError('Seçtiğiniz saat az önce doldu, lütfen başka bir saat seçin.', 409); throw $e; }
        $cols = array_keys($row);
        q('INSERT INTO appointments (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); if ($e instanceof PDOException) throw map_db_error($e); throw $e; }
    appt_after_write(null, $row, new Ctx());
    return [['appointment_id' => $row['id'], 'cancel_token' => $row['cancel_token'], 'status' => $row['status']]];
}

function rpc_cancel_by_token(array $a): bool {
    $tok = (string)($a['p_token'] ?? '');
    if (!is_uuid($tok)) return false;
    rate_limit('cancel', 20, 3600);
    $s = settings();
    $row = q("SELECT * FROM appointments WHERE cancel_token = ? AND status IN ('pending','confirmed') AND starts_at > ?",
             [$tok, gmdate('Y-m-d H:i:s', time() + (int)$s['min_notice_minutes'] * 60)])->fetch();
    if (!$row) return false;
    q("UPDATE appointments SET status = 'cancelled' WHERE id = ?", [$row['id']]);
    appt_after_write($row, array_merge($row, ['status' => 'cancelled']), new Ctx());
    return true;
}

function rpc_public_schedule(array $a): array {
    $s = settings();
    $hours = q('SELECT wh.doctor_id d, wh.weekday w, wh.start_time s, wh.end_time e FROM working_hours wh JOIN doctors d ON d.id = wh.doctor_id
                 WHERE d.is_active = 1 ORDER BY wh.weekday, wh.start_time')->fetchAll();
    $off = q('SELECT t.doctor_id d, t.starts_at s, t.ends_at e, t.reason r FROM time_off t JOIN doctors d ON d.id = t.doctor_id
               WHERE d.is_active = 1 AND t.ends_at > ? AND t.starts_at < ? ORDER BY t.starts_at',
             [now_db(), gmdate('Y-m-d H:i:s', time() + 60 * 86400)])->fetchAll();
    return [
        'tz' => $s['timezone'],
        'hours' => array_map(fn($h) => ['d' => $h['d'], 'w' => (int)$h['w'], 's' => substr($h['s'], 0, 5), 'e' => substr($h['e'], 0, 5)], $hours),
        'off' => array_map(fn($o) => ['d' => $o['d'], 's' => from_db_time($o['s']), 'e' => from_db_time($o['e']), 'r' => $o['r']], $off),
    ];
}

function rpc_update_my_profile(array $a): ?array {
    $c = ctx();
    if (!$c->doctorId) throw new ApiError('Hesabınız bir doktor kaydına bağlı değil.');
    $prof = $a['p_profile'] ?? [];
    if (!is_array($prof)) $prof = [];
    $photo = trim((string)($a['p_photo_url'] ?? ''));
    q('UPDATE doctors SET profile = ?, photo_url = ? WHERE id = ?',
      [json_encode($prof ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $photo !== '' ? $photo : null, $c->doctorId]);
    return null;
}
