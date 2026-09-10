/**
 * Homepage behaviour: the hero slider.
 *
 * Everything else on the page is server-rendered — the grids, the quiz form and
 * the section visibility — so this is deliberately small.
 */
export function initHome() {
    initMobileChrome();
    initHeaderScroll();

    const slider = document.getElementById('slider');
    if (!slider) return;

    const track = document.getElementById('slides');
    const slides = track ? track.children.length : 0;
    if (slides < 2) return;

    const dots = slider.querySelector('.sdots');
    let index = 0;
    let timer = null;

    dots.innerHTML = Array.from({ length: slides }, (_, i) =>
        `<i data-go="${i}"${i ? '' : ' class="on"'}></i>`).join('');

    const go = (i) => {
        index = (i + slides) % slides;
        track.style.transform = `translateX(-${index * 100}%)`;
        dots.querySelectorAll('i').forEach((d, j) => d.classList.toggle('on', j === index));
    };

    const play = () => { timer = setInterval(() => go(index + 1), 6000); };
    const stop = () => clearInterval(timer);
    const restart = () => { stop(); play(); };

    slider.querySelector('.sarr.prev')?.addEventListener('click', () => { go(index - 1); restart(); });
    slider.querySelector('.sarr.next')?.addEventListener('click', () => { go(index + 1); restart(); });
    dots.addEventListener('click', (e) => {
        const d = e.target.closest('[data-go]');
        if (d) { go(Number(d.dataset.go)); restart(); }
    });

    // Pause while the pointer is over it, and while the tab is hidden — an
    // unattended slider advancing in a background tab is wasted work.
    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', play);
    document.addEventListener('visibilitychange', () => document.hidden ? stop() : restart());

    // Swipe on touch.
    let startX = null;
    slider.addEventListener('pointerdown', (e) => { startX = e.clientX; });
    slider.addEventListener('pointerup', (e) => {
        if (startX === null) return;
        const dx = e.clientX - startX;
        if (Math.abs(dx) > 50) { go(dx < 0 ? index + 1 : index - 1); restart(); }
        startX = null;
    });

    play();
}

/**
 * The mobile slide-in menu.
 *
 * Lives here rather than in its own file because it is a dozen lines and only
 * ever appears alongside the tab bar.
 */
function initMobileChrome() {
    const menu = document.getElementById('mmenu');
    const scrim = document.getElementById('mscrim');
    const burger = document.getElementById('burger');
    if (!menu || !scrim) return;

    const singleOpen = menu.dataset.singleOpen !== '0';

    const open = () => {
        menu.classList.add('on');
        scrim.classList.add('on');
        document.body.classList.add('menu-open');
        document.body.style.overflow = 'hidden';
        burger?.setAttribute('aria-expanded', 'true');
    };
    const shut = () => {
        menu.classList.remove('on');
        scrim.classList.remove('on');
        document.body.classList.remove('menu-open');
        document.body.style.overflow = '';
        burger?.setAttribute('aria-expanded', 'false');
    };

    burger?.addEventListener('click', () => (menu.classList.contains('on') ? shut() : open()));
    document.getElementById('mmx')?.addEventListener('click', shut);
    scrim.addEventListener('click', shut);
    document.addEventListener('keydown', (e) => e.key === 'Escape' && shut());

    menu.addEventListener('click', (event) => {
        if (event.target.closest('[data-mm-close]')) { shut(); return; }

        const parent = event.target.closest('.mm-par');
        if (parent) {
            const node = parent.parentElement;
            const wasOpen = node.classList.contains('on');

            // Only one section open at a time, so the highlight keeps meaning
            // something. Siblings only — a nested section stays put.
            if (singleOpen) {
                [...node.parentElement.children].forEach((sib) => {
                    if (sib !== node) sib.classList?.remove('on');
                });
            }

            node.classList.toggle('on', !wasOpen);
            return;
        }

        // Following a link should close the sheet, or it is still open on return.
        if (event.target.closest('a')) shut();
    });

    initMenuFilter(menu);
}

/**
 * Filter the menu as you type.
 *
 * Matches on the row's own text, and keeps a parent visible when any of its
 * children match — otherwise searching for a brand would hide the section it
 * lives in. Sections holding a match are opened so the result is on screen.
 */
