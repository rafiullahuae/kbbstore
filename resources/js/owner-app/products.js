/*
 * Products (screens 10–12) — Petal. A stock-first list, and the product as a
 * column of rows that each open their own editor. Saves go to the server,
 * which hands them to the admin product editor's own rules.
 */
import { S, esc, api, $, $$, ic, th, top, back, toast, sheet, busy, debounce, paint, errorBox, tgl,
  screen, onScreen, mark, once, landed, isFresh, onReset, merge, ln, blk, times, skChips, skNote } from './core.js';

const L = { q: '', filter: 'all', rows: [], next: null, counts: {}, lowAt: 5, loaded: false, paged: false, lastQ: '' };
let P = null;
onReset(() => { Object.assign(L, { rows: [], next: null, counts: {}, loaded: false, paged: false, lastQ: '' }); P = null; });

/* Grey bars shaped like a product row: thumbnail, name, stock line, price. */
const rowSkel = () => '<div class="row">' + blk('th') + '<div class="rm">' + ln(80, 'k-l2') + ln(45, 'k-l2') + ln(40, 'k-sm') + '</div><div class="re">' + ln(0, 'k-amt') + '</div></div>';
const FILTERS = [['all', 'All'], ['low', 'Low stock'], ['out', 'Out of stock'], ['draft', 'Drafts']];

export function setProductFilter(f) { if (FILTERS.some(([k]) => k === f)) L.filter = f; }

function query(extra) {
  const p = new URLSearchParams();
  if (L.q) p.set('q', L.q);
  if (L.filter !== 'all') p.set('filter', L.filter);
  if (extra) Object.keys(extra).forEach((k) => p.set(k, extra[k]));
  const s = p.toString();
  return 'products' + (s ? '?' + s : '');
}

export const stk = (p) => (p.stock_status === 'outofstock' ? '<span class="stk out">Out of stock</span>'
  : p.stock_status === 'onbackorder' ? '<span class="stk low">On backorder</span>'
    : p.manage_stock && p.stock !== null ? (p.low ? '<span class="stk low">Low · ' + p.stock + ' left</span>' : '<span class="stk">In stock · ' + p.stock + '</span>')
      : '<span class="stk">In stock</span>');

export async function renderProducts(view) {
  view.className = 'view single';
  const held = L.loaded && isFresh('products');
  paint(view, top('Products', '<span data-sub>' + (held ? '' : 'Syncing…') + '</span>') + '<div class="body"><label class="search">' + ic('search', 's') + '<input type="search" data-search placeholder="Search name, SKU, brand" aria-label="Search products" value="' + esc(L.q) + '" enterkeyhint="search"><kbd>/</kbd></label>'
    + '<div class="chips" role="group" aria-label="Filter products" data-chips>' + (held ? '' : skChips(4)) + '</div><div class="sortrow"><span data-shown></span><b>Newest ' + ic('down') + '</b></div><div data-list>' + (held ? '' : skNote + '<div class="list">' + times(7, rowSkel) + '</div>') + '</div></div>');
  mark(view, 'products', 0, !held);
  const input = $('[data-search]', view);
  input.addEventListener('input', debounce(() => { L.q = input.value.trim(); load(view); }, 300));
  if (held) paintList(view);
  await fetchProducts();
}

/** The list's one request for the current search and filter (see orders.js fetchOrders). */
export function fetchProducts(passive, force) {
  const q = query();
  return once('products?' + q, async () => {
    const g = S.gen;
    const r = await api('GET', q, undefined, passive);
    if (g !== S.gen || q !== query()) return false;
    const host = onScreen('products');
    if (!r.ok) {
      const list = host && $('[data-list]', host);
      if (list && host.hasAttribute('data-sk')) { paint(list, errorBox(r.data.message)); mark(host, 'products', 0, false); }
      return false;
    }
    const keep = q === L.lastQ && L.paged;
    L.rows = merge(r.data.products, L.rows, keep);
    if (!keep) { L.next = r.data.next; L.paged = false; }
    L.lastQ = q;
    L.lowAt = r.data.low_at;
    if (r.data.counts) L.counts = r.data.counts;
    L.loaded = true;
    landed('products');
    if (host) { paintList(host); mark(host, 'products', 0, false); }
    return true;
  }, force);
}

