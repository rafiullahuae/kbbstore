/* Free gifts, on the phone.                                         (Lane OAG)

   The owner, 28 October: "to add and manage gifts products in each range etc.
   through owner app, the same functionality which i have on desktop admin".
   Marketing -> Free gifts -> Ranges & gifts, phone first: every range, and in
   each one its gift products by type (masks / minis / full size), main and
   backup, quantities, Choose variation, add / edit / duplicate / delete, its
   own Save -- and the Mode.

   ONE RULE. Nothing here decides anything about gifts: every save goes to the
   console's own FreeGiftsController (via OwnerApp\GiftsController), which
   validates, stores and recompiles; the "what a shopper gets now" list is the
   shop's own FreeGifts::linesFor() answer. The counts and messages drawn here
   are display, the same walk the console's screen draws as you type.

   NO OVERRIDES. Each range edit remembers the revision it started from and
   sends it; a save made after somebody else saved (the desk, another phone)
   is refused 409 with nothing written, and a sheet lists his unsaved edits
   with a Refresh. The header's refresh icon re-reads on tap.

   LIGHT. One GET on open (none while it is fresh), one per save, the picker's
   search after 300 ms of typing and at 2 letters or more. Nothing polls, no
   timer runs. Every value from the server passes through esc(). */
import { S, esc, api, $, ic, th, top, back, toast, busy, paint, errorBox, ago, sheet, screen, onScreen, mark, once, landed, isFresh, onReset, ln, times, skNote, tgl, debounce } from './core.js';

const KINDS = [['mask', 'Face masks', 'Masks', 'face mask', 'face masks'], ['mini', 'Mini products', 'Minis', 'mini product', 'mini products'], ['full', 'Full size products', 'Full size', 'full size product', 'full size products']];
const KEYS = ['mask', 'mini', 'full'];
const MODES = [['off', 'Off', 'Nobody sees free gifts.'], ['testing', 'Testing', 'Only the IPs on the Testing list see them.'], ['live', 'Live', 'Every shopper sees them.']];

const G = { d: null, drafts: {}, removed: {}, err: {}, busy: false };
onReset(() => Object.assign(G, { d: null, drafts: {}, removed: {}, err: {}, busy: false }));

const fmt = (n) => Number(n || 0).toLocaleString('en-US');
const amount = (min, max) => 'AED ' + fmt(min) + (max == null ? '+' : ' – ' + fmt(max));
const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
const clone = (o) => JSON.parse(JSON.stringify(o));
const kindOf = (v) => (KEYS.indexOf(v) >= 0 ? v : null);
const kindRow = (k) => KINDS.find((r) => r[0] === k) || null;
const gk = (g) => (g.variant_id ? g.product_id + 'v' + g.variant_id : String(g.product_id));
const can = () => !!(G.d && G.d.can_manage && S.me.can.gifts_edit);
const low = () => (G.d && G.d.low_stock) || 20;
const maxQty = () => (G.d && G.d.max_qty) || 50;
const maxNeed = () => (G.d && G.d.max_need) || 50;
const today = () => { const d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
const niceDate = (s) => { if (!s) return ''; const d = new Date(s + 'T00:00:00'); return isNaN(d) ? s : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); };

/* ------------------------------------------------------------ the model */

const ranges = () => (G.d ? G.d.ranges.slice().sort((a, b) => a.min - b.min || a.id - b.id) : []);
const rangeById = (id) => (G.d ? G.d.ranges.find((r) => r.id === id) || null : null);
const origOf = (r) => ({ min: r.min, max: r.max, label_en: r.label_en, label_ar: r.label_ar, enabled: r.enabled, starts_on: r.starts_on, ends_on: r.ends_on, needs: r.needs ? clone(r.needs) : null, gifts: clone(r.gifts) });
const view = (r) => G.drafts[r.id] || origOf(r);
function draft(id) {
  if (!G.drafts[id]) { const r = rangeById(id); if (r) G.drafts[id] = Object.assign(origOf(r), { rev: G.d.revision }); }
  return G.drafts[id];
}
function needsOf(x) {
  const n = x && x.needs;
  if (!n) return null;
  return KEYS.reduce((a, k) => a + (Number(n[k]) || 0), 0) > 0 ? n : null;
}
const norm = (x) => JSON.stringify([x.min, x.max, (x.label_en || '').trim(), (x.label_ar || '').trim(), !!x.enabled, x.starts_on || null, x.ends_on || null,
  x.gifts.map((g) => [g.product_id, g.quantity, kindOf(g.kind), g.role === 'backup' ? 'backup' : 'primary', g.variant_id || null]), needsOf(x) ? KEYS.map((k) => Number(x.needs[k]) || 0) : null]);
function isDirty(id) {
  if (id === 'new') return !!G.drafts.new;
  const r = rangeById(id), x = G.drafts[id];
  return !!(r && x && norm(x) !== norm(origOf(r)));
}
const dirtyIds = () => Object.keys(G.drafts).filter((k) => isDirty(k === 'new' ? 'new' : +k));
/* Units per type, main gifts only: a backup is a reserve, not part of the count. */
function tally(gifts) {
  const out = { mask: 0, mini: 0, full: 0, none: 0 };
  (gifts || []).forEach((g) => { if (g.role !== 'backup') out[kindOf(g.kind) || 'none'] += Math.max(0, Number(g.quantity) || 0); });
  return out;
}
function stockOf(p, qty) {
  if (!p) return ['out', 'Missing'];
  if (p.status !== 'publish') return ['out', 'Not published'];
  if (p.type === 'variable') return ['info', 'Has options · gift a variation'];
  if (!p.in_stock) return ['out', 'Out of stock'];
  if (p.stock !== null && qty && p.stock < qty) return ['low', 'Only ' + p.stock + ' left'];
  if (p.stock !== null && p.stock <= low()) return ['low', 'Low · ' + p.stock + ' left'];
  if (p.stock === null) return ['low', 'Stock not tracked'];
  return ['ok', 'In stock · ' + fmt(p.stock)];
}
const spill = (s) => '<span class="pill g-' + s[0] + '">' + esc(s[1]) + '</span>';
const gpill = (cls, t) => '<span class="pill g-' + cls + '">' + esc(t) + '</span>';
function bands() {
  const list = ranges().map((r) => { const v = view(r); return { id: r.id, min: v.min, max: v.max, enabled: v.enabled }; });
  if (G.drafts.new) list.push({ id: 'new', min: G.drafts.new.min, max: G.drafts.new.max, enabled: G.drafts.new.enabled });
  return list.filter((b) => typeof b.min === 'number' && !isNaN(b.min));
}
const overlaps = (aMin, aMax, bMin, bMax) => aMin < (bMax == null ? Infinity : bMax + 1) && bMin < (aMax == null ? Infinity : aMax + 1);
function gaps() {
  const on = bands().filter((b) => b.enabled && (b.max == null || b.max > b.min)).sort((a, b) => a.min - b.min), out = [];
  let reach = null;
  for (const b of on) {
    if (reach !== null && b.min > reach + 1) out.push({ from: reach + 1, to: b.min - 1 });
    if (b.max == null) break;
    reach = reach === null ? b.max : Math.max(reach, b.max);
  }
  return out;
}
const nextFree = () => bands().reduce((a, b) => Math.max(a, b.max == null ? b.min + 1 : b.max + 1), 0);
/* [level, text] for one range as it reads now. The server stays the authority on Save. */
function problems(id, x) {
  const out = [];
  if (typeof x.min !== 'number' || isNaN(x.min) || x.min < 0) out.push(['err', 'Enter the amount the range starts at.']);
  else {
    if (x.max != null && x.max < x.min) out.push(['warn', 'The top amount is below the start; it saves as ' + amount(x.max, x.min) + '.']);
    const lo = x.max != null && x.max < x.min ? x.max : x.min, hi = x.max != null && x.max < x.min ? x.min : x.max;
    bands().forEach((b) => { if (String(b.id) !== String(id) && overlaps(lo, hi, b.min, b.max)) out.push(['warn', 'Shares amounts with ' + amount(b.min, b.max) + '. A basket in both gets the higher range.']); });
    if (x.enabled) gaps().forEach((g) => { if (g.to + 1 === x.min || (x.max != null && g.from - 1 === x.max)) out.push(['warn', 'Gap: ' + amount(g.from, g.to) + ' gives nothing.']); });
  }
  if (!needsOf(x) && (!(x.label_en || '').trim() || !(x.label_ar || '').trim())) out.push(['err', 'The wording is needed in English and in Arabic.']);
  if (x.starts_on && x.ends_on && x.ends_on < x.starts_on) out.push(['err', 'The end date is before the start date.']);
  if (x.enabled && !x.gifts.length) out.push(['warn', 'On, with no gifts: this range gives nothing until a gift is added.']);
  x.gifts.forEach((g) => {
    const s = stockOf(g.product, g.role === 'backup' ? 1 : g.quantity);
    if (s[0] === 'out') out.push(['warn', (g.product ? g.product.name : 'A gift') + ': ' + s[1].toLowerCase() + ' — skipped until restocked. The rest of this range is still given.']);
  });
  const n = needsOf(x), have = tally(x.gifts);
  if (n) KINDS.forEach((k) => {
    const need = Number(n[k[0]]) || 0, got = have[k[0]];
    if (got < need) out.push(['warn', k[1] + ': ' + got + ' of ' + need + ' chosen — add ' + (need - got) + ' more.']);
    else if (got > need) out.push(['warn', k[1] + ': ' + got + ' chosen, criteria says ' + need + '.']);
  });
  if (n && have.none) out.push(['warn', plural(have.none, 'gift is', 'gifts are') + ' untagged, so not counted — set its type.']);
  return out;
}
function pills(id, x) {
  const p = [x.enabled ? gpill('ok', 'On') : gpill('mute', 'Off')], t = today(), lv = x.gifts.map((g) => stockOf(g.product, g.quantity)[0]);
  if (x.starts_on && x.starts_on > t) p.push(gpill('info', 'Starts ' + niceDate(x.starts_on)));
  else if (x.ends_on && x.ends_on < t) p.push(gpill('mute', 'Ended ' + niceDate(x.ends_on)));
  else if (x.ends_on) p.push(gpill('info', 'Until ' + niceDate(x.ends_on)));
  if (!x.gifts.length) p.push(gpill('out', 'No gifts'));
  if (lv.indexOf('out') >= 0) p.push(gpill('out', 'Out of stock')); else if (lv.indexOf('low') >= 0) p.push(gpill('low', 'Low stock'));
  if (needsOf(x)) { const n = problems(id, x).filter((m) => / chosen|untagged/.test(m[1])).length; p.push(n ? gpill('low', 'Criteria: ' + plural(n, 'thing', 'things') + ' to check') : gpill('ok', 'Matches criteria')); }
  if (problems(id, x).some((m) => m[0] === 'err')) p.push(gpill('out', 'Needs a fix'));
  if (isDirty(id)) p.push(gpill('info', 'Unsaved'));
  return p.join('');
}
const counts = (x) => { const t = tally(x.gifts); return KINDS.filter((k) => t[k[0]]).map((k) => t[k[0]] + ' ' + (t[k[0]] === 1 ? k[3] : k[4])).concat(t.none ? [t.none + ' untagged'] : []).join(' · ') || 'No gifts yet'; };

