-- =====================================================================
-- Klinikids — 08: KVKK metinleri (kvkk.html sayfası; admin panelinden düzenlenir)
-- Tekrar çalıştırmak zararsızdır.
-- =====================================================================
create table if not exists legal_sections (
  id         uuid primary key default gen_random_uuid(),
  slug       text unique,
  title      text not null check (length(trim(title)) >= 2),
  body       text not null default '',
  sort_order int  not null default 0,
  is_active  boolean not null default true,
  updated_at timestamptz not null default now()
);
alter table legal_sections enable row level security;
drop policy if exists legal_read  on legal_sections;
drop policy if exists legal_admin on legal_sections;
create policy legal_read  on legal_sections for select to anon, authenticated using (is_active or private.is_staff());
create policy legal_admin on legal_sections for all to authenticated
  using (private.current_staff_role() = 'admin') with check (private.current_staff_role() = 'admin');
insert into legal_sections (slug, title, body, sort_order, is_active)
select * from (values
  ($k$veri-sorumlusu$k$, $k$Veri sorumlusu ve kapsam$k$, $k$Bu aydınlatma metni, 6698 sayılı Kişisel Verilerin Korunması Kanunu'nun (KVKK) 10. maddesi uyarınca, veri sorumlusu sıfatıyla **Özel Klinikids Ağız ve Diş Sağlığı Polikliniği** (bundan sonra “Klinik”) tarafından hazırlanmıştır. Çocuk Kliniğimiz ve Yetişkin Kliniğimiz (İzmit Diş Sanatı) aynı veri sorumlusu bünyesinde hizmet verir.

- **Adres:** Mehmet Ali Paşa Mahallesi, Erkan Sokak, RBG İş Merkezi No 7B, İzmit / Kocaeli
- **Telefon:** 0546 421 71 41

Çocuk hastalarımıza ait kişisel veriler, veli veya yasal temsilci aracılığıyla ve onun bilgisi dahilinde işlenir. Bu metinde “siz” ifadesi, çocuk hastalar söz konusu olduğunda veli veya yasal temsilciyi de kapsar.$k$, 1, true),
  ($k$islenen-veriler$k$, $k$Hangi kişisel verileri işliyoruz?$k$, $k$- **Kimlik bilgileri:** ad soyad, yaş veya doğum tarihi; çocuk hastalarda velinin adı soyadı
- **İletişim bilgileri:** telefon numarası, e-posta ve adres
- **Sağlık verileri (özel nitelikli kişisel veri):** şikâyetiniz, muayene bulguları, tedavi planı ve kayıtları, röntgen ve ağız içi fotoğraflar, ilaç, alerji ve sağlık geçmişi bilgileri
- **Randevu bilgileri:** randevu tarihi ve saati, seçtiğiniz hekim ve işlem, randevu formuna yazdığınız not
- **Finansal bilgiler:** ödeme ve fatura bilgileri, anlaşmalı kurum veya sigorta bilgileri
- **Görsel kayıtlar:** varsa güvenlik kamerası görüntüleri
- **İşlem güvenliği bilgileri:** internet sitemizi kullanırken oluşan teknik kayıtlar (IP adresi, tarayıcı bilgisi gibi)$k$, 2, true),
  ($k$amaclar$k$, $k$Kişisel verilerinizi hangi amaçlarla işliyoruz?$k$, $k$- Muayene, teşhis, tedavi ve bakım hizmetlerinin yürütülmesi
- Randevu oluşturulması, teyit edilmesi, hatırlatılması ve değişiklikler için sizinle iletişime geçilmesi
- Sağlık mevzuatından doğan kayıt tutma ve bildirim yükümlülüklerinin yerine getirilmesi
- Faturalandırma, ödeme, anlaşmalı kurum ve sigorta işlemlerinin yürütülmesi
- Hasta güvenliğinin ve hizmet kalitesinin sağlanması, öneri ve şikâyetlerin değerlendirilmesi
- Klinik ve bina güvenliğinin sağlanması
- Yetkili kurum ve kuruluşlar ile yargı mercilerinden gelen taleplerin karşılanması$k$, 3, true),
  ($k$hukuki-sebep$k$, $k$Toplama yöntemi ve hukuki sebepler$k$, $k$Kişisel verileriniz; internet sitemizdeki randevu formu, telefon ve WhatsApp görüşmeleri, klinikte doldurulan formlar, muayene ve tedavi süreçleri ile tetkikler aracılığıyla sözlü, yazılı ve elektronik yollarla toplanır.

Bu veriler KVKK'nın 5. maddesinde yer alan şu hukuki sebeplere dayanılarak işlenir:

- Kanunlarda açıkça öngörülmesi ve hukuki yükümlülüklerimizin yerine getirilmesi
- Sağlık hizmeti sözleşmesinin kurulması ve ifası
- Bir hakkın tesisi, kullanılması veya korunması
- Temel hak ve özgürlüklerinize zarar vermemek kaydıyla Kliniğin meşru menfaati

Sağlık verileriniz, KVKK'nın 6. maddesinde özel nitelikli kişisel veriler için öngörülen şartlara uygun olarak; tıbbi teşhis, tedavi ve bakım hizmetlerinin yürütülmesi amacıyla, sır saklama yükümlülüğü altındaki hekim ve sağlık personeli eliyle ve gerektiğinde açık rızanıza dayanılarak işlenir.$k$, 4, true),
  ($k$aktarim$k$, $k$Kişisel verileriniz kimlere aktarılır?$k$, $k$- Sağlık Bakanlığı ve bağlı kuruluşlar ile Sosyal Güvenlik Kurumu gibi yetkili kamu kurum ve kuruluşlarına, yasal yükümlülüklerimiz kapsamında
- Talep hâlinde yargı mercileri ve yetkili kolluk kuvvetlerine
- Tedavinizin gerektirdiği durumlarda diş protez laboratuvarları ve görüntüleme merkezlerine, yalnızca gerekli bilgilerle sınırlı olarak
- Talebiniz ve sözleşmeniz kapsamında anlaşmalı kurumlar ile özel sigorta şirketlerine
- Muhasebe, hukuk ve bilgi teknolojileri alanında hizmet aldığımız iş ortaklarına, gizlilik yükümlülüğü altında

Online randevu sistemimiz ve internet sitemize ait veriler, sunucuları yurt dışında bulunan bulut hizmet sağlayıcılarında barındırılmaktadır. Bu aktarım, KVKK'nın 9. maddesine uygun olarak gerçekleştirilir; online randevu oluştururken bu konuda açık rızanız alınır. WhatsApp üzerinden bizimle iletişime geçtiğinizde mesajlarınız ilgili platformun altyapısı üzerinden iletilir.$k$, 5, true),
  ($k$haklar$k$, $k$Haklarınız ve başvuru yöntemi$k$, $k$KVKK'nın 11. maddesi uyarınca Kliniğe başvurarak:

- Kişisel verilerinizin işlenip işlenmediğini öğrenme,
- İşlenmişse buna ilişkin bilgi talep etme,
- İşlenme amacını ve bunların amacına uygun kullanılıp kullanılmadığını öğrenme,
- Yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme,
- Eksik veya yanlış işlenmişse düzeltilmesini isteme,
- KVKK'nın 7. maddesinde öngörülen şartlar çerçevesinde silinmesini veya yok edilmesini isteme,
- Düzeltme, silme ve yok etme işlemlerinin verilerin aktarıldığı üçüncü kişilere bildirilmesini isteme,
- Münhasıran otomatik sistemler vasıtasıyla analiz edilmesi suretiyle aleyhinize bir sonucun ortaya çıkmasına itiraz etme,
- Kanuna aykırı olarak işlenmesi sebebiyle zarara uğramanız hâlinde zararın giderilmesini talep etme

haklarına sahipsiniz.

## Nasıl başvurabilirsiniz?

Başvurunuzu, kimliğinizi tespit etmeye yarayan bilgilerle birlikte yazılı olarak klinik adresimize elden teslim edebilir veya noter aracılığıyla gönderebilirsiniz. Kayıtlı elektronik posta (KEP), güvenli elektronik imza ya da daha önce bize bildirdiğiniz ve sistemimizde kayıtlı e-posta adresiniz üzerinden de başvurabilirsiniz.

Başvurunuz, talebin niteliğine göre en kısa sürede ve en geç 30 gün içinde ücretsiz olarak sonuçlandırılır. İşlemin ayrıca bir maliyet gerektirmesi hâlinde Kişisel Verileri Koruma Kurulu tarafından belirlenen tarife uygulanabilir. Çocuk hastalara ilişkin başvurular veli veya yasal temsilci tarafından yapılır.$k$, 6, true),
  ($k$saklama-imha$k$, $k$Saklama ve imha$k$, $k$Kişisel verileriniz, işlendikleri amacın gerektirdiği ve ilgili mevzuatta öngörülen süre boyunca saklanır; bu sürenin sonunda silinir, yok edilir veya anonim hâle getirilir.

- **Muayene ve tedavi kayıtları, röntgenler ve diğer sağlık verileri:** sağlık mevzuatında öngörülen süreler boyunca
- **Fatura ve muhasebe kayıtları:** Vergi Usul Kanunu ve Türk Ticaret Kanunu'nda öngörülen süreler boyunca
- **Online randevu talepleri ve iletişim kayıtları:** randevu tarihinden itibaren en fazla 2 yıl
- **Güvenlik kamerası kayıtları:** varsa, kamera kayıtları bölümünde belirtilen süre boyunca

Saklama süresi dolan veriler; kâğıt kayıtlarda kırpma veya imha, elektronik kayıtlarda geri getirilemeyecek şekilde silme ya da anonim hâle getirme yöntemleriyle, periyodik imha işlemleri kapsamında yok edilir. Verilerinizin güvenliği için yetkisiz erişime, kayba ve kötüye kullanıma karşı gerekli teknik ve idari tedbirleri alırız.$k$, 7, true),
  ($k$kamera$k$, $k$Güvenlik kamerası kayıtları$k$, $k$Klinik ve bina güvenliğinin sağlanması amacıyla giriş, bekleme salonu ve koridor gibi ortak alanlarda güvenlik kamerası bulunmaktadır. Muayene ve tedavi odalarında, tuvaletlerde ve soyunma alanlarında kamera bulunmaz.

Kayıtlara yalnızca yetkili personel erişebilir; kayıtlar 30 gün süreyle saklandıktan sonra otomatik olarak silinir. Bir olayın incelenmesi gerektiğinde ilgili kayıt, yalnızca yetkili mercilerin talebi üzerine paylaşılır. Kamera bulunan alanlarda bilgilendirme levhaları yer alır.$k$, 8, false),
  ($k$internet-sitesi$k$, $k$İnternet sitemiz ve çerezler$k$, $k$İnternet sitemiz reklam veya ziyaretçi takibi amaçlı çerez kullanmaz. Çocuk Kliniği sayfamızdaki oyunun yıldız puanı yalnızca kendi tarayıcınızda saklanır ve bize iletilmez.

Sitenin çalışması için gerekli teknik kayıtlar (ör. sayfa isteklerine ait IP adresi ve tarayıcı bilgisi) barındırma hizmeti sağlayıcılarımız tarafından güvenlik amacıyla tutulabilir. Sayfalarımızda yer alan Google Haritalar ve yazı tipleri gibi üçüncü taraf içerikler, ilgili hizmet sağlayıcıların kendi gizlilik politikalarına tabidir.$k$, 9, true)
) v(slug, title, body, sort_order, is_active)
where not exists (select 1 from legal_sections);
