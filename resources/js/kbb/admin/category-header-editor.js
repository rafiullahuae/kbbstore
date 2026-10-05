/**
 * A category page's "Edit header" panel. (Lane CH)
 *
 * The owner: "for categories we will have category name, description. and
 * background image, but everything can be controlled from the front-end with
 * edit button. and including header area size, spacings etc. AND in the same
 * edit panel, we will have option to choose this page with custom header area,
 * so in that tab we will see the same features as we have done for super sale
 * page."
 *
 * NEVER FETCHED FOR A SHOPPER. storefront-admin.js imports this only when an
 * admin who holds categoryheader.manage presses "Edit header". Nothing here
 * runs on page load.
 *
 * TWO TABS, ONE SWITCH. "Title header" edits the category's own title header
 * (its columns); "Custom header area" edits the Super Sale-style header and
 * banner for this one category. The tab that is open when Save is pressed is
 * the header the page draws; the other tab's values are kept, so switching
 * back loses nothing.
 *
 * RULES THIS FILE KEEPS (storefront-admin.js's and page-header-editor.js's)
 *  - Words from a record, a setting or the server are written with
 *    textContent or setAttribute. The one exception is the title header's
 *    markup from the preview endpoint -- the server's own rendering of the
 *    shop's Blade component -- parsed with DOMParser and adopted.
 *  - A picture address is used only when safeSrc() passes it; the server
 *    checks it again before it is stored or printed.
 *  - The live preview is the page itself. A slider moves the header's own
 *    custom property and a word is written straight in; only a choice that
 *    changes the markup (a picture, a look) asks the server once, on the
 *    click -- never per keystroke. Nothing measures an element.
 *  - Every listener added to the document is removed when the panel closes,
 *    and Cancel puts the page back exactly as it was.
 */
import css from './page-header-editor.css?inline';
import { controls, restyle, safeSrc } from './page-header-editor.js';

const TIMEOUT = 20000;
const UPLOAD_TIMEOUT = 90000;
const clone = (o) => JSON.parse(JSON.stringify(o));

const OWN_CSS = '.kbb-che-off{display:none!important}.kbb-che-tabs{display:grid;gap:6px}'
    + '.kbb-che-in textarea{width:100%;min-height:74px;padding:6px 9px;border:1px solid #eadfe3;border-radius:9px;font:inherit;font-size:12.5px;color:inherit;background:#fff;resize:vertical}'
    + '.kbb-che-count{font-size:11px;color:#9a8790;font-weight:500;justify-self:end}'
    + '.kbb-che-rng div{gap:6px}.kbb-che-rng .kbb-che-reset{border:0;background:none;color:#8a6f78;font:inherit;font-size:11px;cursor:pointer;padding:0 2px;text-decoration:underline}'
    + '.kbb-che-item{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:4px;align-items:center}'
    + '.kbb-che-item select{min-height:32px;border:1px solid #eadfe3;border-radius:8px;font:inherit;font-size:12px;background:#fff;color:inherit;max-width:110px}'
    + '.kbb-che-cols{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}'
    + '.kbb-che-cols label{display:grid;gap:3px;font-size:11.5px;font-weight:600}'
    + '.kbb-che-cols input{width:100%;height:32px;padding:2px;border:1px solid #eadfe3;border-radius:8px;background:#fff}'
    + '.kbb-che-note{font-size:12px;margin:0;padding:8px 10px;border-radius:9px;background:#fbf3f6;color:#7a3552}';

function h(tag, attrs, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v == null || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : String(v));
    }
    for (const kid of kids.flat()) {
        if (kid == null || kid === false) continue;
        el.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid);
    }
    return el;
}

function ensureStyle(id, text) {
    if (document.getElementById(id)) return;
    const st = document.createElement('style');
    st.id = id;
    st.textContent = text;
    document.head.appendChild(st);
}

/** A same-origin path, or null. */
function safePath(value) {
    const v = String(value == null ? '' : value);
    return v.startsWith('/') && !v.startsWith('//') && !v.includes('\\') ? v : null;
}

/** #RRGGBB, or the fallback. */
function hex(value, fallback) {
    const v = String(value == null ? '' : value).toUpperCase();
    return /^#[0-9A-F]{6}$/.test(v) ? v : fallback;
}

async function send(url, csrf, body) {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), TIMEOUT);
    try {
        const res = await fetch(url, {
            method: body === undefined ? 'GET' : 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
            signal: ctl.signal,
        });
        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }
        return { status: res.status, ok: res.ok, body: json };
    } catch (e) {
        return { status: 0, ok: false, body: { message: ctl.signal.aborted ? 'That took too long. Try again.' : 'The connection dropped. Try again.' } };
    } finally {
        clearTimeout(timer);
    }
}

function messageOf(res, fallback) {
    const b = res && res.body;
    if (b && b.errors && typeof b.errors === 'object') {
        const first = Object.values(b.errors)[0];
        if (Array.isArray(first) && first[0]) return String(first[0]);
    }
    if (b && typeof b.message === 'string' && b.message) return b.message;
    if (res && res.status === 419) return 'Your session has expired. Reload the page and try again.';
    if (res && res.status === 403) return 'Your role cannot change this header.';
    if (res && res.status === 401) return 'You are no longer signed in to the admin.';
    return fallback;
}

