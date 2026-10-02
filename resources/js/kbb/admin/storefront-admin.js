/**
 * The storefront admin layer: the thin bar and the quick-edit pencil. (Lane RA)
 *
 * Loaded ONLY by admin-hint.js, and only when the administrator's hint cookie
 * is present. Nothing here runs for a shopper. See that file, and
 * App\Http\Controllers\Admin\StorefrontAdminController, for the whole design.
 *
 * The owner, verbatim:
 *   "this button will open a beautiful popup having all the options for this
 *    specific page (category or brand) to upload/change background image,
 *    title, description etc etc ... the save button on poupup will save
 *    everything smooth and update the page without being refreshed and close
 *    the popup auto. i wan super smooth function with drag n drop image upload
 *    function. must not hang or give any error or disturb anything else."
 *   "site thin top bar, will show only for administrators ... administrator
 *    name, along with logout button ... must be stand alone ... and have a
 *    small hide / unhide arrow type icon at the right corner."
 *
 * RULES THIS FILE KEEPS
 *  - Every word that came from a setting, a record or the server is written
 *    with textContent or setAttribute -- never innerHTML. The ONE exception is
 *    the header markup itself, which is the server's own rendering of the shop's
 *    own Blade component (the same bytes the page prints), parsed with
 *    DOMParser and adopted.
 *  - Every href is checked to be a same-origin path before it is used.
 *  - Every request has a timeout, every button that writes is disabled while
 *    it writes, and every listener this file adds to the document or window is
 *    removed again when the thing that needed it closes.
 *  - Nothing measures an element. The preview's scale comes from
 *    window.innerWidth, which is a number the browser already has.
 */
import css from '../../../css/kbb/kbb-admin-layer.css?inline';

const HINT = 'kbb_ah';
const STORE_KEY = 'kbb_adm_bar';
const TIMEOUT_CONTEXT = 8000;
const TIMEOUT_WRITE = 20000;
const TIMEOUT_UPLOAD = 90000;

const ICON = {
    pencil: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    up: '<path d="M18 15l-6-6-6 6"/>',
    down: '<path d="M6 9l6 6 6-6"/>',
    x: '<path d="M18 6L6 18M6 6l12 12"/>',
    out: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
    image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
    check: '<path d="M20 6L9 17l-5-5"/>',
    bolt: '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>',
};

let state = null;

/* ------------------------------------------------------------------ helpers */

const base = () => {
    const b = (window.KBB && typeof window.KBB.base === 'string') ? window.KBB.base : '';
    return b.replace(/\/+$/, '');
};

/** A same-origin path, or null. Never an absolute URL, never javascript:. */
export function safePath(value) {
    const v = String(value == null ? '' : value);
    if (!v.startsWith('/') || v.startsWith('//') || v.includes('\\')) return null;
    return v;
}

function svg(paths) {
    const ns = 'http://www.w3.org/2000/svg';
    const el = document.createElementNS(ns, 'svg');
    el.setAttribute('viewBox', '0 0 24 24');
    el.setAttribute('fill', 'none');
    el.setAttribute('stroke', 'currentColor');
    el.setAttribute('stroke-width', '2');
    el.setAttribute('stroke-linecap', 'round');
    el.setAttribute('stroke-linejoin', 'round');
    el.setAttribute('aria-hidden', 'true');
    // Constant markup from this file, never from data.
    const doc = new DOMParser().parseFromString(`<svg xmlns="${ns}">${paths}</svg>`, 'image/svg+xml');
    [...doc.documentElement.childNodes].forEach((n) => el.appendChild(document.importNode(n, true)));
    return el;
}

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

function clearHint() {
    const paths = new Set(['/', base() || '/']);
    for (const p of paths) {
        document.cookie = `${HINT}=; Max-Age=0; path=${p}; SameSite=Lax`;
    }
}

function store(key, value) {
    try {
        if (value === undefined) return window.localStorage.getItem(key);
        window.localStorage.setItem(key, value);
    } catch (e) { /* private window, blocked storage: the bar just opens */ }
    return null;
}

/** fetch with a deadline. Rejects with {timeout:true} when it runs out. */
async function request(url, options = {}, ms = TIMEOUT_WRITE) {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), ms);
    try {
        const res = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers || {}),
            },
            signal: ctl.signal,
        });
        let body = null;
        try { body = await res.json(); } catch (e) { body = null; }
        return { status: res.status, ok: res.ok, body };
    } catch (e) {
        if (ctl.signal.aborted) throw Object.assign(new Error('timeout'), { timeout: true });
        throw e;
    } finally {
        clearTimeout(timer);
    }
}

