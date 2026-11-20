import { t, tt } from '../i18n.js';
import { esc, aed, num, pct, toast, empty, tip, txt } from '../ui.js';
import { api, authed, getAccount } from '../api.js';
import { loadList, dropList, S as STORE } from '../main.js';
import { renderSpec, TEMPLATES, toPNG } from '../creative/render.js';
import { recordMotion } from '../creative/motion.js';
import { addVideo } from '../creative/video.js';

let tab = 'images', langF = 'all', sel = null, busy = '';
const thumbs = new Map(); // design id -> small JPEG data URL (rendered once)
let lastMotion = null;

const TPL = { clean: ['Clean K-beauty', 'كوري أنيق'], glow: ['Glow / dewy', 'توهّج ونضارة'], ingredient: ['Ingredient spotlight', 'المكوّن البطل'], offer: ['Price badge', 'شارة السعر'], routine: ['Routine steps', 'خطوات الروتين'], qa: ['Product Q&A', 'أسئلة المنتج'] };

function thumbHTML(c) {
  if (c.image_url && c.kind === 'motion') return `<video src="${esc(authed(c.image_url))}" muted loop playsinline preload="metadata"></video>`;
  if (c.image_url) return `<img src="${esc(authed(c.image_url))}" alt="" loading="lazy">`;
  const d = c.design;
  if (d && thumbs.has(d.id + c.format)) return `<img src="${thumbs.get(d.id + c.format)}" alt="">`;
  return `<div class="sk" data-thumb="${esc(c.id)}" style="aspect-ratio:1"></div>`;
}

async function fillThumbs(list, el) {
  for (const c of list) {
    const d = c.design;
    if (!d || c.image_url || thumbs.has(d.id + c.format)) continue;
    const cv = await renderSpec(d, '1:1');
    const s = document.createElement('canvas'); s.width = 360; s.height = 360;
    s.getContext('2d').drawImage(cv, 0, 0, 360, 360);
    thumbs.set(d.id + c.format, s.toDataURL('image/jpeg', 0.82));
    if (!stillCreatives()) return;
    const slot = el.querySelector(`[data-thumb="${CSS.escape(c.id)}"]`);
    if (slot) slot.outerHTML = `<img src="${thumbs.get(d.id + c.format)}" alt="">`;
  }
}
const stillCreatives = () => location.hash.startsWith('#/creatives');

export function render(S, el) {
  const lib = S.cache.get(`${getAccount()}|creatives`);
  const vids = S.cache.get(`${getAccount()}|videos`);
  el.innerHTML = `<div class="page"><div class="spread"><div class="tabs">${[['images', 'Images'], ['videos', 'Videos'], ['motion', 'Motion ads']].map(([k, l]) => `<button type="button" data-tab="${k}" class="${tab === k ? 'on' : ''}">${esc(t(l))}${k === 'videos' && vids ? ` <span class="muted">${vids.length}</span>` : ''}</button>`).join('')}</div>
    <div class="seg">${[['all', 'All'], ['ar', 'Arabic'], ['en', 'English']].map(([k, l]) => `<button type="button" data-l="${k}" class="${langF === k ? 'on' : ''}">${esc(t(l))}</button>`).join('')}</div></div><div id="cv"></div></div>`;
  el.querySelectorAll('[data-tab]').forEach((b) => { b.onclick = () => { tab = b.dataset.tab; render(S, el); }; });
  el.querySelectorAll('[data-l]').forEach((b) => { b.onclick = () => { langF = b.dataset.l; render(S, el); }; });
  const v = el.querySelector('#cv');
  if (tab === 'images') images(S, v, lib);
  else if (tab === 'videos') videos(S, v, vids);
  else motion(S, v, lib);
}

