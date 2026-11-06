-- =====================================================================
-- Klinikids — Hekim sertifikaları (admin panelinden yükle / düzenle / sil)
-- Supabase → SQL Editor → bu dosyanın tamamını yapıştır → Run
-- 01_schema.sql'den SONRA, bir kez çalıştırılır. Tekrar çalıştırmak zararsızdır.
--
-- Kim ne yapabilir:
--   • Doktor: sadece KENDİ sertifikalarını ekler, düzenler, siler
--   • Admin : tüm doktorların sertifikalarını yönetir
--   • Site  : aktif sertifikaları herkes görür (hekim kartlarında)
-- =====================================================================

create table if not exists doctor_certificates (
  id         uuid primary key default gen_random_uuid(),
  doctor_id  uuid not null references doctors(id) on delete cascade,
  title      text not null check (length(trim(title)) >= 2),   -- ör. "İmplantoloji Sertifika Programı"
  issuer     text,                                             -- veren kurum
  year       int  check (year between 1950 and 2100),
  file_path  text not null,                                    -- sertifikalar klasöründeki dosya: <doktor_id>/<dosya>
  file_type  text not null default 'image' check (file_type in ('image','pdf')),
  sort_order int  not null default 0,
  is_active  boolean not null default true,
  created_at timestamptz not null default now()
);
create index if not exists doctor_certificates_doc_idx on doctor_certificates (doctor_id, sort_order);

alter table doctor_certificates enable row level security;

drop policy if exists certs_read   on doctor_certificates;
drop policy if exists certs_manage on doctor_certificates;
create policy certs_read on doctor_certificates for select to anon, authenticated
  using (is_active or private.is_staff());
create policy certs_manage on doctor_certificates for all to authenticated
  using      (private.current_staff_role() = 'admin' or doctor_id = private.current_staff_doctor_id())
  with check (private.current_staff_role() = 'admin' or doctor_id = private.current_staff_doctor_id());

-- Dosyalar için herkese açık klasör (en fazla 5 MB; JPG, PNG, WEBP, PDF)
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('sertifikalar', 'sertifikalar', true, 5242880,
        array['image/png','image/jpeg','image/webp','application/pdf'])
on conflict (id) do nothing;

-- Doktor yalnızca kendi klasörüne (<doktor_id>/...) yazar; admin her yere
drop policy if exists sertifika_insert on storage.objects;
drop policy if exists sertifika_update on storage.objects;
drop policy if exists sertifika_delete on storage.objects;
create policy sertifika_insert on storage.objects for insert to authenticated
  with check (bucket_id = 'sertifikalar' and (private.current_staff_role() = 'admin'
              or (storage.foldername(name))[1] = private.current_staff_doctor_id()::text));
create policy sertifika_update on storage.objects for update to authenticated
  using (bucket_id = 'sertifikalar' and (private.current_staff_role() = 'admin'
         or (storage.foldername(name))[1] = private.current_staff_doctor_id()::text));
create policy sertifika_delete on storage.objects for delete to authenticated
  using (bucket_id = 'sertifikalar' and (private.current_staff_role() = 'admin'
         or (storage.foldername(name))[1] = private.current_staff_doctor_id()::text));