/** The server's first sentence about what was wrong, or ours. */
function messageOf(res, fallback) {
    const b = res && res.body;
    if (b && b.errors && typeof b.errors === 'object') {
        const first = Object.values(b.errors)[0];
        if (Array.isArray(first) && first[0]) return String(first[0]);
    }
    if (b && typeof b.message === 'string' && b.message) return b.message;
    if (res && res.status === 419) return 'Your session has expired. Reload the page and try again.';
    if (res && res.status === 429) return 'Too many saves in a minute. Wait a moment and press Save again.';
    if (res && (res.status === 401 || res.status === 403)) return 'You are no longer signed in to the admin.';
    return fallback;
}

function toast(text) {
    document.querySelectorAll('.kbb-qe-toast').forEach((t) => t.remove());
    const t = h('div', { class: 'kbb-qe-toast', role: 'status', 'aria-live': 'polite' }, svg(ICON.check), h('span', { text }));
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2700);
}

/* -------------------------------------------------------------------- start */

export async function start() {
    if (state) return state;
    state = { ctx: null };

    let res;
    try {
        const path = encodeURIComponent(window.location.pathname);
        res = await request(`${base()}/admin-api/storefront/context?path=${path}`, {}, TIMEOUT_CONTEXT);
    } catch (e) {
        return state; // network trouble: draw nothing, change nothing
    }

    if (res.status === 401 || res.status === 403) {
        // A forged hint, an expired sign-in, or a role with neither tool:
        // stop asking on every page.
        clearHint();
        return state;
    }

    if (!res.ok || !res.body || res.body.ok !== true) return state;

    state.ctx = res.body;

    const style = h('style', { id: 'kbb-adm-css' });
    style.textContent = css;
    document.head.appendChild(style);

    if (state.ctx.bar) drawBar(state.ctx);
    if (state.ctx.edit) drawPencil(state.ctx.edit);

    return state;
}

/* ---------------------------------------------------------------------- bar */

function drawBar(ctx) {
    const bar = ctx.bar;
    const root = document.documentElement;

    const link = (item, extra) => {
        const href = safePath(item.href);
        if (!href) return null;
        const a = h('a', { href, class: extra || null }, h('span', { text: String(item.label || '') }));
        if (typeof item.badge === 'number' && item.badge > 0) {
            a.appendChild(h('span', {
                class: 'kbb-adm__badge',
                text: item.badge > 99 ? '99+' : String(item.badge),
                'aria-label': `${item.badge} new today`,
            }));
        }
        return a;
    };

    const links = h('nav', { class: 'kbb-adm__links', 'aria-label': 'Admin shortcuts' });
    if (bar.context) links.appendChild(link(bar.context, 'kbb-adm__ctx') || document.createTextNode(''));
    (bar.links || []).forEach((item) => {
        const a = link(item);
        if (a) links.appendChild(a);
    });

    if (bar.clear_cache && safePath(bar.clear_cache)) {
        links.appendChild(h('button', {
            type: 'button',
            onclick: (e) => clearCache(e.currentTarget, bar.clear_cache),
        }, svg(ICON.bolt), h('span', { text: 'Clear cache' })));
    }

    const name = String((ctx.admin && ctx.admin.name) || '');
    const hideBtn = h('button', {
        type: 'button', class: 'kbb-adm__hide', 'aria-label': 'Hide the admin bar', title: 'Hide the admin bar',
        onclick: () => setCollapsed(true),
    }, svg(ICON.up));

    const consoleHref = safePath(bar.console) || '/';

    const el = h('div', { class: 'kbb-adm', id: 'kbb-adm', role: 'region', 'aria-label': 'Admin bar' },
        h('a', { class: 'kbb-adm__brand', href: consoleHref },
            h('span', { text: String(bar.title || 'Admin').replace(/\s*·\s*Admin$/, '') + ' · ' }),
            h('b', { text: 'Admin' })),
        h('span', { class: 'kbb-adm__sep', 'aria-hidden': 'true' }),
        links,
        h('div', { class: 'kbb-adm__me' },
            h('span', { class: 'kbb-adm__name', text: name, title: name }),
            h('span', { class: 'kbb-adm__ini', text: String((ctx.admin && ctx.admin.initials) || 'A'), title: name, 'aria-hidden': 'true' }),
            h('button', {
                type: 'button', class: 'kbb-adm__logout',
                onclick: (e) => logout(e.currentTarget, bar.logout),
            }, svg(ICON.out), h('span', { text: 'Log out' })),
            hideBtn));

    const tab = h('button', {
        type: 'button', class: 'kbb-adm-tab', id: 'kbb-adm-tab', hidden: true,
        'aria-label': 'Show the admin bar', title: 'Show the admin bar',
        onclick: () => setCollapsed(false),
    }, svg(ICON.down));

    document.body.appendChild(el);
    document.body.appendChild(tab);

    function setCollapsed(on) {
        el.hidden = on;
        tab.hidden = !on;
        root.classList.toggle('kbb-adm-on', !on);
        store(STORE_KEY, on ? 'collapsed' : 'open');
        (on ? tab : hideBtn).focus({ preventScroll: true });
    }

    const collapsed = store(STORE_KEY) === 'collapsed';
    el.hidden = collapsed;
    tab.hidden = !collapsed;
    root.classList.toggle('kbb-adm-on', !collapsed);
}