function images(S, v, lib) {
  if (!lib) { v.innerHTML = `<div class="lib">${'<div class="sk" style="height:300px"></div>'.repeat(8)}</div>`; return; }
  const list = lib.filter((c) => c.kind !== 'motion' && (langF === 'all' || c.lang === langF));
  const prods = (STORE.state?.plan?.tests || []).flatMap((x) => x.product_ids || []);
  v.innerHTML = `<div class="card"><header><h2>${esc(t('Generate new'))} ${tip(tt('Claude writes an Arabic + English pair for the product and angle; the policy checker reviews the wording; the design renders here in 1:1, 4:5 and 9:16.', 'يكتب Claude نسختين عربية وإنجليزية؛ يراجع فاحص السياسات الصياغة؛ ويُرسم التصميم هنا بثلاثة مقاسات.'))}</h2></header>
    <div class="row"><label class="f"><span>${esc(tt('Product ID', 'رقم المنتج'))}</span><input id="g-p" value="${esc(prods[0] || '3195')}" style="width:120px"></label>
    <label class="f"><span>${esc(tt('Angle', 'الزاوية'))}</span><input id="g-a" value="${esc(tt('gentle morning routine', 'روتين صباحي لطيف'))}" style="width:240px"></label>
    <label class="f"><span>${esc(tt('Design', 'التصميم'))}</span><select id="g-t">${TEMPLATES.map((k) => `<option value="${k}">${esc(tt(TPL[k][0], TPL[k][1]))}</option>`).join('')}</select></label>
    <button type="button" id="gen" style="align-self:flex-end" ${busy ? 'disabled' : ''}>${esc(busy === 'gen' ? tt('Working…', 'جارٍ العمل…') : t('Generate new'))}</button></div></div>
  ${list.length ? `<div class="lib">${list.map((c) => `<div class="cr" data-cr="${esc(c.id)}" tabindex="0" role="button" aria-label="${esc(t('Preview'))} ${esc(c.headline)}">${thumbHTML(c)}<div class="meta"><div class="spread"><span class="chip">${esc((c.lang || '').toUpperCase())} · ${esc(c.format)}</span><span class="chip">${esc(tt((TPL[c.template] || [c.template])[0], (TPL[c.template] || [, c.template])[1]))}</span></div>
    <b>${txt(c.headline)}</b>${c.results && c.results.spend ? `<span class="muted">${aed(c.results.spend)} · ${num(c.results.purchases)} ${esc(t('Orders'))} · ROAS ${num(c.results.roas, 2)} · CTR ${pct(c.results.ctr)}</span>` : `<span class="muted">${esc(tt('Not running yet', 'لم يُعرض بعد'))}</span>`}</div></div>`).join('')}</div>`
    : `<div class="card">${empty(tt('No creatives yet', 'لا توجد إعلانات بعد'), tt('Generate one above, or let the autopilot create them on the weekly batch day.', 'أنشئي إعلاناً أعلاه أو اتركي الطيار الآلي ينشئها في يوم الدفعة الأسبوعي.'))}</div>`}`;
  fillThumbs(list, v);
  v.querySelectorAll('[data-cr]').forEach((n) => { n.onclick = () => preview(lib.find((c) => c.id === n.dataset.cr)); n.onkeydown = (e) => { if (e.key === 'Enter') n.click(); }; });
  v.querySelector('#gen').onclick = async () => {
    busy = 'gen'; images(S, v, lib);
    try {
      const r = await api('/api/brain/copy', { method: 'POST', body: { product_ids: [v.querySelector('#g-p').value.trim()], angle: v.querySelector('#g-a').value, template: v.querySelector('#g-t').value } });
      for (const d of r.designs || []) for (const f of d.formats) {
        const cv = await renderSpec(d, f);
        const { b64 } = await toPNG(cv);
        await api('/api/creatives', { method: 'POST', body: { design_id: d.id, pair_id: d.pair_id, lang: d.lang, format: f, png: b64, headline: d.headline, primary_text: d.primary_text || '', cta: d.cta, product_ids: d.product_ids, angle: d.angle, template: d.template, source: 'owner', design: d } });
      }
      toast(tt('New Arabic + English pair added in 3 formats.', 'أُضيفت نسختان عربية وإنجليزية بثلاثة مقاسات.'));
      dropList('creatives');
    } catch (e) { toast(e.message); }
    busy = '';
    if (stillCreatives()) { await loadList('creatives', '/api/creatives'); render(STORE, document.getElementById('view')); }
  };
}

