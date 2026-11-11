// Klinikids — Randevu e-postaları
//   • Siteden yeni randevu gelince  → kliniğe bildirim (aysegul@klinikids.company)
//   • Randevu onaylanınca           → hastaya "Randevunuz onaylandı" e-postası (e-posta yazdıysa)
//   • Hasta linkten iptal edince     → kliniğe bildirim
// Veritabanındaki tetikleyici (09_eposta_ve_hekimler.sql) çağırır; dışarıdan çağrılamaz
// (gizli anahtar veritabanında; fonksiyon mail_hook_secret() ile kendisi okur).
//
// Gerekli gizli değerler (Supabase → Edge Functions → Secrets):
//   SMTP_HOST, SMTP_PORT (465), SMTP_USER, SMTP_PASS : aysegul@klinikids.company posta kutusunun giden sunucu bilgileri
//   (İsteğe bağlı) RESEND_API_KEY : SMTP yerine Resend kullanılacaksa
//   (İsteğe bağlı) CLINIC_MAIL, MAIL_FROM
import { createClient } from 'npm:@supabase/supabase-js@2';
import { SMTPClient } from 'https://deno.land/x/denomailer@1.6.0/mod.ts';

const CLINIC_MAIL = Deno.env.get('CLINIC_MAIL') || 'aysegul@klinikids.company';
const MAIL_FROM = Deno.env.get('MAIL_FROM') || `Klinikids <${CLINIC_MAIL}>`;
const SITE = 'https://www.klinikids.com.tr';
const TEL = '0546 421 71 41';
const TZ = 'Europe/Istanbul';

