/* =====================================================================
   Klinikids — Hekim kartları (çocuk ve yetişkin sayfalarında ortak)

   Hekimler YÖNETİM PANELİ'nden yönetilir:
     • Doktorlar sayfası  → hekim ekle, hangi klinikte görüneceğini seç
     • Profilim sayfası   → fotoğraf, tanıtım, özgeçmiş (doktor kendisi düzenler)
     • Sertifikalar       → sertifika yükle
     • Saatler / İzinler  → çalışma saatleri, izin ve resmi tatiller
   Veritabanına ulaşılamazsa sayfadaki yedek liste (window.HEKIMLER) gösterilir.
   ===================================================================== */
(() => {
const box = document.getElementById('hekimler');
if (!box) return;
const SB = 'https://xnjnxogvnldrbridzvqu.supabase.co';
const KEY = 'sb_publishable_CpJXbL8ZBECdSo3uSYXqdg_5W1DStIb';
const CLINIC = box.dataset.clinic === 'cocuk' ? 'cocuk' : 'yetiskin';
const KIDS = CLINIC === 'cocuk';
const TZ = 'Europe/Istanbul';
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ini = n => String(n || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toLocaleUpperCase('tr-TR')).join('');
const norm = n => String(n || '').toLocaleLowerCase('tr-TR').replace(/\s+/g, ' ').trim();
const wa = t => 'https://wa.me/905464217141?text=' + encodeURIComponent(t);
const WA = '<svg viewBox="0 0 24 24" aria-hidden="true"><use href="#i-wa"/></svg>';
const api = (path, opt = {}) => {
  const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 5000);
  return fetch(`${SB}/rest/v1/${path}`, { ...opt, headers: { apikey: KEY, 'Content-Type': 'application/json', ...(opt.headers || {}) }, signal: ctl.signal })
    .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status))).finally(() => clearTimeout(t));
};
/* Sayfa window.HEKIM_YAN_BILGI = true derse öne çıkan bilgiler geniş ekranda fotoğrafın altına dizilir */
const side = !!window.HEKIM_YAN_BILGI;

