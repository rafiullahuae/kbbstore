/**
 * Pages → Page header: the "Edit header" panel on a custom page, and the same
 * controls for the console screen. (Lane PH)
 *
 * The owner: "i must be able to edit the inner page header from the
 * front-end, like super sale page."
 *
 * NEVER FETCHED FOR A SHOPPER. storefront-admin.js imports this only when an
 * admin who holds pageheader.manage presses "Edit header"; the console imports
 * it only on Pages → Page header. Nothing here runs on page load.
 *
 * RULES THIS FILE KEEPS (the same ones storefront-admin.js keeps)
 *  - Every word from a setting or the server is written with textContent or
 *    setAttribute, never innerHTML.
 *  - A picture address is used only if safeSrc() passes it; the server checks
 *    it again with SafeUrl::src() before it is stored or printed.
 *  - Live preview is the page's own header, redrawn by compile() -- the same
 *    function as PageHeaders::compile() -- from data the page already has.
 *    No request until Save. Nothing measures an element.
 *  - Every listener added to the document is removed when the panel closes.
 */
import css from './page-header-editor.css?inline';
import { compile } from './page-header-compile.js';

const TIMEOUT = 20000;
const UPLOAD_TIMEOUT = 90000;
const clone = (o) => JSON.parse(JSON.stringify(o));

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

