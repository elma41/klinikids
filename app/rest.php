<?php
// Sorgu motoru — Supabase/PostgREST adres biçimini anlar, böylece site ve panel
// kodu neredeyse hiç değişmeden çalışır.
//   GET    /api/rest/v1/doctors?select=id,full_name&is_active=eq.true&order=sort_order
//   POST   /api/rest/v1/faqs            (ekle)      PATCH  /api/rest/v1/faqs?id=eq.X  (güncelle)
//   DELETE /api/rest/v1/faqs?id=eq.X    (sil)
// Seçimde ilişkili tablo gömme desteklenir: select=*,patients(full_name,phone)
declare(strict_types=1);

const RESERVED = ['select', 'order', 'limit', 'offset', 'count', 'head', 'on_conflict', 'columns'];
const MAX_ROWS = 5000;

/** QUERY_STRING'i tekrar eden anahtarları koruyarak çözer (PHP'nin $_GET'i son değeri tutar) */
function parse_qs(string $qs): array {
    $out = [];
    foreach (explode('&', $qs) as $p) {
        if ($p === '') continue;
        [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
        $out[] = [rawurldecode(str_replace('+', '%20', $k)), rawurldecode(str_replace('+', '%20', $v))];
    }
    return $out;
}
function qs_get(array $qs, string $k): ?string { foreach ($qs as [$a, $b]) if ($a === $k) return $b; return null; }

/** "a,b(c,d),e" → ['a','b(c,d)','e'] (parantez içini bölmeden) */
function split_top(string $s): array {
    $out = []; $buf = ''; $depth = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $ch = $s[$i];
        if ($ch === '(') $depth++; elseif ($ch === ')') $depth--;
        if ($ch === ',' && $depth === 0) { $out[] = trim($buf); $buf = ''; } else $buf .= $ch;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

/** select ifadesini çöz: ['cols' => [...], 'embeds' => [rel => select-string]] */
function parse_select(string $t, ?string $sel): array {
    $def = tdef($t); $cols = []; $embeds = [];
    foreach (split_top($sel === null || $sel === '' ? '*' : $sel) as $item) {
        if ($item === '*') { $cols = array_merge($cols, array_keys($def['cols'])); continue; }
        if (preg_match('/^([a-z_]+)(?:!\w+)?\((.*)\)$/s', $item, $m)) {
            if (!isset($def['rel'][$m[1]])) throw new ApiError("İlişki bulunamadı: $t → {$m[1]}", 400, 'PGRST200');
            $embeds[$m[1]] = $m[2]; continue;
        }
        col_type($t, $item); $cols[] = $item;
    }
    return ['cols' => array_values(array_unique($cols)), 'embeds' => $embeds];
}

/** Süzgeç değerini çöz: "eq.5" → ['=', 5] */
function build_filter(string $t, string $col, string $expr, array &$params): string {
    $type = col_type($t, $col);
    $neg = false;
    if (str_starts_with($expr, 'not.')) { $neg = true; $expr = substr($expr, 4); }
    $dot = strpos($expr, '.');
    if ($dot === false) throw new ApiError("Geçersiz süzgeç: $col=$expr", 400, 'PGRST100');
    $op = substr($expr, 0, $dot); $val = substr($expr, $dot + 1);
    $c = "t.`$col`";
    $conv = function ($v) use ($type) {
        if ($type === 'bool') return in_array(strtolower($v), ['true', 't', '1'], true) ? 1 : 0;
        return to_db_val($type, $v);
    };
    switch ($op) {
        case 'eq': case 'neq': case 'gt': case 'gte': case 'lt': case 'lte':
            $sym = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op];
            $params[] = $conv($val); $sql = "$c $sym ?"; break;
        case 'like': case 'ilike':
            $params[] = str_replace('*', '%', $val);
            $sql = $op === 'like' ? "$c LIKE BINARY ?" : "$c LIKE ?"; break;
        case 'in':
            if (!preg_match('/^\((.*)\)$/s', $val, $m)) throw new ApiError('Geçersiz in() süzgeci.', 400, 'PGRST100');
            $items = str_getcsv($m[1], ',', '"', '\\');
            if (!$items || $items === [null]) { $sql = '1=0'; break; }
            foreach ($items as $it) $params[] = $conv(trim((string)$it));
            $sql = "$c IN (" . implode(',', array_fill(0, count($items), '?')) . ')'; break;
        case 'is':
            $v = strtolower($val);
            $sql = $v === 'null' ? "$c IS NULL" : ($v === 'true' ? "$c = 1" : ($v === 'false' ? "$c = 0" : null));
            if ($sql === null) throw new ApiError('Geçersiz is süzgeci.', 400, 'PGRST100');
            break;
        default: throw new ApiError("Desteklenmeyen süzgeç: $op", 400, 'PGRST100');
    }
    return $neg ? "NOT ($sql)" : $sql;
}

/** WHERE parçası: yetki kuralı + adres süzgeçleri */
function build_where(string $t, array $qs, $policy): array {
    $parts = []; $params = [];
    if ($policy === false) return ['1=0', []];
    if (is_array($policy)) { $parts[] = '(' . $policy[0] . ')'; array_push($params, ...$policy[1]); }
    foreach ($qs as [$k, $v]) {
        if (in_array($k, RESERVED, true)) continue;
        $parts[] = build_filter($t, $k, $v, $params);
    }
    return [$parts ? implode(' AND ', $parts) : '1=1', $params];
}

function build_order(string $t, ?string $order): string {
    if (!$order) return '';
    $out = [];
    foreach (explode(',', $order) as $o) {
        $bits = explode('.', trim($o)); $col = array_shift($bits);
        col_type($t, $col);
        $dir = in_array('desc', $bits, true) ? 'DESC' : 'ASC';
        $nulls = in_array('nullsfirst', $bits, true) ? 'NULLSFIRST' : (in_array('nullslast', $bits, true) ? 'NULLSLAST' : '');
        if ($nulls === 'NULLSFIRST') $out[] = "t.`$col` IS NULL DESC";
        elseif ($nulls === 'NULLSLAST') $out[] = "t.`$col` IS NULL ASC";
        $out[] = "t.`$col` $dir";
    }
    return ' ORDER BY ' . implode(', ', $out);
}

/** Satırları seç + ilişkili kayıtları göm */
function select_rows(string $t, ?string $sel, string $where, array $params, string $order = '', ?int $limit = null, int $offset = 0, ?Ctx $c = null): array {
    $c = $c ?? ctx();
    $ps = parse_select($t, $sel);
    $def = tdef($t);
    $need = $ps['cols'];
    foreach ($ps['embeds'] as $rel => $_) $need[] = $def['rel'][$rel];
    $need = array_values(array_unique($need));
    if (!$need) $need = array_keys($def['cols']);
    $sql = 'SELECT ' . implode(',', array_map(fn($x) => "t.`$x`", $need)) . " FROM `$t` t WHERE $where$order";
    if ($limit !== null) $sql .= ' LIMIT ' . max(0, min($limit, MAX_ROWS)) . ' OFFSET ' . max(0, $offset);
    elseif ($offset) $sql .= ' LIMIT ' . MAX_ROWS . ' OFFSET ' . max(0, $offset);
    $rows = array_map(fn($r) => from_db_row($t, $r), q($sql, $params)->fetchAll());

    foreach ($ps['embeds'] as $rel => $subSel) {
        $fk = $def['rel'][$rel];
        $ids = array_values(array_unique(array_filter(array_column($rows, $fk))));
        $map = [];
        if ($ids) {
            $pol = policy_read($rel, $c);
            $relPk = tdef($rel)['pk'];
            $sub = parse_select($rel, $subSel);
            $cols = array_values(array_unique(array_merge($sub['cols'], [$relPk])));
            $p = $ids; $w = "t.`$relPk` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
            if ($pol === false) $w = '1=0';
            elseif (is_array($pol)) { $w .= ' AND (' . $pol[0] . ')'; array_push($p, ...$pol[1]); }
            foreach (select_rows($rel, implode(',', $cols), $w, $p, '', null, 0, $c) as $r) {
                $key = $r[$relPk];
                if (!in_array($relPk, $sub['cols'], true)) unset($r[$relPk]);
                $map[$key] = $r;
            }
        }
        foreach ($rows as &$r) { $r[$rel] = $map[$r[$fk] ?? ''] ?? null; } unset($r);
    }
    // Yalnızca istenen sütunlar
    $keep = array_flip(array_merge($ps['cols'], array_keys($ps['embeds'])));
    return array_map(fn($r) => array_intersect_key($r, $keep), $rows);
}

/** Gelen satırı doğrula ve veritabanı değerlerine çevir */
function prep_row(string $t, array $in, bool $isInsert): array {
    $def = tdef($t); $row = [];
    foreach ($in as $k => $v) $row[$k] = to_db_val(col_type($t, $k), $v);
    if ($isInsert) {
        if (($def['pk'] ?? null) === 'id' && ($def['cols']['id'] ?? '') === 'uuid' && empty($row['id'])) $row['id'] = uuid4();
        foreach ($def['auto'] ?? [] as $a) if (empty($row[$a])) $row[$a] = uuid4();
        foreach ($def['defaults'] ?? [] as $k => $v) if (!array_key_exists($k, $row)) $row[$k] = $v;
    }
    return $row;
}

function rest_handle(string $method, string $t, array $qs): void {
    $c = ctx();
    tdef($t);
    $sel = qs_get($qs, 'select');
    $wantCount = qs_get($qs, 'count') === 'exact';
    $head = qs_get($qs, 'head') === 'true' || $method === 'HEAD';

    if ($method === 'GET' || $method === 'HEAD') {
        [$where, $params] = build_where($t, $qs, policy_read($t, $c));
        $limit = qs_get($qs, 'limit'); $offset = (int)(qs_get($qs, 'offset') ?? 0);
        $headers = [];
        if ($wantCount) {
            $total = (int)q("SELECT COUNT(*) FROM `$t` t WHERE $where", $params)->fetchColumn();
            $headers['Content-Range'] = '*/' . $total;
            $headers['X-Total-Count'] = (string)$total;
        }
        if ($head) { json_out(null, 200, $headers); return; }
        $rows = select_rows($t, $sel, $where, $params, build_order($t, qs_get($qs, 'order')), $limit !== null ? (int)$limit : null, $offset, $c);
        json_out($rows, 200, $headers); return;
    }

    $returnRows = $sel !== null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $out = match ($method) {
            'POST' => rest_insert($t, body_json(), $sel, $c),
            'PATCH' => rest_update($t, $qs, body_json(), $sel, $c),
            'DELETE' => rest_delete($t, $qs, $sel, $c),
            default => throw new ApiError('Desteklenmeyen işlem.', 405),
        };
        $pdo->commit();
    } catch (PDOException $e) { $pdo->rollBack(); throw map_db_error($e); }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    mail_flush();
    json_out($returnRows ? $out : null, $returnRows ? ($method === 'POST' ? 201 : 200) : 204);
}