/* Çocuk sayfasında özgeçmişi henüz girilmemiş hekimlerin kartı boş kalmasın: kliniğin genel yaklaşımı */
const KIDS_APPROACH = [
  ['🎈', 'Oyunla tanışma', 'İlk ziyarette aletleri tanıtır, korkuyu meraka çevirir.'],
  ['🗣️', 'Anlat · göster · yap', 'Her adımı önce anlatır, gösterir, sonra uygular.'],
  ['👨‍👩‍👧', 'Ailece birlikte', 'Bulguları ve evde bakımı size ayrıntılı anlatır.'],
];
/* ---------- Kart çizimi ---------- */
function render(list) {
  box.innerHTML = list.map(h => {
    if (KIDS && !(h.approach && h.approach.length) && !((h.edu && h.edu.length) || (h.exp && h.exp.length) || h.about)) h = { ...h, approach: KIDS_APPROACH };
    const facts = cls => h.facts && h.facts.length ? `<div class="${cls}">${h.facts.map(f => `<div><b>${esc(f[0])}</b><span>${esc(f[1])}</span></div>`).join('')}</div>` : '';
    const more = (h.edu && h.edu.length) || (h.exp && h.exp.length) || h.about || (h.hobbies && h.hobbies.length) || h.lang;
    return `<article class="hk reveal" style="--c:${esc(h.color || 'var(--teal)')}" data-doc="${esc(h.id || '')}" data-name="${esc(h.name)}">
  ${side ? '<div class="hk-side">' : ''}<div class="hk-photo"><span class="hk-ini">${esc(ini(h.name))}</span>${h.photo ? `<img src="${esc(h.photo)}" alt="${esc(h.title + ' ' + h.name)}" loading="lazy" data-alt="${esc(h.photoAlt || '')}">` : ''}${h.badge ? `<span class="hk-badge">${esc(h.badge)}</span>` : ''}</div>${side ? facts('hk-facts hk-facts-side') + '</div>' : ''}
  <div class="hk-body">
    <h3>${esc(h.title)} ${esc(h.name)}</h3>
    <div class="hk-role">${esc(h.role)}</div>
    <div class="hk-meta"></div>
    <p class="hk-intro">${esc(h.intro)}</p>
    ${facts(side ? 'hk-facts hk-facts-body' : 'hk-facts')}
    ${h.approach && h.approach.length ? `<div class="hk-appr"><h4>${esc(h.approachTitle || 'Klinikids yaklaşımı')}</h4><ul>${h.approach.map(x => `<li><span>${esc(x[0])}</span><div><b>${esc(x[1])}</b>${esc(x[2])}</div></li>`).join('')}</ul></div>` : ''}
    ${h.tags && h.tags.length ? `<div class="hk-tags">${h.tags.map(t => `<span>${esc(t)}</span>`).join('')}</div>` : ''}
    ${more ? `<details class="hk-more"><summary>Özgeçmiş${h.hobbies && h.hobbies.length ? ' ve hobiler' : ''}</summary><div class="hk-mbody">
      ${h.edu && h.edu.length ? `<h4>Eğitim</h4><ul class="hk-tl">${h.edu.map(e => `<li><b>${esc(e[0])}</b>${esc(e[1])}</li>`).join('')}</ul>` : ''}
      ${h.exp && h.exp.length ? `<h4>Deneyim</h4><ul class="hk-tl">${h.exp.map(e => `<li><b>${esc(e[0])}</b>${esc(e[1])}</li>`).join('')}</ul>` : ''}
      ${h.lang ? `<h4>Diller</h4><p>${esc(h.lang)}</p>` : ''}
      ${h.about ? `<h4>Biraz da kendisi</h4><p>${esc(h.about)}</p>` : ''}
      ${h.hobbies && h.hobbies.length ? `<div class="hk-tags hk-hob">${h.hobbies.map(t => `<span>${esc(t)}</span>`).join('')}</div>` : ''}
    </div></details>` : ''}
    <div class="hk-ctas">
      ${h.bookable === false ? '' : `<a href="#randevu" class="hk-btn hk-main" data-pick-doc="${esc(h.name)}">Randevu Al</a>`}
      <a class="hk-btn hk-sub" href="${wa(h.waMsg || ('Merhaba ' + h.title + ' ' + h.name + (KIDS ? ', çocuğum için bilgi almak istiyorum.' : ', bilgi almak istiyorum.')))}" target="_blank" rel="noopener">${WA} Soru Sor</a>
    </div>
  </div>
</article>`;
  }).join('');
  /* Fotoğraf yüklenemezse yedek adres, o da yoksa baş harfler */
  box.querySelectorAll('.hk-photo img').forEach(img => {
    img.onerror = () => { const alt = img.dataset.alt; if (alt && img.src !== alt) { img.dataset.alt = ''; img.src = alt; } else img.remove(); };
  });
  const io = new IntersectionObserver(en => en.forEach(x => { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } }), { threshold: .1 });
  box.querySelectorAll('.reveal').forEach(el => io.observe(el));
}

/* Veritabanı kaydını kart verisine çevir */
const CL_LBL = { cocuk: 'Çocuk Kliniği', yetiskin: 'Yetişkin Kliniği', ikisi: KIDS ? 'Çocuk ve Yetişkin Kliniği' : 'Yetişkin ve Çocuk Kliniği' };
const fromDb = d => {
  const p = d.profile || {};
  return { ...p, id: d.id, name: d.full_name, title: d.title || 'Dt.', color: p.color || d.color, photo: d.photo_url || '',
           role: p.role || `${d.specialty || 'Diş Hekimi'} · ${CL_LBL[d.clinic] || ''}` };
};

