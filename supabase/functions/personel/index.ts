// Klinikids — Personel hesapları (sadece yönetici çağırabilir)
// İşlemler: create (hesap aç + geçici şifre), reset (yeni geçici şifre), delete (hesabı sil), emails (e-posta listesi)
import { createClient } from 'npm:@supabase/supabase-js@2';

const cors = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
  'Access-Control-Allow-Methods': 'POST, OPTIONS',
};
const json = (b: unknown, status = 200) =>
  new Response(JSON.stringify(b), { status, headers: { ...cors, 'Content-Type': 'application/json' } });

// Okunması kolay geçici şifre (karışan harfler yok): ör. Kk-7m4P-q9Xt
function tempPassword() {
  const A = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
  const r = crypto.getRandomValues(new Uint8Array(8));
  const s = [...r].map(x => A[x % A.length]).join('');
  return `Kk-${s.slice(0, 4)}-${s.slice(4)}`;
}

Deno.serve(async (req) => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: cors });
  try {
    const admin = createClient(Deno.env.get('SUPABASE_URL')!, Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!, {
      auth: { persistSession: false, autoRefreshToken: false },
    });
    const jwt = (req.headers.get('Authorization') || '').replace(/^Bearer\s+/i, '');
    const { data: { user } } = await admin.auth.getUser(jwt);
    if (!user) return json({ error: 'Oturumunuz bulunamadı. Lütfen yeniden giriş yapın.' }, 401);
    const { data: me } = await admin.from('staff_profiles').select('role').eq('user_id', user.id).maybeSingle();
    if (me?.role !== 'admin') return json({ error: 'Bu işlem için yönetici yetkisi gerekir.' }, 403);

    const b = await req.json().catch(() => ({}));

    if (b.action === 'emails') {
      const { data, error } = await admin.auth.admin.listUsers({ perPage: 1000 });
      if (error) throw error;
      const map: Record<string, { email?: string; last?: string | null; pending: boolean }> = {};
      data.users.forEach(u => map[u.id] = { email: u.email, last: u.last_sign_in_at ?? null, pending: !!u.user_metadata?.must_change_password });
      return json({ users: map });
    }

    if (b.action === 'create') {
      const email = String(b.email || '').trim().toLowerCase();
      const full_name = String(b.full_name || '').trim();
      const role = String(b.role || '');
      const doctor_id = role === 'doctor' ? (b.doctor_id || null) : null;
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return json({ error: 'Geçerli bir e-posta adresi girin.' }, 400);
      if (full_name.length < 2) return json({ error: 'Ad soyad girin.' }, 400);
      if (!['admin', 'receptionist', 'doctor'].includes(role)) return json({ error: 'Rol seçin.' }, 400);
      if (role === 'doctor' && !doctor_id) return json({ error: 'Doktor hesabı için bağlı doktoru seçin.' }, 400);
      const password = tempPassword();
      const { data, error } = await admin.auth.admin.createUser({
        email, password, email_confirm: true, user_metadata: { full_name, must_change_password: true },
      });
      if (error) {
        const dup = /already|registered|exists/i.test(error.message);
        return json({ error: dup ? 'Bu e-posta adresiyle zaten bir hesap var.' : error.message }, 400);
      }
      const { error: e2 } = await admin.from('staff_profiles').insert({ user_id: data.user.id, full_name, role, doctor_id });
      if (e2) { await admin.auth.admin.deleteUser(data.user.id); return json({ error: e2.message }, 400); }
      return json({ ok: true, email, password });
    }

    if (b.action === 'reset') {
      const password = tempPassword();
      const { data: u, error: e0 } = await admin.auth.admin.getUserById(b.user_id);
      if (e0 || !u.user) return json({ error: 'Hesap bulunamadı.' }, 404);
      const { error } = await admin.auth.admin.updateUserById(b.user_id, {
        password, user_metadata: { ...u.user.user_metadata, must_change_password: true },
      });
      if (error) throw error;
      return json({ ok: true, email: u.user.email, password });
    }

    if (b.action === 'delete') {
      if (b.user_id === user.id) return json({ error: 'Kendi hesabınızı silemezsiniz.' }, 400);
      await admin.from('staff_profiles').delete().eq('user_id', b.user_id);
      const { error } = await admin.auth.admin.deleteUser(b.user_id);
      if (error) throw error;
      return json({ ok: true });
    }

    return json({ error: 'Bilinmeyen işlem.' }, 400);
  } catch (e) {
    return json({ error: String((e as Error)?.message || e) }, 500);
  }
});
