# Klinikids — Web sitesi ve randevu sistemi

Site, randevu sistemi ve yönetim paneli **tamamen kendi sunucunuzda** (Plesk, PHP 8.2 + MySQL) çalışır.
Supabase'e bağımlılık yoktur. Hasta verileri Türkiye'deki sunucuda tutulur.

## Klasör yapısı

| Klasör | Ne var | Web'den erişilir mi? |
|---|---|---|
| `public_html/` | Site: sayfalar, js/css, fotoğraflar, `api/` (tek giriş noktası), `uploads/` (yüklenen dosyalar) | ✅ Sitenin kökü |
| `app/` | PHP arka uç: veritabanı şeması, yetki kuralları, randevu mantığı, giriş, e-posta, kurulum | ❌ Web kökünün dışında |
| `app/config.php` | Veritabanı ve e-posta ayarları (kurulum sayfası oluşturur, **git'e girmez**) | ❌ |
| `veritabani/` | Eski Supabase sürümünün SQL dosyaları (arşiv) | ❌ |

## Nasıl çalışır

- `public_html/api/index.php` tüm istekleri karşılar; asıl kod `app/` içinde.
- API, Supabase'in adres biçimini taklit eder (`/api/rest/v1/doctors?select=...`). Bu sayede site ve
  panel kodu neredeyse değişmeden taşındı; panel `kk-client.js` ile çalışır.
- Yetki kuralları (`app/policy.php`) eski Supabase RLS kurallarının birebir karşılığıdır:
  ziyaretçi yalnızca aktif içerikleri görür; hasta/randevu verisini yalnızca personel görür;
  doktor yalnızca kendi randevu ve hastalarını görür.
- Oturum, JavaScript'in okuyamadığı güvenli bir çerezde tutulur. Şifreler bcrypt ile saklanır.
- Randevu çakışması, boş saat hesabı ve hız sınırları sunucuda kontrol edilir.
- Yüklenen dosyalar `public_html/uploads/` altında; içerik türü dosyanın kendisinden doğrulanır,
  bu klasörde betik çalışmaz.

## Yayın (Plesk + GitHub)

Plesk → Git, `main` dalını `/httpdocs` klasörüne çeker; sitenin kökü `httpdocs/public_html`.
Değişiklik yayına almak: GitHub'a gönder → Plesk → Git → **Pull now**.
`app/config.php` ve `public_html/uploads/` git dışında olduğu için güncellemelerde silinmez.

## İlk kurulum (bir kez)

1. Plesk → **Databases** → **Add Database**: veritabanı adı, kullanıcı adı ve şifre belirleyin.
2. `https://www.klinikids.com.tr/api/kurulum` adresini açın; kurulum anahtarını ve veritabanı bilgilerini girin.
   Tablolar oluşturulur, veriler Supabase'ten tek seferde aktarılır (yönetici şifresi aynen korunur).
3. `https://www.klinikids.com.tr/api/kurulum/eposta` (yönetici girişiyle): e-posta gönderim ayarları
   (SMTP, ör. Yandex posta kutusu, ya da Resend). "Test e-postası gönder" ile deneyin.

## Yedekleme

Yönetim paneli → **Yedekleme** sayfası (yalnızca yönetici):

- **Yedek şifresi**: yedekler AES-256-GCM ile şifrelenir (anahtar PBKDF2-SHA256, 300.000 tur). Sunucuda şifrenin kendisi değil türetilmiş anahtar saklanır (`app/config.php`). Şifre unutulursa yedekler açılamaz.
- **Gece yedeği**: `app/yedek/` klasörüne (web'den erişilemez), son 30 gün saklanır.
- **Aylık rapor**: her ayın 1'inde (kaçarsa 2–3'ünde) geçen ayın özeti + özet CSV + şifreli tam yedek (`.kkyedek`) klinik e-postasına gider. Hasta adı içeren listeler e-postaya konmaz.
- **Geri yükleme**: `.kkyedek` dosyası + şifre → önce önizleme, onaydan sonra yalnızca **eksik** kayıtlar eklenir; mevcut kayıtlar değişmez. Öncesinde otomatik güvenlik yedeği alınır.

Zamanlanmış görev (Plesk → **Zamanlanmış Görevler** → PHP betiği çalıştır, her gün 03:00):

```
app/cron.php            # günlük yedek (+ ayın 1'inde rapor)
app/cron.php rapor      # raporu hemen gönder (deneme)
```

Fotoğraflar (`public_html/uploads/`) e-posta yedeğine sığmadığı için ayrıca Plesk → **Backup & Restore** ile yedeklenmeli.

---

# Arşiv: eski Supabase sürümü

Aşağıdaki bilgiler eski Supabase kurulumuna aittir; yeni sürümde kullanılmaz.

## Dosyalar (`veritabani/` klasöründe)

| Dosya | Ne işe yarar |
|---|---|
| `01_schema.sql` | Tablolar, yetkiler (RLS), boş saat hesabı, randevu alma ve iptal fonksiyonları |
| `03_kurumlar.sql` | Anlaşmalı kurumlar tablosu ve logo deposu (admin panelindeki **Kurumlar** sayfası için). Bir kez çalıştır. |
| `04_yanlislar.sql` | “Doğru bilinen yanlışlar” tablosu (admin panelindeki **Doğru Bilinen Yanlışlar** sayfası). ✅ Kuruldu. |
| `05_sertifikalar.sql` | Hekim sertifikaları tablosu ve dosya deposu (panelde **Sertifikalar**; doktor kendi sertifikalarını yönetir). ✅ Kuruldu. |
| `06_hizmet_klinik_ve_mucahit.sql` | Hizmetlere klinik bilgisi (çocuk / yetişkin / ikisi) ve Dt. Mücahit Çağan kaydı. ✅ Kuruldu. |
| `07_site_icerikleri.sql` | Hekim profilleri (kart bilgileri panelden), SSS, galeri, sitede çalışma saatleri/izinler, Uzm. Dt. Duygu Nur Cesur Kocakaya. ✅ Kuruldu. |
| `edge-functions/personel` | Panelden personel hesabı açma / şifre sıfırlama / silme (Edge Function). ✅ Yayında. |
| `08_kvkk.sql` | KVKK aydınlatma metni bölümleri (kvkk.html; panelde **KVKK metinleri**). ✅ Kuruldu. |
| `09_eposta_ve_hekimler.sql` | Arya Hanım'ın yeni özgeçmişi, Duygu Hanım iki klinikte, randevu e-postaları (aysegul@klinikids.company) için tetikleyici, KVKK metnine e-posta eklemeleri. Aşağıdaki **Randevu e-postaları** adımlarıyla kur. |
| `edge-functions/randevu-mail` | Yeni randevu → kliniğe, onaylanınca → hastaya e-posta (Edge Function). |
| `02_seed.sql` | Test için 3 örnek doktor, 4 hizmet ve çalışma saatleri. Gerçek kurulumda çalıştırma. |

## Kurulum

1. [supabase.com](https://supabase.com) → ücretsiz hesap → **New project**. Bölge olarak Türkiye'ye en yakın olanı seç (ör. Frankfurt). Veritabanı şifresini bir yere kaydet.
2. Sol menü **SQL Editor** → `01_schema.sql` içeriğini yapıştır → **Run**.
3. Denemek için `02_seed.sql` dosyasını da aynı şekilde çalıştır.
4. **İlk admin hesabı:** Authentication → Users → **Add user** (e-posta + şifre). Oluşan kullanıcının `id` değerini kopyala, SQL Editor'da çalıştır:

```sql
insert into staff_profiles (user_id, full_name, role)
values ('KOPYALADIĞIN-ID', 'Klinik Yöneticisi', 'admin');
```

Doktor hesabı için `role` = `'doctor'` ve `doctor_id` = doktorun id'si. Sekreter için `'receptionist'`.

## Tablolar

- **doctors** — doktorlar. "Doktor çıkar" = `is_active = false` (geçmiş randevular silinmez).
- **services** — hizmetler ve süreleri (muayene 30 dk, dolgu 60 dk…).
- **doctor_services** — hangi doktor hangi hizmeti veriyor.
- **working_hours** — haftalık saatler. `weekday`: 1 = Pazartesi … 7 = Pazar. Öğle arası için aynı güne iki satır.
- **time_off** — izin, tatil, kongre.
- **patients**, **appointments** — hastalar ve randevular.
- **clinic_settings** — slot aralığı (15 dk), en erken randevu (2 saat sonra), en ileri tarih (60 gün), otomatik onay.

## Kim neyi görür

| | Hasta (sitede) | Sekreter | Doktor | Admin |
|---|---|---|---|---|
| Doktor/hizmet/saat listesi | ✔ | ✔ | ✔ | ✔ |
| Boş saatler, dolu bloklar (isimsiz) | ✔ | ✔ | ✔ | ✔ |
| Randevular ve hasta bilgileri | ✘ | Tümü | Sadece kendi | Tümü |
| Doktor ve hizmet ekleme/çıkarma | ✘ | ✘ | ✘ | ✔ |
| Çalışma saati / izin | ✘ | Tümü | Sadece kendi | Tümü |

## Web sitesinden kullanım (Next.js)

```js
// Boş saatler
const { data } = await supabase.rpc('get_available_slots', {
  p_doctor_id: doktorId, p_service_id: hizmetId, p_date: '2026-11-02'
})

// Randevu al
const { data, error } = await supabase.rpc('book_appointment', {
  p_doctor_id: doktorId, p_service_id: hizmetId, p_starts_at: secilenSaat,
  p_full_name: 'Ali Veli', p_phone: '0532 111 22 33', p_email: 'ali@ornek.com',
  p_kvkk_consent: true, p_note: 'Sol alt dişim ağrıyor'
})
// error.message Türkçe ve hastaya doğrudan gösterilebilir

// SMS'teki linkten iptal
await supabase.rpc('cancel_appointment_by_token', { p_token: linktekiToken })
```

## Test edilenler

Gerçek PostgreSQL 16 üzerinde denendi:

- Boş saatler çalışma saatine, öğle arasına, hizmet süresine, izinlere ve mevcut randevulara göre doğru hesaplanıyor
- Aynı veya çakışan saate ikinci randevu alınamıyor
- İki hasta aynı anda aynı saate tıkladığında sadece biri kaydediliyor
- KVKK onayı olmadan, geçersiz telefonla, doktorun vermediği hizmet için veya öğle arasına randevu alınamıyor
- Hasta, randevu ve hasta tablolarını doğrudan okuyamıyor ve yazamıyor
- Doktor sadece kendi randevularını görüyor, doktor ekleyemiyor
- Telefonla girilen randevunun bitiş saati otomatik hesaplanıyor
- İptal linki çalışıyor, iptal edilen saat tekrar boşa çıkıyor

## Sonraki adımlar

1. Next.js projesi: hasta tarafı randevu ekranı
2. Admin paneli: giriş, takvim (FullCalendar), doktor/hizmet/saat yönetimi
3. SMS hatırlatma (Supabase Edge Function + Netgsm/İleti Merkezi), formda captcha
4. KVKK aydınlatma metni (hukukçu kontrolünde)

## Panelden yönetilenler (tasarımcıya gerek yok)

| Sayfa | Kim | Ne yapar |
|---|---|---|
| Personel | Yönetici | E-posta ile hesap açar; geçici şifreyi WhatsApp'tan gönderir; şifre sıfırlar |
| Doktorlar | Yönetici | Hekim ekler, hangi klinikte (çocuk / yetişkin / ikisi) görüneceğini seçer, hizmet ve saat atar |
| Site profili | Doktor (kendi), Yönetici (hepsi) | Fotoğraf, tanıtım, öne çıkanlar, etiketler, eğitim, deneyim, hobiler |
| Sertifikalar | Doktor (kendi), Yönetici (hepsi) | Sertifika yükler / siler |
| Doktorlar → Saatler, İzinler | Doktor (kendi), Sekreter, Yönetici | Çalışma saatleri, izin, resmi tatil (sitede görünür) |
| SSS, Doğru Bilinen Yanlışlar, Galeri, Kurumlar, KVKK metinleri | Yönetici | Ekle / düzenle / sırala / sil |


## Randevu e-postaları (aysegul@klinikids.company)

- Siteden yeni randevu gelince **kliniğe** e-posta gider (hasta, telefon, hekim, işlem, tarih, not).
- Randevu panelden **Onayla** ile onaylanınca, hasta e-posta yazdıysa **hastaya** "Randevunuz onaylandı" e-postası gider.
- Hasta iptal linkinden iptal ederse kliniğe e-posta gider. Personelin panelden yaptığı iptal bildirilmez.
- E-posta gönderilemese bile randevu kaydı asla engellenmez.

**Kurulum (bir kez):**
1. Supabase → **Edge Functions** → **Deploy a new function** → adı `randevu-mail`, içerik `veritabani/edge-functions/randevu-mail/index.ts`.
   Fonksiyon ayarlarında **Verify JWT / Enforce JWT verification = KAPALI** (veritabanı çağırır; kendi gizli anahtarıyla korunur).
2. Edge Functions → **Secrets** bölümüne ekle:
   - `SMTP_HOST` = e-posta sağlayıcınızın giden sunucusu (ör. mail.klinikids.company)
   - `SMTP_PORT` = `465` (SSL). Supabase 25 ve 587 portlarına izin vermez.
   - `SMTP_USER` = `aysegul@klinikids.company`
   - `SMTP_PASS` = bu posta kutusunun şifresi
   - (Alternatif) `RESEND_API_KEY` girilirse SMTP yerine Resend kullanılır (alan adı doğrulaması gerekir).
3. SQL Editor'da `09_eposta_ve_hekimler.sql` → **Run**.
4. Test: sitede kendi e-postanızla randevu alın, panelden onaylayın. Sonuçlar:
   `select status_code, content, created from net._http_response order by created desc limit 10;`
