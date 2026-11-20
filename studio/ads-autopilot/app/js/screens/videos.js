// The owner's ad videos: drop in new ones to run and past ones (with or
// without their old results); each is analysed and the autopilot runs the best.
import { tt } from '../i18n.js';
import { esc, aed, num, pct, toast, empty, tip, txt } from '../ui.js';
import { api, getAccount, hydrate } from '../api.js';
import { loadList, dropList, S as STORE } from '../main.js';
import { addVideo } from '../creative/video.js';

let busy = '', status = 'new';
const still = () => location.hash.startsWith('#/videos');

export function render(S, el) {
  const vids = S.cache.get(`${getAccount()}|videos`);
  el.innerHTML = `<div class="page"><div class="page-h"><div><h1>${esc(tt('Videos', 'الفيديوهات'))}</h1><p>${esc(tt('Your ad videos: analysed for hook, language, on-screen text, products, angle and safe zones; the best become ads with Arabic + English text.', 'فيديوهاتك الإعلانية: تُحلَّل الافتتاحية واللغة والنص والمنتجات والزاوية والمنطقة الآمنة، والأفضل تتحول إلى إعلانات.'))}</p></div></div>
  <div class="card"><header><h2>${esc(tt('Add videos', 'إضافة فيديوهات'))} ${tip(tt('The original stays on your Mac: it is copied once into the app\'s data folder; only a few small frames go to Claude for analysis.', 'يبقى الملف على جهازك ويُنسخ مرة واحدة إلى مجلد البيانات؛ تُرسل لقطات صغيرة فقط للتحليل.'))}</h2>
    <div class="seg" id="vstat"><button type="button" data-s="new" class="${status === 'new' ? 'on' : ''}">${esc(tt('New to run', 'جديد للتشغيل'))}</button><button type="button" data-s="past" class="${status === 'past' ? 'on' : ''}">${esc(tt('Used before', 'استُخدم سابقاً'))}</button></div></header>
    <label class="drop" id="drop"><input type="file" id="vf" accept="video/mp4,video/quicktime,video/webm,.mov,.m4v" multiple hidden><b>${esc(tt('Drop videos here or choose files', 'اسحبي الفيديوهات هنا أو اختاري ملفات'))}</b><br><span class="small">MP4 · MOV · WebM</span></label>
    <details class="adv" id="pastbox" ${status === 'past' ? '' : 'hidden'}><summary>${esc(tt('Old results (optional — used when the video is not found in the Meta account)', 'النتائج السابقة (اختياري)'))}</summary><div class="row" style="margin-top:6px">
      ${[['spend', 'Spend AED', 'الإنفاق'], ['purchases', 'Orders', 'الطلبات'], ['revenue', 'Sales AED', 'المبيعات'], ['impressions', 'Impressions', 'الظهور'], ['clicks', 'Clicks', 'النقرات']].map(([k, en, ar]) => `<label class="f"><span>${esc(tt(en, ar))}</span><input type="number" min="0" data-r="${k}" style="width:120px"></label>`).join('')}
      <label class="f" style="flex:1"><span>${esc(tt('Notes', 'ملاحظات'))}</span><input id="vnote" placeholder="${esc(tt('e.g. did well in Ramadan', 'مثلاً: نجح في رمضان'))}"></label></div></details>
    <p class="small muted" id="vprog">${esc(busy)}</p></div>
  ${!vids ? '<div class="sk" style="height:300px"></div>' : vids.length ? `<div class="grid g2">${vids.map(card).join('')}</div>` : `<div class="card">${empty(tt('No videos yet', 'لا توجد فيديوهات بعد'), tt('Add your Reels and past ads above.', 'أضيفي فيديوهاتك أعلاه.'))}</div>`}</div>`;
  hydrate(el);
  el.querySelectorAll('#vstat [data-s]').forEach((b) => { b.onclick = () => { status = b.dataset.s; render(S, el); }; });
  const drop = el.querySelector('#drop'), input = el.querySelector('#vf');
  const handle = async (files) => {
    const results = {}; let any = false;
    el.querySelectorAll('[data-r]').forEach((i) => { if (i.value) { results[i.dataset.r] = +i.value; any = true; } });
    for (const f of files) {
      if (!/^video\//.test(f.type) && !/\.(mp4|mov|m4v|webm)$/i.test(f.name)) continue;
      try {
        await addVideo(f, status, any ? results : null, el.querySelector('#vnote')?.value, (step) => { busy = `${f.name}: ${({ upload: tt('copying…', 'نسخ…'), frames: tt('reading frames…', 'قراءة اللقطات…'), analyse: tt('analysing…', 'تحليل…') })[step]}`; const p = document.getElementById('vprog'); if (p) p.textContent = busy; });
        toast(tt(`${f.name} analysed.`, `تم تحليل ${f.name}.`));
      } catch (e) { toast(`${f.name}: ${e.message}`); }
    }
    busy = '';
    dropList('videos');
    await loadList('videos', '/api/brain/x/videos');
    if (still()) render(STORE, document.getElementById('view'));
  };
  input.onchange = () => handle([...input.files]);
  drop.ondragover = (e) => { e.preventDefault(); drop.classList.add('over'); };
  drop.ondragleave = () => drop.classList.remove('over');
  drop.ondrop = (e) => { e.preventDefault(); drop.classList.remove('over'); handle([...e.dataTransfer.files]); };
}

function card(x) {
  const a = x.analysis, r = x.results;
  const sev = { block: 'danger', fix: 'warn', tip: 'info' };
  return `<div class="card"><header><div class="row">${x.thumb ? `<img data-src="${esc(x.thumb)}" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:12px">` : ''}<span><b>${esc(x.name)}</b><br><span class="small muted">${esc(x.status === 'past' ? tt('Used before', 'استُخدم سابقاً') : tt('New to run', 'جديد للتشغيل'))} · ${(x.bytes / 1048576).toFixed(1)} MB${x.meta_ad_ids?.length ? ' · ' + esc(tt('matched', 'مطابق')) + ' ' + x.meta_ad_ids.length + ' ' + esc(tt('Meta ad(s)', 'إعلان')) : ''}</span></span></div>
    ${a ? `<span class="score" data-tip="${esc(Object.entries(a.score_parts || {}).map(([k, v]) => `${k} ${v}`).join(' · '))}">${a.score}<span class="small muted">/100</span></span>` : '<span class="chip">…</span>'}</header>
  ${a ? `<div class="row small" style="margin-bottom:8px"><span class="chip acc">${esc(a.aspect)}</span><span class="chip">${num(a.duration_s, 1)} s</span><span class="chip">${esc(a.angle || '—')}</span><span class="chip">${esc(tt('text', 'النص'))}: ${esc(a.language)}</span>${(a.placements || []).map((p) => `<span class="chip">${esc(p)}</span>`).join('')}</div>
    ${a.hook ? `<p><b>${esc(tt('Hook', 'الافتتاحية'))}:</b> ${txt(a.hook)} <span class="muted">(${num(a.hook_strength)}/10)</span></p>` : ''}
    ${a.products_shown?.length ? `<p class="small"><b>${esc(tt('Products', 'المنتجات'))}:</b> ${a.products_shown.map((p) => esc(p.title)).join(' · ')}</p>` : ''}
    ${a.on_screen_text?.length ? `<p class="small"><b>${esc(tt('On screen', 'على الشاشة'))}:</b> ${a.on_screen_text.map(txt).join(' · ')}</p>` : ''}
    <div class="list">${(a.issues || []).map((i) => `<div class="alert ${sev[i.severity] || ''} small">${esc(i.detail)}</div>`).join('')}</div>
    ${a.suggestions?.length ? `<ul class="small" style="margin:8px 0 0;padding-inline-start:18px">${a.suggestions.map((s) => `<li>${txt(s)}</li>`).join('')}</ul>` : ''}
    ${a.trims?.length ? `<p class="small"><b>${esc(tt('Trims', 'قص'))}:</b> ${a.trims.map((tr) => `${num(tr.from_s, 1)}–${num(tr.to_s, 1)} s (${esc(tr.why)})`).join(' · ')}</p>` : ''}
    <p class="small muted">${esc(tt('Analysed by', 'حلّله'))} ${esc(a.model || '')}</p>` : `<p class="muted">${esc(tt('Waiting for analysis…', 'بانتظار التحليل…'))}</p>`}
  ${r ? `<p class="small"><b>${esc(tt('Results', 'النتائج'))} (${esc(r.source)}):</b> ${aed(r.spend)} · ${num(r.purchases)} ${esc(tt('orders', 'طلبات'))}${r.revenue ? ' · ROAS ' + num(r.revenue / Math.max(1, r.spend), 2) : ''}${r.impressions && r.clicks ? ' · CTR ' + pct(r.clicks / r.impressions) : ''}</p>` : ''}</div>`;
}

export async function load(S) {
  if (S.cache.has(`${getAccount()}|videos`)) return false;
  await loadList('videos', '/api/brain/x/videos');
  return still();
}
void api;