/* ------------------------------------------------------------ the data */

const sub = () => (G.d ? 'Up to date · ' + (ago(G.at) === 'now' ? 'just now' : ago(G.at) + ' ago') : 'Syncing…');

/** The one read: on open (unless fresh), on Sync now, on Refresh after a conflict. */
export function fetchGifts(passive, force) {
  return once('gifts', async () => {
    const g = S.gen;
    const r = await api('GET', 'gifts', undefined, passive);
    if (g !== S.gen) return false;
    const host = onScreen('gifts');
    if (!r.ok) {
      if (host && !G.d) { paint($(':scope > .body', host), errorBox(r.data.message)); mark(host, 'gifts', 0, false); }
      else if (!passive) toast(r.data.message || 'Could not refresh the gifts.', true);
      return false;
    }
    take(r.data);
    redraw();
    return true;
  }, force);
}

/* A new answer: drafts with no edits are dropped (they would carry an old
   revision into his next edit); edited ones keep the revision they began from,
   so their Save is refused if somebody else saved meanwhile. `ours` is the
   revision our own write started from: a draft that began there saw exactly
   what the server had before that write, so it moves on with it. */
function take(d, ours) {
  Object.keys(G.drafts).forEach((k) => {
    const x = G.drafts[k];
    if (!isDirty(k === 'new' ? 'new' : +k)) delete G.drafts[k];
    else if (ours && x.rev === ours) x.rev = d.revision;
  });
  G.d = d;
  G.at = new Date().toISOString();
  landed('gifts');
}

/* --------------------------------------------------------------- screens */

let host = null, cur = null;

export async function renderGifts(v, id) {
  host = v;
  v.className = 'view single';
  // (2.60.628's address for Gift box clicks, kept: it now has its own screen.)
  if (/\/gifts\/clicks$/.test(location.hash)) { location.replace('#/clicks?from=gifts'); return; }
  cur = /\/gifts\/new$/.test(location.hash) ? 'new' : (id || 0);
  if (cur === 'new' && !G.drafts.new) addRange(null, true);
  // Paint directly, not through redraw(): redraw() only repaints a screen
  // that is ALREADY Free gifts, and on a second visit (data in hand) the view
  // still holds More, so it painted nothing and the screen never opened
  // (2.60.616, the owner: "open the free gifts page, go back, and when try to
  // open again, it's not clickable at all").
  if (G.d) { if (cur === 0) paintHome(); else paintRange(); }
  else {
    screen(v, 'gifts', 0, top('Free gifts', 'Syncing…', back('#/more')),
      skNote + times(2, () => '<div class="card">' + ln(60) + ln(85, 'k-sm') + '</div>') + '<div class="list">' + times(3, () => '<div class="row"><div class="rm">' + ln(50) + ln(75, 'k-sm') + '</div></div>') + '</div>', true);
  }
  if (!G.d || !isFresh('gifts')) await fetchGifts();
}

function redraw() {
  if (!host || !onScreen('gifts') || !G.d) return;
  if (cur === 0) paintHome(); else paintRange();
}

function paintHome() {
  const d = G.d, mode = d.mode, list = ranges();
  const head = top('Free gifts', '<span data-gsub>' + esc(sub()) + '</span>', back('#/more'));
  const checks = d.checklist || [];
  let body = '<section class="card g-mode"><h4 class="sh">Mode <span class="muted">' + esc((MODES.find((m) => m[0] === mode) || MODES[0])[2]) + '</span></h4>'
    + '<div class="g-modes" role="radiogroup" aria-label="Mode">' + MODES.map((m) => '<button type="button" role="radio" class="m-' + m[0] + (mode === m[0] ? ' on' : '') + '" aria-checked="' + (mode === m[0]) + '" data-gmode="' + m[0] + '"' + (can() ? '' : ' disabled') + '>' + ic('lock', 's') + m[1] + '</button>').join('') + '</div>'
    + (mode === 'testing' ? '<p class="hint g-hint">' + esc(plural(d.testers, 'IP is', 'IPs are')) + ' on the Testing list.</p>' : '') + '</section>';
  if (checks.length) {
    body += '<section class="card g-checks"><h4 class="sh">Things to check <span class="muted">' + checks.length + '</span></h4>'
      + checks.map((c) => '<' + (c.range ? 'a href="#/gifts/' + c.range + '"' : 'div') + ' class="row g-ck ' + (c.level === 'alert' ? 'bad' : 'warn') + '"><span class="g-ckd"></span><div class="rm"><small class="g-wrap">' + esc(c.text) + '</small></div></' + (c.range ? 'a' : 'div') + '>').join('') + '</section>';
  }
  body += '<a class="row g-ckl" href="#/clicks?from=gifts"><span class="tone t-acc">' + ic('chart', 's') + '</span><div class="rm"><b>Gift box clicks</b><small>View all, Gifts Criteria, ranges and photos: by day, week or month</small></div><div class="re">' + ic('chev', 'chev s') + '</div></a>';
  body += '<div class="gh"><span>Ranges & gifts</span>' + (can() ? '<button type="button" data-gadd>+ Add range</button>' : '') + '</div>';
  const rows = list.map((r) => {
    const x = view(r), warn = problems(r.id, x).length;
    return '<a class="row g-rr' + (x.enabled ? '' : ' off') + '" href="#/gifts/' + r.id + '"><div class="rm"><b>' + esc(amount(x.min, x.max)) + (warn ? ' <i class="g-bang" aria-label="Things to check">!</i>' : '') + (isDirty(r.id) ? ' <i class="g-dot" aria-label="Unsaved"></i>' : '') + '</b>'
      + '<small>' + esc((x.label_en || '').trim() || 'No wording yet') + '</small><small>' + esc(counts(x)) + '</small>'
      + '<span class="g-thumbs">' + x.gifts.slice(0, 6).map((g) => th(g.product && g.product.thumb, 'xs')).join('') + (x.gifts.length > 6 ? '<em>+' + (x.gifts.length - 6) + '</em>' : '') + '</span></div>'
      + '<div class="re">' + (x.enabled ? gpill('ok', 'On') : gpill('mute', 'Off')) + ic('chev', 'chev s') + '</div></a>';
  });
  if (G.drafts.new) rows.push('<a class="row g-rr" href="#/gifts/new"><div class="rm"><b>New range <i class="g-dot"></i></b><small>' + esc(amount(G.drafts.new.min, G.drafts.new.max)) + ' · not saved yet</small></div><div class="re">' + ic('chev', 'chev s') + '</div></a>');
  body += rows.length ? '<div class="list">' + rows.join('') + '</div>' : '<div class="card empty">No ranges yet.' + (can() ? ' <button type="button" class="tbtn" data-gadd>Add the first range</button>' : '') + '</div>';
  const gp = gaps();
  if (gp.length) body += '<p class="hint g-hint">' + gp.map((g) => 'Gap: ' + esc(amount(g.from, g.to)) + ' gives nothing.').join('<br>') + '</p>';
  body += '<p class="hint g-hint">Whole dirhams: "AED 100 – 299" covers AED 100.00 to AED 299.99. If two ranges share an amount, the basket gets the higher range. A sold-out gift is skipped until it is back; the rest of the range is still given.</p>';
  screen(host, 'gifts', 0, head, body, false);
}

