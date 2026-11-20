import { t, getLang } from './i18n.js';

// Every string from data goes through esc() before it reaches innerHTML.
const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ESC[c]);
const nf = (d) => new Intl.NumberFormat(getLang() === 'ar' ? 'ar-AE' : 'en-AE', { maximumFractionDigits: d, minimumFractionDigits: d });
export const aed = (v, d = 0) => (v == null || isNaN(v) ? '—' : (getLang() === 'ar' ? `${nf(d).format(v)} د.إ` : `AED ${nf(d).format(v)}`));
export const num = (v, d = 0) => (v == null || isNaN(v) ? '—' : nf(d).format(v));
export const pct = (v, d = 2) => (v == null || isNaN(v) ? '—' : `${nf(d).format(v * 100)}%`);
export const isAR = (s) => /[؀-ۿ]/.test(String(s || ''));
// Arabic text always renders RTL, in the Arabic face
export const txt = (s) => (isAR(s) ? `<span class="rtl" dir="rtl" lang="ar">${esc(s)}</span>` : esc(s));
export function ago(iso) {
  if (!iso) return '—';
  const s = (Date.now() - Date.parse(iso)) / 1000;
  const rtf = new Intl.RelativeTimeFormat(getLang() === 'ar' ? 'ar' : 'en', { numeric: 'auto' });
  if (Math.abs(s) < 3600) return rtf.format(-Math.round(s / 60), 'minute');
  if (Math.abs(s) < 86400) return rtf.format(-Math.round(s / 3600), 'hour');
  return rtf.format(-Math.round(s / 86400), 'day');
}
export const inTime = (iso) => { const m = Math.round((Date.parse(iso) - Date.now()) / 60000); return m <= 0 ? t('now') : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`; };

export const VERDICT = {
  keep: ['Keep', 'إبقاء', 'Performing; leave it running.'], scale: ['Scale', 'زيادة', 'Beating target; budget goes up one step.'],
  kill: ['Stop', 'إيقاف', 'Statistically unlikely to pay back; paused.'], cut: ['Cut', 'تخفيض', 'Losing money; budget goes down one step.'],
  watch: ['Watch', 'مراقبة', 'Not enough evidence yet either way.'], learning: ['Learning', 'تعلّم', 'Meta is still learning; no edits.'],
  fatigued: ['Fatigued', 'مُرهَق', 'People have seen it too often; a fresh creative is queued.'], settling: ['Settling', 'قيد الاحتساب', 'Sales from the last 2 days are still being counted.'],
  no_data: ['Too early', 'مبكر', 'Too little spend to judge.'],
};
export function verdictChip(v) {
  if (!v) return '<span class="chip">—</span>';
  const [en, ar, tip] = VERDICT[v.verdict] || [v.verdict, v.verdict, ''];
  return `<span class="chip v-${esc(v.verdict)}" data-tip="${esc(tip)}">${esc(getLang() === 'ar' ? ar : en)}</span>`;
}

export const tip = (s) => `<span class="i" tabindex="0" data-tip="${esc(s)}">i</span>`;

export function kpi(label, value, sub, help, cls = '') {
  return `<div class="kpi"><span class="l">${esc(t(label))} ${help ? tip(help) : ''}</span><span class="v num" data-tip="${esc(help || '')}">${value}</span><span class="d ${cls}">${sub || '&nbsp;'}</span></div>`;
}

export function skeleton(rows = 3) {
  return `<div class="page">${'<div class="sk" style="height:84px"></div>'.repeat(1)}<div class="grid g4">${'<div class="sk" style="height:86px"></div>'.repeat(4)}</div>${'<div class="sk" style="height:160px"></div>'.repeat(rows)}</div>`;
}

export function empty(title, what, action = '') {
  return `<div class="empty"><b>${esc(title)}</b>${esc(what)}${action ? `<div style="margin-top:12px">${action}</div>` : ''}</div>`;
}

export function toast(msg, undo) {
  const el = document.createElement('div');
  el.className = 'toast';
  el.innerHTML = `<span>${esc(msg)}</span>`;
  if (undo) {
    const b = document.createElement('button');
    b.textContent = t('Undo');
    b.onclick = () => { el.remove(); undo(); };
    el.append(b);
  }
  document.getElementById('toasts').append(el);
  setTimeout(() => el.remove(), undo ? 8000 : 4000);
}

export function confirmBox(title, body, okLabel, danger) {
  const d = document.getElementById('dlg');
  d.innerHTML = `<form method="dialog" class="dl"><h2>${esc(title)}</h2><p class="ink2">${esc(body)}</p><div class="row" style="justify-content:flex-end"><button value="no" class="ghost">${esc(t('Cancel'))}</button><button value="yes" class="${danger ? 'danger' : ''}">${esc(okLabel)}</button></div></form>`;
  d.showModal();
  return new Promise((ok) => { d.onclose = () => ok(d.returnValue === 'yes'); });
}

// One-axis line chart (AED), fixed viewBox: no layout measuring. Each day has
// a hit area with a native tooltip listing both series.
export function lineChart(series, keys, colors, labels, fmt) {
  const W = 760, H = 220, L = 84, R = 14, T = 12, B = 26;
  const n = series.length;
  if (!n) return '';
  const max = Math.max(1, ...series.flatMap((d) => keys.map((k) => d[k] || 0))) * 1.1;
  const x = (i) => L + (i * (W - L - R)) / Math.max(1, n - 1);
  const y = (v) => T + (H - T - B) * (1 - v / max);
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => max * f);
  let s = `<svg class="chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(labels.join(', '))}"><g class="grid">`;
  for (const tv of ticks) s += `<line x1="${L}" x2="${W - R}" y1="${y(tv)}" y2="${y(tv)}"/><text x="${L - 6}" y="${y(tv) + 4}" text-anchor="end">${esc(fmt(tv))}</text>`;
  s += '</g>';
  keys.forEach((k, j) => {
    s += `<polyline fill="none" stroke="${colors[j]}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="${series.map((d, i) => `${x(i).toFixed(1)},${y(d[k] || 0).toFixed(1)}`).join(' ')}"/>`;
    const last = series[n - 1];
    s += `<circle cx="${x(n - 1)}" cy="${y(last[k] || 0)}" r="4" fill="${colors[j]}" stroke="var(--surface)" stroke-width="2"/>`;
  });
  [0, Math.floor(n / 2), n - 1].forEach((i) => { s += `<text x="${x(i)}" y="${H - 6}" text-anchor="middle">${esc(series[i].date.slice(5))}</text>`; });
  const w = (W - L - R) / Math.max(1, n - 1);
  series.forEach((d, i) => { s += `<rect x="${x(i) - w / 2}" y="${T}" width="${w}" height="${H - T - B}" fill="transparent"><title>${esc(d.date)}\n${keys.map((k, j) => `${labels[j]}: ${fmt(d[k] || 0)}`).join('\n')}</title></rect>`; });
  return s + '</svg>';
}

