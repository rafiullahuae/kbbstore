/**
 * Customer reviews (the sr-* markup, ported verbatim from the plugin).
 * Ten hooks rendered with nothing behind them — this wires all of them:
 * filter chips, load more, the detail popup, the submit sheet (star picker,
 * photo previews, captcha, honeypot already in the markup), and the
 * helpful-vote button.
 */

import { t } from './i18n.js';

const INITIAL_VISIBLE = 8;

function initReviewFilters(section) {
    const grid = section.querySelector('.sr-grid');
    const chips = section.querySelectorAll('[data-f]');
    const moreBtn = section.querySelector('[data-sr-more]');
    if (!grid) return;

    const cards = () => [...grid.querySelectorAll('.sr-card')];
    let activeFilter = 'all';
    let expanded = false;

    const matches = (card) => {
        if (activeFilter === 'all') return true;
        if (activeFilter === 'photos') return card.dataset.photos === '1';
        return card.dataset.rating === activeFilter;
    };

    const apply = () => {
        const visible = cards().filter(matches);
        let shown = 0;

        cards().forEach((card) => {
            const ok = matches(card);
            const withinLimit = expanded || shown < INITIAL_VISIBLE;
            card.style.display = ok && withinLimit ? '' : 'none';
            if (ok && withinLimit) shown++;
        });

        if (moreBtn) moreBtn.style.display = !expanded && visible.length > INITIAL_VISIBLE ? '' : 'none';
    };

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chips.forEach((c) => c.classList.remove('on'));
            chip.classList.add('on');
            activeFilter = chip.dataset.f;
            expanded = false;
            apply();
        });
    });

    moreBtn?.addEventListener('click', () => {
        expanded = true;
        apply();
    });

    apply();
}

