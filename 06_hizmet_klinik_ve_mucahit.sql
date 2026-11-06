-- =====================================================================
-- Klinikids — 1) Hizmetlere klinik bilgisi  2) Dt. Mücahit Çağan kaydı
-- Supabase → SQL Editor → bu dosyanın tamamını yapıştır → Run
-- Tekrar çalıştırmak zararsızdır.
-- =====================================================================

-- 1) Her hizmet hangi sayfanın randevu formunda görünsün: cocuk / yetiskin / ikisi
alter table services add column if not exists clinic text not null default 'ikisi'
  check (clinic in ('cocuk','yetiskin','ikisi'));

-- Mevcut hizmetleri sınıflandır (sadece hâlâ varsayılan 'ikisi' olanlar)
update services set clinic = 'cocuk'
 where clinic = 'ikisi' and (name ilike '%(Çocuk)%' or name in ('Fissür Örtücü','Yer Tutucu'));
update services set clinic = 'yetiskin'
 where clinic = 'ikisi' and name in ('Diş Taşı Temizliği','Diş Eti Tedavisi','Diş Beyazlatma','İmplant','Köprü',
       'Hareketli Protez','Tam Protez','Lamine','E-max','Mockup / Gülüş Tasarımı','Şeffaf Plak');
-- "Muayene / Kontrol" ve "Flor Uygulaması" iki klinikte de görünür ('ikisi' kalır)

-- 2) Dt. Mücahit Çağan: her iki klinik, tüm aktif hizmetler, Pzt–Cum 09:00–12:30 ve 13:30–18:00 (diğer hekimlerle aynı)
--    (Hizmetleri ve saatleri sonra admin panelinden düzenleyebilirsiniz.)
do $$
declare v_id uuid;
begin
  select id into v_id from doctors where full_name = 'Mücahit Çağan';
  if v_id is null then
    insert into doctors (title, full_name, specialty, clinic, color, sort_order, is_active)
    values ('Dt.', 'Mücahit Çağan', 'Diş Hekimi', 'ikisi', '#1C8C88',
            coalesce((select max(sort_order) from doctors), 0) + 1, true)
    returning id into v_id;

    insert into working_hours (doctor_id, weekday, start_time, end_time)
    select v_id, w, t.s::time, t.e::time from generate_series(1,5) w,
           (values ('09:00','12:30'), ('13:30','18:00')) t(s, e);

    insert into doctor_services (doctor_id, service_id)
    select v_id, id from services where is_active;
  end if;
end $$;
