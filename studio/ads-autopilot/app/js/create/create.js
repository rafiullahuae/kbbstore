// ✨ Create with Claude -- one box, one place (the owner: "the creation part i need to make it super easy, not from
// 100s different apps / models"). Reachable from the top bar, Overview, Creatives and the C key.
//
// He types what he wants (Arabic or English) or taps chips, optionally attaching his own photo or video. ONE engine
// call (POST /api/brain/x/create, NDJSON: think → lint → done) has Claude pick the product(s), angle, hook, family,
// formats and the Arabic + English copy (policy-linted); this page then draws every size locally with the quality
// gate and a motion version. A follow-up ("make the headline shorter") changes only what was asked; only the designs
// whose spec changed are drawn again (specHash). Use / Edit / Regenerate per idea; Save to library; Run it (approved
// for the next cycle) or Launch now (a test ad set through the executor); Download PNG / MP4.
//
// Reliability: every submit carries an idempotency key kept in this browser until it is answered, so a closed tab or a
// dropped connection resends the SAME key and gets the SAME session (never a second creation). Sessions live in the
// engine; reopening the box shows the last one.
import { tt, getLang } from '../i18n.js';
import { esc, toast, txt, confirmBox } from '../ui.js';
import { openRunDialog } from '../screens/live-ui.js';
import { api, apiStream, getAccount } from '../api.js';
import { renderVideo } from '../creative/motion.js';
import { thumb, specHash, creativeID, withPhoto, saveDesign, saveVideo, redrawSave, redrawVideo } from './draw.js';

const OCCASIONS = [['', 'Any time', 'أي وقت'], ['ramadan', 'Ramadan', 'رمضان'], ['eid', 'Eid', 'العيد'], ['white_friday', 'White Friday', 'الجمعة البيضاء'], ['national_day', 'UAE National Day', 'اليوم الوطني'], ['mothers_day', "Mother's Day", 'عيد الأم'], ['summer', 'Summer', 'الصيف']];
const FORMATS = [['', 'All sizes', 'كل المقاسات'], ['post', 'Post 1:1 · 4:5', 'منشور'], ['reel', 'Reel / Story 9:16', 'ريلز / ستوري']];
const LANGS = [['', 'Arabic + English', 'عربي + إنجليزي'], ['ar', 'Arabic', 'عربي'], ['en', 'English', 'إنجليزي']];

const st = { session: null, view: 'ar', busy: false, steps: [], photo: null, video: null, products: null, chips: { product: '', occasion: '', format: '', lang: '' }, drawn: new Map(), videos: new Map(), stats: { drawn: 0, skipped: 0, last: [] }, gen: null };
if (typeof window !== 'undefined') window.__kaaCreate = st;

const PKEY = 'kaa.cc.pending', SKEY = 'kaa.cc.session';
const ls = { get(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch { return null; } }, set(k, v) { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch {} } };
const uid = () => (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2)).replace(/-/g, '').slice(0, 24);

let dlg = null;
function dialog() {
  if (dlg) return dlg;
  dlg = document.createElement('dialog');
  dlg.id = 'cc';
  dlg.className = 'cc';
  document.body.appendChild(dlg);
  dlg.addEventListener('close', () => { if (st.gen) st.gen.abort(); });
  return dlg;
}

// open the box: fresh, on a past session, or with a creation request's brief
export async function openCreate(opts = {}) {
  const g = dialog();
  if (opts.session) { st.session = opts.session; st.drawn.clear(); }
  else if (opts.fresh) { st.session = null; }
  else {
    // always the engine's latest copy: a set kept in memory showed the old price after it was corrected (21 Nov)
    const sid = (st.session && st.session.id) || ls.get(SKEY);
    if (sid) { const all = await api('/api/brain/x/create').catch(() => null); if (all) st.session = all.find((s) => s.id === sid) || null; }
  }
  st.request = opts.request || null;
  paint();
  if (!g.open) g.showModal();
  const ta = g.querySelector('#cc-in');
  if (opts.prompt && ta) ta.value = opts.prompt;
  if (ta) ta.focus();
  if (!st.products) api('/api/brain/x/products').then((p) => { st.products = p || []; if (g.open && !st.busy) { paintChips(); paintProds(); } }).catch(() => {});
  if (!st.gen1) api('/api/brain/x/gen/settings', { noAccount: true }).then((g1) => { st.gen1 = g1; if (g.open && !st.busy) paintNote(); }).catch(() => {});
  // a submit the tab never saw answered: resend the same key (the engine answers with the same session)
  const pend = ls.get(PKEY);
  if (pend && pend.account === getAccount() && !st.busy) run(pend.body, true);
  else if (st.session) drawAll();
}

function chip(group, list) {
  return `<div class="cc-chips" role="group" aria-label="${esc(group)}">${list.map(([v, en, ar]) => `<button type="button" class="chip ${st.chips[group] === v ? 'acc' : ''}" data-chip="${group}" data-v="${esc(v)}">${esc(tt(en, ar))}</button>`).join('')}</div>`;
}