function paintRange() {
  const id = cur, r = id === 'new' ? null : rangeById(id);
  if (id !== 'new' && !r) { screen(host, 'gifts', id, top('Free gifts', '', back('#/gifts')), '<div class="card empty">This range is not here any more. It may have been deleted on another device.</div>', false); return; }
  if (id === 'new' && !G.drafts.new) { location.replace('#/gifts'); return; }
  const ro = !can(), x = ro ? view(r) : (id === 'new' ? G.drafts.new : draft(id));
  const head = top(id === 'new' ? 'New range' : esc(amount(x.min, x.max)), '<span data-gsub>' + esc(sub()) + '</span>', back('#/gifts'));
  const dis = ro ? ' disabled' : '';
  let b = '<div class="g-pills">' + pills(id, x) + '</div>';
  // Basket amount and On / Off.
  b += '<section class="card"><h4 class="sh">Basket amount <span class="g-onoff">' + (x.enabled ? 'On' : 'Off') + ' ' + tgl(x.enabled, 'Range on', 'data-gon' + dis) + '</span></h4>'
    + '<div class="g-amt"><label class="inp"><em>AED</em><input type="number" inputmode="numeric" min="0" step="1" data-gf="min" aria-label="From (AED)" value="' + (x.min == null || isNaN(x.min) ? '' : x.min) + '"' + dis + '></label><b>–</b>'
    + '<label class="inp"><input type="number" inputmode="numeric" min="0" step="1" data-gf="max" aria-label="Up to (AED)" placeholder="and above" value="' + (x.max == null ? '' : x.max) + '"' + (ro || x.max == null ? ' disabled' : '') + '></label></div>'
    + '<div class="fld-row"><span>And above<small>No top amount</small></span>' + tgl(x.max == null, 'And above', 'data-gabove' + dis) + '</div></section>';
  // Criteria (internal): three steppers and their total.
  const n = needsOf(x), tot = n ? KEYS.reduce((a, k) => a + (Number(n[k]) || 0), 0) : 0;
  b += '<section class="card"><h4 class="sh">Criteria <span class="muted">= ' + plural(tot, 'gift', 'gifts') + '</span></h4><p class="hint g-hint">Internal only — shoppers never see the types.</p>'
    + KINDS.map((k) => { const v = x.needs ? Number(x.needs[k[0]]) || 0 : 0; return '<div class="fld-row g-need"><span><i class="g-kd k-' + k[0] + '"></i>' + k[1] + '</span><span class="g-step"><button type="button" data-gneed="' + k[0] + '" data-d="-1" aria-label="One fewer ' + k[3] + '"' + (ro || v <= 0 ? ' disabled' : '') + '>−</button><b>' + v + '</b><button type="button" data-gneed="' + k[0] + '" data-d="1" aria-label="One more ' + k[3] + '"' + (ro || v >= maxNeed() ? ' disabled' : '') + '>+</button></span></div>'; }).join('') + '</section>';
  // Messages, then the wording.
  b += '<div data-gmsgs>' + msgs(id, x) + '</div>';
  b += '<section class="card"><h4 class="sh">Wording on the shop</h4>' + (n
    ? '<p class="hint g-hint">Automatic from the counts above' + (r && r.gives && !isDirty(id) ? ': <b>' + esc(r.gives.sentence || '—') + '</b>' : '; written when you save.') + '</p>'
    : '<label class="fld"><span>English</span><span class="inp"><input type="text" maxlength="160" data-gf="label_en" value="' + esc(x.label_en || '') + '"' + dis + '></span></label>'
      + '<label class="fld"><span>Arabic</span><span class="inp"><input type="text" maxlength="160" dir="rtl" lang="ar" data-gf="label_ar" value="' + esc(x.label_ar || '') + '"' + dis + '></span></label>')
    + '<div class="g-dates"><label class="fld"><span>Starts (optional)</span><span class="inp"><input type="date" data-gf="starts_on" value="' + esc(x.starts_on || '') + '"' + dis + '></span></label>'
    + '<label class="fld"><span>Ends (optional)</span><span class="inp"><input type="date" data-gf="ends_on" value="' + esc(x.ends_on || '') + '"' + dis + '></span></label></div></section>';
  // The gifts, one box per type (plus Untagged only when there is one).
  const have = tally(x.gifts), key = String(id), undo = G.removed[key];
  const boxes = KINDS.slice();
  if (x.gifts.some((g) => !kindOf(g.kind)) || (undo && !kindOf(undo.g.kind))) boxes.push(['none', 'Untagged', 'Untagged', 'untagged gift', 'untagged gifts']);
  b += '<div class="gh"><span>Gifts</span>' + (ro ? '' : '<button type="button" data-gpick="" data-role="primary">+ Add gift</button>') + '</div>';
  boxes.forEach((k) => {
    const kk = k[0], need = n ? Number(n[kk]) || 0 : null, got = have[kk];
    const cnt = kk === 'none' || !n ? plural(got, 'unit', 'units') : got + ' of ' + need + (got === need ? ' ✓' : '');
    const cls = kk === 'none' || !n ? '' : got === need ? ' ok' : got < need ? ' short' : ' over';
    const main = x.gifts.map((g, i) => [g, i]).filter(([g]) => (kindOf(g.kind) || 'none') === kk && g.role !== 'backup');
    const bk = x.gifts.map((g, i) => [g, i]).filter(([g]) => (kindOf(g.kind) || 'none') === kk && g.role === 'backup');
    b += '<section class="card g-box k-' + kk + '"><h4 class="sh"><span><i class="g-kd k-' + kk + '"></i>' + k[1] + '</span><span class="g-cnt' + cls + '">' + esc(cnt) + '</span></h4>'
      + (main.length ? main.map(([g, i]) => giftRow(g, i, ro)).join('') : '<p class="g-none">None yet.</p>')
      + (undo && (kindOf(undo.g.kind) || 'none') === kk ? '<div class="row g-undo"><div class="rm"><small>Removed ' + esc(undo.g.product ? undo.g.product.name : 'a gift') + '.</small></div><button type="button" class="tbtn" data-gundo>Undo</button></div>' : '')
      + (ro || kk === 'none' ? '' : '<button type="button" class="g-addk" data-gpick="' + kk + '" data-role="primary">' + ic('plus', 's') + 'Add ' + k[4] + '</button>')
      + (kk === 'none' ? '' : '<div class="g-bk"><p class="g-bkh"><b>Backup</b> Used only if a main gift runs out · most stock first</p>'
        + (bk.length ? bk.map(([g, i]) => giftRow(g, i, ro)).join('') : '<p class="g-none">No backup yet.</p>')
        + (ro ? '' : '<button type="button" class="g-addk bk" data-gpick="' + kk + '" data-role="backup">' + ic('plus', 's') + 'Add backup</button>') + '</div>')
      + '</section>';
  });
  const counted = have.mask + have.mini + have.full, units = counted + have.none;
  b += '<p class="g-tot' + (n ? (problems(id, x).some((m) => / chosen|untagged/.test(m[1])) ? ' short' : ' ok') : '') + '">' + (n ? 'Total: ' + counted + ' of ' + plural(tot, 'gift', 'gifts') : 'Total: ' + plural(units, 'gift', 'gifts') + ' · No criteria set') + '</p>';
  // What a shopper gets now: the shop's own walk, for the range as saved.
  if (r) {
    const gv = r.gives;
    b += '<section class="card"><h4 class="sh">A shopper gets now <span class="muted">' + (isDirty(id) ? 'as saved' : '') + '</span></h4>'
      + (!r.enabled ? '<p class="g-none">Off: this range gives nothing.</p>' : !gv || !gv.lines.length ? '<p class="g-none">Nothing: no gift here can be given right now.</p>'
        : gv.lines.map((l) => '<div class="kv"><span>' + esc(l.name) + (l.backup ? ' <span class="tag">Backup</span>' : '') + '</span><span>× ' + l.quantity + '</span></div>').join('') + '<div class="kv tot"><span>' + esc(gv.sentence || 'Gifts') + '</span><span>' + plural(gv.units, 'gift', 'gifts') + '</span></div>')
      + '</section>';
  }
  if (!ro) {
    b += id === 'new'
      ? '<div class="btns g-acts"><button type="button" class="btn ghost" data-gdiscard>Remove this new range</button></div>'
      : '<div class="btns g-acts"><button type="button" class="btn sec" data-gdup>Duplicate</button><button type="button" class="btn sec danger" data-gdel>Delete…</button></div>';
    b += '<div class="g-save" data-gsave>' + saveBar(id) + '</div>';
  }
  screen(host, 'gifts', id === 'new' ? -1 : id, head, b, false);
}