function initMenuFilter(menu) {
    const input = document.getElementById('mmFilter');
    const empty = document.getElementById('mmEmpty');
    if (!input) return;

    const rows = [...menu.querySelectorAll('.mm-it, .mm-si, .mm-grp')];
    const nodes = [...menu.querySelectorAll('.mm-node')];

    const run = () => {
        const q = input.value.trim().toLowerCase();

        if (!q) {
            rows.forEach((r) => r.classList.remove('mm-hide'));
            nodes.forEach((n) => { n.classList.remove('mm-hide'); n.classList.remove('on'); });
            if (empty) empty.hidden = true;
            return;
        }

        let hits = 0;

        // Leaves first, then a section is shown if it or anything inside matched.
        rows.forEach((row) => {
            if (row.classList.contains('mm-par') || row.classList.contains('mm-grp')) return;
            const match = row.textContent.toLowerCase().includes(q);
            row.classList.toggle('mm-hide', !match);
            if (match) hits++;
        });

        nodes.slice().reverse().forEach((node) => {
            const label = node.querySelector('.mm-par');
            const selfMatch = label && label.textContent.toLowerCase().includes(q);
            const childMatch = node.querySelector('.mm-si:not(.mm-hide), .mm-it:not(.mm-hide), .mm-node:not(.mm-hide)');

            if (selfMatch) {
                node.querySelectorAll('.mm-si, .mm-it').forEach((r) => r.classList.remove('mm-hide'));
                node.querySelectorAll('.mm-node').forEach((n) => n.classList.remove('mm-hide'));
                hits++;
            }

            const show = selfMatch || !!childMatch;
            node.classList.toggle('mm-hide', !show);
            node.classList.toggle('on', show);
            if (label) label.classList.remove('mm-hide');
        });

        menu.querySelectorAll('.mm-grp').forEach((g) => g.classList.add('mm-hide'));
        if (empty) empty.hidden = hits > 0;
    };

    let timer;
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 90); });
}


/**
 * Collapse the trending row once the page moves.
 *
 * The bar and the category nav stay pinned; the trending chips are a discovery
 * aid, so they give their row back as soon as someone starts reading. The
 * listener is passive and only touches the DOM when the state actually
 * changes, so it costs nothing while scrolling.
 */
function initHeaderScroll() {
    const header = document.querySelector('header');
    if (!header) return;

    /*
     * Two thresholds, not one.
     *
     * The trending row is up to 52px tall and collapsing it shortens the page,
     * which can drop the scroll position back below a single threshold — the
     * row reopens, the page lengthens, and it crosses again. That feedback
     * loop is what made the header shudder on a small scroll.
     *
     * Collapsing at 120 and reopening only below 40 leaves an 80px gap, wider
     * than the row itself, so a collapse can never undo its own trigger.
     */
    const COLLAPSE_AT = 120;
    const EXPAND_AT = 40;

    let scrolled = false;
    let ticking = false;

    const apply = () => {
        ticking = false;
        const y = window.scrollY;

        // Only the crossing of a threshold changes anything; between the two
        // the current state simply holds.
        const next = scrolled ? y > EXPAND_AT : y > COLLAPSE_AT;

        if (next !== scrolled) {
            scrolled = next;
            header.classList.toggle('scrolled', next);
        }
    };

    // One read per frame at most, so a fast scroll cannot queue up work.
    const onScroll = () => {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(apply);
        }
    };

    apply();
    window.addEventListener('scroll', onScroll, { passive: true });
}


/**
 * The account panel.
 *
 * Hover opens it on a pointer device; a tap opens it on a phone, where it
 * becomes a sheet with a backdrop. The trigger is a link, so a tap must be
 * stopped from navigating before the panel has a chance to appear.
 */
export function initAccountPanel() {
    const host = document.querySelector('[data-acct]');
    if (!host) return;

    const panel = host.querySelector('.acct');
    const link = host.querySelector('a.ib');
    if (!panel || !link) return;

    const phone = () => window.matchMedia('(max-width: 900px)').matches;
    let scrim = null;
    let timer;

    const open = () => {
        clearTimeout(timer);
        panel.hidden = false;
        link.setAttribute('aria-expanded', 'true');

        if (phone() && !scrim) {
            scrim = document.createElement('div');
            scrim.className = 'acct-scrim';
            scrim.addEventListener('click', close);
            document.body.appendChild(scrim);
        }
    };

    const close = () => {
        panel.hidden = true;
        link.setAttribute('aria-expanded', 'false');
        scrim?.remove();
        scrim = null;
    };

    // Hover or click, as chosen. A phone is always a tap, since hover there
    // either does not exist or fires on the tap anyway.
    const byHover = 'click' !== host.dataset.acctOpen;

    if (byHover) {
        // A short grace period, so crossing the gap does not close it.
        host.addEventListener('mouseenter', () => { if (!phone()) open(); });
        host.addEventListener('mouseleave', () => { if (!phone()) timer = setTimeout(close, 180); });
    }

    link.addEventListener('click', (event) => {
        if (!phone() && byHover) return;   // the link works as a link
        event.preventDefault();
        panel.hidden ? open() : close();
    });

    document.addEventListener('keydown', (e) => { if ('Escape' === e.key) close(); });
    document.addEventListener('pointerdown', (e) => {
        if (!host.contains(e.target) && !e.target.closest('.acct')) close();
    }, true);

    // Sign in / create account
    panel.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-acct-tab]');
        if (!tab) return;
        const which = tab.dataset.acctTab;
        panel.querySelectorAll('[data-acct-tab]').forEach((t) => t.classList.toggle('on', t === tab));
        panel.querySelectorAll('[data-acct-pane]').forEach((p) => p.classList.toggle('on', p.dataset.acctPane === which));
    });

    // A fresh sum after a failed submission, since each one is single-use.
    panel.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form.querySelector('[data-hc-token]')) return;

        setTimeout(async () => {
            try {
                const r = await fetch('/api/human-check', { headers: { Accept: 'application/json' } });
                if (!r.ok) return;
                const q = await r.json();
                form.querySelector('[data-hc-token]').value = q.token;
                form.querySelector('[data-hc-q]').textContent = q.question;
            } catch (e) { /* the page is navigating anyway */ }
        }, 1200);
    });
}


