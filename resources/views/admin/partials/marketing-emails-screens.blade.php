{{--
    Growth & Marketing → Marketing Emails — Campaigns · Templates · Customer
    groups · Reports, the builder and Review & send. Lane MK, packages E3 + E4
    of docs/EMAILS-PLAN.md.

    The owner, 4 October: "Marketing Emails under Growth & Marketing … builder
    and pre-ready templates … new arrivals, this week special, best sellers etc
    etc. and can be editable easily … i need proper tabs not just throw the
    content, follow this also for all emails pages stuff." The approved mocks
    are docs/rj-email-previews/admin/m1–m5; every view below follows its mock's
    fields, labels and layout, and the "PROPOSAL · static mockup" strip is the
    one thing left out, because this is the real screen.

    ── TABS ──────────────────────────────────────────────────────────────────
    Every tab row is drawn by tabBar() below: role=tablist / tab / tabpanel,
    ← → Home End, the selected tab focusable and the rest tabindex=-1, and the
    row scrolls sideways inside itself at 390 rather than widening the page.
    The markup is Lane EK's kbb-tabs contract with an `mke-` prefix; moving to
    EK's shared component is the class/attribute swap in tabBar() and
    tabPanel() only (mke-tabs → ectabs kbb-tabs, mke-tab → ectab kbb-tab,
    data-mke-tabs → data-kbt, data-mke-tab → data-kbt-tab).

    ── SAFETY ────────────────────────────────────────────────────────────────
    Every string from the server goes through esc() before innerHTML. The
    email preview is the server's render in an iframe with
    sandbox="allow-same-origin" and NO allow-scripts: nothing in it can run,
    and this script reads it only to outline and select a block.

    ── NO LAYOUT MEASURING ───────────────────────────────────────────────────
    Drag and drop works on LIST ORDER: the row under the pointer is the
    pointer event's own target (the browser's hit test), and which half of a
    row is a pair of CSS drop zones, top:0/50% each. Nothing here asks an
    element for its size or position; MarketingEmailsScreenTest forbids those
    APIs by name. The preview iframe's height is an estimate from the block
    list, and the frame scrolls if the email is longer.

    Pulled into app.blade.php once, beside emails-screens: it wraps window.go
    for 'mkt-email'. Its sidebar row is NOT registered from here: the approved
    m0 mock puts "Marketing Emails" FIRST in Growth & Marketing with a "new"
    tag, which only a NAV row can do (kbbAddNavEntry places after an anchor
    and carries no tag), and a row in NAV plus one from here is the duplicate
    AdminNavAndIdsTest refuses.
--}}
@verbatim
<style>
.mke{display:grid;gap:0;min-width:0}
.mke > *{min-width:0}
.mke-tabs{display:flex;gap:6px;flex-wrap:nowrap;overflow-x:auto;margin:0 0 16px;scrollbar-width:none;max-width:100%;padding:1px}
.mke-tabs::-webkit-scrollbar{display:none}
.mke-tab{flex:0 0 auto;font:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:99px;border:1px solid var(--border);background:var(--surface);color:var(--ink-2);cursor:pointer;white-space:nowrap}
.mke-tab.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.mke-tab:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.mke-tab .ct{font-weight:600;opacity:.8}
.mke-panel:focus{outline:none}
.mke-grid{display:grid;gap:14px}
.mke-g2{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.mke-g3{grid-template-columns:repeat(3,minmax(0,1fr))}.mke-g4{grid-template-columns:repeat(4,minmax(0,1fr))}
.mke-split{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.15fr);gap:16px;align-items:start}
.mke-builder{display:grid;grid-template-columns:200px minmax(0,1fr) 270px;gap:14px;align-items:start}
.mke-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh-s);padding:18px;min-width:0}
.mke-card h3{font-size:14.5px;font-weight:700;margin:0 0 4px}
.mke-card p.d{font-size:12.5px;color:var(--ink-soft);margin:0 0 12px}
.mke-f{display:block;margin:0 0 12px;min-width:0}
.mke-l{display:block;font-size:12px;font-weight:600;color:var(--ink-2);margin-bottom:5px}
.mke-in{display:block;border:1px solid var(--border);border-radius:10px;padding:9px 11px;font-size:13px;background:var(--surface);color:var(--ink);width:100%;box-sizing:border-box;font-family:inherit;min-width:0}
textarea.mke-in{resize:vertical;min-height:84px;line-height:1.5}
select.mke-in{appearance:none;-webkit-appearance:none;padding-right:28px;background-image:linear-gradient(45deg,transparent 50%,#8a93a6 50%),linear-gradient(135deg,#8a93a6 50%,transparent 50%);background-position:calc(100% - 16px) 50%,calc(100% - 11px) 50%;background-size:5px 5px;background-repeat:no-repeat}
.mke-h{display:block;font-size:11.5px;color:var(--ink-faint);margin-top:4px;line-height:1.5}
.mke-kpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:14px 16px;min-width:0}
.mke-kpi b{display:block;font-size:22px;letter-spacing:-.02em;margin:4px 0 2px;color:var(--ink);overflow-wrap:anywhere}
.mke-kpi span{font-size:12px;color:var(--ink-soft)}
.mke-tbl{width:100%;border-collapse:collapse}
.mke-tbl td,.mke-tbl th{vertical-align:middle}
.mke-tbl td{padding:12px;border-bottom:1px solid var(--border-2);font-size:13px;overflow-wrap:anywhere}
.mke-tbl tr:last-child td{border-bottom:0}
.mke-tbl .sub{font-size:11.5px;color:var(--ink-faint);margin-top:2px}
.mke-tbl tr.click{cursor:pointer}
.mke-tbl tr.click:hover td{background:var(--surface-2)}
.mke-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-width:0}
.mke-sp{flex:1}
.mke-acts{display:flex;gap:6px;flex-wrap:wrap}
.mke-acts .btn{white-space:nowrap}
.mke-frame{border:1px solid var(--border);border-radius:14px;background:#fff8f5;display:block;width:100%}
.mke-phone{width:390px;max-width:100%;margin:0 auto}
.mke-dev{display:inline-flex;border:1px solid var(--border);border-radius:99px;overflow:hidden;background:var(--surface)}
.mke-dev button{font:inherit;font-size:12px;font-weight:600;padding:6px 12px;color:var(--ink-soft);border:0;background:none;cursor:pointer}
.mke-dev button.on{background:var(--ink);color:#fff}
.mke-blocks .b{display:flex;align-items:center;gap:9px;width:100%;border:1px solid var(--border);background:var(--surface);border-radius:10px;padding:9px 10px;margin-bottom:7px;font:inherit;font-size:12.5px;font-weight:600;color:var(--ink);cursor:grab;text-align:start;touch-action:none;user-select:none}
.mke-blocks .b i{width:24px;height:24px;border-radius:7px;background:var(--accent-soft);color:var(--accent-ink);display:grid;place-items:center;font-style:normal;font-size:12px;flex:0 0 24px}
.mke-blocks .b.lock{opacity:.7;cursor:default}
.mke-blocks .b:focus-visible{outline:2px solid var(--accent)}
.mke-layers{display:grid;gap:6px;margin:0;padding:0;list-style:none}
.mke-layer{position:relative;display:flex;align-items:center;gap:6px;border:1px solid var(--border);background:var(--surface);border-radius:10px;padding:6px 6px 6px 4px;font-size:12.5px;font-weight:600;min-width:0}
.mke-layer.sel{border:2px solid #3f6fe0;padding:5px 5px 5px 3px}
.mke-layer .grip{cursor:grab;touch-action:none;padding:4px 6px;color:var(--ink-faint);border:0;background:none;font:inherit;font-size:14px;line-height:1}
.mke-layer .nm{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;border:0;background:none;font:inherit;text-align:start;color:var(--ink);padding:4px 2px}
.mke-layer .ud{border:1px solid var(--border);background:var(--surface);border-radius:7px;width:26px;height:26px;font-size:12px;cursor:pointer;color:var(--ink-2);flex:0 0 26px}
.mke-layer .ud:disabled{opacity:.35;cursor:default}
.mke-layer .dz{position:absolute;left:0;right:0;height:50%;display:none;z-index:2}
.mke-layer .dz.top{top:0}.mke-layer .dz.bot{top:50%}
.mke-dragging .mke-layer .dz{display:block}
.mke-plist .dz{position:absolute;left:0;right:0;height:50%;display:none;z-index:2}.mke-plist .dz.top{top:0}.mke-plist .dz.bot{top:50%}
.mke-dragging .mke-plist .dz{display:block}
.mke-layer.drop-before{box-shadow:0 -3px 0 #3f6fe0}
.mke-layer.drop-after{box-shadow:0 3px 0 #3f6fe0}
.mke-ghost{position:fixed;z-index:9999;pointer-events:none;background:#3f6fe0;color:#fff;font-size:12px;font-weight:700;padding:6px 10px;border-radius:8px;box-shadow:0 8px 20px rgba(16,24,40,.25);left:0;top:0}
.mke-dragging iframe{pointer-events:none}
.mke-canvas{position:relative;min-width:0}
.mke-canvas.drop-on{outline:2px dashed #3f6fe0;outline-offset:4px;border-radius:14px}
.mke-meter{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--ink-soft);margin:0 0 8px;flex-wrap:wrap}
.mke-meter .mke-bar{flex:1;min-width:120px}
.mke-bar{height:8px;border-radius:99px;background:var(--surface-3);overflow:hidden}
.mke-bar i{display:block;height:100%;background:var(--accent);border-radius:99px}
.mke-bar.warn i{background:#d08a1b}.mke-bar.bad i{background:#c0392b}
.mke-rule{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) minmax(0,1fr) auto;gap:8px;align-items:start;margin-bottom:8px}
.mke-rule > .v.wide{grid-column:1 / 4;grid-row:2}
.mke-x{width:34px;height:36px;border-radius:9px;border:1px solid var(--border);display:grid;place-items:center;color:var(--ink-soft);background:var(--surface);cursor:pointer;font-size:14px}
.mke-count{background:linear-gradient(135deg,var(--accent-soft),var(--surface));border:1px solid var(--border);border-radius:var(--r);padding:16px}
.mke-count b{font-size:30px;letter-spacing:-.03em;color:var(--ink)}
.mke-check{padding:0;margin:0}
.mke-check li{list-style:none;margin:0 0 8px;font-size:13px;line-height:1.5}
.mke-inbox{border:1px solid var(--border);border-radius:12px;padding:12px 14px;background:var(--surface)}
.mke-inbox .from{font-weight:700;font-size:13.5px}
.mke-inbox .subj{font-size:13px;margin-top:2px}
.mke-inbox .pre{font-size:12.5px;color:var(--ink-faint)}
.mke-scroll{overflow-x:auto}
.mke-note{border-radius:12px;padding:12px 14px;font-size:12.5px;line-height:1.55;background:#fff6e6;border:1px solid #f1d9a8;color:#6b4700;margin-bottom:14px}
.mke-note code,.mke-code{display:block;margin-top:8px;font-family:var(--mono);font-size:12px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:8px 10px;overflow-wrap:anywhere;white-space:pre-wrap;color:var(--ink)}
.mke-tpls{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(266px,100%),1fr));gap:14px}
.mke-tpl{display:flex;flex-direction:column;gap:8px;padding:12px}
/* A fixed 240-wide window onto the email at 600, scale .4: 600 × .4 = 240,
   so the thumbnail is the whole width of the email with nothing measured. */
.mke-thumb{position:relative;width:240px;max-width:100%;height:300px;margin:0 auto;overflow:hidden;border-radius:12px;border:1px solid var(--border);background:#fff8f5}
.mke-thumb iframe{position:absolute;left:0;top:0;width:600px;height:750px;border:0;transform:scale(.4);transform-origin:0 0;pointer-events:none}
.mke-thumb.blank{display:grid;place-items:center;font-size:40px;color:var(--ink-faint);background:var(--surface-2)}
.mke-chip{display:inline-block;font-size:11px;font-weight:600;background:var(--surface-3);border-radius:7px;padding:2px 7px;margin:0 4px 4px 0;color:var(--ink-2)}
.mke-emi{display:flex;flex-wrap:wrap;gap:6px}
.mke-emi label{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;border:1px solid var(--border);border-radius:99px;padding:5px 10px;cursor:pointer;background:var(--surface)}
.mke-emi label.on{background:var(--accent-soft);border-color:var(--accent);color:var(--accent-ink)}
.mke-emi input{margin:0}
.mke-toggle{display:flex;align-items:center;gap:10px;font-size:13px;margin:0 0 12px;cursor:pointer}
.mke-toggle input{width:18px;height:18px}
.mke-pp{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 0}
/* Lane EC: the hand-picked products, in the order the email prints them. */
.mke-plist{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:4px}
.mke-plist li{position:relative;display:flex;gap:6px;align-items:center;border:1px solid var(--border);border-radius:9px;padding:5px 6px;background:var(--surface);font-size:12.5px;min-width:0}
.mke-plist li .n{flex:1;min-width:0;line-height:1.3;overflow-wrap:anywhere}
.mke-plist li i{font-style:normal;min-width:18px;height:18px;border-radius:9px;background:var(--surface-3);font-size:10.5px;text-align:center;line-height:18px;color:var(--ink-2)}
.mke-plist li button{border:0;background:var(--surface-3);border-radius:7px;min-width:26px;height:26px;cursor:pointer;font:inherit;color:var(--ink-2)}
.mke-plist li button:disabled{opacity:.35;cursor:default}
.mke-plist li .grip{cursor:grab;touch-action:none;background:transparent}
.mke-plist li.drop-before{box-shadow:0 -2px 0 var(--accent)}.mke-plist li.drop-after{box-shadow:0 2px 0 var(--accent)}
.mke-mode{display:grid;grid-template-columns:1fr 1fr;background:var(--surface-3);border-radius:11px;padding:3px;margin:0 0 12px}
.mke-mode button{font:inherit;font-size:13px;font-weight:600;border:0;background:transparent;border-radius:9px;padding:8px 6px;color:var(--ink-soft);cursor:pointer}
.mke-mode button small{display:block;font-weight:400;font-size:11px;color:var(--ink-faint)}
.mke-mode button[aria-pressed="true"]{background:var(--surface);color:var(--ink);box-shadow:0 1px 3px rgba(16,24,40,.12)}
.mke-ideas{display:flex;flex-wrap:wrap;gap:6px;margin:-4px 0 12px}
.mke-ideas button{font:inherit;font-size:12px;border:1px dashed var(--border);background:var(--surface);border-radius:99px;padding:4px 10px;cursor:pointer;color:var(--ink-2);text-align:start}
.mke-pp .p{display:inline-flex;gap:6px;align-items:center;border:1px solid var(--border);border-radius:99px;padding:4px 6px 4px 10px;font-size:12px;background:var(--surface)}
.mke-pp .p button{border:0;background:var(--surface-3);border-radius:99px;width:20px;height:20px;cursor:pointer}
.mke-res{display:grid;gap:4px;margin-top:6px;max-height:180px;overflow:auto}
.mke-res button{display:flex;gap:8px;align-items:center;border:1px solid var(--border);background:var(--surface);border-radius:8px;padding:6px 8px;font:inherit;font-size:12px;text-align:start;cursor:pointer;color:var(--ink)}
.mke-res img{width:28px;height:28px;border-radius:6px;object-fit:cover}
.mke-empty{padding:18px;color:var(--ink-soft);font-size:13px}
.mke-err{color:#b8362d;font-size:12.5px;margin-top:8px}
.mke-warn{color:#8a5a00;font-size:12.5px}
.mke-back{justify-self:start;display:inline-block;border:0;background:none;color:var(--ink-soft);font:inherit;font-size:13px;cursor:pointer;padding:0;margin:0 0 10px;text-align:start}
.mke-namein{font:inherit;font-size:16px;font-weight:700;border:1px solid transparent;border-radius:8px;padding:4px 6px;background:transparent;color:var(--ink);min-width:120px;max-width:100%;field-sizing:content}
.mke-namein:hover,.mke-namein:focus{border-color:var(--border);background:var(--surface)}
.mke-typed{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
.mke-typed input{width:120px}
.mke-panel-tabs{margin:-4px 0 12px}
.mke-panel-tabs .mke-tab{padding:6px 12px;font-size:12.5px}
@media (max-width:1100px){.mke-builder{grid-template-columns:180px minmax(0,1fr)}.mke-builder > .mke-side{grid-column:1 / -1}}
@media (max-width:880px){
  .mke-split,.mke-builder,.mke-g2,.mke-g3{grid-template-columns:minmax(0,1fr)}
  .mke-g4{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
  .mke-rule{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
  .mke-rule > .v{grid-column:1 / 2}
  .mke-rule > .v.wide{grid-column:1 / -1;grid-row:auto}
  .mke-hide-sm{display:none}
  .mke-builder > .mke-side{grid-column:auto}
}
@media (max-width:480px){
  .mke-g4{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px}
  .mke-kpi{padding:12px}
  .mke-kpi b{font-size:19px}
  .mke-card{padding:14px}
}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'mkt-email';
  var LABEL = 'Marketing Emails';
  var GROUP = 'Growth & Marketing';

  /* The block palette: the m2 mock's order, glyphs and words. */
  var PALETTE = [
    ['mini_header', '▭', 'Mini header'], ['hero_image', '▣', 'Hero image'], ['heading', 'H', 'Heading'],
    ['text', '¶', 'Text'], ['button', '⬭', 'Button'], ['product_row', '▤', 'Product row'],
    ['product_grid', '▦', 'Product grid'], ['coupon', '%', 'Coupon'], ['image', '🖼', 'Image'],
    ['columns', '▥', 'Columns 2 / 3'], ['divider', '—', 'Divider'], ['spacer', '↕', 'Spacer'],
    ['social', '@', 'Social links'], ['badges', '✦', 'Benefit chips'], ['footer', '▁', 'Footer + unsubscribe']
  ];
  var NAMES = {}; PALETTE.forEach(function (p) { NAMES[p[0]] = p[2]; });

  /* Labels the canvas prints over the selected block — constants, never data. */
  var OUTLINE = {
    mini_header: 'Mini header', hero_image: 'Hero image', heading: 'Heading', text: 'Text', button: 'Button',
    product_row: 'Product row · auto-filled', product_grid: 'Product grid · auto-filled', coupon: 'Coupon',
    image: 'Image', columns: 'Columns', divider: 'Divider', spacer: 'Spacer', social: 'Social links', badges: 'Benefit chips', footer: 'Footer · required'
  };

  /* Default props of a new block (Blocks::schema()'s defaults). */
  var DEFAULTS = {
    mini_header: { topbar: true, nav: true, tagline: '' },
    hero_image: { art: '', src: '', alt: '', href: '' },
    heading: { style: 'hero', icon: 'spark', tone: 'pink', eyebrow: 'Picked for you', title: 'Your headline', lead: '', align: 'center', highlight: '' },
    text: { body: 'Write a sentence or two. **Bold**, *italic* and [a link](/shop/) work.', align: 'left', size: 15 },
    button: { label: 'Shop now', href: '/shop/', style: 'solid', align: 'center' },
    product_row: { title: '', fill: 'best_sellers', order: 'auto', count: 3, brand_id: null, category_id: null, max_price: 54, product_ids: [], cta: 'Shop now', show_sale: true },
    product_grid: { title: '', fill: 'newest', order: 'auto', count: 4, columns: '2', layout: 'standard', brand_id: null, category_id: null, max_price: 54, product_ids: [], cta: 'Shop now', show_sale: true },
    coupon: { coupon_id: null, line: '10% off your next order', expires: '' },
    image: { src: '', alt: '', href: '', width: 'inset' },
    columns: { count: '2', source: 'manual', items: [{ image: '', title: 'First', text: 'A line about it.', href: '/shop/' }, { image: '', title: 'Second', text: 'A line about it.', href: '/shop/' }], cta: 'Read more' },
    divider: {},
    spacer: { height: 24 },
    social: { instagram: '', facebook: '', tiktok: '', youtube: '', whatsapp: '' },
    badges: { items: [{ icon: 'truck', bold: 'Free delivery', text: 'over AED 199' }, { icon: 'bolt', bold: '1–3 days', text: 'across the UAE' }, { icon: 'card', bold: 'Tabby, Tamara', text: ', card or cash' }, { icon: 'sparkles', bold: '100% authentic', text: ', from Korea' }] },
    footer: { why: 'auto', note: '' }
  };
  var BADGE_GLYPH = { truck: '🚚', bolt: '⚡', card: '💳', sparkles: '✨', gift: '🎁', heart: '💕', star: '⭐', box: '📦' };

  /* Estimated rendered height of each block at 600 wide (no measuring). */
  var EST = { mini_header: 150, hero_image: 330, heading: 230, text: 110, button: 90, coupon: 170, image: 300, columns: 330, divider: 30, social: 80, badges: 150, footer: 340 };

  var STATUS = {
    draft: ['grey', 'Draft'], scheduled: ['amber', 'Scheduled'], sending: ['blue', 'Sending'], paused: ['amber', 'Paused'],
    sent: ['green', 'Sent'], cancelled: ['grey', 'Cancelled'], failed: ['red', 'Did not start']
  };

  var S = {
    view: 'tabs', tab: 'campaigns', tplTab: 'ready', opts: null, overview: null, templates: null, groups: null, reports: null,
    report: null, b: null, rv: null,
    g: { audience: 'customers', match: 'all', name: '', rules: [], editing: null, count: null, people: null, page: 1, period: 'year', err: '' }
  };

  /* --------------------------------------------------------------- utilities */

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function base() { return root() + '/admin-api/email-marketing'; }
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function toastMsg(t) { try { if (typeof window.toast === 'function') window.toast(t); } catch (e) {} }
  function host() { return document.getElementById('content'); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function when(iso) { if (!iso) return ''; var d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); }
  function clock(iso) { var d = iso ? new Date(iso) : new Date(); return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }); }
  function kb(bytes) { return Math.round((bytes || 0) / 1024) + ' KB'; }

  async function api(method, path, body) {
    var opts = { method: method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(base() + path, opts);
    var data = null;
    try { data = await r.json(); } catch (e) { data = null; }
    if (r.ok || r.status === 422 || r.status === 409) return { status: r.status, ok: r.ok, data: data || {} };
    var err = new Error('marketing ' + r.status); err.status = r.status; throw err;
  }
  function why(e) {
    if (e && e.status === 403) return 'Your role cannot do this. Marketing Emails is for the Owner and Manager (Administrator).';
    if (e && e.status === 404) return 'This screen is not in the server\'s route table yet. Clear the route cache (Platform → Cache) and reload.';
    if (e && e.status === 429) return 'Too many presses in one minute. Wait a moment and try again.';
    return 'The request did not complete. Try again in a moment.';
  }
  function refusal(d) {
    if (!d) return 'Could not save.';
    if (d.error) return d.error;
    if (d.errors) { var k = Object.keys(d.errors)[0]; if (k !== undefined) { var v = d.errors[k]; return String(Array.isArray(v) ? v[0] : v); } }
    return d.message || 'Could not save.';
  }
  function debounce(fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }

  /* ------------------------------------------------------------------- tabs */

  /* ONE place that writes tab markup. See the header for the kbb-tabs swap. */
  function tabBar(group, tabs, current, label) {
    return '<div class="mke-tabs" role="tablist" aria-label="' + esc(label) + '" data-mke-tabs="' + esc(group) + '">'
      + tabs.map(function (t) {
        var on = t[0] === current;
        return '<button type="button" role="tab" class="mke-tab' + (on ? ' on' : '') + '" id="mket_' + esc(group) + '_' + esc(t[0]) + '"'
          + ' data-mke-tab="' + esc(t[0]) + '" aria-controls="mkep_' + esc(group) + '" aria-selected="' + (on ? 'true' : 'false') + '" tabindex="' + (on ? '0' : '-1') + '">'
          + esc(t[1]) + (t[2] != null ? ' <span class="ct">· ' + esc(t[2]) + '</span>' : '') + '</button>';
      }).join('') + '</div>';
  }
  function tabPanel(group, current, inner) {
    return '<div class="mke-panel" role="tabpanel" id="mkep_' + esc(group) + '" aria-labelledby="mket_' + esc(group) + '_' + esc(current) + '" tabindex="0">' + inner + '</div>';
  }

  function selectTab(group, id, focus) {
    if (group === 'main') { S.tab = id; S.view = 'tabs'; S.report = null; paint(); }
    else if (group === 'tpl') { S.tplTab = id; paint(); }
    else if (group === 'aud') { S.g.audience = id; S.g.rules = []; S.g.editing = null; S.g.name = ''; S.g.people = null; paint(); recount(); }
    else if (group === 'period') { S.g.period = id; applyPeriod(); paint(); }
    else if (group === 'bleft') { S.b.left = id; paintBuilder(); }
    else if (group === 'bright') { S.b.right = id; paintBuilder(); }
    else if (group === 'dev') { S.b.device = id; paintBuilder(); }
    else if (group === 'steps') {
      if (id === 'design') openBuilder('campaign', S.rv.id);
      else if (id === 'customers') { var gsel = document.querySelector('[data-mke-rs="segment_id"]'); if (gsel) gsel.focus(); }
    }
    else if (group === 'when') { S.rv.when = id; paintReview(); }
    if (focus) {
      var b = document.getElementById('mket_' + group + '_' + id);
      if (b) b.focus();
    }
  }

  /* ----------------------------------------------------------------- shell */

  function shell(inner) {
    var h = host(); if (!h) return;
    h.innerHTML = '<div class="wrap mke" data-mke="1">' + inner + '</div>';
  }
  function setTitle(sub) {
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = LABEL + (sub ? ' · ' + sub : '');
  }

  async function loadOpts() {
    if (S.opts) return S.opts;
    S.opts = (await api('GET', '/options')).data;
    return S.opts;
  }

  function paint() {
    if (S.view === 'builder') return paintBuilder();
    if (S.view === 'review') return paintReview();
    setTitle(S.report ? 'Report' : ({ campaigns: '', templates: 'Templates', groups: 'Customer groups', reports: 'Reports' })[S.tab]);
    var heads = {
      campaigns: ['Marketing Emails', 'Design an email, choose who gets it, send now or later.'],
      templates: ['Templates', 'Ready-made emails that fill themselves with products from the shop when they are sent. Use one as it is, or duplicate it and make it yours.'],
      groups: ['Customer groups', 'Customers (people who ordered or have an account) and Subscribers (newsletter sign-ups) are kept as two separate lists. Groups update themselves as people order.'],
      reports: ['Reports', 'What each campaign did: delivered, clicked, and the orders that followed within 7 days of a click.']
    };
    var head = S.report ? [S.report.name, reportLead(S.report)] : heads[S.tab];
    var top = '<div class="mke-row"><div class="page-head" style="margin:0"><h2>' + esc(head[0]) + '</h2><p>' + esc(head[1]) + '</p></div><span class="mke-sp"></span>'
      + (S.tab === 'campaigns' ? '<button type="button" class="btn" data-mke="new">+ New campaign</button>' : '') + '</div><div style="height:14px"></div>';
    var tabs = tabBar('main', [['campaigns', 'Campaigns'], ['templates', 'Templates'], ['groups', 'Customer groups'], ['reports', 'Reports']], S.tab, 'Marketing Emails');
    var inner = '<div class="mke-empty">Loading…</div>';
    if (S.tab === 'campaigns' && S.overview) inner = campaignsPanel();
    if (S.tab === 'templates' && S.templates) inner = templatesPanel();
    if (S.tab === 'groups' && S.groups && S.opts) inner = groupsPanel();
    if (S.tab === 'reports' && (S.reports || S.report)) inner = S.report ? reportPanel() : reportsPanel();
    shell(top + tabs + tabPanel('main', S.tab, inner));
    load();
  }

  var loading = {};
  async function load() {
    var key = S.tab;
    if (loading[key]) return;
    try {
      if (S.tab === 'campaigns' && !S.overview) { loading[key] = 1; S.overview = (await api('GET', '/overview')).data; delete loading[key]; paint(); }
      else if (S.tab === 'templates' && !S.templates) { loading[key] = 1; S.templates = (await api('GET', '/templates')).data.templates || []; delete loading[key]; paint(); }
      else if (S.tab === 'groups' && (!S.groups || !S.opts)) { loading[key] = 1; await loadOpts(); S.groups = (await api('GET', '/groups')).data; delete loading[key]; paint(); recount(); }
      else if (S.tab === 'reports' && !S.reports && !S.report) { loading[key] = 1; S.reports = (await api('GET', '/reports')).data.campaigns || []; delete loading[key]; paint(); }
    } catch (e) { delete loading[key]; var p = document.getElementById('mkep_main'); if (p) p.innerHTML = '<div class="mke-note">' + esc(why(e)) + '</div>'; }
  }

  /* ------------------------------------------------------------- campaigns */

  function statusPill(c) {
    var st = STATUS[c.status] || ['grey', c.status];
    var label = st[1];
    if (c.status === 'sent' && c.finished_label) label = 'Sent ' + c.finished_label;
    if (c.status === 'scheduled' && c.scheduled_label) label = 'Scheduled ' + c.scheduled_label;
    if (c.status === 'sending' || c.status === 'paused') label += ' ' + num(c.sent) + ' / ' + num(c.recipients);
    var bar = (c.status === 'sending' || c.status === 'paused') && c.recipients ? '<div class="mke-bar" style="margin-top:6px"><i style="width:' + Math.min(100, Math.round(c.sent * 100 / c.recipients)) + '%"></i></div>' : '';
    return '<span class="pill ' + st[0] + '">' + esc(label) + '</span>' + bar;
  }

  function cronNote(cron, force) {
    if (!cron || cron.alive) return '';
    var scheduled = force || (S.overview && (S.overview.campaigns || []).some(function (c) { return c.status === 'scheduled' || c.status === 'sending'; }));
    if (!scheduled) return '';
    return '<div class="mke-note"><b>Scheduled sends need the cron line.</b> No scheduler tick has been seen for 10 minutes'
      + (cron.last_tick ? ' (last ' + esc(when(cron.last_tick)) + ')' : '') + ', so a scheduled campaign will not start on its own and a sending one only moves while its Review &amp; send page is open. Add this once in '
      + esc(cron.where) + ':<code>' + esc(cron.line) + '</code></div>';
  }

  function campaignsPanel() {
    var o = S.overview, t = o.tiles || {};
    var tiles = '<div class="mke-grid mke-g4" style="margin-bottom:14px">'
      + '<div class="mke-kpi"><span>Can be emailed</span><b>' + num(t.can_email) + '</b><span>of ' + num(t.customers) + ' customers + ' + num(t.subscribers) + ' subscribers</span></div>'
      + '<div class="mke-kpi"><span>Sent this month</span><b>' + num(t.sent_month) + '</b></div>'
      + '<div class="mke-kpi"><span>Clicks (30 days)</span><b>' + (t.click_rate_30 == null ? '—' : esc(t.click_rate_30) + '%') + '</b></div>'
      + '<div class="mke-kpi"><span>Orders from email (30 days)</span><b>' + esc(t.revenue_30 || '—') + '</b>' + (t.orders_30 ? '<span>' + num(t.orders_30) + ' orders</span>' : '') + '</div></div>';

    var rows = (o.campaigns || []).map(function (c) {
      var acts = [];
      if (c.status === 'draft' || c.status === 'scheduled') acts.push('<button type="button" class="btn ghost sm" data-mke="edit" data-id="' + c.id + '">Edit</button>', '<button type="button" class="btn sm" data-mke="review" data-id="' + c.id + '">Review &amp; send</button>');
      if (c.status === 'sending' || c.status === 'paused') acts.push('<button type="button" class="btn sm" data-mke="review" data-id="' + c.id + '">Open</button>');
      if (c.status === 'sent' || c.status === 'cancelled' || c.status === 'sending' || c.status === 'paused') acts.push('<button type="button" class="btn ghost sm" data-mke="report" data-id="' + c.id + '">Report</button>');
      acts.push('<button type="button" class="btn ghost sm" data-mke="dup" data-id="' + c.id + '">Duplicate</button>');
      if (c.status === 'draft' || c.status === 'cancelled' || c.status === 'failed') acts.push('<button type="button" class="btn ghost sm" data-mke="del" data-id="' + c.id + '" aria-label="Delete ' + esc(c.name) + '">Delete</button>');
      var clicks = c.sent ? (Math.round((c.clickers || 0) * 1000 / c.sent) / 10) + '%' : '—';
      return '<tr><td><b>' + esc(c.name) + '</b>' + (c.subject ? '<div class="sub">Subject: ' + esc(c.subject) + '</div>' : '') + '<div class="mke-acts" style="margin-top:8px">' + acts.join('') + '</div></td>'
        + '<td>' + statusPill(c) + '</td><td class="mke-hide-sm">' + esc(c.group || '—') + '</td><td class="mke-hide-sm">' + (c.sent ? num(c.sent) : '—') + '</td>'
        + '<td class="mke-hide-sm">' + clicks + '</td><td class="mke-hide-sm">' + (c.orders ? num(c.orders) + ' · ' + esc(c.revenue) : '—') + '</td></tr>';
    }).join('');
    var table = '<div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Campaign</th><th>Status</th><th class="mke-hide-sm">Group</th><th class="mke-hide-sm">Sent</th><th class="mke-hide-sm">Clicks</th><th class="mke-hide-sm">Orders</th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="6"><div class="mke-empty">No campaigns yet. Press <b>+ New campaign</b> and pick a ready template — New arrivals, This week\'s special, Best sellers…</div></td></tr>') + '</tbody></table></div>'
      + '<div class="mke-h" style="margin-top:8px">Opens are not tracked — there is no tracking pixel. Apple Mail pre-loads images, so open rates are inflated and unreliable; clicks and orders are real.</div>';

    var l = o.limits || {};
    var limits = '<div class="sec-title">Sending limits</div><div class="mke-card"><div class="mke-grid mke-g3">'
      + '<label class="mke-f"><span class="mke-l">Per minute</span><input class="mke-in" type="number" min="1" max="120" id="mkeRate" value="' + esc(l.per_minute) + '"' + (o.can_send ? '' : ' disabled') + '><span class="mke-h">At most 25 go in one step.</span></label>'
      + '<label class="mke-f"><span class="mke-l">Per day</span><input class="mke-in" type="number" min="1" max="' + esc(l.max_cap) + '" id="mkeCap" value="' + esc(l.per_day) + '"' + (o.can_send ? '' : ' disabled') + '><span class="mke-h">' + (l.google ? 'Google Workspace allows about 2,000 a day per account — the most this accepts.' : 'Server mail: 500 is the careful default; the order emails share this server.') + '</span></label>'
      + '<div class="mke-f"><span class="mke-l">Used today</span><div class="mke-in" style="background:var(--surface-2)">' + num(l.used_today) + ' of ' + num(l.per_day) + '</div><span class="mke-h">Counted from midnight, Dubai time, across every campaign.</span></div>'
      + '</div>' + (o.can_send ? '<div class="mke-row"><span class="mke-sp"></span><button type="button" class="btn sm" data-mke="limits">Save limits</button></div>' : '<div class="mke-h">🔒 Only <b>Owner and Manager (Administrator)</b> can change these.</div>') + '</div>';

    return cronNote(o.cron) + tiles + table + limits;
  }

  /* ------------------------------------------------------------- templates */

  function templatesPanel() {
    var ready = S.templates.filter(function (t) { return t.preset; });
    var mine = S.templates.filter(function (t) { return !t.preset; });
    var list = S.tplTab === 'mine' ? mine : ready;
    var cards = list.map(function (t) {
      var acts = '<button type="button" class="btn sm" data-mke="use" data-id="' + t.id + '">Use</button>'
        + '<button type="button" class="btn ghost sm" data-mke="tdup" data-id="' + t.id + '">Duplicate</button>'
        + (t.preset ? '' : '<button type="button" class="btn ghost sm" data-mke="tedit" data-id="' + t.id + '">Edit</button><button type="button" class="btn ghost sm" data-mke="tdel" data-id="' + t.id + '">Delete</button>');
      return '<div class="mke-card mke-tpl"><div class="mke-thumb"><iframe loading="lazy" sandbox="" tabindex="-1" aria-hidden="true" title="" src="' + esc(base() + '/templates/' + t.id + '/preview') + '"></iframe></div>'
        + '<div><b style="font-size:14px">' + esc(t.name) + '</b><div class="mke-h" style="margin-top:2px">' + esc(t.description || '') + '</div></div>'
        + '<div>' + (t.fills || []).map(function (f) { return '<span class="mke-chip">' + esc(f) + '</span>'; }).join('') + (t.theme === 'playful' ? '<span class="mke-chip">Playful K-beauty look</span>' : '') + (t.locale === 'ar' ? '<span class="mke-chip">Arabic · right to left</span>' : '') + (t.preset ? '<span class="mke-chip">Ready · read-only</span>' : '') + '</div>'
        + '<div class="mke-acts">' + acts + '</div></div>';
    }).join('');
    if (S.tplTab === 'ready') {
      cards = '<div class="mke-card mke-tpl"><div class="mke-thumb blank">＋</div><div><b style="font-size:14px">Start from blank</b><div class="mke-h" style="margin-top:2px">A header, a heading, a button and the footer — build the rest.</div></div><div class="mke-acts"><button type="button" class="btn sm" data-mke="blank">Use</button></div></div>' + cards;
    }
    if (!cards) cards = '<div class="mke-empty">No templates of your own yet. Duplicate a ready one, or press <b>Save as my template</b> in the builder.</div>';
    return tabBar('tpl', [['ready', 'Ready templates', ready.length], ['mine', 'My templates', mine.length]], S.tplTab, 'Template library')
      + tabPanel('tpl', S.tplTab, '<div class="mke-tpls">' + cards + '</div>'
      + '<div class="mke-h" style="margin-top:10px">Every product block fills itself from the shop when the email is sent — sold-out and unpublished products are left out. <b>Use</b> starts a campaign from a copy; the ready templates themselves never change, so a later update can improve them without touching your work.</div>');
  }

  /* ---------------------------------------------------------------- groups */

  function fieldsFor(aud) { return ((S.opts && S.opts.fields) || []).filter(function (f) { return f.audience === aud; }); }
  function fieldDef(key) { return ((S.opts && S.opts.fields) || []).filter(function (f) { return f.key === key; })[0]; }
  function opDef(field, op) { var f = fieldDef(field); return f ? f.ops.filter(function (o) { return o.op === op; })[0] : null; }

  function blankValue(kind) {
    var y = new Date().getFullYear();
    return { money: 500, num: 2, days: 90, money_range: [100, 500], num_range: [1, 3], date: new Date().toISOString().slice(0, 10), month: new Date().toISOString().slice(0, 7), year: y, range: [y + '-01-01', new Date().toISOString().slice(0, 10)], none: null, emirates: ['dubai'], name: '', id: '', country: 'AE' }[kind];
  }

  function valueInput(i, rule) {
    var o = opDef(rule.field, rule.op); if (!o) return '';
    var k = o.kind, v = rule.value, a = ' data-mke-rv="' + i + '"';
    if (k === 'none') return '<div class="mke-h" style="margin-top:9px">—</div>';
    if (k === 'money' || k === 'num' || k === 'days') return '<input class="mke-in" type="number" min="0"' + a + ' value="' + esc(v) + '" aria-label="Value">';
    if (k === 'year') return '<input class="mke-in" type="number" min="2000" max="2100"' + a + ' value="' + esc(v) + '" aria-label="Year">';
    if (k === 'month') return '<input class="mke-in" type="month"' + a + ' value="' + esc(v) + '" aria-label="Month">';
    if (k === 'date') return '<input class="mke-in" type="date"' + a + ' value="' + esc(v) + '" aria-label="Date">';
    if (k === 'country') return '<input class="mke-in" maxlength="2"' + a + ' value="' + esc(v) + '" aria-label="Country code">';
    if (k === 'money_range' || k === 'num_range' || k === 'range') {
      var t = k === 'range' ? 'date' : 'number';
      v = Array.isArray(v) ? v : ['', ''];
      return '<div class="mke-grid mke-g2" style="gap:6px"><input class="mke-in" type="' + t + '" data-mke-rv="' + i + '" data-part="0" value="' + esc(v[0]) + '" aria-label="From"><input class="mke-in" type="' + t + '" data-mke-rv="' + i + '" data-part="1" value="' + esc(v[1]) + '" aria-label="To"></div>';
    }
    if (k === 'emirates') {
      v = Array.isArray(v) ? v : [];
      return '<div class="mke-emi">' + (S.opts.emirates || []).map(function (e) {
        var on = v.indexOf(e.key) !== -1;
        return '<label class="' + (on ? 'on' : '') + '"><input type="checkbox" data-mke-emi="' + i + '" value="' + esc(e.key) + '"' + (on ? ' checked' : '') + '>' + esc(e.name) + '</label>';
      }).join('') + '</div>';
    }
    if (k === 'id' && rule.field === 'category') {
      return '<select class="mke-in"' + a + ' aria-label="Category"><option value="">Choose…</option>' + (S.opts.categories || []).map(function (c) { return '<option value="' + c.id + '"' + (String(c.id) === String(v) ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select>';
    }
    if (k === 'id') return '<input class="mke-in" type="number" min="1"' + a + ' value="' + esc(v) + '" placeholder="Product id" aria-label="Product id">';
    var list = rule.field === 'brand' ? ' list="mkeBrandList"' : '';
    return '<input class="mke-in"' + a + list + ' value="' + esc(v) + '" aria-label="Value">';
  }

  function ruleRow(rule, i) {
    var fs = fieldsFor(S.g.audience), f = fieldDef(rule.field) || fs[0];
    return '<div class="mke-rule"><select class="mke-in" data-mke-rf="' + i + '" aria-label="Rule">' + fs.map(function (x) { return '<option value="' + esc(x.key) + '"' + (x.key === rule.field ? ' selected' : '') + '>' + esc(x.label) + '</option>'; }).join('') + '</select>'
      + '<select class="mke-in" data-mke-ro="' + i + '" aria-label="Comparison">' + f.ops.map(function (o) { return '<option value="' + esc(o.op) + '"' + (o.op === rule.op ? ' selected' : '') + '>' + esc(o.label) + '</option>'; }).join('') + '</select>'
      + '<div class="v' + ((opDef(rule.field, rule.op) || {}).kind === 'emirates' ? ' wide' : '') + '">' + valueInput(i, rule) + '</div><button type="button" class="mke-x" data-mke-rx="' + i + '" aria-label="Remove this rule">×</button></div>';
  }

  function countBox() {
    var c = S.g.count, aud = S.g.audience === 'subscribers' ? 'subscribers' : 'customers';
    if (!c) return '<div class="mke-count"><b>…</b> ' + aud + ' match</div>';
    var diff = [];
    Object.keys(c.reasons || {}).forEach(function (k) { if (c.reasons[k]) diff.push(num(c.reasons[k]) + ' ' + (c.reason_labels ? c.reason_labels[k] : k)); });
    return '<div class="mke-count" aria-live="polite"><b>' + num(c.matched) + '</b> ' + aud + ' match · <b>' + num(c.emailable) + '</b> can be emailed'
      + '<div class="mke-h">' + (diff.length ? esc(diff.join(' · ')) + '. ' : '') + (S.g.audience === 'customers' ? 'Spend counts paid orders only, after refunds. Emirate comes from the delivery address.' : 'Only confirmed sign-ups can be emailed (double opt-in).')
      + (c.top_brand ? ' Top brand of this group: <strong style="color:var(--ink-2)">' + esc(c.top_brand.name) + '</strong>.' : '') + '</div></div>';
  }

  function peopleBox() {
    var p = S.g.people; if (!p) return '';
    var c = S.g.count || { matched: 0 };
    var from = (S.g.page - 1) * 50 + 1, to = from + p.length - 1;
    var rows = p.map(function (r) {
      return '<tr><td><b>' + esc(r.name || r.email) + '</b>' + (r.name ? '<div class="sub">' + esc(r.email) + '</div>' : '') + '</td><td class="mke-hide-sm">' + esc(r.detail) + '</td>'
        + '<td>' + (r.reason === 'ok' ? '<span class="pill green">Can be emailed</span>' : '<span class="pill grey">' + esc((S.g.count && S.g.count.reason_labels && S.g.count.reason_labels[r.reason]) || r.reason) + '</span>') + '</td></tr>';
    }).join('');
    return '<div class="sec-title">The ' + num(c.matched) + ' in this group</div><div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Person</th><th class="mke-hide-sm">Orders</th><th>Email</th></tr></thead><tbody>' + (rows || '<tr><td colspan="3"><div class="mke-empty">Nobody matches.</div></td></tr>') + '</tbody></table>'
      + '<div class="mke-row" style="padding:8px 10px"><span class="mke-h" style="margin:0">' + (p.length ? num(from) + '–' + num(to) + ' of ' + num(c.matched) : '') + '</span><span class="mke-sp"></span>'
      + '<button type="button" class="btn ghost sm" data-mke="pprev"' + (S.g.page > 1 ? '' : ' disabled') + '>← Previous 50</button><button type="button" class="btn ghost sm" data-mke="pnext"' + (to < c.matched ? '' : ' disabled') + '>Next 50 →</button></div></div>';
  }

  function periodCard() {
    var r = S.g.rules.filter(function (x) { return x.field === 'order_date'; })[0];
    var y = new Date().getFullYear();
    var mv = r && r.op === 'in_month' ? r.value : new Date().toISOString().slice(0, 7);
    var yv = r && r.op === 'in_year' ? r.value : y;
    var rv = r && r.op === 'between' ? r.value : [y + '-01-01', new Date().toISOString().slice(0, 10)];
    var years = []; for (var i = y; i >= y - 6; i--) years.push(i);
    var inner = S.g.period === 'month'
      ? '<label class="mke-f"><span class="mke-l">Month</span><input class="mke-in" type="month" id="mkePm" value="' + esc(mv) + '"></label>'
      : S.g.period === 'range'
        ? '<div class="mke-grid mke-g2"><label class="mke-f"><span class="mke-l">From</span><input class="mke-in" type="date" id="mkePf" value="' + esc(rv[0]) + '"></label><label class="mke-f"><span class="mke-l">To</span><input class="mke-in" type="date" id="mkePt" value="' + esc(rv[1]) + '"></label></div>'
        : '<label class="mke-f"><span class="mke-l">Year</span><select class="mke-in" id="mkePy">' + years.map(function (x) { return '<option' + (String(x) === String(yv) ? ' selected' : '') + '>' + x + '</option>'; }).join('') + '</select></label>';
    return '<div class="mke-card"><h3>Order date</h3><p class="d">Pick one way to set the period. It becomes the “Order date” rule of the group on the left.</p>'
      + tabBar('period', [['month', 'Month'], ['year', 'Year'], ['range', 'Custom range']], S.g.period, 'Order date period')
      + tabPanel('period', S.g.period, inner + '<div class="mke-row"><span class="mke-sp"></span><button type="button" class="btn ghost sm" data-mke="period">' + (r ? 'Update the rule' : 'Add as a rule') + '</button></div>') + '</div>';
  }

  function groupsPanel() {
    var t = (S.groups && S.groups.totals) || {};
    var list = (S.groups.groups || []).filter(function (g) { return g.audience === S.g.audience; });
    var saved = list.map(function (g) {
      return '<tr class="click" data-mke-g="' + g.id + '"><td><b>' + esc(g.name) + '</b>' + (g.preset ? '<div class="sub">Ready group</div>' : '') + '</td><td class="mke-hide-sm">' + (g.audience === 'subscribers' ? 'Subscribers' : 'Customers') + '</td><td>' + num(g.emailable) + '</td>'
        + '<td style="white-space:nowrap"><a class="btn ghost sm" style="text-decoration:none" href="' + esc(base() + '/groups/' + g.id + '/export') + '" data-mke-stop="1" aria-label="Download ' + esc(g.name) + ' as CSV">CSV</a>'
        + (g.preset || g.used ? '' : ' <button type="button" class="btn ghost sm" data-mke="gdel" data-id="' + g.id + '" aria-label="Delete ' + esc(g.name) + '">×</button>') + '</td></tr>';
    }).join('');
    var rules = S.g.rules.map(ruleRow).join('');
    var help = S.g.audience === 'customers'
      ? '<b>Rules:</b> total spent · number of orders · average order · emirate / region (Dubai, Abu Dhabi, Sharjah, Ajman, Ras Al Khaimah, Fujairah, Umm Al Quwain, outside UAE) · order date by month, by year or a custom date range · brands bought (“mostly” = the brand with the biggest share of what they spent; or “ever bought”) · last and first order · never ordered · bought a product or from a category · used a coupon · country · city · has an account · newsletter subscribed.'
      : '<b>Rules:</b> signed up (month, year, range or the last N days) · also a customer · has ordered · signed up from.';
    var left = '<div class="mke-card"><div class="mke-row"><h3 style="margin:0">' + (S.g.editing ? 'Edit group' : 'New group') + '</h3><span class="mke-sp"></span>'
      + '<select class="mke-in" id="mkeMatch" style="width:auto" aria-label="Match"><option value="all"' + (S.g.match === 'all' ? ' selected' : '') + '>Match ALL rules</option><option value="any"' + (S.g.match === 'any' ? ' selected' : '') + '>Match ANY rule</option></select></div><div style="height:10px"></div>'
      + '<label class="mke-f"><span class="mke-l">Name</span><input class="mke-in" id="mkeGname" maxlength="120" value="' + esc(S.g.name) + '" placeholder="e.g. Mostly bought Medicube · Dubai · 2026"></label>'
      + rules + '<button type="button" class="btn ghost sm" data-mke="radd">+ Add rule</button>'
      + '<div class="mke-h" style="margin-top:10px">' + help + '</div><div style="height:12px"></div>' + countBox()
      + (S.g.err ? '<div class="mke-err">' + esc(S.g.err) + '</div>' : '')
      + '<div style="height:10px"></div><div class="mke-row"><button type="button" class="btn ghost sm" data-mke="see">See the ' + (S.g.count ? num(S.g.count.matched) : '…') + '</button>'
      + (S.g.editing ? '<button type="button" class="btn ghost sm" data-mke="gnew">New group</button>' : '') + '<span class="mke-sp"></span><button type="button" class="btn sm" data-mke="gsave">' + (S.g.editing ? 'Save changes' : 'Save group') + '</button></div></div>'
      + '<datalist id="mkeBrandList">' + ((S.opts.brands || []).map(function (b) { return b.name; }).concat(S.opts.order_brands || [])).filter(function (v, i, a) { return a.indexOf(v) === i; }).map(function (n) { return '<option value="' + esc(n) + '">'; }).join('') + '</datalist>';
    var right = (S.g.audience === 'customers' ? periodCard() + '<div style="height:14px"></div>' : '')
      + '<div class="mke-card"><h3>Saved groups</h3><div class="mke-scroll"><table class="mke-tbl"><thead><tr><th>Group</th><th class="mke-hide-sm">List</th><th>Can email</th><th></th></tr></thead><tbody>'
      + (saved || '<tr><td colspan="4"><div class="mke-empty">None yet.</div></td></tr>') + '</tbody></table></div><div class="mke-h" style="margin-top:6px">Tap a group to open it. CSV downloads everyone in it, with whether they can be emailed.</div></div>';
    return tabBar('aud', [['customers', 'Customers', num(t.customers)], ['subscribers', 'Subscribers', num(t.subscribers)]], S.g.audience, 'Which list')
      + tabPanel('aud', S.g.audience, '<div class="mke-split"><div>' + left + '</div><div>' + right + '</div></div>' + peopleBox());
  }

  var recount = debounce(async function () {
    if (S.view !== 'tabs' || S.tab !== 'groups') return;
    try {
      var r = await api('POST', '/groups/count', { audience: S.g.audience, match: S.g.match, rules: S.g.rules });
      S.g.err = r.ok ? '' : refusal(r.data);
      if (r.ok) S.g.count = r.data;
    } catch (e) { S.g.err = why(e); }
    var box = document.querySelector('.mke-count');
    if (box && S.tab === 'groups') { box.outerHTML = countBox(); var see = document.querySelector('[data-mke="see"]'); if (see && S.g.count) see.textContent = 'See the ' + num(S.g.count.matched); }
    var err = document.querySelector('.mke-err'); if (S.g.err && !err) paint();
  }, 350);

  async function people(page) {
    S.g.page = page;
    try {
      var r = await api('POST', '/groups/people', { audience: S.g.audience, match: S.g.match, rules: S.g.rules, page: page });
      if (r.ok) S.g.people = r.data.people || [];
    } catch (e) { toastMsg(why(e)); }
    paint();
  }

  function applyPeriod() {
    var r = { field: 'order_date' };
    if (S.g.period === 'month') { r.op = 'in_month'; r.value = (document.getElementById('mkePm') || {}).value || new Date().toISOString().slice(0, 7); }
    else if (S.g.period === 'range') { r.op = 'between'; r.value = [(document.getElementById('mkePf') || {}).value || '', (document.getElementById('mkePt') || {}).value || '']; if (!r.value[0]) r.value = blankValue('range'); }
    else { r.op = 'in_year'; r.value = parseInt((document.getElementById('mkePy') || {}).value || new Date().getFullYear(), 10); }
    return r;
  }

  /* --------------------------------------------------------------- reports */

  function reportLead(r) {
    var span = r.started_at ? when(r.started_at) + (r.finished_at ? '–' + clock(r.finished_at) : '') : '';
    return [span ? 'Sent ' + span : (STATUS[r.status] || ['', r.status])[1], r.group, num(r.recipients) + ' recipients'].filter(Boolean).join(' · ');
  }

  function reportsPanel() {
    var rows = S.reports.map(function (c) {
      return '<tr class="click" data-mke-r="' + c.id + '"><td><b>' + esc(c.name) + '</b><div class="sub">' + esc(c.subject || '') + '</div></td><td>' + statusPill(c) + '</td><td class="mke-hide-sm">' + esc(c.group || '—') + '</td><td>' + num(c.sent) + '</td><td class="mke-hide-sm">' + (c.orders ? num(c.orders) + ' · ' + esc(c.revenue) : '—') + '</td></tr>';
    }).join('');
    return '<div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Campaign</th><th>Status</th><th class="mke-hide-sm">Group</th><th>Sent</th><th class="mke-hide-sm">Orders (7 days)</th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="5"><div class="mke-empty">No campaign has been sent yet.</div></td></tr>') + '</tbody></table></div>';
  }

  function reportPanel() {
    var r = S.report;
    var links = (r.links || []).map(function (l) { return '<tr><td>' + esc(l.label) + '<div class="sub">' + esc(l.url) + '</div></td><td>' + num(l.clicks) + '</td></tr>'; }).join('');
    var fails = (r.failures || []).map(function (f) { return '<tr><td>' + esc(f.email) + '</td><td>' + (f.status === 'failed' ? 'Refused' : 'Skipped') + '</td><td class="mke-hide-sm">' + esc(f.error) + '</td></tr>'; }).join('');
    return '<button type="button" class="mke-back" data-mke="reports">← All reports</button>'
      + '<div class="mke-grid mke-g4">'
      + '<div class="mke-kpi"><span>Accepted by mail servers</span><b>' + num(r.sent) + '</b><span>' + num(r.failed) + ' refused' + (r.skipped ? ' · ' + num(r.skipped) + ' skipped' : '') + (r.pending ? ' · ' + num(r.pending) + ' still to go' : '') + '</span></div>'
      + '<div class="mke-kpi"><span>Clicked</span><b>' + num(r.clickers) + ' · ' + esc(r.click_rate) + '%</b><span>' + num(r.clicks) + ' clicks in all</span></div>'
      + '<div class="mke-kpi"><span>Orders within 7 days</span><b>' + num(r.orders) + ' · ' + esc(r.revenue) + '</b><span>Paid orders after a click</span></div>'
      + '<div class="mke-kpi"><span>Unsubscribed</span><b>' + num(r.unsubscribes) + '</b><span>' + num(r.complaints) + ' spam complaints reported</span></div></div>'
      + '<div class="sec-title">Most clicked</div><div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Link</th><th>Clicks</th></tr></thead><tbody>' + (links || '<tr><td colspan="2"><div class="mke-empty">No links yet.</div></td></tr>') + '</tbody></table></div>'
      + (fails ? '<div class="sec-title">Not delivered</div><div class="mke-card mke-scroll" style="padding:6px"><table class="mke-tbl"><thead><tr><th>Address</th><th>What happened</th><th class="mke-hide-sm">The mail server said</th></tr></thead><tbody>' + fails + '</tbody></table></div>' : '')
      + '<div class="mke-h" style="margin-top:8px">Opens are not tracked (no pixel). Orders count only paid orders by the same address within 7 days after their first click on this campaign.</div>';
  }

  async function openReport(id) {
    S.view = 'tabs'; S.tab = 'reports'; S.report = null; paint();
    try { S.report = (await api('GET', '/reports/' + id)).data.report; } catch (e) { toastMsg(why(e)); }
    paint();
  }

  /* --------------------------------------------------------------- builder */

  function blockLabel(b) {
    var p = b.props || {}, n = NAMES[b.type] || b.type;
    var extra = p.title || p.label || p.eyebrow || (p.body ? String(p.body).slice(0, 30) : '');
    return n + (extra ? ' · ' + extra : '');
  }

  async function openBuilder(kind, id) {
    S.view = 'builder';
    shell('<div class="mke-empty">Opening the builder…</div>');
    setTitle('Builder');
    try {
      await loadOpts();
      var d = kind === 'template' ? (await api('GET', '/templates/' + id)).data.template : (await api('GET', '/campaigns/' + id)).data.campaign;
      if (!S.groups) S.groups = (await api('GET', '/groups')).data;
      S.b = {
        kind: kind, id: d.id, name: d.name, subject: d.subject || '', preheader: d.preheader || '', from_name: d.from_name || '', segment_id: d.segment_id || null,
        theme: d.theme || 'standard', locale: d.locale || 'en', ideas: d.subject_ideas || [],
        blocks: kind === 'template' ? d.blocks_list : d.blocks, editable: kind === 'template' ? !d.preset : d.editable, preset: !!d.preset, status: d.status || 'draft',
        sel: 0, device: 'desktop', left: 'blocks', right: 'block', past: [], future: [], saved: d.updated_at, preview: null, dirty: false, warnings: d.warnings || []
      };
      S.b.sel = Math.min(2, S.b.blocks.length - 1);
      paintBuilder();
      refresh();
    } catch (e) { shell('<div class="mke-note">' + esc(why(e)) + '</div><button class="btn ghost" data-mke="home">Back</button>'); }
  }

  function estHeight(blocks, phone) {
    var h = 60;
    blocks.forEach(function (b) {
      var p = b.props || {};
      if (b.type === 'product_grid') h += (p.title ? 40 : 0) + Math.ceil((p.count || 4) / (phone ? Math.min(2, parseInt(p.columns || 2, 10)) : parseInt(p.columns || 2, 10))) * (phone ? 360 : 340);
      else if (b.type === 'product_row') h += (p.title ? 40 : 0) + (p.count || 3) * 130;
      else if (b.type === 'spacer') h += p.height || 24;
      else if (b.type === 'text') h += 40 + Math.ceil(String(p.body || '').length / (phone ? 40 : 70)) * 26;
      else h += EST[b.type] || 100;
    });
    return Math.min(6000, Math.round(h * (phone ? 1.2 : 1)));
  }

  function paintBuilder() {
    var b = S.b; if (!b) return;
    setTitle('Builder');
    var mine = b.kind === 'template';
    var savedPill = b.dirty ? '<span class="pill amber">Saving…</span>' : '<span class="pill grey">' + (b.editable ? 'Draft · saved ' + esc(clock(b.saved)) : (b.preset ? 'Ready template · read-only' : 'Sent · read-only')) + '</span>';
    var head = '<button type="button" class="mke-back" data-mke="home">← ' + (mine ? 'Templates' : 'Campaigns') + '</button>'
      + '<div class="mke-row" style="margin-bottom:14px"><input class="mke-namein" id="mkeBname" maxlength="120" value="' + esc(b.name) + '" aria-label="Name"' + (b.editable ? '' : ' disabled') + '>' + savedPill + '<span class="mke-sp"></span>'
      + '<button type="button" class="btn ghost sm" data-mke="undo" aria-label="Undo"' + (b.past.length ? '' : ' disabled') + '>↶</button><button type="button" class="btn ghost sm" data-mke="redo" aria-label="Redo"' + (b.future.length ? '' : ' disabled') + '>↷</button>'
      + '<span class="mke-dev">' + tabBar('dev', [['desktop', 'Desktop'], ['phone', 'Phone']], b.device, 'Preview width').replace('class="mke-tabs"', 'class="mke-tabs" style="margin:0;gap:0;padding:0"').replace(/class="mke-tab( on)?"/g, function (m, on) { return 'class="mke-tab' + (on || '') + '" style="border:0;border-radius:0;padding:6px 12px;font-size:12px"'; }) + '</span>'
      + (mine ? '' : '<button type="button" class="btn ghost sm" data-mke="btest">Send test</button>')
      + (mine ? '<button type="button" class="btn sm" data-mke="tuse" data-id="' + b.id + '">Use in a campaign →</button>' : '<button type="button" class="btn sm" data-mke="next">Next: choose customers →</button>') + '</div>';

    var palette = PALETTE.map(function (p) {
      var lock = p[0] === 'footer';
      return '<button type="button" class="b' + (lock ? ' lock' : '') + '" data-mke-add="' + p[0] + '"' + (lock || !b.editable ? ' disabled' : '') + '><i>' + esc(p[1]) + '</i>' + esc(p[2]) + (lock ? ' 🔒' : '') + '</button>';
    }).join('');
    var layers = '<ol class="mke-layers" id="mkeLayers">' + b.blocks.map(function (bl, i) {
      var lock = bl.type === 'footer';
      return '<li class="mke-layer' + (i === b.sel ? ' sel' : '') + '" data-mke-i="' + i + '"><span class="dz top"></span><span class="dz bot"></span>'
        + '<button type="button" class="grip" data-mke-grip="' + i + '" aria-label="Drag to move"' + (lock || !b.editable ? ' disabled' : '') + '>⠿</button>'
        + '<button type="button" class="nm" data-mke-pick="' + i + '">' + esc(blockLabel(bl)) + '</button>'
        + '<button type="button" class="ud" data-mke-up="' + i + '" aria-label="Move up"' + (i === 0 || lock || !b.editable ? ' disabled' : '') + '>↑</button>'
        + '<button type="button" class="ud" data-mke-down="' + i + '" aria-label="Move down"' + (i >= b.blocks.length - 2 || lock || !b.editable ? ' disabled' : '') + '>↓</button></li>';
    }).join('') + '</ol>';
    var left = '<div class="mke-card mke-blocks" style="padding:12px">' + tabBar('bleft', [['blocks', 'Drag a block'], ['order', 'Order']], b.left, 'Blocks').replace('class="mke-tabs"', 'class="mke-tabs mke-panel-tabs"')
      + tabPanel('bleft', b.left, b.left === 'blocks'
        ? palette + '<div class="mke-h">Drag a block onto the email, or tap it to add it under the selected block. The footer (addresses, Unsubscribe) is in every campaign and cannot be removed.</div>'
        : layers + '<div class="mke-h" style="margin-top:8px">Drag ⠿ to move a block, or use ↑ ↓. The footer always stays last.</div>') + '</div>';

    var pv = b.preview || {}, max = pv.max_bytes || 97280, pct = Math.min(100, Math.round((pv.bytes || 0) * 100 / max));
    var meter = '<div class="mke-meter"><span>Size ' + kb(pv.bytes) + ' of 95 KB</span><div class="mke-bar ' + (pct > 95 ? 'bad' : pct > 75 ? 'warn' : '') + '" role="meter" aria-valuemin="0" aria-valuemax="' + max + '" aria-valuenow="' + (pv.bytes || 0) + '" aria-label="Email size"><i style="width:' + pct + '%"></i></div>'
      + (pv.bytes > max ? '<span class="mke-err" style="margin:0">Too big to send — Gmail cuts emails over 102 KB.</span>' : '') + '</div>';
    var phone = b.device === 'phone';
    var canvas = '<div class="mke-canvas" id="mkeCanvas">' + meter
      + '<div' + (phone ? ' class="mke-phone"' : '') + '><iframe class="mke-frame" id="mkeFrame" title="Email preview" sandbox="allow-same-origin" style="height:' + estHeight(b.blocks, phone) + 'px"></iframe></div>'
      + ((pv.errors || []).concat(pv.warnings || []).map(function (w) { return '<div class="mke-warn" style="margin-top:6px">⚠ ' + esc(w) + '</div>'; }).join('')) + '</div>';

    var right = '<div class="mke-card mke-side" style="padding:14px">' + tabBar('bright', [['block', 'Block'], ['email', mine ? 'Template' : 'Email']], b.right, 'Settings').replace('class="mke-tabs"', 'class="mke-tabs mke-panel-tabs"')
      + tabPanel('bright', b.right, b.right === 'block' ? blockForm() : emailForm()) + '</div>';

    shell(head + '<div class="mke-builder">' + left + canvas + right + '</div>');
    writeFrame();
  }

  function sel(name, opts, cur, attrs) {
    return '<select class="mke-in" ' + attrs + '>' + opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(cur == null ? '' : cur) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>';
  }
  function fText(k, label, v, help, area, max) {
    return '<label class="mke-f"><span class="mke-l">' + esc(label) + '</span>' + (area ? '<textarea class="mke-in" data-mke-p="' + k + '" maxlength="' + (max || 3000) + '">' + esc(v) + '</textarea>' : '<input class="mke-in" data-mke-p="' + k + '" maxlength="' + (max || 200) + '" value="' + esc(v) + '">') + (help ? '<span class="mke-h">' + help + '</span>' : '') + '</label>';
  }
  function fSel(k, label, opts, v, help) { return '<label class="mke-f"><span class="mke-l">' + esc(label) + '</span>' + sel(k, opts, v, 'data-mke-p="' + k + '"') + (help ? '<span class="mke-h">' + help + '</span>' : '') + '</label>'; }
  function fBool(k, label, v) { return '<label class="mke-toggle"><input type="checkbox" data-mke-p="' + k + '"' + (v ? ' checked' : '') + '>' + esc(label) + '</label>'; }
  function fNum(k, label, v, min, max) { return '<label class="mke-f"><span class="mke-l">' + esc(label) + '</span><input class="mke-in" type="number" data-mke-p="' + k + '" min="' + min + '" max="' + max + '" value="' + esc(v) + '"></label>'; }
  var MARKS = 'Plain text with <b>**bold**</b>, <i>*italic*</i> and [a link](https://…). {first_name} prints the name.';

  function blockForm() {
    var b = S.b, bl = b.blocks[b.sel]; if (!bl) return '<div class="mke-empty">Select a block on the email.</div>';
    var p = bl.props || {}, o = S.opts || {}, t = bl.type, h = '<h3>' + esc(NAMES[t]) + '</h3>';
    var dis = b.editable ? '' : '<div class="mke-h">Read-only. ' + (b.preset ? 'Duplicate this template to change it.' : '') + '</div>';
    if (t === 'mini_header') h += '<p class="d">The shop\'s logo or name, and Shop · Track order · My account.</p>' + fBool('topbar', 'Show the line above (“Authentic K-beauty, curated for you”)', p.topbar) + fBool('nav', 'Show Shop · Track order · My account', p.nav)
      + (b.theme === 'playful' ? fText('tagline', 'Line above the card (Playful look)', p.tagline, 'e.g. ✿ glow-up alert ✿ — shown when “Show the line above” is on.', false, 80) : '');
    if (t === 'hero_image') h += '<p class="d">A full-width picture under the header.</p>' + fSel('art', 'Picture', [['', 'From the media library']].concat((o.art || []).map(function (a) { return [a.key, a.alt]; })), p.art)
      + (p.art ? '' : fText('src', 'Picture address', p.src, 'Pick from the media library, or an https:// address.') + '<button type="button" class="btn ghost sm" data-mke="media" data-k="src" style="margin:-4px 0 12px">Choose from media library</button>')
      + fText('alt', 'Describe the picture', p.alt, 'Shown when pictures are off.') + fText('href', 'Link (optional)', p.href, 'https://… or /shop/');
    if (t === 'heading') h += fSel('style', 'Style', [['hero', 'Hero — icon, small line, headline'], ['title', 'Title — large serif line'], ['label', 'Label — small grey capitals']], p.style)
      + (p.style === 'hero' ? fSel('icon', 'Icon', [['none', 'None']].concat((o.icons || []).map(function (x) { return [x, x]; })), p.icon) + fSel('tone', 'Colour', (o.tones || []).map(function (x) { return [x, x]; }), p.tone) + fText('eyebrow', 'Small line above', p.eyebrow, '', false, 80) : '')
      + fText('title', p.style === 'label' ? 'Label' : 'Headline', p.title, '{first_name} and {top_brand} work here.', false, 160)
      + (p.style !== 'label' ? fText('highlight', 'Highlighted line (optional)', p.highlight, 'Printed under the headline in a pink highlighter — “less prices”. In the Standard look it joins the headline.', false, 80) : '')
      + (p.style !== 'label' ? fText('lead', 'Sentence under it', p.lead, MARKS, true, 600) + fSel('align', 'Align', [['center', 'Centre'], ['left', 'Left']], p.align) : '');
    if (t === 'text') h += fText('body', 'Text', p.body, MARKS, true, 3000) + fSel('align', 'Align', [['left', 'Left'], ['center', 'Centre']], p.align) + fNum('size', 'Text size (px)', p.size, 13, 18);
    if (t === 'button') h += fText('label', 'Button text', p.label, '', false, 60) + fText('href', 'Link', p.href, 'https://… or a page of the shop like /super-sale/. A javascript: or data: link is refused.') + fSel('style', 'Style', [['solid', 'Solid'], ['ghost', 'Outline'], ['dark', 'Dark']], p.style) + fSel('align', 'Align', [['center', 'Centre'], ['left', 'Left']], p.align);
    if (t === 'product_row' || t === 'product_grid') {
      var top = b.preview && b.preview.top_brand;
      var manual = p.fill === 'hand_picked';
      h += '<p class="d">Pictures, prices and links come from the catalogue when each email is sent. Sold-out products are skipped.</p>'
        + '<div class="mke-mode" role="group" aria-label="How products are chosen"><button type="button" data-mke-fillmode="auto" aria-pressed="' + (!manual) + '">Automatic<small>a rule and a count</small></button><button type="button" data-mke-fillmode="manual" aria-pressed="' + manual + '">Manual<small>search, pick, order</small></button></div>'
        + (manual ? '' : fSel('fill', 'Fill with', Object.keys(o.fills || {}).filter(function (k) { return k !== 'hand_picked'; }).map(function (k) { return [k, o.fills[k]]; }), p.fill, p.fill === 'group_top_brand' ? 'For “Mostly bought Medicube” that is Medicube. Each group gets its own brand.' : ''))
        + (p.fill === 'group_top_brand' ? '<div class="mke-count" style="padding:10px 12px;margin-bottom:12px"><b style="font-size:15px">' + esc(top ? top.name : 'Choose a group') + '</b><div class="mke-h">' + (top ? 'Top brand of the chosen group · ' + esc(p.count) + ' best-selling in-stock products' : 'Pick the group under Email → Group to see its top brand. Until then this shows best sellers.') + '</div></div>' : '')
        + (p.fill === 'brand' ? fSel('brand_id', 'Brand', [['', 'Choose…']].concat((o.brands || []).map(function (x) { return [x.id, x.name]; })), p.brand_id) : '')
        + (p.fill === 'category' ? fSel('category_id', 'Category', [['', 'Choose…']].concat((o.categories || []).map(function (x) { return [x.id, x.name]; })), p.category_id) : '')
        + (p.fill === 'under_price' ? fNum('max_price', 'Under (AED)', p.max_price, 1, 100000) : '')
        + (p.fill === 'hand_picked' ? pickedBox(p) : '')
        + '<div class="mke-grid mke-g2" style="gap:10px">' + fSel('count', manual ? 'Show up to' : 'How many', [1, 2, 3, 4, 5, 6, 7, 8].map(function (n) { return [n, n]; }), p.count) + (t === 'product_grid' ? fSel('columns', 'Columns', [['1', '1'], ['2', '2'], ['3', '3 (2 on a phone)']], p.columns) : '<span></span>') + '</div>'
        + (t === 'product_grid' ? fSel('layout', 'Card style', [['standard', 'Standard cards'], ['playful', 'Playful pastel cards with type stickers']], p.layout) : '')
        + (manual ? '' : fSel('order', 'Order by', Object.keys(o.orders || {}).map(function (k) { return [k, o.orders[k]]; }), p.order))
        + fText('title', 'Small heading above (optional)', p.title, '', false, 100) + fText('cta', 'Button text', p.cta, '', false, 40) + fBool('show_sale', 'Show sale price', p.show_sale)
        + '<div class="mke-h">Other fills: picked by hand · a brand · a category · new in · best sellers · on sale · under a price · bundles &amp; sets.</div>';
    }
    if (t === 'coupon') h += '<p class="d">The code comes from Store → Coupons when the email is sent. An expired or used-up coupon leaves the block out.</p>'
      + fSel('coupon_id', 'Coupon', [['', 'None yet — leave the block out']].concat((o.coupons || []).map(function (c) { return [c.id, c.code + (c.live ? '' : ' (not live)')]; })), p.coupon_id)
      + fText('line', 'Line under the code', p.line, '{top_brand} works here.', false, 160) + fText('expires', 'Small print (blank = the coupon\'s own end date)', p.expires, '', false, 120);
    if (t === 'image') h += fText('src', 'Picture address', p.src, '') + '<button type="button" class="btn ghost sm" data-mke="media" data-k="src" style="margin:-4px 0 12px">Choose from media library</button>' + fText('alt', 'Describe the picture', p.alt) + fText('href', 'Link (optional)', p.href) + fSel('width', 'Width', [['inset', 'Inside the margins'], ['full', 'Full width']], p.width);
    if (t === 'columns') {
      h += fSel('count', 'Columns', [['2', '2'], ['3', '3']], p.count) + fSel('source', 'Fill', [['manual', 'Written here'], ['latest_posts', 'Latest journal posts (when sent)']], p.source) + fText('cta', 'Link text', p.cta, '', false, 40);
      if (p.source === 'manual') {
        var items = p.items || [];
        for (var i = 0; i < parseInt(p.count, 10); i++) {
          var it = items[i] || { image: '', title: '', text: '', href: '' };
          h += '<div class="sec-title" style="margin:10px 0 6px">Column ' + (i + 1) + '</div>'
            + ['title', 'text', 'href', 'image'].map(function (k) { return '<label class="mke-f"><span class="mke-l">' + { title: 'Title', text: 'Text', href: 'Link', image: 'Picture address' }[k] + '</span><input class="mke-in" data-mke-ci="' + i + '" data-k="' + k + '" value="' + esc(it[k] || '') + '"></label>'; }).join('');
        }
      }
    }
    if (t === 'divider') h += '<p class="d">A thin line between sections.</p>';
    if (t === 'spacer') h += fNum('height', 'Height (px)', p.height, 8, 64);
    if (t === 'social') h += '<p class="d">Leave a box empty to hide that link.</p>' + ['instagram', 'facebook', 'tiktok', 'youtube', 'whatsapp'].map(function (k) { return fText(k, k.charAt(0).toUpperCase() + k.slice(1), p[k], ''); }).join('');
    if (t === 'footer') h += '<p class="d">Required, and always last: the Dubai and Korea addresses (Emails → Design &amp; branding), the policy pages, Unsubscribe · Email preferences, why this email arrived, and “View this email in your browser”.</p>' + fSel('why', 'Why they got it', [['auto', 'From the group (customers or subscribers)'], ['customers', 'You bought from us before'], ['subscribers', 'You subscribed to our emails']], p.why)
      + (b.theme === 'playful' ? fText('note', 'Line above the footer (Playful look)', p.note, 'e.g. Made with love (and a lot of serum) in Dubai ✿', false, 120) : '');
    if (t === 'badges') {
      h += '<p class="d">Up to four small chips: a picture, a bold start and the rest of the line. Write only what the shop really does.</p>';
      var chips = p.items || [];
      for (var bi = 0; bi < 4; bi++) {
        var ch = chips[bi] || { icon: 'sparkles', bold: '', text: '' };
        h += '<div class="sec-title" style="margin:10px 0 6px">Chip ' + (bi + 1) + '</div>'
          + '<label class="mke-f"><span class="mke-l">Picture</span><select class="mke-in" data-mke-bi="' + bi + '" data-k="icon">' + Object.keys(BADGE_GLYPH).map(function (k) { return '<option value="' + k + '"' + (k === ch.icon ? ' selected' : '') + '>' + BADGE_GLYPH[k] + ' ' + k + '</option>'; }).join('') + '</select></label>'
          + '<div class="mke-grid mke-g2" style="gap:10px"><label class="mke-f"><span class="mke-l">Bold</span><input class="mke-in" data-mke-bi="' + bi + '" data-k="bold" maxlength="40" value="' + esc(ch.bold || '') + '"></label>'
          + '<label class="mke-f"><span class="mke-l">Then</span><input class="mke-in" data-mke-bi="' + bi + '" data-k="text" maxlength="60" value="' + esc(ch.text || '') + '"></label></div>';
      }
      h += '<div class="mke-h">Empty a chip\'s two boxes to leave it out.</div>';
    }
    if (t !== 'footer' && b.editable) h += '<div class="mke-row" style="margin-top:6px"><button type="button" class="btn ghost sm" data-mke="bdup">Duplicate block</button><span class="mke-sp"></span><button type="button" class="btn ghost sm" data-mke="bdel">Remove block</button></div>';
    return dis + h;
  }

  /*
   * Manual: search and pick, then put them in order (Lane EC). The email
   * prints product_ids in this order; ↑ ↓ and the ⠿ grip move one. Names of
   * products picked in an earlier visit are fetched ONCE for the whole list
   * (GET …/products?ids=), never per row and never per keystroke.
   */
  function pickedBox(p) {
    var ids = p.product_ids || [];
    namesFor(ids);
    var editable = S.b && S.b.editable;
    // The search and its results survive a repaint, so several products can
    // be picked from one search (each pick repaints the panel).
    return '<div class="mke-f"><span class="mke-l">Products (' + ids.length + ' of 12)</span><input class="mke-in" id="mkePsearch" placeholder="Search products…" aria-label="Search products" value="' + esc(S.pq || '') + '">'
      + '<div class="mke-res" id="mkePres">' + (S.presHtml || '') + '</div>'
      + (ids.length ? '<ol class="mke-plist" id="mkePicked" aria-label="Picked products, in email order">' + ids.map(function (id, i) {
        var n = (S.pnames || {})[id] || ('#' + id);
        return '<li data-mke-pi="' + i + '"><span class="dz top"></span><span class="dz bot"></span><button type="button" class="grip" data-mke-pgrip="' + i + '" aria-label="Drag to move"' + (editable ? '' : ' disabled') + '>⠿</button><i>' + (i + 1) + '</i><span class="n">' + esc(n) + '</span>'
          + '<button type="button" data-mke-pup="' + i + '" aria-label="Move up"' + (i === 0 || !editable ? ' disabled' : '') + '>↑</button>'
          + '<button type="button" data-mke-pdown="' + i + '" aria-label="Move down"' + (i === ids.length - 1 || !editable ? ' disabled' : '') + '>↓</button>'
          + '<button type="button" data-mke-unpick="' + id + '" aria-label="Remove"' + (editable ? '' : ' disabled') + '>×</button></li>';
      }).join('') + '</ol><div class="mke-h">The email shows them in this order' + (ids.length > (p.count || 0) ? ' — the first ' + esc(p.count) + ' (Show up to).' : '.') + '</div>' : '<div class="mke-h">Search above and tap a product to add it.</div>')
      + '</div>';
  }
  var nameAsked = {};
  function namesFor(ids) {
    S.pnames = S.pnames || {};
    var want = ids.filter(function (id) { return !S.pnames[id] && !nameAsked[id]; });
    if (!want.length) return;
    want.forEach(function (id) { nameAsked[id] = true; });
    api('GET', '/products?ids=' + want.join(',')).then(function (r) {
      ((r.data && r.data.products) || []).forEach(function (x) { S.pnames[x.id] = (x.brand ? x.brand + ' · ' : '') + x.name; });
      if (S.view === 'builder') paintBuilderKeepFocus();
    }).catch(function () {});
  }
  function movePicked(from, to) {
    change(function (b) {
      var ids = (b.blocks[b.sel].props.product_ids || []).slice();
      if (from < 0 || from >= ids.length) return;
      to = Math.max(0, Math.min(ids.length - 1, to));
      var x = ids.splice(from, 1)[0]; ids.splice(to, 0, x);
      b.blocks[b.sel].props.product_ids = ids;
    });
  }

  function emailForm() {
    var b = S.b, gs = (S.groups && S.groups.groups) || [];
    var len = (b.subject || '').length;
    var dis = b.editable ? '' : ' disabled';
    var h = '<label class="mke-f"><span class="mke-l">Subject</span><input class="mke-in" data-mke-e="subject" maxlength="200" value="' + esc(b.subject) + '"' + dis + '><span class="mke-h">' + len + ' characters' + (len <= 45 ? ' · fits on a phone' : ' · a phone shows about 45') + '. {first_name} and {top_brand} work here.</span></label>'
      + ((b.ideas || []).length && b.editable ? '<div class="mke-ideas" aria-label="Other subject lines">' + b.ideas.map(function (x, i) { return '<button type="button" data-mke-subj="' + i + '">' + esc(x) + '</button>'; }).join('') + '</div>' : '')
      + '<label class="mke-f"><span class="mke-l">Preview line</span><input class="mke-in" data-mke-e="preheader" maxlength="200" value="' + esc(b.preheader) + '"' + dis + '><span class="mke-h">The grey line an inbox shows after the subject.</span></label>'
      + '<div class="mke-grid mke-g2" style="gap:10px"><label class="mke-f"><span class="mke-l">Look</span>' + sel('theme', Object.keys((S.opts && S.opts.themes) || { standard: 'Standard' }).map(function (k) { return [k, S.opts.themes[k]]; }), b.theme, 'data-mke-e="theme"' + dis) + '</label>'
      + '<label class="mke-f"><span class="mke-l">Language</span>' + sel('locale', Object.keys((S.opts && S.opts.locales) || { en: 'English' }).map(function (k) { return [k, S.opts.locales[k]]; }), b.locale, 'data-mke-e="locale"' + dis) + '</label></div>'
      + '<div class="mke-h" style="margin:-4px 0 12px">Playful K-beauty is the “New look” design: pastel cards, highlighter headline, benefit chips. Arabic prints the email right to left; write the words in Arabic.</div>';
    if (b.kind === 'campaign') {
      h += '<label class="mke-f"><span class="mke-l">From name</span><input class="mke-in" data-mke-e="from_name" maxlength="120" value="' + esc(b.from_name) + '" placeholder="K Beauty Bliss"' + dis + '><span class="mke-h">The address is the shop\'s own (Emails → Sending &amp; delivery).</span></label>'
        + '<label class="mke-f"><span class="mke-l">Group</span>' + sel('g', [['', 'Choose on the next step…']].concat(gs.map(function (g) { return [g.id, g.name + ' · ' + (g.audience === 'subscribers' ? 'Subscribers' : 'Customers')]; })), b.segment_id, 'data-mke-e="segment_id"' + dis) + '<span class="mke-h">Fills “This group\'s top brand” and the footer\'s why-line in the preview.</span></label>'
        + '<div class="mke-row"><button type="button" class="btn ghost sm" data-mke="asTpl">Save as my template</button></div>';
    } else {
      h += '<div class="mke-h">Changes to your template save as you type. Campaigns already made from it keep their own copy.</div>';
    }
    return h;
  }

  /* The preview: the server's render, written into a no-script frame. */
  function writeFrame() {
    var f = document.getElementById('mkeFrame'); if (!f || !S.b || !S.b.preview || !S.b.preview.html) return;
    f.srcdoc = S.b.preview.html;
    f.onload = function () { decorateFrame(f); };
  }
  function decorateFrame(f) {
    var doc; try { doc = f.contentDocument; } catch (e) { return; } if (!doc || !doc.head) return;
    var bl = S.b.blocks[S.b.sel];
    var st = doc.createElement('style');
    var label = bl ? (OUTLINE[bl.type] || '') : '';
    st.textContent = 'tbody[data-mkb]{cursor:pointer}tbody[data-mkb]:hover > tr > td{box-shadow:inset 0 0 0 1px rgba(63,111,224,.35)}'
      + 'tbody[data-mkb="' + S.b.sel + '"] > tr > td{box-shadow:inset 2px 0 0 #3f6fe0,inset -2px 0 0 #3f6fe0}'
      + 'tbody[data-mkb="' + S.b.sel + '"] > tr:first-child > td{box-shadow:inset 2px 0 0 #3f6fe0,inset -2px 0 0 #3f6fe0,inset 0 2px 0 #3f6fe0;position:relative}'
      + 'tbody[data-mkb="' + S.b.sel + '"] > tr:last-child > td{box-shadow:inset 2px 0 0 #3f6fe0,inset -2px 0 0 #3f6fe0,inset 0 -2px 0 #3f6fe0}'
      + 'tbody[data-mkb="' + S.b.sel + '"] > tr:only-child > td{box-shadow:inset 0 0 0 2px #3f6fe0}'
      + 'tbody[data-mkb="' + S.b.sel + '"] > tr:first-child > td::before{content:' + JSON.stringify(label) + ';position:absolute;top:0;left:0;z-index:3;background:#3f6fe0;color:#fff;font:700 11px/1 -apple-system,Segoe UI,Roboto,sans-serif;padding:5px 8px;border-radius:0 0 7px 0}';
    doc.head.appendChild(st);
    doc.addEventListener('click', function (e) {
      e.preventDefault();
      var t = e.target.closest && e.target.closest('tbody[data-mkb]');
      if (t) { S.b.sel = parseInt(t.getAttribute('data-mkb'), 10); S.b.right = 'block'; paintBuilder(); }
    });
  }

  var refresh = debounce(async function () {
    var b = S.b; if (!b || S.view !== 'builder') return;
    try {
      var r = await api('POST', '/preview', { blocks: b.blocks, subject: b.subject, preheader: b.preheader, segment_id: b.segment_id, theme: b.theme, locale: b.locale });
      b.preview = r.data;
      if (b.preview && b.preview.top_brand === undefined) b.preview.top_brand = null;
    } catch (e) { toastMsg(why(e)); return; }
    if (S.view === 'builder') {
      var meter = document.querySelector('.mke-meter');
      paintBuilderKeepFocus();
    }
  }, 450);

  function paintBuilderKeepFocus() {
    var a = document.activeElement, key = a && (a.getAttribute('data-mke-p') || a.getAttribute('data-mke-e') || a.id), pos = a && a.selectionStart;
    paintBuilder();
    if (!key) return;
    var el = document.querySelector('[data-mke-p="' + key + '"],[data-mke-e="' + key + '"]') || document.getElementById(key);
    if (el) { el.focus(); try { if (pos != null) el.setSelectionRange(pos, pos); } catch (e) {} }
  }

  var save = debounce(async function () {
    var b = S.b; if (!b || !b.editable) return;
    var body = { name: b.name, subject: b.subject, preheader: b.preheader, blocks: b.blocks, theme: b.theme, locale: b.locale };
    if (b.kind === 'campaign') { body.from_name = b.from_name; body.segment_id = b.segment_id; }
    try {
      var r = await api('PUT', (b.kind === 'template' ? '/templates/' : '/campaigns/') + b.id, body);
      if (!r.ok) { toastMsg(refusal(r.data)); b.dirty = false; return; }
      b.dirty = false; b.saved = new Date().toISOString();
      var pill = document.querySelector('.mke-row .pill'); if (pill) { pill.className = 'pill grey'; pill.textContent = 'Draft · saved ' + clock(b.saved); }
    } catch (e) { toastMsg(why(e)); }
  }, 900);

  function change(mut, repaint) {
    var b = S.b; if (!b || !b.editable) return;
    b.past.push(JSON.stringify(b.blocks)); if (b.past.length > 60) b.past.shift(); b.future = [];
    mut(b);
    b.dirty = true;
    if (repaint !== false) paintBuilderKeepFocus();
    save(); refresh();
  }

  function addBlock(type, at) {
    if (type === 'footer') return;
    change(function (b) {
      var i = at == null ? Math.min(b.sel + 1, b.blocks.length - 1) : at;
      if (type === 'mini_header') { if (b.blocks.some(function (x) { return x.type === 'mini_header'; })) { toastMsg('The email already has its header.'); return; } i = 0; }
      i = Math.max(b.blocks[0] && b.blocks[0].type === 'mini_header' && type !== 'mini_header' ? 1 : 0, Math.min(i, b.blocks.length - 1));
      b.blocks.splice(i, 0, { type: type, props: clone(DEFAULTS[type] || {}) });
      b.sel = i; b.right = 'block';
    });
  }
  function move(from, to) {
    var b = S.b; if (!b) return;
    var last = b.blocks.length - 1;
    if (from === to || from < 0 || b.blocks[from].type === 'footer') return;
    to = Math.max(0, Math.min(to, last - 1));
    if (b.blocks[0].type === 'mini_header' && from !== 0) to = Math.max(1, to);
    if (b.blocks[from].type === 'mini_header') to = 0;
    change(function (b) { var x = b.blocks.splice(from, 1)[0]; b.blocks.splice(to, 0, x); b.sel = to; });
  }

  /* ----------------------------------------------------------- drag & drop */

  var drag = null;
  function startDrag(e, kind, value, label) {
    if (!S.b || !S.b.editable) return;
    e.preventDefault();
    try { if (e.target.releasePointerCapture) e.target.releasePointerCapture(e.pointerId); } catch (x) {}
    drag = { kind: kind, value: value, moved: false, x: e.clientX, y: e.clientY, target: null, ghost: null, label: label };
  }
  function overDrag(e) {
    if (!drag) return;
    if (!drag.moved && Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 6) return;
    if (!drag.moved) {
      drag.moved = true;
      var w = document.querySelector('[data-mke]'); if (w) w.classList.add('mke-dragging');
      if (drag.kind === 'new' && S.b.left !== 'order') { /* keep palette visible; the canvas is the target */ }
      drag.ghost = document.createElement('div'); drag.ghost.className = 'mke-ghost'; drag.ghost.textContent = drag.label; document.body.appendChild(drag.ghost);
    }
    drag.ghost.style.transform = 'translate(' + (e.clientX + 12) + 'px,' + (e.clientY + 12) + 'px)';
    document.querySelectorAll('.drop-before,.drop-after,.drop-on').forEach(function (n) { n.classList.remove('drop-before', 'drop-after', 'drop-on'); });
    var t = e.target, zone = t.closest && t.closest('.dz'), row = t.closest && t.closest('[data-mke-i]'), canvas = t.closest && t.closest('#mkeCanvas');
    drag.target = null;
    if (drag.kind === 'pmove') {
      var li = t.closest && t.closest('[data-mke-pi]');
      if (li) {
        var at = parseInt(li.getAttribute('data-mke-pi'), 10);
        var below = !!(zone && zone.classList.contains('bot'));
        li.classList.add(below ? 'drop-after' : 'drop-before');
        drag.target = { at: below ? at + 1 : at };
      }
      return;
    }
    if (row) {
      var i = parseInt(row.getAttribute('data-mke-i'), 10), after = zone ? zone.classList.contains('bot') : true;
      row.classList.add(after ? 'drop-after' : 'drop-before');
      drag.target = { at: after ? i + 1 : i };
    } else if (canvas) {
      canvas.classList.add('drop-on');
      drag.target = { at: null };
    }
  }
  function endDrag() {
    if (!drag) return;
    var d = drag; drag = null;
    if (d.ghost) d.ghost.remove();
    var w = document.querySelector('[data-mke]'); if (w) w.classList.remove('mke-dragging');
    document.querySelectorAll('.drop-before,.drop-after,.drop-on').forEach(function (n) { n.classList.remove('drop-before', 'drop-after', 'drop-on'); });
    if (!d.moved) { if (d.kind === 'new') addBlock(d.value); return; }
    if (d.kind === 'pmove' && !d.target) return;
    if (!d.target) return;
    if (d.kind === 'pmove') { movePicked(d.value, d.target.at > d.value ? d.target.at - 1 : d.target.at); return; }
    if (d.kind === 'new') addBlock(d.value, d.target.at);
    else { var to = d.target.at == null ? S.b.blocks.length - 2 : (d.target.at > d.value ? d.target.at - 1 : d.target.at); move(d.value, to); }
  }

  /* ---------------------------------------------------------------- review */

  async function openReview(id) {
    S.view = 'review';
    shell('<div class="mke-empty">Opening Review &amp; send…</div>');
    setTitle('Review & send');
    try {
      if (!S.groups) S.groups = (await api('GET', '/groups')).data;
      var d = (await api('GET', '/campaigns/' + id + '/review')).data;
      var prev = S.rv && S.rv.id === id ? S.rv : null;
      S.rv = { id: id, d: d, when: prev ? prev.when : (d.campaign.status === 'scheduled' ? 'schedule' : 'now'), progress: prev ? prev.progress : null, msg: '', typing: prev ? prev.typing : false, testTo: prev ? prev.testTo : d.admin_email };
      if (['sending', 'paused', 'sent', 'cancelled'].indexOf(d.campaign.status) !== -1) {
        S.rv.progress = (await api('GET', '/campaigns/' + id + '/progress')).data.progress;
      }
      paintReview();
      if (S.rv.progress && S.rv.progress.status === 'sending' && d.can_send) driveA();
    } catch (e) { shell('<div class="mke-note">' + esc(why(e)) + '</div><button class="btn ghost" data-mke="home">Back</button>'); }
  }

  function paintReview() {
    var R = S.rv, d = R.d, c = d.campaign, ck = d.checks, n = d.count;
    setTitle('Review & send');
    var gs = (S.groups && S.groups.groups) || [];
    var locked = !c.editable;
    var steps = tabBar('steps', [['design', '1 · Design ✓'], ['customers', '2 · Customers' + (d.group ? ' ✓' : '')], ['send', '3 · Send']], 'send', 'Steps');
    var head = '<button type="button" class="mke-back" data-mke="home">← Campaigns</button><div class="page-head"><h2>Review &amp; send · ' + esc(c.name) + '</h2><p>Step 3 of 3 — design ✓ · customers ' + (d.group ? '✓' : '—') + ' · send</p></div>' + steps;
    var len = (c.subject || '').length;
    var inbox = '<div class="mke-card"><h3>In the inbox</h3><div class="mke-inbox"><div class="from">' + esc(c.from_name || 'K Beauty Bliss') + '</div><div class="subj"><b>' + esc(c.subject || '(no subject yet)') + '</b></div><div class="pre">' + esc(c.preheader || '') + '</div></div><div style="height:12px"></div>'
      + '<label class="mke-f"><span class="mke-l">Subject</span><input class="mke-in" data-mke-rs="subject" maxlength="200" value="' + esc(c.subject) + '"' + (locked ? ' disabled' : '') + '><span class="mke-h">' + len + ' characters' + (len <= 45 ? ' · fits on a phone' : ' · a phone shows about 45') + '</span></label>'
      + '<label class="mke-f"><span class="mke-l">Preview line</span><input class="mke-in" data-mke-rs="preheader" maxlength="200" value="' + esc(c.preheader) + '"' + (locked ? ' disabled' : '') + '></label></div>';
    var reasons = n ? Object.keys(n.reasons || {}).filter(function (k) { return n.reasons[k]; }).map(function (k) { return num(n.reasons[k]) + ' ' + k; }) : [];
    var who = '<div class="sec-title">Who</div><div class="mke-card"><div class="mke-row">'
      + (locked ? '<b>' + esc(d.group ? d.group.name : '—') + '</b>' : sel('g', [['', 'Choose a customer group…']].concat(gs.map(function (g) { return [g.id, g.name]; })), c.segment_id, 'data-mke-rs="segment_id" style="width:auto;max-width:100%" aria-label="Customer group"'))
      + (d.group ? '<span class="pill grey">' + (d.group.audience === 'subscribers' ? 'Subscribers' : 'Customers') + '</span><span class="mke-sp"></span><span class="pill green">' + num(n.emailable) + ' will receive it</span>' : '') + '</div>'
      + '<div class="mke-h">Left out automatically: unsubscribed, bounced before, unconfirmed sign-ups' + (reasons.length ? ' (' + esc(reasons.join(' · ')) + ')' : '') + '.</div></div>';
    var today = new Date(); today.setDate(today.getDate() + 1);
    var sd = c.scheduled_at ? new Date(c.scheduled_at) : today;
    var dateV = sd.toISOString().slice(0, 10), timeV = c.scheduled_at ? ('0' + sd.getHours()).slice(-2) + ':' + ('0' + sd.getMinutes()).slice(-2) : '19:00';
    var whenCard = '<div class="sec-title">When</div><div class="mke-card">' + tabBar('when', [['now', 'Send now'], ['schedule', 'Schedule']], R.when, 'When')
      + tabPanel('when', R.when, (R.when === 'schedule' ? '<div class="mke-grid mke-g2"><label class="mke-f"><span class="mke-l">Date</span><input class="mke-in" type="date" id="mkeSd" value="' + esc(dateV) + '"></label><label class="mke-f"><span class="mke-l">Time (Dubai)</span><input class="mke-in" type="time" id="mkeSt" value="' + esc(timeV) + '"></label></div>' : '')
      + '<div class="mke-h">Goes out at ' + num(d.limits.per_minute) + ' a minute' + (d.minutes ? ' — about ' + num(d.minutes) + ' minute' + (d.minutes === 1 ? '' : 's') + ' for this group' : '') + ', at most ' + num(d.limits.per_day) + ' a day. '
      + (d.cron.alive ? 'The scheduler is running, so it carries on if you close this page.' : 'Scheduled sends need the one cron line; without it, keep this page open and it sends from here.') + '</div>'
      + (R.when === 'schedule' ? cronNote(d.cron, true).replace('mke-note', 'mke-note" style="margin:10px 0 0') : '')) + '</div>';

    var checks = [];
    checks.push((ck.unsubscribe ? '✅' : '❌') + ' Unsubscribe link and one-click unsubscribe');
    checks.push((ck.addresses.length === 2 ? '✅' : ck.addresses.length ? '⚠️' : '❌') + (ck.addresses.length === 2 ? ' Dubai and Korea addresses in the footer' : ' ' + (ck.addresses.length ? 'Only the ' + esc(ck.addresses.join(' and ')) + ' address' : 'No postal address — it cannot be sent until one is') + ' in the footer — fill both in Emails → Design &amp; branding'));
    checks.push(ck.test ? '✅ Test sent to ' + esc(ck.test.to) + ' at ' + esc(clock(ck.test.at)) : '⚠️ No test sent yet');
    (ck.fills || []).forEach(function (f) {
      if (f.kind === 'products') checks.push((f.count ? '✅' : '⚠️') + ' Products filled: ' + (d.top_brand && f.fill === 'group_top_brand' ? esc(d.top_brand.name) + ', ' : '') + num(f.count) + ' in stock' + (f.count < f.wanted ? ' (of ' + num(f.wanted) + ' wanted)' : ''));
      if (f.kind === 'coupon') checks.push(f.ok ? '✅ Coupon ' + esc(f.code) + ' exists' + (f.restricted ? ' — ⚠️ limited to certain addresses' : '') : '⚠️ No live coupon chosen — the coupon block will be left out');
    });
    checks.push((ck.size_ok ? '✅' : '❌') + ' Size ' + kb(d.bytes) + ' (Gmail cuts at 102 KB)');
    if (!ck.subject) checks.push('❌ Write a subject line');
    (ck.errors || []).forEach(function (e) { checks.push('❌ ' + esc(e)); });

    var canGo = d.can_send && d.group && n && n.emailable > 0 && ck.subject && ck.size_ok && ck.addresses.length > 0 && !(ck.errors || []).length;
    var schedLabel = 'Schedule for ' + (function () { try { var x = new Date(dateV + 'T' + timeV); return x.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ', ' + timeV; } catch (e) { return ''; } })();
    var primary = R.when === 'schedule' ? schedLabel : 'Send now to ' + (n ? num(n.emailable) : '…');
    var progress = R.progress;
    var sendArea;
    if (progress && progress.status !== 'draft' && progress.status !== 'scheduled') {
      var pct = progress.recipients ? Math.round((progress.sent + progress.failed + progress.skipped) * 100 / progress.recipients) : 0;
      sendArea = '<div class="mke-row"><b>' + esc((STATUS[progress.status] || ['', progress.status])[1]) + '</b><span class="mke-sp"></span><span class="mke-h" style="margin:0">' + num(progress.sent) + ' sent · ' + num(progress.failed) + ' refused · ' + num(progress.pending) + ' to go</span></div>'
        + '<div class="mke-bar" style="margin:10px 0" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '" aria-label="Sending progress"><i style="width:' + pct + '%"></i></div>'
        + (progress.building ? '<div class="mke-h">Writing the list…</div>' : '')
        + (progress.status === 'sending' ? '<div class="mke-h">' + (d.cron.alive ? 'The scheduler is sending it; you can close this page.' : 'Sending from this page — keep it open until it says Sent.') + (R.wait ? ' ' + esc(R.wait) : '') + '</div>' : '')
        + (d.can_send && (progress.status === 'sending' || progress.status === 'paused') ? '<div class="mke-row" style="margin-top:10px">' + (progress.status === 'sending' ? '<button type="button" class="btn ghost sm" data-mke="pause">Pause</button>' : '<button type="button" class="btn sm" data-mke="resume">Resume</button>') + '<button type="button" class="btn ghost sm" data-mke="cancel">Cancel the rest</button><span class="mke-sp"></span><button type="button" class="btn ghost sm" data-mke="report" data-id="' + c.id + '">Report</button></div>' : '')
        + (progress.status === 'sent' || progress.status === 'cancelled' ? '<div class="mke-row" style="margin-top:10px"><span class="mke-sp"></span><button type="button" class="btn sm" data-mke="report" data-id="' + c.id + '">See the report</button></div>' : '');
    } else {
      sendArea = '<div class="mke-row" style="margin-top:12px"><input class="mke-in" id="mkeTestTo" style="width:auto;flex:1;min-width:160px" value="' + esc(R.testTo || '') + '" aria-label="Send the test to"><button type="button" class="btn ghost" data-mke="test">Send me a test</button></div>'
        + '<div class="mke-row" style="margin-top:12px"><span class="mke-sp"></span>' + (c.status === 'scheduled' && d.can_send ? '<button type="button" class="btn ghost" data-mke="unsched">Unschedule</button>' : '')
        + '<button type="button" class="btn" data-mke="go"' + (canGo ? '' : ' disabled') + '>' + esc(primary) + '</button></div>'
        + (R.typing ? '<div class="mke-typed"><label for="mkeConfirm" class="mke-l" style="margin:0">Type <b>' + num(n.emailable) + '</b> to confirm:</label><input class="mke-in" id="mkeConfirm" inputmode="numeric" autocomplete="off"><button type="button" class="btn" data-mke="confirm">' + (R.when === 'schedule' ? 'Schedule' : 'Send to ' + num(n.emailable)) + '</button></div>' : '')
        + (c.status === 'scheduled' ? '<div class="mke-h" style="margin-top:8px">Scheduled for ' + esc(when(c.scheduled_at)) + '.</div>' : '');
    }
    var before = '<div class="mke-card"><h3>Before it goes</h3><ul class="mke-check">' + checks.map(function (x) { return '<li>' + x + '</li>'; }).join('') + '</ul>' + sendArea
      + (R.msg ? '<div class="' + (R.msgOk ? 'mke-h' : 'mke-err') + '">' + esc(R.msg) + '</div>' : '')
      + '<div class="mke-h" style="margin-top:10px">🔒 Only <b>Owner and Manager (Administrator)</b> can send or schedule a campaign.</div></div>';
    var phone = '<div style="height:12px"></div><div class="mke-phone"><iframe class="mke-frame" id="mkeRframe" title="The email on a phone" sandbox="" style="height:' + estHeight(c.blocks, true) + 'px"></iframe></div>';
    shell(head + tabPanel('steps', 'send', '<div class="mke-split"><div>' + inbox + who + whenCard + '</div><div>' + before + phone + '</div></div>'));
    var f = document.getElementById('mkeRframe'); if (f) f.srcdoc = d.html || '';
  }

  var driving = false;
  async function driveA() {
    if (driving) return; driving = true;
    try {
      while (S.view === 'review' && S.rv && S.rv.progress && S.rv.progress.status === 'sending') {
        var r = await api('POST', '/campaigns/' + S.rv.id + '/step');
        var p = r.data.progress || {};
        S.rv.progress = p;
        S.rv.wait = p.room && p.room.allowed === 0 ? (p.room.day_left === 0 ? 'Today\'s limit is reached — it carries on after midnight.' : 'Waiting for the per-minute limit…') : '';
        paintReviewProgress();
        if (p.done || p.status !== 'sending') break;
        await new Promise(function (res) { setTimeout(res, p.room && p.room.allowed === 0 ? 10000 : 1200); });
      }
    } catch (e) { toastMsg(why(e)); }
    driving = false;
    if (S.view === 'review' && S.rv && S.rv.progress && S.rv.progress.done) { S.overview = null; S.reports = null; }
  }
  function paintReviewProgress() { var a = document.activeElement; paintReview(); if (a && a.id) { var el = document.getElementById(a.id); if (el) el.focus(); } }

  /* ---------------------------------------------------------------- events */

  document.addEventListener('click', async function (e) {
    if (!document.querySelector('[data-mke]')) return;
    var t = e.target;
    var tab = t.closest && t.closest('[role="tab"][data-mke-tab]');
    if (tab) { var bar = tab.closest('[data-mke-tabs]'); e.preventDefault(); selectTab(bar.getAttribute('data-mke-tabs'), tab.getAttribute('data-mke-tab'), false); return; }
    var a = t.closest && t.closest('[data-mke]');
    var act = a && a.getAttribute('data-mke');
    var id = a && parseInt(a.getAttribute('data-id') || '0', 10);
    if (t.closest && t.closest('[data-mke-stop]')) return;

    var gRow = t.closest && t.closest('[data-mke-g]');
    if (gRow && !act) {
      var g = (S.groups.groups || []).filter(function (x) { return String(x.id) === gRow.getAttribute('data-mke-g'); })[0];
      if (g) { S.g.editing = g.preset ? null : g.id; S.g.name = g.preset ? g.name + ' (copy)' : g.name; S.g.match = g.match; S.g.rules = clone(g.rules); S.g.people = null; paint(); recount(); }
      return;
    }
    var rRow = t.closest && t.closest('[data-mke-r]');
    if (rRow) return openReport(parseInt(rRow.getAttribute('data-mke-r'), 10));
    var pick = t.closest && t.closest('[data-mke-pick]');
    if (pick) { S.b.sel = parseInt(pick.getAttribute('data-mke-pick'), 10); S.b.right = 'block'; paintBuilder(); return; }
    var up = t.closest && t.closest('[data-mke-up]');
    if (up) { var i = parseInt(up.getAttribute('data-mke-up'), 10); move(i, i - 1); return; }
    var down = t.closest && t.closest('[data-mke-down]');
    if (down) { var j = parseInt(down.getAttribute('data-mke-down'), 10); move(j, j + 1); return; }
    var rx = t.closest && t.closest('[data-mke-rx]');
    if (rx) { S.g.rules.splice(parseInt(rx.getAttribute('data-mke-rx'), 10), 1); paint(); recount(); return; }
    var fm = t.closest && t.closest('[data-mke-fillmode]');
    if (fm && S.b) {
      var wantManual = fm.getAttribute('data-mke-fillmode') === 'manual';
      change(function (b) {
        var p = b.blocks[b.sel].props;
        if (wantManual && p.fill !== 'hand_picked') { S.lastAuto = S.lastAuto || {}; S.lastAuto[b.sel] = p.fill; p.fill = 'hand_picked'; }
        else if (!wantManual && p.fill === 'hand_picked') { p.fill = (S.lastAuto && S.lastAuto[b.sel]) || 'on_sale'; }
      });
      return;
    }
    var pup = t.closest && t.closest('[data-mke-pup]');
    if (pup) { var pi = parseInt(pup.getAttribute('data-mke-pup'), 10); movePicked(pi, pi - 1); return; }
    var pdown = t.closest && t.closest('[data-mke-pdown]');
    if (pdown) { var pj = parseInt(pdown.getAttribute('data-mke-pdown'), 10); movePicked(pj, pj + 1); return; }
    var subj = t.closest && t.closest('[data-mke-subj]');
    if (subj && S.b && S.b.editable) { S.b.subject = S.b.ideas[parseInt(subj.getAttribute('data-mke-subj'), 10)] || S.b.subject; S.b.dirty = true; paintBuilderKeepFocus(); save(); refresh(); return; }
    var unpick = t.closest && t.closest('[data-mke-unpick]');
    if (unpick) { var pid = parseInt(unpick.getAttribute('data-mke-unpick'), 10); change(function (b) { var p = b.blocks[b.sel].props; p.product_ids = (p.product_ids || []).filter(function (x) { return x !== pid; }); }); return; }
    var pr = t.closest && t.closest('[data-mke-pr]');
    if (pr) { var nid = parseInt(pr.getAttribute('data-mke-pr'), 10); S.pnames = S.pnames || {}; S.pnames[nid] = pr.getAttribute('data-name'); change(function (b) { var p = b.blocks[b.sel].props; p.product_ids = (p.product_ids || []).filter(function (x) { return x !== nid; }).concat([nid]).slice(0, 12); }); return; }
    if (!act) return;
    e.preventDefault();

    try {
      if (act === 'home') { S.view = 'tabs'; S.b = null; S.overview = null; S.templates = null; paint(); }
      else if (act === 'new') { S.tab = 'templates'; S.tplTab = 'ready'; paint(); }
      else if (act === 'reports') { S.report = null; S.reports = null; paint(); }
      else if (act === 'edit') openBuilder('campaign', id);
      else if (act === 'review') openReview(id);
      else if (act === 'report') openReport(id);
      else if (act === 'dup') { var r1 = await api('POST', '/campaigns/' + id + '/duplicate'); if (r1.ok) openBuilder('campaign', r1.data.campaign.id); }
      else if (act === 'del') { if (!window.confirm('Delete this campaign? This cannot be undone.')) return; var r2 = await api('DELETE', '/campaigns/' + id); if (!r2.ok) toastMsg(refusal(r2.data)); S.overview = null; paint(); }
      else if (act === 'limits') {
        var r3 = await api('POST', '/limits', { per_minute: parseInt(document.getElementById('mkeRate').value, 10), per_day: parseInt(document.getElementById('mkeCap').value, 10) });
        toastMsg(r3.ok ? 'Sending limits saved.' : refusal(r3.data)); if (r3.ok) { S.overview.limits = r3.data.limits; paint(); }
      }
      else if (act === 'use' || act === 'blank') { var r4 = await api('POST', '/campaigns', act === 'use' ? { template_id: id } : {}); if (r4.ok) openBuilder('campaign', r4.data.campaign.id); else toastMsg(refusal(r4.data)); }
      else if (act === 'tuse') { var r4b = await api('POST', '/campaigns', { template_id: id }); if (r4b.ok) openBuilder('campaign', r4b.data.campaign.id); }
      else if (act === 'tdup') { var r5 = await api('POST', '/templates/' + id + '/duplicate'); S.templates = null; if (r5.ok) { S.tplTab = 'mine'; openBuilder('template', r5.data.template.id); } }
      else if (act === 'tedit') openBuilder('template', id);
      else if (act === 'tdel') { if (!window.confirm('Delete this template?')) return; await api('DELETE', '/templates/' + id); S.templates = null; paint(); }
      else if (act === 'radd') { var f = fieldsFor(S.g.audience)[0]; S.g.rules.push({ field: f.key, op: f.ops[0].op, value: blankValue(f.ops[0].kind) }); paint(); recount(); }
      else if (act === 'see') people(1);
      else if (act === 'pprev') people(Math.max(1, S.g.page - 1));
      else if (act === 'pnext') people(S.g.page + 1);
      else if (act === 'period') { var pr2 = applyPeriod(); var k = -1; S.g.rules.forEach(function (x, i) { if (x.field === 'order_date') k = i; }); if (k === -1) S.g.rules.push(pr2); else S.g.rules[k] = pr2; paint(); recount(); }
      else if (act === 'gnew') { S.g.editing = null; S.g.name = ''; S.g.rules = []; S.g.people = null; paint(); recount(); }
      else if (act === 'gsave') {
        var body = { name: S.g.name, audience: S.g.audience, match: S.g.match, rules: S.g.rules };
        var r6 = S.g.editing ? await api('PUT', '/groups/' + S.g.editing, body) : await api('POST', '/groups', body);
        if (!r6.ok) { S.g.err = refusal(r6.data); paint(); return; }
        S.g.editing = r6.data.id; S.g.err = ''; S.groups = (await api('GET', '/groups')).data; toastMsg('Group saved.'); paint();
      }
      else if (act === 'gdel') { if (!window.confirm('Delete this group?')) return; var r7 = await api('DELETE', '/groups/' + id); if (!r7.ok) toastMsg(refusal(r7.data)); S.groups = (await api('GET', '/groups')).data; paint(); }
      else if (act === 'undo' && S.b.past.length) { S.b.future.push(JSON.stringify(S.b.blocks)); S.b.blocks = JSON.parse(S.b.past.pop()); S.b.sel = Math.min(S.b.sel, S.b.blocks.length - 1); S.b.dirty = true; paintBuilder(); save(); refresh(); }
      else if (act === 'redo' && S.b.future.length) { S.b.past.push(JSON.stringify(S.b.blocks)); S.b.blocks = JSON.parse(S.b.future.pop()); S.b.sel = Math.min(S.b.sel, S.b.blocks.length - 1); S.b.dirty = true; paintBuilder(); save(); refresh(); }
      else if (act === 'bdel') { change(function (b) { if (b.blocks[b.sel].type === 'footer') return; b.blocks.splice(b.sel, 1); b.sel = Math.max(0, b.sel - 1); }); }
      else if (act === 'bdup') { change(function (b) { var x = clone(b.blocks[b.sel]); if (x.type === 'mini_header' || x.type === 'footer') return; b.blocks.splice(b.sel + 1, 0, x); b.sel++; }); }
      else if (act === 'media') {
        if (typeof window.kbbPickMedia !== 'function') { toastMsg('The media picker is not loaded on this page.'); return; }
        var key = a.getAttribute('data-k');
        window.kbbPickMedia({ title: 'Picture for the email', note: 'JPG or PNG. Shown 600 pixels wide.', upload: false, onPick: function (urls) { var u = (urls && urls[0]) || ''; if (u) change(function (b) { b.blocks[b.sel].props[key] = u; }); } });
      }
      else if (act === 'next') { await flushSave(); openReview(S.b.id); }
      else if (act === 'btest') { await flushSave(); openReview(S.b.id); }
      else if (act === 'asTpl') {
        var name = window.prompt('Name for your template', S.b.name); if (!name) return;
        var r8 = await api('POST', '/templates', { name: name, subject: S.b.subject, preheader: S.b.preheader, blocks: S.b.blocks, theme: S.b.theme, locale: S.b.locale });
        toastMsg(r8.ok ? 'Saved under Templates → My templates.' : refusal(r8.data)); S.templates = null;
      }
      else if (act === 'test') {
        S.rv.testTo = (document.getElementById('mkeTestTo') || {}).value || '';
        var r9 = await api('POST', '/campaigns/' + S.rv.id + '/test', { to: S.rv.testTo });
        S.rv.msg = r9.ok ? r9.data.message : refusal(r9.data); S.rv.msgOk = r9.ok;
        if (r9.ok) S.rv.d.checks.test = { to: S.rv.testTo, at: new Date().toISOString() };
        paintReview();
      }
      else if (act === 'go') { S.rv.typing = true; S.rv.msg = ''; paintReview(); var cf = document.getElementById('mkeConfirm'); if (cf) cf.focus(); }
      else if (act === 'confirm') {
        var typed = (document.getElementById('mkeConfirm') || {}).value || '';
        var r10;
        if (S.rv.when === 'schedule') r10 = await api('POST', '/campaigns/' + S.rv.id + '/schedule', { confirm: typed, date: document.getElementById('mkeSd').value, time: document.getElementById('mkeSt').value });
        else r10 = await api('POST', '/campaigns/' + S.rv.id + '/send', { confirm: typed });
        if (!r10.ok) { S.rv.msg = refusal(r10.data); S.rv.msgOk = false; paintReview(); return; }
        S.rv.typing = false; S.overview = null;
        if (S.rv.when === 'schedule') { S.rv.msg = 'Scheduled.'; S.rv.msgOk = true; openReview(S.rv.id); }
        else { S.rv.progress = r10.data.progress; paintReview(); driveA(); }
      }
      else if (act === 'unsched') { await api('POST', '/campaigns/' + S.rv.id + '/unschedule'); openReview(S.rv.id); }
      else if (act === 'pause' || act === 'resume' || act === 'cancel') {
        if (act === 'cancel' && !window.confirm('Stop sending? Nobody else in the group will get it.')) return;
        var r11 = await api('POST', '/campaigns/' + S.rv.id + '/' + act);
        S.rv.progress = r11.data.progress; paintReview(); if (act === 'resume') driveA();
      }
    } catch (err) { toastMsg(why(err)); }
  });

  async function flushSave() {
    var b = S.b; if (!b || !b.editable) return;
    var body = { name: b.name, subject: b.subject, preheader: b.preheader, blocks: b.blocks, theme: b.theme, locale: b.locale };
    if (b.kind === 'campaign') { body.from_name = b.from_name; body.segment_id = b.segment_id; }
    var r = await api('PUT', '/campaigns/' + b.id, body);
    if (!r.ok) toastMsg(refusal(r.data));
  }

  document.addEventListener('keydown', function (e) {
    var t = e.target.closest && e.target.closest('[role="tab"][data-mke-tab]');
    if (!t) return;
    var bar = t.closest('[data-mke-tabs]'); if (!bar) return;
    var tabs = Array.prototype.slice.call(bar.querySelectorAll('[role="tab"]'));
    var i = tabs.indexOf(t), n = -1, rtl = document.documentElement.dir === 'rtl';
    if (e.key === 'ArrowRight') n = rtl ? i - 1 : i + 1;
    else if (e.key === 'ArrowLeft') n = rtl ? i + 1 : i - 1;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = tabs.length - 1;
    else return;
    e.preventDefault();
    n = (n + tabs.length) % tabs.length;
    selectTab(bar.getAttribute('data-mke-tabs'), tabs[n].getAttribute('data-mke-tab'), true);
  });

  document.addEventListener('input', function (e) {
    if (!document.querySelector('[data-mke]')) return;
    var t = e.target;
    if (t.id === 'mkeGname') { S.g.name = t.value; return; }
    if (t.id === 'mkeBname') { S.b.name = t.value; S.b.dirty = true; save(); return; }
    if (t.hasAttribute('data-mke-p') && S.b) {
      var k = t.getAttribute('data-mke-p');
      if (t.type === 'checkbox' || t.tagName === 'SELECT') return;
      var v = t.type === 'number' ? (t.value === '' ? '' : parseInt(t.value, 10)) : t.value;
      change(function (b) { b.blocks[b.sel].props[k] = v; }, false);
      return;
    }
    if (t.hasAttribute('data-mke-bi') && S.b && t.tagName !== 'SELECT') {
      var bi = parseInt(t.getAttribute('data-mke-bi'), 10), bk = t.getAttribute('data-k');
      change(function (b) { var p = b.blocks[b.sel].props; p.items = p.items || []; while (p.items.length <= bi) p.items.push({ icon: 'sparkles', bold: '', text: '' }); p.items[bi][bk] = t.value; }, false);
      return;
    }
    if (t.hasAttribute('data-mke-ci') && S.b) {
      var ci = parseInt(t.getAttribute('data-mke-ci'), 10), ck = t.getAttribute('data-k');
      change(function (b) { var p = b.blocks[b.sel].props; p.items = p.items || []; while (p.items.length <= ci) p.items.push({ image: '', title: '', text: '', href: '' }); p.items[ci][ck] = t.value; }, false);
      return;
    }
    if (t.hasAttribute('data-mke-e') && S.b && t.tagName !== 'SELECT') { S.b[t.getAttribute('data-mke-e')] = t.value; S.b.dirty = true; save(); refresh(); return; }
    if (t.hasAttribute('data-mke-rv')) {
      var ri = parseInt(t.getAttribute('data-mke-rv'), 10), rule = S.g.rules[ri], part = t.getAttribute('data-part');
      var val = t.type === 'number' ? (t.value === '' ? '' : Number(t.value)) : t.value;
      if (part !== null) { rule.value = Array.isArray(rule.value) ? rule.value : ['', '']; rule.value[parseInt(part, 10)] = val; } else rule.value = val;
      recount(); return;
    }
    if (t.id === 'mkePsearch') psearch(t.value);
  });

  var psearch = debounce(async function (q) {
    try {
      var r = await api('GET', '/products?q=' + encodeURIComponent(q));
      var box = document.getElementById('mkePres'); if (!box) return;
      S.pq = q;
      box.innerHTML = S.presHtml = (r.data.products || []).map(function (p) {
        return '<button type="button" data-mke-pr="' + p.id + '" data-name="' + esc(p.name) + '">' + (p.img ? '<img src="' + esc(p.img) + '" alt="">' : '') + '<span>' + esc(p.brand ? p.brand + ' · ' : '') + esc(p.name) + (p.live ? '' : ' <i>(not live)</i>') + '</span></button>';
      }).join('') || '<div class="mke-h">Nothing found.</div>';
    } catch (e) {}
  }, 300);

  document.addEventListener('change', async function (e) {
    if (!document.querySelector('[data-mke]')) return;
    var t = e.target;
    if (t.id === 'mkeMatch') { S.g.match = t.value; recount(); return; }
    if (t.hasAttribute('data-mke-rf')) { var i = parseInt(t.getAttribute('data-mke-rf'), 10), f = fieldDef(t.value); S.g.rules[i] = { field: f.key, op: f.ops[0].op, value: blankValue(f.ops[0].kind) }; paint(); recount(); return; }
    if (t.hasAttribute('data-mke-ro')) { var j = parseInt(t.getAttribute('data-mke-ro'), 10), r = S.g.rules[j], o = opDef(r.field, t.value), prev = opDef(r.field, r.op); r.op = t.value; if (!prev || prev.kind !== o.kind) r.value = blankValue(o.kind); paint(); recount(); return; }
    if (t.hasAttribute('data-mke-rv') && t.tagName === 'SELECT') { S.g.rules[parseInt(t.getAttribute('data-mke-rv'), 10)].value = t.value; recount(); return; }
    if (t.hasAttribute('data-mke-emi')) {
      var k = parseInt(t.getAttribute('data-mke-emi'), 10), rr = S.g.rules[k], v = Array.isArray(rr.value) ? rr.value : [];
      rr.value = t.checked ? v.concat([t.value]).filter(function (x, n, a) { return a.indexOf(x) === n; }) : v.filter(function (x) { return x !== t.value; });
      t.parentNode.classList.toggle('on', t.checked); recount(); return;
    }
    if (t.hasAttribute('data-mke-p') && S.b && (t.type === 'checkbox' || t.tagName === 'SELECT')) {
      var key = t.getAttribute('data-mke-p');
      var val = t.type === 'checkbox' ? t.checked : t.value;
      if (['count', 'brand_id', 'category_id', 'coupon_id'].indexOf(key) !== -1) val = val === '' ? null : parseInt(val, 10);
      change(function (b) { b.blocks[b.sel].props[key] = val; });
      return;
    }
    if (t.hasAttribute('data-mke-bi') && t.tagName === 'SELECT' && S.b) {
      var bsi = parseInt(t.getAttribute('data-mke-bi'), 10);
      change(function (b) { var p = b.blocks[b.sel].props; p.items = p.items || []; while (p.items.length <= bsi) p.items.push({ icon: 'sparkles', bold: '', text: '' }); p.items[bsi].icon = t.value; });
      return;
    }
    if (t.hasAttribute('data-mke-e') && t.tagName === 'SELECT' && S.b) {
      var ek = t.getAttribute('data-mke-e');
      if (ek === 'theme' || ek === 'locale') S.b[ek] = t.value; else S.b.segment_id = t.value ? parseInt(t.value, 10) : null;
      S.b.dirty = true; if (ek === 'theme') paintBuilderKeepFocus(); save(); refresh(); return;
    }
    if (t.hasAttribute('data-mke-rs') && S.rv) {
      var field = t.getAttribute('data-mke-rs'), body = {};
      body[field] = field === 'segment_id' ? (t.value ? parseInt(t.value, 10) : null) : t.value;
      var res = await api('PUT', '/campaigns/' + S.rv.id, body);
      if (!res.ok) { S.rv.msg = refusal(res.data); S.rv.msgOk = false; }
      openReview(S.rv.id);
      return;
    }
    if (t.id === 'mkeSd' || t.id === 'mkeSt') { paintReviewKeep(t.id); }
  });
  function paintReviewKeep(id) { var v1 = (document.getElementById('mkeSd') || {}).value, v2 = (document.getElementById('mkeSt') || {}).value; if (v1 && v2) S.rv.d.campaign.scheduled_at = new Date(v1 + 'T' + v2).toISOString(); paintReview(); var el = document.getElementById(id); if (el) el.focus(); }

  document.addEventListener('pointerdown', function (e) {
    if (!S.b || S.view !== 'builder' || e.button > 0) return;
    var add = e.target.closest && e.target.closest('[data-mke-add]');
    if (add && !add.disabled) { startDrag(e, 'new', add.getAttribute('data-mke-add'), NAMES[add.getAttribute('data-mke-add')]); return; }
    var pgrip = e.target.closest && e.target.closest('[data-mke-pgrip]');
    if (pgrip && !pgrip.disabled) { var pg = parseInt(pgrip.getAttribute('data-mke-pgrip'), 10); startDrag(e, 'pmove', pg, (S.pnames || {})[S.b.blocks[S.b.sel].props.product_ids[pg]] || 'Product'); return; }
    var grip = e.target.closest && e.target.closest('[data-mke-grip]');
    if (grip && !grip.disabled) { var i = parseInt(grip.getAttribute('data-mke-grip'), 10); startDrag(e, 'move', i, blockLabel(S.b.blocks[i])); }
  });
  document.addEventListener('pointermove', overDrag);
  document.addEventListener('pointerup', endDrag);
  document.addEventListener('pointercancel', function () { if (drag) { drag.moved = false; drag.target = null; } endDrag(); });
  /* A palette button is pressed with the keyboard as a click: add under the selected block. */
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-mke-add]')) { e.preventDefault(); addBlock(e.target.getAttribute('data-mke-add')); }
  });

  /* ---------------------------------------------------------------- wiring */

  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) return previousGo.apply(this, arguments);
    try { cur = id; } catch (e) {}
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.dataset.go === id); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]'); if (group) group.classList.add('open');
    var side = document.querySelector('#side'); if (side) side.classList.remove('open');
    var h = host(); if (h) { h.innerHTML = ''; h.scrollTop = 0; }
    S.view = 'tabs'; S.overview = null; S.templates = null; S.reports = null; S.report = null; S.groups = null;
    try { var q = new URLSearchParams(window.location.search).get('tab'); if (['campaigns', 'templates', 'groups', 'reports'].indexOf(q) !== -1) S.tab = q; } catch (e) {}
    paint();
    return undefined;
  };
  /* For the console's other screens (and the proof harness): open a view, or a group's rules. */
  function setGroup(g) {
    S.view = 'tabs'; S.tab = 'groups';
    if (g.audience) S.g.audience = g.audience;
    S.g.name = g.name || ''; S.g.rules = clone(g.rules || []); S.g.match = g.match || 'all'; S.g.editing = null; S.g.people = null; S.g.count = null;
    paint(); recount();
  }
  window.kbbMarketingEmails = { openBuilder: openBuilder, openReview: openReview, openReport: openReport, setGroup: setGroup, people: people, state: S };

})();
</script>
@endverbatim