/* ---------- Stiller (sertifika, durum, saatler) ---------- */
if (!document.getElementById('hkCertCss')) {
  const st = document.createElement('style'); st.id = 'hkCertCss';
  st.textContent = `
.hk-certlink{display:inline-flex;align-items:center;gap:6px;margin:8px 0 6px;padding:4px 11px 4px 8px;border:1px solid color-mix(in srgb,var(--c) 35%,transparent);border-radius:99px;background:color-mix(in srgb,var(--c) 8%,#fff);color:var(--c);font-family:inherit;font-weight:700;font-size:12.5px;line-height:1.2;cursor:pointer;align-self:flex-start;width:auto;transition:background .2s,transform .2s;vertical-align:middle}
.hk-certlink:hover{background:color-mix(in srgb,var(--c) 16%,#fff);transform:translateY(-1px)}
.hk-certlink b{display:inline-grid;place-items:center;min-width:18px;height:18px;padding:0 5px;border-radius:99px;background:var(--c);color:#fff;font-size:11px}
.hk-certlink i{font-style:normal;opacity:.7;transition:transform .2s}.hk-certlink:hover i{transform:translateX(2px)}
.cert-lb{position:fixed;inset:0;z-index:9999;background:rgba(10,16,28,.88);display:flex;align-items:center;justify-content:center;padding:56px 16px 24px;flex-direction:column;gap:14px}
.cert-lb figure{margin:0;max-width:min(980px,100%);max-height:calc(100vh - 150px);display:flex;flex-direction:column;align-items:center}
.cert-lb img{max-width:100%;max-height:calc(100vh - 170px);border-radius:10px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.cert-lb .pdfbox{background:#fff;border-radius:16px;padding:40px 48px;text-align:center;font-weight:700;color:#1b2533}
.cert-lb .pdfbox a{display:inline-block;margin-top:12px;background:#1b2533;color:#fff;border-radius:10px;padding:10px 18px;text-decoration:none}
.cert-lb figcaption{color:#fff;text-align:center;margin-top:12px;font-size:15px}.cert-lb figcaption small{display:block;opacity:.7;font-size:13px;margin-top:2px}
.cert-lb .x,.cert-lb .nv{position:absolute;border:0;background:rgba(255,255,255,.14);color:#fff;border-radius:50%;width:44px;height:44px;font-size:22px;cursor:pointer;display:grid;place-items:center}
.cert-lb .x{top:12px;right:12px}.cert-lb .pv{left:12px;top:50%;transform:translateY(-50%)}.cert-lb .nx{right:12px;top:50%;transform:translateY(-50%)}
.cert-lb .nv[hidden]{display:none}
.cert-lb .strip{display:flex;gap:8px;max-width:min(980px,100%);overflow-x:auto;padding:4px 2px}
.cert-lb .strip button{flex:none;width:64px;height:48px;border-radius:8px;overflow:hidden;border:2px solid transparent;padding:0;background:#fff;cursor:pointer;opacity:.55;font-family:inherit;font-weight:700;font-size:11px;line-height:1.1;color:#1b2533}
.cert-lb .strip button.on{border-color:#fff;opacity:1}
.cert-lb .strip img{width:100%;height:100%;object-fit:cover}
@media (max-width:700px){.cert-lb .nv{top:auto;bottom:84px;transform:none}}
.hk-meta{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 6px}
.hk-meta:empty{display:none}
.hk-meta .hk-certlink{margin:0}
.hk-st{display:inline-flex;align-items:center;gap:6px;padding:4px 11px 4px 9px;border-radius:99px;border:1px solid #E3E7EC;background:#fff;color:#4A5563;font-family:inherit;font-weight:700;font-size:12.5px;line-height:1.2;cursor:pointer}
.hk-st:hover{border-color:#C9D1DA}
.hk-st .dot{width:8px;height:8px;border-radius:50%;background:#B8C0CA;flex:none}
.hk-st.on .dot{background:#2DB36F;box-shadow:0 0 0 3px rgba(45,179,111,.18)}
.hk-st.off{background:#FFF4E5;border-color:#F6D9AE;color:#9A5B00}
.hk-st i{font-style:normal;opacity:.6;transition:transform .2s}.hk-st[aria-expanded="true"] i{transform:rotate(90deg)}
.hk-week{margin:2px 0 10px;border:1px solid #E9EDF1;border-radius:12px;padding:8px 12px;background:#fff;font-size:13px;max-width:360px}
.hk-week[hidden]{display:none}
.hk-week div{display:flex;justify-content:space-between;gap:12px;padding:4px 0;border-bottom:1px dashed #EEF1F4}
.hk-week div:last-of-type{border-bottom:0}
.hk-week div.today{font-weight:800;color:var(--c)}
.hk-week span:last-child{color:#4A5563;text-align:right}.hk-week .closed{color:#A0A8B3}
.hk-week p{margin:6px 0 0;font-size:12px;color:#9A5B00}
.kk-hours{margin:14px 0 4px;font-size:15px;line-height:1.6}
.kk-hours b{display:block;font-size:13px;letter-spacing:.06em;text-transform:uppercase;opacity:.75;margin-bottom:2px}
.kk-hours .now{display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:800;margin-top:8px;padding:5px 12px 5px 10px;border-radius:99px;background:#fff;box-shadow:0 4px 12px -6px rgba(0,0,0,.35);letter-spacing:.01em}
.kk-hours .now:before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor;box-shadow:0 0 0 3px color-mix(in srgb,currentColor 22%,transparent)}
.kk-hours .now.open{color:#15803D}.kk-hours .now.closed{color:#C2410C}
.kk-closure{display:flex;gap:12px;align-items:center;margin:0 0 18px;padding:14px 18px;border-radius:16px;background:#FFF4E5;border:1px solid #F6D9AE;color:#7A4A00;font-weight:700;line-height:1.45}
.kk-closure span:first-child{font-size:24px}
.hk-svc{margin:12px 0 8px;padding:12px 14px;border-radius:16px;background:color-mix(in srgb,var(--c) 7%,#fff);border:1px solid color-mix(in srgb,var(--c) 18%,transparent)}
.hk-svc h4{margin:0 0 8px;font-size:13.5px;font-weight:800;color:var(--ink,#173A5E)}
.hk-svc h4 span{font-weight:600;font-size:12px;opacity:.6}
.hk-svc-row{display:flex;flex-wrap:wrap;gap:6px}
.hk-svc-row button{display:inline-flex;align-items:center;gap:6px;padding:6px 11px 6px 8px;border-radius:12px;border:1px solid color-mix(in srgb,var(--c) 22%,transparent);background:#fff;font-family:inherit;font-weight:700;font-size:13px;color:#2B3A4A;cursor:pointer;transition:transform .15s,border-color .15s,box-shadow .15s}
.hk-svc-row button:hover{transform:translateY(-1px);border-color:var(--c);box-shadow:0 6px 14px -8px var(--c)}
.hk-svc-row button[hidden]{display:none}
.hk-svc-row button span{font-size:15px}
.hk-svc-row .more{background:var(--c);color:#fff;border-color:transparent}
@media (max-width:700px){.hk-svc{padding:10px 12px}.hk-svc-row button{font-size:12.5px;padding:5px 9px 5px 7px}}`;
  document.head.appendChild(st);
}