function paintChips() {
  const el = dlg.querySelector('#cc-chips');
  if (!el) return;
  const prods = (st.products || []).slice(0, 40);
  el.innerHTML = `<label class="f cc-prod"><span>${esc(tt('Product', 'المنتج'))}</span><select id="cc-p"><option value="">${esc(tt('Claude picks', 'يختار Claude'))}</option>${prods.map((p) => `<option value="${esc(p.id)}" ${st.chips.product === p.id ? 'selected' : ''}>${esc(p.title)}</option>`).join('')}</select></label>
    ${chip('occasion', OCCASIONS)}${chip('format', FORMATS)}${chip('lang', LANGS)}`;
  el.querySelector('#cc-p').onchange = (e) => { st.chips.product = e.target.value; };
  el.querySelectorAll('[data-chip]').forEach((b) => { b.onclick = () => { st.chips[b.dataset.chip] = b.dataset.v; paintChips(); }; });
}

function paintNote() {
  const el = dlg.querySelector('#cc-note');
  if (!el) return;
  const one = st.gen1 && st.gen1.one_key;
  const hasKey = one && one.key && one.key.set && one.active;
  el.innerHTML = `<span class="chip">${esc(tt('Uses your Claude API key · about $0.05 a request', 'يستخدم مفتاح Claude API · نحو ٠٫٠٥$ للطلب'))}</span>
    ${hasKey ? `<span class="chip acc">${esc(tt(`AI backgrounds on · $${Number(one.image_usd).toFixed(2)} a picture, asked before each`, `خلفيات الذكاء الاصطناعي مفعّلة · $${Number(one.image_usd).toFixed(2)} للصورة، بعد سؤالكِ`))}</span>`
    : `<span class="small muted">${esc(tt('Add the "AI pictures & video" key in Settings → Connections for AI backgrounds and moving scenes; without it the designs are drawn here, free.', 'أضيفي مفتاح «صور وفيديو بالذكاء الاصطناعي» في الإعدادات ← الاتصالات للخلفيات والمشاهد المتحركة؛ بدونه تُرسم التصاميم هنا مجاناً.'))}</span>`}
    <button type="button" class="link small" id="cc-max">${esc(tt('Make it in Claude (Max plan, no credits) instead', 'اصنعيه في Claude (باقة Max، بلا رصيد) بدلاً من ذلك'))}</button>`;
  el.querySelector('#cc-max').onclick = () => {
    const p = dlg.querySelector('#cc-in').value.trim() || tt('Make 2 Arabic + English ads for what the brief says needs them most.', 'اصنع إعلانين بالعربية والإنجليزية لما يحتاجه الموجز أكثر.');
    const brief = `Use the KBB Ads Autopilot connector. Call get_brief, then: ${p}${st.chips.product ? ` (product id ${st.chips.product})` : ''}. Show me the previews.`;
    navigator.clipboard?.writeText(brief).catch(() => {});
    location.href = 'claude://claude.ai/new?q=' + encodeURIComponent(brief);
    toast(tt('Opening Claude with your request (also copied).', 'يُفتح Claude بطلبكِ (ونُسخ أيضاً).'));
  };
}

