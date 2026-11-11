-- =====================================================================
-- Klinikids — 07: Panelden yönetilen site içerikleri
--   1) Hekim profilleri (kart bilgileri + fotoğraf) — doktor kendi profilini düzenler
--   2) Sık sorulan sorular (çocuk / yetişkin)
--   3) Galeri (fotoğraf yükle / sil / sırala)
--   4) Sitede çalışma saatleri ve izinler (herkese açık özet, hasta bilgisi yok)
--   5) Uzm. Dt. Duygu Nur Cesur Kocakaya (yetişkin kliniği, ortodonti)
-- Tekrar çalıştırmak zararsızdır.
-- =====================================================================

-- 1) HEKİM PROFİLLERİ
alter table doctors add column if not exists profile   jsonb not null default '{}'::jsonb;
alter table doctors add column if not exists photo_url text;

-- Doktor yalnızca KENDİ profilini ve fotoğrafını değiştirir (klinik, aktiflik vb. yönetici işidir)
create or replace function update_my_profile(p_profile jsonb, p_photo_url text)
returns void language plpgsql security definer set search_path = public as $$
declare v uuid := private.current_staff_doctor_id();
begin
  if v is null then raise exception 'Hesabınız bir doktor kaydına bağlı değil.'; end if;
  update doctors set profile = coalesce(p_profile, '{}'::jsonb), photo_url = nullif(trim(p_photo_url), '') where id = v;
end $$;
revoke execute on function update_my_profile(jsonb, text) from public, anon;
grant  execute on function update_my_profile(jsonb, text) to authenticated;

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('hekim-foto', 'hekim-foto', true, 5242880, array['image/png','image/jpeg','image/webp'])
on conflict (id) do nothing;
drop policy if exists hekim_foto_insert on storage.objects;
drop policy if exists hekim_foto_update on storage.objects;
drop policy if exists hekim_foto_delete on storage.objects;
create policy hekim_foto_insert on storage.objects for insert to authenticated
  with check (bucket_id = 'hekim-foto' and (private.current_staff_role() = 'admin'
              or (storage.foldername(name))[1] = private.current_staff_doctor_id()::text));
create policy hekim_foto_update on storage.objects for update to authenticated
  using (bucket_id = 'hekim-foto' and (private.current_staff_role() = 'admin'
         or (storage.foldername(name))[1] = private.current_staff_doctor_id()::text));
create policy hekim_foto_delete on storage.objects for delete to authenticated
  using (bucket_id = 'hekim-foto' and (private.current_staff_role() = 'admin'
         or (storage.foldername(name))[1] = private.current_staff_doctor_id()::text));

-- 5) YENİ HEKİM: Uzm. Dt. Duygu Nur Cesur Kocakaya
insert into services (name, duration_minutes, sort_order, clinic, description)
select 'Ortodonti Muayenesi', 30, 14, 'ikisi', 'Diş teli / şeffaf plak değerlendirmesi'
 where not exists (select 1 from services where name = 'Ortodonti Muayenesi');

do $$
declare v_id uuid;
begin
  select id into v_id from doctors where full_name = 'Duygu Nur Cesur Kocakaya';
  if v_id is null then
    insert into doctors (title, full_name, specialty, clinic, color, sort_order, is_active)
    values ('Uzm. Dt.', 'Duygu Nur Cesur Kocakaya', 'Ortodonti Uzmanı', 'yetiskin', '#7A5BA6',
            coalesce((select max(sort_order) from doctors), 0) + 1, true)
    returning id into v_id;
    insert into working_hours (doctor_id, weekday, start_time, end_time)
    select v_id, w, t.s::time, t.e::time from generate_series(1,5) w,
           (values ('09:00','12:30'), ('13:30','18:00')) t(s, e);
    insert into doctor_services (doctor_id, service_id)
    select v_id, id from services where name in ('Muayene / Kontrol', 'Şeffaf Plak', 'Ortodonti Muayenesi');
  end if;
end $$;