function initReviewModal(section) {
    const modal = section.querySelector('[data-sr-modal]');
    const body = section.querySelector('[data-sr-mbody]');
    if (!modal || !body) return;

    const shut = () => { modal.classList.remove('on'); modal.hidden = true; };

    section.querySelectorAll('.sr-card').forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('.sr-help')) return; // the vote button handles its own click

            const data = card.querySelector('.sr-data');
            if (!data) return;

            let r;
            try { r = JSON.parse(data.textContent); } catch { return; }

            const stars = Array.from({ length: 5 }, (_, i) =>
                `<span class="${i < r.rate ? 'f' : ''}">★</span>`).join('');
            const photos = (r.imgs || []).map((u) =>
                `<img src="${escapeHtml(u)}" alt="" loading="lazy" style="width:100%;border-radius:8px">`).join('');

            body.innerHTML = `
                <div class="sr-ct"><span class="sr-av">${escapeHtml(r.ini)}</span>
                    <div class="sr-cmeta"><span class="sr-nm">${escapeHtml(r.name)}${r.vf ? ' <span class="sr-verified">✓ Verified</span>' : ''}</span>
                    <span class="sr-cs">${stars}</span></div></div>
                ${r.title ? `<h4 class="sr-h">${escapeHtml(r.title)}</h4>` : ''}
                <p class="sr-tx">${escapeHtml(r.text)}</p>
                ${photos ? `<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:10px">${photos}</div>` : ''}
                <span class="sr-dt">${escapeHtml(r.date)}</span>`;

            modal.hidden = false;
            requestAnimationFrame(() => modal.classList.add('on'));
        });
    });

    modal.querySelectorAll('[data-sr-mclose]').forEach((el) => el.addEventListener('click', shut));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) shut(); });
}

function initHelpfulVotes(section) {
    section.addEventListener('click', async (event) => {
        const btn = event.target.closest('.sr-help');
        if (!btn) return;

        event.stopPropagation();
        if (btn.disabled) return;
        btn.disabled = true;

        try {
            const response = await fetch(`${window.KBB.routes.reviewsHelpful}/${btn.dataset.id}/helpful`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
            });
            const data = await response.json();

            if (data.ok) {
                const count = btn.querySelector('span');
                if (count) count.textContent = data.helpful;
                if (data.already) window.kbbToast?.(t('store.js.already_helpful', 'You already marked this helpful.'));
            }
        } catch {
            // Quiet failure — a vote count is not worth interrupting the page for.
        } finally {
            btn.disabled = false;
        }
    });
}

function initReviewSheet(section) {
    const sheet = section.querySelector('[data-sr-sheet]');
    const openBtns = document.querySelectorAll('[data-sr-open]');
    if (!sheet) return;

    const form = sheet.querySelector('[data-sr-form]');
    const msg = sheet.querySelector('[data-sr-msg]');
    const starsPicker = sheet.querySelector('[data-sr-stars]');
    const ratingInput = sheet.querySelector('[data-sr-rating]');
    const fileInput = sheet.querySelector('[data-sr-file]');
    const previews = sheet.querySelector('[data-sr-previews]');
    const questionEl = sheet.querySelector('[data-sr-question]');
    const tokenInput = sheet.querySelector('[data-sr-token]');

    let selectedFiles = [];

    const loadCaptcha = async () => {
        try {
            const response = await fetch(window.KBB.routes.reviewsCaptcha, { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (questionEl) questionEl.textContent = data.question;
            if (tokenInput) tokenInput.value = data.token;
        } catch {
            if (questionEl) questionEl.textContent = '—';
        }
    };

    const open = () => {
        sheet.hidden = false;
        requestAnimationFrame(() => sheet.classList.add('on'));
        loadCaptcha();
    };

    const shut = () => { sheet.classList.remove('on'); sheet.hidden = true; };

    openBtns.forEach((btn) => btn.addEventListener('click', open));
    sheet.querySelectorAll('[data-sr-close]').forEach((el) => el.addEventListener('click', shut));

    starsPicker?.addEventListener('click', (event) => {
        const star = event.target.closest('[data-v]');
        if (!star) return;

        const value = Number(star.dataset.v);
        if (ratingInput) ratingInput.value = value;

        [...starsPicker.children].forEach((s, i) => s.classList.toggle('f', i < value));
    });

    fileInput?.addEventListener('change', () => {
        selectedFiles = [...fileInput.files].slice(0, 6);
        if (!previews) return;

        previews.innerHTML = selectedFiles.map((file) => {
            const url = URL.createObjectURL(file);
            return `<span class="sr-pv-item" style="background:#fff url('${url}') center/cover;width:56px;height:56px;border-radius:8px;display:inline-block;margin:4px"></span>`;
        }).join('');
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (msg) msg.textContent = '';

        const submitBtn = form.querySelector('.sr-submit');
        if (submitBtn) submitBtn.disabled = true;

        const body = new FormData(form);
        body.set('product_id', section.dataset.product);

        // FormData(form) already picked up the file input's own files, but
        // re-set it explicitly in case a browser dropped the input's files
        // on a prior failed submit (Safari has done this).
        body.delete('sr_photos[]');
        selectedFiles.forEach((file) => body.append('sr_photos[]', file));

        try {
            const response = await fetch(window.KBB.routes.reviewsSubmit, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
                body,
            });
            const data = await response.json();

            if (!data.ok) {
                if (msg) msg.textContent = data.error || t('store.js.generic_error', 'Something went wrong — please try again.');
                loadCaptcha();
                return;
            }

            if (msg) msg.textContent = data.message;
            form.reset();
            if (previews) previews.innerHTML = '';
            selectedFiles = [];
            [...(starsPicker?.children || [])].forEach((s) => s.classList.remove('f'));

            setTimeout(shut, 1800);
        } catch {
            if (msg) msg.textContent = t('store.js.review_failed', 'Could not submit — please check your connection and try again.');
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export function initReviews() {
    const section = document.getElementById('sr');
    if (!section) return;

    initReviewFilters(section);
    initReviewModal(section);
    initHelpfulVotes(section);
    initReviewSheet(section);
}
