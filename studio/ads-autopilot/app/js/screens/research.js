import { tt, getLang } from '../i18n.js';
import { esc, ago, empty, toast } from '../ui.js';
import { api } from '../api.js';

let topic = 'all';
const TOPICS = { all: ['All', 'الكل'], trend: ['Trends', 'الاتجاهات'], ingredient: ['Ingredients', 'المكونات'], competitor: ['Competitors', 'المنافسون'], arabic_audience: ['Arabic audience', 'الجمهور العربي'], format: ['Formats & hooks', 'الأشكال والافتتاحيات'], hook: ['Hooks', 'الافتتاحيات'], season: ['Seasons', 'المواسم'] };
const link = (s) => `<a href="${esc(/^https:\/\//.test(s.url) ? s.url : '#')}" target="_blank" rel="noopener noreferrer">${esc(s.title || hostOf(s.url))}</a>`;
const hostOf = (u) => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch { return u; } };

export function render(S, el) {
  const r = S.cache.get('|research'), tr = S.cache.get('|trending');
  if (!r || !tr) { el.innerHTML = '<div class="page"><div class="sk" style="height:260px"></div><div class="grid g2"><div class="sk" style="height:200px"></div><div class="sk" style="height:200px"></div></div></div>'; return; }
  const notes = (r.notes || []).filter((n) => topic === 'all' || n.topic === topic);
  const ar = getLang() === 'ar';
  el.innerHTML = `<div class="page"><div class="page-h"><div><h1>${esc(tt('Research', 'أبحاث السوق'))}</h1><p>${esc(tt('Claude searches the web for what skincare shoppers want now, what competitors run and what is trending; every note is dated and sourced.', 'يبحث Claude في الويب عمّا يريده المتسوقون الآن وما يعرضه المنافسون وما هو رائج؛ كل ملاحظة مؤرخة ولها مصدر.'))}</p></div>
    <div class="row"><button class="ghost" id="rt">${esc(tt('Check trending now', 'افحص الرائج الآن'))}</button><button class="ghost" id="refresh">${esc(tt('Research now', 'ابحث الآن'))}</button></div></div>
  <div class="card"><header><h2>${esc(tt('Trending now', 'الرائج الآن'))}</h2><span class="sub">${esc(tt('updated', 'آخر تحديث'))} ${esc(ago(tr.updated_at))} · ${esc(tt('daily while the app is open', 'يومياً ما دام التطبيق مفتوحاً'))}</span></header>
    ${(tr.items || []).length ? `<table><thead><tr><th>#</th><th>${esc(tt('Product / ingredient', 'المنتج / المكوّن'))}</th><th>${esc(tt('Momentum', 'الزخم'))}</th><th>${esc(tt('Where', 'أين'))}</th><th>${esc(tt('In the shop', 'في المتجر'))}</th><th>${esc(tt('Sources', 'المصادر'))}</th></tr></thead><tbody>
      ${tr.items.map((it) => `<tr><td class="num"><b>${it.rank}</b></td><td><b>${esc(it.name)}</b> <span class="muted small">${esc(it.brand || '')} · ${esc(it.kind)}</span><br><span class="small ink2">${esc(ar ? it.why_ar || it.why : it.why)}</span></td>
        <td><span class="chip mom-${esc(it.momentum)}">${esc(it.momentum)}</span></td><td>${esc(it.region === 'gcc' ? tt('GCC', 'الخليج') : tt('global', 'عالمي'))}</td>
        <td>${it.in_stock ? `<span class="chip acc">✓ ${esc(tt('in stock — prioritised in ads', 'متوفر — أولوية في الإعلانات'))}</span>` : it.in_shop ? `<span class="chip">${esc(tt('sold out', 'نفد'))}</span>` : `<span class="chip">${esc(tt('not sold', 'غير متوفر'))}</span>`}</td>
        <td class="small">${(it.sources || []).map(link).join(' · ')}</td></tr>`).join('')}</tbody></table>
      ${(tr.consider_stocking || []).length ? `<div class="alert info" style="margin-top:12px"><span><b>${esc(tt('Consider stocking', 'فكّري في توفيرها'))}:</b> ${tr.consider_stocking.map((x) => `${esc(x.name)}${x.brand ? ' (' + esc(x.brand) + ')' : ''}`).join(' · ')}</span></div>` : ''}`
      : empty(tt('No trending list yet', 'لا توجد قائمة رائجة بعد'), tt('It runs on launch and daily once Claude is connected.', 'يعمل عند التشغيل ويومياً بعد ربط Claude.'))}</div>
  <div class="spread"><div class="seg">${Object.entries(TOPICS).filter(([k]) => k === 'all' || (r.notes || []).some((n) => n.topic === k)).map(([k, v]) => `<button type="button" data-t="${k}" class="${topic === k ? 'on' : ''}">${esc(tt(v[0], v[1]))}</button>`).join('')}</div>
    <span class="muted small">${esc(tt('Market notes updated', 'ملاحظات السوق'))} ${esc(ago(r.updated_at))}</span></div>
  ${notes.length ? `<div class="grid g2">${notes.map((n) => `<article class="card"><header><span class="chip">${esc(tt(...(TOPICS[n.topic] || [n.topic, n.topic])))}</span><span class="sub">${esc(new Date(n.date).toLocaleDateString(ar ? 'ar-AE' : 'en-GB'))} · ${esc(tt('strength', 'القوة'))} ${Math.round((n.strength || 0) * 100)}%</span></header>
    <div class="bi"><div><h3>${esc(n.title)}</h3><p class="ink2">${esc(n.body)}</p></div><div class="rtl" dir="rtl" lang="ar"><h3>${esc(n.title_ar || '')}</h3><p class="ink2">${esc(n.body_ar || '')}</p></div></div>
    ${n.angles?.length ? `<p><b class="small">${esc(tt('Ad angles', 'زوايا إعلانية'))}:</b> ${n.angles.map((a) => `<span class="chip">${esc(a)}</span>`).join(' ')}</p>` : ''}
    <p class="small"><b>${esc(tt('Sources', 'المصادر'))}:</b> ${(n.sources || []).map(link).join(' · ')}</p></article>`).join('')}</div>`
    : `<div class="card">${empty(tt('No market notes yet', 'لا توجد ملاحظات بعد'), tt('Connect Claude in Settings; research then runs on launch and every few days.', 'اربطي Claude في الإعدادات؛ يعمل البحث عند التشغيل وكل بضعة أيام.'))}</div>`}</div>`;
  el.querySelectorAll('[data-t]').forEach((b) => { b.onclick = () => { topic = b.dataset.t; render(S, el); }; });
  const run = (btn, fn) => async () => { btn.disabled = true; try { await fn(); toast(tt('Updated.', 'تم التحديث.')); S.cache.delete('|research'); S.cache.delete('|trending'); await load(S); if (location.hash.startsWith('#/research')) render(S, el); } catch (x) { toast(x.message); } btn.disabled = false; };
  const rb = el.querySelector('#refresh'), tb = el.querySelector('#rt');
  rb.onclick = run(rb, () => api('/api/brain/research', { method: 'POST', body: {}, noAccount: true }));
  tb.onclick = run(tb, () => api('/api/brain/x/trending', { method: 'POST' }));
}
export async function load(S) {
  const need = [];
  if (!S.cache.has('|research')) need.push(api('/api/brain/x/research').catch(() => ({ notes: [] })).then((x) => S.cache.set('|research', x)));
  if (!S.cache.has('|trending')) need.push(api('/api/brain/x/trending').catch(() => ({ items: [] })).then((x) => S.cache.set('|trending', x)));
  if (!need.length) return false;
  await Promise.all(need);
  return location.hash.startsWith('#/research');
}
