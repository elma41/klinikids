/* =====================================================================
   Klinikids — Hekim kartları (çocuk ve yetişkin sayfalarında ortak)
   Sayfa, window.HEKIMLER listesini tanımlar; bu dosya kartları çizer.
   Yeni hekim eklemek için sayfadaki listeye bir kayıt eklemeniz yeterli.
   ===================================================================== */
(() => {
const box = document.getElementById('hekimler');
const list = window.HEKIMLER || [];
if (!box || !list.length) return;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ini = n => n.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toLocaleUpperCase('tr-TR')).join('');
const wa = t => 'https://wa.me/905464217141?text=' + encodeURIComponent(t);
const WA = '<svg viewBox="0 0 24 24" aria-hidden="true"><use href="#i-wa"/></svg>';

box.innerHTML = list.map((h, i) => {
  const more = (h.edu && h.edu.length) || (h.exp && h.exp.length) || h.about || (h.hobbies && h.hobbies.length) || h.lang;
  return `<article class="hk reveal" style="--c:${esc(h.color || 'var(--teal)')}">
  <div class="hk-photo"><span class="hk-ini">${esc(ini(h.name))}</span>${h.photo ? `<img src="${esc(h.photo)}" alt="${esc(h.title + ' ' + h.name)}" loading="lazy" data-alt="${esc(h.photoAlt || '')}">` : ''}${h.badge ? `<span class="hk-badge">${esc(h.badge)}</span>` : ''}</div>
  <div class="hk-body">
    <h3>${esc(h.title)} ${esc(h.name)}</h3>
    <div class="hk-role">${esc(h.role)}</div>
    <p class="hk-intro">${esc(h.intro)}</p>
    ${h.facts && h.facts.length ? `<div class="hk-facts">${h.facts.map(f => `<div><b>${esc(f[0])}</b><span>${esc(f[1])}</span></div>`).join('')}</div>` : ''}
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
      <a class="hk-btn hk-sub" href="${wa(h.waMsg || ('Merhaba, ' + h.title + ' ' + h.name + ' hakkında bilgi almak istiyorum.'))}" target="_blank" rel="noopener">${WA} Soru Sor</a>
    </div>
  </div>
</article>`;
}).join('');

/* Fotoğraf: önce foto/ klasörü, yoksa yedek adres, o da yoksa baş harfler */
box.querySelectorAll('.hk-photo img').forEach(img => {
  img.onerror = () => { const alt = img.dataset.alt; if (alt && img.src !== alt) { img.dataset.alt = ''; img.src = alt; } else img.remove(); };
});
/* Görünür olunca beliren animasyon (sayfanın kendi gözlemcisi bunları kaçırmasın) */
const io = new IntersectionObserver(en => en.forEach(x => { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } }), { threshold: .1 });
box.querySelectorAll('.reveal').forEach(el => io.observe(el));
})();
