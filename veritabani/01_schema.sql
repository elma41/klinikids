-- =====================================================================
-- Diş Kliniği Randevu Sistemi — Veritabanı Şeması (Supabase / PostgreSQL)
-- Supabase panelinde: SQL Editor → bu dosyayı yapıştır → Run
-- =====================================================================

create extension if not exists btree_gist;  -- çift randevu engeli için

-- ---------------------------------------------------------------------
-- 1) Sabit türler
-- ---------------------------------------------------------------------
create type staff_role         as enum ('admin', 'receptionist', 'doctor');
create type appointment_status as enum ('pending', 'confirmed', 'cancelled', 'completed', 'no_show');
create type appointment_source as enum ('online', 'phone', 'walk_in');

-- ---------------------------------------------------------------------
-- 2) Klinik ayarları (tek satırlık tablo)
-- ---------------------------------------------------------------------
create table clinic_settings (
  id                 boolean primary key default true check (id),
  clinic_name        text    not null default 'Diş Kliniği',
  timezone           text    not null default 'Europe/Istanbul',
  slot_step_minutes  int     not null default 15  check (slot_step_minutes between 5 and 120),
  min_notice_minutes int     not null default 120 check (min_notice_minutes >= 0), -- en erken kaç dk sonrasına randevu alınır
  max_days_ahead     int     not null default 60  check (max_days_ahead between 1 and 365),
  auto_confirm       boolean not null default false  -- false: online randevular sekreter onayı bekler
);
insert into clinic_settings default values;

-- ---------------------------------------------------------------------
-- 3) Doktorlar, hizmetler ve kimin hangi hizmeti verdiği
-- ---------------------------------------------------------------------
create table doctors (
  id         uuid primary key default gen_random_uuid(),
  full_name  text not null,
  title      text,                       -- ör. "Dt.", "Uzm. Dt."
  specialty  text,                       -- ör. "Ortodonti"
  bio        text,
  photo_url  text,
  color      text not null default '#0EA5E9',  -- admin takviminde doktorun rengi
  is_active  boolean not null default true,    -- "doktor çıkar" = pasife al (geçmiş randevular korunur)
  sort_order int not null default 0,
  created_at timestamptz not null default now()
);

create table services (
  id               uuid primary key default gen_random_uuid(),
  name             text not null,
  description      text,
  duration_minutes int  not null check (duration_minutes between 5 and 480),
  is_active        boolean not null default true,
  sort_order       int not null default 0
);

create table doctor_services (
  doctor_id  uuid not null references doctors(id)  on delete cascade,
  service_id uuid not null references services(id) on delete cascade,
  primary key (doctor_id, service_id)
);

-- ---------------------------------------------------------------------
-- 4) Personel hesapları (Supabase Auth kullanıcılarına bağlı)
-- ---------------------------------------------------------------------
create table staff_profiles (
  user_id   uuid primary key references auth.users(id) on delete cascade,
  full_name text not null,
  role      staff_role not null,
  doctor_id uuid references doctors(id) on delete set null,
  check (role <> 'doctor' or doctor_id is not null)
);

-- ---------------------------------------------------------------------
-- 5) Haftalık çalışma saatleri ve izinler
-- ---------------------------------------------------------------------
create table working_hours (
  id         uuid primary key default gen_random_uuid(),
  doctor_id  uuid not null references doctors(id) on delete cascade,
  weekday    smallint not null check (weekday between 1 and 7),  -- 1 = Pazartesi … 7 = Pazar
  start_time time not null,
  end_time   time not null,
  check (start_time < end_time),
  -- Aynı doktorun aynı gündeki aralıkları çakışamaz (09–12 ve 13–18 olur, 09–12 ve 11–14 olmaz)
  exclude using gist (
    doctor_id with =,
    weekday   with =,
    tsrange(date '2000-01-01' + start_time, date '2000-01-01' + end_time) with &&
  )
);

create table time_off (
  id         uuid primary key default gen_random_uuid(),
  doctor_id  uuid not null references doctors(id) on delete cascade,
  starts_at  timestamptz not null,
  ends_at    timestamptz not null,
  reason     text,               -- ör. "Yıllık izin", "Kongre"
  created_at timestamptz not null default now(),
  check (starts_at < ends_at)
);

-- ---------------------------------------------------------------------
-- 6) Hastalar ve randevular
-- ---------------------------------------------------------------------
create table patients (
  id              uuid primary key default gen_random_uuid(),
  full_name       text not null,
  phone           text not null unique,   -- 10 hane, başında 0 olmadan: 5XXXXXXXXX
  email           text,
  kvkk_consent_at timestamptz,            -- açık rıza zamanı
  notes           text,                   -- sadece personel görür
  created_at      timestamptz not null default now()
);

