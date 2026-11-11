-- =====================================================================
-- Klinikids — Doğru bilinen yanlışlar (admin panelinden ekle / düzenle / çıkar)
-- Supabase → SQL Editor → bu dosyanın tamamını yapıştır → Run
-- 01_schema.sql'den SONRA, bir kez çalıştırılır. Tekrar çalıştırmak zararsızdır:
-- tablo doluysa mevcut maddelere dokunmaz.
-- =====================================================================

create table if not exists myths (
  id         uuid primary key default gen_random_uuid(),
  clinic     text not null check (clinic in ('cocuk','yetiskin')),
  emoji      text,                          -- sadece çocuk sayfasında görünür
  myth       text not null check (length(trim(myth))  >= 5),   -- yanlış inanış
  truth      text not null check (length(trim(truth)) >= 5),   -- doğrusu
  sort_order int  not null default 0,
  is_active  boolean not null default true,
  created_at timestamptz not null default now()
);
create index if not exists myths_clinic_idx on myths (clinic, sort_order);

alter table myths enable row level security;

-- Herkes aktif maddeleri görür; personel pasifleri de görür; sadece admin değiştirir
drop policy if exists myths_read  on myths;
drop policy if exists myths_admin on myths;
create policy myths_read  on myths for select to anon, authenticated using (is_active or private.is_staff());
create policy myths_admin on myths for all to authenticated
  using (private.current_staff_role() = 'admin') with check (private.current_staff_role() = 'admin');