function paint() {
  const s = st.session;
  const ar = getLang() === 'ar';
  dlg.setAttribute('dir', ar ? 'rtl' : 'ltr');
  dlg.innerHTML = `<div class="cc-wrap">
    <header class="cc-h"><h2>✨ ${esc(tt('Create with Claude', 'أنشئي مع Claude'))}</h2>
      ${s ? `<div class="seg" id="cc-lang">${[['ar', 'عربي'], ['en', 'EN']].map(([k, l]) => `<button type="button" data-v="${k}" class="${st.view === k ? 'on' : ''}">${l}</button>`).join('')}</div>` : ''}
      <span class="spacer"></span>${s ? `<button type="button" class="ghost small" id="cc-new">${esc(tt('New', 'جديد'))}</button>` : ''}<button type="button" class="ghost small" id="cc-x" aria-label="${esc(tt('Close', 'إغلاق'))}">✕</button></header>
    ${st.request ? `<div class="alert"><b>${esc(tt('For the request', 'للطلب'))}:</b> ${txt(ar ? st.request.title_ar : st.request.title)}</div>` : ''}
    <div class="cc-body">${s ? results(s) : intro()}</div>
    <div class="cc-status" id="cc-status" aria-live="polite">${steps()}</div>
    <form class="cc-ask" id="cc-form">
      <textarea id="cc-in" rows="${s ? 1 : 3}" dir="auto" placeholder="${esc(s ? tt('Tell Claude what to change… "make the headline shorter", "more pink", "show the price", "swap to the toner"', 'قولي لـ Claude ما تريدين تغييره… «عنوان أقصر»، «وردي أكثر»، «أظهري السعر»، «بدّلي إلى التونر»') : tt('What do you want? e.g. "Ramadan ad for the snail essence, Arabic, Reel and post" or "3 ads for sunscreen for oily skin"', 'ماذا تريدين؟ مثلاً «إعلان رمضان لإسنس الحلزون، بالعربية، ريلز ومنشور» أو «٣ إعلانات لواقي الشمس للبشرة الدهنية»'))}"></textarea>
      ${s ? '' : `<label class="ghost small cc-attach" title="${esc(tt('Attach your own photo or video', 'أرفقي صورتكِ أو الفيديو'))}">📎 ${esc(st.photo ? tt('Photo attached', 'صورة مرفقة') : st.video ? st.video.name : tt('Photo / video', 'صورة / فيديو'))}<input type="file" id="cc-file" accept="image/*,video/mp4,video/quicktime,video/x-m4v" hidden></label>`}
      <button type="submit" id="cc-go" ${st.busy ? 'disabled' : ''}>${esc(st.busy ? tt('Working…', 'جارٍ العمل…') : s ? tt('Change', 'غيّري') : tt('Create', 'أنشئي'))}</button>
    </form>
    ${s ? '' : '<div id="cc-chips" class="cc-chiprow"></div>'}<div class="cc-note" id="cc-note"></div></div>`;
  dlg.querySelector('#cc-x').onclick = () => dlg.close();
  const nw = dlg.querySelector('#cc-new'); if (nw) nw.onclick = () => { st.session = null; ls.set(SKEY, null); st.photo = null; st.video = null; paint(); };
  dlg.querySelectorAll('#cc-lang [data-v]').forEach((b) => { b.onclick = () => { st.view = b.dataset.v; paint(); drawAll(); }; });
  const fi = dlg.querySelector('#cc-file');
  if (fi) fi.onchange = () => attach(fi.files[0]);
  const ta = dlg.querySelector('#cc-in');
  ta.onkeydown = (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); dlg.querySelector('#cc-form').requestSubmit(); } };
  dlg.querySelector('#cc-form').onsubmit = (e) => { e.preventDefault(); submit(ta.value.trim()); };
  if (!s) paintChips();
  paintNote();
  wireResults();
}

function intro() {
  return `<div class="cc-intro"><p>${esc(tt('Say it in one sentence, Arabic or English. Claude picks the product, the angle and the hook, writes Arabic and English copy (checked against Meta\'s rules), and the app designs every size and a short video. You see it on a phone before anything runs.', 'قوليها في جملة واحدة بالعربية أو الإنجليزية. يختار Claude المنتج والزاوية والجملة الافتتاحية، ويكتب نصاً عربياً وإنجليزياً (مُراجَعاً وفق قواعد ميتا)، ويصمّم التطبيق كل المقاسات وفيديو قصيراً. ترينه على الهاتف قبل أي تشغيل.'))}</p></div>`;
}

const STEP_LBL = {
  think: ['Choosing the product and writing Arabic + English copy…', 'اختيار المنتج وكتابة النص العربي والإنجليزي…'],
  lint: ['Checking the wording against Meta\'s rules…', 'مراجعة الصياغة وفق قواعد ميتا…'],
  design: ['Designing the sizes…', 'تصميم المقاسات…'],
  video: ['Making the video…', 'صنع الفيديو…'],
  wait: ['Finishing your earlier request…', 'إكمال طلبكِ السابق…'],
};
function steps() {
  if (!st.steps.length) return '';
  return st.steps.map((x) => `<span class="cc-step ${x.done ? 'done' : ''} ${x.err ? 'err' : ''}">${x.done ? '✓' : x.err ? '!' : '<i class="cc-spin"></i>'} ${esc(x.err || x.label || tt(...(STEP_LBL[x.k] || [x.k, x.k])))}${x.err ? ` <button type="button" class="small" id="cc-retry">${esc(tt('Try again', 'حاولي مجدداً'))}</button>` : ''}</span>`).join('');
}
function setStep(k, label) {
  for (const x of st.steps) if (!x.err) x.done = true;
  if (k) { st.steps = st.steps.filter((x) => x.k !== k || x.err); st.steps.push({ k, label }); }
  const el = dlg && dlg.querySelector('#cc-status');
  if (el) { el.innerHTML = steps(); wireRetry(); }
}
function failStep(msg) {
  const cur = st.steps[st.steps.length - 1];
  if (cur && !cur.done) cur.err = msg; else st.steps.push({ k: 'err', err: msg });
  const el = dlg && dlg.querySelector('#cc-status');
  if (el) { el.innerHTML = steps(); wireRetry(); }
}
function wireRetry() { const b = dlg.querySelector('#cc-retry'); if (b) b.onclick = () => { const p = ls.get(PKEY); if (p) run(p.body, true); else if (st.lastBody) run(Object.assign({}, st.lastBody, { key: uid() }), false); }; }