create table appointments (
  id           uuid primary key default gen_random_uuid(),
  doctor_id    uuid not null references doctors(id)  on delete restrict,
  service_id   uuid references services(id) on delete restrict,  -- listeden seçilen işlem
  custom_service_name text,   -- listede olmayan, personelin elle yazdığı işlem
  patient_id   uuid not null references patients(id) on delete restrict,
  starts_at    timestamptz not null,
  ends_at      timestamptz not null,
  status       appointment_status not null default 'pending',
  source       appointment_source not null default 'phone',
  patient_note text,          -- hastanın formda yazdığı not
  staff_note   text,          -- personelin iç notu
  cancel_token uuid not null default gen_random_uuid() unique,  -- SMS'teki iptal linki için
  created_at   timestamptz not null default now(),
  check (starts_at < ends_at),
  check (service_id is not null or nullif(trim(custom_service_name), '') is not null),
  -- ÇİFT RANDEVU ENGELİ: aynı doktorun aktif randevuları zaman olarak çakışamaz.
  -- İki hasta aynı anda aynı saate tıklasa bile veritabanı sadece birini kabul eder.
  exclude using gist (
    doctor_id with =,
    tstzrange(starts_at, ends_at) with &&
  ) where (status in ('pending', 'confirmed'))
);

create index appointments_doctor_time_idx on appointments (doctor_id, starts_at);
create index time_off_doctor_time_idx     on time_off (doctor_id, starts_at);

-- Personel panelden randevu eklerken bitiş saatini hizmet süresinden otomatik doldur
create or replace function set_appointment_end()
returns trigger language plpgsql as $$
begin
  if new.ends_at is null then
    select new.starts_at + make_interval(mins => s.duration_minutes)
      into new.ends_at
      from services s where s.id = new.service_id;
    if new.ends_at is null then
      raise exception 'Elle yazılan işlem için bitiş saatini de girin.';
    end if;
  end if;
  return new;
end $$;

create trigger appointments_set_end
  before insert or update of starts_at, service_id on appointments
  for each row execute function set_appointment_end();

-- ---------------------------------------------------------------------
-- 7) Yetki yardımcıları
-- ---------------------------------------------------------------------
create or replace function current_staff_role()
returns staff_role language sql stable security definer set search_path = public as $$
  select role from staff_profiles where user_id = auth.uid()
$$;

create or replace function current_staff_doctor_id()
returns uuid language sql stable security definer set search_path = public as $$
  select doctor_id from staff_profiles where user_id = auth.uid()
$$;

create or replace function is_staff()
returns boolean language sql stable security definer set search_path = public as $$
  select exists (select 1 from staff_profiles where user_id = auth.uid())
$$;

-- ---------------------------------------------------------------------
-- 8) Satır bazlı güvenlik (RLS)
--    Hasta (anon): sadece aktif doktor, hizmet ve çalışma saatlerini görür.
--    Randevu ve hasta bilgilerine doğrudan erişemez; aşağıdaki fonksiyonları kullanır.
--    Admin: her şey. Sekreter: randevu, hasta, izin, çalışma saati.
--    Doktor: sadece kendi randevuları, izinleri ve çalışma saatleri.
-- ---------------------------------------------------------------------
alter table clinic_settings enable row level security;
alter table doctors         enable row level security;
alter table services        enable row level security;
alter table doctor_services enable row level security;
alter table staff_profiles  enable row level security;
alter table working_hours   enable row level security;
alter table time_off        enable row level security;
alter table patients        enable row level security;
alter table appointments    enable row level security;

-- Ayarlar
create policy settings_read  on clinic_settings for select to anon, authenticated using (true);
create policy settings_admin on clinic_settings for update to authenticated
  using (current_staff_role() = 'admin') with check (current_staff_role() = 'admin');

-- Doktorlar
create policy doctors_read  on doctors for select to anon, authenticated using (is_active or is_staff());
create policy doctors_admin on doctors for all to authenticated
  using (current_staff_role() = 'admin') with check (current_staff_role() = 'admin');

-- Hizmetler
create policy services_read  on services for select to anon, authenticated using (is_active or is_staff());
create policy services_admin on services for all to authenticated
  using (current_staff_role() = 'admin') with check (current_staff_role() = 'admin');

-- Doktor–hizmet eşleşmesi
create policy ds_read  on doctor_services for select to anon, authenticated using (true);
create policy ds_admin on doctor_services for all to authenticated
  using (current_staff_role() = 'admin') with check (current_staff_role() = 'admin');

