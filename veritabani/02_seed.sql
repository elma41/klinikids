-- =====================================================================
-- Örnek veriler (test için). Gerçek kliniğe geçerken bu dosyayı çalıştırma,
-- doktorları ve hizmetleri admin panelinden ekle.
-- =====================================================================

insert into doctors (id, full_name, title, specialty, color, sort_order) values
  ('11111111-1111-1111-1111-111111111111', 'Ayşe Yılmaz',  'Uzm. Dt.', 'Ortodonti',         '#0EA5E9', 1),
  ('22222222-2222-2222-2222-222222222222', 'Mehmet Kaya',  'Dt.',      'Genel Diş Hekimliği', '#22C55E', 2),
  ('33333333-3333-3333-3333-333333333333', 'Elif Demir',   'Uzm. Dt.', 'Pedodonti (Çocuk)',  '#F59E0B', 3);

insert into services (id, name, duration_minutes, sort_order) values
  ('aaaaaaaa-0000-0000-0000-000000000001', 'Muayene / Kontrol',  30, 1),
  ('aaaaaaaa-0000-0000-0000-000000000002', 'Diş Taşı Temizliği', 45, 2),
  ('aaaaaaaa-0000-0000-0000-000000000003', 'Dolgu',              60, 3),
  ('aaaaaaaa-0000-0000-0000-000000000004', 'Ortodonti Kontrolü', 30, 4);

insert into doctor_services (doctor_id, service_id) values
  ('11111111-1111-1111-1111-111111111111', 'aaaaaaaa-0000-0000-0000-000000000001'),
  ('11111111-1111-1111-1111-111111111111', 'aaaaaaaa-0000-0000-0000-000000000004'),
  ('22222222-2222-2222-2222-222222222222', 'aaaaaaaa-0000-0000-0000-000000000001'),
  ('22222222-2222-2222-2222-222222222222', 'aaaaaaaa-0000-0000-0000-000000000002'),
  ('22222222-2222-2222-2222-222222222222', 'aaaaaaaa-0000-0000-0000-000000000003'),
  ('33333333-3333-3333-3333-333333333333', 'aaaaaaaa-0000-0000-0000-000000000001'),
  ('33333333-3333-3333-3333-333333333333', 'aaaaaaaa-0000-0000-0000-000000000003');

-- Pazartesi–Cuma 09:00–12:30 ve 13:30–18:00 (öğle arası), Dr. Mehmet ayrıca Cumartesi 10:00–14:00
insert into working_hours (doctor_id, weekday, start_time, end_time)
select d.id, wd, t.s, t.e
  from doctors d
 cross join generate_series(1, 5) wd
 cross join (values (time '09:00', time '12:30'), (time '13:30', time '18:00')) t(s, e);

insert into working_hours (doctor_id, weekday, start_time, end_time) values
  ('22222222-2222-2222-2222-222222222222', 6, '10:00', '14:00');