async function attach(f) {
  if (!f) return;
  if (f.type.startsWith('image/')) {
    st.photo = await new Promise((ok) => { const r = new FileReader(); r.onload = () => ok(String(r.result)); r.readAsDataURL(f); });
    st.video = null; paint(); return;
  }
  try { // the owner's video goes to the data folder once (the same upload the Videos screen uses)
    toast(tt('Adding your video…', 'إضافة الفيديو…'));
    const v = await api('/api/brain/x/videos', { method: 'POST', body: f, headers: { 'X-Filename': encodeURIComponent(f.name), 'X-Status': 'new' } });
    st.video = { id: v.id, name: f.name }; st.photo = null; paint();
  } catch (e) { toast(e.message); }
}

function submit(text) {
  if (st.busy) return;
  const s = st.session;
  if (s) {
    if (!text) return;
    const m = /^(?:idea|فكرة)\s*(\d)\s*[:：]\s*/i.exec(text);
    run({ session_id: s.id, edit: m ? text.slice(m[0].length) : text, target: m ? +m[1] : (st.target || 0), key: uid() });
    st.target = 0;
    return;
  }
  const c = st.chips;
  if (!text && !c.product && !st.video) { toast(tt('Say what you want, or pick a product.', 'قولي ما تريدين أو اختاري منتجاً.')); return; }
  run({ prompt: text, product_ids: c.product ? [c.product] : [], occasion: c.occasion, formats: c.format === 'post' ? ['1:1', '4:5'] : c.format === 'reel' ? ['9:16'] : [], langs: c.lang ? [c.lang] : [], video_id: st.video ? st.video.id : '', photo: !!st.photo, request_id: st.request ? st.request.id : '', key: uid() });
}

async function run(body, resend) {
  st.busy = true; st.steps = []; st.lastBody = body;
  ls.set(PKEY, { account: getAccount(), body, at: Date.now() });
  const b = dlg.querySelector('#cc-go'); if (b) { b.disabled = true; b.textContent = tt('Working…', 'جارٍ العمل…'); }
  setStep(resend ? 'wait' : 'think');
  st.gen = new AbortController();
  let session = null, err = '';
  try {
    await apiStream('/api/brain/x/create', body, (ln) => {
      if (ln.step === 'think' && resend) setStep('think');
      else if (ln.step === 'lint') setStep('lint');
      else if (ln.step === 'done') session = ln.session;
      else if (ln.step === 'error') err = ln.error;
    }, { signal: st.gen.signal });
  } catch (e) { err = e.name === 'AbortError' ? '' : (e.message || String(e)); }
  st.gen = null;
  if (!session) {
    st.busy = false;
    if (err) { failStep(err); const g = dlg.querySelector('#cc-go'); if (g) { g.disabled = false; g.textContent = tt('Create', 'أنشئي'); } }
    return; // the pending key stays: Try again (or reopening) resends it
  }
  ls.set(PKEY, null);
  ls.set(SKEY, session.id);
  window.__kaa?.cache.delete(getAccount() + '|ccsessions'); // Creatives → Made with Claude reloads
  if (!session.designs.some((d) => d.lang === st.view)) st.view = (session.designs[0] || {}).lang || 'ar';
  st.session = session; st.busy = false;
  const said = (session.turns[session.turns.length - 1] || {}).said;
  paint();
  setStep('design');
  await drawAll();
  if (said) toast(said);
}

// ---------- results ----------
function designsOf(s) { return (s.designs || []).map((d) => withPhoto(d, st.photo)); }