-- Personel profilleri
create policy staff_self  on staff_profiles for select to authenticated using (user_id = auth.uid());
create policy staff_admin on staff_profiles for all to authenticated
  using (current_staff_role() = 'admin') with check (current_staff_role() = 'admin');

-- Çalışma saatleri
create policy wh_read on working_hours for select to anon, authenticated using (true);
create policy wh_manage on working_hours for all to authenticated
  using      (current_staff_role() in ('admin', 'receptionist') or doctor_id = current_staff_doctor_id())
  with check (current_staff_role() in ('admin', 'receptionist') or doctor_id = current_staff_doctor_id());

-- İzinler
create policy to_manage on time_off for all to authenticated
  using      (current_staff_role() in ('admin', 'receptionist') or doctor_id = current_staff_doctor_id())
  with check (current_staff_role() in ('admin', 'receptionist') or doctor_id = current_staff_doctor_id());

-- Hastalar
create policy patients_staff on patients for all to authenticated
  using (current_staff_role() in ('admin', 'receptionist'))
  with check (current_staff_role() in ('admin', 'receptionist'));
create policy patients_doctor_read on patients for select to authenticated
  using (exists (select 1 from appointments a
                 where a.patient_id = patients.id and a.doctor_id = current_staff_doctor_id()));

-- Randevular
create policy appt_staff on appointments for all to authenticated
  using (current_staff_role() in ('admin', 'receptionist'))
  with check (current_staff_role() in ('admin', 'receptionist'));
create policy appt_doctor_read on appointments for select to authenticated
  using (doctor_id = current_staff_doctor_id());
create policy appt_doctor_update on appointments for update to authenticated
  using (doctor_id = current_staff_doctor_id()) with check (doctor_id = current_staff_doctor_id());

-- ---------------------------------------------------------------------
-- 9) Herkese açık fonksiyonlar (web sitesi bunları çağırır)
-- ---------------------------------------------------------------------

-- Bir doktorun belirli bir gündeki, belirli bir hizmet için BOŞ saatleri
create or replace function get_available_slots(p_doctor_id uuid, p_service_id uuid, p_date date)
returns table (slot_start timestamptz, slot_end timestamptz)
language plpgsql stable security definer set search_path = public as $$
#variable_conflict use_column
declare
  s          clinic_settings;
  v_duration int;
  v_today    date;
begin
  select * into s from clinic_settings;
  v_today := (now() at time zone s.timezone)::date;

  if p_date < v_today or p_date > v_today + s.max_days_ahead then
    return;
  end if;

  select sv.duration_minutes into v_duration
    from services sv
    join doctor_services ds on ds.service_id = sv.id
    join doctors d          on d.id = ds.doctor_id
   where sv.id = p_service_id and d.id = p_doctor_id
     and sv.is_active and d.is_active;

  if v_duration is null then
    return;  -- doktor bu hizmeti vermiyor veya pasif
  end if;

  return query
  with candidates as (
    select (gs at time zone s.timezone)                                         as st,
           ((gs + make_interval(mins => v_duration)) at time zone s.timezone)   as en
      from working_hours wh
     cross join lateral generate_series(
             p_date + wh.start_time,
             p_date + wh.end_time - make_interval(mins => v_duration),
             make_interval(mins => s.slot_step_minutes)) as gs
     where wh.doctor_id = p_doctor_id
       and wh.weekday = extract(isodow from p_date)
  )
  select c.st, c.en
    from candidates c
   where c.st >= now() + make_interval(mins => s.min_notice_minutes)
     and not exists (select 1 from appointments a
                      where a.doctor_id = p_doctor_id
                        and a.status in ('pending', 'confirmed')
                        and tstzrange(a.starts_at, a.ends_at) && tstzrange(c.st, c.en))
     and not exists (select 1 from time_off t
                      where t.doctor_id = p_doctor_id
                        and tstzrange(t.starts_at, t.ends_at) && tstzrange(c.st, c.en))
   order by c.st;
end $$;

-- Doktorun dolu zaman blokları (hasta adı YOK) — sitede haftalık "dolu/boş" görünümü için
create or replace function get_busy_blocks(p_doctor_id uuid, p_from timestamptz, p_to timestamptz)
returns table (busy_start timestamptz, busy_end timestamptz)
language sql stable security definer set search_path = public as $$
  select a.starts_at, a.ends_at from appointments a
   where a.doctor_id = p_doctor_id
     and a.status in ('pending', 'confirmed')
     and tstzrange(a.starts_at, a.ends_at) && tstzrange(p_from, least(p_to, p_from + interval '31 days'))
  union all
  select t.starts_at, t.ends_at from time_off t
   where t.doctor_id = p_doctor_id
     and tstzrange(t.starts_at, t.ends_at) && tstzrange(p_from, least(p_to, p_from + interval '31 days'))
  order by 1