function msgs(id, x) {
  const m = problems(id, x).map((p) => '<li class="' + p[0] + '">' + esc(p[1]) + '</li>');
  if (G.err[id]) m.push('<li class="err" role="alert">The server said: ' + esc(G.err[id]) + '</li>');
  return m.length ? '<ul class="card g-msgs" aria-live="polite">' + m.join('') + '</ul>' : '';
}

function saveBar(id) {
  const dirty = isDirty(id), err = problems(id, id === 'new' ? G.drafts.new : draft(id)).some((p) => p[0] === 'err');
  return '<span>' + (dirty ? 'Unsaved changes' : 'Saved') + '</span>'
    + (dirty && id !== 'new' ? '<button type="button" class="btn ghost" data-gdiscard>Discard</button>' : '')
    + '<button type="button" class="btn pri" data-gsavebtn' + (!dirty || G.busy || err ? ' disabled' : '') + '>' + (G.busy ? 'Saving…' : 'Save range') + '</button>';
}

function giftRow(g, i, ro) {
  const p = g.product, name = p ? p.name : 'Product #' + g.product_id, bk = g.role === 'backup', s = stockOf(p, bk ? 1 : g.quantity);
  const k = kindRow(kindOf(g.kind));
  return '<div class="row g-gi ' + s[0] + '">' + th(p && p.thumb, 'sm')
    + '<button type="button" class="rm g-gn" data-gedit="' + i + '"' + (ro ? ' disabled' : '') + '>' + (p && p.brand ? '<small>' + esc(p.brand) + '</small>' : '') + '<b class="cl2">' + esc(name) + '</b>'
    + '<span class="g-meta">' + spill(s[0] === 'out' ? [s[0], s[1] + ' — skipped'] : s) + (k ? '<span class="tag">' + k[2] + (bk ? ' · Backup' : '') + '</span>' : '') + '</span></button>'
    + (bk ? '<span class="g-asn">As needed</span>' : '<span class="g-step sm"><button type="button" data-gq="' + i + '" data-d="-1" aria-label="One fewer ' + esc(name) + '"' + (ro || g.quantity <= 1 ? ' disabled' : '') + '>−</button><b>' + g.quantity + '</b><button type="button" data-gq="' + i + '" data-d="1" aria-label="One more ' + esc(name) + '"' + (ro || g.quantity >= maxQty() ? ' disabled' : '') + '>+</button></span>')
    + '</div>';
}

/* Light repaint while typing: the messages and the Save bar only, so the field keeps its focus. */
function lightPaint() {
  const h = onScreen('gifts');
  if (!h || cur === 0) return;
  const x = cur === 'new' ? G.drafts.new : G.drafts[cur];
  if (!x) return;
  const m = $('[data-gmsgs]', h), s = $('[data-gsave]', h);
  if (m) paint(m, msgs(cur, x));
  if (s) paint(s, saveBar(cur));
}

/* ------------------------------------------------------------ actions */

function addRange(from, quiet) {
  if (!G.drafts.new) {
    const base = from ? clone(from) : { label_en: '', label_ar: '', gifts: [], starts_on: null, ends_on: null, needs: null };
    G.drafts.new = { min: G.d ? nextFree() : 0, max: null, label_en: base.label_en, label_ar: base.label_ar, enabled: false, starts_on: base.starts_on, ends_on: base.ends_on, needs: base.needs ? clone(base.needs) : null, gifts: base.gifts, rev: G.d ? G.d.revision : '' };
  }
  if (!quiet) location.hash = '#/gifts/new';
}

function body(x) {
  const n = needsOf(x);
  return {
    min: x.min, max: x.max, label_en: (x.label_en || '').trim(), label_ar: (x.label_ar || '').trim(), enabled: !!x.enabled, starts_on: x.starts_on || null, ends_on: x.ends_on || null,
    gifts: x.gifts.map((g) => Object.assign({ product_id: g.product_id }, g.variant_id ? { variant_id: g.variant_id } : {}, { quantity: g.quantity, kind: kindOf(g.kind), role: g.role === 'backup' ? 'backup' : 'primary' })),
    needs: n ? { mask: Number(n.mask) || 0, mini: Number(n.mini) || 0, full: Number(n.full) || 0 } : null,
    revision: x.rev,
  };
}

async function save(btn) {
  const id = cur, x = id === 'new' ? G.drafts.new : G.drafts[id];
  if (!x || G.busy) return;
  const name = id === 'new' ? 'the new range' : amount(x.min, x.max), before = G.d.ranges.map((r) => r.id);
  G.busy = true; lightPaint();
  const r = await busy(btn, () => api('POST', id === 'new' ? 'gifts/ranges' : 'gifts/ranges/' + id, body(x))).finally(() => { G.busy = false; });
  if (r.status === 409) { conflict(); lightPaint(); return; }
  if (!r.ok) { G.err[id] = r.data.message || 'Not saved.'; toast('Not saved: ' + G.err[id], true); redraw(); return; }
  delete G.drafts[id]; delete G.err[id]; delete G.removed[String(id)];
  take(r.data, x.rev);
  toast('Saved ' + name + '.');
  if (id === 'new') { const made = G.d.ranges.find((rr) => before.indexOf(rr.id) === -1); location.replace(made ? '#/gifts/' + made.id : '#/gifts'); return; }
  redraw();
}

