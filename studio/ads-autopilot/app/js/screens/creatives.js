import { tt } from '../i18n.js';
import { esc, toast, empty, tip, txt } from '../ui.js';
import { api, getAccount } from '../api.js';
import { dropList, S as STORE } from '../main.js';
import { renderSpec, TEMPLATES, toPNG } from '../creative/render.js';
import { recordMotion } from '../creative/motion.js';

let tab = 'images', langF = 'all', busy = '';
const thumbs = new Map();
let lastMotion = null;
const TPL = { clean: ['Clean K-beauty', 'كوري أنيق'], glow: ['Glow / dewy', 'توهّج ونضارة'], ingredient: ['Ingredient spotlight', 'المكوّن البطل'], offer: ['Price badge', 'شارة السعر'], routine: ['Routine steps', 'خطوات الروتين'], qa: ['Product Q&A', 'أسئلة المنتج'] };
const still = () => location.hash.startsWith('#/creatives');

export function render(S, el) {
  const designs = S.cache.get(`${getAccount()}|designs`);
  const lib = S.cache.get(`${getAccount()}|creatives`);
  el.innerHTML = `<div class="page"><div class="page-h"><div><h1>${esc(tt('Creatives', 'الإعلانات'))}</h1><p>${esc(tt('Every idea ships as an Arabic + English pair in 1:1, 4:5 and 9:16. Original art from the shop\'s own photos.', 'كل فكرة بنسختين عربية وإنجليزية بثلاثة مقاسات، من صور المتجر نفسها.'))}</p></div>
    <div class="row"><div class="tabs">${[['images', 'Images', 'الصور'], ['motion', 'Motion ads', 'إعلانات متحركة']].map(([k, en, ar]) => `<button type="button" data-tab="${k}" class="${tab === k ? 'on' : ''}">${esc(tt(en, ar))}</button>`).join('')}</div>
    <div class="seg">${[['all', 'All', 'الكل'], ['ar', 'Arabic', 'العربية'], ['en', 'English', 'الإنجليزية']].map(([k, en, ar]) => `<button type="button" data-l="${k}" class="${langF === k ? 'on' : ''}">${esc(tt(en, ar))}</button>`).join('')}</div></div></div><div id="cv"></div></div>`;
  el.querySelectorAll('[data-tab]').forEach((b) => { b.onclick = () => { tab = b.dataset.tab; render(S, el); }; });
  el.querySelectorAll('[data-l]').forEach((b) => { b.onclick = () => { langF = b.dataset.l; render(S, el); }; });
  const v = el.querySelector('#cv');
  if (!designs || !lib) { v.innerHTML = `<div class="lib">${'<div class="sk" style="height:320px"></div>'.repeat(8)}</div>`; return; }
  if (tab === 'images') images(S, v, designs, lib); else motion(S, v, designs, lib);
}

function images(S, v, designs, lib) {
  const ds = designs.filter((d) => langF === 'all' || d.lang === langF).slice().reverse();
  const plain = lib.filter((c) => (langF === 'all' || c.lang === langF) && !ds.some((d) => d.lang === c.lang && d.headline === c.headline));
  v.innerHTML = `<div class="card"><header><h2>${esc(tt('Make a new pair', 'إنشاء زوج جديد'))} ${tip(tt('Claude writes Arabic + English copy for the product and angle, the policy checker reviews it, and the design renders here.', 'يكتب Claude نسختين عربية وإنجليزية، ويراجعها فاحص السياسات، ويُرسم التصميم هنا.'))}</h2></header>
    <div class="row"><label class="f"><span>${esc(tt('Product ID (from the shop)', 'رقم المنتج'))}</span><input id="g-p" style="width:130px" placeholder="3195"></label>
    <label class="f"><span>${esc(tt('Angle', 'الزاوية'))}</span><input id="g-a" style="width:260px" value="${esc(tt('gentle morning routine', 'روتين صباحي لطيف'))}"></label>
    <label class="f"><span>${esc(tt('Design', 'التصميم'))}</span><select id="g-t">${TEMPLATES.map((k) => `<option value="${k}">${esc(tt(...TPL[k]))}</option>`).join('')}</select></label>
    <button type="button" id="gen" style="align-self:flex-end" ${busy ? 'disabled' : ''}>${esc(busy ? tt('Working…', 'جارٍ العمل…') : tt('Create', 'إنشاء'))}</button></div></div>
  ${ds.length || plain.length ? `<div class="lib">${ds.map((d) => `<div class="cr" data-d="${esc(d.id)}" tabindex="0" role="button"><div class="media" data-thumb="${esc(d.id)}">${thumbs.has(d.id) ? `<img src="${thumbs.get(d.id)}" alt="">` : '<div class="sk" style="height:100%"></div>'}<span class="tag">${esc((d.lang || '').toUpperCase())} · ${esc((d.formats || ['1:1']).join(' / '))}</span></div>
    <div class="meta"><b>${txt(d.headline)}</b><div class="row"><span class="chip">${esc(tt(...(TPL[d.template] || [d.template, d.template])))}</span><span class="chip">${esc(d.angle || '')}</span></div></div></div>`).join('')}
    ${plain.map((c) => `<div class="cr"><div class="media" style="display:grid;place-items:center;padding:16px;text-align:center"><span>${txt(c.headline)}</span><span class="tag">${esc((c.lang || '').toUpperCase())} · ${esc(c.format || '')}</span></div><div class="meta"><span class="muted">${txt(c.primary_text || '')}</span></div></div>`).join('')}</div>`
    : `<div class="card">${empty(tt('No creatives yet', 'لا توجد إعلانات بعد'), tt('Create one above, or let the autopilot make them on the weekly batch day.', 'أنشئي إعلاناً أعلاه أو اتركي الطيار الآلي ينشئها في يوم الدفعة الأسبوعي.'))}</div>`}`;
  fill(v, ds);
  v.querySelectorAll('[data-d]').forEach((n) => { n.onclick = () => preview(designs.find((d) => d.id === n.dataset.d)); n.onkeydown = (e) => { if (e.key === 'Enter') n.click(); }; });
  v.querySelector('#gen').onclick = async () => {
    const pid = v.querySelector('#g-p').value.trim();
    if (!pid) { toast(tt('Type a product ID from the shop.', 'اكتبي رقم منتج من المتجر.')); return; }
    busy = 'gen'; images(S, v, designs, lib);
    try {
      const r = await api('/api/brain/x/copy', { method: 'POST', body: { product_ids: [pid], angle: v.querySelector('#g-a').value, template: v.querySelector('#g-t').value } });
      await postDesigns(r.designs || [], 'owner');
      toast(tt('New Arabic + English pair added in 3 formats.', 'أُضيفت نسختان عربية وإنجليزية بثلاثة مقاسات.'));
    } catch (e) { toast(e.message); }
    busy = '';
    dropList('designs', 'creatives');
    if (still()) { await load(STORE); render(STORE, document.getElementById('view')); }
  };
}

