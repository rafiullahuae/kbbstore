import { t, tt, getLang } from '../i18n.js';
import { esc, ago, empty, toast } from '../ui.js';
import { api } from '../api.js';
import { loadList, dropList } from '../main.js';
import { getAccount } from '../api.js';

let topic = 'all';
const TOPICS = { all: ['All', 'الكل'], trend: ['Trends', 'الاتجاهات'], ingredient: ['Ingredients', 'المكونات'], competitor: ['Competitors', 'المنافسون'], arabic_audience: ['Arabic audience', 'الجمهور العربي'], format: ['Formats & hooks', 'الأشكال والافتتاحيات'], hook: ['Hooks', 'الافتتاحيات'], season: ['Seasons', 'المواسم'] };

export function render(S, el) {
  const r = S.cache.get(`${getAccount()}|research`);
  if (!r) { el.innerHTML = '<div class="page"><div class="sk" style="height:42px"></div><div class="grid g2"><div class="sk" style="height:200px"></div><div class="sk" style="height:200px"></div></div></div>'; return; }
  const notes = (r.notes || []).filter((n) => topic === 'all' || n.topic === topic);
  const ar = getLang() === 'ar';
  el.innerHTML = `<div class="page"><div class="spread"><div class="seg">${Object.entries(TOPICS).filter(([k]) => k === 'all' || (r.notes || []).some((n) => n.topic === k)).map(([k, v]) => `<button type="button" data-t="${k}" class="${topic === k ? 'on' : ''}">${esc(tt(v[0], v[1]))}</button>`).join('')}</div>
  <div class="row"><span class="muted small">${esc(tt('Updated', 'آخر تحديث'))} ${esc(ago(r.updated_at))} · ${esc(tt('refreshes every 3 days', 'يتجدد كل ٣ أيام'))}</span><button class="ghost" id="refresh">${esc(tt('Research now', 'ابحث الآن'))}</button></div></div>
  ${notes.length ? `<div class="grid g2">${notes.map((n) => `<article class="card"><header><span class="chip">${esc(tt((TOPICS[n.topic] || [n.topic])[0], (TOPICS[n.topic] || [, n.topic])[1]))}</span><span class="muted small">${esc(new Date(n.date).toLocaleDateString(ar ? 'ar-AE' : 'en-GB'))} · ${esc(tt('strength', 'القوة'))} ${Math.round((n.strength || 0) * 100)}%</span></header>
    <div class="bi"><div><h3>${esc(n.title)}</h3><p class="ink2">${esc(n.body)}</p></div><div class="rtl" dir="rtl" lang="ar"><h3>${esc(n.title_ar)}</h3><p class="ink2">${esc(n.body_ar)}</p></div></div>
    ${n.angles?.length ? `<p><b class="small">${esc(tt('Ad angles', 'زوايا إعلانية'))}:</b> ${n.angles.map((a) => `<span class="chip">${esc(a)}</span>`).join(' ')}</p>` : ''}
    <p class="small"><b>${esc(t('Sources'))}:</b> ${(n.sources || []).map((s) => `<a href="${esc(/^https:\/\//.test(s.url) ? s.url : '#')}" target="_blank" rel="noopener noreferrer">${esc(s.title || s.url)}</a>`).join(' · ')}</p></article>`).join('')}</div>`
    : `<div class="card">${empty(tt('No research yet', 'لا توجد أبحاث بعد'), tt('Connect Claude in Settings; the autopilot then searches the web for skincare trends, competitors and Arabic-audience insights every 3 days.', 'اربطي Claude في الإعدادات؛ عندها يبحث الطيار الآلي عن اتجاهات العناية والمنافسين وجمهورنا العربي كل ٣ أيام.'))}</div>`}</div>`;
  el.querySelectorAll('[data-t]').forEach((b) => { b.onclick = () => { topic = b.dataset.t; render(S, el); }; });
  el.querySelector('#refresh').onclick = async (e) => {
    e.target.disabled = true;
    try { await api('/api/brain/research', { method: 'POST', body: {} }); dropList('research'); toast(tt('Research refreshed.', 'تم تحديث الأبحاث.')); await load(S); render(S, el); } catch (x) { toast(x.message); }
    e.target.disabled = false;
  };
}
export async function load(S) {
  if (S.cache.has(`${getAccount()}|research`)) return false;
  await loadList('research', '/api/research');
  return location.hash.startsWith('#/research');
}
