/* =====================================================================
   Klinikids — Online Randevu (çocuk ve yetişkin sayfalarında ortak)
   Kullanım:  <div id="randevuApp" data-clinic="cocuk"></div>   (veya "yetiskin")
              <script src="randevu.js" defer></script>
   Publishable anahtar herkese açık olacak şekilde tasarlanmıştır;
   veriler veritabanındaki güvenlik kurallarıyla korunur.
   ===================================================================== */
(() => {
const SB = 'https://xnjnxogvnldrbridzvqu.supabase.co';
const KEY = 'sb_publishable_CpJXbL8ZBECdSo3uSYXqdg_5W1DStIb';
const WA_PHONE = '905464217141';
const TZ = 'Europe/Istanbul';
const H = { apikey: KEY, 'Content-Type': 'application/json' };

const root = document.getElementById('randevuApp');
if (!root) return;
const CLINIC = root.dataset.clinic === 'cocuk' ? 'cocuk' : 'yetiskin';
const KIDS = CLINIC === 'cocuk';

const get = p => fetch(`${SB}/rest/v1/${p}`, { headers: H }).then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)));
const rpc = (fn, body) => fetch(`${SB}/rest/v1/rpc/${fn}`, { method: 'POST', headers: H, body: JSON.stringify(body) })
  .then(async r => { const j = await r.json().catch(() => null); if (!r.ok) throw new Error((j && j.message) || 'Bir sorun oluştu, lütfen tekrar deneyin.'); return j; });

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const norm = s => String(s || '').toLocaleLowerCase('tr-TR').replace(/\s+/g, ' ').trim();
const todayTZ = () => new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const hm = s => new Intl.DateTimeFormat('tr-TR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' }).format(new Date(s));
const hourOf = s => Number(new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', hourCycle: 'h23' }).format(new Date(s)));
const addD = (s, n) => { const d = new Date(s + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
const dow = s => new Date(s + 'T12:00:00Z').getUTCDay() || 7;
const DOW = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
const MON = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
const dnum = s => +s.slice(8, 10), dmon = s => MON[+s.slice(5, 7) - 1];
const longD = s => new Date(s + 'T12:00:00Z').toLocaleDateString('tr-TR', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' });
const initials = n => norm(n).split(' ').filter(Boolean).slice(0, 2).map(w => w[0].toLocaleUpperCase('tr-TR')).join('');
const docLabel = d => (d.title ? d.title + ' ' : '') + d.full_name;
const svcLabel = n => KIDS ? String(n).replace(/\s*\(Çocuk\)/i, '') : n;
function normPhone(p) { let v = String(p || '').replace(/\D/g, ''); if (/^90\d{10}$/.test(v)) v = v.slice(2); else if (/^0\d{10}$/.test(v)) v = v.slice(1); return /^5\d{9}$/.test(v) ? v : null; }
const waLink = t => `https://wa.me/${WA_PHONE}?text=${encodeURIComponent(t)}`;
const WA_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91A9.85 9.85 0 0 0 12.04 2Zm5.8 14.06c-.24.68-1.41 1.3-1.95 1.35-.5.05-.97.23-3.27-.68-2.77-1.09-4.52-3.92-4.66-4.1-.13-.18-1.11-1.48-1.11-2.82s.7-2 .95-2.27c.25-.27.54-.34.72-.34h.52c.17 0 .39-.06.61.47.23.54.77 1.88.84 2.02.07.13.11.29.02.47-.09.18-.13.29-.27.45-.13.16-.28.35-.4.47-.13.13-.27.28-.12.55.16.27.7 1.15 1.5 1.86 1.03.92 1.9 1.2 2.17 1.34.27.13.43.11.59-.07.16-.18.68-.79.86-1.06.18-.27.36-.23.61-.14.25.09 1.58.75 1.85.88.27.14.45.2.52.32.07.11.07.66-.17 1.33Z"/></svg>';
const WA_TXT = KIDS ? 'Merhaba Klinikids! Çocuğum için randevu almak istiyorum 😊' : 'Merhaba Klinikids! Randevu almak istiyorum.';

/* ---------- iskelet ---------- */
root.classList.add('rb');
root.innerHTML = `
  <div class="rb-loading" data-el="loading">Takvim yükleniyor…</div>
  <form data-el="body" novalidate hidden><div class="rb-cols"><div class="rb-colA">
    <div class="rb-step" data-step="doc"><span class="rb-lbl"><b class="rb-n">1</b> Hekim seçin</span><div class="rb-docs" data-el="docs"></div></div>
    <div class="rb-step" data-step="svc"><span class="rb-lbl"><b class="rb-n">2</b> İşlem</span><div class="rb-chips" data-el="svcs"></div></div>
    <div class="rb-step" data-step="day"><span class="rb-lbl"><b class="rb-n">3</b> Gün</span><div data-el="days"></div></div>
    <div class="rb-step" data-step="slot"><span class="rb-lbl"><b class="rb-n">4</b> Saat</span><div data-el="slots"></div></div>
    </div><div class="rb-colB">
    <div class="rb-step" data-step="info"><span class="rb-lbl"><b class="rb-n">5</b> ${KIDS ? 'Çocuğun ve velinin bilgileri' : 'Bilgileriniz'}</span>
      <div class="rb-grid">
        <label class="rb-field">${KIDS ? 'Çocuğun adı soyadı' : 'Ad soyad'} *<input data-el="name" autocomplete="${KIDS ? 'off' : 'name'}" placeholder="${KIDS ? 'Örn. Deniz Yılmaz' : 'Örn. Ayşe Yılmaz'}"></label>
        ${KIDS ? `<label class="rb-field">Yaşı<input data-el="age" type="number" min="0" max="17" inputmode="numeric" placeholder="Örn. 6"></label>` : ''}
        <label class="rb-field">${KIDS ? 'Veli cep telefonu' : 'Cep telefonu'} *<input data-el="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="05xx xxx xx xx"></label>
        ${KIDS ? `<label class="rb-field">Veli adı<input data-el="parent" autocomplete="name" placeholder="Örn. Ayşe Yılmaz"></label>` : ''}
        <label class="rb-field rb-full">${KIDS ? 'Veli e-posta adresi' : 'E-posta adresiniz'} <small style="font-weight:600;opacity:.7">(isteğe bağlı · onay e-postası için)</small><input data-el="email" type="email" autocomplete="email" inputmode="email" placeholder="ornek@mail.com"></label>
        <label class="rb-field rb-full">Notunuz<textarea data-el="note" placeholder="${KIDS ? 'Şikâyeti, çocuğunuzun daha önce diş hekimine gidip gitmediği…' : 'Şikâyetinizi veya sorunuzu kısaca yazabilirsiniz…'}"></textarea></label>
        <label class="rb-check rb-full"><input type="checkbox" data-el="ok"> <span>${KIDS ? 'Velisi olarak, çocuğumun ve kendi' : 'Kişisel ve sağlık'} ${KIDS ? 'kişisel ve sağlık verilerimizin' : 'verilerimin'} randevu işlemleri için klinik tarafından işlenmesine açık rıza veriyorum. <a href="kvkk.html" target="_blank" rel="noopener" style="text-decoration:underline;font-weight:700">KVKK aydınlatma metni</a></span></label>
      </div>
    </div>
    <p class="rb-sum" data-el="sum"></p>
    <div class="rb-err" data-el="err" role="alert"></div>
    <div class="rb-foot">
      <button type="submit" class="rb-btn rb-primary" data-el="submit">Randevuyu oluştur</button>
      <a class="rb-btn rb-wa" href="${waLink(WA_TXT)}" target="_blank" rel="noopener">${WA_ICON} WhatsApp</a>
    </div>
  </div></div></form>
  <div class="rb-done" data-el="done" hidden></div>`;
const $ = n => root.querySelector(`[data-el="${n}"]`);
const step = n => root.querySelector(`[data-step="${n}"]`);
const form = $('body'), err = $('err');

/* ---------- durum ---------- */
const st = { ready: false, docs: [], svcs: [], links: [], hours: [], maxAhead: 60,
  doc: null, svc: null, week: null, day: null, slot: null, slots: {}, loading: false, pick: null, req: 0 };
const docOf = id => st.docs.find(d => d.id === id), svcOf = id => st.svcs.find(s => s.id === id);
const svcsFor = docId => st.svcs.filter(s => st.links.some(l => l.doctor_id === docId && l.service_id === s.id));
const works = (docId, day) => st.hours.some(h => h.doctor_id === docId && h.weekday === dow(day));
const firstWeek = () => addD(todayTZ(), 1 - dow(todayTZ()));
const lastDay = () => addD(todayTZ(), st.maxAhead);

function fallback() {
  root.innerHTML = `<div class="rb-fallback"><span class="rb-big">💬</span>
    <h3>Randevu için bize yazın</h3>
    <p>Online takvim şu an kullanılamıyor. WhatsApp'tan yazın ya da arayın, size en uygun saati hemen ayarlayalım.</p>
    <div class="rb-foot" style="justify-content:center"><a class="rb-btn rb-wa" href="${waLink(WA_TXT)}" target="_blank" rel="noopener">${WA_ICON} WhatsApp'tan yaz</a><a class="rb-btn" href="tel:+${WA_PHONE}">📞 0546 421 71 41</a></div></div>`;
}

async function init() {
  try {
    const [docs, svcs, links, hours, set] = await Promise.all([
      get(`doctors?select=id,full_name,title,specialty,color,clinic&is_active=eq.true&clinic=in.(${CLINIC},ikisi)&order=sort_order,full_name`),
      get('services?select=*&is_active=eq.true&order=sort_order,name'),
      get('doctor_services?select=doctor_id,service_id'),
      get('working_hours?select=doctor_id,weekday'),
      get('clinic_settings?select=max_days_ahead').catch(() => [])]);
    /* Hizmetin kliniği (cocuk / yetiskin / ikisi): bu sayfaya ait olmayan hizmetler gösterilmez */
    st.svcs = svcs.filter(s => !s.clinic || s.clinic === CLINIC || s.clinic === 'ikisi'); st.links = links; st.hours = hours;
    if (set[0]) st.maxAhead = set[0].max_days_ahead;
    st.docs = docs.filter(d => svcsFor(d.id).length && hours.some(h => h.doctor_id === d.id));
    if (!st.docs.length) return fallback();
    st.ready = true;
    if (st.docs.length === 1) st.doc = st.docs[0].id;
    $('loading').hidden = true; form.hidden = false;
    if (st.pick) applyPick(st.pick); else render();
  } catch (e) { console.warn('Online randevu yüklenemedi.', e); fallback(); }
}

function applyPick(name) {
  const s = st.svcs.find(x => norm(x.name) === norm(name));
  if (!s) { render(); return; }
  if (!st.doc || !svcsFor(st.doc).some(x => x.id === s.id)) {
    const d = st.docs.find(d => svcsFor(d.id).some(x => x.id === s.id)); if (!d) { render(); return; } st.doc = d.id;
  }
  selectSvc(s.id);
}
window.KK_pick = name => { st.pick = name; if (st.ready) applyPick(name); };
/* Hekim kartındaki "Randevu al" düğmesi: hekimi seçili getirir */
window.KK_doc = name => {
  if (!st.ready) return;
  const d = st.docs.find(x => norm(x.full_name) === norm(name)); if (!d || d.id === st.doc) return;
  st.doc = d.id; if (!svcsFor(d.id).some(x => x.id === st.svc)) { st.svc = null; st.week = null; st.day = null; } st.slot = null; st.slots = {};
  render(); if (st.svc) loadWeek(null);
};
document.addEventListener('click', e => {
  const p = e.target.closest('[data-pick]'); if (p) window.KK_pick(p.dataset.pick);
  const q = e.target.closest('[data-pick-doc]'); if (q) window.KK_doc(q.dataset.pickDoc);
});

function selectSvc(id) { st.svc = id; st.slot = null; st.slots = {}; st.day = null; st.week = null; render(); loadWeek(null); }

/* Haftayı yükle; hafta verilmezse boş saati olan ilk haftayı bul */
async function loadWeek(week) {
  if (!st.doc || !st.svc) return;
  const my = ++st.req, doc = st.doc, svc = st.svc, t = todayTZ();
  st.loading = true; render();
  let w = week || firstWeek(), tries = week ? 1 : 6;
  while (tries--) {
    const days = [...Array(7)].map((_, i) => addD(w, i));
    const res = await Promise.all(days.map(d => (d >= t && d <= lastDay() && works(doc, d))
      ? rpc('get_available_slots', { p_doctor_id: doc, p_service_id: svc, p_date: d }).catch(() => [])
      : Promise.resolve(null)));
    if (my !== st.req) return;
    days.forEach((d, i) => st.slots[d] = res[i]);
    if (week || days.some(d => (st.slots[d] || []).length) || addD(w, 7) > lastDay()) break;
    w = addD(w, 7);
  }
  st.week = w; st.loading = false;
  const wd = [...Array(7)].map((_, i) => addD(w, i));
  if (!wd.includes(st.day) || !(st.slots[st.day] || []).length) st.day = wd.find(d => (st.slots[d] || []).length) || null;
  st.slot = null;
  render();
}
async function reloadDay(day) {
  const r = await rpc('get_available_slots', { p_doctor_id: st.doc, p_service_id: st.svc, p_date: day }).catch(() => null);
  if (r) st.slots[day] = r; st.slot = null; render();
}

function render() {
  const d = docOf(st.doc), s = svcOf(st.svc);
  step('doc').hidden = false;
  $('docs').innerHTML = st.docs.map(x => `<button type="button" class="rb-doc" data-rb="doc" data-id="${x.id}" aria-pressed="${x.id === st.doc}"><span class="rb-av" style="--c:${esc(x.color)}">${esc(initials(x.full_name))}</span><span>${esc(docLabel(x))}<small>${esc(x.specialty || '')}</small></span></button>`).join('');
  $('svcs').innerHTML = d ? svcsFor(d.id).map(x => `<button type="button" class="rb-chip" data-rb="svc" data-id="${x.id}" aria-pressed="${x.id === st.svc}">${esc(svcLabel(x.name))}<small>${x.duration_minutes} dk</small></button>`).join('') : '<p class="rb-hint">Önce hekim seçin.</p>';

  // Gün — haftalık sayfa
  let dh;
  if (!s) dh = '<p class="rb-hint">Önce işlem seçin.</p>';
  else if (st.loading || !st.week) dh = '<p class="rb-hint">Boş saatler kontrol ediliyor…</p>';
  else {
    const days = [...Array(7)].map((_, i) => addD(st.week, i)), t = todayTZ();
    const a = days[0], b = days[6];
    const title = dmon(a) === dmon(b) ? `${dnum(a)} – ${dnum(b)} ${dmon(b)}` : `${dnum(a)} ${dmon(a)} – ${dnum(b)} ${dmon(b)}`;
    const canPrev = st.week > firstWeek(), canNext = addD(st.week, 7) <= lastDay();
    const any = days.some(k => (st.slots[k] || []).length);
    dh = `<div class="rb-week">
      <div class="rb-wnav"><button type="button" class="rb-arrow" data-rb="prev" ${canPrev ? '' : 'disabled'} aria-label="Önceki hafta">‹</button>
        <b>${title}</b>
        <button type="button" class="rb-arrow" data-rb="next" ${canNext ? '' : 'disabled'} aria-label="Sonraki hafta">›</button></div>
      <div class="rb-days">${days.map(k => {
        const v = st.slots[k], n = v ? v.length : 0, past = k < t;
        const lab = past ? 'Geçti' : n ? `${n} boş` : v ? 'Dolu' : 'Kapalı';
        return `<button type="button" class="rb-day${k === t ? ' is-today' : ''}" data-rb="day" data-d="${k}" aria-pressed="${k === st.day}" ${n ? '' : 'disabled'} aria-label="${longD(k)}, ${lab}"><span>${DOW[dow(k) - 1]}</span><b>${dnum(k)}</b><em>${lab}</em></button>`;
      }).join('')}</div>
      ${any ? '' : `<p class="rb-hint" style="margin-top:8px">Bu hafta boş saat yok. ${canNext ? 'Sonraki haftaya bakın ya da' : ''} <a href="${waLink(WA_TXT)}" target="_blank" rel="noopener">WhatsApp'tan yazın</a>.</p>`}
    </div>`;
  }
  $('days').innerHTML = dh;

  // Saat
  let sh;
  if (!st.day || st.loading) sh = '<p class="rb-hint">Önce gün seçin.</p>';
  else {
    const list = st.slots[st.day] || [];
    const grp = (t, a) => a.length ? `<div class="rb-slot-g"><h4>${t}</h4><div class="rb-slots">${a.map(x => `<button type="button" class="rb-slot" data-rb="slot" data-s="${esc(x.slot_start)}" aria-pressed="${x.slot_start === st.slot}">${hm(x.slot_start)}</button>`).join('')}</div></div>` : '';
    sh = list.length ? `<p class="rb-hint" style="margin-bottom:6px">${longD(st.day)}</p>` + grp('Sabah', list.filter(x => hourOf(x.slot_start) < 12)) + grp('Öğleden sonra', list.filter(x => hourOf(x.slot_start) >= 12)) : '<p class="rb-hint">Bu günde boş saat kalmadı.</p>';
  }
  $('slots').innerHTML = sh;

  step('doc').classList.toggle('ok', !!d); step('svc').classList.toggle('ok', !!s);
  step('day').classList.toggle('ok', !!st.day && !st.loading); step('slot').classList.toggle('ok', !!st.slot);
  [...root.querySelectorAll('.rb-step:not([hidden]) .rb-n')].forEach((b, i) => b.textContent = i + 1);
  $('sum').innerHTML = st.slot && d && s ? `<b>${esc(docLabel(d))}</b> · ${esc(svcLabel(s.name))} · <b>${longD(st.day)}, ${hm(st.slot)}</b>` : '';
}

root.addEventListener('click', e => {
  const b = e.target.closest('[data-rb]'); if (!b || b.disabled) return; err.textContent = '';
  const k = b.dataset.rb;
  if (k === 'doc' && st.doc !== b.dataset.id) {
    st.doc = b.dataset.id;
    if (!svcsFor(st.doc).some(x => x.id === st.svc)) { st.svc = null; st.slots = {}; st.day = null; st.week = null; }
    st.slot = null; render(); if (st.svc) { st.slots = {}; loadWeek(null); }
  }
  else if (k === 'svc' && st.svc !== b.dataset.id) selectSvc(b.dataset.id);
  else if (k === 'prev') loadWeek(addD(st.week, -7));
  else if (k === 'next') loadWeek(addD(st.week, 7));
  else if (k === 'day') { st.day = b.dataset.d; st.slot = null; render(); }
  else if (k === 'slot') { st.slot = b.dataset.s; render(); const n = $('name'); if (!n.value) n.focus({ preventScroll: true }); step('info').scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  else if (k === 'new') { st.slot = null; $('done').hidden = true; form.hidden = false; loadWeek(st.week); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
});

form.addEventListener('submit', async e => {
  e.preventDefault(); err.textContent = '';
  const d = docOf(st.doc), s = svcOf(st.svc);
  if (!d || !s || !st.slot) { err.textContent = 'Lütfen işlem, gün ve saat seçin.'; return; }
  const name = $('name').value.trim(), phone = normPhone($('phone').value);
  if (name.length < 3) { err.textContent = KIDS ? 'Lütfen çocuğun adını ve soyadını yazın.' : 'Lütfen adınızı ve soyadınızı yazın.'; $('name').focus(); return; }
  if (!phone) { err.textContent = 'Lütfen geçerli bir cep telefonu yazın (05xx xxx xx xx).'; $('phone').focus(); return; }
  if (!$('ok').checked) { err.textContent = 'Devam etmek için KVKK onay kutusunu işaretleyin.'; return; }
  const email = $('email').value.trim();
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { err.textContent = 'Lütfen geçerli bir e-posta adresi yazın ya da alanı boş bırakın.'; $('email').focus(); return; }
  const age = KIDS ? $('age').value.trim() : '', parent = KIDS ? $('parent').value.trim() : '', note = $('note').value.trim();
  const fullNote = [KIDS ? `Hasta: Çocuk${age ? ` (${age} yaş)` : ''}${parent ? ` · Veli: ${parent}` : ''}` : 'Hasta: Yetişkin', note && `Not: ${note}`].filter(Boolean).join(' · ');
  const btn = $('submit'); btn.disabled = true; btn.textContent = 'Gönderiliyor…';
  try {
    const r = await rpc('book_appointment', { p_doctor_id: d.id, p_service_id: s.id, p_starts_at: st.slot, p_full_name: name, p_phone: phone, p_email: email || null, p_kvkk_consent: true, p_note: fullNote });
    const row = Array.isArray(r) ? r[0] : r, ok = row && row.status === 'confirmed', when = `${longD(st.day)}, ${hm(st.slot)}`;
    const waTxt = `Merhaba Klinikids 👋 Sitenizden randevu aldım.\n\n👤 ${name}${KIDS && age ? ` (${age} yaş)` : ''}\n🦷 ${svcLabel(s.name)}\n📅 ${when}\n👩‍⚕️ ${docLabel(d)}`;
    $('done').innerHTML = `<span class="rb-big">${ok ? '✅' : '🎉'}</span><h3>${ok ? 'Randevunuz onaylandı!' : 'Randevu talebiniz alındı!'}</h3>
      <dl><div><dt>Hasta</dt><dd>${esc(name)}</dd></div><div><dt>Hekim</dt><dd>${esc(docLabel(d))}</dd></div>
      <div><dt>İşlem</dt><dd>${esc(svcLabel(s.name))}</dd></div><div><dt>Tarih</dt><dd>${when}</dd></div></dl>
      <p>${ok ? 'Sizi bekliyoruz! Gelemeyecekseniz lütfen bize haber verin.' : `Saatiniz sizin için ayrıldı. Ekibimiz kısa süre içinde randevunuzu teyit edecek${email ? '; onaylandığında e-posta adresinize de bilgi gelecek' : ''}.`}</p>
      <div class="rb-foot" style="justify-content:center"><a class="rb-btn rb-wa" href="${waLink(waTxt)}" target="_blank" rel="noopener">${WA_ICON} WhatsApp'tan yaz</a><button type="button" class="rb-btn" data-rb="new">＋ Yeni randevu</button></div>`;
    form.hidden = true; $('done').hidden = false;
    ['name', 'phone', 'email', 'note', 'age', 'parent'].forEach(n => { const el = $(n); if (el) el.value = ''; }); $('ok').checked = false; st.slot = null;
    root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (typeof window.confetti === 'function') window.confetti();
  } catch (ex) {
    err.textContent = ex.message;
    if (/müsait|doldu/i.test(ex.message)) reloadDay(st.day);
  } finally { btn.disabled = false; btn.textContent = 'Randevuyu oluştur'; }
});

init();
})();