/* The edits that would be lost: listed so he can redo them after Refresh. */
function edits() {
  const out = [];
  dirtyIds().forEach((k) => {
    const x = G.drafts[k], r = k === 'new' ? null : rangeById(+k), o = r ? origOf(r) : null, at = k === 'new' ? 'New range ' + amount(x.min, x.max) : amount(o.min, o.max);
    if (!o) { out.push(at + ': not saved yet' + (x.gifts.length ? ', ' + plural(x.gifts.length, 'gift', 'gifts') : '')); return; }
    if (x.min !== o.min || x.max !== o.max) out.push(at + ': amount → ' + amount(x.min, x.max));
    if (x.enabled !== o.enabled) out.push(at + ': switched ' + (x.enabled ? 'on' : 'off'));
    if ((x.label_en || '') !== (o.label_en || '') || (x.label_ar || '') !== (o.label_ar || '')) out.push(at + ': wording changed');
    if ((x.starts_on || '') !== (o.starts_on || '') || (x.ends_on || '') !== (o.ends_on || '')) out.push(at + ': dates changed');
    if (JSON.stringify(needsOf(x)) !== JSON.stringify(needsOf(o))) out.push(at + ': criteria changed');
    const ok = {}, nk = {};
    o.gifts.forEach((g) => { ok[gk(g)] = g; });
    x.gifts.forEach((g) => { nk[gk(g)] = g; });
    const nm = (g) => (g.product ? g.product.name : 'Product #' + g.product_id);
    x.gifts.forEach((g) => {
      const a = ok[gk(g)];
      if (!a) out.push(at + ': added ' + nm(g));
      else if (a.quantity !== g.quantity || kindOf(a.kind) !== kindOf(g.kind) || a.role !== g.role) out.push(at + ': ' + nm(g) + ' → ' + g.quantity + ' × ' + ((kindRow(kindOf(g.kind)) || [0, 0, 'Untagged'])[2]) + (g.role === 'backup' ? ' · Backup' : ''));
    });
    o.gifts.forEach((g) => { if (!nk[gk(g)]) out.push(at + ': removed ' + nm(g)); });
  });
  return out;
}

function conflict() {
  const list = edits();
  sheet('Changed on another device', '<div class="g-conf"><p class="hint">These gifts were changed on another device. Refresh to see the latest.</p>'
    + (list.length ? '<p class="g-bkh"><b>Your unsaved edits</b> Redo them after the refresh:</p><ul class="g-msgs">' + list.map((t) => '<li>' + esc(t) + '</li>').join('') + '</ul>' : '')
    + '<div class="btns"><button type="button" class="btn sec" data-close>Not now</button><button type="button" class="btn pri" data-grefresh>' + ic('refresh', 's') + 'Refresh</button></div></div>', (s, close) => {
    $('[data-grefresh]', s).addEventListener('click', async (e) => {
      await busy(e.currentTarget, async () => {
        G.drafts = {}; G.removed = {}; G.err = {};
        const ok = await fetchGifts(false, true);
        if (ok) { toast('Up to date. Your edits were not saved; redo them here.'); close(); }
        if (cur === 'new') location.replace('#/gifts');
      });
    });
  });
}

function pinSheet(to, row) {
  sheet('Enter your app PIN', '<p class="hint">' + esc('Confirm switching free gifts to ' + row[1] + '.') + '</p>'
    + '<form class="g-pin" data-gpin><input type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="8" aria-label="App PIN" autofocus data-gpinv>'
    + '<p class="hint g-pinmsg" aria-live="polite" data-gpinm></p>'
    + '<div class="btns"><button type="button" class="btn sec" data-close>Cancel</button><button type="submit" class="btn ' + (to === 'off' ? 'sec danger' : 'pri') + '">' + ic('lock', 's') + 'Confirm</button></div></form>', (s, close) => {
    const f = $('[data-gpin]', s), v = $('[data-gpinv]', s), msg = $('[data-gpinm]', s);
    v.focus({ preventScroll: true });
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      const pin = v.value.replace(/\D/g, '');
      if (pin.length < 6) { msg.textContent = 'Your PIN is 6 to 8 digits.'; return; }
      busy(f.querySelector('[type=submit]'), async () => {
        const r = await api('POST', 'gifts/mode', { mode: to, pin });
        v.value = '';
        if (!r.ok) {
          if (r.data && r.data.pin_refused && r.data.code === 'bad_pin') { msg.textContent = r.data.message || 'Wrong PIN.'; v.focus(); return; }
          close(); toast((r.data && r.data.message) || 'Mode not changed.', true); return;
        }
        close(); take(r.data); redraw();
        toast(to === 'live' ? 'Free gifts are Live: every shopper sees them.' : to === 'testing' ? 'Free gifts are in Testing: only the IPs on the list see them.' : 'Free gifts are Off: nobody sees them.');
      });
    });
  });
}

function confirmSheet(title, text, yes, cls, run) {
  sheet(title, '<p class="hint">' + esc(text) + '</p><div class="btns"><button type="button" class="btn sec" data-close>No</button><button type="button" class="btn ' + (cls || 'pri') + '" data-gyes>' + esc(yes) + '</button></div>', (s, close) => {
    $('[data-gyes]', s).addEventListener('click', (e) => busy(e.currentTarget, async () => { await run(); close(); }));
  });
}

function giftSheet(x, i) {
  const g = x.gifts[i];
  if (!g) return;
  const name = g.product ? g.product.name : 'Product #' + g.product_id, k = kindOf(g.kind);
  sheet(name, '<p class="g-bkh"><b>Type</b> Internal only</p><div class="chips g-chips">' + KINDS.map((r) => '<button type="button" data-gk="' + r[0] + '"' + (k === r[0] ? ' class="on"' : '') + '>' + r[2] + '</button>').join('') + '</div>'
    + (k ? '<p class="g-bkh"><b>Main or backup</b> A backup is used only if a main gift runs out</p><div class="chips g-chips">' + [['primary', 'Main'], ['backup', 'Backup']].map((r) => '<button type="button" data-gr="' + r[0] + '"' + ((g.role === 'backup' ? 'backup' : 'primary') === r[0] ? ' class="on"' : '') + '>' + r[1] + '</button>').join('') + '</div>' : '')
    + '<button type="button" class="btn sec wide danger g-rm" data-grm>' + ic('trash', 's') + 'Remove from this range</button>', (s, close) => {
    s.addEventListener('click', (e) => {
      const kb = e.target.closest('[data-gk]'), rb = e.target.closest('[data-gr]'), rm = e.target.closest('[data-grm]');
      if (kb) { g.kind = kb.getAttribute('data-gk'); if (g.product) g.product.gift_kind = g.kind; }
      else if (rb) g.role = rb.getAttribute('data-gr');
      else if (rm) { G.removed[String(cur)] = { g: x.gifts.splice(i, 1)[0], at: i }; }
      else return;
      close(); redraw();
    });
  });
}

/* --------------------------------------------------------------- picker */

/* The console's picker, phone sized: instant search (300 ms, 2 letters or
   more, a slower earlier answer never overwrites a newer one), New picks go
   to, Type and Show filters, "Products used in gifts", and Choose variation
   for a product with shades or sizes -- each variation its own gift line. */
