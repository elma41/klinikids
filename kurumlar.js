/* =====================================================================
   Klinikids — Anlaşmalı Kurumlar (çocuk ve yetişkin sayfalarında ortak)

   Kurumlar YÖNETİM PANELİ → "Kurumlar" sayfasından eklenir / çıkarılır.
   Liste veritabanından okunur; burada bir şey değiştirmenize gerek yok.
   Aktif kurum yoksa bölüm sitede gizlenir.
   ===================================================================== */
(() => {
const SB = 'https://xnjnxogvnldrbridzvqu.supabase.co';
const KEY = 'sb_publishable_CpJXbL8ZBECdSo3uSYXqdg_5W1DStIb';
const box = document.getElementById('kurumlar');
const sec = document.getElementById('anlasmali');
if (!box) return;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const logoUrl = p => p ? `${SB}/storage/v1/object/public/kurum-logolari/${encodeURIComponent(p)}` : '';
const hide = () => { if (sec) sec.hidden = true; else box.hidden = true; };

fetch(`${SB}/rest/v1/institutions?select=name,logo_path&is_active=eq.true&order=sort_order,name`, { headers: { apikey: KEY } })
  .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
  .then(list => {
    if (!list.length) return hide();
    const item = (k, dup) => `<li class="kr" title="${esc(k.name)}"${dup ? ' aria-hidden="true"' : ''}>
      ${k.logo_path ? `<img src="${esc(logoUrl(k.logo_path))}" alt="${dup ? '' : esc(k.name)}">` : ''}
      <span class="kr-ph">${esc(k.name)}</span>
    </li>`;
    marquee(list, item);
  })
  .catch(hide);

/* Logolar sağdan sola sürekli kayar; üzerine gelince durur. */
function marquee(list, item) {
  if (!document.getElementById('krRunCss')) {
    const st = document.createElement('style'); st.id = 'krRunCss';
    st.textContent = `
.kr-view{overflow:hidden;-webkit-mask-image:linear-gradient(90deg,transparent,#000 6%,#000 94%,transparent);mask-image:linear-gradient(90deg,transparent,#000 6%,#000 94%,transparent)}
.kr-grid.kr-run{display:flex;gap:0;width:max-content;grid-template-columns:none;padding:8px 0;opacity:1;transform:none;animation:krRun var(--kr-dur,30s) linear infinite}
.kr-view:hover .kr-run{animation-play-state:paused}
.kr-run .kr{width:var(--kr-w,160px);flex:none;margin-right:var(--kr-gap,16px)}
.kr-run .kr img{filter:none;opacity:1}
@keyframes krRun{to{transform:translateX(-50%)}}
@media (max-width:560px){.kr-run{--kr-w:110px;--kr-gap:10px}}
@media (prefers-reduced-motion:reduce){.kr-grid.kr-run{animation:none!important;flex-wrap:wrap;width:auto}}`;
    document.head.appendChild(st);
  }
  // reveal (giriş animasyonu) dış kutuya taşınır; kayma animasyonu ile çakışmasın
  let view = box.parentElement.classList.contains('kr-view') ? box.parentElement : null;
  if (!view) {
    view = document.createElement('div'); view.className = 'kr-view';
    if (box.classList.contains('reveal')) { view.classList.add('reveal', 'in'); box.classList.remove('reveal', 'in'); }
    box.before(view); view.appendChild(box);
  }
  box.classList.add('kr-run');
  const build = () => {
    const w = matchMedia('(max-width:560px)').matches ? 120 : 176;      // kutu + boşluk
    const reps = Math.max(1, Math.ceil((view.clientWidth + w) / (w * list.length)));
    const half = (dup0) => Array.from({ length: reps }, (_, r) => list.map(k => item(k, dup0 || r > 0)).join('')).join('');
    box.innerHTML = half(false) + half(true);                        // iki eş yarı → kesintisiz döngü
    box.style.setProperty('--kr-dur', (reps * list.length * w / 45).toFixed(1) + 's'); // ~45 px/sn
    box.querySelectorAll('.kr img').forEach(img => {
      const ok = () => img.parentElement && img.parentElement.classList.add('has-logo');
      if (img.complete && img.naturalWidth) ok(); else { img.onload = ok; img.onerror = () => img.remove(); }
    });
  };
  build();
  let lastW = view.clientWidth, t;
  addEventListener('resize', () => { clearTimeout(t); t = setTimeout(() => { if (view.clientWidth !== lastW) { lastW = view.clientWidth; build(); } }, 200); });
}
})();