/* ---------- Sertifikalar ---------- */
const certUrl = p => `${SB}/storage/v1/object/public/sertifikalar/${p.split('/').map(encodeURIComponent).join('/')}`;
function lightbox(items, start) {
  let i = start;
  const lb = document.createElement('div'); lb.className = 'cert-lb'; lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true');
  const draw = () => {
    const c = items[i], url = certUrl(c.file_path), meta = [c.issuer, c.year].filter(Boolean).join(' · ');
    lb.innerHTML = `<button class="x" aria-label="Kapat">×</button>
      <button class="nv pv" aria-label="Önceki" ${items.length > 1 ? '' : 'hidden'}>‹</button><button class="nv nx" aria-label="Sonraki" ${items.length > 1 ? '' : 'hidden'}>›</button>
      <figure>${c.file_type === 'pdf' ? `<div class="pdfbox">📄 PDF belge<br><a href="${esc(url)}" target="_blank" rel="noopener">Belgeyi aç</a></div>` : `<img src="${esc(url)}" alt="${esc(c.title)}">`}
      <figcaption>${esc(c.title)}${meta ? `<small>${esc(meta)}</small>` : ''}${items.length > 1 ? `<small>${i + 1} / ${items.length}</small>` : ''}</figcaption></figure>
      ${items.length > 1 ? `<div class="strip">${items.map((x, k) => `<button type="button" data-k="${k}" class="${k === i ? 'on' : ''}" aria-label="${esc(x.title)}">${x.file_type === 'pdf' ? '📄<br>PDF' : `<img src="${esc(certUrl(x.file_path))}" alt="" loading="lazy">`}</button>`).join('')}</div>` : ''}`;
    lb.querySelectorAll('.strip button').forEach(b => b.onclick = () => { i = +b.dataset.k; draw(); });
    lb.querySelector('.x').onclick = close;
    lb.querySelector('.pv').onclick = () => { i = (i - 1 + items.length) % items.length; draw(); };
    lb.querySelector('.nx').onclick = () => { i = (i + 1) % items.length; draw(); };
  };
  const key = e => { if (e.key === 'Escape') close(); if (items.length > 1 && e.key === 'ArrowRight') { i = (i + 1) % items.length; draw(); } if (items.length > 1 && e.key === 'ArrowLeft') { i = (i - 1 + items.length) % items.length; draw(); } };
  function close() { lb.remove(); document.removeEventListener('keydown', key); document.body.style.overflow = ''; }
  lb.addEventListener('click', e => { if (e.target === lb) close(); });
  document.addEventListener('keydown', key); document.body.style.overflow = 'hidden';
  draw(); document.body.appendChild(lb);
}