async function load(view, more) {
  if (!more) { await fetchProducts(false, true); return; }
  const r = await api('GET', query({ before: L.next }));
  if (!r.ok) { toast(r.data.message || 'Could not load more.', true); return; }
  L.rows = L.rows.concat(r.data.products);
  L.next = r.data.next;
  L.paged = true;
  if ($('[data-list]', view)) paintList(view);
}

function paintList(view) {
  const c = L.counts;
  const sub = $('[data-sub]', view);
  if (sub && c.all !== undefined && !L.q) sub.textContent = (c.all || 0).toLocaleString('en-US') + ' products · ' + (c.low || 0) + ' low on stock';
  paint($('[data-chips]', view), FILTERS.map(([k, l]) => '<button type="button" data-pf="' + k + '"' + (L.filter === k ? ' class="on"' : '') + '>' + l + (c[k] !== undefined ? '<span>' + c[k] + '</span>' : '') + '</button>').join(''));
  const shown = $('[data-shown]', view);
  if (shown) shown.textContent = L.rows.length + (L.next ? '+' : '') + ' shown';
  paint($('[data-list]', view), (L.rows.length ? '<div class="list">' + L.rows.map((p) => '<a class="row" href="#/products/' + p.id + '">' + th(p.thumb)
    + '<div class="rm"><b class="cl2">' + esc(p.name) + '</b><small>' + stk(p) + (p.status !== 'publish' ? ' · ' + esc(p.status) : '') + '</small></div>'
    + '<div class="re"><b>' + esc(p.sale_display || p.price_display || '—') + '</b></div></a>').join('') + '</div>' : '<div class="card empty">No products match.</div>')
    + (L.next ? '<button type="button" class="btn sec wide" data-more>Show more</button>' : ''));
}

export async function productsClick(e, view) {
  const f = e.target.closest('[data-pf]');
  if (f) { L.filter = f.getAttribute('data-pf'); await load(view); return true; }
  const m = e.target.closest('[data-more]');
  if (m) { await busy(m, () => load(view, true)); return true; }
  return false;
}

/* ---------------------------------------------------------------- detail */

export async function renderProduct(view, id) {
  view.className = 'view single';
  if (P && P.id === +id && isFresh('product:' + id)) paintProduct(view);
  else screen(view, 'product', id, top('Product', '', back('#/products', 'Back to products')), skNote
    + '<div class="gal">' + times(3, () => '<div>' + blk('k-fill') + '</div>') + '</div>' + ln(70, 'k-nm') + '<div class="pmeta">' + blk('k-pill') + ln(30, 'k-sm') + '</div>'
    + '<div class="tiles">' + times(2, () => '<div class="card">' + ln(70, 'k-sm') + ln(40, 'k-tile') + '</div>') + '</div>'
    + '<section class="ed list">' + times(6, () => '<div class="row">' + blk('k-ico') + '<div class="rm">' + ln(30) + ln(60, 'k-sm') + '</div></div>') + '</section>', true);
  await fetchProduct(id);
}

export function fetchProduct(id, passive, force) {
  return once('product:' + id, async () => {
    const g = S.gen;
    const r = await api('GET', 'products/' + id, undefined, passive);
    if (g !== S.gen) return false;
    const host = onScreen('product', id);
    if (!r.ok) {
      if (host && host.hasAttribute('data-sk')) screen(host, 'product', id, top('Product', '', back('#/products')), errorBox(r.data.message || 'This product could not be opened.'));
      return false;
    }
    P = r.data.product;
    landed('product:' + id);
    if (host) paintProduct(host);
    return true;
  }, force);
}