-- Mevcut kart bilgilerini veritabanına aktar (sadece profili boş olanlar)
update doctors set profile = $j${"intro": "Hacettepe Üniversitesi mezunu. 2008'den bu yana mesleğini yalnızca çocuk hastalara ayırıyor; deneyimini 2026'da kurduğu Klinikids'te sürdürüyor.", "facts": [["2007", "Hacettepe Üniv."], ["18+ yıl", "Çocuklarla"]], "tags": ["Süt dişi tedavileri", "Koruyucu tedaviler", "Yer tutucu", "Erken ortodonti"], "edu": [["2002 – 2007", "Hacettepe Üniversitesi Diş Hekimliği Fakültesi, Ankara"], ["Lise", "Trabzon Yomra Fen Lisesi"], ["Sürekli eğitim", "Yurt içi ve yurt dışında çocuk diş hekimliği (pedodonti) kursları, sempozyumlar, online ve uygulamalı eğitimler"]], "exp": [["2026 –", "Özel Klinikids Ağız ve Diş Sağlığı Polikliniği, İzmit · Kurucu"], ["2019 – 2022", "Dentatlas Ağız ve Diş Sağlığı Polikliniği"], ["2018 – 2019", "Aden Aile Ağız ve Diş Sağlığı Polikliniği, Yenişehir ve Başiskele"], ["2013 – 2018", "Hospitadent Ağız ve Diş Sağlığı Merkezi, Çamlıca"], ["2010 – 2015", "Kendi muayenehanesi, Kadıköy"], ["2009 – 2010", "Dr. Tahsin Özbek Çocuk Diş Kliniği, İzmit"], ["2008 – 2009", "5.15 Çocuk Diş Kliniği, Etiler"], ["2007 – 2008", "Dt. Turan Sarı Muayenehanesi, İzmit"]], "about": "1983 Trabzon doğumlu. Evli ve bir oğul annesi. Karadeniz'e ayrı bir sevgisi var ama ülkemizin her köşesini ayrı seviyor.", "hobbies": ["🚶‍♀️ Yürüyüş", "🏃‍♀️ Koşu", "💃 Halk oyunları, flamenko, Latin dansları", "🍳 Yemek yapmak", "🧒 Çocuklarla sohbet", "☕ Dostlarla vakit"], "color": "#FF6F9C", "role": "Diş Hekimi · Klinikids kurucusu"}$j$::jsonb, photo_url = $j$foto/ayse-gul-aydin-cetinkaya.jpg$j$ where full_name = $j$Ayşe Gül Aydın Çetinkaya$j$ and profile = '{}'::jsonb;
update doctors set profile = $j${"intro": "Çocuk kliniğimizde küçük hastalarımızın muayene ve tedavilerini yürütür; her adımı çocuğun anlayacağı dilde anlatarak ilerler.", "facts": [["Klinikids", "Çocuk Kliniği"], ["İzmit", "Kocaeli"]], "tags": ["Muayene & kontrol", "Koruyucu tedaviler", "Dolgu", "Flor & fissür örtücü"], "color": "#45B4EA"}$j$::jsonb, photo_url = $j$foto/arya-koc-kus.jpg$j$ where full_name = $j$Arya Koç Kuş$j$ and profile = '{}'::jsonb;
update doctors set profile = $j${"intro": "Dicle Üniversitesi Diş Hekimliği Fakültesi'nden 2023'te mezun oldu. Öğrenciliğinde Almanya'da özel bir klinikte stajyer hekim olarak çalıştı; orada gözlemlediği tedavi tekniklerini bugün kendi tedavilerinde uyguluyor. Oral cerrahi, implantoloji, vital diş tedavileri, gülüş tasarımı ve protez alanlarına özel ilgi duyuyor.", "facts": [["2023", "Dicle Üniv. mezuniyeti"], ["Almanya", "Klinik staj"], ["2 klinik", "Çocuk ve yetişkin"]], "tags": ["Oral cerrahi", "İmplantoloji", "Vital diş tedavileri", "Gülüş tasarımı", "Protez", "Genel diş hekimliği"], "edu": [["2018 – 2023", "Dicle Üniversitesi Diş Hekimliği Fakültesi, Diyarbakır"], ["Lise", "Haydar Aliyev Fen Lisesi; Özel Sınav Temel Lisesi"], ["İlköğretim", "Atatürk İlköğretim Okulu ve Mevlana İlköğretim Okulu"], ["Sürekli eğitim", "Yurt içi ve yurt dışında alanında değerli hocaların eğitim programları"]], "exp": [["2026 –", "Özel Klinikids Ağız ve Diş Sağlığı Polikliniği, İzmit"], ["2023 –", "Özel sektörde klinisyen diş hekimi"], ["2021", "Almanya'da özel bir klinikte 3 ay stajyer hekim: Alman tedavi teknikleri, laboratuvar sistemleri ve klinik işleyişi"]], "about": "1999 Iğdır doğumlu; bugün İzmit'te yaşıyor. Lise yıllarında ailesinden birini ameliyat eden bir cerrahtan etkilenerek bu mesleği seçti. En büyük hedefi, tüm ağız ve diş tedavilerini uluslararası standartlar ve bilimsel temeller üzerinde, hastalarına en kolay ve en koruyucu şekilde ulaştırmak. En büyük hobisinin mesleği olduğunu söylüyor.", "hobbies": ["🦷 Mesleği", "🧭 Yeni yerler keşfetmek", "🏊 Yüzmek"], "color": "#1C8C88"}$j$::jsonb, photo_url = $j$foto/mucahit-cagan.jpg$j$ where full_name = $j$Mücahit Çağan$j$ and profile = '{}'::jsonb;
update doctors set profile = $j${"intro": "2003 yılında Ankara Üniversitesi Diş Hekimliği Fakültesi'nden mezun oldu. 20 yılı aşkın deneyimiyle estetik diş hekimliği, restoratif uygulamalar, kanal tedavisi, protez ve implant üstü protezler alanlarında çalışıyor. Ön bölge estetik restorasyonları, el becerisi ve sanatsal bakışın bilimsel bilgiyle buluştuğu özel ilgi alanı.", "facts": [["2003", "Ankara Üniv. mezuniyeti"], ["20+ yıl", "Mesleki deneyim"], ["TR · EN", "Konuştuğu diller"]], "tags": ["Estetik diş hekimliği", "Ön bölge estetik restorasyonlar", "Restoratif uygulamalar", "Kanal tedavisi", "Protez", "İmplant üstü protez", "Genel diş hekimliği"], "edu": [["2003", "Ankara Üniversitesi Diş Hekimliği Fakültesi"], ["Sürekli eğitim", "Ulusal ve uluslararası kongre, sempozyum, kurs ve eğitim programları"]], "exp": [["Günümüzde", "Özel Klinik IDS Ağız ve Diş Sağlığı Polikliniği, İzmit"], ["2025", "Ayşe Gül Aydın Çetinkaya Diş Muayenehanesi"], ["2008 – 2025", "Kendi muayenehanesi, İzmit"], ["2006 – 2008", "Kocaeli Acıbadem Hastanesi"], ["2004 – 2006", "Alanya Özel Hayat Hastanesi"], ["2003 – 2004", "Dentfam Ağız ve Diş Sağlığı Polikliniği"]], "lang": "Türkçe, İngilizce", "about": "Tedavi yaklaşımının merkezinde hasta memnuniyeti, ağız ve diş sağlığının yeniden kazandırılması, fonksiyon ve estetiğin bütünlüğü var. Hastalarıyla güçlü bir iletişim ve güven ilişkisi kurmayı, her tedaviyi kişiye özel, titizlikle ve özenle yapmayı temel ilkeleri arasında görüyor. Meslekteki en değerli kazanımlarından biri, yıllar sonra bile hastalarından aldığı memnuniyet ve süregelen güven. Bilimsel ve teknolojik yenilikleri takip ederek kendini sürekli geliştirmeye önem veriyor. Mesleği dışında resim ve heykel sanatına, farkındalığı temel alan Doğu felsefelerine ilgi duyuyor; hayatını bir köpekle paylaşıyor ve doğaya, sanata, keşfetmeye özel bir yer ayırıyor.", "hobbies": ["🎨 Resim ve heykel", "🧘 Doğu felsefeleri", "✈️ Seyahat", "🌲 Orman yürüyüşleri", "⛺ Kamp", "🐕 Köpeğiyle vakit"], "color": "#B8955A"}$j$::jsonb, photo_url = $j$https://pixel-p2.s3.eu-central-1.amazonaws.com/doctor/avatar/a81303ec/a81303ec-079a-4464-bcae-7025bf0348f2_large.jpg$j$ where full_name = $j$Mehtap Uysal$j$ and profile = '{}'::jsonb;
update doctors set profile = $j${"color": "#7A5BA6", "intro": "2018 yılında Kocaeli Üniversitesi Diş Hekimliği Fakültesi'nden fakülte birincisi olarak mezun oldu. 2019'da Diş Hekimliğinde Uzmanlık Sınavı'nda (DUS) Türkiye 39.'su olarak aynı üniversitenin Ortodonti Anabilim Dalı'nda uzmanlık eğitimine başladı ve başarıyla tamamladı.", "facts": [["2018", "Fakülte birincisi"], ["DUS 39.", "Türkiye derecesi"], ["Ortodonti", "Uzmanlık alanı"]], "tags": ["Sabit ortodontik tedavi", "Şeffaf plak", "Erken dönem önleyici ortodonti", "Fonksiyonel ortodonti", "Yetişkin ortodontisi"], "edu": [["Uzmanlık", "Kocaeli Üniversitesi Diş Hekimliği Fakültesi, Ortodonti Anabilim Dalı"], ["2019", "DUS · Türkiye 39.'su"], ["2018", "Kocaeli Üniversitesi Diş Hekimliği Fakültesi · Fakülte birincisi"]], "about": "Mesleğini bilim, estetik ve kişiye özel yaklaşımın bir araya geldiği özel bir alan olarak görüyor. Her hastasının ihtiyaç ve beklentilerini ayrı ayrı değerlendirerek sağlıklı, fonksiyonel ve yüz estetiğiyle uyumlu gülüşler oluşturmayı hedefliyor. Mesleki gelişmeleri ve güncel teknolojileri yakından takip ederek kendini sürekli geliştirmeye önem veriyor."}$j$::jsonb, photo_url = $j$foto/duygu-nur-cesur-kocakaya.jpg$j$ where full_name = $j$Duygu Nur Cesur Kocakaya$j$ and profile = '{}'::jsonb;