function addCerts() {
  api('doctor_certificates?select=title,issuer,year,file_path,file_type,doctor_id,doctors(full_name)&is_active=eq.true&order=sort_order,created_at')
    .then(rows => {
      box.querySelectorAll('article.hk').forEach(card => {
        const items = rows.filter(r => (card.dataset.doc && r.doctor_id === card.dataset.doc) || norm(r.doctors && r.doctors.full_name) === norm(card.dataset.name));
        if (!items.length) return;
        const el = document.createElement('button');
        el.type = 'button'; el.className = 'hk-certlink';
        el.setAttribute('aria-label', `${items.length} sertifikayı görüntüle`);
        el.innerHTML = `🏅 Sertifikalar <b>${items.length}</b> <i>›</i>`;
        el.onclick = () => lightbox(items, 0);
        card.querySelector('.hk-meta').appendChild(el);
      });
    }).catch(() => {});
}

/* ---------- Çalışma saatleri ve izinler ---------- */
const DAYS = ['', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
const SD = ['', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
const nowParts = () => {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: TZ, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date()).map(x => [x.type, x.value]));
  return { w: ({ Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 })[p.weekday], hm: `${p.hour}:${p.minute}` };
};
const fmtD = d => new Date(d).toLocaleDateString('tr-TR', { timeZone: TZ, day: 'numeric', month: 'long' });
const lastDay = e => new Date(new Date(e).getTime() - 1);   // bitiş anı ertesi gün 00:00 ise önceki gün
const range = (s, e) => { const a = fmtD(s), b = fmtD(lastDay(e)); return a === b ? a : `${a} – ${b}`; };
const span = segs => segs.length ? `${segs[0].s}–${segs[segs.length - 1].e}` : '';