/** Sign in / create account tabs on the account page. */
export function initAccountPage() {
    const tabs = document.querySelectorAll('[data-acw-tab]');
    if (!tabs.length) return;

    tabs.forEach((tab) => tab.addEventListener('click', () => {
        const which = tab.dataset.acwTab;
        tabs.forEach((t) => t.classList.toggle('on', t === tab));
        document.querySelectorAll('[data-acw-pane]').forEach((p) =>
            p.classList.toggle('on', p.dataset.acwPane === which));
    }));
}


/** Show or hide a password. */
export function initReveal() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-reveal]');
        if (!button) return;

        const input = document.getElementById(button.dataset.reveal);
        if (!input) return;

        const shown = 'text' === input.type;
        input.type = shown ? 'password' : 'text';
        button.classList.toggle('on', !shown);
        button.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
        input.focus();
    });
}


/**
 * Password strength.
 *
 * Four bands, judged on length and variety with the obvious weaknesses
 * subtracted: a single repeated character, a run off the keyboard, a date, or
 * one of the passwords that turn up at the top of every breach list. It is a
 * hint rather than a gate — the form still enforces its eight-character
 * minimum on the server.
 */
const WEAK_WORDS = [
    'password', 'qwerty', 'abc123', 'letmein', 'welcome', 'admin', 'iloveyou',
    'monkey', 'dragon', 'football', 'princess', 'sunshine', 'kbeauty', 'skincare',
];

function passwordScore(value) {
    if (!value) return { score: 0, label: '' };

    const lower = value.toLowerCase();

    // Anything on this list is weak whatever else it contains.
    if (WEAK_WORDS.some((w) => lower.includes(w))) return { score: 1, label: 'Too common' };

    // A single character repeated, or a straight run, is length without variety.
    if (/^(.)\1+$/.test(value)) return { score: 1, label: 'Too simple' };
    if (/^(?:0123|1234|2345|3456|4567|5678|6789|abcd|qwer|asdf)/i.test(value)) {
        return { score: 1, label: 'Too simple' };
    }

    let score = 0;
    if (value.length >= 8) score += 1;
    if (value.length >= 12) score += 1;
    if (value.length >= 16) score += 1;

    let variety = 0;
    if (/[a-z]/.test(value)) variety += 1;
    if (/[A-Z]/.test(value)) variety += 1;
    if (/[0-9]/.test(value)) variety += 1;
    if (/[^A-Za-z0-9]/.test(value)) variety += 1;

    if (variety >= 3) score += 1;
    if (variety === 4) score += 1;

    // Only digits, however many, is a date or a phone number.
    if (/^\d+$/.test(value)) score = Math.min(score, 1);

    if (value.length < 8) score = Math.min(score, 1);

    score = Math.max(1, Math.min(4, score));

    return { score, label: ['', 'Weak', 'Fair', 'Good', 'Strong'][score] };
}

export function initPasswordMeter() {
    const meter = document.querySelector('.meter');
    if (!meter) return;

    /*
     * Every password field on the form, not just the first.
     *
     * A browser filling saved details often puts the value in the confirmation
     * field and leaves the first one empty, which left the bar at zero with a
     * password plainly on screen. Whichever field holds something is the one
     * worth judging.
     */
    const fields = Array.from(document.querySelectorAll('input[type="password"]'));
    if (!fields.length) return;

    const label = meter.querySelector('.meter-label');

    const update = () => {
        const filled = fields.find((f) => f.value);
        const { score, label: text } = passwordScore(filled ? filled.value : '');
        meter.dataset.score = String(score);
        if (label) label.textContent = text;
    };

    meter.dataset.score = '0';

    fields.forEach((f) => {
        f.addEventListener('input', update);
        // A field the browser fills never fires `input`.
        f.addEventListener('change', update);
    });

    /*
     * Autofill lands without an event and without a fixed delay, so the first
     * couple of seconds are polled and then left alone, rather than running a
     * timer for the life of the page.
     */
    let tries = 0;
    const settle = setInterval(() => {
        update();
        if (++tries > 8) clearInterval(settle);
    }, 250);
}