export async function postDesigns(designs, source) {
  for (const d of designs) for (const f of d.formats || ['1:1']) {
    const { b64 } = await toPNG(await renderSpec(d, f));
    await api('/api/creatives', { method: 'POST', body: { lang: d.lang, format: f, png: b64, headline: d.headline, primary_text: d.primary_text || d.sub || '', cta: d.cta || 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: d.product_ids, angle: d.angle, source } });
  }
}

async function fill(v, ds) {
  for (const d of ds) {
    if (thumbs.has(d.id)) continue;
    const cv = await renderSpec(d, '4:5');
    const s = document.createElement('canvas'); s.width = 320; s.height = 400; s.getContext('2d').drawImage(cv, 0, 0, 320, 400);
    thumbs.set(d.id, s.toDataURL('image/jpeg', 0.82));
    if (!still()) return;
    const slot = v.querySelector(`[data-thumb="${CSS.escape(d.id)}"] .sk`);
    if (slot) slot.outerHTML = `<img src="${thumbs.get(d.id)}" alt="">`;
  }
}

async function preview(d) {
  if (!d) return;
  const g = document.getElementById('dlg');
  g.style.maxWidth = '760px';
  const ar = d.lang === 'ar';
  g.innerHTML = `<div class="dl"><div class="spread"><h2>${txt(d.headline)}</h2><button class="ghost small" id="x">✕</button></div>
  <div class="mock"><div><p class="small muted">${esc(tt('Feed', 'الخلاصة'))}</p><div class="phone"><div class="scr"><div class="post"><header><i></i><b>kbeautybliss</b><span class="muted">· ${esc(tt('Sponsored', 'مُموَّل'))}</span></header>
    <div class="txt" ${ar ? 'dir="rtl" lang="ar"' : ''}>${esc(d.primary_text || d.sub || '')}</div><canvas id="pf"></canvas>
    <div class="bar" ${ar ? 'dir="rtl"' : ''}><span>kbeautybliss.com<br><b>${esc(d.headline)}</b></span><span class="chip">${esc(ar ? 'تسوّقي الآن' : 'Shop now')}</span></div></div></div></div></div>
  <div><p class="small muted">${esc(tt('Story / Reels', 'ستوري / ريلز'))}</p><div class="phone"><div class="scr"><div class="story"><canvas id="ps"></canvas><div class="cta">⌃ ${esc(ar ? 'تسوّقي الآن' : 'Shop now')}</div></div></div></div></div></div></div>`;
  g.showModal();
  g.querySelector('#x').onclick = () => g.close();
  await renderSpec(d, '4:5', g.querySelector('#pf'));
  await renderSpec(d, '9:16', g.querySelector('#ps'));
}