/** The address rule SafeUrl::src() applies, so a bad one is never drawn. */
export function safeSrc(value) {
    const v = String(value == null ? '' : value).trim();
    if (v === '' || v.length > 500 || /[\x00-\x20"'<>`\\]/.test(v)) return '';
    if (/^https?:\/\/[^/?#@\s]/i.test(v)) return v;
    if (v.startsWith('/') && !v.startsWith('//')) return v;
    return '';
}

/** A same-origin path, or null. */
function safePath(value) {
    const v = String(value == null ? '' : value);
    return v.startsWith('/') && !v.startsWith('//') && !v.includes('\\') ? v : null;
}

function ensureStyle(id, text) {
    if (document.getElementById(id)) return;
    const st = document.createElement('style');
    st.id = id;
    st.textContent = text;
    document.head.appendChild(st);
}

/** The picture a bag draws: {d, m, alt} or null. Mirrors PageHeaders::picture(). */
export function picture(bag) {
    const d = safeSrc(bag.img);
    const m = safeSrc(bag.img_m);
    if (!d && !m) return null;
    if (!bag.d.image && !bag.m.image) return null;
    return { d: d || m, m: m || d, alt: String(bag.alt || '') };
}

/** One device's half on both, so a phone look can be previewed at desktop width. */
export function asDevice(bag, dev) {
    if (dev !== 'd' && dev !== 'm') return bag;
    return { ...bag, d: bag[dev], m: bag[dev] };
}

/**
 * Restyle a header region in place: classes, the style attribute, and the
 * picture element. `parts` holds the region's children by role.
 */
export function restyle(parts, bag, kind, breakpoint, crumb) {
    const pic = picture(bag);
    const c = compile(bag, kind, { intro: !!(parts.intro && parts.intro.textContent.trim() !== ''), image: !!pic, crumb: crumb || { d: true, m: true } });

    parts.wrap.className = c.wrap + (parts.wrap.classList.contains('kbb-phe-live') ? ' kbb-phe-live' : '');
    parts.wrap.setAttribute('style', c.style);
    for (const k of ['crumb', 'title', 'count', 'intro', 'button']) {
        if (parts[k]) parts[k].className = c.cls[k];
    }

    if (!pic) {
        if (parts.image) parts.image.remove();
        parts.image = null;
        return;
    }
    if (!parts.image) {
        parts.image = h('picture', {}, h('img', { decoding: 'async', alt: '' }));
        const after = parts.crumb && parts.crumb.parentNode === parts.wrap ? parts.crumb.nextSibling : parts.wrap.firstChild;
        parts.wrap.insertBefore(parts.image, after);
    }
    parts.image.className = c.cls.image;
    let source = parts.image.querySelector('source');
    if (pic.m !== pic.d) {
        if (!source) {
            source = h('source', {});
            parts.image.insertBefore(source, parts.image.firstChild);
        }
        source.setAttribute('media', `(max-width: ${Number(breakpoint) || 900}px)`);
        source.setAttribute('srcset', pic.m);
    } else if (source) {
        source.remove();
    }
    const img = parts.image.querySelector('img');
    img.setAttribute('src', pic.d);
    img.setAttribute('alt', pic.alt);
}

/**
 * A header built from sample parts, for the console's preview stage. The same
 * element names and classes partials/page-header.blade.php prints.
 */
export function stage(sample, bag, kind, breakpoint, crumb) {
    const parts = {};
    parts.wrap = h('div', {});
    parts.crumb = h('nav', {}, h('a', { href: '#', onclick: (e) => e.preventDefault() }, sample.home || 'Home'), ' / ', h('span', { text: sample.title }));
    parts.title = h('h1', {}, sample.title);
    parts.wrap.append(parts.crumb, parts.title);
    if (kind === 'collection') {
        parts.count = h('span', { text: sample.count });
        parts.title.append(' ', parts.count);
        parts.intro = h('p', { text: sample.intro || '' });
        parts.button = h('a', { href: '#', onclick: (e) => e.preventDefault() }, sample.button || 'All products');
        parts.wrap.append(parts.intro, parts.button);
    }
    restyle(parts, bag, kind, breakpoint, crumb);
    return parts.wrap;
}

/* ------------------------------------------------------------- controls */

/**
 * Draw the controls for one bag into `host`.
 *
 * opts: {bag, spec, kind, dev, onChange(), onDevice(dev), pick(done)}
 * `bag` is edited in place. Returns {redraw()}.
 */
export function controls(host, opts) {
    const { spec } = opts;
    ensureStyle('kbb-phe-css', css);
    const kindOk = (kinds) => kinds.includes(opts.kind);

    function redraw() {
        host.replaceChildren(...build());
    }

    function changed(full) {
        if (full) redraw();
        opts.onChange();
    }

    function build() {
        const dev = opts.dev;
        const half = opts.bag[dev];
        const out = [];

        out.push(h('div', { class: 'kbb-phe-sec' },
            h('p', { class: 'kbb-phe-h', text: 'Device' }),
            h('div', { class: 'kbb-phe-seg', role: 'group', 'aria-label': 'Device' },
                [['d', 'Desktop'], ['m', 'Phone']].map(([v, l]) => h('button', {
                    type: 'button', 'aria-pressed': String(dev === v),
                    onclick: () => { opts.dev = v; if (opts.onDevice) opts.onDevice(v); redraw(); },
                }, l))),
            h('p', { class: 'kbb-phe-help', text: `Desktop is wider than ${spec.breakpoint}px; phone is ${spec.breakpoint}px and narrower. Each has its own settings.` }),
            h('div', { class: 'kbb-phe-row' }, h('button', {
                type: 'button', class: 'kbb-phe-btn',
                onclick: () => { opts.bag[dev === 'd' ? 'm' : 'd'] = clone(half); changed(true); },
            }, dev === 'd' ? 'Copy these settings to phone' : 'Copy these settings to desktop'))));

        // Show / hide
        out.push(h('div', { class: 'kbb-phe-sec' },
            h('p', { class: 'kbb-phe-h', text: 'Show' }),
            h('div', { class: 'kbb-phe-tog' }, spec.shows.filter((s) => kindOk(s.kinds)).map((s) => h('label', {},
                h('input', {
                    type: 'checkbox', checked: half[s.key] ? true : null,
                    onchange: (e) => { half[s.key] = e.currentTarget.checked; changed(true); },
                }), h('span', { text: s.label })))),
            h('p', { class: 'kbb-phe-help', text: 'A hidden title is hidden from view only: it stays in the page as its heading (H1), so search engines and screen readers still read it. The breadcrumb also follows Appearance › Header › Breadcrumbs, which can hide it on every page.' })));

        // Position
        const order = half.order.filter((k) => opts.kind === 'collection' || ['crumb', 'image', 'title'].includes(k));
        const label = (k) => (spec.elements.find((e) => e.key === k) || { label: k }).label;
        const off = (k) => (k === 'title' ? !half.title : !half[k]);
        const move = (k, by) => {
            const all = half.order;
            const vis = order;
            const j = vis.indexOf(k) + by;
            if (j < 0 || j >= vis.length) return;
            const a = all.indexOf(k);
            const b = all.indexOf(vis[j]);
            [all[a], all[b]] = [all[b], all[a]];
            changed(true);
        };
        out.push(h('div', { class: 'kbb-phe-sec' },
            h('p', { class: 'kbb-phe-h', text: 'Position' }),
            h('ol', { class: 'kbb-phe-ord' }, order.map((k, i) => h('li', { class: off(k) ? 'is-off' : null },
                h('span', { text: label(k) }),
                h('button', { type: 'button', 'aria-label': `Move ${label(k)} up`, disabled: i === 0 ? true : null, onclick: () => move(k, -1) }, '↑'),
                h('button', { type: 'button', 'aria-label': `Move ${label(k)} down`, disabled: i === order.length - 1 ? true : null, onclick: () => move(k, 1) }, '↓')))),
            spec.selects.filter((s) => s.key !== 'fit' && (s.key !== 'button_at' || opts.kind === 'collection')).map((s) => h('div', { class: 'kbb-phe-sel' },
                h('span', { text: s.label }),
                h('div', { class: 'kbb-phe-seg', role: 'group', 'aria-label': s.label }, s.options.map((o) => h('button', {
                    type: 'button', 'aria-pressed': String(half[s.key] === o.value),
                    onclick: () => { half[s.key] = o.value; changed(true); },
                }, o.label)))))));

        // Picture
        const pic = (key, title) => {
            const v = safeSrc(opts.bag[key]);
            return h('div', { class: 'kbb-phe-pic' },
                h('div', { class: 'kbb-phe-thumb' }, v ? h('img', { src: v, alt: '' }) : (key === 'img_m' ? 'Same as desktop' : 'No picture')),
                h('div', { class: 'kbb-phe-row' },
                    h('span', { text: title, style: 'width:100%;font-weight:600;font-size:12px' }),
                    h('button', {
                        type: 'button', class: 'kbb-phe-btn',
                        onclick: () => opts.pick((url) => { if (safeSrc(url)) { opts.bag[key] = url; changed(true); } }, key),
                    }, v ? 'Change' : 'Choose'),
                    v ? h('button', { type: 'button', class: 'kbb-phe-btn', onclick: () => { opts.bag[key] = ''; changed(true); } }, 'Remove') : null));
        };
        const fit = spec.selects.find((s) => s.key === 'fit');
        out.push(h('div', { class: 'kbb-phe-sec' },
            h('p', { class: 'kbb-phe-h', text: 'Header picture' }),
            pic('img', 'Picture'),
            pic('img_m', 'Phone picture (optional)'),
            h('label', { class: 'kbb-phe-sel' }, h('span', { text: 'Alt text' }), h('input', {
                type: 'text', maxlength: '160', value: opts.bag.alt || '', placeholder: 'What the picture shows',
                oninput: (e) => { opts.bag.alt = e.currentTarget.value; opts.onChange(); },
            })),
            fit ? h('div', { class: 'kbb-phe-sel' }, h('span', { text: fit.label }), h('div', { class: 'kbb-phe-seg', role: 'group', 'aria-label': fit.label },
                fit.options.map((o) => h('button', {
                    type: 'button', 'aria-pressed': String(half.fit === o.value),
                    onclick: () => { half.fit = o.value; changed(true); },
                }, o.label)))) : null,
            h('p', { class: 'kbb-phe-help', text: 'From the Media Library. “Fill” crops the picture to the height you set; “Whole picture” shows all of it inside that height.' })));

        // Sizes
        out.push(h('div', { class: 'kbb-phe-sec' },
            h('p', { class: 'kbb-phe-h', text: dev === 'd' ? 'Sizes · desktop' : 'Sizes · phone' }),
            spec.numbers.map((n) => {
                const outEl = h('output', { text: `${half[n.key]}px` });
                return h('label', { class: 'kbb-phe-rng' },
                    h('div', {}, h('span', { text: n.label }), outEl),
                    h('input', {
                        type: 'range', min: n.min, max: n.max, step: '1', value: half[n.key],
                        oninput: (e) => { half[n.key] = Number(e.currentTarget.value); outEl.textContent = `${half[n.key]}px`; opts.onChange(); },
                    }));
            })));

        return out;
    }

    redraw();
    return { redraw };
}

/* --------------------------------------------------------- the storefront */

let open = null;

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
    if (b && typeof b.message === 'string' && b.message) return b.message;
    if (b && b.errors && typeof b.errors === 'object') {
        const first = Object.values(b.errors)[0];
        if (Array.isArray(first) && first[0]) return String(first[0]);
    }
    if (res && res.status === 419) return 'Your session has expired. Reload the page and try again.';
    if (res && res.status === 403) return 'Your role cannot change the page header.';
    if (res && res.status === 401) return 'You are no longer signed in to the admin.';
    return fallback;
}

/**
 * Find the header on this page and put it in the configurable shape, if it is
 * not in it already. Returns {parts, restore()} or null when the page has no
 * header this panel understands.
 */
function takeHeader(ctx) {
    const wrapNow = document.querySelector('.kbb-home [data-kbb-ph]');
    if (wrapNow) {
        const saved = wrapNow.cloneNode(true);
        return {
            parts: partsOf(wrapNow),
            restore: () => { const live = document.querySelector('.kbb-home [data-kbb-ph]'); if (live) live.replaceWith(saved); },
        };
    }

    const crumb = document.querySelector('.kbb-home .sec > .wrap > nav.crumb');
    if (ctx.kind === 'collection') {
        const sh = document.querySelector('.kbb-home .sec > .wrap > .sh');
        if (!crumb || !sh) return null;
        const savedCrumb = crumb.cloneNode(true);
        const savedSh = sh.cloneNode(true);
        const wrap = h('div', { 'data-kbb-ph': ctx.key });
        sh.replaceWith(wrap);
        const title = sh.querySelector('h1');
        wrap.append(crumb, ...[title, sh.querySelector('p'), sh.querySelector('a.lnk')].filter(Boolean));
        return {
            parts: partsOf(wrap),
            restore: () => {
                const live = document.querySelector('.kbb-home [data-kbb-ph]');
                if (!live) return;
                live.replaceWith(savedCrumb, document.createTextNode('\n\n    '), savedSh);
            },
        };
    }

    const article = document.querySelector('.kbb-home article.policy');
    const title = article && article.querySelector(':scope > h1');
    if (!crumb || !title) return null;
    const savedCrumb = crumb.cloneNode(true);
    const savedTitle = title.cloneNode(true);
    const marker = crumb.previousSibling;
    const parent = crumb.parentNode;
    const wrap = h('div', { 'data-kbb-ph': ctx.key });
    title.replaceWith(wrap);
    wrap.append(crumb, title);
    return {
        parts: partsOf(wrap),
        restore: () => {
            const live = document.querySelector('.kbb-home [data-kbb-ph]');
            if (!live) return;
            live.replaceWith(savedTitle);
            parent.insertBefore(savedCrumb, marker ? marker.nextSibling : parent.firstChild);
        },
    };
}

function partsOf(wrap) {
    const title = wrap.querySelector(':scope > h1');
    return {
        wrap,
        crumb: wrap.querySelector(':scope > nav'),
        image: wrap.querySelector(':scope > picture'),
        title,
        count: title ? title.querySelector('.cnt, span') : null,
        intro: wrap.querySelector(':scope > p'),
        button: wrap.querySelector(':scope > a'),
    };
}

/** The storefront panel. Called by storefront-admin.js with the context's `pageheader`. */
export function openPanel(ctx, csrf, opener, toast) {
    if (open) { open.focus(); return; }
    ensureStyle('kbb-phe-css', css);
    ensureStyle('kbb-ph-css', ctx.spec.css);

    const taken = takeHeader(ctx);
    if (!taken) {
        if (toast) toast('This page has no header the panel can edit.');
        return;
    }
    const { parts } = taken;
    parts.wrap.classList.add('kbb-phe-live');

    const st = {
        scope: ctx.own ? 'page' : 'global',
        bag: clone(ctx.bag),
        dev: window.matchMedia(`(max-width: ${ctx.spec.breakpoint}px)`).matches ? 'm' : 'd',
        dirty: false,
        busy: false,
    };
    const atPhone = () => window.matchMedia(`(max-width: ${ctx.spec.breakpoint}px)`).matches;
    // Previewing one device's look at the other's width: that device's
    // breadcrumb switch goes with it.
    const crumbFor = (dev) => (dev === 'd' || dev === 'm' ? { d: ctx.spec.crumb[dev], m: ctx.spec.crumb[dev] } : ctx.spec.crumb);
    const live = () => {
        const same = (st.dev === 'm') === atPhone();
        restyle(parts, same ? st.bag : asDevice(st.bag, st.dev), ctx.kind, ctx.spec.breakpoint, same ? ctx.spec.crumb : crumbFor(st.dev));
    };

    const err = h('p', { class: 'kbb-phe-err', role: 'alert' });
    const body = h('div', {});
    const scopeHost = h('div', {});
    const saveBtn = h('button', { type: 'button', class: 'kbb-phe-btn is-primary', onclick: () => save() }, 'Save');
    const pickerHost = h('div', {});
    const panel = h('div', { class: 'kbb-phe', role: 'dialog', 'aria-label': `Edit the header of ${ctx.label}`, tabindex: '-1' },
        h('div', { class: 'kbb-phe-in' },
            h('div', { class: 'kbb-phe-head' }, h('b', { text: `Page header · ${ctx.label}` }),
                h('button', { type: 'button', class: 'kbb-phe-x', 'aria-label': 'Close', onclick: () => close() }, '×')),
            scopeHost, pickerHost, body, err,
            h('div', { class: 'kbb-phe-foot' }, saveBtn,
                h('button', { type: 'button', class: 'kbb-phe-btn', onclick: () => close() }, 'Cancel'),
                safePath(ctx.console) ? h('a', { href: safePath(ctx.console) }, 'Pages › Page header') : null)));

    function drawScope() {
        scopeHost.replaceChildren(h('div', { class: 'kbb-phe-sec' },
            h('p', { class: 'kbb-phe-h', text: 'Apply to' }),
            h('div', { class: 'kbb-phe-seg', role: 'group', 'aria-label': 'Apply to' },
                [['page', 'This page only'], ['global', 'All custom pages']].map(([v, l]) => h('button', {
                    type: 'button', 'aria-pressed': String(st.scope === v),
                    onclick: () => { st.scope = v; drawScope(); },
                }, l))),
            h('p', { class: 'kbb-phe-help', text: st.scope === 'page'
                ? 'Saves a look for this page alone. Every other custom page keeps the global look.'
                : 'Saves the global look for every custom page that has no look of its own, and this page follows it from now on.' }),
            ctx.own ? h('div', { class: 'kbb-phe-row' }, h('button', {
                type: 'button', class: 'kbb-phe-btn',
                onclick: () => { st.bag = clone(ctx.global); st.dirty = true; ui.redraw(); live(); save('inherit'); },
            }, 'Use the global look on this page')) : null));
    }

    const ui = controls(body, {
        get bag() { return st.bag; },
        spec: ctx.spec,
        kind: ctx.kind,
        get dev() { return st.dev; },
        set dev(v) { st.dev = v; },
        onDevice: () => live(),
        onChange: () => { st.dirty = true; live(); },
        pick: (done) => picker(done),
    });

    /* -- the Media Library, in the panel: one page of 24 at a time */
    function picker(done) {
        let page = 1;
        const grid = h('div', { class: 'kbb-phe-grid' });
        const note = h('p', { class: 'kbb-phe-help', text: 'Loading the Media Library…' });
        const more = h('button', { type: 'button', class: 'kbb-phe-btn', hidden: true, onclick: () => { page += 1; load(); } }, 'More');
        const file = h('input', { type: 'file', accept: (ctx.upload.types || []).join(','), hidden: true, onchange: (e) => upload(e.currentTarget.files[0]) });
        const box = h('div', { class: 'kbb-phe-sec' },
            h('p', { class: 'kbb-phe-h', text: 'Choose a picture' }), note, grid,
            h('div', { class: 'kbb-phe-row' }, more,
                h('button', { type: 'button', class: 'kbb-phe-btn', onclick: () => file.click() }, 'Upload new'), file,
                h('button', { type: 'button', class: 'kbb-phe-btn', onclick: () => pickerHost.replaceChildren() }, 'Cancel')));
        pickerHost.replaceChildren(box);

        const choose = (url) => { pickerHost.replaceChildren(); done(url); };

        async function load() {
            more.hidden = true;
            const res = await send(`${ctx.endpoints.media}?page=${page}`);
            if (!res.ok || !res.body || !Array.isArray(res.body.items)) {
                note.textContent = messageOf(res, 'The Media Library could not be read.');
                return;
            }
            note.textContent = res.body.total ? 'Tap a picture to use it.' : 'The Media Library is empty. Upload a picture.';
            res.body.items.forEach((it) => {
                const url = safeSrc(it.url);
                const thumb = safeSrc(it.thumb) || url;
                if (!url) return;
                grid.appendChild(h('button', { type: 'button', 'aria-label': String(it.name || it.filename || 'Picture'), onclick: () => choose(url) },
                    h('img', { src: thumb, alt: '', loading: 'lazy' })));
            });
            more.hidden = !(res.body.pages > page);
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

    async function save(scope) {
        if (st.busy) return;
        st.busy = true;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        err.textContent = '';
        const which = scope || st.scope;
        const res = await send(ctx.endpoints.apply, csrf, { key: ctx.key, scope: which, bag: which === 'inherit' ? null : st.bag });
        st.busy = false;
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (!res.ok || !res.body || res.body.ok !== true) {
            err.textContent = messageOf(res, 'That could not be saved.');
            return;
        }
        ctx.own = !!res.body.own;
        ctx.bag = res.body.bag;
        ctx.global = res.body.global;
        st.bag = clone(ctx.bag);
        st.scope = ctx.own ? 'page' : 'global';
        st.dirty = false;
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
        document.removeEventListener('keydown', onKey);
        panel.remove();
        open = null;
        if (revert) taken.restore();
        else {
            parts.wrap.classList.remove('kbb-phe-live');
            restyle(parts, ctx.bag, ctx.kind, ctx.spec.breakpoint, ctx.spec.crumb);
        }
        if (opener && opener.isConnected) opener.focus({ preventScroll: true });
    }

    drawScope();
    document.addEventListener('keydown', onKey);
    document.body.appendChild(panel);
    open = panel;
    live();
    panel.focus({ preventScroll: true });
}