function addSchedule() {
  api('rpc/get_public_schedule', { method: 'POST', body: '{}' }).then(S => {
    const now = new Date(), { w: today, hm } = nowParts();
    const cards = [...box.querySelectorAll('article.hk')].filter(c => c.dataset.doc);
    const ids = cards.map(c => c.dataset.doc);
    cards.forEach(card => {
      const id = card.dataset.doc;
      const hrs = S.hours.filter(h => h.d === id), offs = S.off.filter(o => o.d === id);
      if (!hrs.length) return;
      const byDay = w => hrs.filter(h => h.w === w);
      const curOff = offs.find(o => new Date(o.s) <= now && new Date(o.e) > now);
      const nextOff = offs.find(o => new Date(o.s) > now && new Date(o.s) - now < 14 * 864e5);
      let cls = '', txt;
      if (curOff) { cls = 'off'; txt = `🏖️ ${curOff.r ? esc(curOff.r) + ' · ' : 'İzinde · '}${esc(fmtD(lastDay(curOff.e)))}'e kadar`; }
      else {
        const t = byDay(today), open = t.some(h => hm >= h.s && hm < h.e);
        if (t.length && hm < t[t.length - 1].e) { cls = open ? 'on' : ''; txt = `<span class="dot"></span>Bugün ${span(t)}`; }
        else {
          let n = 1; while (n < 8 && !byDay(((today - 1 + n) % 7) + 1).length) n++;
          const nd = ((today - 1 + n) % 7) + 1;
          txt = `<span class="dot"></span>${n === 1 ? 'Yarın' : DAYS[nd]} ${byDay(nd)[0].s}'da`;
        }
      }
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'hk-st ' + cls; btn.setAttribute('aria-expanded', 'false');
      btn.innerHTML = `${txt} <i>›</i>`;
      const wk = document.createElement('div'); wk.className = 'hk-week'; wk.hidden = true;
      wk.innerHTML = [1, 2, 3, 4, 5, 6, 7].map(d => { const x = byDay(d); return `<div class="${d === today ? 'today' : ''}"><span>${DAYS[d]}</span><span class="${x.length ? '' : 'closed'}">${x.length ? x.map(h => `${h.s}–${h.e}`).join(', ') : 'Çalışmıyor'}</span></div>`; }).join('')
        + offs.map(o => `<p>🏖️ ${esc(range(o.s, o.e))}${o.r ? ' · ' + esc(o.r) : ' · İzinli'}</p>`).join('');
      btn.onclick = () => { wk.hidden = !wk.hidden; btn.setAttribute('aria-expanded', String(!wk.hidden)); };
      const meta = card.querySelector('.hk-meta');
      meta.prepend(btn); meta.after(wk);
      if (!curOff && nextOff) { const p = document.createElement('span'); p.className = 'hk-st off'; p.style.cursor = 'default'; p.textContent = `📅 ${range(nextOff.s, nextOff.e)} izinli`; meta.appendChild(p); }
    });

    /* Klinik geneli: bu sayfadaki tüm hekimler aynı anda izindeyse → klinik kapalı (resmi tatil vb.) */
    const closures = [];
    S.off.filter(o => ids.includes(o.d)).forEach(o => {
      const all = ids.every(id => S.off.some(x => x.d === id && x.s === o.s && x.e === o.e));
      if (all && !closures.some(c => c.s === o.s && c.e === o.e) && new Date(o.s) - now < 21 * 864e5) closures.push(o);
    });
    if (closures.length) {
      const c = closures[0], ongoing = new Date(c.s) <= now;
      const html = `<span>${ongoing ? '🎉' : '📅'}</span><span>${c.r ? esc(c.r) + ' nedeniyle ' : ''}${esc(range(c.s, c.e))} tarihlerinde kliniğimiz kapalıdır.${ongoing ? ' Acil durumlar için WhatsApp’tan yazabilirsiniz.' : ''}</span>`;
      ['#randevu .wrap', '#hekimler'].forEach(sel => {
        const host = document.querySelector(sel); if (!host) return;
        const n = document.createElement('div'); n.className = 'kk-closure'; n.innerHTML = html;
        sel === '#hekimler' ? host.before(n) : host.prepend(n);
      });
    }

    /* İletişim kutusu: kliniğin genel çalışma saatleri */
    const contact = document.querySelector('#iletisim .loc-box > p, #iletisim.cbox > p, #iletisim > p');
    if (contact && ids.length) {
      const day = d => { const x = S.hours.filter(h => ids.includes(h.d) && h.w === d); return x.length ? `${x.map(h => h.s).sort()[0]}–${x.map(h => h.e).sort().pop()}` : ''; };
      const rows = []; let i = 1;
      while (i <= 7) { const v = day(i); let j = i; while (j < 7 && day(j + 1) === v) j++; rows.push(`${SD[i]}${j > i ? '–' + SD[j] : ''} ${v || 'Kapalı'}`); i = j + 1; }
      const t = day(today), closedNow = closures.some(c => new Date(c.s) <= now && new Date(c.e) > now);
      const openNow = !closedNow && t && hm >= t.split('–')[0] && hm < t.split('–')[1];
      const el = document.createElement('div'); el.className = 'kk-hours';
      el.innerHTML = `<b>Çalışma saatleri</b>${rows.join(' · ')}<br><span class="now ${openNow ? 'open' : 'closed'}">${openNow ? 'Şu an açığız' : 'Şu an kapalıyız'}</span>`;
      contact.after(el);
    }
  }).catch(() => {});
}


