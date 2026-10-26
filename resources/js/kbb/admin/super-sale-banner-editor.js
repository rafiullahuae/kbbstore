/**
 * The live Super Sale banner's editor (Lane SSB): the on-page "Edit banner"
 * panel on /super-sale/, and the same controls in the console's
 * Pages → Page banners → Super Sale banner tab.
 *
 * The owner: "allow us the full controls of changing the text size, color
 * etc", "desktop, mobile, eng arabic etc all controls i need", and "give
 * these whole options on front-end of super sale page with editor button on
 * banner."
 *
 * HOW IT IS REACHED. Never from a shop page's HTML. admin-hint.js loads the
 * storefront admin layer only for a browser holding the admin hint cookie;
 * that layer asks the authenticated /admin-api/storefront/context, which
 * answers `salebanner` only to a role with pagebanners.manage and only on
 * /super-sale/; and this chunk is fetched on the first press of "Edit
 * banner". A shopper pays nothing.
 *
 * RULES THIS FILE KEEPS
 *  - The preview is BUILT HERE from the data the context already sent: no
 *    request per keystroke, none per toggle. styleAttr() is
 *    App\Support\SuperSaleBanner::styleAttr() in JavaScript, and
 *    SuperSaleLiveBannerTest runs both on the same configs.
 *  - Every word goes in with textContent or setAttribute. The only markup
 *    parsed is the server's own constants (the tabby/tamara marks and the gift
 *    icon), as an SVG document.
 *  - Every picture address passes safeSrc() before it is an attribute.
 *  - Nothing measures an element. No popups: closing with unsaved changes
 *    keeps them as a draft (memory and localStorage) that the panel offers
 *    back; Discard is a button.
 *  - Every request has a deadline; a 419 (the 120-minute CSRF token) fetches a
 *    fresh token from the context endpoint and retries once.
 */

const TIMEOUT = 20000;
const UPLOAD_TIMEOUT = 90000;
const DRAFT_KEY = 'kbb_ssb_draft';
const PROPS = ['s', 'w', 'c', 'x', 'y', 'v'];
const DEVS = { m: 'Phone', d: 'Computer' };

/** The banner's stylesheet (where the page has not printed it) and the device toggle's rules, which no shop page carries. */
function ensureStyles(spec) {
    for (const [id, text] of [['kbb-ssb-css', spec.css], ['kbb-ssb-css-forced', spec.css_forced]]) {
        if (document.getElementById(id)) continue;
        const st = document.createElement('style');
        st.id = id;
        st.textContent = text;
        document.head.appendChild(st);
    }
}
const LANGS = { en: 'English', ar: 'العربية' };
const clone = (o) => JSON.parse(JSON.stringify(o));

/**
 * PHP sends an empty map as [] -- and a property set on an array is dropped
 * by JSON.stringify, so a style edited onto one would never reach the server.
 * Every level of the style map is made a plain object before anything writes.
 */
const obj = (v) => (v && typeof v === 'object' && !Array.isArray(v) ? v : {});
export function normalise(c) {
    c.st = obj(c.st);
    for (const lang of ['en', 'ar']) {
        c.st[lang] = obj(c.st[lang]);
        for (const dev of ['m', 'd']) {
            if (c.st[lang][dev] === undefined) continue;
            c.st[lang][dev] = obj(c.st[lang][dev]);
            for (const el of Object.keys(c.st[lang][dev])) c.st[lang][dev][el] = obj(c.st[lang][dev][el]);
        }
    }
    c.alpha = obj(c.alpha);
    return c;
}

/* ------------------------------------------------------------- helpers */

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

