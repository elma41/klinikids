-- =====================================================================
-- Klinikids — Anlaşmalı kurumlar (admin panelinden logo ekle / çıkar)
-- Supabase → SQL Editor → bu dosyanın tamamını yapıştır → Run
-- 01_schema.sql'den SONRA, bir kez çalıştırılır.
-- =====================================================================

create table if not exists institutions (
  id         uuid primary key default gen_random_uuid(),
  name       text not null check (length(trim(name)) >= 2),
  logo_path  text,                         -- kurum-logolari klasöründeki dosya adı
  sort_order int  not null default 0,
  is_active  boolean not null default true,
  created_at timestamptz not null default now()
);

alter table institutions enable row level security;

-- Herkes aktif kurumları görür; personel pasifleri de görür; sadece admin değiştirir
drop policy if exists institutions_read  on institutions;
drop policy if exists institutions_admin on institutions;
create policy institutions_read  on institutions for select to anon, authenticated using (is_active or private.is_staff());
create policy institutions_admin on institutions for all to authenticated
  using (private.current_staff_role() = 'admin') with check (private.current_staff_role() = 'admin');

-- Logolar için herkese açık depolama klasörü (en fazla 2 MB; PNG, JPG, WEBP, SVG)
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('kurum-logolari', 'kurum-logolari', true, 2097152,
        array['image/png','image/jpeg','image/webp','image/svg+xml'])
on conflict (id) do nothing;

drop policy if exists kurum_logo_admin_insert on storage.objects;
drop policy if exists kurum_logo_admin_update on storage.objects;
drop policy if exists kurum_logo_admin_delete on storage.objects;
create policy kurum_logo_admin_insert on storage.objects for insert to authenticated
  with check (bucket_id = 'kurum-logolari' and private.current_staff_role() = 'admin');
create policy kurum_logo_admin_update on storage.objects for update to authenticated
  using (bucket_id = 'kurum-logolari' and private.current_staff_role() = 'admin');
create policy kurum_logo_admin_delete on storage.objects for delete to authenticated
  using (bucket_id = 'kurum-logolari' and private.current_staff_role() = 'admin');