const STATUS = { publish: ['pub', 'Published'], draft: ['draft', 'Draft'], private: ['draft', 'Private'], scheduled: ['hold', 'Scheduled'] };

function paintProduct(view) {
  const p = P, can = p.can_edit;
  const row = (k, icon, t, v) => '<button type="button" class="row" data-ed="' + k + '"' + (can || k === 'desc' || k === 'short' ? '' : ' aria-disabled="true"') + '><span class="ico">' + ic(icon) + '</span><div class="rm"><b>' + t + '</b><span class="rv">' + v + '</span></div>' + (can || k === 'desc' || k === 'short' ? ic('chev', 'chev s') : '') + '</button>';
  const imgs = p.images.slice(0, 3);
  const gal = imgs.length ? '<div class="gal' + (imgs.length === 1 ? ' one' : '') + '">' + imgs.map((src, i) => '<div>' + th(src, 'lg') + (i === 0 ? '<span class="cv">COVER</span>' : '') + '</div>').join('') + '</div>' : '';
  const st = STATUS[p.status] || ['pend', p.status];
  screen(view, 'product', p.id, top(esc(p.name), '', back('#/products', 'Back to products'), (navigator.share ? '<button class="ib plain" type="button" data-share aria-label="Share">' + ic('share') + '</button>' : '')
    + '<a class="ib plain" href="' + esc(p.url) + '" target="_blank" rel="noopener noreferrer" aria-label="View on the shop">' + ic('eye') + '</a>')
    , gal + '<h2 class="pname">' + esc(p.name) + '</h2>'
    + '<div class="pmeta"><span class="pill" data-s="' + st[0] + '">' + esc(st[1]) + '</span><span>' + esc([p.brand, p.sku].filter(Boolean).join(' · ')) + '</span>' + (p.visible ? '' : '<span class="tag">Hidden from catalogue</span>') + '</div>'
    + '<div class="tiles"><div class="card"><small>Sold · 30 days</small><b>' + p.sold_30d + '</b></div><div class="card"><small>Revenue · 30 days</small><b>' + esc(p.revenue_30d || '—') + '</b></div></div>'
    + '<section class="ed list">'
    + row('price', 'tag', 'Price', p.sale_display ? esc(p.price_display || '—') + ' · on sale ' + esc(p.sale_display) : esc(p.price_display || 'No price'))
    + row('inv', 'stack', 'Inventory', stk(p) + (p.manage_stock ? '' : ' · not tracked'))
    + row('cat', 'grid', 'Categories', esc(p.category_names.join(', ') || 'None'))
    + row('vis', 'eye', 'Visibility', esc(st[1]) + (p.visible ? ' · in catalogue' : ' · hidden'))
    + row('desc', 'text', 'Description', esc(p.description ? p.description.slice(0, 80) : 'None'))
    + row('short', 'text', 'Short description', esc(p.short_description || 'None'))
    + '<div class="row" aria-disabled="true"><span class="ico">' + ic('box') + '</span><div class="rm"><b>Product type</b><span class="rv">' + esc(p.type_label) + '</span></div></div>'
    + '</section>'
    + (p.variants.length ? '<section class="card"><h4 class="sh">Variants <span class="muted">' + p.variants.length + '</span></h4>' + p.variants.map((v) => '<div class="row"><div class="rm"><b>' + esc(v.label) + '</b><small>' + esc([v.sku, v.price_display].filter(Boolean).join(' · ')) + '</small></div><div class="re"><b>' + (v.stock !== null ? v.stock + ' left' : esc(v.stock_status)) + '</b></div></div>').join('') + '<p class="hint">Variant stock is edited in the admin product editor.</p></section>' : '')
    + (can ? '' : '<p class="ver">Your role can view products but not change them.</p>'));
}

