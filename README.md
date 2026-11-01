# Diş Kliniği Randevu Sistemi — Veritabanı

## Dosyalar

| Dosya | Ne işe yarar |
|---|---|
| `01_schema.sql` | Tablolar, yetkiler (RLS), boş saat hesabı, randevu alma ve iptal fonksiyonları |
| `03_kurumlar.sql` | Anlaşmalı kurumlar tablosu ve logo deposu (admin panelindeki **Kurumlar** sayfası için). Bir kez çalıştır. |
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