/** An address the page may use as a picture, or ''. */
export function safeSrc(value) {
    const v = String(value == null ? '' : value).trim();
    if (v === '' || v.length > 500 || /[\x00-\x20"'<>`\\]/.test(v)) return '';
    if (/^https?:\/\/[^/?#@\s]/i.test(v)) return v;
    if (v.startsWith('/') && !v.startsWith('//')) return v;
    return '';
}

/** One of the server's constant SVGs, as nodes. */
function svgOf(markup) {
    const doc = new DOMParser().parseFromString(String(markup || ''), 'image/svg+xml');
    const el = doc.documentElement;
    return el && el.nodeName === 'svg' ? document.importNode(el, true) : null;
}

/** A number as SuperSaleBanner::num() prints it. */
export function num(v) {
    const s = Number(v).toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    return s === '-0' || s === '' ? '0' : s;
}

/* ------------------------------------------- the style, as the server does */

/** One property as the page draws it: Arabic's own, else English's, else the shipped value. */
export function resolve(st, defaults, lang, dev, el, p) {
    const own = st && st[lang] && st[lang][dev] && st[lang][dev][el] ? st[lang][dev][el][p] : undefined;
    if (own !== undefined && own !== null) return own;
    if (lang === 'ar') {
        const en = st && st.en && st.en[dev] && st.en[dev][el] ? st.en[dev][el][p] : undefined;
        if (en !== undefined && en !== null) return en;
    }
    return defaults[lang][dev][el][p];
}

/** App\Support\SuperSaleBanner::styleAttr(), in JavaScript. */
export function styleAttr(c, spec, lang) {
    const st = c.st || { en: {}, ar: {} };
    const parts = [];
    for (const dev of ['m', 'd']) {
        const def = spec.style_defaults[lang][dev];
        for (const { key: el } of spec.elements) {
            for (const p of PROPS) {
                const v = resolve(st, spec.style_defaults, lang, dev, el, p);
                const same = p === 'v' ? Boolean(v) === Boolean(def[el][p]) : String(v) === String(def[el][p]);
                if (same) continue;
                let out;
                if (p === 's') out = num(Number(v) / spec.ucap[dev]);
                else if (p === 'x' || p === 'y') out = `${num(v)}px`;
                else if (p === 'v') out = v ? spec.element_display[el] : 'none';
                else out = String(v);
                parts.push(`--ssb-${el}-${p}${dev}:${out}`);
            }
        }
        const al = resolve(st, spec.style_defaults, lang, dev, '_', 'al');
        if (al !== def._.al) {
            parts.push(`--ssb-al-a${dev}:${spec.align_css[al][0]}`);
            parts.push(`--ssb-al-t${dev}:${spec.align_css[al][1]}`);
        }
        const g = resolve(st, spec.style_defaults, lang, dev, '_', 'g');
        if (String(g) !== String(def._.g)) parts.push(`--ssb-g${dev}:${num(Number(g) / spec.ucap[dev])}`);
    }
    for (const dev of ['m', 'd']) {
        const [x, y] = spec.focus[dev];
        const fx = parseInt(c[`fx_${dev}`] ?? x, 10);
        const fy = parseInt(c[`fy_${dev}`] ?? y, 10);
        if (fx !== x || fy !== y) parts.push(`--ssb-fp${dev}:${fx}% ${fy}%`);
    }
    return parts.join(';');
}

/** App\Support\SuperSaleBanner::pictures(), in JavaScript (before Save, a new picture's transparency is not known yet). */
export function pictures(c, ctx, lang) {
    const pic = (slot) => {
        const url = slot === '' ? safeSrc(ctx.shipped.url) : safeSrc(c[slot]);
        if (!url) return null;
        return { url, alpha: slot === '' ? !!ctx.shipped.alpha : !!(c.alpha && c.alpha[slot]) };
    };
    const slot = lang === 'ar' && c.model_ar ? 'model_ar' : (c.model ? 'model' : '');
    const m = pic(slot);
    if (!m) return null;
    let d = null;
    if (c.mode_d === 'rect') {
        const rslot = lang === 'ar' && c.model_d_ar ? 'model_d_ar' : (c.model_d ? 'model_d' : '');
        d = rslot ? pic(rslot) : null;
    }
    const shape = (dev) => (c[`shape_${dev}`] === 'auto' ? (m.alpha ? 'none' : 'clip') : c[`shape_${dev}`]);
    let cls = `ssb-shm-${shape('m')}`;
    cls += d ? ` ssb-mode-rect${c.fit_d === 'contain' ? ' ssb-fit-contain' : ''}` : ` ssb-shd-${shape('d')}`;
    return { m, d, cls };
}

/**
 * partials/super-sale-banner.blade.php, built here. `force` is 'm', 'd' or
 * null: the preview's device toggle (.ssb-fm / .ssb-fd), which shows a
 * layout whatever the window's width is.
 */
export function buildBanner(ctx, c, lang, force) {
    const spec = ctx.spec;
    const ar = lang === 'ar';
    const t = (k) => String(c[`${k}_${lang}`] || '');
    const root = h('div', { class: `kbb-ssb${force ? ` ssb-f${force}` : ''}`, dir: ar ? 'rtl' : 'ltr', 'data-ssb': '' });
    const style = styleAttr(c, spec, lang);
    if (style) root.setAttribute('style', style);

    const copy = h('div', { class: 'ssb-copy' });
    if (c.logos || t('pay')) {
        const pay = h('div', { class: 'ssb-pay' });
        if (c.logos) {
            pay.append(...[svgOf(spec.logos.tabby), svgOf(ar ? spec.logos.tamara_ar : spec.logos.tamara)].filter(Boolean));
        }
        if (t('pay')) pay.appendChild(h('span', { class: 'ssb-paytxt', text: t('pay') }));
        copy.appendChild(pay);
    }
    if (t('tagline')) copy.appendChild(h('span', { class: 'ssb-tag', text: t('tagline') }));
    if (t('title')) copy.appendChild(h('div', { class: 'ssb-super', text: t('title') }));
    if (String(c.code || '')) {
        copy.appendChild(h('span', { class: 'ssb-coupon' }, t('coupon') ? h('span', { text: t('coupon') }) : null, h('b', { text: String(c.code) })));
    }

    const hero = h('div', { class: 'ssb-hero' }, copy);
    const pics = c.model_on ? pictures(c, ctx, lang) : null;
    if (pics) {
        const img = h('img', { alt: '', width: '381', height: '500', decoding: 'async' });
        if (force) {
            // The window's width would choose the wrong <source>: the preview
            // shows the device it was asked for.
            img.src = force === 'd' && pics.d ? pics.d.url : pics.m.url;
            hero.appendChild(h('div', { class: `ssb-model ${pics.cls}` }, h('picture', {}, img)));
        } else {
            img.src = pics.m.url;
            hero.appendChild(h('div', { class: `ssb-model ${pics.cls}` }, h('picture', {},
                pics.d ? h('source', { media: '(min-width: 901px)', srcset: pics.d.url }) : null, img)));
        }
    }

    const inner = h('div', { class: 'ssb-in' }, hero);
    const gifts = c.gifts_on ? (ctx.gifts && ctx.gifts[c.band]) || [] : [];
    if (gifts.length) {
        const circs = h('span', { class: 'ssb-circs' });
        gifts.forEach((g) => {
            const src = safeSrc(g.src);
            if (src) circs.appendChild(h('span', { class: 'ssb-c' }, h('img', { src, alt: String(g.alt || ''), width: '62', height: '62', decoding: 'async' })));
        });
        const gi = h('span', { class: 'ssb-gi' }, svgOf(spec.gift_icon), t('gifts') ? h('span', { text: t('gifts') }) : null);
        inner.appendChild(h('div', { class: 'ssb-gifts' }, gi, circs, t('free') ? h('span', { class: 'ssb-free', text: t('free') }) : null));
    }
    root.appendChild(inner);
    return root;
}

/* ---------------------------------------------------------- the controls */

/**
 * Every control, for the language and device being edited. `st` is
 * {c, lang, dev}; `on.change()` redraws the preview, `on.pick(slot)` opens
 * the picture chooser. Returns the element; the caller replaces it when the
 * language or device changes.
 */
export function controls(ctx, st, on) {
    const spec = ctx.spec;
    const c = st.c;
    normalise(c);
    const { lang, dev } = st;
    const where = `${LANGS[lang]} · ${DEVS[dev]}`;
    const changed = () => on.change();

    const field = (label, input, help) => h('label', { class: 'ssb-ed-f' }, h('span', { class: 'ssb-ed-l', text: label }), input, help ? h('small', { class: 'ssb-ed-help', text: help }) : null);
    const check = (key, label) => h('label', { class: 'ssb-ed-chk' },
        h('input', { type: 'checkbox', checked: !!c[key], onchange: (e) => { c[key] = e.currentTarget.checked; changed(); } }), h('span', { text: label }));
    const select = (value, options, set) => {
        const s = h('select', { class: 'ssb-ed-in', onchange: (e) => { set(e.currentTarget.value); changed(); } });
        Object.entries(options).forEach(([v, l]) => s.appendChild(h('option', { value: v, selected: v === String(value), text: l })));
        return s;
    };
    const section = (title, open, ...kids) => h('details', { class: 'ssb-ed-sec', open }, h('summary', { text: title }), ...kids);

    /* -- the banner */
    const banner = section('Banner', true,
        check('on', 'Show the live banner (off = the header picture, as before)'),
        check('logos', 'tabby and tamara logos'),
        check('model_on', 'The picture'),
        check('gifts_on', 'The free gifts row'),
        field('Coupon code (both languages)', h('input', {
            class: 'ssb-ed-in', maxlength: '24', value: String(c.code || ''), placeholder: 'GLOW',
            oninput: (e) => { c.code = e.currentTarget.value; changed(); },
        }), 'Must be one of the shop’s coupons — Save refuses a code that is not. Empty = no coupon line.'));

    /* -- the words */
    const words = section(`Words — ${LANGS[lang]}`, false,
        ...ctx.texts.map((t) => field(t.label, h('input', {
            class: 'ssb-ed-in', maxlength: String(spec.max_text), dir: lang === 'ar' ? 'rtl' : 'ltr', lang,
            value: String(c[`${t.key}_${lang}`] ?? ''), placeholder: lang === 'ar' ? t.ar : t.en,
            oninput: (e) => { c[`${t.key}_${lang}`] = e.currentTarget.value; changed(); },
        }))),
        h('small', { class: 'ssb-ed-help', text: 'Empty a line and it is not drawn.' }));

    /* -- one element's style, with its inheritance shown */
    const own = (el, p) => {
        const b = c.st[lang] && c.st[lang][dev] && c.st[lang][dev][el];
        return b && b[p] !== undefined && b[p] !== null ? b[p] : null;
    };
    const set = (el, p, v) => {
        c.st[lang] = c.st[lang] || {};
        c.st[lang][dev] = c.st[lang][dev] || {};
        c.st[lang][dev][el] = c.st[lang][dev][el] || {};
        if (v === null) delete c.st[lang][dev][el][p];
        else c.st[lang][dev][el][p] = v;
    };
    const value = (el, p) => resolve(c.st, spec.style_defaults, lang, dev, el, p);
    const state = (el, props) => {
        if (props.some((p) => own(el, p) !== null)) return lang === 'ar' ? 'Arabic only' : 'Changed';
        if (lang === 'ar' && props.some((p) => (c.st.en && c.st.en[dev] && c.st.en[dev][el] && c.st.en[dev][el][p] != null))) return 'Same as English';
        return lang === 'ar' ? 'Same as English' : 'As shipped';
    };
    const range = (p) => spec.ranges[p];
    const numIn = (el, p, step) => h('input', {
        class: 'ssb-ed-in ssb-ed-num', type: 'number', step: String(step), min: String(range(p)[0]), max: String(range(p)[1]), value: String(value(el, p)),
        oninput: (e) => { const v = e.currentTarget.value; if (v === '' || Number.isNaN(Number(v))) return; set(el, p, Math.max(range(p)[0], Math.min(range(p)[1], Number(v)))); changed(); },
    });

    const elementRow = (el, label) => {
        const chip = h('span', { class: 'ssb-ed-chip', text: state(el, PROPS) });
        const color = h('input', { type: 'color', value: String(value(el, 'c')).toLowerCase(), 'aria-label': `${label} colour` });
        const hex = h('input', { class: 'ssb-ed-in ssb-ed-hex', maxlength: '7', value: String(value(el, 'c')), 'aria-label': `${label} colour, hex` });
        color.addEventListener('input', () => { set(el, 'c', color.value.toUpperCase()); hex.value = color.value.toUpperCase(); chip.textContent = state(el, PROPS); changed(); });
        hex.addEventListener('input', () => {
            if (!/^#[0-9a-f]{6}$/i.test(hex.value)) { hex.classList.add('is-bad'); return; }
            hex.classList.remove('is-bad'); set(el, 'c', hex.value.toUpperCase()); color.value = hex.value.toLowerCase(); chip.textContent = state(el, PROPS); changed();
        });
        const weights = {};
        spec.weights.forEach((w) => { weights[w] = String(w); });
        const row = h('div', { class: 'ssb-ed-el' },
            h('div', { class: 'ssb-ed-elh' }, h('b', { text: label }), chip,
                h('button', { type: 'button', class: 'ssb-ed-link', text: lang === 'ar' ? 'Use English' : 'Reset', onclick: () => {
                    PROPS.forEach((p) => set(el, p, null)); on.redraw(); changed();
                } })),
            h('div', { class: 'ssb-ed-grid' },
                h('label', { class: 'ssb-ed-chk' }, h('input', { type: 'checkbox', checked: !!value(el, 'v'), onchange: (e) => { set(el, 'v', e.currentTarget.checked); chip.textContent = state(el, PROPS); changed(); } }), h('span', { text: 'Show' })),
                field('Size (px)', numIn(el, 's', 0.5)),
                field('Weight', select(value(el, 'w'), weights, (v) => { set(el, 'w', Number(v)); chip.textContent = state(el, PROPS); })),
                h('div', { class: 'ssb-ed-f' }, h('span', { class: 'ssb-ed-l', text: 'Colour' }), h('div', { class: 'ssb-ed-color' }, color, hex)),
                field('Move across (px)', numIn(el, 'x', 1)),
                field('Move up or down (px)', numIn(el, 'y', 1))));
        row.addEventListener('input', () => { chip.textContent = state(el, PROPS); });
        return row;
    };

    const styles = section(`Text style — ${where}`, true,
        h('small', { class: 'ssb-ed-help', text: lang === 'ar'
            ? 'Arabic takes each value from English until you change it here; “Same as English” puts it back.'
            : 'Sizes are in px at full width and shrink with a narrower screen. “Reset” puts the shipped value back.' }),
        ...spec.elements.map((e) => elementRow(e.key, e.label)));

    const aligns = {};
    Object.entries(spec.aligns).forEach(([k, l]) => { aligns[k] = l; });
    const layout = section(`Layout — ${where}`, false,
        field('Text alignment', select(value('_', 'al'), aligns, (v) => set('_', 'al', v))),
        field('Space between the lines (px)', numIn('_', 'g', 0.5)),
        h('button', { type: 'button', class: 'ssb-ed-link', text: lang === 'ar' ? 'Use English' : 'Reset', onclick: () => { set('_', 'al', null); set('_', 'g', null); on.redraw(); changed(); } }));

    /* -- the pictures */
    const rect = dev === 'd' && c.mode_d === 'rect';
    const slot = rect ? (lang === 'ar' ? 'model_d_ar' : 'model_d') : (lang === 'ar' ? 'model_ar' : 'model');
    const current = safeSrc(c[slot]);
    const inherited = lang === 'ar'
        ? (safeSrc(c[rect ? 'model_d' : 'model']) || (rect ? '' : safeSrc(ctx.shipped.url)))
        : (rect ? '' : safeSrc(ctx.shipped.url));
    const shown = current || inherited;
    const picNote = current
        ? (c.alpha && c.alpha[slot] ? 'Your picture — transparent background.' : 'Your picture. Its transparency is checked when you save.')
        : (lang === 'ar' ? 'Same as English.' : (rect ? 'No rectangle picture yet — the computer shows the single picture until you add one.' : 'The shipped picture: the lady, transparent background.'));
    const pics = section(`Picture — ${where}`, false,
        dev === 'd' ? field('On a computer', select(c.mode_d, spec.modes, (v) => { c.mode_d = v; on.redraw(); }),
            'Single image: the same picture as the phone. Rectangle design: one wide picture you design — make it 1440 × 700 px.') : null,
        h('div', { class: 'ssb-ed-pic' },
            shown ? h('img', { src: shown, alt: '' }) : h('span', { text: 'No picture' }),
            h('div', {},
                h('p', { class: 'ssb-ed-help', text: picNote }),
                h('div', { class: 'ssb-ed-row' },
                    h('button', { type: 'button', class: 'ssb-ed-btn', text: current ? 'Change picture' : 'Add a picture', onclick: () => on.pick(slot) }),
                    current ? h('button', { type: 'button', class: 'ssb-ed-btn', text: lang === 'ar' ? 'Same as English' : (rect ? 'Remove' : 'Use the shipped picture'), onclick: () => {
                        c[slot] = ''; if (c.alpha) delete c.alpha[slot]; on.redraw(); changed();
                    } }) : null))),
        rect
            ? field('Fit', select(c.fit_d, spec.fits, (v) => { c.fit_d = v; }))
            : field(`Backdrop — ${DEVS[dev]}`, select(c[`shape_${dev}`], spec.shapes, (v) => { c[`shape_${dev}`] = v; }),
                'Automatic: a transparent picture stands on the banner with nothing behind it; a photo is cut into the arch.'),
        field(`Focus, across (%) — ${DEVS[dev]}`, h('input', { class: 'ssb-ed-in', type: 'range', min: '0', max: '100', value: String(c[`fx_${dev}`]), oninput: (e) => { c[`fx_${dev}`] = Number(e.currentTarget.value); changed(); } })),
        field(`Focus, down (%) — ${DEVS[dev]}`, h('input', { class: 'ssb-ed-in', type: 'range', min: '0', max: '100', value: String(c[`fy_${dev}`]), oninput: (e) => { c[`fy_${dev}`] = Number(e.currentTarget.value); changed(); } })),
        h('small', { class: 'ssb-ed-help', text: 'Focus is the point a cropped picture keeps in view.' }));

    /* -- the gifts */
    const bands = {};
    ctx.bands.forEach((b) => { bands[b.value] = b.label; });
    const gifts = section('Free gifts row', false,
        field('Which gifts', select(c.band, bands, (v) => { c.band = v; })),
        h('small', { class: 'ssb-ed-help', text: `Your real gifts from Marketing › Free gifts, picked the way the cart’s gift box picks them.${ctx.gifts_mode !== 'live' ? ` Free gifts are ${ctx.gifts_mode} now.` : ''}` }));

    return h('div', { class: 'ssb-ed-body' }, banner, words, styles, layout, pics, gifts);
}

/* -------------------------------------------------------- requests */

async function send(url, headers, body) {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), TIMEOUT);
    try {
        const res = await fetch(url, {
            method: body === undefined ? 'GET' : 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), ...headers() },
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
    if (b && typeof b.error === 'string' && b.error) return b.error;
    if (res && res.status === 403) return 'Your role cannot change the Super Sale banner.';
    if (res && res.status === 401) return 'You are no longer signed in to the admin.';
    return fallback;
}

/**
 * The picture chooser: the Media Library, a page at a time, and an upload.
 * `headers()` gives the request's CSRF header.
 */
export function chooser(ctx, headers, done) {
    let page = 1;
    const grid = h('div', { class: 'ssb-ed-grid4' });
    const note = h('p', { class: 'ssb-ed-help', text: 'Loading the Media Library…' });
    const more = h('button', { type: 'button', class: 'ssb-ed-btn', hidden: true, text: 'More', onclick: () => { page += 1; load(); } });
    const file = h('input', { type: 'file', hidden: true, accept: (ctx.upload.types || []).join(','), onchange: (e) => upload(e.currentTarget.files[0]) });
    const box = h('div', { class: 'ssb-ed-chooser', role: 'group', 'aria-label': 'Choose a picture' },
        h('div', { class: 'ssb-ed-row' }, h('b', { text: 'Choose a picture' }),
            h('button', { type: 'button', class: 'ssb-ed-btn', text: 'Upload new', onclick: () => file.click() }), file,
            h('button', { type: 'button', class: 'ssb-ed-btn', text: 'Cancel', onclick: () => box.remove() })),
        note, grid, more);

    const choose = (url) => { box.remove(); done(url); };

    async function load() {
        more.hidden = true;
        const res = await send(`${ctx.endpoints.media}?page=${page}`, headers);
        if (!res.ok || !res.body || !Array.isArray(res.body.items)) { note.textContent = messageOf(res, 'The Media Library could not be read.'); return; }
        note.textContent = res.body.total ? 'Tap a picture to use it.' : 'The Media Library is empty. Upload a picture.';
        res.body.items.forEach((it) => {
            const url = safeSrc(it.url);
            const thumb = safeSrc(it.thumb) || url;
            if (url) grid.appendChild(h('button', { type: 'button', class: 'ssb-ed-thumb', 'aria-label': String(it.name || it.filename || 'Picture'), onclick: () => choose(url) }, h('img', { src: thumb, alt: '', loading: 'lazy' })));
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
        Object.entries(headers()).forEach(([k, v]) => req.setRequestHeader(k, v));
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
    return box;
}

/* ----------------------------------------------------- the on-page panel */

const CSS = '@media (min-width:1100px){html.ssb-ed-open body{margin-inline-end:400px}}'
    + '.ssb-ed{position:fixed;z-index:2147483000;top:0;bottom:0;inset-inline-end:0;width:min(400px,100vw);display:flex;flex-direction:column;background:#fff;color:#2a2228;'
    + 'box-shadow:-10px 0 40px -18px rgba(0,0,0,.45);font:400 13.5px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}'
    + '@media (max-width:640px){.ssb-ed{top:auto;width:100vw;height:50vh;border-radius:16px 16px 0 0;box-shadow:0 -10px 40px -18px rgba(0,0,0,.45)}}'
    + '.ssb-ed-head{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #eee}.ssb-ed-head b{flex:1;font-size:15px}'
    + '.ssb-ed-x{border:0;background:none;font-size:20px;line-height:1;cursor:pointer;padding:4px 8px;color:inherit}'
    + '.ssb-ed-bar{display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px;border-bottom:1px solid #eee}'
    + '.ssb-ed-seg{display:inline-flex;background:#f3edf0;border-radius:99px;padding:3px}.ssb-ed-seg button{border:0;background:none;font:inherit;font-weight:600;font-size:12.5px;padding:6px 12px;border-radius:99px;cursor:pointer;color:#6b5f66}'
    + '.ssb-ed-seg button[aria-pressed="true"]{background:#e4457c;color:#fff}'
    + '.ssb-ed-scroll{flex:1;overflow:auto;padding:4px 14px 14px;overscroll-behavior:contain}'
    + '.ssb-ed-foot{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 14px;border-top:1px solid #eee}.ssb-ed-msg{flex:1 1 100%;font-size:12.5px;color:#6b5f66;min-height:1em}.ssb-ed-msg.is-bad{color:#b4443c}'
    + '.ssb-ed-btn{border:1px solid #ddd2d8;background:#fff;border-radius:9px;padding:7px 12px;font:inherit;font-weight:600;cursor:pointer;color:inherit}.ssb-ed-btn.is-primary{background:#e4457c;border-color:#e4457c;color:#fff}.ssb-ed-btn[disabled]{opacity:.5;cursor:default}'
    + '.ssb-ed-sec{border-bottom:1px solid #f0e8ec;padding:6px 0}.ssb-ed-sec>summary{cursor:pointer;font-weight:700;padding:8px 0;list-style-position:inside}'
    + '.ssb-ed-f{display:grid;gap:4px;margin:8px 0;min-width:0}.ssb-ed-l{font-size:12px;font-weight:600;color:#6b5f66}.ssb-ed-help{display:block;color:#857980;font-size:12px;margin:4px 0}'
    + '.ssb-ed-in{width:100%;box-sizing:border-box;border:1px solid #ddd2d8;border-radius:8px;padding:7px 9px;font:inherit;color:inherit;background:#fff;min-width:0}.ssb-ed-in.is-bad{border-color:#b4443c}'
    + '.ssb-ed-chk{display:flex;align-items:center;gap:8px;margin:6px 0;font-weight:500}'
    + '.ssb-ed-el{border:1px solid #f0e8ec;border-radius:10px;padding:8px 10px;margin:8px 0}.ssb-ed-elh{display:flex;align-items:center;gap:8px}.ssb-ed-elh b{flex:1}'
    + '.ssb-ed-chip{font-size:11px;font-weight:700;background:#f3edf0;color:#6b5f66;border-radius:99px;padding:2px 8px}'
    + '.ssb-ed-link{border:0;background:none;color:#c3306a;font:inherit;font-size:12px;font-weight:600;cursor:pointer;padding:2px 0}'
    + '.ssb-ed-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 10px;align-items:end}.ssb-ed-grid .ssb-ed-f{margin:5px 0}'
    + '.ssb-ed-color{display:flex;gap:6px;align-items:center}.ssb-ed-color input[type=color]{width:34px;height:32px;padding:0;border:1px solid #ddd2d8;border-radius:8px;background:#fff;flex:none}'
    + '.ssb-ed-pic{display:grid;grid-template-columns:96px minmax(0,1fr);gap:10px;align-items:center;margin:8px 0}.ssb-ed-pic>img,.ssb-ed-pic>span{width:96px;height:96px;object-fit:contain;background:#f6f0f3;border-radius:10px;display:grid;place-items:center;font-size:12px;color:#857980}'
    + '.ssb-ed-row{display:flex;flex-wrap:wrap;gap:6px;align-items:center}'
    + '.ssb-ed-chooser{border:1px solid #f0e8ec;border-radius:10px;padding:10px;margin:8px 0;background:#fffafc}.ssb-ed-grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px}'
    + '.ssb-ed-thumb{border:1px solid #eee;border-radius:8px;padding:0;background:#fff;cursor:pointer;aspect-ratio:1}.ssb-ed-thumb img{width:100%;height:100%;object-fit:contain;border-radius:8px;display:block}'
    + '.ssb-ed-off{opacity:.45}';

let openPanelState = null;

function readDraft() {
    try { const v = window.localStorage.getItem(DRAFT_KEY); return v ? JSON.parse(v) : null; } catch (e) { return null; }
}
function writeDraft(c) {
    try { if (c) window.localStorage.setItem(DRAFT_KEY, JSON.stringify(c)); else window.localStorage.removeItem(DRAFT_KEY); } catch (e) { /* storage blocked: the draft lives until the tab closes */ }
}

/**
 * The on-page editor. `ctx` is the context's `salebanner`; `csrf` the
 * context's token; `opener` the pill; `toast(text)` the layer's toast.
 */
export function openPanel(ctx, csrf, opener, toast) {
    if (openPanelState) return;
    if (!document.getElementById('kbb-ssb-ed-css')) {
        const st = document.createElement('style');
        st.id = 'kbb-ssb-ed-css';
        st.textContent = CSS;
        document.head.appendChild(st);
    }
    ensureStyles(ctx.spec);

    const pageLang = document.documentElement.lang === 'ar' ? 'ar' : 'en';
    // The element the preview draws into: the banner on the page, or -- with
    // the banner switched off -- a place beside the header picture, which is
    // hidden while the panel is open and shown again if it stays off.
    const headerPic = document.querySelector('.kbb-ph>picture.kbb-ph-i');
    let current = document.querySelector('.kbb-ssb[data-ssb]');
    const hadBanner = !!current;
    if (!current) {
        current = h('div', { 'data-ssb-holder': '' });
        const at = document.querySelector('[data-kbb-ph] > nav') || document.querySelector('.kbb-home .sec > .wrap > *');
        if (at) at.after(current);
        else document.querySelector('.kbb-home')?.prepend(current);
    }
    if (headerPic) headerPic.hidden = true;
    const draft = readDraft();
    const st = {
        c: normalise(clone(draft || ctx.config)),
        lang: pageLang,
        dev: window.matchMedia('(max-width: 900px)').matches ? 'm' : 'd',
        dirty: !!draft,
        busy: false,
        token: csrf,
    };
    openPanelState = st;

    const msg = h('p', { class: 'ssb-ed-msg', role: 'status', text: draft ? 'Your unsaved changes are back. Save them, or Discard.' : '' });
    const scroll = h('div', { class: 'ssb-ed-scroll' });
    const saveBtn = h('button', { type: 'button', class: 'ssb-ed-btn is-primary', text: 'Save', onclick: () => save() });
    const discardBtn = h('button', { type: 'button', class: 'ssb-ed-btn', text: 'Discard changes', onclick: () => discard() });
    const seg = (options, current, set) => h('div', { class: 'ssb-ed-seg', role: 'group' }, ...Object.entries(options).map(([k, l]) => h('button', {
        type: 'button', 'aria-pressed': String(k === current()), text: l,
        onclick: (e) => { set(k); e.currentTarget.parentNode.querySelectorAll('button').forEach((b, i) => b.setAttribute('aria-pressed', String(Object.keys(options)[i] === current()))); redraw(); preview(); },
    })));
    const panel = h('aside', { class: 'ssb-ed', role: 'dialog', 'aria-label': 'Edit the Super Sale banner' },
        h('div', { class: 'ssb-ed-head' }, h('b', { text: 'Super Sale banner' }), h('button', { type: 'button', class: 'ssb-ed-x', 'aria-label': 'Close', text: '×', onclick: () => close() })),
        h('div', { class: 'ssb-ed-bar' },
            seg(LANGS, () => st.lang, (v) => { st.lang = v; }),
            seg(DEVS, () => st.dev, (v) => { st.dev = v; })),
        scroll,
        h('div', { class: 'ssb-ed-foot' }, msg, saveBtn, discardBtn,
            h('small', { class: 'ssb-ed-help', text: 'Also in the console: Pages › Page banners › Super Sale banner.' })));

    const headers = () => ({ 'X-CSRF-TOKEN': st.token });

    function redraw() {
        const top = scroll.scrollTop;
        scroll.replaceChildren(controls(ctx, st, {
            change: () => { st.dirty = true; msg.classList.remove('is-bad'); msg.textContent = 'Unsaved changes.'; preview(); },
            redraw: () => { redraw(); preview(); },
            pick: (slot) => {
                const box = chooser(ctx, headers, (url) => { st.c[slot] = url; if (st.c.alpha) delete st.c.alpha[slot]; st.dirty = true; msg.textContent = 'Unsaved changes.'; redraw(); preview(); });
                scroll.prepend(box);
                scroll.scrollTop = 0;
            },
        }));
        scroll.scrollTop = top;
    }

    /** The banner on the page, redrawn from the unsaved state: no request. */
    function preview() {
        const el = buildBanner(ctx, st.c, st.lang, st.dev);
        if (!st.c.on) el.classList.add('ssb-ed-off');
        current.replaceWith(el);
        current = el;
    }

    async function save() {
        if (st.busy) return;
        st.busy = true;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        msg.classList.remove('is-bad');
        let res = await send(ctx.endpoints.save, headers, { super_sale_banner: st.c });
        if (res.status === 419) {
            // The CSRF token outlived its 120 minutes: ask for a fresh one, once.
            const fresh = await send(`${ctx.endpoints.context}?path=${encodeURIComponent(window.location.pathname)}`, () => ({}));
            if (fresh.ok && fresh.body && typeof fresh.body.csrf === 'string') {
                st.token = fresh.body.csrf;
                res = await send(ctx.endpoints.save, headers, { super_sale_banner: st.c });
            }
        }
        st.busy = false;
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save';
        if (!res.ok || !res.body || !res.body.sale_banner) {
            msg.classList.add('is-bad');
            msg.textContent = messageOf(res, 'That could not be saved.');
            return;
        }
        Object.assign(ctx, res.body.sale_banner);
        st.c = normalise(clone(ctx.config));
        st.dirty = false;
        writeDraft(null);
        msg.textContent = 'Saved. It is on the shop now.';
        redraw();
        preview();
        if (toast) toast('Super Sale banner saved.');
    }

    function discard() {
        st.c = normalise(clone(ctx.config));
        st.dirty = false;
        writeDraft(null);
        msg.classList.remove('is-bad');
        msg.textContent = 'Back to the saved banner.';
        redraw();
        preview();
    }

    function onKey(e) { if (e.key === 'Escape') close(); }

    /** Close: the page shows the saved banner again; unsaved changes stay as a draft. */
    function close() {
        writeDraft(st.dirty ? st.c : null);
        if (ctx.config.on) {
            const el = buildBanner(ctx, ctx.config, pageLang, null);
            current.replaceWith(el);
            current = el;
            if (opener) { el.parentNode.classList.add('kbb-qe-host'); el.parentNode.appendChild(opener); }
        } else {
            // Off: the header picture is the page's again (one the page did
            // not print, because the banner was on, comes back on a reload).
            current.remove();
            if (headerPic) headerPic.hidden = false;
            if (hadBanner && toast) toast('The banner is off. Reload the page to see the header picture.');
        }
        if (st.dirty && toast) toast('Your unsaved changes are kept. Press Edit banner to carry on.');
        document.removeEventListener('keydown', onKey);
        document.documentElement.classList.remove('ssb-ed-open');
        panel.remove();
        openPanelState = null;
        if (opener) opener.focus();
    }

    document.addEventListener('keydown', onKey);
    // On a wide screen the page makes room for the panel, so the whole banner
    // is in view; on a phone the banner is brought to the top, above the sheet.
    document.documentElement.classList.add('ssb-ed-open');
    document.body.appendChild(panel);
    redraw();
    preview();
    // The banner is at the top of the page: there, nothing (the sticky header
    // included) is in front of it, and on a phone it ends above the sheet.
    window.scrollTo(0, 0);
    panel.querySelector('button').focus();
}

/* ---------------------------------------------------- the console's tab */

/**
 * Pages → Page banners → Super Sale banner: the same controls and preview in
 * the console. `sb` is the payload's `sale_banner` (its config is the state
 * the console saves with everything else); `headers()` the console's CSRF
 * header; `onChange()` tells the console's draft registry.
 */
export function mountConsole(host, sb, headers, onChange) {
    if (!document.getElementById('kbb-ssb-ed-css')) {
        const s = document.createElement('style');
        s.id = 'kbb-ssb-ed-css';
        s.textContent = CSS;
        document.head.appendChild(s);
    }
    ensureStyles(sb.spec);
    const st = host.__ssb || { lang: 'en', dev: 'd' };
    st.c = normalise(sb.config);
    host.__ssb = st;

    const stage = h('div', { class: 'ssb-ed-stage' });
    const body = h('div', {});
    const seg = (options, key) => h('div', { class: 'ssb-ed-seg', role: 'group' }, ...Object.entries(options).map(([k, l]) => h('button', {
        type: 'button', 'aria-pressed': String(k === st[key]), text: l, onclick: () => { st[key] = k; mountConsole(host, sb, headers, onChange); },
    })));
    const draw = () => stage.replaceChildren(buildBanner(sb, st.c, st.lang, st.dev));
    const redraw = () => body.replaceChildren(controls(sb, st, {
        change: () => { draw(); onChange(); },
        redraw: () => { redraw(); draw(); },
        pick: (slot) => body.prepend(chooser(sb, headers, (url) => { st.c[slot] = url; if (st.c.alpha) delete st.c.alpha[slot]; redraw(); draw(); onChange(); })),
    }));
    host.replaceChildren(
        h('div', { class: 'ssb-ed-bar', style: 'padding:0 0 10px;border:0' }, seg(LANGS, 'lang'), seg(DEVS, 'dev')),
        h('div', { style: 'border:1px solid #eee;border-radius:12px;overflow:hidden;margin-bottom:12px' }, stage),
        body);
    draw();
    redraw();
}
