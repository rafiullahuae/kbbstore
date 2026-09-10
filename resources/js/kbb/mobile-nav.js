/**
 * Mobile drawer and second-level overlay. T-CHROME-13 / T-CHROME-14.
 *
 * Built client-side from window.KBB.nav, which the layout serialises from the
 * database — the theme hard-coded this tree in PHP.
 *
 * All hrefs come pre-prefixed from the server via window.KBB.base, so nothing
 * here constructs a path. That is what keeps the drawer working when the app is
 * served from a subdirectory.
 */

import { open, closeAll } from './overlay.js';

const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

const link = (url) => {
    const base = window.KBB?.base || '';
    const path = String(url ?? '/');
    if (/^([a-z][a-z0-9+.-]*:|\/\/)/i.test(path)) return path;
    return base + (path.startsWith('/') ? path : `/${path}`);
};

const renderSub = (item) => {
    const groups = (item.children || []).map((child) => {
        if (child.children && child.children.length) {
            const links = child.children
                .map((l) => `<a href="${escape(link(l.url))}">${escape(l.label)}</a>`)
                .join('');
            return `<div class="msub-group"><div class="msub-gh">${escape(child.label)}</div>${links}</div>`;
        }
        return `<a href="${escape(link(child.url))}">${escape(child.label)}</a>`;
    }).join('');

    return `
        <div class="msub-h">
            <button class="msub-back" type="button" data-kbb-msub-back>‹</button>
            <b>${escape(item.label)}</b>
        </div>
        <a class="msub-all" href="${escape(link(item.url))}">Shop all ${escape(item.label)} →</a>
        <div class="msub-body">${groups}</div>
    `;
};

export function initMobileNav() {
    const list = document.getElementById('mlist');
    const sub = document.getElementById('msub');
    const nav = window.KBB?.nav || [];
    if (!list) return;

    list.innerHTML = nav.map((item, index) => {
        const hasChildren = item.children && item.children.length;
        const icon = item.icon ? `<span class="mi">${escape(item.icon)}</span>` : '';
        const badge = item.badge ? `<span class="npill">${escape(item.badge)}</span>` : '';

        return hasChildren
            ? `<button class="mrow" type="button" data-kbb-msub="${index}">${icon}<span>${escape(item.label)}</span>${badge}<span class="chev">›</span></button>`
            : `<a class="mrow" href="${escape(link(item.url))}">${icon}<span>${escape(item.label)}</span>${badge}</a>`;
    }).join('');

    list.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-kbb-msub]');
        if (!trigger || !sub) return;

        sub.innerHTML = renderSub(nav[Number(trigger.dataset.kbbMsub)]);
        sub.classList.add('on');
    });

    sub?.addEventListener('click', (event) => {
        if (event.target.closest('[data-kbb-msub-back]')) sub.classList.remove('on');
    });

    const search = document.querySelector('[data-kbb-msearch]');
    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && search.value.trim()) {
            closeAll();
            window.location.href = `${window.KBB.routes.shop}?s=${encodeURIComponent(search.value.trim())}`;
        }
    });

    window.kbbOpenDrawer = open;
}