async function clearCache(btn, url) {
    if (!window.confirm('Clear the shop\'s application cache now? Pages rebuild themselves on their next visit.')) return;
    btn.disabled = true;
    try {
        const res = await request(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': state.ctx.csrf },
            body: JSON.stringify({ target: 'application' }),
        });
        toast(res.ok ? 'Cache cleared' : messageOf(res, 'The cache could not be cleared.'));
    } catch (e) {
        toast(e.timeout ? 'That took too long. Try again.' : 'The cache could not be cleared.');
    } finally {
        btn.disabled = false;
    }
}

async function logout(btn, url) {
    const href = safePath(url);
    if (!href) return;
    btn.disabled = true;
    const form = new FormData();
    form.append('_token', state.ctx.csrf);
    try {
        // The console's own sign-out, with its CSRF token. `manual` because
        // its answer is a redirect to the sign-in page, which is not where the
        // owner wants to be: he stays on the page he was reading.
        await fetch(href, { method: 'POST', body: form, credentials: 'same-origin', redirect: 'manual' });
    } catch (e) { /* the reload below shows the truth either way */ }
    clearHint();
    window.location.reload();
}

/* ------------------------------------------------------------------- pencil */

/** The element the page draws its header with, if any. */
function currentHeader() {
    return document.querySelector('[data-kbb-title-header]') || document.querySelector('main .kbb-banner, #content .kbb-banner');
}

function drawPencil(edit) {
    const host = currentHeader();
    const pill = h('button', {
        type: 'button',
        class: 'kbb-qe-pill',
        'aria-haspopup': 'dialog',
        'aria-label': `Edit the header of ${edit.name}`,
        onclick: () => openEditor(edit, pill),
    }, svg(ICON.pencil), h('span', { text: 'Edit' }));

    if (host) {
        host.classList.add('kbb-qe-host');
        host.appendChild(pill);
    } else {
        // No header on this page (switched off, or nothing to draw yet): sit
        // beside the plain heading instead.
        const heading = document.querySelector('#content h1, main h1');
        if (!heading) return;
        pill.classList.add('kbb-qe-pill--plain');
        heading.insertAdjacentElement('afterend', pill);
    }
    state.pill = pill;
}

/* ------------------------------------------------------------------- editor */

const FOCUS_CHOICES = [['', 'Shop setting'], ['left', 'Left'], ['center', 'Centre'], ['right', 'Right']];