function results(s) {
  const ds = designsOf(s).filter((d) => d.lang === st.view);
  const ideas = [...new Set(ds.map((d) => d.idea))];
  const last = s.turns[s.turns.length - 1] || {};
  return `<div class="cc-said">${txt(last.said || s.prompt)}${last.changed && last.changed.length ? ` <span class="chip">${esc(tt('changed', 'تغيّر'))}: ${esc(last.changed.join(', '))}</span>` : ''}</div>
  <datalist id="cc-plist">${(st.products || []).map((p) => `<option value="${esc(p.title)}"></option>`).join('')}</datalist>
  <div class="cc-ideas">${ideas.map((n) => { const d = ds.find((x) => x.idea === n); const rtl = d.lang === 'ar';
    return `<section class="cc-idea" data-idea="${n}"><header><b>${esc(tt('Idea', 'الفكرة'))} ${n}</b> <span class="chip">${esc(d.template)}</span> <span class="muted small">${txt(d.angle)}</span></header>
    ${prodRow(n, d.product_ids || [])}
    <div class="cc-phones">
      <div><p class="small muted">${esc(tt('Feed 4:5', 'الخلاصة ٤:٥'))}</p><div class="phone"><div class="scr"><div class="post"><header><i></i><b>kbeautybliss</b><span class="muted">· ${esc(tt('Sponsored', 'مُموَّل'))}</span></header>
        <div class="txt" ${rtl ? 'dir="rtl" lang="ar"' : ''}>${esc(d.primary_text || d.sub || '')}</div><div class="cc-slot" data-slot="${esc(d.id)}|4:5"><div class="sk"></div></div>
        <div class="bar" ${rtl ? 'dir="rtl"' : ''}><span>kbeautybliss.com<br><b>${esc(d.headline)}</b></span><span class="chip">${esc(rtl ? 'تسوّقي الآن' : 'Shop now')}</span></div></div></div></div></div>
      <div><p class="small muted">${esc(tt('Story / Reel 9:16', 'ستوري / ريلز ٩:١٦'))}</p><div class="phone"><div class="scr"><div class="story cc-story" data-slot="${esc(d.id)}|9:16"><div class="sk"></div></div></div></div></div>
      <div class="cc-side"><p class="small muted">1:1</p><div class="cc-sq" data-slot="${esc(d.id)}|1:1"><div class="sk"></div></div><div class="cc-q" data-q="${esc(d.id)}"></div>
        <div class="cc-acts"><button type="button" class="small" data-use="${n}">${esc(tt('Use', 'استخدمي'))}</button><button type="button" class="small ghost" data-edit="${n}">${esc(tt('Edit', 'عدّلي'))}</button><button type="button" class="small ghost" data-regen="${n}" title="${esc(tt('A NEW design from Claude (angle, hook, family change)', 'تصميم جديد من Claude (تتغيّر الزاوية والتصميم)'))}">${esc(tt('Regenerate', 'أعيدي الإنشاء'))}</button>
          <button type="button" class="small ghost" data-redraw="${n}" title="${esc(tt('The SAME design drawn again in every size and the video, and saved to the library', 'التصميم نفسه يُرسم من جديد بكل المقاسات والفيديو ويُحفظ في المكتبة'))}">↻ ${esc(tt('Redraw & save', 'أعيدي الرسم واحفظي'))}</button></div></div>
    </div></section>`; }).join('')}</div>
  <div class="cc-bar"><span class="small muted" id="cc-drawn"></span><span class="spacer"></span>
    <button type="button" class="ghost" id="cc-redraw" title="${esc(tt('Every idea of this set: the same designs drawn again in every size and the videos, all saved to the library', 'كل أفكار المجموعة: التصاميم نفسها تُرسم من جديد بكل المقاسات والفيديو وتُحفظ'))}">↻ ${esc(tt('Redraw all & save', 'أعيدي رسم الكل واحفظي'))}</button>
    <button type="button" class="ghost" id="cc-dl">⬇ ${esc(tt('Download', 'تنزيل'))}</button>
    <button type="button" class="ghost" id="cc-save">${esc(tt('Save to library', 'حفظ في المكتبة'))}</button>
    <button type="button" class="ghost" id="cc-launch" title="${esc(tt('A small test ad set now, through every check, inside your limits', 'مجموعة اختبار صغيرة الآن، عبر كل الفحوص وضمن حدودكِ'))}">${esc(tt('Launch now (test)', 'أطلقي الآن (اختبار)'))}</button>
    <button type="button" id="cc-run" title="${esc(tt('The autopilot tests it in its next cycle, inside your limits', 'يختبره الطيار الآلي في دورته القادمة ضمن حدودكِ'))}">▶ ${esc(tt('Run it', 'شغّليه'))}</button></div>`;
}

// the products of one idea: ✕ takes one out (an idea keeps at least one), "+ Add or swap" puts one in (max 3). The
// owner's own edit: no Claude call, the words and design stay, the photos and price follow (engine create.go).
function prodRow(n, ids) {
  const name = (id) => { const p = (st.products || []).find((x) => x.id === id); return p ? p.title : `#${id}`; };
  return `<div class="cc-prods row small" data-prods="${n}" style="gap:6px;flex-wrap:wrap;margin:6px 0 10px"><span class="muted">${esc(tt('Products', 'المنتجات'))}:</span>
    ${ids.map((id) => `<span class="chip">${txt(name(id).slice(0, 48))}${ids.length > 1 ? ` <button type="button" class="link" data-rmprod="${n}|${esc(id)}" aria-label="${esc(tt('Take out', 'إزالة'))}" title="${esc(tt('Take this product out of the idea', 'إزالة المنتج من الفكرة'))}">✕</button>` : ''}</span>`).join('')}
    ${ids.length < 3 ? `<input list="cc-plist" data-addprod="${n}" placeholder="${esc(tt('+ Add or swap a product…', '+ أضيفي أو بدّلي منتجاً…'))}" style="min-width:220px;max-width:100%">` : ''}
    ${ids.length === 1 ? `<span class="muted">${esc(tt('To swap: add the new one, then ✕ the old one.', 'للتبديل: أضيفي الجديد ثم ✕ القديم.'))}</span>` : ''}</div>`;
}

