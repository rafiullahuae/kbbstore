// Correct the price on every ad (the owner, 21 Nov: "it should always take the sale price ... the designs are
// absolutely stunning, i don't want to lose them, just the corrections i need in the price").
// The engine (engine/reprice.go) puts today's selling price on every kept design and lists the library pictures /
// videos that show the old one; this redraws each from ITS OWN design (same template, photo, palette, words) and
// puts it back under the same library id. A live ad that showed the old one is swapped by a job, run right away.
import { api } from '../api.js';
import { tt } from '../i18n.js';
import { esc } from '../ui.js';
import { renderSpec, toPNG, assets } from '../creative/render.js';
import { renderVideo } from '../creative/motion.js';

const b64 = (blob) => new Promise((ok, no) => { const fr = new FileReader(); fr.onload = () => ok(String(fr.result).split(',')[1]); fr.onerror = no; fr.readAsDataURL(blob); });

export const repriceLog = [];
if (typeof window !== 'undefined') window.__kaaReprice = repriceLog;

// runReprice: the whole correction. onStep(text) reports progress. Returns {fixed, failed[], skipped[], swaps, swapErrors[]}.
export async function runReprice(onStep = () => {}, plan0 = null) {
  onStep(tt('Checking every design: the shop’s prices, repeated photos…', 'فحص كل تصميم: أسعار المتجر والصور المكررة…'));
  const plan = plan0 || await api('/api/reprice', { method: 'POST', body: {} });
  const items = (plan.items || []);
  const todo = items.filter((x) => !x.skip);
  const out = { fixed: 0, text: plan.text_now || 0, failed: [], skipped: items.filter((x) => x.skip), swaps: [...(plan.text_swaps || [])], swapErrors: [] };
  for (let i = 0; i < todo.length; i++) {
    const it = todo[i], d = it.design;
    onStep(it.old_price === it.new_price ? tt(`Redrawing ${i + 1} of ${todo.length}: each photo once`, `إعادة رسم ${i + 1} من ${todo.length}: كل صورة مرة واحدة`) : tt(`Redrawing ${i + 1} of ${todo.length}: ${it.old_price} → ${it.new_price}`, `إعادة رسم ${i + 1} من ${todo.length}: ${it.old_price} ← ${it.new_price}`));
    try {
      let body;
      if (it.format === 'mp4') {
        const r = await renderVideo(d, '9:16', it.video_s || 6, null, () => {});
        if (r.mime !== 'video/mp4') throw new Error(tt('this browser made ' + r.mime + ', not MP4', 'المتصفح صنع ' + r.mime + ' وليس MP4'));
        body = { video: await b64(r.blob), layout: 2 };
      } else {
        // no quality gate here: this picture already passed it and runs; only its price / repeated photo changes, and
        // refusing the redraw left the WRONG price live (0.6.1 kept the old picture on "product 39% of the frame")
        const A = await assets(d);
        const { b64: png } = await toPNG(await renderSpec(d, it.format, null, { assets: A }));
        body = { png, layout: 2 };
      }
      const r = await api(`/api/reprice/${encodeURIComponent(it.creative_id)}`, { method: 'PUT', body });
      out.fixed++;
      out.swaps.push(...(r.swaps || []));
      repriceLog.push({ id: it.creative_id, format: it.format, ok: true });
    } catch (e) {
      out.failed.push({ id: it.creative_id, format: it.format, why: String(e.message || e) });
      repriceLog.push({ id: it.creative_id, format: it.format, error: String(e.message || e) });
    }
  }
  for (let i = 0; i < out.swaps.length; i++) { // the live ads: swapped now, through every executor check
    onStep(tt(`Replacing live ad ${i + 1} of ${out.swaps.length} with the corrected one…`, `استبدال الإعلان العامل ${i + 1} من ${out.swaps.length} بالنسخة المصحّحة…`));
    try {
      const r = await api(`/api/jobs/${encodeURIComponent(out.swaps[i])}/approve`, { method: 'POST', body: {} });
      const j = r.job || {};
      if (r.run_error || j.status !== 'done') out.swapErrors.push(r.run_error || j.waiting || j.result || j.status || 'not run');
    } catch (e) { out.swapErrors.push(String(e.message || e)); }
  }
  return out;
}