export async function productClick(e, view) {
  if (!P) return false;
  if (e.target.closest('[data-share]')) { navigator.share({ title: P.name, url: P.url }).catch(() => {}); return true; }
  const r = e.target.closest('[data-ed]');
  if (!r || r.getAttribute('aria-disabled') === 'true') return false;
  const k = r.getAttribute('data-ed');
  if (k === 'price') priceSheet(view);
  else if (k === 'inv') invSheet(view);
  else if (k === 'cat') catSheet(view);
  else if (k === 'vis') visSheet(view);
  else if (k === 'desc' || k === 'short') sheet(k === 'desc' ? 'Description' : 'Short description', '<div class="ro">' + esc((k === 'desc' ? P.description : P.short_description) || 'None') + '</div><p class="hint">Formatted text is edited in the admin product editor, so its layout is never broken from a phone.</p><button class="btn pri wide" type="button" data-close>Done</button>');
  return true;
}

async function save(panel, close, view, data) {
  const err = $('[data-err]', panel);
  const r = await api('POST', 'products/' + P.id, data);
  if (!r.ok) { err.hidden = false; err.textContent = r.data.message || 'Not saved.'; return; }
  P = r.data.product;
  landed('product:' + P.id);
  close();
  paintProduct(view);
  toast('Saved · live on the shop');
}

function priceSheet(view) {
  sheet('Price', '<label class="fld"><span>Regular price</span><span class="inp"><em>' + esc(S.currency || 'AED') + '</em><input inputmode="decimal" data-reg value="' + esc(P.price || '') + '"></span></label>'
    + '<label class="fld"><span>Sale price</span><span class="inp"><em>' + esc(S.currency || 'AED') + '</em><input inputmode="decimal" data-sale value="' + esc(P.sale || '') + '" placeholder="None"></span></label>'
    + (P.sale_window ? '<p class="hint">This product has a sale schedule; it is kept.</p>' : '') + '<p class="hint" data-hint></p>'
    + '<p class="alert" data-err hidden></p><button class="btn pri wide" type="button" data-save>Save price</button>', (panel, close) => {
    const reg = $('[data-reg]', panel), sale = $('[data-sale]', panel), hint = $('[data-hint]', panel);
    const upd = () => {
      const a = parseFloat(reg.value), b = parseFloat(sale.value);
      hint.innerHTML = a > 0 && b > 0 && b < a ? 'Shoppers see <s>' + esc(reg.value) + '</s> <b>' + esc(sale.value) + '</b> · ' + Math.round((1 - b / a) * 100) + '% off' : '';
    };
    reg.addEventListener('input', upd); sale.addEventListener('input', upd); upd();
    $('[data-save]', panel).addEventListener('click', (e) => busy(e.currentTarget, () => save(panel, close, view, { price_aed: reg.value.trim() || null, sale_aed: sale.value.trim() || null })));
  });
}

