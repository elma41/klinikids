/* =====================================================================
   Klinikids — Anlaşmalı Kurumlar (çocuk ve yetişkin sayfalarında ortak)

   KURUM EKLEMEK İÇİN:
   1) Logoyu foto/kurumlar klasörüne koyun (kare ya da yatay PNG/JPG/SVG).
   2) Aşağıdaki listede ilgili satırın "ad" ve "logo" kısmını doldurun.
   Logo dosyası bulunamazsa kutuda kurumun adı yazı olarak görünür.
   Henüz eklenmemiş kutular "Logo" yazılı boş kare olarak görünür;
   hiç kurum kalmasın isterseniz listeyi boş bırakın: const KURUMLAR = [];
   ===================================================================== */
const KURUMLAR = [
  { ad: '', logo: 'foto/kurumlar/kurum-1.png' },
  { ad: '', logo: 'foto/kurumlar/kurum-2.png' },
  { ad: '', logo: 'foto/kurumlar/kurum-3.png' },
  { ad: '', logo: 'foto/kurumlar/kurum-4.png' },
  { ad: '', logo: 'foto/kurumlar/kurum-5.png' },
  { ad: '', logo: 'foto/kurumlar/kurum-6.png' },
  { ad: '', logo: 'foto/kurumlar/kurum-7.png' },
  { ad: '', logo: 'foto/kurumlar/kurum-8.png' },
];

(() => {
const box = document.getElementById('kurumlar');
const sec = document.getElementById('anlasmali');
if (!box) return;
if (!KURUMLAR.length) { if (sec) sec.hidden = true; return; }
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
box.innerHTML = KURUMLAR.map(k => `<li class="kr" title="${esc(k.ad)}">
  ${k.logo ? `<img src="${esc(k.logo)}" alt="${esc(k.ad || 'Anlaşmalı kurum logosu')}" loading="lazy">` : ''}
  <span class="kr-ph">${k.ad ? esc(k.ad) : 'Logo'}</span>
</li>`).join('');
box.querySelectorAll('.kr img').forEach(img => {
  img.onload = () => img.parentElement.classList.add('has-logo');
  img.onerror = () => img.remove();
});
})();
