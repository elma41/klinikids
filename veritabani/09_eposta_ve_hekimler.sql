-- =====================================================================
-- Klinikids — 09: Hekim güncellemeleri + randevu e-postaları
--   1) Dt. Arya Koç Kuş — yeni özgeçmiş
--   2) Uzm. Dt. Duygu Nur Cesur Kocakaya — çocuk VE yetişkin kliniğinde
--   3) Randevu e-postaları (aysegul@klinikids.company)
--        • siteden yeni randevu → kliniğe e-posta
--        • randevu onaylanınca  → hastaya "Randevunuz onaylandı" e-postası
--        • hasta linkten iptal ederse → kliniğe e-posta
--   4) Online randevuda e-posta alanı (isteğe bağlı) — biçim kontrolü
-- ✅ 2026-11-12 canlı veritabanına uygulandı. Tekrar çalıştırmak zararsızdır.
-- =====================================================================

-- 1) ARYA KOÇ KUŞ — özgeçmiş (profil tamamen yenilenir)
update doctors
   set profile = $j${"color": "#45B4EA", "intro": "2022 yılında Marmara Üniversitesi Diş Hekimliği Fakültesi'nden mezun oldu. Meslek hayatının ilk yıllarından itibaren çocuk hastalarla çalışmaya ilgi duydu ve zamanla çalışma alanını ağırlıklı olarak çocuk diş hekimliği üzerine yoğunlaştırdı.", "facts": [["2022", "Marmara Üniv. mezuniyeti"], ["Çocuk", "Diş hekimliği odağı"], ["Klinikids", "Çocuk Kliniği"]], "tags": ["Çocuk diş hekimliği", "Süt dişi tedavileri", "Muayene & kontrol", "Koruyucu tedaviler", "Dolgu", "Flor & fissür örtücü"], "edu": [["2017 – 2022", "Marmara Üniversitesi Diş Hekimliği Fakültesi, İstanbul"], ["2016", "Marmara Üniversitesi Yabancı Diller Yüksekokulu · İngilizce hazırlık"], ["Lise", "Bingöl Anadolu Öğretmen Lisesi"], ["İlk ve ortaokul", "Şehit Mustafa Gündoğdu İlköğretim Okulu, Bingöl"]], "exp": [["Günümüzde", "Özel Klinik İDS Ağız ve Diş Sağlığı Polikliniği, İzmit"], ["Önceki", "Körfez Nova Ağız ve Diş Sağlığı Polikliniği"], ["2022", "Ayşegül Aydın Çetinkaya Muayenehanesi · Meslek hayatının ilk adımı"]], "about": "12 Şubat 1997'de Bingöl'de doğdu. Mesleki yaklaşımında çocukların ağız ve diş sağlığını korumayı, erken yaşta doğru ağız bakım alışkanlıkları kazanmalarını sağlamayı ve diş hekimi ziyaretlerini onlar için olumlu bir deneyime dönüştürmeyi önemsiyor; her çocuğun ihtiyacına uygun, özenli ve güven temelli bir tedavi süreci sunmayı hedefliyor. Evli ve bir oğlu var. Ailesi ve sevdikleriyle vakit geçirmek onun için çok değerli; kalabalık ailesi ve gezi grubuyla yeni yerler keşfetmekten keyif alıyor.", "hobbies": ["🎬 Film izlemek", "📚 Kitap okumak", "✈️ Seyahat ve yeni yerler keşfetmek", "🍽️ Farklı mutfaklar ve yeni lezzetler"]}$j$::jsonb,
       photo_url = coalesce(nullif(photo_url, ''), 'foto/arya-koc-kus.jpg')
 where full_name = 'Arya Koç Kuş';

-- 2) DUYGU NUR CESUR KOCAKAYA — iki klinikte de görünsün ve randevu alınabilsin
update doctors set clinic = 'ikisi' where full_name = 'Duygu Nur Cesur Kocakaya';
-- Profilde sabit bir "role" yazısı varsa kaldır; kartta otomatik "Ortodonti Uzmanı · Çocuk ve Yetişkin Kliniği" yazar
update doctors set profile = profile - 'role' where full_name = 'Duygu Nur Cesur Kocakaya' and profile ? 'role';

-- 3) RANDEVU E-POSTALARI
create extension if not exists pg_net with schema extensions;
create schema if not exists private;