-- 2) SIK SORULAN SORULAR
create table if not exists faqs (
  id         uuid primary key default gen_random_uuid(),
  clinic     text not null check (clinic in ('cocuk','yetiskin')),
  question   text not null check (length(trim(question)) >= 5),
  answer     text not null check (length(trim(answer))   >= 5),
  sort_order int  not null default 0,
  is_active  boolean not null default true,
  created_at timestamptz not null default now()
);
alter table faqs enable row level security;
drop policy if exists faqs_read  on faqs;
drop policy if exists faqs_admin on faqs;
create policy faqs_read  on faqs for select to anon, authenticated using (is_active or private.is_staff());
create policy faqs_admin on faqs for all to authenticated
  using (private.current_staff_role() = 'admin') with check (private.current_staff_role() = 'admin');
insert into faqs (clinic, question, answer, sort_order)
select * from (values
  ('cocuk', $j$Çocuğumu ilk kez ne zaman diş hekimine getirmeliyim?$j$, $j$İlk diş çıktığında ya da en geç 1 yaşında ilk muayeneyi öneriyoruz. Erken ziyaret hem olası sorunları erken yakalar hem de çocuğunuzun diş hekimiyle korkusuzca tanışmasını sağlar.$j$, 1),
  ('cocuk', $j$Ne kadar diş macunu kullanmalıyım?$j$, $j$3 yaşından küçüklerde pirinç tanesi kadar, 3–6 yaş arasında bezelye tanesi kadar florürlü macun yeterlidir. Çocuk tükürmeyi öğrenene kadar fırçalamayı siz yapın ya da yakından takip edin.$j$, 2),
  ('cocuk', $j$Süt dişindeki çürük neden tedavi edilmeli, nasılsa düşecek?$j$, $j$Bazı süt azı dişleri 10–12 yaşına kadar ağızda kalır. Tedavi edilmeyen çürük ağrı, enfeksiyon, beslenme sorunlarına ve alttan gelen kalıcı dişin etkilenmesine yol açabilir.$j$, 3),
  ('cocuk', $j$Fissür örtücü nedir?$j$, $j$Azı dişlerinin çiğneme yüzeyindeki derin oluklara uygulanan, çürüğe karşı koruyucu ince bir kaplamadır. Uyuşturma gerektirmez, hızlı ve ağrısızdır. Uygunluğuna muayenede karar verilir.$j$, 4),
  ('cocuk', $j$Yer tutucu ne işe yarar?$j$, $j$Süt dişi erken kaybedildiğinde, alttan gelecek kalıcı dişin yerinin kapanmasını önler. Düşerse ya da kırılırsa kendiniz takmaya çalışmadan bizi arayın.$j$, 5),
  ('cocuk', $j$Muayenede ben de yanında olabilir miyim?$j$, $j$Elbette. Özellikle küçük yaşta ebeveynin yanında olması çocuğu rahatlatır. Bazı çocuklar ise tek başına daha cesur olur; birlikte en iyisine karar veririz.$j$, 6),
  ('yetiskin', $j$Diş beyazlatma dişlere zarar verir mi?$j$, $j$Hekim kontrolünde, uygun yöntemle yapılan beyazlatma güvenlidir. İşlem sırasında ve sonrasında geçici hassasiyet olabilir. Dolgu ve kaplamaların rengi değişmez; bu nedenle uygunluğunu muayenede değerlendiririz.$j$, 1),
  ('yetiskin', $j$Eksik dişlerim için hangi seçenekler var?$j$, $j$İmplant, köprü, hareketli protez ve tam protez başlıca seçeneklerdir. Eksik dişler yalnızca estetiği değil; çiğneme, konuşma ve komşu dişlerin konumunu da etkiler. Size uygun çözüm muayene ve görüntüleme sonrası belirlenir.$j$, 2),
  ('yetiskin', $j$Lamine ile E-max arasındaki fark nedir?$j$, $j$Lamine, dişin ön yüzeyine uygulanan ince porselen kaplamadır. E-max ise lityum disilikat seramikten üretilen, ışık geçirgenliği yüksek bir materyaldir; lamine ya da tam kaplama olarak kullanılabilir. Hangisinin uygun olduğu dişin durumuna göre belirlenir.$j$, 3),
  ('yetiskin', $j$Mockup nedir, neden önemli?$j$, $j$Mockup, planlanan gülüşün tedaviden önce ağız içinde geçici olarak denenmesidir. Form, boy ve renk önceden görülür; karar sürecine siz de aktif olarak katılırsınız.$j$, 4),
  ('yetiskin', $j$Şeffaf plak tedavisi dışarıdan belli olur mu?$j$, $j$Şeffaf plaklar kişiye özel üretilir ve konuşurken çoğu zaman fark edilmez. Etkili olması için günün büyük bölümünde takılması gerekir; süreyi hekiminiz belirler.$j$, 5),
  ('yetiskin', $j$Ne sıklıkla kontrole gelmeliyim?$j$, $j$Genellikle 6 ayda bir kontrol ve gerektiğinde diş taşı temizliği önerilir. Diş eti hastalığı ya da yüksek çürük riski varsa bu aralık hekiminiz tarafından kısaltılabilir.$j$, 6)
) v(clinic, question, answer, sort_order)
where not exists (select 1 from faqs);