/** The server's title header markup as one adopted element, or null. */
function adopt(html) {
    const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
    const fresh = doc.body.firstElementChild;
    return fresh && fresh.hasAttribute('data-kbb-title-header') ? document.importNode(fresh, true) : null;
}

/* ------------------------------------------------- the custom header area */

/** The tick, built from its own shapes (PageBanners::ICON), never from markup. */
function tick() {
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('class', 'kbb-pb-ic');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const c = document.createElementNS(NS, 'circle');
    for (const [k, v] of Object.entries({ cx: 12, cy: 12, r: 10, fill: 'none', stroke: 'currentColor', 'stroke-width': 2 })) c.setAttribute(k, v);
    const p = document.createElementNS(NS, 'path');
    for (const [k, v] of Object.entries({ d: 'm7.5 12.3 3 3 6-6.4', fill: 'none', stroke: 'currentColor', 'stroke-width': 2.2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })) p.setAttribute(k, v);
    svg.append(c, p);
    return svg;
}

/**
 * partials/page-banner.blade.php, in the DOM: the same elements, classes and
 * style properties PageBanners::view() prints. Null when it would draw nothing.
 */
export function bannerEl(banner, spec) {
    const d = safeSrc(banner.img_d);
    const m = safeSrc(banner.img_m);
    const sd = banner.strip_d !== false;
    const sm = banner.strip_m !== false;
    const items = banner.strip && (sd || sm) ? (banner.items || []).filter((it) => String(it.en || '').trim() !== '') : [];
    if (!d && !m && !items.length) return null;

    const n = (k) => Math.round(Number(banner[k]) || 0);
    const style = `--pb-bg:${hex(banner.bg, '#C8336A')};--pb-ink:${hex(banner.ink, '#FFFFFF')};--pb-ic:${hex(banner.icon, '#FFFFFF')}`
        + `;--pb-hd:${n('sh_d')}px;--pb-hm:${n('sh_m')}px;--pb-fd:${n('fs_d')}px;--pb-fm:${n('fs_m')}px;--pb-id:${n('ic_d')}px;--pb-im:${n('ic_m')}px`;
    const el = h('div', { class: 'kbb-pb', style, 'data-banner': banner.id });
    if (d || m) {
        const pic = h('picture', {});
        if (d && m && d !== m) pic.appendChild(h('source', { media: `(max-width: ${Number(spec.breakpoint) || 900}px)`, srcset: m }));
        pic.appendChild(h('img', { src: d || m, alt: String(banner.alt || ''), decoding: 'async' }));
        el.appendChild(h('div', { class: 'kbb-pb-img' }, pic));
    }
    if (items.length) {
        // (2.60.396) The whole strip off on one device, as PageBanners::view().
        el.appendChild(h('ul', { class: 'kbb-pb-strip' + (sd === sm ? '' : (sd ? ' kbb-pb-xm' : ' kbb-pb-xd')) }, items.map((it) => h('li', { class: it.dev === 'd' ? 'kbb-pb-d' : (it.dev === 'm' ? 'kbb-pb-m' : null) }, tick(), h('span', { text: String(it.en) })))));
    }
    return el;
}

/** partials/page-header.blade.php's parts, for restyle(). */
function headerParts(sample, key) {
    const parts = {};
    parts.wrap = h('div', { 'data-kbb-ph': key });
    const home = safePath(sample.home_href) || '/';
    parts.crumb = h('nav', {}, h('a', { href: home }, String(sample.home || 'Home')), ' / ', h('span', { text: String(sample.title || '') }));
    parts.title = h('h1', {}, String(sample.title || ''));
    parts.count = h('span', { text: String(sample.count || '') });
    parts.title.append(' ', parts.count);
    parts.intro = h('p', { text: String(sample.intro || '') });
    parts.button = h('a', { href: safePath(sample.button_href) || '/' }, String(sample.button || 'All products'));
    parts.wrap.append(parts.crumb, parts.title, parts.intro, parts.button);
    return parts;
}

/* ------------------------------------------------------------- the panel */

let open = null;