function picker(x, box, role) {
  const P = { q: '', filter: 'all', kind: 'all', box: kindOf(box), role: role === 'backup' ? 'backup' : 'primary', rows: null, initial: {}, seq: 0, loading: false, err: '', choosing: null, asking: null, added: 0 };
  const boxRow = kindRow(P.box);
  const title = 'Add ' + (boxRow ? (P.role === 'backup' ? 'backup ' : '') + boxRow[4] : 'gifts');
  const chips = (attr, opts, on) => '<div class="chips">' + opts.map((o) => '<button type="button" data-' + attr + '="' + (o[0] === null ? '' : o[0]) + '"' + (on === o[0] ? ' class="on"' : '') + '>' + o[1] + '</button>').join('') + '</div>';
  sheet(title, '<div class="g-pk"><label class="search">' + ic('search', 's') + '<input type="search" placeholder="Search by name or SKU" aria-label="Search products" autocomplete="off" data-pkq></label>'
    + '<div data-pkf></div><p class="g-pkst" role="status" aria-live="polite" data-pkst></p><div data-pkr aria-live="polite"></div>'
    + '<div class="g-pkfoot"><span data-pkt></span><button type="button" class="btn pri" data-close>Done</button></div></div>', (s) => {
    const res = $('[data-pkr]', s), st = $('[data-pkst]', s), filt = $('[data-pkf]', s), tot = $('[data-pkt]', s);
    const paintFilters = () => paint(filt, '<div class="g-fl"><b>New picks go to</b>' + chips('pkin', KINDS.map((k) => [k[0], k[2]]).concat([[null, 'Ask me']]), P.box) + '</div>'
      + '<div class="g-fl"><b>Type</b>' + chips('pkk', [['all', 'All'], ['mask', 'Masks'], ['mini', 'Minis'], ['full', 'Full size'], ['none', 'Untagged']], P.kind) + '</div>'
      + '<div class="g-fl"><b>Show</b>' + chips('pks', [['all', 'All'], ['gift', 'Gift-only'], ['instock', 'In stock'], ['used', '★ Products used in gifts']], P.filter) + '</div>');
    const key = () => P.filter + '|' + P.kind;
    async function fetchRows(q) {
      const mine = ++P.seq;
      P.loading = true; P.err = ''; paintRes();
      const r = await api('GET', 'gifts/products?q=' + encodeURIComponent(q) + '&filter=' + encodeURIComponent(P.filter) + '&kind=' + encodeURIComponent(P.kind));
      if (mine !== P.seq) return;
      P.loading = false;
      if (!r.ok) { P.err = r.data.message || 'Search failed.'; paintRes(); return; }
      P.rows = r.data.products;
      if (q === '') P.initial[key()] = P.rows;
      paintRes();
    }
    const later = debounce((q) => fetchRows(q), 300);
    function typed(q) {
      P.q = q; P.choosing = null; P.asking = null;
      const t = q.trim();
      if (!t) { P.seq++; P.loading = false; P.err = ''; if (P.initial[key()]) { P.rows = P.initial[key()]; paintRes(); } else fetchRows(''); return; }
      if (t.length < 2) { P.seq++; P.loading = false; P.rows = null; P.err = 'Type at least 2 letters to search.'; paintRes(); return; }
      P.loading = true; P.err = ''; paintRes();
      later(t);
    }
    const refetch = () => { const t = P.q.trim(); if (t.length >= 2) fetchRows(t); else if (P.initial[key()]) { P.rows = P.initial[key()]; paintRes(); } else fetchRows(''); };
    function pick(pr, kind, v) {
      if (kind && !pr.gift_kind) pr.gift_kind = kind;
      const g = { product_id: pr.id, quantity: 1, product: v || pr, kind: kind, role: kind && P.role === 'backup' ? 'backup' : 'primary' };
      if (v) g.variant_id = v.variant_id;
      x.gifts.push(g);
      P.added++; P.asking = null;
      st.textContent = 'Added "' + (v || pr).name + '" to ' + (kind ? kindRow(kind)[1] : 'Untagged') + (kind && P.role === 'backup' ? ' · Backup' : '') + '.';
      paintRes(); redraw();
    }
    function ask(k) {
      return '<div class="g-ask"><span>Which box?</span>' + KINDS.map((r) => '<button type="button" data-pkask="' + r[0] + '" data-key="' + k + '">' + r[2] + '</button>').join('') + '<button type="button" data-pkask="" data-key="' + k + '">Untagged</button></div>';
    }
    function paintRes() {
      const t = tally(x.gifts), n = needsOf(x);
      tot.textContent = KINDS.map((k) => k[2] + ' ' + t[k[0]] + (n ? '/' + (Number(n[k[0]]) || 0) : '')).join(' · ') + (t.none ? ' · Untagged ' + t.none : '');
      if (!P.err && !P.loading && P.rows) st.textContent = P.q.trim().length >= 2 ? plural(P.rows.length, 'match', 'matches') + (P.rows.length >= 20 && P.filter !== 'used' ? ' (first 20; keep typing)' : '') : st.textContent;
      if (P.loading) st.textContent = 'Searching…';
      if (P.err) { paint(res, '<p class="g-none">' + esc(P.err) + '</p>'); return; }
      if (!P.rows) { paint(res, ''); return; }
      // Choose variation: the product's own list, in place of the results.
      const ch = P.choosing && P.rows.find((p) => p.id === P.choosing);
      if (ch) {
        paint(res, '<button type="button" class="lnk" data-pkback>' + ic('back', 's') + 'All results</button><div class="g-vh">' + th(ch.thumb, 'sm') + '<div class="rm"><b class="cl2">' + esc(ch.name) + '</b><small>Choose variation — each one you pick is its own gift</small></div></div>'
          + '<div class="list">' + (ch.variants || []).map((v) => {
            const vg = x.gifts.find((g) => g.product_id === ch.id && g.variant_id === v.variant_id), vin = vg ? kindRow(kindOf(vg.kind)) : null;
            return '<button type="button" class="row g-res" data-pkv="' + v.variant_id + '" aria-pressed="' + !!vg + '">' + th(v.thumb, 'sm') + '<div class="rm"><b>' + esc(v.variant_label || v.name) + '</b><span class="g-meta"><span class="muted">' + esc(v.price) + '</span>' + spill(stockOf(v)) + '</span></div><span class="g-st' + (vg ? ' on' : '') + '">' + (vg ? '✓ Added' + (vin ? ' · ' + vin[2] : '') : '+ Add') + '</span></button>'
              + (P.asking === 'v' + v.variant_id && !vg ? ask('v' + v.variant_id) : '');
          }).join('') + '</div>');
        return;
      }
      if (!P.rows.length) { paint(res, '<p class="g-none">' + (P.filter === 'used' && P.q.trim().length < 2 ? 'No product has been used as a gift yet.' : 'No product matches' + (P.filter !== 'all' || P.kind !== 'all' ? ' this filter' : '') + '.') + '</p>'); return; }
      paint(res, '<div class="list">' + P.rows.map((pr) => {
        const isVar = pr.type === 'variable', vars = pr.variants || [], chosen = isVar ? x.gifts.filter((g) => g.product_id === pr.id && g.variant_id) : [];
        const mine = isVar ? null : x.gifts.find((g) => g.product_id === pr.id && !g.variant_id), taken = isVar ? chosen.length > 0 : !!mine, bad = isVar && !vars.length;
        const s = isVar ? ['info', plural(vars.length, 'variation', 'variations')] : stockOf(pr), into = mine ? kindRow(kindOf(mine.kind)) : null, kp = kindRow(kindOf(pr.gift_kind));
        return '<button type="button" class="row g-res" data-pkp="' + pr.id + '" aria-pressed="' + taken + '"' + (bad ? ' disabled' : '') + '>' + th(pr.thumb, 'sm') + '<div class="rm">' + (pr.brand ? '<small>' + esc(pr.brand) + '</small>' : '') + '<b class="cl2">' + esc(pr.name) + '</b>'
          + '<span class="g-meta">' + (pr.gift_only ? gpill('info', 'Gift only') : '<span class="muted">' + esc(pr.price) + '</span>') + spill(s) + (kp ? '<span class="tag">' + kp[2] + '</span>' : '') + '</span></div>'
          + '<span class="g-st' + (taken ? ' on' : '') + '">' + (bad ? 'No variations' : isVar ? (taken ? '✓ ' + chosen.length + ' · ' : '') + 'Choose variation ›' : taken ? '✓ Added' + (into ? ' · ' + into[2] : '') : '+ Add') + '</span></button>'
          + (P.asking === pr.id && !taken ? ask(String(pr.id)) : '');
      }).join('') + '</div>');
    }
    s.addEventListener('click', (e) => {
      const t = e.target;
      const fi = t.closest('[data-pkin]'), fk = t.closest('[data-pkk]'), fs = t.closest('[data-pks]');
      if (fi) { P.box = kindOf(fi.getAttribute('data-pkin')); P.asking = null; paintFilters(); paintRes(); return; }
      if (fk) { P.kind = fk.getAttribute('data-pkk'); paintFilters(); refetch(); return; }
      if (fs) { const f = fs.getAttribute('data-pks'); P.filter = P.filter === f && f === 'used' ? 'all' : f; paintFilters(); refetch(); return; }
      if (t.closest('[data-pkback]')) { P.choosing = null; P.asking = null; paintRes(); return; }
      const a = t.closest('[data-pkask]');
      if (a) {
        const k = a.getAttribute('data-key'), kind = kindOf(a.getAttribute('data-pkask'));
        if (k.charAt(0) === 'v') { const pr = P.rows.find((p) => p.id === P.choosing), v = pr && (pr.variants || []).find((vv) => 'v' + vv.variant_id === k); if (pr && v) pick(pr, kind, v); }
        else { const pr = P.rows.find((p) => String(p.id) === k); if (pr) pick(pr, kind); }
        return;
      }
      const vb = t.closest('[data-pkv]');
      if (vb) {
        const pr = P.rows.find((p) => p.id === P.choosing), v = pr && (pr.variants || []).find((vv) => vv.variant_id === +vb.getAttribute('data-pkv'));
        if (!v) return;
        const i = x.gifts.findIndex((g) => g.product_id === pr.id && g.variant_id === v.variant_id);
        if (i >= 0) { x.gifts.splice(i, 1); P.added--; paintRes(); redraw(); return; }
        const kind = kindOf(pr.gift_kind) || P.box;
        if (!kind) { P.asking = P.asking === 'v' + v.variant_id ? null : 'v' + v.variant_id; paintRes(); return; }
        pick(pr, kind, v);
        return;
      }
      const pb = t.closest('[data-pkp]');
      if (pb) {
        const pr = P.rows.find((p) => p.id === +pb.getAttribute('data-pkp'));
        if (!pr) return;
        if (pr.type === 'variable') { P.choosing = pr.id; P.asking = null; paintRes(); return; }
        const i = x.gifts.findIndex((g) => g.product_id === pr.id && !g.variant_id);
        if (i >= 0) { x.gifts.splice(i, 1); P.added--; P.asking = null; paintRes(); redraw(); return; }
        const kind = kindOf(pr.gift_kind) || P.box;
        if (!kind) { P.asking = P.asking === pr.id ? null : pr.id; paintRes(); return; }
        pick(pr, kind);
      }
    });
    const qi = $('[data-pkq]', s);
    qi.addEventListener('input', () => typed(qi.value));
    qi.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    paintFilters();
    fetchRows('');
  }, 'g-sheet');
}

