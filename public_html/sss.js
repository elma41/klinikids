/* =====================================================================
   Klinikids — Sık Sorulan Sorular (çocuk ve yetişkin sayfalarında ortak)
   Sorular YÖNETİM PANELİ → "SSS" sayfasından eklenir, düzenlenir, sıralanır.
   Veritabanına ulaşılamazsa sayfadaki mevcut sorular olduğu gibi kalır.
   Hiç aktif soru yoksa bölüm gizlenir.
   ===================================================================== */
(() => {
const SB = '/api';
const KEY = 'sb_publishable_CpJXbL8ZBECdSo3uSYXqdg_5W1DStIb';
const box = document.querySelector('[data-sss]');
if (!box) return;
const clinic = box.dataset.sss === 'cocuk' ? 'cocuk' : 'yetiskin';
const sec = box.closest('section');
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 5000);
fetch(`${SB}/rest/v1/faqs?select=question,answer&clinic=eq.${clinic}&is_active=eq.true&order=sort_order,created_at`, { headers: { apikey: KEY }, signal: ctl.signal })
  .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)))
  .then(list => {
    if (!list.length) { document.getElementById('ld-sss')?.remove(); if (sec) sec.hidden = true; document.querySelectorAll('a[href="#sss"]').forEach(a => a.hidden = true); return; }
    box.innerHTML = list.map(f => `<details><summary>${esc(f.question)}</summary><p>${esc(f.answer).replace(/\n/g, '<br>')}</p></details>`).join('');
    // Google için soru-cevap verisi: panelde yapılan değişiklikler otomatik yansısın
    const ld = document.getElementById('ld-sss');
    if (ld) try {
      const d = JSON.parse(ld.textContent);
      d.mainEntity = list.map(f => ({ '@type': 'Question', name: f.question, acceptedAnswer: { '@type': 'Answer', text: f.answer } }));
      ld.textContent = JSON.stringify(d);
    } catch (e) {}
    if (matchMedia('(hover:hover)').matches) box.querySelectorAll('details').forEach(d => {
      d.addEventListener('mouseenter', () => d.open = true); d.addEventListener('mouseleave', () => d.open = false);
      d.querySelector('summary').addEventListener('click', e => e.preventDefault());
    });
  })
  .catch(() => {})
  .finally(() => clearTimeout(t));
})();