function invSheet(view) {
  sheet('Inventory', '<div class="fld"><span>Quantity in stock</span><div class="step"><button type="button" data-step="-1" aria-label="One less">−</button><input type="number" inputmode="numeric" min="0" max="1000000" data-qty value="' + (P.stock === null ? 0 : P.stock) + '" aria-label="Quantity"><button type="button" data-step="1" aria-label="One more">+</button></div></div>'
    + '<div class="fld-row"><div>Track quantity<small>Stock goes down with each order</small></div>' + tgl(P.manage_stock, 'Track quantity', 'data-track') + '</div>'
    + '<div class="fld-row" data-instock-row' + (P.manage_stock ? ' hidden' : '') + '><div>In stock<small>When the quantity is not tracked</small></div>' + tgl(P.stock_status !== 'outofstock', 'In stock', 'data-instock') + '</div>'
    + '<div class="fld-row"><div>Allow backorders<small>Keep selling at zero</small></div>' + tgl(P.stock_status === 'onbackorder', 'Allow backorders', 'data-back') + '</div>'
    + '<div class="fld-row"><div>Low-stock alert at<small>Sends a notification</small></div><b>' + L.lowAt + '</b></div>'
    + '<p class="alert" data-err hidden></p><button class="btn pri wide" type="button" data-save>Save inventory</button>', (panel, close) => {
    const qty = $('[data-qty]', panel);
    panel.addEventListener('click', (e) => {
      const st = e.target.closest('[data-step]');
      if (st) { qty.value = Math.max(0, (parseInt(qty.value, 10) || 0) + +st.getAttribute('data-step')); return; }
      const t = e.target.closest('.tgl');
      if (t) {
        t.setAttribute('aria-checked', t.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
        if (t.hasAttribute('data-track')) $('[data-instock-row]', panel).hidden = t.getAttribute('aria-checked') === 'true';
      }
    });
    $('[data-save]', panel).addEventListener('click', (e) => {
      const track = $('[data-track]', panel).getAttribute('aria-checked') === 'true';
      const backo = $('[data-back]', panel).getAttribute('aria-checked') === 'true';
      const n = Math.max(0, parseInt(qty.value, 10) || 0);
      const inStock = track ? n > 0 : $('[data-instock]', panel).getAttribute('aria-checked') === 'true';
      const data = { manage_stock: track, stock_status: backo ? 'onbackorder' : (inStock ? 'instock' : 'outofstock') };
      if (track) data.stock = n;
      busy(e.currentTarget, () => save(panel, close, view, data));
    });
  });
}

async function catSheet(view) {
  sheet('Categories', '<div class="ccats" data-cats>' + times(6, () => blk('k-chip')) + '</div><p class="alert" data-err hidden></p><button class="btn pri wide" type="button" data-save>Save categories</button>', async (panel, close) => {
    const r = await api('GET', 'categories');
    const box = $('[data-cats]', panel);
    if (!r.ok) { paint(box, errorBox(r.data.message)); return; }
    const on = new Set(P.category_ids);
    paint(box, r.data.categories.map((c) => '<button type="button" data-cat="' + c.id + '"' + (on.has(c.id) ? ' class="on"' : '') + '>' + ic('check') + esc(c.name) + '</button>').join(''));
    box.addEventListener('click', (e) => { const b = e.target.closest('[data-cat]'); if (b) b.classList.toggle('on'); });
    $('[data-save]', panel).addEventListener('click', (e) => busy(e.currentTarget, () => save(panel, close, view, { category_ids: $$('[data-cat].on', box).map((b) => +b.getAttribute('data-cat')) })));
  });
}

function visSheet(view) {
  sheet('Visibility', '<div class="stlist">' + [['publish', 'pub', 'Published'], ['draft', 'draft', 'Draft'], ['private', 'draft', 'Private']].map(([k, c, l]) => '<button type="button" data-vs="' + k + '"' + (P.status === k ? ' class="cur"' : '') + '><span class="pill" data-s="' + c + '">' + l + '</span>' + ic('check') + '</button>').join('') + '</div>'
    + '<div class="fld-row"><div>Show in the catalogue<small>Listed on category and search pages</small></div>' + tgl(P.visible, 'Show in the catalogue', 'data-visible') + '</div>'
    + '<p class="alert" data-err hidden></p><button class="btn pri wide" type="button" data-save>Save</button>', (panel, close) => {
    let status = P.status;
    panel.addEventListener('click', (e) => {
      const b = e.target.closest('[data-vs]');
      if (b) { status = b.getAttribute('data-vs'); $$('[data-vs]', panel).forEach((x) => x.classList.toggle('cur', x === b)); }
      const t = e.target.closest('[data-visible]');
      if (t) t.setAttribute('aria-checked', t.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
    });
    $('[data-save]', panel).addEventListener('click', (e) => busy(e.currentTarget, () => save(panel, close, view, { status, is_visible: $('[data-visible]', panel).getAttribute('aria-checked') === 'true' })));
  });
}

export async function refreshProductsList() {
  if (onScreen('products')) await fetchProducts(true);
}