async function preview(c) {
  if (!c) return;
  const d = document.getElementById('dlg');
  d.style.maxWidth = '760px';
  d.innerHTML = `<div class="dl"><div class="spread"><h2>${txt(c.headline)}</h2><button class="ghost small" id="x">✕</button></div>
  <div class="mock"><div><p class="small muted">${esc(tt('Feed', 'الخلاصة'))}</p><div class="phone"><div class="scr"><div class="post"><header><i></i><b>kbeautybliss</b><span class="muted">· ${esc(tt('Sponsored', 'مُموَّل'))}</span></header>
    <div class="txt" ${c.lang === 'ar' ? 'dir="rtl" lang="ar" style="font-family:var(--font-ar)"' : ''}>${esc(c.primary_text || '')}</div><canvas id="pf"></canvas>
    <div class="bar" ${c.lang === 'ar' ? 'dir="rtl"' : ''}><span>kbeautybliss.com<br><b>${esc(c.headline)}</b></span><span class="chip">${esc(c.lang === 'ar' ? 'تسوّقي الآن' : 'Shop now')}</span></div></div></div></div></div>
  <div><p class="small muted">${esc(tt('Story / Reels', 'ستوري / ريلز'))}</p><div class="phone"><div class="scr"><div class="story"><canvas id="ps"></canvas><div class="cta">⌃ ${esc(c.lang === 'ar' ? 'تسوّقي الآن' : 'Shop now')}</div></div></div></div></div></div></div>`;
  d.showModal();
  d.querySelector('#x').onclick = () => d.close();
  const spec = c.design;
  if (spec) { await renderSpec(spec, '4:5', d.querySelector('#pf')); await renderSpec(spec, '9:16', d.querySelector('#ps')); }
  else if (c.image_url) { for (const id of ['pf', 'ps']) { const im = new Image(); im.src = authed(c.image_url); im.onload = () => { const cv = d.querySelector('#' + id); cv.width = im.width; cv.height = im.height; cv.getContext('2d').drawImage(im, 0, 0); }; } }
}

