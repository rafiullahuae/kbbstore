import { tt } from '../i18n.js';
import { esc, toast, empty, tip, txt } from '../ui.js';
import { api, getAccount } from '../api.js';
import { dropList, S as STORE } from '../main.js';
import { renderSpec, FAMILIES as TEMPLATES, FAMILY_LABELS, toPNG } from '../creative/render.js';
import { renderVideo } from '../creative/motion.js';
import { score, shipDesign } from '../creative/quality.js';
import { generateBackground } from '../creative/gen-ui.js';

let tab = 'images', langF = 'all', busy = '';
const thumbs = new Map();
let lastMotion = null;
const TPL = Object.assign({ clean: FAMILY_LABELS.hero }, FAMILY_LABELS);
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

// Every format is scored first (creative/quality.js); below threshold it is
// refused, not posted.
export async function postDesigns(designs, source) {
  let refused = 0;
  for (const d of designs) refused += (await shipDesign(d, source, renderSpec, toPNG)).refused.length;
  if (refused) toast(tt(`${refused} format(s) held back by the quality check (see the design).`, `حُجبت ${refused} صيغة لعدم اجتيازها فحص الجودة.`));
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

async function preview(d0) {
  if (!d0) return;
  const d = Object.assign({}, d0);
  const g = document.getElementById('dlg');
  g.style.maxWidth = '980px';
  const paint = async () => {
    const ar = d.lang === 'ar';
    g.innerHTML = `<div class="dl"><div class="spread"><h2>${txt(d.headline)}</h2><button class="ghost small" id="x">✕</button></div>
    <div class="row" style="margin:6px 0 12px"><label class="f"><span>${esc(tt('Design', 'التصميم'))}</span><select id="p-fam">${TEMPLATES.map((k) => `<option value="${k}" ${(d.template === k || (d.template === 'clean' && k === 'hero')) ? 'selected' : ''}>${esc(tt(...TPL[k]))}</option>`).join('')}</select></label>
      ${ar ? `<label class="f"><span>${esc(tt('Numerals', 'الأرقام'))}</span><select id="p-num"><option value="arab" ${d.numerals !== 'latn' ? 'selected' : ''}>٠١٢٣</option><option value="latn" ${d.numerals === 'latn' ? 'selected' : ''}>0123</option></select></label>` : ''}
      <button type="button" class="ghost" id="p-ai" style="align-self:flex-end">${esc(d.bg ? tt('New AI background', 'خلفية جديدة بالذكاء الاصطناعي') : tt('AI background…', 'خلفية بالذكاء الاصطناعي…'))}</button>
      ${d.bg ? `<button type="button" class="ghost" id="p-noai" style="align-self:flex-end">${esc(tt('Original art', 'التصميم الأصلي'))}</button>` : ''}
      <button type="button" id="p-ship" style="align-self:flex-end">${esc(tt('Add to library', 'إضافة إلى المكتبة'))}</button></div>
    <div class="mock"><div><p class="small muted">${esc(tt('Feed 4:5', 'الخلاصة ٤:٥'))}</p><div class="phone"><div class="scr"><div class="post"><header><i></i><b>kbeautybliss</b><span class="muted">· ${esc(tt('Sponsored', 'مُموَّل'))}</span></header>
      <div class="txt" ${ar ? 'dir="rtl" lang="ar"' : ''}>${esc(d.primary_text || d.sub || '')}</div><canvas id="pf"></canvas>
      <div class="bar" ${ar ? 'dir="rtl"' : ''}><span>kbeautybliss.com<br><b>${esc(d.headline)}</b></span><span class="chip">${esc(ar ? 'تسوّقي الآن' : 'Shop now')}</span></div></div></div></div></div>
    <div><p class="small muted">${esc(tt('Story / Reels 9:16', 'ستوري / ريلز ٩:١٦'))}</p><div class="phone"><div class="scr"><div class="story"><canvas id="ps"></canvas><div class="cta">⌃ ${esc(ar ? 'تسوّقي الآن' : 'Shop now')}</div></div></div></div></div>
    <div style="flex:1;min-width:220px"><p class="small muted">${esc(tt('Quality check', 'فحص الجودة'))}</p><div id="pq"><div class="sk" style="height:160px"></div></div></div></div></div>`;
    g.querySelector('#x').onclick = () => g.close();
    g.querySelector('#p-fam').onchange = (e) => { d.template = e.target.value; paint(); };
    const pn = g.querySelector('#p-num'); if (pn) pn.onchange = (e) => { d.numerals = e.target.value; paint(); };
    const na = g.querySelector('#p-noai'); if (na) na.onclick = () => { delete d.bg; paint(); };
    g.querySelector('#p-ai').onclick = async () => {
      const pf = g.querySelector('#pf');
      try {
        const bg = await generateBackground(d, '4:5', 'image', (pf.assets && pf.assets[0] && pf.assets[0].palette) || [], askCost);
        if (bg) { d.bg = bg; toast(tt(`Background made ($${bg.usd.toFixed(2)}). Your product photo is on top.`, `تم إنشاء الخلفية ($${bg.usd.toFixed(2)}).`)); paint(); }
      } catch (e) { toast(e.message); }
    };
    g.querySelector('#p-ship').onclick = async () => {
      const r = await shipDesign(d, 'owner', renderSpec, toPNG).catch((e) => { toast(e.message); return null; });
      if (r) { toast(r.refused.length ? tt(`${r.posted} added, ${r.refused.length} held back by the quality check.`, `أُضيفت ${r.posted}، وحُجبت ${r.refused.length}.`) : tt(`${r.posted} formats added.`, `أُضيفت ${r.posted} صيغ.`)); dropList('creatives'); }
    };
    const pf = await renderSpec(d, '4:5', g.querySelector('#pf'));
    await renderSpec(d, '9:16', g.querySelector('#ps'));
    const qs = [];
    for (const f of d.formats || ['1:1', '4:5', '9:16']) qs.push([f, await score(d, f, pf.assets)]);
    const pq = g.querySelector('#pq');
    if (pq) pq.innerHTML = `<table class="small"><tbody>${qs.map(([f, q]) => `<tr><td><b>${esc(f)}</b></td><td><span class="chip ${q.ok ? 'acc' : ''}">${q.ok ? '✓' : '✕'} ${q.score}</span></td><td>${esc(tt('product', 'المنتج'))} ${Math.round(q.checks.product_share * 100)}% · ${esc(tt('contrast', 'التباين'))} ${q.checks.min_contrast} · ${esc(tt('text', 'النص'))} ${Math.round(q.checks.text_density * 100)}%${q.fails.length ? `<br><span style="color:var(--danger)">${q.fails.map(esc).join('<br>')}</span>` : ''}</td></tr>`).join('')}</tbody></table>
      <p class="small muted">${esc(tt('Cut-out', 'القصّ'))}: ${esc((qs[0] || [null, { checks: {} }])[1].checks.cutout || '')}</p>`;
  };
  g.showModal();
  await paint();
}

// The price, the provider and the prompt (editable) BEFORE anything is paid.
function askCost({ provider, est, prompt }) {
  return new Promise((ok) => {
    const box = document.createElement('dialog');
    box.style.maxWidth = '640px';
    box.innerHTML = `<div class="dl"><h2>${esc(tt('Generate with AI', 'إنشاء بالذكاء الاصطناعي'))}</h2>
      <p><b>${esc(provider ? provider.name : est.provider)}</b> · <b>$${est.usd.toFixed(2)}</b> ${est.seconds ? `(${est.seconds} s)` : ''} · ${esc(tt('this month', 'هذا الشهر'))} $${est.spent_usd.toFixed(2)} / $${est.budget_usd.toFixed(2)}</p>
      <p class="small muted">${esc(est.price_note)}</p>
      ${est.ok ? '' : `<p class="small" style="color:var(--danger)">${esc(est.why)}</p>`}
      <label class="f"><span>${esc(tt('Art direction (written by the brain; edit if you like)', 'التوجيه الفني (كتبه الذكاء؛ يمكنكِ تعديله)'))}</span><textarea rows="7" style="width:100%">${esc(prompt)}</textarea></label>
      <div class="row" style="margin-top:10px;justify-content:flex-end"><button type="button" class="ghost" data-no>${esc(tt('Cancel', 'إلغاء'))}</button><button type="button" data-yes ${est.ok ? '' : 'disabled'}>${esc(tt(`Generate for $${est.usd.toFixed(2)}`, `إنشاء مقابل $${est.usd.toFixed(2)}`))}</button></div></div>`;
    document.body.appendChild(box);
    const done = (v) => { box.close(); box.remove(); ok(v); };
    box.querySelector('[data-no]').onclick = () => done(null);
    box.querySelector('[data-yes]').onclick = () => done(box.querySelector('textarea').value.trim());
    box.showModal();
  });
}

function motion(S, v, designs) {
  const src = designs.filter((d) => langF === 'all' || d.lang === langF);
  v.innerHTML = `<div class="grid g21"><div class="card"><header><h2>${esc(tt('Make a motion ad', 'صنع إعلان متحرك'))} ${tip(tt('6–15 s MP4: the product is revealed with a light sweep and particles, the headline writes in (Arabic right to left), benefits arrive as captions, a Ken Burns moment on the product photo, then an end card with the price and the call to action. Add your music and the cuts follow its beat.', 'فيديو MP4 من ٦ إلى ١٥ ثانية: ظهور المنتج، العنوان يُكتب من اليمين، المزايا، ثم بطاقة السعر والدعوة. أضيفي موسيقاكِ فتتبع القطعات الإيقاع.'))}</h2></header>
    ${src.length ? `<div class="row"><label class="f" style="flex:1"><span>${esc(tt('Design', 'التصميم'))}</span><select id="m-src">${src.map((d) => `<option value="${esc(d.id)}">${esc((d.lang || '').toUpperCase())} · ${esc(d.headline)}</option>`).join('')}</select></label>
    <label class="f"><span>${esc(tt('Format', 'المقاس'))}</span><select id="m-f"><option>9:16</option><option>4:5</option><option>1:1</option></select></label>
    <label class="f"><span>${esc(tt('Seconds', 'الثواني'))}</span><input id="m-d" type="number" min="6" max="15" value="10" style="width:80px"></label>
    <label class="f"><span>${esc(tt('Music (optional)', 'موسيقى (اختياري)'))}</span><input id="m-a" type="file" accept="audio/*"></label></div>
    <div class="row" style="margin-top:10px"><button type="button" id="m-go" ${busy ? 'disabled' : ''}>${esc(tt('Render MP4', 'إنشاء MP4'))}</button><button type="button" class="ghost" id="m-ai">${esc(tt('AI moving background…', 'خلفية متحركة بالذكاء الاصطناعي…'))}</button><span class="small muted" id="m-p"></span></div>` : `<p class="muted">${esc(tt('Make an image pair first; motion ads are built from designs.', 'أنشئي تصميماً أولاً؛ الإعلانات المتحركة تُبنى من التصاميم.'))}</p>`}
    </div>
    <div class="card"><header><h2>${esc(tt('Preview', 'معاينة'))}</h2></header><div class="phone" style="margin:auto"><div class="scr"><div class="story" id="m-out">${lastMotion ? `<video controls playsinline loop autoplay muted src="${lastMotion.url}" style="width:100%;height:100%;object-fit:cover"></video>` : ''}<div class="cta">⌃ ${esc(tt('Shop now', 'تسوّقي الآن'))}</div></div></div></div></div></div>`;
  const goB = v.querySelector('#m-go');
  if (!goB) return;
  let aiBg = null;
  v.querySelector('#m-ai').onclick = async () => {
    const d = src.find((x) => x.id === v.querySelector('#m-src').value);
    try { const bg = await generateBackground(Object.assign({}, d), v.querySelector('#m-f').value, 'video', [], askCost); if (bg) { aiBg = bg; toast(tt('Moving background ready; render the MP4.', 'الخلفية المتحركة جاهزة.')); } } catch (e) { toast(e.message); }
  };
  goB.onclick = async () => {
    const d = Object.assign({}, src.find((x) => x.id === v.querySelector('#m-src').value), aiBg ? { bg: aiBg } : {});
    const f = v.querySelector('#m-f').value, dur = Math.max(6, Math.min(15, +v.querySelector('#m-d').value || 10));
    busy = 'motion'; goB.disabled = true;
    const p = v.querySelector('#m-p');
    try {
      const r = await renderVideo(d, f, dur, v.querySelector('#m-a').files[0] || null, (x) => { p.textContent = `${Math.round(x * 100)}%`; });
      if (lastMotion) URL.revokeObjectURL(lastMotion.url);
      lastMotion = { url: URL.createObjectURL(r.blob) };
      const b64 = await new Promise((ok) => { const fr = new FileReader(); fr.onload = () => ok(String(fr.result).split(',')[1]); fr.readAsDataURL(r.blob); });
      await api('/api/creatives', { method: 'POST', body: { lang: d.lang, format: f, video: b64, headline: d.headline, primary_text: d.primary_text || d.sub || '', cta: d.cta || 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: d.product_ids, angle: d.angle, source: 'brain' } });
      p.textContent = `${r.mime} · ${r.codec} · ${(r.blob.size / 1e6).toFixed(2)} MB · ${dur} s${r.beats ? ' · ' + r.beats + ' ' + tt('beats', 'إيقاع') : ''}`;
      window.__kaaMotion = { mime: r.mime, bytes: r.blob.size, width: r.width, height: r.height, ms: r.ms };
      dropList('creatives');
    } catch (e) { toast(e.message); p.textContent = e.message; }
    busy = '';
    const out = v.querySelector('#m-out');
    if (still() && lastMotion && out) out.innerHTML = `<video controls playsinline loop autoplay muted src="${lastMotion.url}" style="width:100%;height:100%;object-fit:cover"></video><div class="cta">⌃ ${esc(tt('Shop now', 'تسوّقي الآن'))}</div>`;
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