/* ---------- Yaptığı tedaviler (panelde hekime atanan hizmetler) ---------- */
const SVC_ICON = [[/muayene|kontrol/i,'🔍'],[/flor/i,'✨'],[/fiss?ür/i,'🛡️'],[/dolgu/i,'🦷'],[/kanal/i,'🩺'],[/yer tutucu/i,'🧩'],[/çekim/i,'🪥'],[/temizli|polisaj|taşı/i,'🫧'],
  [/ortodont|plak|tel/i,'😁'],[/implant/i,'🔩'],[/beyazlat/i,'💎'],[/lamine|e-max|mockup|gülüş/i,'💫'],[/köprü|protez/i,'👑'],[/diş eti/i,'🌿']];
const svcIcon = n => (SVC_ICON.find(([r]) => r.test(n)) || [0, '🦷'])[1];
const svcName = n => String(n).replace(/\s*\((çocuk|yetişkin)\)\s*$/i, '');
function addServices() {
  api('doctor_services?select=doctor_id,services(name,clinic,is_active,sort_order)').then(rows => {
    box.querySelectorAll('article.hk[data-doc]').forEach(card => {
      const list = rows.filter(r => r.doctor_id === card.dataset.doc && r.services && r.services.is_active && [CLINIC, 'ikisi'].includes(r.services.clinic || 'ikisi'))
        .map(r => r.services).sort((a, b) => a.sort_order - b.sort_order);
      if (!list.length) return;
      const MAX = 8, name = card.dataset.name;
      const el = document.createElement('div'); el.className = 'hk-svc';
      el.innerHTML = `<h4>${KIDS ? 'Yaptığı tedaviler' : 'Randevu alabileceğiniz işlemler'} <span>· tıklayın, randevu alın</span></h4><div class="hk-svc-row">${list.map((x, i) =>
        `<button type="button" data-svc="${esc(x.name)}" ${i >= MAX ? 'hidden' : ''}><span>${svcIcon(x.name)}</span>${esc(svcName(x.name))}</button>`).join('')}${list.length > MAX ? `<button type="button" class="more">+${list.length - MAX} işlem</button>` : ''}</div>`;
      el.querySelectorAll('[data-svc]').forEach(b => b.onclick = () => {
        window.KK_doc && window.KK_doc(name); window.KK_pick && window.KK_pick(b.dataset.svc);
        const r = document.getElementById('randevu'); if (r) r.scrollIntoView({ behavior: 'smooth' });
      });
      const mo = el.querySelector('.more'); if (mo) mo.onclick = () => { el.querySelectorAll('[data-svc][hidden]').forEach(b => b.hidden = false); mo.remove(); };
      const anchor = card.querySelector('.hk-more') || card.querySelector('.hk-ctas');
      anchor.before(el);
    });
  }).catch(() => {});
}
/* ---------- Başlat: önce veritabanı, olmazsa yedek liste ---------- */
api(`doctors?select=id,full_name,title,specialty,color,clinic,profile,photo_url&is_active=eq.true&clinic=in.(${CLINIC},ikisi)&order=sort_order,full_name`)
  .then(rows => { if (!rows.length) throw new Error('boş'); render(rows.map(fromDb)); addSchedule(); addServices(); })
  .catch(() => render(window.HEKIMLER || []))
  .finally(addCerts);
})();