export function spark(vals, color = 'var(--accent)') {
  if (!vals.length) return '';
  const W = 120, H = 28, max = Math.max(...vals), min = Math.min(...vals), r = max - min || 1;
  const pts = vals.map((v, i) => `${(i * W / Math.max(1, vals.length - 1)).toFixed(1)},${(H - 3 - (v - min) / r * (H - 6)).toFixed(1)}`).join(' ');
  return `<svg class="spark" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke="${color}" stroke-width="1.6" points="${pts}"/></svg>`;
}

// failCard: a settings card whose data did not load is drawn anyway, with the reason and Try again -- never left out
// silently (20 Nov 2026: the Claude desktop and AI-key cards vanished while an old engine served a newer app).
export function failCard(el, title, err, retry) {
  if (!el || !el.isConnected) return;
  const why = (err && err.status === 404) || /no such endpoint/i.test((err && err.message) || '')
    ? t('This engine does not have it: it is older than the app. Close the KBB Ads Autopilot Terminal window and start it again.')
    : (err && err.message) || t('It did not load.');
  el.innerHTML = `<div class="card" data-failed><header><h2>${esc(title)}</h2></header><p class="alert warn small">${esc(why)}</p><div class="row"><button type="button" class="ghost small" data-retry>${esc(t('Try again'))}</button></div></div>`;
  el.querySelector('[data-retry]').onclick = () => { el.innerHTML = '<div class="sk" style="height:120px"></div>'; retry(); };
}