// the product names arrive after the box opens: only the product rows (and the search list) are painted again
function paintProds() {
  if (!st.session || !dlg) return;
  const dl = dlg.querySelector('#cc-plist'); if (dl) dl.innerHTML = (st.products || []).map((p) => `<option value="${esc(p.title)}"></option>`).join('');
  dlg.querySelectorAll('[data-prods]').forEach((el) => { const n = +el.dataset.prods; const d = (st.session.designs || []).find((x) => x.idea === n); if (d) el.outerHTML = prodRow(n, d.product_ids || []); });
  wireResults();
}

async function setProducts(n, ids, removed) {
  try {
    const s = await api(`/api/brain/x/create/${st.session.id}/idea/${n}`, { method: 'PUT', body: { product_ids: ids } });
    st.session = s; paint(); drawAll();
    const p = removed && (st.products || []).find((x) => x.id === removed);
    const words = (s.designs || []).filter((d) => d.idea === n).map((d) => `${d.headline} ${d.primary_text || ''} ${d.sub || ''}`).join(' ').toLowerCase();
    const still = p && p.brand && words.includes(p.brand.toLowerCase()) && !ids.some((id) => ((st.products || []).find((x) => x.id === id) || {}).brand === p.brand);
    toast(still ? tt(`Products changed. The words still name ${p.brand}: press Edit to rewrite them.`, `تغيّرت المنتجات. النص ما زال يذكر ${p.brand}: اضغطي «عدّلي» لإعادة كتابته.`) : tt('Products changed: the words and design stay, the photos follow.', 'تغيّرت المنتجات: النص والتصميم كما هما، والصور تتبع المنتجات.'));
  } catch (e) { toast(e.message); }
}

// Redraw & save: the same designs of the chosen ideas (all when none), every size + the video, saved
async function redrawIdeas(only) {
  const s = st.session;
  if (!s || st.redrawing) return;
  st.redrawing = true;
  const ds = designsOf(s).filter((d) => !only || only.includes(d.idea));
  const el = () => dlg && dlg.querySelector('#cc-drawn');
  const out = { ids: [], weak: [], videos: 0 };
  try {
    for (let i = 0; i < ds.length; i++) {
      const d = ds[i];
      if (el()) el().textContent = tt(`Redrawing ${i + 1} of ${ds.length} (idea ${d.idea}, ${d.lang.toUpperCase()})…`, `إعادة رسم ${i + 1} من ${ds.length}…`);
      const r = await redrawSave(d); out.ids.push(...r.ids); out.weak.push(...r.weak);
      if (d.motion) {
        const v = await renderVideo(d, '9:16', 6, null, () => {});
        if (v.mime === 'video/mp4') { out.ids.push(await redrawVideo(d, v.blob)); out.videos++; }
        const old = st.videos.get(d.id); if (old) URL.revokeObjectURL(old.url);
        st.videos.set(d.id, { h: specHash(d), url: URL.createObjectURL(v.blob), blob: v.blob, bytes: v.blob.size });
      }
      for (const f of d.formats || []) st.drawn.delete(`${d.id}|${f}`);
    }
    await api(`/api/brain/x/create/${s.id}`, { method: 'PUT', body: { saved: out.ids, used: ds.map((d) => d.id) } });
    st.session.saved = [...new Set([...(st.session.saved || []), ...out.ids])];
    window.__kaaRedraw = out;
    toast(tt(`${only ? 'Idea ' + only.join(', ') : 'The whole set'}: ${out.ids.length - out.videos} pictures + ${out.videos} video(s) drawn again and saved — same design, every size.${out.weak.length ? ` ${out.weak.length} size(s) are below the quality check and were saved anyway: ${out.weak.map((w) => w.format).join(', ')}.` : ''}`,
      `${only ? 'الفكرة ' + only.join('، ') : 'المجموعة كلها'}: أُعيد رسم ${out.ids.length - out.videos} صورة و${out.videos} فيديو وحُفظت — التصميم نفسه بكل المقاسات.${out.weak.length ? ` ${out.weak.length} مقاس تحت فحص الجودة وحُفظ رغم ذلك.` : ''}`));
  } catch (e) { toast(e.message); window.__kaaRedraw = { error: String(e.message || e) }; } finally { st.redrawing = false; drawAll(); }
}