function motion(S, v, designs) {
  const src = designs.filter((d) => langF === 'all' || d.lang === langF);
  v.innerHTML = `<div class="grid g21"><div class="card"><header><h2>${esc(tt('Make a motion ad', 'صنع إعلان متحرك'))} ${tip(tt('6–15 s video from a design: the product and text animate in, captions are burned in (Arabic for Arabic ads) inside the Reels safe zone, then a call-to-action end card. Add your own music if you like.', 'فيديو ٦–١٥ ثانية من التصميم مع ترجمة مدمجة داخل المنطقة الآمنة وبطاقة ختامية.'))}</h2></header>
    ${src.length ? `<div class="row"><label class="f" style="flex:1"><span>${esc(tt('Design', 'التصميم'))}</span><select id="m-src">${src.map((d) => `<option value="${esc(d.id)}">${esc((d.lang || '').toUpperCase())} · ${esc(d.headline)}</option>`).join('')}</select></label>
    <label class="f"><span>${esc(tt('Format', 'المقاس'))}</span><select id="m-f"><option>9:16</option><option>4:5</option></select></label>
    <label class="f"><span>${esc(tt('Seconds', 'الثواني'))}</span><input id="m-d" type="number" min="6" max="15" value="8" style="width:80px"></label>
    <label class="f"><span>${esc(tt('Music (optional)', 'موسيقى (اختياري)'))}</span><input id="m-a" type="file" accept="audio/*"></label></div>
    <div class="row" style="margin-top:10px"><button type="button" id="m-go" ${busy ? 'disabled' : ''}>${esc(tt('Render', 'إنشاء'))}</button><span class="small muted" id="m-p"></span></div>` : `<p class="muted">${esc(tt('Make an image pair first; motion ads are built from designs.', 'أنشئي تصميماً أولاً؛ الإعلانات المتحركة تُبنى من التصاميم.'))}</p>`}
    <div id="m-out" style="margin-top:12px">${lastMotion ? `<video controls playsinline src="${lastMotion.url}" style="max-height:420px;border-radius:16px"></video>` : ''}</div></div>
    <div class="card"><header><h2>${esc(tt('How it is built', 'كيف يُصنع'))}</h2></header><ul class="small ink2" style="padding-inline-start:18px;margin:0">
      <li>${esc(tt('Recorded in the browser from the template (no upload of your photos anywhere).', 'يُسجَّل داخل المتصفح من القالب.'))}</li><li>${esc(tt('Captions stay above the bottom 35% and below the top 14% of a 9:16 frame (Reels UI).', 'تبقى الترجمة خارج أعلى ١٤٪ وأسفل ٣٥٪ من الإطار.'))}</li>
      <li>${esc(tt('MP4 when the browser can record it, otherwise WebM; saved to the library.', 'MP4 إن أمكن وإلا WebM؛ يُحفظ في المكتبة.'))}</li></ul></div></div>`;
  const goB = v.querySelector('#m-go');
  if (!goB) return;
  goB.onclick = async () => {
    const d = src.find((x) => x.id === v.querySelector('#m-src').value);
    const f = v.querySelector('#m-f').value, dur = Math.max(6, Math.min(15, +v.querySelector('#m-d').value || 8));
    busy = 'motion'; goB.disabled = true;
    const p = v.querySelector('#m-p');
    try {
      const r = await recordMotion(d, f, dur, v.querySelector('#m-a').files[0] || null, (x) => { p.textContent = `${Math.round(x * 100)}%`; });
      if (lastMotion) URL.revokeObjectURL(lastMotion.url);
      lastMotion = { url: URL.createObjectURL(r.blob) };
      const b64 = await new Promise((ok) => { const fr = new FileReader(); fr.onload = () => ok(String(fr.result).split(',')[1]); fr.readAsDataURL(r.blob); });
      await api('/api/creatives', { method: 'POST', body: { lang: d.lang, format: f, video: b64, headline: d.headline, primary_text: d.primary_text || d.sub || '', cta: d.cta || 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: d.product_ids, angle: d.angle, source: 'brain' } });
      p.textContent = `${r.mime} · ${Math.round(r.blob.size / 1024)} KB · ${dur} s`;
      window.__kaaMotion = { mime: r.mime, bytes: r.blob.size, width: r.width, height: r.height, ms: r.ms };
      dropList('creatives');
    } catch (e) { toast(e.message); p.textContent = e.message; }
    busy = '';
    if (still() && lastMotion) v.querySelector('#m-out').innerHTML = `<video controls playsinline src="${lastMotion.url}" style="max-height:420px;border-radius:16px"></video>`;
    goB.disabled = false;
  };
}

export async function load(S) {
  const id = getAccount();
  const need = [];
  if (!S.cache.has(`${id}|designs`)) need.push(api('/api/brain/x/designs').catch(() => []).then((x) => S.cache.set(`${id}|designs`, x)));
  if (!S.cache.has(`${id}|creatives`)) need.push(api('/api/creatives').catch(() => []).then((x) => S.cache.set(`${id}|creatives`, x || [])));
  if (!need.length) return false;
  await Promise.all(need);
  return still();
}