-- Sitedeki mevcut maddeler (tablo boşsa bir kez eklenir)
insert into myths (clinic, emoji, myth, truth, sort_order)
select * from (values
  ('cocuk', $q$🍼$q$, $q$Süt dişleri nasılsa düşecek, tedavi etmeye gerek yok.$q$, $q$Süt dişleri çiğneme, konuşma ve kalıcı dişlere yer tutma görevi görür. Bazı süt azıları 10–12 yaşına kadar ağızda kalır. Tedavi edilmeyen çürük ağrı ve enfeksiyona, alttaki kalıcı dişin etkilenmesine yol açabilir.$q$, 1),
  ('cocuk', $q$🎒$q$, $q$İlk diş hekimi ziyareti okul çağında yapılır.$q$, $q$İlk muayene, ilk diş çıktığında ya da en geç 1 yaşında önerilir. Erken ziyaret sorunları küçükken yakalar ve çocuğun diş hekimiyle korkusuzca tanışmasını sağlar.$q$, 2),
  ('cocuk', $q$🧴$q$, $q$Florürlü diş macunu küçük çocuklara zararlı.$q$, $q$Doğru miktarda kullanıldığında florürlü macun çürüğe karşı korur. 3 yaş altına pirinç tanesi, 3–6 yaşa bezelye tanesi kadar macun yeterlidir; fırçalamayı bir yetişkin yapmalı ya da takip etmelidir.$q$, 3),
  ('cocuk', $q$🌙$q$, $q$Gece biberonla süt ya da meyve suyu vermek zararsız.$q$, $q$Uykuda tükürük akışı azalır; şekerli sıvılar dişlerin üzerinde uzun süre kalarak “biberon çürüğüne” yol açabilir. Uyku öncesi dişler fırçalanmalı, gece susayan çocuğa su verilmelidir.$q$, 4),
  ('cocuk', $q$🍬$q$, $q$Önemli olan şekerin miktarı, ne sıklıkta yendiği değil.$q$, $q$Sıklık çok önemli: Gün boyu atıştırılan her şekerli yiyecek dişlerde yeni bir asit atağı başlatır. Tatlıyı ara öğün yerine yemekle birlikte vermek daha az zararlıdır.$q$, 5),
  ('cocuk', $q$🌡️$q$, $q$Diş çıkarmak yüksek ateş ve ishale neden olur.$q$, $q$Diş çıkarma döneminde huzursuzluk, salya artışı ve hafif ateş görülebilir. Yüksek ateş ya da ishal başka bir nedene bağlı olabilir; çocuk doktorunuza danışın.$q$, 6),
  ('cocuk', $q$🪥$q$, $q$Çocuk kendi dişini fırçalayabiliyorsa yardıma gerek yok.$q$, $q$Çocukların el becerisi genellikle 7–8 yaşına kadar tam temizlik için yeterli olmaz. Bu yaşa kadar fırçalamaya yardım etmeniz ya da sonunda kontrol etmeniz önerilir.$q$, 7),
  ('cocuk', $q$🦷$q$, $q$Yerinden çıkan kalıcı dişe yapılacak bir şey yok.$q$, $q$Kalıcı diş, kökünden tutulmadan sütün içinde ve vakit kaybetmeden kliniğe getirilirse çoğu zaman yerine takılabilir. Süt dişi ise yerine takılmaz; yine de muayene gerekir.$q$, 8),
  ('yetiskin', null, $q$Diş eti kanaması normaldir, önemsenmez.$q$, $q$Fırçalarken ya da kendiliğinden kanayan diş eti çoğu zaman iltihap belirtisidir. Erken dönemde profesyonel temizlik ve doğru bakımla tamamen geri döndürülebilir; ihmal edilirse diş kaybına kadar ilerleyebilir.$q$, 1),
  ('yetiskin', null, $q$Sert fırça dişleri daha iyi temizler.$q$, $q$Sert kıllı fırça ve bastırarak fırçalamak diş eti çekilmesine ve diş yüzeyinde aşınmaya yol açabilir. Yumuşak kıllı fırça ve doğru teknik yeterlidir.$q$, 2),
  ('yetiskin', null, $q$Diş taşı temizliği mineyi aşındırır, dişleri seyreltir.$q$, $q$Temizlik mineye zarar vermez. Sonrasında fark edilen aralıklar, daha önce taşın ve şişmiş diş etinin doldurduğu boşluklardır. Kısa süreli hassasiyet olabilir.$q$, 3),
  ('yetiskin', null, $q$Hamilelikte diş tedavisi yapılmaz.$q$, $q$Muayene, diş taşı temizliği ve gerekli tedaviler hamilelikte yapılabilir; planlı işlemler için en rahat dönem genellikle ikinci üç aydır. Ağrı ve enfeksiyon gibi acil durumlar ertelenmemelidir. Hekiminize hamile olduğunuzu mutlaka söyleyin.$q$, 4),
  ('yetiskin', null, $q$Her doğum bir diş götürür.$q$, $q$Bebek, ihtiyaç duyduğu kalsiyumu annenin dişlerinden çekmez. Hamilelikte hormonlar diş eti hassasiyetini, bulantı ve kusma ise asit atağını artırabilir; düzenli bakım ve kontrolle bu riskler azaltılır.$q$, 5),
  ('yetiskin', null, $q$Üst dişlerin çekimi gözlere zarar verir.$q$, $q$Üst dişlerin çekimi ile göz sağlığı arasında böyle bir ilişki yoktur. Bu yaygın inanış nedeniyle çekilmesi gereken bir dişi bekletmek, enfeksiyonun yayılmasına yol açabilir.$q$, 6),
  ('yetiskin', null, $q$Ağrı yoksa dişlerde sorun yoktur.$q$, $q$Çürük ve diş eti hastalıkları çoğu zaman uzun süre ağrısız ilerler. Ağrı başladığında tedavi genellikle daha kapsamlı olur; bu yüzden düzenli kontrol önemlidir.$q$, 7),
  ('yetiskin', null, $q$Beyazlatma dişin minesine zarar verir.$q$, $q$Hekim kontrolünde uygulanan beyazlatma mineye zarar vermez. Geçici hassasiyet görülebilir. Dolgu ve kaplamaların rengi değişmediği için önce uygunluk değerlendirilir.$q$, 8),
  ('yetiskin', null, $q$Kanal tedavisi çok acı verir.$q$, $q$Kanal tedavisi lokal anestezi altında yapılır ve çoğu zaman var olan ağrıyı gideren tedavidir. Sonrasında birkaç gün hafif hassasiyet olabilir.$q$, 9),
  ('yetiskin', null, $q$İmplant herkese yapılamaz, yaşlılara hiç yapılmaz.$q$, $q$İmplant için belirleyici olan yaş değil; genel sağlık durumu ve çene kemiğinin uygunluğudur. Uygunluk muayene ve görüntüleme ile değerlendirilir.$q$, 10)
) v(clinic, emoji, myth, truth, sort_order)
where not exists (select 1 from myths);