function videos(S, v, vids) {
  if (!vids) { v.innerHTML = '<div class="sk" style="height:140px"></div><div class="sk" style="height:300px;margin-top:16px"></div>'; return; }
  const list = vids.filter((x) => langF === 'all' || (x.analysis && (x.analysis.language === langF || x.analysis.language === 'mixed')));
  v.innerHTML = `<div class="card"><header><h2>${esc(tt('Your ad videos', 'فيديوهاتك الإعلانية'))} ${tip(tt('New videos to run, and videos used before (with or without their old results). Each is analysed: hook, language, on-screen text, products, angle, safe zones; then the autopilot writes Arabic + English text for it and runs the best ones.', 'فيديوهات جديدة أو سابقة. يُحلَّل كل فيديو ثم يكتب الطيار الآلي نصاً عربياً وإنجليزياً ويشغّل الأفضل.'))}</h2>
    <div class="seg" id="vstat"><button type="button" data-s="new" class="on">${esc(t('New to run'))}</button><button type="button" data-s="past">${esc(t('Used before'))}</button></div></header>
    <label class="drop" id="drop"><input type="file" id="vf" accept="video/*" multiple hidden><b>${esc(t('Drop videos here or choose files'))}</b><br><span class="small">${esc(tt('The original stays on your Mac (copied once into the app\'s data folder); only small frames are sent for analysis.', 'يبقى الملف الأصلي على جهازك؛ تُرسل لقطات صغيرة فقط للتحليل.'))}</span></label>
    <details class="adv" id="pastbox" hidden><summary>${esc(tt('Old results (optional, if it is not found in the Meta account)', 'النتائج السابقة (اختياري)'))}</summary><div class="row" style="margin-top:6px">
      ${[['spend', 'Spend AED', 'الإنفاق'], ['purchases', 'Orders', 'الطلبات'], ['revenue', 'Revenue AED', 'الإيرادات'], ['impressions', 'Impressions', 'الظهور'], ['clicks', 'Clicks', 'النقرات']].map(([k, en, ar]) => `<label class="f"><span>${esc(tt(en, ar))}</span><input type="number" min="0" data-r="${k}" style="width:120px"></label>`).join('')}
      <label class="f" style="flex:1"><span>${esc(tt('Notes', 'ملاحظات'))}</span><input id="vnote" placeholder="${esc(tt('e.g. did well in Ramadan', 'مثلاً: نجح في رمضان'))}"></label></div></details>
    <p class="small muted" id="vprog">${esc(busy || '')}</p></div>
  ${list.length ? `<div class="grid g2">${list.map(videoCard).join('')}</div>` : `<div class="card">${empty(tt('No videos yet', 'لا توجد فيديوهات بعد'), tt('Drop your Reels and ads above; the best-scoring ones become ads with Arabic and English text.', 'أضيفي فيديوهاتك أعلاه؛ أفضلها يتحول إلى إعلانات بنص عربي وإنجليزي.'))}</div>`}`;
  let status = 'new';
  v.querySelectorAll('#vstat [data-s]').forEach((b) => { b.onclick = () => { status = b.dataset.s; v.querySelectorAll('#vstat button').forEach((x) => x.classList.toggle('on', x === b)); v.querySelector('#pastbox').hidden = status !== 'past'; }; });
  const drop = v.querySelector('#drop'), input = v.querySelector('#vf');
  const handle = async (files) => {
    const results = {}; let any = false;
    v.querySelectorAll('[data-r]').forEach((i) => { if (i.value) { results[i.dataset.r] = +i.value; any = true; } });
    for (const f of files) {
      if (!f.type.startsWith('video/')) continue;
      try {
        await addVideo(f, status, any ? results : null, v.querySelector('#vnote')?.value, (step) => { busy = `${f.name}: ${({ upload: tt('copying…', 'نسخ…'), frames: tt('reading frames…', 'قراءة اللقطات…'), analyse: tt('analysing…', 'تحليل…') })[step]}`; const p = document.getElementById('vprog'); if (p) p.textContent = busy; });
        toast(tt(`${f.name} analysed.`, `تم تحليل ${f.name}.`));
      } catch (e) { toast(`${f.name}: ${e.message}`); }
    }
    busy = '';
    dropList('videos');
    await loadList('videos', '/api/videos');
    if (stillCreatives()) render(STORE, document.getElementById('view'));
  };
  input.onchange = () => handle([...input.files]);
  drop.ondragover = (e) => { e.preventDefault(); drop.classList.add('over'); };
  drop.ondragleave = () => drop.classList.remove('over');
  drop.ondrop = (e) => { e.preventDefault(); drop.classList.remove('over'); handle([...e.dataTransfer.files]); };
  drop.onclick = (e) => { if (e.target === drop || e.target.tagName === 'B') input.click(); };
}