function wireResults() {
  if (!st.session) return;
  dlg.querySelectorAll('[data-redraw]').forEach((b) => { b.onclick = () => redrawIdeas([+b.dataset.redraw]); });
  const ra = dlg.querySelector('#cc-redraw'); if (ra) ra.onclick = () => redrawIdeas(null);
  dlg.querySelectorAll('[data-rmprod]').forEach((b) => { b.onclick = () => { const [n, id] = b.dataset.rmprod.split('|'); const d = (st.session.designs || []).find((x) => x.idea === +n); setProducts(+n, (d.product_ids || []).filter((x) => x !== id), id); }; });
  dlg.querySelectorAll('[data-addprod]').forEach((inp) => { inp.onchange = () => { const p = (st.products || []).find((x) => x.title === inp.value.trim()); if (!p) { toast(tt('Pick a product from the list.', 'اختاري منتجاً من القائمة.')); return; } const n = +inp.dataset.addprod; const d = (st.session.designs || []).find((x) => x.idea === n); setProducts(n, [...new Set([...(d.product_ids || []), p.id])].slice(0, 3)); }; });
  dlg.querySelectorAll('[data-edit]').forEach((b) => { b.onclick = () => { st.target = +b.dataset.edit; const ta = dlg.querySelector('#cc-in'); ta.placeholder = tt(`What should change in idea ${b.dataset.edit}?`, `ما الذي يتغيّر في الفكرة ${b.dataset.edit}؟`); ta.focus(); }; });
  dlg.querySelectorAll('[data-regen]').forEach((b) => { b.onclick = () => run({ session_id: st.session.id, edit: `Make idea ${b.dataset.regen} again with a different angle, hook and design family; keep the product.`, target: +b.dataset.regen, key: uid() }); });
  dlg.querySelectorAll('[data-use]').forEach((b) => { b.onclick = () => saveIdeas([+b.dataset.use]).then((r) => r && toast(tt(`Idea ${b.dataset.use} saved to the library (${r.ids.length} pictures).`, `حُفظت الفكرة ${b.dataset.use} في المكتبة (${r.ids.length} صور).`))); });
  dlg.querySelector('#cc-save').onclick = () => saveIdeas().then((r) => r && toast(tt(`${r.ids.length} pictures saved${r.refused.length ? `, ${r.refused.length} held back by the quality check` : ''}.`, `حُفظت ${r.ids.length} صور${r.refused.length ? `، وحُجبت ${r.refused.length}` : ''}.`)));
  dlg.querySelector('#cc-run').onclick = () => runIt(false);
  dlg.querySelector('#cc-launch').onclick = () => runIt(true);
  dlg.querySelector('#cc-dl').onclick = download;
}

// drawAll: draw every visible slot; a design whose spec did not change is NOT drawn again.
async function drawAll() {
  const s = st.session;
  if (!s || !dlg || !dlg.open) return;
  const gen = (st.drawGen = (st.drawGen || 0) + 1);
  const ds = designsOf(s);
  let drawn = 0, skipped = 0;
  const changed = [];
  for (const d of ds) {
    const h = specHash(d);
    for (const f of ['4:5', '9:16', '1:1']) {
      const k = `${d.id}|${f}`;
      const have = st.drawn.get(k);
      if (have && have.h === h) { skipped++; continue; }
      if (d.lang !== st.view) continue; // drawn when he switches language
      const r = await thumb(d, f, f === '9:16' ? 300 : 360);
      if (gen !== st.drawGen) return;
      st.drawn.set(k, { h, url: r.url, q: r.q });
      drawn++; changed.push(k);
      fillSlot(k);
    }
  }
  for (const d of ds) for (const f of ['4:5', '9:16', '1:1']) fillSlot(`${d.id}|${f}`);
  st.stats.drawn += drawn; st.stats.skipped += skipped; st.stats.last = changed;
  const el = dlg.querySelector('#cc-drawn');
  if (el) el.textContent = tt(`${drawn} drawn, ${skipped} unchanged`, `رُسم ${drawn}، ودون تغيير ${skipped}`);
  // motion: one 9:16 video per idea with motion, in the language on show, again only if its design changed
  const mv = ds.filter((d) => d.motion && d.lang === st.view);
  if (mv.length) {
    let started = false;
    for (const d of mv) {
      const h = specHash(d), have = st.videos.get(d.id);
      if (have && have.h === h) { fillVideo(d.id); continue; }
      if (!started) { setStep('video'); started = true; }
      try {
        const r = await renderVideo(d, '9:16', 6, null, () => {});
        if (gen !== st.drawGen) return;
        if (have) URL.revokeObjectURL(have.url);
        st.videos.set(d.id, { h, url: URL.createObjectURL(r.blob), blob: r.blob, bytes: r.blob.size });
        fillVideo(d.id);
      } catch (e) { failStep(tt('The video could not be made here: ', 'تعذّر صنع الفيديو هنا: ') + e.message); return; }
    }
  }
  setStep(null);
}

function fillSlot(k) {
  const v = st.drawn.get(k), slot = dlg && dlg.querySelector(`[data-slot="${CSS.escape(k)}"]`);
  if (!v || !slot) return;
  const [id, f] = k.split('|');
  if (f === '9:16' && st.videos.has(id)) return;
  slot.innerHTML = `<img src="${v.url}" alt="">${f === '9:16' ? `<div class="cta">⌃ ${esc(getLang() === 'ar' ? 'تسوّقي الآن' : 'Shop now')}</div>` : ''}`;
  if (f === '4:5') {
    const q = dlg.querySelector(`[data-q="${CSS.escape(id)}"]`);
    if (q) q.innerHTML = ['1:1', '4:5', '9:16'].map((ff) => { const x = st.drawn.get(`${id}|${ff}`); return x ? `<span class="chip ${x.q.ok ? 'acc' : ''}" title="${esc(x.q.fails.join(' · '))}">${ff} ${x.q.ok ? '✓' : '✕'} ${x.q.score}</span>` : ''; }).join(' ');
  }
}
function fillVideo(id) {
  const v = st.videos.get(id), slot = dlg && dlg.querySelector(`[data-slot="${CSS.escape(id + '|9:16')}"]`);
  if (v && slot) slot.innerHTML = `<video src="${v.url}" poster="${(st.drawn.get(id + "|9:16") || {}).url || ""}" autoplay muted loop playsinline></video><div class="cta">⌃ ${esc(getLang() === 'ar' ? 'تسوّقي الآن' : 'Shop now')}</div>`;
}