function openEditor(edit, opener) {
    if (state.modal) return;

    const original = { ...edit.fields };
    const values = { ...edit.fields };
    let busy = false;
    let previewTimer = 0;
    let previewSeq = 0;
    let xhr = null;
    const cleanups = [];

    const listen = (target, type, fn, opts) => {
        target.addEventListener(type, fn, opts);
        cleanups.push(() => target.removeEventListener(type, fn, opts));
    };

    const isBanner = edit.mode === 'banner';
    const noun = edit.type === 'brand' ? 'brand' : 'category';

    /* -- the preview */
    const pvStage = h('div', { class: 'kbb-qe__pv-stage' });
    const pv = h('div', { class: 'kbb-qe__pv', 'aria-label': 'Preview', role: 'img' },
        pvStage, h('span', { class: 'kbb-qe__pv-tag', text: 'Preview' }), h('span', { class: 'kbb-qe__pv-busy', 'aria-hidden': 'true' }));

    /* -- the picture */
    const fileIn = h('input', {
        type: 'file', accept: (edit.upload.types || []).join(','), hidden: true, tabindex: '-1',
    });
    const thumb = h('div', { class: 'kbb-qe__thumb' }, svg(ICON.image));
    const dropTitle = h('b', { text: 'Drop the banner here or click to choose' });
    const dropNote = h('span', {
        text: `Best at ${edit.upload.recommended}. JPG, PNG, WebP or GIF, up to ${Math.round(edit.upload.max_bytes / 1048576)} MB.`,
    });
    const barFill = h('i');
    const drop = h('div', {
        class: 'kbb-qe__drop', role: 'button', tabindex: '0',
        'aria-label': 'Banner picture: drop a file here or press Enter to choose one',
    }, thumb, h('div', { class: 'kbb-qe__drop-txt' }, dropTitle, dropNote, h('div', { class: 'kbb-qe__bar' }, barFill)), fileIn);
    const removeBtn = h('button', { type: 'button', class: 'kbb-qe__link', text: 'Remove picture' });
    const picRow = h('div', { class: 'kbb-qe__pic-row' }, removeBtn);

    /* -- the words */
    const fld = (id, label, input, hint) => h('div', { class: 'kbb-qe__fld' },
        h('label', { class: 'kbb-qe__label', for: id, text: label }), input,
        hint ? h('p', { class: 'kbb-qe__hint', text: hint }) : null);

    const titleIn = h('input', {
        class: 'kbb-qe__in', id: 'kbb-qe-title', maxlength: '300', autocomplete: 'off',
        placeholder: String(edit.placeholders.title || ''),
    });
    const subIn = h('input', { class: 'kbb-qe__in', id: 'kbb-qe-sub', maxlength: '300', autocomplete: 'off', placeholder: 'Optional' });
    const descIn = h('textarea', {
        class: 'kbb-qe__in', id: 'kbb-qe-desc', maxlength: '5000', rows: '3',
        placeholder: edit.placeholders.description ? String(edit.placeholders.description) : `Blank uses the ${noun}'s own description`,
    });
    titleIn.value = values.header_title || '';
    subIn.value = values.header_subtitle || '';
    descIn.value = values.header_description || '';

    const hasFocus = (edit.keys || []).includes('focus') && !isBanner;
    const seg = h('div', { class: 'kbb-qe__seg', role: 'radiogroup', 'aria-label': 'Where a phone crops the picture' });
    FOCUS_CHOICES.forEach(([v, label]) => {
        const input = h('input', { type: 'radio', name: 'kbb-qe-focus', value: v });
        input.checked = (values.focus || '') === v;
        input.addEventListener('change', () => { values.focus = v; schedulePreview(); });
        seg.appendChild(h('label', null, input, h('span', { text: label })));
    });

    const err = h('p', { class: 'kbb-qe__err', role: 'alert' });

    const more = h('div', { class: 'kbb-qe__more' });
    (edit.more || []).forEach((m, i) => {
        const href = safePath(m.href);
        if (href) more.appendChild(h('a', { href, text: (i === 0 ? 'More design options → ' : '') + String(m.label || '') }));
    });

    const cancelBtn = h('button', { type: 'button', class: 'kbb-qe__btn', text: 'Cancel' });
    const saveBtn = h('button', { type: 'button', class: 'kbb-qe__btn kbb-qe__btn--go', text: 'Save' });
    const closeX = h('button', { type: 'button', class: 'kbb-qe__x', 'aria-label': 'Close' }, svg(ICON.x));

    const heading = h('h2', { class: 'kbb-qe__title', id: 'kbb-qe-h' },
        `Edit ${noun} header`, h('small', { text: String(edit.name || '') }));

    const body = h('div', { class: 'kbb-qe__body' },
        err,
        pv,
        h('div', { class: 'kbb-qe__fld' },
            h('span', { class: 'kbb-qe__label', text: 'Banner picture' }), drop, picRow),
        fld('kbb-qe-title', 'Title', titleIn, `Blank shows the ${noun} name.`),
        fld('kbb-qe-sub', 'Line under the title', subIn),
        isBanner ? null : fld('kbb-qe-desc', 'Description', descIn,
            `Blank shows the ${noun}'s own description.`),
        hasFocus ? h('div', { class: 'kbb-qe__fld' },
            h('span', { class: 'kbb-qe__label', text: 'On a phone, keep this part of the picture' }), seg) : null,
        isBanner ? h('p', { class: 'kbb-qe__hint', text: `This ${noun} shows its own Banner, so these change the banner's picture, heading and line.` }) : null);

    const box = h('div', { class: 'kbb-qe__box', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'kbb-qe-h' },
        h('div', { class: 'kbb-qe__head' }, heading, closeX),
        body,
        h('div', { class: 'kbb-qe__foot' }, more, cancelBtn, saveBtn));
    const bg = h('div', { class: 'kbb-qe__bg' });
    const modal = h('div', { class: 'kbb-qe', id: 'kbb-qe' }, bg, box);

    state.modal = modal;
    document.documentElement.classList.add('kbb-qe-lock');
    document.body.appendChild(modal);

    /* ---------------------------------------------------------- behaviour */

    function setThumb() {
        const url = safePath(values.header_image) || (/^https?:\/\//i.test(values.header_image || '') ? values.header_image : '');
        thumb.style.backgroundImage = url ? `url("${encodeURI(url).replace(/"/g, '%22')}")` : '';
        thumb.firstChild && (thumb.firstChild.style.display = url ? 'none' : '');
        removeBtn.hidden = !values.header_image;
        dropTitle.textContent = values.header_image ? 'Drop a new banner here or click to replace it' : 'Drop the banner here or click to choose';
    }

    function showError(text) {
        err.textContent = text || '';
        err.classList.toggle('is-on', !!text);
        if (text) err.scrollIntoView({ block: 'nearest' });
    }

    function payload() {
        const p = {
            header_image: values.header_image || '',
            header_title: titleIn.value,
            header_subtitle: subIn.value,
            path: window.location.pathname,
        };
        if (!isBanner) p.header_description = descIn.value;
        if (hasFocus) p.focus = values.focus || '';
        return p;
    }

    function dirty() {
        return (values.header_image || '') !== (original.header_image || '')
            || titleIn.value !== (original.header_title || '')
            || subIn.value !== (original.header_subtitle || '')
            || (!isBanner && descIn.value !== (original.header_description || ''))
            || (hasFocus && (values.focus || '') !== (original.focus || ''));
    }

    function stageScale() {
        // The stage is the WINDOW's width, so the header lays itself out
        // exactly as it does on the page -- its own gutters, its own maximum
        // width -- and the stage is then scaled down to the pop-up. Read from
        // the window's own width: no element is measured.
        const vw = window.innerWidth || 1280;
        const stage = vw;
        const boxW = Math.min(vw < 641 ? vw : 560, vw) - 38;
        pvStage.style.setProperty('--qe-stage', `${Math.max(200, stage)}px`);
        pvStage.style.setProperty('--qe-zoom', String(Math.min(1, boxW / Math.max(200, stage))));
    }

    function adopt(html, into) {
        into.textContent = '';
        if (!html) return null;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const node = doc.body.firstElementChild;
        if (!node) return null;
        const el = document.importNode(node, true);
        into.appendChild(el);
        return el;
    }

    async function refreshPreview() {
        const mine = ++previewSeq;
        pv.classList.add('is-busy');
        try {
            const res = await request(edit.endpoints.preview, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': state.ctx.csrf },
                body: JSON.stringify(payload()),
            }, TIMEOUT_WRITE);
            if (mine !== previewSeq || !state.modal) return;
            if (!res.ok) {
                pvStage.textContent = '';
                pvStage.appendChild(h('div', { class: 'kbb-qe__pv-note', text: messageOf(res, 'The preview could not be drawn.') }));
                return;
            }
            ensureCss(res.body.kind);
            const el = adopt(res.body.html, pvStage);
            if (el) {
                // The page already has these ids; the preview must not.
                el.querySelectorAll('[id]').forEach((n) => n.setAttribute('id', `kbb-qe-pv-${n.id}`));
                el.querySelectorAll('[for]').forEach((n) => n.setAttribute('for', `kbb-qe-pv-${n.getAttribute('for')}`));
                el.removeAttribute('aria-labelledby');
                el.removeAttribute('data-kbb-title-header');
                el.setAttribute('aria-hidden', 'true');
            } else {
                pvStage.appendChild(h('div', { class: 'kbb-qe__pv-note', text: String(res.body.note || 'Nothing is drawn here.') }));
            }
        } catch (e) {
            if (mine === previewSeq && state.modal) {
                pvStage.textContent = '';
                pvStage.appendChild(h('div', { class: 'kbb-qe__pv-note', text: 'The preview is taking too long; your changes are still here.' }));
            }
        } finally {
            if (mine === previewSeq) pv.classList.remove('is-busy');
        }
    }

    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 350);
    }

    /* -- upload: the media library's own endpoint, with progress */
    function upload(file) {
        showError('');
        const types = edit.upload.types || [];
        if (!file || (types.length && !types.includes(file.type))) {
            showError('That file is not a picture this shop takes. Use a JPG, PNG, WebP or GIF.');
            return;
        }
        if (file.size > edit.upload.max_bytes) {
            showError(`That picture is ${(file.size / 1048576).toFixed(1)} MB. The limit is ${Math.round(edit.upload.max_bytes / 1048576)} MB -- save it smaller and try again.`);
            return;
        }
        if (xhr) xhr.abort();

        const form = new FormData();
        form.append('file', file);
        form.append('folder', edit.upload.folder);

        drop.classList.add('is-up');
        barFill.style.width = '2%';
        saveBtn.disabled = true;
        dropTitle.textContent = 'Uploading…';

        const req = new XMLHttpRequest();
        xhr = req;
        req.open('POST', edit.endpoints.upload);
        req.timeout = TIMEOUT_UPLOAD;
        req.withCredentials = true;
        req.setRequestHeader('Accept', 'application/json');
        req.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        req.setRequestHeader('X-CSRF-TOKEN', state.ctx.csrf);
        req.upload.onprogress = (e) => {
            if (e.lengthComputable) barFill.style.width = `${Math.max(2, Math.round((e.loaded / e.total) * 100))}%`;
        };
        const done = (text) => {
            if (xhr !== req) return;
            xhr = null;
            drop.classList.remove('is-up');
            barFill.style.width = '0';
            saveBtn.disabled = busy;
            setThumb();
            if (text) showError(text);
        };
        req.onload = () => {
            let body = null;
            try { body = JSON.parse(req.responseText); } catch (e) { body = null; }
            const url = body && body.ok && typeof body.url === 'string' ? body.url : '';
            if (req.status >= 200 && req.status < 300 && url) {
                values.header_image = url;
                done('');
                schedulePreview();
            } else {
                done(messageOf({ status: req.status, body }, 'The picture could not be uploaded.'));
            }
        };
        req.onerror = () => done('The upload stopped -- check the connection and drop the picture again.');
        req.ontimeout = () => done('The upload took too long. Try a smaller picture, or drop it again.');
        req.onabort = () => done('');
        req.send(form);
    }

    /* -- save */
    async function save() {
        if (busy) return;
        if (xhr) { showError('Wait for the picture to finish uploading.'); return; }
        busy = true;
        showError('');
        saveBtn.disabled = true;
        cancelBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        try {
            const res = await request(edit.endpoints.save, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': state.ctx.csrf },
                body: JSON.stringify(payload()),
            }, TIMEOUT_WRITE);
            if (!res.ok || !res.body || res.body.ok !== true) {
                showError(messageOf(res, 'That did not save. Nothing on the page has changed.'));
                return;
            }
            edit.fields = { ...res.body.fields };
            const swapped = swapHeader(res.body);
            close(true);
            toast(res.body.message || 'Saved');
            if (swapped === 'reload') window.location.reload();
        } catch (e) {
            showError(e.timeout
                ? 'Saving is taking too long. Nothing on the page has changed -- check the connection and press Save again.'
                : 'That did not save. Nothing on the page has changed.');
        } finally {
            busy = false;
            if (state.modal === modal) {
                saveBtn.disabled = false;
                cancelBtn.disabled = false;
                saveBtn.textContent = 'Save';
            }
        }
    }

    /* -- close: every listener this opened goes with it */
    function close(force) {
        if (!force && busy) return;
        if (!force && dirty() && !window.confirm('Close without saving your changes?')) return;
        clearTimeout(previewTimer);
        previewSeq++;
        if (xhr) { const r = xhr; xhr = null; r.abort(); }
        cleanups.splice(0).forEach((fn) => fn());
        modal.remove();
        document.documentElement.classList.remove('kbb-qe-lock');
        state.modal = null;
        if (opener && opener.isConnected) opener.focus({ preventScroll: true });
        else if (state.pill && state.pill.isConnected) state.pill.focus({ preventScroll: true });
    }

    /* ------------------------------------------------------------- wiring */

    closeX.addEventListener('click', () => close(false));
    cancelBtn.addEventListener('click', () => close(false));
    bg.addEventListener('click', () => close(false));
    saveBtn.addEventListener('click', save);
    removeBtn.addEventListener('click', () => { values.header_image = ''; setThumb(); schedulePreview(); });
    [titleIn, subIn, descIn].forEach((el) => el.addEventListener('input', schedulePreview));

    drop.addEventListener('click', () => fileIn.click());
    drop.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); }
    });
    fileIn.addEventListener('change', () => { if (fileIn.files && fileIn.files[0]) upload(fileIn.files[0]); fileIn.value = ''; });

    let depth = 0;
    drop.addEventListener('dragenter', (e) => { e.preventDefault(); depth++; drop.classList.add('is-over'); });
    drop.addEventListener('dragover', (e) => { e.preventDefault(); e.dataTransfer && (e.dataTransfer.dropEffect = 'copy'); });
    drop.addEventListener('dragleave', () => { depth = Math.max(0, depth - 1); if (!depth) drop.classList.remove('is-over'); });
    drop.addEventListener('drop', (e) => {
        e.preventDefault();
        depth = 0;
        drop.classList.remove('is-over');
        const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) upload(f);
    });

    // A file dropped anywhere else while the pop-up is open must not make the
    // browser navigate away to it and lose the owner's edits.
    const swallow = (e) => { if (!drop.contains(e.target)) e.preventDefault(); };
    listen(window, 'dragover', swallow);
    listen(window, 'drop', swallow);

    listen(document, 'keydown', (e) => {
        if (e.key === 'Escape') { e.preventDefault(); close(false); return; }
        if (e.key !== 'Tab') return;
        const f = [...box.querySelectorAll('a[href],button:not([disabled]),input:not([type=hidden]):not([hidden]),textarea,[tabindex="0"]')]
            .filter((n) => !n.closest('[hidden]') && n.type !== 'file');
        if (!f.length) return;
        const first = f[0];
        const last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        else if (!box.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    });
    listen(window, 'resize', stageScale);

    setThumb();
    stageScale();
    refreshPreview();
    setTimeout(() => (window.innerWidth > 640 ? titleIn : closeX).focus({ preventScroll: true }), 30);
}