function videoCard(x) {
  const a = x.analysis;
  const r = x.results;
  const sev = { block: 'danger', fix: 'warn', tip: 'info' };
  return `<div class="card" data-video="${esc(x.id)}"><header><div class="row">${x.thumb ? `<img src="${esc(authed(x.thumb))}" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:10px">` : ''}<span><b>${esc(x.name)}</b><br><span class="small muted">${esc(x.status === 'past' ? t('Used before') : t('New to run'))} · ${(x.bytes / 1048576).toFixed(1)} MB${x.meta_ad_ids?.length ? ' · ' + esc(tt('matched to', 'مطابق لـ')) + ' ' + x.meta_ad_ids.length + ' ' + esc(tt('Meta ad(s)', 'إعلان')) : ''}</span></span></div>
    ${a ? `<span class="score" data-tip="${esc(Object.entries(a.score_parts || {}).map(([k, v]) => `${k} ${v}`).join(' · '))}">${a.score}<span class="small muted">/100</span></span>` : '<span class="chip">…</span>'}</header>
  ${a ? `<div class="row small" style="margin-bottom:8px"><span class="chip">${esc(a.aspect)}</span><span class="chip">${num(a.duration_s, 1)} s</span><span class="chip">${esc(a.angle || '—')}</span><span class="chip">${esc(tt('text', 'النص'))}: ${esc(a.language)}</span>${(a.placements || []).map((p) => `<span class="chip">${esc(p)}</span>`).join('')}</div>
    ${a.hook ? `<p><b>${esc(t('Hook'))}:</b> ${txt(a.hook)} <span class="muted">(${num(a.hook_strength)}/10)</span></p>` : ''}
    ${a.products_shown?.length ? `<p class="small"><b>${esc(tt('Products', 'المنتجات'))}:</b> ${a.products_shown.map((p) => esc(p.title)).join(' · ')}</p>` : ''}
    ${a.on_screen_text?.length ? `<p class="small"><b>${esc(tt('On screen', 'على الشاشة'))}:</b> ${a.on_screen_text.map(txt).join(' · ')}</p>` : ''}
    <div class="list">${(a.issues || []).map((i) => `<div class="alert ${sev[i.severity] || ''} small">${esc(i.detail)}</div>`).join('')}</div>
    ${a.suggestions?.length ? `<p class="small"><b>${esc(t('Suggestions'))}:</b></p><ul class="small" style="margin:0;padding-inline-start:18px">${a.suggestions.map((s) => `<li>${txt(s)}</li>`).join('')}</ul>` : ''}
    ${a.trims?.length ? `<p class="small"><b>${esc(tt('Trims', 'قص'))}:</b> ${a.trims.map((tr) => `${num(tr.from_s, 1)}–${num(tr.to_s, 1)} s (${esc(tr.why)})`).join(' · ')}</p>` : ''}
    <p class="small muted">${esc(tt('Analysed by', 'حلّله'))} ${esc(a.model || '')}</p>` : `<p class="muted">${esc(tt('Waiting for analysis…', 'بانتظار التحليل…'))}</p>`}
  ${r ? `<p class="small"><b>${esc(tt('Results', 'النتائج'))} (${esc(r.source)}):</b> ${aed(r.spend)} · ${num(r.purchases)} ${esc(t('Orders'))}${r.revenue ? ' · ROAS ' + num(r.revenue / Math.max(1, r.spend), 2) : ''}${r.impressions && r.clicks ? ' · CTR ' + pct(r.clicks / r.impressions) : ''}</p>` : ''}</div>`;
}

function motion(S, v, lib) {
  if (!lib) { v.innerHTML = '<div class="sk" style="height:300px"></div>'; return; }
  const src = lib.filter((c) => c.design && c.kind !== 'motion' && (langF === 'all' || c.lang === langF));
  const made = lib.filter((c) => c.kind === 'motion');
  v.innerHTML = `<div class="grid g21"><div class="card"><header><h2>${esc(t('Make motion ad'))} ${tip(tt('A 6–15 s video built from a design: the product and text animate in, captions are burned in (Arabic for Arabic ads) inside the Reels safe zone, then a call-to-action end card. Add your own music if you like.', 'فيديو من ٦ إلى ١٥ ثانية من التصميم: يظهر المنتج والنص بحركة، مع ترجمة مدمجة داخل المنطقة الآمنة، ثم بطاقة دعوة للشراء.'))}</h2></header>
    <div class="row"><label class="f" style="flex:1"><span>${esc(tt('Design', 'التصميم'))}</span><select id="m-src">${src.map((c) => `<option value="${esc(c.id)}">${esc((c.lang || '').toUpperCase())} · ${esc(c.headline)}</option>`).join('')}</select></label>
    <label class="f"><span>${esc(tt('Format', 'المقاس'))}</span><select id="m-f"><option>9:16</option><option>4:5</option></select></label>
    <label class="f"><span>${esc(tt('Seconds', 'الثواني'))}</span><input id="m-d" type="number" min="6" max="15" value="8" style="width:80px"></label>
    <label class="f"><span>${esc(tt('Music (optional)', 'موسيقى (اختياري)'))}</span><input id="m-a" type="file" accept="audio/*"></label></div>
    <div class="row" style="margin-top:10px"><button type="button" id="m-go" ${busy ? 'disabled' : ''}>${esc(t('Render'))}</button><span class="small muted" id="m-p"></span></div>
    <div id="m-out" style="margin-top:12px">${lastMotion ? `<video controls playsinline src="${lastMotion.url}" style="max-height:420px;border-radius:14px"></video>` : ''}</div></div>
    <div class="card"><header><h2>${esc(tt('Motion ads made', 'الإعلانات المتحركة'))}</h2></header>${made.length ? `<div class="list">${made.map((m) => `<div><span class="chip">${esc((m.lang || '').toUpperCase())} · ${esc(m.format)}</span><span>${txt(m.headline)}<br><span class="small muted">${Math.round((m.bytes || 0) / 1024)} KB</span></span></div>`).join('')}</div>` : `<p class="muted">${esc(tt('None yet.', 'لا شيء بعد.'))}</p>`}</div></div>`;
  v.querySelector('#m-go').onclick = async () => {
    const c = src.find((x) => x.id === v.querySelector('#m-src').value);
    if (!c) return;
    const f = v.querySelector('#m-f').value, dur = Math.max(6, Math.min(15, +v.querySelector('#m-d').value || 8));
    const audio = v.querySelector('#m-a').files[0] || null;
    busy = 'motion'; v.querySelector('#m-go').disabled = true;
    const p = v.querySelector('#m-p');
    try {
      const r = await recordMotion(c.design, f, dur, audio, (x) => { p.textContent = `${Math.round(x * 100)}%`; });
      if (lastMotion) URL.revokeObjectURL(lastMotion.url);
      lastMotion = { url: URL.createObjectURL(r.blob), mime: r.mime, bytes: r.blob.size };
      const b64 = await new Promise((ok) => { const fr = new FileReader(); fr.onload = () => ok(String(fr.result).split(',')[1]); fr.readAsDataURL(r.blob); });
      await api('/api/creatives', { method: 'POST', body: { pair_id: c.pair_id, lang: c.lang, format: f, video: b64, mime: r.mime, headline: c.headline, primary_text: c.primary_text, cta: c.cta, product_ids: c.product_ids, angle: c.angle, template: c.template, source: 'brain', design: c.design } });
      p.textContent = `${r.mime} · ${Math.round(r.blob.size / 1024)} KB · ${dur} s`;
      window.__kaaMotion = { mime: r.mime, bytes: r.blob.size, width: r.width, height: r.height, ms: r.ms };
      dropList('creatives');
    } catch (e) { toast(e.message); p.textContent = e.message; }
    busy = '';
    if (stillCreatives()) { await loadList('creatives', '/api/creatives'); render(STORE, document.getElementById('view')); }
  };
}

export async function load(S) {
  const need = [];
  if (!S.cache.has(`${getAccount()}|creatives`)) need.push(loadList('creatives', '/api/creatives'));
  if (!S.cache.has(`${getAccount()}|videos`)) need.push(loadList('videos', '/api/videos'));
  if (!need.length) return false;
  await Promise.all(need);
  return stillCreatives();
}
