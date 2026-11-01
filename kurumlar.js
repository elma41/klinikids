/* =====================================================================
   Klinikids — Anlaşmalı Kurumlar (çocuk ve yetişkin sayfalarında ortak)

   Kurumlar YÖNETİM PANELİ → "Kurumlar" sayfasından eklenir / çıkarılır.
   Liste veritabanından okunur; burada bir şey değiştirmenize gerek yok.
   Logolar sabit, renkli kutular hâlinde dizilir. Aktif kurum yoksa bölüm gizlenir.
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

if (!document.getElementById('krCss')) {
  const st = document.createElement('style'); st.id = 'krCss';
  st.textContent = `
.kr-grid.kr-static{display:flex;flex-wrap:wrap;gap:16px;grid-template-columns:none}
.kr-grid.kr-static.center{justify-content:center}
.kr-static .kr{width:170px;flex:none}
.kr-static .kr img{filter:none;opacity:1}
@media (max-width:560px){.kr-grid.kr-static{gap:10px}.kr-static .kr{width:calc((100% - 20px)/3)}}`;
  document.head.appendChild(st);
}

fetch(`${SB}/rest/v1/institutions?select=name,logo_path&is_active=eq.true&order=sort_order,name`, { headers: { apikey: KEY } })
  .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
  .then(list => {
    if (!list.length) return hide();
    box.classList.add('kr-static');
    box.classList.add('center');   // logolar sayfanın tam ortasında
    box.innerHTML = list.map(k => `<li class="kr" title="${esc(k.name)}">
      ${k.logo_path ? `<img src="${esc(logoUrl(k.logo_path))}" alt="${esc(k.name)}" loading="lazy">` : ''}
      <span class="kr-ph">${esc(k.name)}</span>
    </li>`).join('');
    box.querySelectorAll('.kr img').forEach(img => {
      const ok = () => img.parentElement && img.parentElement.classList.add('has-logo');
      if (img.complete && img.naturalWidth) ok(); else { img.onload = ok; img.onerror = () => img.remove(); }
    });
  })
  .catch(hide);
})();