/** The stylesheet a header needs, for a page that did not load it. */
function ensureCss(kind) {
    const href = safePath(state.ctx.edit && state.ctx.edit.css && state.ctx.edit.css[kind === 'banner' ? 'banner' : 'header']);
    if (!href || kind === 'none') return;
    if ([...document.querySelectorAll('link[rel=stylesheet]')].some((l) => l.getAttribute('href') === href)) return;
    document.head.appendChild(h('link', { rel: 'stylesheet', href }));
}

/**
 * Put the saved header on the page, in place.
 *
 * The header the page has is replaced by the one the server just drew from
 * the same component. When the page has none to replace, or the saved header
 * is of the other kind (a banner switched on or off underneath), the page is
 * reloaded once instead -- the only honest way to put a header where the
 * template decides it goes.
 */
function swapHeader(result) {
    const old = currentHeader();
    const oldKind = old ? (old.hasAttribute('data-kbb-title-header') ? 'header' : 'banner') : 'none';

    if (result.kind === 'none' && oldKind === 'none') return 'kept';
    if (!old || result.kind !== oldKind) return 'reload';

    const doc = new DOMParser().parseFromString(String(result.html || ''), 'text/html');
    const fresh = doc.body.firstElementChild;
    if (!fresh) return 'reload';

    const el = document.importNode(fresh, true);
    el.classList.add('kbb-qe-host', 'kbb-qe-swapped');
    if (state.pill) el.appendChild(state.pill);
    old.replaceWith(el);
    setTimeout(() => el.classList.remove('kbb-qe-swapped'), 1300);
    return 'swapped';
}