-- 3) GALERİ
create table if not exists gallery (
  id         uuid primary key default gen_random_uuid(),
  clinic     text not null default 'ikisi' check (clinic in ('cocuk','yetiskin','ikisi')),
  url        text,
  file_path  text,
  caption    text,
  sort_order int  not null default 0,
  is_active  boolean not null default true,
  created_at timestamptz not null default now(),
  check (url is not null or file_path is not null)
);
alter table gallery enable row level security;
drop policy if exists gallery_read  on gallery;
drop policy if exists gallery_admin on gallery;
create policy gallery_read  on gallery for select to anon, authenticated using (is_active or private.is_staff());
create policy gallery_admin on gallery for all to authenticated
  using (private.current_staff_role() = 'admin') with check (private.current_staff_role() = 'admin');
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('galeri', 'galeri', true, 8388608, array['image/png','image/jpeg','image/webp'])
on conflict (id) do nothing;
drop policy if exists galeri_admin_insert on storage.objects;
drop policy if exists galeri_admin_update on storage.objects;
drop policy if exists galeri_admin_delete on storage.objects;
create policy galeri_admin_insert on storage.objects for insert to authenticated
  with check (bucket_id = 'galeri' and private.current_staff_role() = 'admin');
create policy galeri_admin_update on storage.objects for update to authenticated
  using (bucket_id = 'galeri' and private.current_staff_role() = 'admin');