/** Called by storefront-admin.js with the context's `categoryheader`. */
export function openPanel(ctx, csrf, opener, toast) {
    if (open) { open.focus(); return; }
    ensureStyle('kbb-phe-css', css);
    ensureStyle('kbb-che-css', OWN_CSS);
    ensureStyle('kbb-ph-css', ctx.pageheader.css);
    ensureStyle('kbb-pb-css', ctx.spec.banner.css);
    ensureStyle('kbb-chc-css', ctx.spec.area_css);
    if (ctx.css && ![...document.querySelectorAll('link[rel=stylesheet]')].some((l) => l.getAttribute('href') === ctx.css)) {
        const href = safePath(ctx.css);
        if (href) document.head.appendChild(h('link', { rel: 'stylesheet', href }));
    }

    const bp = Number(ctx.spec.breakpoint) || 900;
    const atPhone = () => window.matchMedia(`(max-width: ${bp - 0.02}px)`).matches;

    const st = {
        tab: ctx.mode === 'custom' ? 'custom' : 'title',
        dev: atPhone() ? 'm' : 'd',
        fields: clone(ctx.fields),
        sent: {},              // which title-header fields changed
        custom: clone(ctx.custom),
        customDirty: false,
        dirty: false,
        busy: false,
        note: '',
        seq: 0,
    };
    st.fields.header_style = { ...(ctx.fields.header_style || {}) };

    /* -- the page: what was there, and the two views the panel swaps between */
    const page = snapshot();

    function snapshot() {
        const area = document.querySelector('[data-kbb-ch]');
        const th = document.querySelector('[data-kbb-title-header]');
        const crumbWrap = [...document.querySelectorAll('.wrap')].find((w) => w.querySelector(':scope > .crumb') && !w.classList.contains('shop')) || null;
        const legacy = document.querySelector('.kbb-banner');
        const grid = document.querySelector('.wrap.shop');
        // Copies to put back on Cancel -- without the Edit header button,
        // which is moved, never copied.
        const copy = (el) => {
            if (!el) return null;
            const c = el.cloneNode(true);
            c.querySelectorAll('.kbb-qe-pill').forEach((p) => p.remove());
            return c;
        };
        return {
            area, th, crumbWrap, legacy, grid,
            saved: { th: copy(th), area: copy(area) },
            hidden: [],
            made: [],
        };
    }

    // A class as well as `hidden`: the title header's own display:flex beats
    // the hidden attribute's display:none.
    function hide(el) {
        if (el && !el.hidden) { el.hidden = true; el.classList.add('kbb-che-off'); page.hidden.push(el); }
    }
    function unhide(el) {
        if (el && el.hidden) { el.hidden = false; el.classList.remove('kbb-che-off'); page.hidden = page.hidden.filter((x) => x !== el); }
    }
    function place(el) {
        // Directly above the products, where the server draws either header.
        if (page.grid && page.grid.parentNode) page.grid.parentNode.insertBefore(el, page.grid);
        else document.body.appendChild(el);
    }
    function movePill(to) {
        if (!opener || !to) return;
        to.classList.add('kbb-qe-host');
        opener.classList.remove('kbb-qe-pill--plain');
        to.appendChild(opener);
    }

    /* -- the title header view */
    function titleView() {
        hide(page.area);
        hide(customEl);
        if (page.crumbWrap) unhide(page.crumbWrap);
        else {
            // The page was drawn with the custom area: the shop's breadcrumb row, as ShopController draws it.
            page.crumbWrap = h('div', { class: 'wrap' }, h('div', { class: 'crumb' }, h('b', { text: String(ctx.sample.home || 'Home') }), ` / ${String(ctx.sample.crumb || '')}`));
            page.made.push(page.crumbWrap);
            place(page.crumbWrap);
        }
        if (page.legacy) unhide(page.legacy);
        if (page.th) { unhide(page.th); page.th.classList.add('kbb-phe-live'); movePill(page.th); }
        liveTitle();
        if (!page.th || st.sent.header_image !== undefined || st.sent.header_style !== undefined) preview();
    }

    /* -- the custom header area view */
    let customEl = null;
    let parts = null;
    function customView() {
        if (page.crumbWrap) hide(page.crumbWrap);
        hide(page.legacy);
        hide(page.th);
        hide(page.area);
        if (!customEl) {
            customEl = h('div', { class: 'kbb-home kbb-chc kbb-phe-live', 'data-kbb-ch': String(ctx.id) });
            parts = headerParts(ctx.sample, ctx.key);
            page.made.push(customEl);
            place(customEl);
        }
        unhide(customEl);
        movePill(customEl);
        liveCustom();
    }

    function liveCustom() {
        if (!customEl) return;
        const c = st.custom;
        const same = (st.dev === 'm') === atPhone();
        const bag = same ? c.header : { ...c.header, d: c.header[st.dev], m: c.header[st.dev] };
        const crumb = ctx.pageheader.crumb;
        restyle(parts, bag, 'collection', ctx.pageheader.breakpoint, same ? crumb : { d: crumb[st.dev], m: crumb[st.dev] });
        const wrap = h('div', { class: 'wrap' }, parts.wrap);
        const banner = c.banner_on ? bannerEl(c.banner, ctx.spec.banner) : null;
        const kids = c.banner_at === 'below' ? [wrap, banner] : [banner, wrap];
        customEl.replaceChildren(...kids.filter(Boolean));
        if (opener && customEl.classList.contains('kbb-qe-host')) customEl.appendChild(opener);
    }

    /* -- the title header, live: words and numbers in place, looks from the server */
    function styleVal(key) {
        const v = st.fields.header_style[key];
        return v === undefined || v === null || v === '' ? null : v;
    }

    function liveTitle() {
        const el = page.th;
        if (!el) return;
        const t = el.querySelector('.kbb-th__title');
        if (t) t.textContent = st.fields.header_title.trim() || String(ctx.placeholders.title || '');
        const d = el.querySelector('.kbb-th__desc');
        if (d && st.sent.header_description !== undefined) d.textContent = st.fields.header_description.trim();
        for (const n of ctx.spec.numbers) {
            const v = styleVal(n.key);
            if (n.var) el.style.setProperty(n.var, `${v === null ? n.shop : v}px`);
        }
    }

    let previewTimer = null;
    function preview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(runPreview, 120);
    }
    async function runPreview() {
        const seq = ++st.seq;
        const res = await send(ctx.endpoints.preview, csrf, { fields: payloadFields(), path: window.location.pathname });
        if (seq !== st.seq || !open) return;
        if (!res.ok || !res.body || res.body.ok !== true) { err.textContent = messageOf(res, 'The preview could not be drawn.'); return; }
        err.textContent = '';
        st.note = String(res.body.note || '');
        drawNote();
        swapTitle(res.body.html);
    }

    function swapTitle(html) {
        const fresh = adopt(html);
        if (!fresh) {
            if (page.th) { page.th.remove(); page.th = null; }
            if (opener && st.tab === 'title') movePill(page.crumbWrap);
            return;
        }
        fresh.classList.add('kbb-phe-live');
        if (page.th && page.th.parentNode) page.th.replaceWith(fresh);
        else {
            const legacyAfter = page.legacy && page.legacy.parentNode ? page.legacy : page.crumbWrap;
            if (legacyAfter && legacyAfter.parentNode) legacyAfter.after(fresh); else place(fresh);
            page.made.push(fresh);
        }
        page.th = fresh;
        if (st.tab === 'title') { movePill(fresh); liveTitle(); } else hide(fresh);
    }

    /* -- what Save and the preview send: only the fields that changed */
    function payloadFields() {
        const out = {};
        for (const k of ['header_title', 'header_description', 'header_image']) {
            if (st.sent[k] !== undefined) out[k] = st.fields[k];
        }
        if (st.sent.header_style !== undefined) {
            const s = {};
            for (const k of Object.keys(st.sent.header_style)) {
                const v = st.fields.header_style[k];
                s[k] = v === undefined || v === '' ? null : v;
            }
            out.header_style = s;
        }
        return out;
    }
    function setField(k, v) { st.fields[k] = v; st.sent[k] = true; st.dirty = true; }
    function setStyle(k, v) {
        if (v === null || v === '') delete st.fields.header_style[k]; else st.fields.header_style[k] = v;
        st.sent.header_style = st.sent.header_style || {};
        st.sent.header_style[k] = true;
        st.dirty = true;
    }

    /* -- the panel */
    const err = h('p', { class: 'kbb-phe-err', role: 'alert' });
    const noteHost = h('div', {});
    const tabsHost = h('div', { class: 'kbb-che-tabs' });
    const body = h('div', { class: 'kbb-phe-in', style: 'padding:0' });
    const pickerHost = h('div', {});
    const saveBtn = h('button', { type: 'button', class: 'kbb-phe-btn is-primary', onclick: () => save() }, 'Save');
    const panel = h('div', { class: 'kbb-phe kbb-che', role: 'dialog', 'aria-label': `Edit the header of ${ctx.name}`, tabindex: '-1' },
        h('div', { class: 'kbb-phe-in kbb-che-in' },
            h('div', { class: 'kbb-phe-head' }, h('b', { text: `Category header · ${ctx.name}` }),
                h('button', { type: 'button', class: 'kbb-phe-x', 'aria-label': 'Close', onclick: () => close() }, '×')),
            tabsHost, noteHost, pickerHost, body, err,
            h('div', { class: 'kbb-phe-foot' }, saveBtn,
                h('button', { type: 'button', class: 'kbb-phe-btn', onclick: () => close() }, 'Cancel'),
                safePath(ctx.console) ? h('a', { href: safePath(ctx.console) }, 'Catalog › Categories') : null)));

    function drawTabs() {
        // The owner, after seeing both tabs: "FOR CATEGORIES pages, we have
        // already on our existing site. backgroudn image, centeralized title
        // and description text. that's it." So the panel is the title header
        // alone. The tabs return only for a category already saved with a
        // custom header area, so it can be switched back.
        if (ctx.mode !== 'custom' && st.tab !== 'custom') { tabsHost.replaceChildren(); return; }
        tabsHost.replaceChildren(
            h('div', { class: 'kbb-phe-seg', role: 'tablist', 'aria-label': 'Which header this page shows' },
                [['title', 'Title header'], ['custom', 'Custom header area']].map(([v, l]) => h('button', {
                    type: 'button', role: 'tab', 'aria-selected': String(st.tab === v), 'aria-pressed': String(st.tab === v), 'data-tab': v,
                    onclick: () => { if (st.tab === v) return; st.tab = v; st.dirty = true; drawTabs(); drawBody(); if (v === 'title') titleView(); else customView(); },
                }, l))),
            h('p', { class: 'kbb-phe-help', text: st.tab === 'custom'
                ? 'Save with this tab open and this category page shows the Super Sale-style header area, banner and strip. Your title header settings are kept.'
                : 'Save with this tab open and this category page shows its title header. Any custom header area you made is kept for later.' }));
    }

    function drawNote() {
        noteHost.replaceChildren();
        if (st.tab === 'title' && ctx.legacy_banner) noteHost.appendChild(h('p', { class: 'kbb-che-note', text: 'This category shows its own banner (Catalog › Categories › Banner) in place of the title header. Turn that banner off to see these settings.' }));
        else if (st.tab === 'title' && st.note) noteHost.appendChild(h('p', { class: 'kbb-che-note', text: st.note }));
    }

    function drawBody() {
        drawNote();
        body.replaceChildren(...(st.tab === 'title' ? titleControls() : customControls()));
    }

    function devSeg(redraw) {
        return h('div', { class: 'kbb-phe-sec' },
            h('p', { class: 'kbb-phe-h', text: 'Device' }),
            h('div', { class: 'kbb-phe-seg', role: 'group', 'aria-label': 'Device' },
                [['d', 'Laptop'], ['m', 'Phone']].map(([v, l]) => h('button', {
                    type: 'button', 'aria-pressed': String(st.dev === v),
                    onclick: () => { st.dev = v; redraw(); },
                }, l))),
            h('p', { class: 'kbb-phe-help', text: `Laptop is ${bp}px and wider; phone is narrower. Sizes, spacing and looks are set for each one.` }));
    }

    function range(n) {
        const own = styleVal(n.key);
        const val = own === null ? n.shop : Number(own);
        const outEl = h('output', { text: `${val}px` });
        const reset = own === null ? null : h('button', {
            type: 'button', class: 'kbb-che-reset',
            onclick: () => { setStyle(n.key, null); liveTitle(); drawBody(); },
        }, 'shop setting');
        return h('label', { class: 'kbb-phe-rng kbb-che-rng', 'data-key': n.key },
            h('div', {}, h('span', { text: n.label }), h('span', {}, reset, ' ', outEl)),
            h('input', {
                type: 'range', min: n.min, max: n.max, step: n.step || 1, value: val,
                oninput: (e) => { const v = Number(e.currentTarget.value); setStyle(n.key, v); outEl.textContent = `${v}px`; liveTitle(); },
                onchange: () => drawBody(),
            }));
    }

    /**
     * One choice as a row of buttons. With `withShop`, the first button is
     * "Shop" (follow Appearance › Site layout › Category header); without it
     * (the picture position) blank IS centre, so Centre stores nothing.
     */
    function choiceSeg(label, key, options, withShop) {
        const cur = styleVal(key);
        const opts = withShop ? [{ value: '', label: 'Shop' }, ...options] : options;
        const now = cur === null ? (withShop ? '' : 'center') : cur;
        return h('div', { class: 'kbb-phe-sel' }, h('span', { text: label }),
            h('div', { class: 'kbb-phe-seg', role: 'group', 'aria-label': label }, opts.map((o) => h('button', {
                type: 'button', 'aria-pressed': String(now === o.value),
                onclick: () => { setStyle(key, !withShop && o.value === 'center' ? null : o.value); drawBody(); preview(); },
            }, o.label))));
    }

    function picRow(title, value, onPick, onRemove, emptyText) {
        const v = safeSrc(value);
        return h('div', { class: 'kbb-phe-pic' },
            h('div', { class: 'kbb-phe-thumb' }, v ? h('img', { src: v, alt: '' }) : emptyText),
            h('div', { class: 'kbb-phe-row' },
                h('span', { text: title, style: 'width:100%;font-weight:600;font-size:12px' }),
                h('button', { type: 'button', class: 'kbb-phe-btn', onclick: (e) => picker(onPick, e.currentTarget.closest('.kbb-phe-pic')) }, v ? 'Change' : 'Choose'),
                v ? h('button', { type: 'button', class: 'kbb-phe-btn', onclick: onRemove }, 'Remove') : null));
    }

    function titleControls() {
        const dev = st.dev;
        const sfx = dev === 'd' ? '_desktop' : '_phone';
        const nums = (group) => ctx.spec.numbers.filter((n) => n.group === group && (n.dev === dev || n.dev === 'both')).map(range);
        const titleIn = h('input', {
            type: 'text', maxlength: String(ctx.limits.title), value: st.fields.header_title, placeholder: String(ctx.placeholders.title || ''), 'aria-label': 'Name',
            oninput: (e) => { setField('header_title', e.currentTarget.value); titleCount.textContent = `${e.currentTarget.value.length} / ${ctx.limits.title}`; liveTitle(); },
        });
        const titleCount = h('span', { class: 'kbb-che-count', text: `${st.fields.header_title.length} / ${ctx.limits.title}` });
        const descIn = h('textarea', {
            maxlength: String(ctx.limits.description), placeholder: String(ctx.placeholders.description || 'The category’s own description'), 'aria-label': 'Description',
            oninput: (e) => { setField('header_description', e.currentTarget.value); descCount.textContent = `${e.currentTarget.value.length} / ${ctx.limits.description}`; liveTitle(); },
            onchange: () => { if (!(page.th && page.th.querySelector('.kbb-th__desc'))) preview(); },
        });
        descIn.value = st.fields.header_description;
        const descCount = h('span', { class: 'kbb-che-count', text: `${st.fields.header_description.length} / ${ctx.limits.description}` });

        return [
            h('div', { class: 'kbb-phe-sec' },
                h('p', { class: 'kbb-phe-h', text: 'Name and description' }),
                h('label', { class: 'kbb-phe-sel' }, h('span', { text: 'Name' }), titleIn, titleCount),
                h('label', { class: 'kbb-phe-sel' }, h('span', { text: 'Description' }), descIn, descCount),
                h('p', { class: 'kbb-phe-help', text: 'Blank uses the category’s own name and description. Plain text: what you type is shown as typed.' })),
            devSeg(drawBody),
            h('div', { class: 'kbb-phe-sec' },
                h('p', { class: 'kbb-phe-h', text: 'Background picture' }),
                picRow('Picture', st.fields.header_image, (url) => { setField('header_image', url); drawBody(); preview(); }, () => { setField('header_image', ''); drawBody(); preview(); }, 'No picture'),
                picRow('Phone picture (optional)', st.fields.header_style.img_phone, (url) => { setStyle('img_phone', url); drawBody(); preview(); }, () => { setStyle('img_phone', null); drawBody(); preview(); }, 'Same as laptop'),
                choiceSeg(dev === 'd' ? 'Picture position · laptop' : 'Picture position · phone', dev === 'd' ? 'focus_desktop' : 'focus', ctx.spec.focus, false),
                h('p', { class: 'kbb-phe-help', text: `From the Media Library, or upload one. ${ctx.upload.recommended ? `About ${ctx.upload.recommended} works best.` : ''}` })),
            h('div', { class: 'kbb-phe-sec' }, h('p', { class: 'kbb-phe-h', text: dev === 'd' ? 'Header area size · laptop' : 'Header area size · phone' }), ...nums('size')),
            h('div', { class: 'kbb-phe-sec' }, h('p', { class: 'kbb-phe-h', text: dev === 'd' ? 'Spacing · laptop' : 'Spacing · phone' }), ...nums('space')),
            h('div', { class: 'kbb-phe-sec' }, h('p', { class: 'kbb-phe-h', text: dev === 'd' ? 'Text look · laptop' : 'Text look · phone' }),
                ...nums('text'),
                ...ctx.spec.choices.map((c) => choiceSeg(c.label, c.key + sfx, c.options, true))),
        ];
    }

    /* -- the custom area's controls: Pages › Page header's own, then the banner */
    function customControls() {
        const c = st.custom;
        const touch = () => { st.customDirty = true; st.dirty = true; liveCustom(); };
        const hostHeader = h('div', {});
        controls(hostHeader, {
            get bag() { return st.custom.header; },
            spec: ctx.pageheader,
            kind: 'collection',
            get dev() { return st.dev; },
            set dev(v) { st.dev = v; },
            // The banner's own sizes follow the same device switch.
            onDevice: () => { drawBody(); liveCustom(); },
            onChange: touch,
            pick: (done, at) => picker(done, at),
        });

        const b = c.banner;
        const sb = ctx.spec.banner;
        const redrawBanner = () => { touch(); drawBody(); };
        const items = h('div', { class: 'kbb-phe-sec', style: 'border:0;padding:0' }, (b.items || []).map((it, i) => h('div', { class: 'kbb-che-item' },
            h('input', { type: 'text', maxlength: String(sb.max_text), value: it.en, 'aria-label': `Strip line ${i + 1}`, oninput: (e) => { it.en = e.currentTarget.value; touch(); } }),
            (() => {
                const sel = h('select', { 'aria-label': `Strip line ${i + 1}: where it shows`, onchange: (e) => { it.dev = e.currentTarget.value; touch(); } },
                    sb.devices.map((o) => h('option', { value: o.value }, o.label)));
                sel.value = it.dev || 'both';
                return sel;
            })(),
            h('button', { type: 'button', class: 'kbb-phe-btn', 'aria-label': `Remove strip line ${i + 1}`, onclick: () => { b.items.splice(i, 1); redrawBanner(); } }, '×'))));

        return [
            h('div', { class: 'kbb-phe-sec' }, h('p', { class: 'kbb-che-note', text: 'The same header as the Super Sale page, with its own banner and strip, for this category only.' })),
            hostHeader,
            h('div', { class: 'kbb-phe-sec' },
                h('p', { class: 'kbb-phe-h', text: 'Banner and strip' }),
                h('div', { class: 'kbb-phe-tog' },
                    h('label', {}, h('input', { type: 'checkbox', checked: c.banner_on ? true : null, onchange: (e) => { c.banner_on = e.currentTarget.checked; redrawBanner(); } }), h('span', { text: 'Show the banner' })),
                    h('label', {}, h('input', { type: 'checkbox', checked: b.strip ? true : null, onchange: (e) => { b.strip = e.currentTarget.checked; redrawBanner(); } }), h('span', { text: 'Show the strip' })),
                    h('label', {}, h('input', { type: 'checkbox', checked: b.strip_d !== false ? true : null, onchange: (e) => { b.strip_d = e.currentTarget.checked; redrawBanner(); } }), h('span', { text: 'Strip on desktop' })),
                    h('label', {}, h('input', { type: 'checkbox', checked: b.strip_m !== false ? true : null, onchange: (e) => { b.strip_m = e.currentTarget.checked; redrawBanner(); } }), h('span', { text: 'Strip on mobile' }))),
                h('div', { class: 'kbb-phe-sel' }, h('span', { text: 'Position' }),
                    h('div', { class: 'kbb-phe-seg', role: 'group', 'aria-label': 'Banner position' }, ctx.spec.banner_at.map((o) => h('button', {
                        type: 'button', 'aria-pressed': String(c.banner_at === o.value), onclick: () => { c.banner_at = o.value; redrawBanner(); },
                    }, o.label)))),
                picRow('Banner picture', b.img_d, (url) => { b.img_d = url; redrawBanner(); }, () => { b.img_d = ''; redrawBanner(); }, 'No picture'),
                picRow('Phone banner picture (optional)', b.img_m, (url) => { b.img_m = url; redrawBanner(); }, () => { b.img_m = ''; redrawBanner(); }, 'Same as laptop'),
                h('label', { class: 'kbb-phe-sel' }, h('span', { text: 'Alt text' }), h('input', { type: 'text', maxlength: '160', value: b.alt || '', placeholder: 'What the picture shows', oninput: (e) => { b.alt = e.currentTarget.value; touch(); } })),
                h('label', { class: 'kbb-phe-sel' }, h('span', { text: 'Link (optional)' }), h('input', { type: 'text', maxlength: '500', value: b.link || '', placeholder: '/super-sale/ or https://…', oninput: (e) => { b.link = e.currentTarget.value; st.customDirty = true; st.dirty = true; } })),
                h('p', { class: 'kbb-phe-h', text: 'Strip lines' }),
                items,
                (b.items || []).length < sb.max_items ? h('div', { class: 'kbb-phe-row' }, h('button', { type: 'button', class: 'kbb-phe-btn', onclick: () => { b.items = b.items || []; b.items.push({ en: '', ar: '', dev: 'both' }); redrawBanner(); } }, '+ Add a line')) : null,
                h('div', { class: 'kbb-che-cols' }, sb.colours.map((col) => h('label', {}, h('span', { text: col.label }),
                    h('input', { type: 'color', value: hex(b[col.key], '#C8336A').toLowerCase(), oninput: (e) => { b[col.key] = e.currentTarget.value.toUpperCase(); touch(); } })))),
                ...sb.numbers.filter((n) => n.key.endsWith(st.dev === 'd' ? '_d' : '_m')).map((n) => {
                    const outEl = h('output', { text: `${b[n.key]}px` });
                    return h('label', { class: 'kbb-phe-rng' }, h('div', {}, h('span', { text: n.label }), outEl),
                        h('input', { type: 'range', min: n.min, max: n.max, step: '1', value: b[n.key], oninput: (e) => { b[n.key] = Number(e.currentTarget.value); outEl.textContent = `${b[n.key]}px`; touch(); } }));
                })),
        ];
    }

    /* -- the Media Library, in the panel: opens UNDER the row whose button was
       pressed and scrolls into view (the 2.60.392 fix, kept). */
    let openBox = null;
    function picker(done, at) {
        let pageNo = 1;
        const grid = h('div', { class: 'kbb-phe-grid' });
        const note = h('p', { class: 'kbb-phe-help', text: 'Loading the Media Library…' });
        const more = h('button', { type: 'button', class: 'kbb-phe-btn', hidden: true, onclick: () => { pageNo += 1; load(); } }, 'More');
        const file = h('input', { type: 'file', accept: (ctx.upload.types || []).join(','), hidden: true, onchange: (e) => upload(e.currentTarget.files[0]) });
        const box = h('div', { class: 'kbb-phe-sec kbb-che-picker' },
            h('p', { class: 'kbb-phe-h', text: 'Choose a picture' }), note, grid,
            h('div', { class: 'kbb-phe-row' }, more,
                h('button', { type: 'button', class: 'kbb-phe-btn', onclick: () => file.click() }, 'Upload new'), file,
                h('button', { type: 'button', class: 'kbb-phe-btn', onclick: () => shut() }, 'Cancel')));
        const shut = () => { box.remove(); if (openBox === box) openBox = null; };
        if (openBox) openBox.remove();
        if (at && at.parentNode) at.after(box); else pickerHost.replaceChildren(box);
        openBox = box;
        box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

        const choose = (url) => { shut(); done(url); };

        async function load() {
            more.hidden = true;
            const res = await send(`${ctx.endpoints.media}?page=${pageNo}`);
            if (!res.ok || !res.body || !Array.isArray(res.body.items)) { note.textContent = messageOf(res, 'The Media Library could not be read.'); return; }
            note.textContent = res.body.total ? 'Tap a picture to use it.' : 'The Media Library is empty. Upload a picture.';
            res.body.items.forEach((it) => {
                const url = safeSrc(it.url);
                const thumb = safeSrc(it.thumb) || url;
                if (!url) return;
                grid.appendChild(h('button', { type: 'button', 'aria-label': String(it.name || it.filename || 'Picture'), onclick: () => choose(url) },
                    h('img', { src: thumb, alt: '', loading: 'lazy' })));
            });
            more.hidden = !(res.body.pages > pageNo);
        }

        function upload(f) {
            if (!f) return;
            if ((ctx.upload.types || []).length && !ctx.upload.types.includes(f.type)) { note.textContent = 'Use a JPG, PNG, WebP or GIF.'; return; }
            if (f.size > ctx.upload.max_bytes) { note.textContent = `That picture is over ${Math.round(ctx.upload.max_bytes / 1048576)} MB.`; return; }
            const form = new FormData();
            form.append('file', f);
            form.append('folder', ctx.upload.folder);
            const req = new XMLHttpRequest();
            req.open('POST', ctx.endpoints.upload);
            req.timeout = UPLOAD_TIMEOUT;
            req.withCredentials = true;
            req.setRequestHeader('Accept', 'application/json');
            req.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            req.setRequestHeader('X-CSRF-TOKEN', csrf);
            note.textContent = 'Uploading…';
            req.onload = () => {
                let b = null;
                try { b = JSON.parse(req.responseText); } catch (e) { b = null; }
                const url = b && b.ok ? safeSrc(b.url) : '';
                if (req.status >= 200 && req.status < 300 && url) choose(url);
                else note.textContent = messageOf({ status: req.status, body: b }, 'The picture could not be uploaded.');
            };
            req.onerror = () => { note.textContent = 'The upload stopped. Try again.'; };
            req.ontimeout = () => { note.textContent = 'The upload took too long. Try a smaller picture.'; };
            req.send(form);
        }

        load();
    }

    /* -- save, close */
    async function save() {
        if (st.busy) return;
        st.busy = true;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        err.textContent = '';
        const payload = { mode: st.tab, fields: payloadFields(), path: window.location.pathname };
        if (st.tab === 'custom' || st.customDirty) {
            payload.custom = { header: st.custom.header, banner: st.custom.banner, banner_on: st.custom.banner_on, banner_at: st.custom.banner_at };
        }
        const res = await send(ctx.endpoints.save, csrf, payload);
        st.busy = false;
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (!res.ok || !res.body || res.body.ok !== true) {
            err.textContent = messageOf(res, 'That could not be saved.');
            return;
        }
        ctx.mode = res.body.mode;
        ctx.fields = res.body.fields;
        ctx.custom = res.body.custom;
        if (st.tab === 'title') swapTitle(res.body.html);
        if (toast) toast(String(res.body.message || 'Saved'));
        finish(false);
    }

    function onKey(e) {
        if (e.key === 'Escape') close();
    }

    function close() {
        if (st.dirty && !window.confirm('Close without saving? The header goes back to how it was.')) return;
        finish(true);
    }

    function finish(revert) {
        clearTimeout(previewTimer);
        st.seq += 1;
        document.removeEventListener('keydown', onKey);
        panel.remove();
        open = null;
        if (revert) {
            // The page exactly as it was: made nodes out, hidden ones back, the
            // title header and the area as they were drawn.
            const pillHome = page.saved.th ? 'th' : (page.saved.area ? 'area' : null);
            for (const el of page.made) if (el !== page.th) el.remove();
            for (const el of [...page.hidden]) unhide(el);
            if (page.th && page.saved.th) {
                const back = page.saved.th.cloneNode(true);
                page.th.replaceWith(back);
                page.th = back;
            } else if (page.th && !page.saved.th) {
                page.th.remove();
                page.th = null;
            }
            if (customEl) customEl.remove();
            const home = pillHome === 'th' ? page.th : (pillHome === 'area' ? document.querySelector('[data-kbb-ch]') : null);
            if (home) movePill(home);
        } else {
            for (const el of document.querySelectorAll('.kbb-phe-live')) el.classList.remove('kbb-phe-live');
            // The tab that was saved is what the page shows; the other stays hidden.
            if (st.tab === 'custom') {
                if (page.area && page.area !== customEl) page.area.remove();
                if (page.th) page.th.remove();
                for (const el of [page.crumbWrap, page.legacy]) if (el) { el.hidden = true; el.classList.add('kbb-che-off'); }
            } else if (customEl || page.area) {
                if (customEl) customEl.remove();
                if (page.area) page.area.remove();
            }
        }
        if (opener && opener.isConnected) opener.focus({ preventScroll: true });
    }

    drawTabs();
    drawBody();
    document.addEventListener('keydown', onKey);
    document.body.appendChild(panel);
    open = panel;
    if (st.tab === 'custom') customView(); else titleView();
    panel.focus({ preventScroll: true });
}