function pk_where(string $t, array $rows): array {
    $pk = tdef($t)['pk'];
    $ids = array_column($rows, $pk);
    if (!$ids) return ['1=0', []];
    return ["t.`$pk` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function rest_insert(string $t, array $body, ?string $sel, Ctx $c): array {
    $list = array_is_list($body) && $body !== [] && is_array($body[0] ?? null) ? $body : [$body];
    if (policy_write($t, 'insert', $c) === false) throw rls_denied($t);
    $inserted = [];
    foreach ($list as $in) {
        if (!is_array($in) || !$in) throw new ApiError('Boş kayıt eklenemez.');
        $row = prep_row($t, $in, true);
        if (!policy_check($t, 'insert', $row, $c)) throw rls_denied($t);
        if ($t === 'appointments') $row = appt_guard($row, null);
        $cols = array_keys($row);
        q("INSERT INTO `$t` (" . implode(',', array_map(fn($x) => "`$x`", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
        $inserted[] = $row;
        if ($t === 'appointments') appt_after_write(null, $row, $c);
    }
    if ($sel === null) return [];
    if (!tdef($t)['pk']) { // birleşik anahtarlı tablo (doctor_services)
        $ps = parse_select($t, $sel);
        return array_map(fn($r) => array_intersect_key(from_db_row($t, $r), array_flip($ps['cols'])), $inserted);
    }
    [$w, $p] = pk_where($t, $inserted);
    return select_rows($t, $sel, $w, $p, '', null, 0, $c);
}

function rest_update(string $t, array $qs, array $body, ?string $sel, Ctx $c): array {
    $pk = tdef($t)['pk'];
    if (!$pk) throw new ApiError('Bu tablo güncellenemez.', 405);
    if (!$body) throw new ApiError('Güncellenecek alan yok.');
    $using = policy_write($t, 'update', $c);
    if ($using === false) return [];
    [$where, $params] = build_where($t, $qs, $using);
    $patch = prep_row($t, $body, false);
    unset($patch[$pk]);
    $targets = q("SELECT * FROM `$t` t WHERE $where FOR UPDATE", $params)->fetchAll();
    foreach ($targets as $old) {
        $new = array_merge($old, $patch);
        if (!policy_check($t, 'update', $new, $c)) throw rls_denied($t);
        if ($t === 'appointments') { $new = appt_guard($new, $old['id']); $patch['ends_at'] = $new['ends_at']; }
        $set = array_keys($patch);
        if (!$set) continue;
        q("UPDATE `$t` SET " . implode(',', array_map(fn($x) => "`$x` = ?", $set)) . " WHERE `$pk` = ?", [...array_values($patch), $old[$pk]]);
        if ($t === 'appointments') appt_after_write($old, $new, $c);
    }
    if ($sel === null || !$targets) return [];
    [$w, $p] = pk_where($t, $targets);
    return select_rows($t, $sel, $w, $p, '', null, 0, $c);
}

function rest_delete(string $t, array $qs, ?string $sel, Ctx $c): array {
    $using = policy_write($t, 'delete', $c);
    if ($using === false) return [];
    [$where, $params] = build_where($t, $qs, $using);
    $pk = tdef($t)['pk'];
    if (!$pk) { // birleşik anahtar: doğrudan sil
        $rows = $sel !== null ? select_rows($t, $sel, $where, $params, '', null, 0, $c) : [];
        $del = preg_replace('/\bt\./', '', $where);
        q("DELETE FROM `$t` WHERE $del", $params);
        return $rows;
    }
    $targets = q("SELECT `$pk` FROM `$t` t WHERE $where FOR UPDATE", $params)->fetchAll();
    if (!$targets) return [];
    [$w, $p] = pk_where($t, $targets);
    $rows = $sel !== null ? select_rows($t, $sel, $w, $p, '', null, 0, $c) : [];
    q("DELETE FROM `$t` WHERE `$pk` IN (" . implode(',', array_fill(0, count($p), '?')) . ')', $p);
    return $rows;
}

// ---------------------------------------------------------------------
// Randevu kuralları (Postgres'teki tetikleyici ve çakışma kısıtının karşılığı)
// ---------------------------------------------------------------------
function appt_guard(array $row, ?string $selfId): array {
    if (empty($row['ends_at'])) {
        $dur = !empty($row['service_id']) ? q('SELECT duration_minutes FROM services WHERE id = ?', [$row['service_id']])->fetchColumn() : false;
        if ($dur === false) throw new ApiError('Elle yazılan işlem için bitiş saatini de girin.');
        $row['ends_at'] = gmdate('Y-m-d H:i:s', strtotime($row['starts_at'] . ' UTC') + (int)$dur * 60);
    }
    if (empty($row['service_id']) && trim((string)($row['custom_service_name'] ?? '')) === '')
        throw new ApiError('İşlem seçin ya da işlem adını yazın.', 400, '23514');
    if (strcmp($row['starts_at'], $row['ends_at']) >= 0) throw new ApiError('Bitiş saati başlangıçtan sonra olmalı.', 400, '23514');
    if (in_array($row['status'] ?? 'pending', ['pending', 'confirmed'], true)) {
        q('SELECT id FROM doctors WHERE id = ? FOR UPDATE', [$row['doctor_id']]); // aynı doktora eşzamanlı yazımı sırala
        $clash = q("SELECT 1 FROM appointments WHERE doctor_id = ? AND status IN ('pending','confirmed')
                     AND starts_at < ? AND ends_at > ? AND id <> ? LIMIT 1",
                   [$row['doctor_id'], $row['ends_at'], $row['starts_at'], $selfId ?? ($row['id'] ?? '')])->fetch();
        if ($clash) throw new ApiError('conflicting key value violates exclusion constraint "appointments_no_overlap"', 409, '23P01');
    }
    return $row;
}

/** Yazımdan sonra e-posta olayları (eski veritabanı tetikleyicisinin karşılığı) */
function appt_after_write(?array $old, array $new, Ctx $c): void {
    $st = $new['status'] ?? 'pending';
    if ($old === null) {
        if (($new['source'] ?? 'phone') === 'online' || $st === 'confirmed') mail_queue($new['id'], 'insert');
    } elseif (($old['status'] ?? '') !== $st) {
        if ($st === 'confirmed') mail_queue($new['id'], 'confirmed');
        elseif ($st === 'cancelled' && in_array($old['status'], ['pending', 'confirmed'], true) && !$c->uid) mail_queue($new['id'], 'cancelled');
    }
}