/* ---------------------------------------------------------------- events */

export async function giftsClick(e) {
  const t = e.target;
  const m = t.closest('[data-gmode]');
  if (m) {
    const to = m.getAttribute('data-gmode'), row = MODES.find((r) => r[0] === to);
    if (!G.d || to === G.d.mode || !row) return true;
    // Locked (2.60.615): Yes / No first, then the app PIN once. The server
    // checks the PIN before it writes anything; this is only the way in.
    confirmSheet('Switch to ' + row[1] + '?', row[2] + ' Switch free gifts to ' + row[1] + '?', 'Yes, ' + row[1], to === 'off' ? 'sec danger' : 'pri', async () => {
      pinSheet(to, row); // sheet() closes this Yes / No sheet first
    });
    return true;
  }
  if (t.closest('[data-gadd]')) { addRange(null); return true; }
  if (cur === 0 || !G.d) return false;
  const x = cur === 'new' ? G.drafts.new : (can() ? draft(cur) : null);
  if (!x) return false;
  if (t.closest('[data-gon]')) { x.enabled = !x.enabled; redraw(); return true; }
  if (t.closest('[data-gabove]')) { if (x.max == null) x.max = (typeof x.min === 'number' && !isNaN(x.min) ? x.min : 0) + 199; else x.max = null; redraw(); return true; }
  const nd = t.closest('[data-gneed]');
  if (nd) { const k = nd.getAttribute('data-gneed'); if (!x.needs) x.needs = { mask: 0, mini: 0, full: 0 }; x.needs[k] = Math.max(0, Math.min(maxNeed(), (Number(x.needs[k]) || 0) + (+nd.getAttribute('data-d')))); redraw(); return true; }
  const q = t.closest('[data-gq]');
  if (q) { const g = x.gifts[+q.getAttribute('data-gq')]; if (g) { g.quantity = Math.max(1, Math.min(maxQty(), g.quantity + (+q.getAttribute('data-d')))); redraw(); } return true; }
  const ed = t.closest('[data-gedit]');
  if (ed) { giftSheet(x, +ed.getAttribute('data-gedit')); return true; }
  if (t.closest('[data-gundo]')) { const u = G.removed[String(cur)]; if (u && !x.gifts.some((g) => gk(g) === gk(u.g))) x.gifts.splice(Math.min(u.at, x.gifts.length), 0, u.g); delete G.removed[String(cur)]; redraw(); return true; }
  const pk = t.closest('[data-gpick]');
  if (pk) { delete G.removed[String(cur)]; picker(x, pk.getAttribute('data-gpick'), pk.getAttribute('data-role')); return true; }
  const sv = t.closest('[data-gsavebtn]');
  if (sv) { await save(sv); return true; }
  if (t.closest('[data-gdiscard]')) {
    const was = cur;
    delete G.drafts[was]; delete G.err[was]; delete G.removed[String(was)];
    toast(was === 'new' ? 'Removed the new range.' : 'Changes discarded.');
    if (was === 'new') location.replace('#/gifts'); else redraw();
    return true;
  }
  if (t.closest('[data-gdup]')) { if (G.drafts.new) { toast('Save or remove the new range first.', true); return true; } addRange(x); return true; }
  if (t.closest('[data-gdel]')) {
    const r = rangeById(cur);
    if (!r) return true;
    confirmSheet('Delete this range?', 'Delete ' + amount(r.min, r.max) + ' and its ' + plural(r.gifts.length, 'gift', 'gifts') + '? The shop stops giving them at once.', 'Delete range', 'sec danger', async () => {
      const res = await api('POST', 'gifts/ranges/' + r.id + '/delete', { revision: (G.drafts[r.id] && G.drafts[r.id].rev) || G.d.revision });
      if (res.status === 409) { conflict(); return; }
      if (!res.ok) { toast(res.data.message || 'Not deleted.', true); return; }
      const was = (G.drafts[r.id] && G.drafts[r.id].rev) || G.d.revision;
      delete G.drafts[r.id]; delete G.removed[String(r.id)]; delete G.err[r.id];
      take(res.data, was);
      toast('Deleted ' + amount(r.min, r.max) + '.');
      location.replace('#/gifts');
    });
    return true;
  }
  return false;
}

/* Typing: numbers, wording and dates go into the draft; only the messages and the Save bar repaint. */
document.addEventListener('input', (e) => {
  const f = e.target.closest && e.target.closest('[data-gf]');
  if (!f || !onScreen('gifts') || cur === 0 || !can()) return;
  const x = cur === 'new' ? G.drafts.new : draft(cur), k = f.getAttribute('data-gf');
  if (!x) return;
  if (k === 'min' || k === 'max') x[k] = f.value === '' ? (k === 'max' ? null : NaN) : Math.floor(Number(f.value));
  else if (k === 'starts_on' || k === 'ends_on') x[k] = f.value || null;
  else x[k] = f.value;
  lightPaint();
});
/* Leaving a field redraws the parts that read it (the title, the pills). */
document.addEventListener('change', (e) => { if (e.target.closest && e.target.closest('[data-gf]') && onScreen('gifts') && cur !== 0) redraw(); });

/* ------------------------------------------------- gift box clicks (2.60.628)
   The console's Free gifts -> Clicks, for the phone: GET gifts/clicks reads one
   small table of daily totals. One request per change of period; nothing
   polls, nothing measures. A late answer repaints only if this screen is still
   the one on show. Bars are SVG: the owner app's CSP refuses inline styles in
   markup, and an SVG's sizes are attributes, not styles. */
/* (2.60.629) Its own screen, #/clicks: from the home screen's rows, from More
   and from Free gifts (?from=gifts, so Back goes there). */