create policy galeri_admin_delete on storage.objects for delete to authenticated
  using (bucket_id = 'galeri' and private.current_staff_role() = 'admin');
insert into gallery (clinic, url, sort_order)
select * from (values
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/araba-son.webp$j$, 1),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_4887aaaaa.webp$j$, 2),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_4862aaaaa.webp$j$, 3),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_4899aaaa.webp$j$, 4),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_5229aaaaa.webp$j$, 5),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_5116aaaaaa.webp$j$, 6),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_5184aaaaa.webp$j$, 7),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_5013aaaaa.webp$j$, 8),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_5749aaaaa.jpg$j$, 9),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_5509aaaa.jpg$j$, 10),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_5378aaaaa.jpg$j$, 11),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/DSC_5420aaaaa.jpg$j$, 12),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/17.jpg$j$, 13),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/16.jpg$j$, 14),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/15.jpg$j$, 15),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/14.jpg$j$, 16),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/13.jpg$j$, 17),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/12.jpg$j$, 18),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/11.jpg$j$, 19),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/10.jpg$j$, 20),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/8.jpg$j$, 21),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/6.jpg$j$, 22),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/9.jpg$j$, 23),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/7.jpg$j$, 24),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/5.jpg$j$, 25),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/3.jpg$j$, 26),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/2.jpg$j$, 27),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-26.jpg$j$, 28),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-28.jpg$j$, 29),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-29.jpg$j$, 30),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-35.jpg$j$, 31),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-36.jpg$j$, 32),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-30.jpg$j$, 33),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-31.jpg$j$, 34),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-34.jpg$j$, 35),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-37.jpg$j$, 36),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-38.jpg$j$, 37),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/1.jpg$j$, 38),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-40.jpg$j$, 39),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-42.jpg$j$, 40),
  ('ikisi', $j$https://aysegulaydin.gumlet.io/img/galeri/galeri-43.jpg$j$, 41)
) v(clinic, url, sort_order)
where not exists (select 1 from gallery);

-- 4) SİTE İÇİN ÇALIŞMA SAATLERİ VE İZİNLER
create or replace function get_public_schedule()
returns json language sql stable security definer set search_path = public as $$
  select json_build_object(
    'tz', (select timezone from clinic_settings limit 1),
    'hours', (select coalesce(json_agg(json_build_object('d', wh.doctor_id, 'w', wh.weekday,
                     's', to_char(wh.start_time, 'HH24:MI'), 'e', to_char(wh.end_time, 'HH24:MI'))
                     order by wh.weekday, wh.start_time), '[]'::json)
                from working_hours wh join doctors d on d.id = wh.doctor_id where d.is_active),
    'off', (select coalesce(json_agg(json_build_object('d', t.doctor_id, 's', t.starts_at, 'e', t.ends_at, 'r', t.reason)
                     order by t.starts_at), '[]'::json)
              from time_off t join doctors d on d.id = t.doctor_id
             where d.is_active and t.ends_at > now() and t.starts_at < now() + interval '60 days')
  )
$$;
grant execute on function get_public_schedule() to anon, authenticated;