$$;

-- Hastanın online randevu alması
create or replace function book_appointment(
  p_doctor_id     uuid,
  p_service_id    uuid,
  p_starts_at     timestamptz,
  p_full_name     text,
  p_phone         text,
  p_email         text,
  p_kvkk_consent  boolean,
  p_note          text default null
)
returns table (appointment_id uuid, cancel_token uuid, status appointment_status)
language plpgsql volatile security definer set search_path = public as $$
#variable_conflict use_column
declare
  s          clinic_settings;
  v_phone    text;
  v_patient  uuid;
  v_duration int;
  v_appt     appointments;
begin
  if not coalesce(p_kvkk_consent, false) then
    raise exception 'KVKK aydınlatma metnini onaylamanız gerekiyor.';
  end if;

  if length(trim(coalesce(p_full_name, ''))) < 3 then
    raise exception 'Lütfen adınızı ve soyadınızı girin.';
  end if;

  -- Telefonu 5XXXXXXXXX biçimine getir
  v_phone := regexp_replace(coalesce(p_phone, ''), '\D', '', 'g');
  if    v_phone ~ '^90\d{10}$' then v_phone := substr(v_phone, 3);
  elsif v_phone ~ '^0\d{10}$'  then v_phone := substr(v_phone, 2);
  end if;
  if v_phone !~ '^5\d{9}$' then
    raise exception 'Lütfen geçerli bir cep telefonu numarası girin.';
  end if;

  select * into s from clinic_settings;

  -- Saat gerçekten boş ve kurallara uygun mu? (çalışma saati, izin, ileri tarih sınırı)
  if not exists (
    select 1 from get_available_slots(p_doctor_id, p_service_id, (p_starts_at at time zone s.timezone)::date) g
     where g.slot_start = p_starts_at
  ) then
    raise exception 'Seçtiğiniz saat artık müsait değil, lütfen başka bir saat seçin.';
  end if;

  select duration_minutes into v_duration from services where id = p_service_id;

  -- Hasta kaydı: aynı telefon varsa mevcut kaydı kullan (isim üzerine yazılmaz)
  insert into patients (full_name, phone, email, kvkk_consent_at)
  values (trim(p_full_name), v_phone, nullif(trim(p_email), ''), now())
  on conflict (phone) do update
     set kvkk_consent_at = now(),
         email = coalesce(patients.email, excluded.email)
  returning id into v_patient;

  insert into appointments (doctor_id, service_id, patient_id, starts_at, ends_at, status, source, patient_note)
  values (p_doctor_id, p_service_id, v_patient, p_starts_at,
          p_starts_at + make_interval(mins => v_duration),
          case when s.auto_confirm then 'confirmed'::appointment_status else 'pending'::appointment_status end,
          'online', nullif(trim(p_note), ''))
  returning * into v_appt;

  return query select v_appt.id, v_appt.cancel_token, v_appt.status;

exception
  when exclusion_violation then  -- aynı anda başka biri aynı saati aldı
    raise exception 'Seçtiğiniz saat az önce doldu, lütfen başka bir saat seçin.';
end $$;

-- SMS/e-postadaki linkten iptal
create or replace function cancel_appointment_by_token(p_token uuid)
returns boolean
language plpgsql volatile security definer set search_path = public as $$
declare
  s clinic_settings;
begin
  select * into s from clinic_settings;
  update appointments
     set status = 'cancelled'
   where cancel_token = p_token
     and status in ('pending', 'confirmed')
     and starts_at > now() + make_interval(mins => s.min_notice_minutes);
  return found;
end $$;

-- Fonksiyon erişimleri
revoke execute on all functions in schema public from public;
grant execute on function get_available_slots(uuid, uuid, date)                                  to anon, authenticated;
grant execute on function get_busy_blocks(uuid, timestamptz, timestamptz)                        to anon, authenticated;
grant execute on function book_appointment(uuid, uuid, timestamptz, text, text, text, boolean, text) to anon, authenticated;
grant execute on function cancel_appointment_by_token(uuid)                                      to anon, authenticated;
grant execute on function current_staff_role()      to anon, authenticated;
grant execute on function current_staff_doctor_id() to anon, authenticated;
grant execute on function is_staff()                to anon, authenticated;
grant execute on function set_appointment_end()     to anon, authenticated;
