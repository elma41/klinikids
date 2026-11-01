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
    box.innerHTML = list.map(k => `<li class="kr" title="${esc(k.name)}">
      ${k.logo_path ? `<img src="${esc(logoUrl(k.logo_path))}" alt="${esc(k.name)}" loading="lazy">` : ''}
      <span class="kr-ph">${esc(k.name)}</span>
    </li>`).join('');
    box.querySelectorAll('.kr img').forEach(img => {
      img.onload = () => img.parentElement.classList.add('has-logo');
      img.onerror = () => img.remove();
    });
  })
  .catch(hide);
})();