export function repriceSummary(o) {
  const lines = [];
  if (o.text) lines.push(tt(`${o.text} ad text(s): the written price corrected to the sale price.`, `${o.text} نص إعلان: صُحّح السعر المكتوب إلى سعر التخفيض.`));
  lines.push(o.fixed ? tt(`${o.fixed} picture(s)/video(s) redrawn: the sale price, each product photo once. Everything else in the designs is unchanged.`, `أُعيد رسم ${o.fixed} صورة/فيديو: سعر التخفيض، وكل صورة منتج مرة واحدة. باقي التصاميم كما هي.`) : tt('Nothing to fix: every ad shows the shop’s current price and each product photo once.', 'لا شيء للتصحيح: كل الإعلانات بسعر المتجر الحالي وكل صورة منتج مرة واحدة.'));
  if (o.swaps.length) lines.push(o.swapErrors.length ? tt(`Live ads: ${o.swaps.length - o.swapErrors.length} of ${o.swaps.length} replaced; the rest wait in Plan → Upcoming jobs (${o.swapErrors[0]}).`, `الإعلانات العاملة: استُبدل ${o.swaps.length - o.swapErrors.length} من ${o.swaps.length}؛ الباقي في الخطة ← المهام القادمة.`) : tt(`${o.swaps.length} live ad(s) replaced with the corrected version (same ad set; Meta reviews them first).`, `استُبدل ${o.swaps.length} إعلان عامل بالنسخة المصحّحة (المجموعة نفسها؛ تراجعها Meta أولاً).`));
  if (o.failed.length) lines.push(tt(`${o.failed.length} could not be redrawn and keep the old picture: `, `${o.failed.length} لم تُعد رسمها وتبقى بالصورة القديمة: `) + o.failed.map((f) => `${f.id} (${f.why})`).join('; '));
  if (o.skipped.length) lines.push(tt(`${o.skipped.length} were made with your own photo: open that set in Create, attach the photo again and save.`, `${o.skipped.length} صُنعت بصورتكِ الخاصة: افتحي المجموعة في «أنشئي»، أرفقي الصورة مجدداً واحفظي.`));
  if (o.text && !o.fixed) lines.splice(1, 1); // the words were corrected: not "nothing to fix"
  return lines;
}

// the card on Creatives: one button. Its state lives here, not in the page: the screen redraws itself (live
// updates) while the correction runs, and the redrawn card shows where it is.
const RP = { busy: false, lines: [] };
const paintCard = () => {
  const o = document.getElementById('rp-out'), b = document.getElementById('rp-go');
  if (o) o.innerHTML = RP.lines.map((l) => `<div>${esc(l)}</div>`).join('');
  if (b) b.disabled = RP.busy;
};

export function repriceCard() {
  return `<div class="card" id="rp-card" style="margin-bottom:14px"><div class="row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div style="flex:1 1 260px;min-width:0"><b>${esc(tt('Fix the ads', 'تصحيح الإعلانات'))}</b><div class="small muted">${esc(tt('Redraws every saved picture and video that shows an old price (it takes the sale price when there is one) or the same product photo more than once. Nothing else in the designs changes; live ads are swapped for the corrected version.', 'يعيد رسم كل صورة وفيديو محفوظ يُظهر سعراً قديماً (يأخذ سعر التخفيض إن وُجد) أو صورة المنتج نفسها أكثر من مرة. لا يتغيّر شيء آخر في التصاميم؛ وتُستبدل الإعلانات العاملة بالنسخة المصحّحة.'))}</div><div class="small" id="rp-out" aria-live="polite">${RP.lines.map((l) => `<div>${esc(l)}</div>`).join('')}</div></div>
    <button type="button" id="rp-go"${RP.busy ? ' disabled' : ''}>${esc(tt('Fix all ads', 'صحّحي كل الإعلانات'))}</button></div></div>`;
}

// autoFix: the same correction, by itself (the owner: "i want a permanent fix"). Checked when the app opens and
// after the shop is read again (a sale that starts or ends), at most every 10 minutes; it draws only when a saved
// picture shows a price other than today's or a repeated photo, and says what it did.
let lastAuto = 0;
export async function autoFix(after) {
  if (RP.busy || Date.now() - lastAuto < 10 * 60e3) return null;
  lastAuto = Date.now();
  RP.busy = true; paintCard();
  try {
    const plan = await api('/api/reprice', { method: 'POST', body: {} });
    if (!(plan.items || []).some((x) => !x.skip) && !plan.text_now) return null;
    const r = await runReprice((t) => { RP.lines = [t]; paintCard(); }, plan);
    RP.lines = repriceSummary(r);
    if (after) after(r);
    return r;
  } catch (e) { RP.lines = [String(e.message || e)]; return null; } finally { RP.busy = false; paintCard(); }
}

export function wireRepriceCard(root, after) {
  const b = root.querySelector('#rp-go');
  if (!b) return;
  b.onclick = async () => {
    if (RP.busy) return;
    RP.busy = true; RP.lines = []; paintCard();
    try {
      const r = await runReprice((t) => { RP.lines = [t]; paintCard(); });
      RP.lines = repriceSummary(r);
      RP.busy = false;
      if (after) after(r);
    } catch (e) { RP.lines = [String(e.message || e)]; }
    RP.busy = false; paintCard();
  };
}