create table if not exists private.mail_hook (
  id     int  primary key default 1 check (id = 1),
  url    text not null,
  secret text not null
);
-- Gizli anahtar ilk kurulumda rastgele üretilir, dosyada yazmaz; randevu-mail fonksiyonu mail_hook_secret() ile kendisi okur
insert into private.mail_hook (id, url, secret)
values (1, 'https://xnjnxogvnldrbridzvqu.supabase.co/functions/v1/randevu-mail', encode(gen_random_bytes(32), 'hex'))
on conflict (id) do update set url = excluded.url;   -- mevcut gizli anahtar korunur
revoke all on private.mail_hook from public, anon, authenticated;

-- Sadece sunucu tarafı (service_role) okuyabilir; siteden çağrılamaz
create or replace function public.mail_hook_secret()
returns text language sql stable security definer set search_path = public as $$
  select secret from private.mail_hook where id = 1
$$;
revoke execute on function public.mail_hook_secret() from public, anon, authenticated;
grant  execute on function public.mail_hook_secret() to service_role;

create or replace function private.appointment_mail()
returns trigger language plpgsql security definer set search_path = public as $$
declare
  h       private.mail_hook;
  v_event text;
begin
  if tg_op = 'INSERT' then
    if new.source <> 'online' and new.status <> 'confirmed' then return new; end if;
    v_event := 'insert';
  elsif new.status is distinct from old.status and new.status = 'confirmed' then
    v_event := 'confirmed';
  elsif new.status is distinct from old.status and new.status = 'cancelled'
        and old.status in ('pending', 'confirmed') and auth.uid() is null then
    v_event := 'cancelled';           -- hasta iptal linkinden iptal etti (personel iptali bildirilmez)
  else
    return new;
  end if;

  select * into h from private.mail_hook where id = 1;
  if h.url is null then return new; end if;

  perform net.http_post(
    url     := h.url,
    body    := jsonb_build_object('appointment_id', new.id, 'event', v_event),
    headers := jsonb_build_object('Content-Type', 'application/json', 'x-hook-secret', h.secret),
    timeout_milliseconds := 15000
  );
  return new;
exception when others then
  -- E-posta gönderilemese bile randevu kaydı asla engellenmez
  raise warning 'Randevu e-postası kuyruğa alınamadı: %', sqlerrm;
  return new;
end $$;
revoke execute on function private.appointment_mail() from public, anon, authenticated;

drop trigger if exists appointments_mail on appointments;
create trigger appointments_mail
  after insert or update of status on appointments
  for each row execute function private.appointment_mail();

-- 4) ONLINE RANDEVU — e-posta biçim kontrolü + yeni yazılan e-postanın kaydedilmesi
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

  -- E-posta isteğe bağlı; yazıldıysa biçimi doğru olmalı (onay e-postası buraya gider)
  if nullif(trim(p_email), '') is not null and trim(p_email) !~* '^[^\s@]+@[^\s@]+\.[^\s@]+$' then
    raise exception 'Lütfen geçerli bir e-posta adresi girin.';
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
         email = coalesce(excluded.email, patients.email)   -- yeni yazılan e-posta güncel kabul edilir
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

grant execute on function book_appointment(uuid, uuid, timestamptz, text, text, text, boolean, text) to anon, authenticated;

-- 5) KVKK AYDINLATMA METNİ — e-posta eklemeleri (kvkk.html; panelde KVKK metinleri)
--    Metnin geri kalanına dokunmaz; tekrar çalıştırmak zararsızdır.
update legal_sections
   set body = replace(body, $t$- Randevu oluşturulması, teyit edilmesi, hatırlatılması ve değişiklikler için sizinle iletişime geçilmesi$t$, $t$- Randevu oluşturulması, teyit edilmesi, hatırlatılması ve değişiklikler için sizinle iletişime geçilmesi; randevu onayı ve bilgilendirme e-postalarının gönderilmesi$t$)
 where slug = 'amaclar' and body not like '%onay ve bilgilendirme e-postalarının%' and body not like '%onayı ve bilgilendirme e-postalarının%';
update legal_sections
   set body = body || E'\n\n' || $t$Randevu formunda paylaştığınız e-posta adresi yalnızca randevunuzla ilgili bilgilendirme için kullanılır. Ayrıca onay vermediğiniz sürece size tanıtım veya kampanya amaçlı ticari elektronik ileti gönderilmez.$t$
 where slug = 'amaclar' and body not like '%ticari elektronik ileti%';
update legal_sections
   set body = body || ' ' || $t$Randevu bildirimleri ve onay e-postaları, Kliniğin e-posta hizmet sağlayıcısının altyapısı üzerinden gönderilir.$t$
 where slug = 'aktarim' and body not like '%e-posta hizmet sağlayıcısının%';

-- KONTROL: son e-posta denemelerinin sonucu (status_code 200 = gönderildi)
-- select id, status_code, content, created from net._http_response order by created desc limit 10;
