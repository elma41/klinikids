/* =====================================================================
   Klinikids — Galeri (çocuk ve yetişkin sayfalarında ortak)
   Kullanım: <div id="galeriApp" data-theme="cocuk"></div>  (veya "yetiskin")
   Resim önce foto/galeri/<dosya adı> klasöründen aranır; yoksa
   aysegulaydin.com.tr'deki kopyası gösterilir.
   ===================================================================== */
(() => {
const root = document.getElementById('galeriApp');
if (!root) return;
const CDN = 'https://aysegulaydin.gumlet.io/img/galeri/';
const FILES = ['araba-son.webp','DSC_4887aaaaa.webp','DSC_4862aaaaa.webp','DSC_4899aaaa.webp','DSC_5229aaaaa.webp','DSC_5116aaaaaa.webp','DSC_5184aaaaa.webp','DSC_5013aaaaa.webp','DSC_5749aaaaa.jpg','DSC_5509aaaa.jpg','DSC_5378aaaaa.jpg','DSC_5420aaaaa.jpg','17.jpg','16.jpg','15.jpg','14.jpg','13.jpg','12.jpg','11.jpg','10.jpg','8.jpg','6.jpg','9.jpg','7.jpg','5.jpg','3.jpg','2.jpg','galeri-26.jpg','galeri-28.jpg','galeri-29.jpg','galeri-35.jpg','galeri-36.jpg','galeri-30.jpg','galeri-31.jpg','galeri-34.jpg','galeri-37.jpg','galeri-38.jpg','1.jpg','galeri-40.jpg','galeri-42.jpg','galeri-43.jpg'];
const KIDS = root.dataset.theme === 'cocuk';
const FIRST = window.innerWidth < 640 ? 6 : window.innerWidth < 900 ? 9 : window.innerWidth < 1100 ? 8 : 10;
const thumb = f => CDN + f + '?w=720&compress=smart&skip_larger=true';
const big = f => CDN + f + '?height=1400&compress=smart&skip_larger=true';
const local = f => 'foto/galeri/' + f;

const css = document.createElement('style');
css.textContent = `
.gl-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.gl-item{aspect-ratio:4/3;margin:0;display:block;width:100%;padding:0;border:0;background:none;cursor:zoom-in;border-radius:${KIDS ? '24px' : '14px'};overflow:hidden;position:relative;box-shadow:0 14px 30px -20px rgba(15,42,68,.5);transition:transform .3s}
.gl-item:hover{transform:translateY(-4px)}
.gl-item img{width:100%;height:100%;object-fit:cover;display:block;background:${KIDS ? '#FFF1C4' : '#E8EEF4'}}
.gl-item[hidden]{display:none}
.gl-more{display:flex;justify-content:center;margin-top:18px}
.gl-more button{font:inherit;font-weight:800;cursor:pointer;padding:13px 24px;border-radius:${KIDS ? '99px' : '12px'};border:${KIDS ? '0' : '1px solid #E2E8EF'};background:${KIDS ? '#FF6F9C' : '#fff'};color:${KIDS ? '#fff' : '#0F2A44'};${KIDS ? 'box-shadow:0 5px 0 #D84C79;' : ''}}
.gl-box{position:fixed;inset:0;z-index:200;background:rgba(8,20,34,.92);display:grid;place-items:center;padding:56px 64px}
.gl-box img{max-width:100%;max-height:calc(100vh - 112px);border-radius:12px;box-shadow:0 30px 60px -20px #000}
.gl-box button{position:absolute;width:48px;height:48px;border-radius:50%;border:0;background:rgba(255,255,255,.14);color:#fff;font-size:26px;cursor:pointer;display:grid;place-items:center}
.gl-box button:hover{background:rgba(255,255,255,.28)}
.gl-box .x{top:14px;right:14px}.gl-box .p{left:12px;top:50%;transform:translateY(-50%)}.gl-box .n{right:12px;top:50%;transform:translateY(-50%)}
.gl-box .c{position:absolute;bottom:16px;left:0;right:0;text-align:center;color:#C9D6E4;font-size:14px;font-weight:700}
@media (max-width:1100px){.gl-grid{grid-template-columns:repeat(4,1fr)}}@media (max-width:900px){.gl-grid{grid-template-columns:repeat(3,1fr)}}@media (max-width:640px){.gl-grid{grid-template-columns:1fr 1fr;gap:8px}.gl-box{padding:60px 8px}.gl-box .p,.gl-box .n{top:auto;bottom:8px;transform:none}}`;
document.head.appendChild(css);

root.innerHTML = `<div class="gl-grid">${FILES.map((f, i) => `<button type="button" class="gl-item" data-i="${i}" ${i >= FIRST ? 'hidden' : ''} aria-label="Fotoğrafı büyüt (${i + 1}/${FILES.length})"><img data-f="${f}" alt="Klinikids kliniğinden bir kare" loading="lazy" decoding="async"></button>`).join('')}</div>
  <div class="gl-more"><button type="button">Tüm fotoğrafları göster (${FILES.length})</button></div>`;

/* Önce yerel kopya, yoksa CDN */
const srcOf = {};
root.querySelectorAll('img[data-f]').forEach(img => {
  const f = img.dataset.f;
  img.onerror = () => { img.onerror = () => img.closest('.gl-item').remove(); img.src = thumb(f); srcOf[f] = big(f); };
  img.src = local(f); srcOf[f] = local(f);
});

const more = root.querySelector('.gl-more');
more.querySelector('button').onclick = () => { root.querySelectorAll('.gl-item[hidden]').forEach(b => b.hidden = false); more.remove(); };

/* Büyük görüntüleyici */
let box = null, cur = 0;
const items = () => [...root.querySelectorAll('.gl-item')];
function show(i) {
  const list = items(); cur = (i + list.length) % list.length;
  const f = list[cur].querySelector('img').dataset.f;
  box.querySelector('img').src = srcOf[f] || big(f);
  box.querySelector('.c').textContent = `${cur + 1} / ${list.length}`;
}
function open(i) {
  box = document.createElement('div'); box.className = 'gl-box'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-label', 'Fotoğraf galerisi');
  box.innerHTML = '<img alt="Klinikids kliniğinden bir kare"><button class="x" aria-label="Kapat">×</button><button class="p" aria-label="Önceki">‹</button><button class="n" aria-label="Sonraki">›</button><div class="c"></div>';
  document.body.appendChild(box); document.body.style.overflow = 'hidden';
  box.querySelector('.x').onclick = close; box.querySelector('.p').onclick = () => show(cur - 1); box.querySelector('.n').onclick = () => show(cur + 1);
  box.addEventListener('click', e => { if (e.target === box) close(); });
  let sx = null; box.addEventListener('touchstart', e => sx = e.touches[0].clientX, { passive: true });
  box.addEventListener('touchend', e => { if (sx === null) return; const d = e.changedTouches[0].clientX - sx; if (Math.abs(d) > 50) show(cur + (d < 0 ? 1 : -1)); sx = null; });
  show(i); box.querySelector('.x').focus();
}
function close() { box && box.remove(); box = null; document.body.style.overflow = ''; }
addEventListener('keydown', e => { if (!box) return; if (e.key === 'Escape') close(); if (e.key === 'ArrowRight') show(cur + 1); if (e.key === 'ArrowLeft') show(cur - 1); });
root.addEventListener('click', e => { const b = e.target.closest('.gl-item'); if (b) open(items().indexOf(b)); });
})();