async function saveIdeas(only) {
  const s = st.session;
  const ds = designsOf(s).filter((d) => !only || only.includes(d.idea));
  const out = { ids: [], refused: [] };
  try {
    for (const d of ds) { const r = await saveDesign(d); out.ids.push(...r.ids); out.refused.push(...r.refused); }
    for (const d of ds) { const v = st.videos.get(d.id); if (v && v.h === specHash(d)) out.ids.push(await saveVideo(d, v.blob)); }
    await api(`/api/brain/x/create/${s.id}`, { method: 'PUT', body: { saved: out.ids, used: ds.map((d) => d.id) } });
    st.session.saved = [...new Set([...(st.session.saved || []), ...out.ids])];
    return out;
  } catch (e) { toast(e.message); return null; }
}

async function runIt(launch) {
  const s = st.session;
  if (launch && !(await confirmBox(tt('Launch a test now?', 'إطلاق اختبار الآن؟'), tt('A small test ad set goes live now, through every check, inside your limits.', 'مجموعة اختبار صغيرة تُطلق الآن، عبر كل الفحوص وضمن حدودكِ.'), tt('Launch', 'إطلاق')))) return;
  const ds = designsOf(s).filter((d) => d.lang === 'ar' || d.lang === 'en');
  let ids = [];
  const r0 = await saveIdeas();
  if (!r0) return;
  // the feed picture (4:5) of each language + the video run; every size stays in the library
  // Run it: the feed picture (4:5) of each language + the videos go to the autopilot; Launch now tests the feed
  // pictures only (one ad per idea and language), so a test stays small. Every size stays in the library.
  ids = r0.ids.filter((id) => /-4x5$/.test(id) || (!launch && /-mp4$/.test(id)));
  if (!ids.length) ids = r0.ids.slice(0, 4);
  if (!ids.length) { toast(tt('Nothing passed the quality check to run.', 'لا شيء اجتاز فحص الجودة.')); return; }
  try {
    const r = await api('/api/launch', { method: 'POST', body: { creative_ids: ids.slice(0, 12), launch, key: s.id + (launch ? '-launch' : '-run') } });
    await api(`/api/brain/x/create/${s.id}`, { method: 'PUT', body: { ran: ids, proposals: r.proposals || [] } });
    window.__kaaLaunch = Object.assign({ ids }, r);
    // Run it: a window that stays, with what was sent, every step live and the outcome (screens/live-ui.js)
    const nameOf = (id) => { const d = ds.find((x) => id.startsWith(creativeID(x, 'x').slice(0, -2) + '-')); return d ? `${d.headline || d.idea || id} · ${(d.lang || '').toUpperCase()} · ${/-mp4$/.test(id) ? tt('video', 'فيديو') : tt('picture 4:5', 'صورة 4:5')}` : id; };
    if (!launch) { openRunDialog(r, ids.slice(0, 12).map((id) => ({ name: nameOf(id) }))); return; }
    toast(tt(`Launched: ${(r.counts || {}).done || 0} steps done, ${(r.counts || {}).refused || 0} refused by the checks.`, `أُطلق: ${(r.counts || {}).done || 0} خطوات، ورُفضت ${(r.counts || {}).refused || 0}.`));
    void ds;
  } catch (e) { if (launch) toast(e.message); else openRunDialog({ note: tt('Not sent: ', 'لم يُرسل: ') + e.message }, ids.map((id) => ({ name: id }))); }
}

async function download() {
  const s = st.session;
  const ds = designsOf(s).filter((d) => d.lang === st.view);
  const { renderSpec, toPNG } = await import('../creative/render.js');
  for (const d of ds) {
    for (const f of d.formats || ['1:1', '4:5', '9:16']) {
      const { b64 } = await toPNG(await renderSpec(d, f));
      const a = document.createElement('a'); a.href = 'data:image/png;base64,' + b64; a.download = `kbb-${d.lang}-idea${d.idea}-${f.replace(':', 'x')}.png`; a.click();
    }
    const v = st.videos.get(d.id);
    if (v) { const a = document.createElement('a'); a.href = v.url; a.download = `kbb-${d.lang}-idea${d.idea}-9x16.mp4`; a.click(); }
  }
}

