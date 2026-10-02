/**
 * PRESS FEEDBACK — Lane RD.
 *
 * The owner: "when u click on any button or icon. it leaves gray square /
 * rectangle box instead of changing the color of button icon itself ... click
 * on any icon / button should give feeling live that user is clicking on some
 * live thing." He chose C · Ripple, with A, B, D and E selectable in
 * Appearance → Site layout → Press feedback.
 *
 * ── WHAT THIS FILE DOES, AND ALL IT DOES ────────────────────────────────────
 *
 * It toggles CLASSES on the control under the finger. Nothing else: it reads
 * no geometry, writes no style, and creates no element. Every pixel of every
 * style is in kbb.css, keyed by `html[data-press]`, so this file does not know
 * or care which letter is chosen.
 *
 *   kbb-pressed          on pointerdown, held for at least HOLD ms. A quick tap
 *                        on a phone is often shorter than one frame of :active,
 *                        which is half of why the shop's buttons felt dead.
 *   kbb-rip / kbb-rip2   on pointerdown, ALTERNATING. Two names for one wave,
 *                        so a second tap restarts it without the reflow trick
 *                        (reading a layout property to flush styles), which
 *                        this project forbids and this file never needs.
 *   kbb-pop / kbb-pop2   on release, alternating for the same reason (D's
 *                        spring back).
 *
 * The wave and pop classes come off on `animationend`. A letter whose CSS
 * declares no animation for them simply leaves them on until the next press
 * swaps them, which styles nothing.
 *
 * ── OFF IS THE PAGE AS IT WAS ───────────────────────────────────────────────
 *
 * With Off chosen the layout prints no `data-press` at all, and this returns
 * before adding a single listener. No class is ever added, so no rule matches
 * and nothing the shop did before changes.
 *
 * ── NO MEASURING ────────────────────────────────────────────────────────────
 *
 * The ripple spreads from the CENTRE of the control, as the owner's playground
 * did, precisely so that nothing has to know where the finger landed relative
 * to the box. PressFeedbackTest forbids every element-measuring API by name in
 * this file.
 */

/* What counts as a control. The CSS decides what each one does; this only
   finds the element a press belongs to. `.search-in` is the header search box,
   whose field is an <input type="search"> inside it. */
const CONTROL = 'a[href], button, [role="button"], summary, label, select, '
    + 'input[type="submit"], input[type="button"], input[type="checkbox"], input[type="radio"], .search-in';

/* The shortest a press is shown for, in ms. */
const HOLD = 180;

const swap = (el, a, b) => {
    const next = el.classList.contains(a) ? b : a;
    el.classList.remove(a, b);
    el.classList.add(next);
};

export function initPress() {
    if (!document.documentElement.hasAttribute('data-press')) return;

    let held = null;
    let since = 0;
    let timer = 0;
    let letGo = null;

    const finish = () => {
        clearTimeout(timer);
        timer = 0;
        if (letGo) {
            const el = letGo;
            letGo = null;
            el.classList.remove('kbb-pressed');
            swap(el, 'kbb-pop', 'kbb-pop2');
        }
    };

    const press = (el) => {
        finish();
        if (held && held !== el) held.classList.remove('kbb-pressed');
        held = el;
        since = Date.now();
        el.classList.add('kbb-pressed');
        swap(el, 'kbb-rip', 'kbb-rip2');
    };

    const release = () => {
        if (!held) return;
        letGo = held;
        held = null;
        timer = setTimeout(finish, Math.max(0, HOLD - (Date.now() - since)));
    };

    const controlOf = (target) => {
        const el = target && target.closest ? target.closest(CONTROL) : null;
        if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') return null;
        return el;
    };

    document.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || !event.isPrimary) return;
        const el = controlOf(event.target);
        if (el) press(el);
    }, { passive: true });

    document.addEventListener('pointerup', release, { passive: true });
    document.addEventListener('pointercancel', release, { passive: true });
    window.addEventListener('blur', release);

    /* Enter or Space on a focused control is a press too. */
    document.addEventListener('keydown', (event) => {
        if (event.repeat || (event.key !== 'Enter' && event.key !== ' ')) return;
        const el = controlOf(event.target);
        if (el) press(el);
    });
    document.addEventListener('keyup', release);

    document.addEventListener('animationend', (event) => {
        if (!String(event.animationName).startsWith('kbb-press-')) return;
        const el = event.target;
        if (el && el.classList) el.classList.remove('kbb-rip', 'kbb-rip2', 'kbb-pop', 'kbb-pop2');
    });
}
