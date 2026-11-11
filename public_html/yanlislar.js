/* =====================================================================
   Klinikids — Doğru Bilinen Yanlışlar (çocuk ve yetişkin sayfalarında ortak)

   Maddeler YÖNETİM PANELİ → "Doğru Bilinen Yanlışlar" sayfasından
   eklenir, düzenlenir, sıralanır, gizlenir veya silinir.
   Liste veritabanından okunur. Veritabanına ulaşılamazsa sayfadaki
   yedek liste (window.YANLIS_YEDEK) gösterilir. Hiç aktif madde yoksa bölüm gizlenir.
   ===================================================================== */
(() => {
const SB = 'https://xnjnxogvnldrbridzvqu.supabase.co';
const KEY = 'sb_publishable_CpJXbL8ZBECdSo3uSYXqdg_5W1DStIb';
const box = document.querySelector('[data-yanlis]');
if (!box) return;
const clinic = box.dataset.yanlis;               // 'cocuk' | 'yetiskin'
const sec = box.closest('section');
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const card = clinic === 'cocuk'
  ? m => `<details class="myth reveal"><summary><span class="q">${esc(m.emoji || '🦷')}</span><span>“${esc(m.myth)}”<span class="hint">Doğru mu? Üzerine gelin 👆</span></span></summary><div class="ans"><b class="v">YANLIŞ</b><br><b class="t">DOĞRUSU</b>${esc(m.truth)}</div></details>`
  : m => `<details class="myth reveal"><summary><span class="x">YANLIŞ</span><span>“${esc(m.myth)}”</span><span class="pl">+</span></summary><div class="ans"><b>DOĞRUSU</b>${esc(m.truth)}</div></details>`;

const navLinks = () => document.querySelectorAll('a[href="#yanlislar"]');
function render(list) {
  if (!list || !list.length) {               // hiç madde yok: bölümü ve menü bağlantısını gizle
    if (sec) sec.hidden = true; else box.hidden = true;
    navLinks().forEach(a => a.hidden = true);
    return;
  }
  if (sec) sec.hidden = false;
  navLinks().forEach(a => a.hidden = false);
  box.innerHTML = list.map(card).join('');
  /* Masaüstünde üzerine gelince aç, çıkınca kapat; dokunmatikte dokununca */
  const hover = matchMedia('(hover:hover)').matches;
  box.querySelectorAll('details.myth').forEach(d => {
    if (hover) {
      d.addEventListener('mouseenter', () => d.open = true);
      d.addEventListener('mouseleave', () => d.open = false);
      d.querySelector('summary').addEventListener('click', e => e.preventDefault());
    }
  });
  /* Görünür olunca beliren animasyon */
  const io = new IntersectionObserver(en => en.forEach(x => { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } }), { threshold: .1 });
  box.querySelectorAll('.reveal').forEach(el => io.observe(el));
}

const fallback = () => render(window.YANLIS_YEDEK || []);
const ctl = new AbortController();
const timer = setTimeout(() => ctl.abort(), 4000);
fetch(`${SB}/rest/v1/myths?select=emoji,myth,truth&clinic=eq.${clinic}&is_active=eq.true&order=sort_order,created_at`,
      { headers: { apikey: KEY }, signal: ctl.signal })
  .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
  .then(render)
  .catch(fallback)
  .finally(() => clearTimeout(timer));
})();