let ch = null, chBack = '#/';
export async function renderClicks(v, q) {
  ch = v;
  v.className = 'view single';
  chBack = q === 'from=gifts' ? '#/gifts' : (q === 'from=more' ? '#/more' : '#/');
  paintClicks();
  if (!GC.d || !isFresh('clicks')) await loadClicks();
}
export function clicksClick(e) {
  const t = e.target, p = t.closest('[data-gcp]'), b = t.closest('[data-gcb]');
  if (p) { setPeriod(p.getAttribute('data-gcp')); return true; }
  if (b) { GC.by = b.getAttribute('data-gcb'); loadClicks(); paintClicks(); return true; }
  return false;
}
const GC = { p: '7d', by: 'day', from: '', to: '', today: '', d: null, busy: false, seq: 0, err: '', stale: false };
const GC_ORDER = [2, 0, 3, 4, 1];
const GC_PERIODS = [['today', 'Today'], ['7d', '7 days'], ['30d', '30 days'], ['month', 'This month'], ['last', 'Last month'], ['90d', '90 days'], ['year', 'This year']];
const gcDay = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
function gcRange(p, today) {
  const t = new Date(today + 'T12:00:00'), y = t.getFullYear(), m = t.getMonth();
  const back = (n) => { const d = new Date(t); d.setDate(d.getDate() - n); return gcDay(d); };
  if (p === 'today') return [today, today];
  if (p === '30d') return [back(29), today];
  if (p === '90d') return [back(89), today];
  if (p === 'month') return [gcDay(new Date(y, m, 1)), today];
  if (p === 'last') return [gcDay(new Date(y, m - 1, 1)), gcDay(new Date(y, m, 0))];
  if (p === 'year') return [gcDay(new Date(y, 0, 1)), today];
  return [back(6), today];
}
function setPeriod(p) {
  GC.p = p;
  if (GC.today) { const r = gcRange(p, GC.today); GC.from = r[0]; GC.to = r[1]; }
  if (p === 'today') GC.by = 'day';
  if (p === 'year' && GC.by === 'day') GC.by = 'month';
  loadClicks();
  paintClicks();
}
export async function loadClicks() {
  const seq = ++GC.seq;
  GC.busy = true; GC.err = ''; GC.stale = false;
  const r = await api('GET', 'gifts/clicks?' + (GC.from && GC.to ? 'from=' + GC.from + '&to=' + GC.to + '&' : '') + 'by=' + GC.by);
  if (seq !== GC.seq) return;
  GC.busy = false;
  if (r.ok) { GC.d = r.data; GC.from = r.data.from; GC.to = r.data.to; GC.today = r.data.today; landed('clicks'); }
  else GC.err = (r.data && r.data.message) || 'The clicks could not be loaded.';
  if (ch && onScreen('clicks')) paintClicks();
  return r.ok;
}
function paintClicks() {
  const d = GC.d, f = (n) => Number(n || 0).toLocaleString('en-US');
  const head = top('Gift box clicks', '<span>' + (d ? esc(d.from + ' – ' + d.to) : 'Loading…') + (GC.busy && d ? ' · updating…' : '') + '</span>', back(chBack));
  let b = '<div class="chips" role="group" aria-label="Period">' + GC_PERIODS.map(([k, l]) => '<button type="button" data-gcp="' + k + '"' + (GC.p === k ? ' class="on"' : '') + '>' + l + '</button>').join('') + '</div>'
    + '<div class="gc-by"><div class="seg" role="group" aria-label="Group by">' + [['day', 'Day'], ['week', 'Week'], ['month', 'Month']].map(([k, l]) => '<button type="button" data-gcb="' + k + '"' + (GC.by === k ? ' class="on"' : '') + '>' + l + '</button>').join('') + '</div></div>';
  if (GC.err) b += errorBox(GC.err);
  if (!d) { screen(ch, 'clicks', 0, head, b + '<div class="tiles">' + times(4, () => '<div class="card">' + ln(70, 'k-sm') + ln(45, 'k-tile') + '</div>') + '</div>', true); return; }
  if (!d.ready) { screen(ch, 'clicks', 0, head, b + '<div class="card empty">Counting starts once update 2.60.627 has finished installing.</div>', false); return; }
  b += '<div class="tiles gc-tiles">' + GC_ORDER.map((a) => '<div class="card gc-c' + a + '"><small>' + esc(d.actions[a]) + '</small><b>' + f(d.totals[a]) + '</b><small>' + esc(plural(d.carts[a], 'cart', 'carts')) + '</small></div>').join('') + '</div>';
  const o = d.orders;
  if (o) {
    const pc = (a, n) => (n ? Math.round(a * 1000 / n) / 10 + '%' : '–');
    b += '<section class="card"><h4 class="sh">Did it lead to orders?</h4><div class="gc-conv"><div><b>' + pc(o.used_ordered, o.used) + '</b><small>' + f(o.used_ordered) + ' of ' + esc(plural(o.used, 'cart', 'carts')) + ' that used the gift box ordered</small></div>'
      + '<div><b>' + pc(o.others_ordered, o.others) + '</b><small>' + f(o.others_ordered) + ' of ' + esc(plural(o.others, 'cart', 'carts')) + ' that did not</small></div></div><p class="hint">People only, bots left out.</p></section>';
  }
  const max = Math.max(1, ...d.buckets.map((x) => x.total)), every = Math.max(1, Math.ceil(d.buckets.length / 6));
  b += '<section class="card"><h4 class="sh">By ' + esc(d.by) + ' <span class="muted">' + f(d.total) + ' taps</span></h4>'
    + '<svg class="gc-chart" viewBox="0 0 ' + (d.buckets.length * 10) + ' 100" preserveAspectRatio="none" role="img" aria-label="Taps per ' + esc(d.by) + '">' + d.buckets.map((x, i) => {
      let y = 100;
      return GC_ORDER.map((a) => { if (!x.clicks[a]) return ''; const h = x.clicks[a] * 100 / max; y -= h; return '<rect class="gc-c' + a + '" x="' + (i * 10 + 1) + '" y="' + y.toFixed(2) + '" width="8" height="' + h.toFixed(2) + '"/>'; }).join('');
    }).join('') + '</svg>'
    + '<div class="gc-x">' + d.buckets.map((x, i) => '<span>' + (i % every === 0 ? esc(x.label.replace(/^\w{3} /, '')) : '') + '</span>').join('') + '</div>'
    + '<div class="gc-key">' + GC_ORDER.map((a) => '<span class="gc-c' + a + '"><i></i>' + esc(d.actions[a]) + '</span>').join('') + '</div></section>';
  // Newest first: each period's taps, with its own split bar.
  b += '<div class="gh"><span>' + (d.by === 'day' ? 'Each day' : d.by === 'week' ? 'Each week' : 'Each month') + '</span></div><div class="list">'
    + d.buckets.slice().reverse().map((x) => '<div class="row gc-row"><div class="rm"><b>' + esc(x.label) + '</b><small>' + GC_ORDER.filter((a) => x.clicks[a]).map((a) => esc(d.actions[a]) + ' ' + f(x.clicks[a])).join(' · ') + (x.total ? '' : 'No taps') + '</small>'
      + (x.total ? (() => { let at = 0; return '<svg class="gc-split" viewBox="0 0 ' + x.total + ' 1" preserveAspectRatio="none" aria-hidden="true">' + GC_ORDER.map((a) => { if (!x.clicks[a]) return ''; const r = '<rect class="gc-c' + a + '" x="' + at + '" y="0" width="' + x.clicks[a] + '" height="1"/>'; at += x.clicks[a]; return r; }).join('') + '</svg>'; })() : '') + '</div><div class="re"><b>' + f(x.total) + '</b></div></div>').join('') + '</div>';
  b += '<section class="card"><h4 class="sh">Where they tapped</h4>' + d.places.map((name, i) => {
    const c = d.by_place[i], all = c.reduce((x, y) => x + y, 0);
    return '<div class="row"><div class="rm"><b>' + esc(name) + '</b><small>' + GC_ORDER.filter((a) => c[a]).map((a) => esc(d.actions[a]) + ' ' + f(c[a])).join(' · ') + (all ? '' : 'No taps') + '</small></div><div class="re"><b>' + f(all) + '</b></div></div>';
  }).join('') + '</section>';
  const top1 = d.ranges.length ? d.ranges[0].clicks : 1;
  b += '<section class="card"><h4 class="sh">Ranges looked at <span class="muted">in Gifts Criteria</span></h4>' + (d.ranges.length ? d.ranges.map((r) => '<div class="gc-rng"><span>' + esc(r.label) + '</span><b>' + f(r.clicks) + '</b><svg class="gc-bar" viewBox="0 0 100 1" preserveAspectRatio="none" aria-hidden="true"><rect x="0" y="0" width="' + (r.clicks * 100 / top1).toFixed(2) + '" height="1"/></svg></div>').join('') : '<p class="empty">No range taps in this period yet.</p>') + '</section>';
  b += '<p class="hint g-hint">Counted from update 2.60.627 on, for shoppers with something in the bag. Each cart’s own taps are on it in Carts.</p>';
  screen(ch, 'clicks', 0, head, b, false);
}