const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));
const when = (iso: string) => new Intl.DateTimeFormat('tr-TR', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
const phoneFmt = (p: string) => p && p.length === 10 ? `0${p.slice(0, 3)} ${p.slice(3, 6)} ${p.slice(6, 8)} ${p.slice(8)}` : p;
const ok = (b: unknown, status = 200) => new Response(JSON.stringify(b), { status, headers: { 'Content-Type': 'application/json' } });

async function send(to: string, subject: string, html: string, replyTo?: string) {
  const text = html.replace(/<br\s*\/?>/g, '\n').replace(/<\/(p|tr|h2|div)>/g, '\n').replace(/<[^>]+>/g, '').replace(/&amp;/g, '&').replace(/\n{3,}/g, '\n\n').trim();
  const resend = Deno.env.get('RESEND_API_KEY');
  if (resend) {
    const r = await fetch('https://api.resend.com/emails', {
      method: 'POST', headers: { Authorization: `Bearer ${resend}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ from: MAIL_FROM, to: [to], subject, html, text, ...(replyTo ? { reply_to: replyTo } : {}) }),
    });
    if (!r.ok) throw new Error('Resend: ' + await r.text());
    return;
  }
  const client = new SMTPClient({
    connection: {
      hostname: Deno.env.get('SMTP_HOST')!, port: Number(Deno.env.get('SMTP_PORT') || 465), tls: true,
      auth: { username: Deno.env.get('SMTP_USER') || CLINIC_MAIL, password: Deno.env.get('SMTP_PASS')! },
    },
  });
  try { await client.send({ from: MAIL_FROM, to, subject, content: text, html, ...(replyTo ? { replyTo } : {}) }); }
  finally { await client.close(); }
}

const shell = (title: string, body: string) => `<!doctype html><html lang="tr"><body style="margin:0;background:#F5F7F8;font-family:Arial,Helvetica,sans-serif;color:#1B2533">
<div style="max-width:560px;margin:0 auto;padding:24px 16px"><div style="background:#fff;border-radius:16px;padding:28px 24px;border:1px solid #E6EAEE">
<div style="font-weight:800;font-size:20px;color:#1E5F5B;margin-bottom:4px">Klinikids</div>
<h2 style="margin:0 0 16px;font-size:20px">${title}</h2>${body}</div>
<p style="font-size:12px;color:#7A8592;text-align:center;margin-top:14px">Özel Klinikids Ağız ve Diş Sağlığı Polikliniği · Mehmet Ali Paşa Mah. Erkan Sok. RBG İş Merkezi No 7B, İzmit / Kocaeli · ${TEL}</p></div></body></html>`;
const table = (rows: [string, string][]) => `<table style="width:100%;border-collapse:collapse;font-size:15px">${rows.filter(r => r[1]).map(([k, v]) =>
  `<tr><td style="padding:8px 0;color:#6B7684;width:110px;vertical-align:top">${esc(k)}</td><td style="padding:8px 0;font-weight:700">${esc(v)}</td></tr>`).join('')}</table>`;

Deno.serve(async (req) => {
  if (req.method !== 'POST') return ok({ error: 'method' }, 405);
  const db = createClient(Deno.env.get('SUPABASE_URL')!, Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!, { auth: { persistSession: false } });
  const { data: secret } = await db.rpc('mail_hook_secret');
  const got = req.headers.get('x-hook-secret') || '';
  if (!secret || got.length !== String(secret).length || got !== secret) return ok({ error: 'yetkisiz' }, 401);
  try {
    const { appointment_id, event } = await req.json();
    const { data: a, error } = await db.from('appointments')
      .select('id,starts_at,status,source,patient_note,custom_service_name, patients(full_name,phone,email), doctors(title,full_name,clinic), services(name)')
      .eq('id', appointment_id).maybeSingle();
    if (error) throw error;
    if (!a) return ok({ skipped: 'bulunamadı' });
    const p: any = a.patients || {}, d: any = a.doctors || {}, s: any = a.services || {};
    const doc = `${d.title || 'Dt.'} ${d.full_name || ''}`.trim();
    const svc = s.name || a.custom_service_name || '';
    const clinic = d.clinic === 'cocuk' ? 'Çocuk Kliniği' : d.clinic === 'yetiskin' ? 'Yetişkin Kliniği' : 'Klinikids';
    const sent: string[] = [];

    // 1) Kliniğe: yeni online randevu / iptal
    if ((event === 'insert' && a.source === 'online') || event === 'cancelled') {
      const isNew = event === 'insert';
      const subj = isNew ? `Yeni online randevu${a.status === 'pending' ? ' (onay bekliyor)' : ''}: ${p.full_name} · ${when(a.starts_at)}`
                         : `Randevu iptal edildi: ${p.full_name} · ${when(a.starts_at)}`;
      const body = table([['Hasta', p.full_name], ['Telefon', phoneFmt(p.phone)], ['E-posta', p.email || ''], ['Hekim', doc], ['Klinik', clinic],
        ['İşlem', svc], ['Tarih', when(a.starts_at)], ['Not', a.patient_note || '']]) +
        (isNew && a.status === 'pending' ? `<p style="margin-top:18px">Onaylamak için <a href="${SITE}/admin.html" style="color:#1E5F5B;font-weight:700">yönetim paneline</a> girin. Onaylandığında hastaya otomatik e-posta gider${p.email ? '' : ' (hasta e-posta yazmadı, telefonla teyit edin)'}.</p>` : '');
      await send(CLINIC_MAIL, subj, shell(isNew ? 'Yeni randevu talebi' : 'Randevu iptal edildi', body), p.email || undefined);
      sent.push('klinik');
    }

    // 2) Hastaya: randevu onaylandı
    if (a.status === 'confirmed' && (event === 'confirmed' || event === 'insert') && p.email) {
      const body = `<p>Merhaba ${esc(p.full_name)},</p><p>Randevunuz <b>onaylandı</b>. Sizi bekliyoruz!</p>` +
        table([['Hekim', doc], ['Klinik', clinic], ['İşlem', svc], ['Tarih', when(a.starts_at)]]) +
        `<p style="margin-top:18px">Adres: Mehmet Ali Paşa Mah. Erkan Sok. RBG İş Merkezi No 7B, İzmit / Kocaeli</p>
         <p>Gelemeyecekseniz lütfen en kısa sürede <a href="tel:+905464217141">${TEL}</a> numarasından ya da bu e-postayı yanıtlayarak bize haber verin.</p>`;
      await send(p.email, `Randevunuz onaylandı · ${when(a.starts_at)}`, shell('Randevunuz onaylandı ✅', body), CLINIC_MAIL);
      sent.push('hasta');
    }
    return ok({ sent });
  } catch (e) {
    console.error(e);
    return ok({ error: String((e as Error).message || e) }, 500);
  }
});
