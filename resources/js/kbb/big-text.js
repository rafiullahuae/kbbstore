/*
 * PHONES SET TO LARGE TEXT SEE THE NORMAL SHOP -- the second half. (Lane BT)
 *
 * Loaded ONLY for a visitor whose browser is enlarging text: the inline
 * detector in partials/big-text-guard.blade.php reads its own computed size,
 * and only when that is more than 4% over 16px does it document.write this
 * file, parser-blocking, so it runs before the rest of <body> paints and the
 * enlarged phone never sees a frame of huge text. A normal visitor never
 * requests it. The mechanism, the measurements and why each rule below exists
 * are in that partial's header and docs/lane-bt/README.md; BigTextGuardTest
 * pins this file.
 *
 * Every rule is a CORRECTION of the shop's own declarations, never a new style:
 *   font-size     absolute sizes / zoom (solved in float32, see ex());
 *   line-height   px and calc / zoom; var() ones only when the custom
 *                 properties they name are defined with lengths;
 *   em/rem/ic     on every other property x zoom (they come from the divided
 *                 specified size and would shrink);
 *   SVG text      left alone (Blink never applies text zoom to it);
 *   the UA's em margins and the root / form-control sizes in one <style> put
 *   first in <head>, the margins at :where() specificity 0.
 */
(function (d) {
    var me = d.currentScript;
    var r = me ? parseFloat(me.getAttribute('data-kbb-zoom')) : 0;
    if (!(r > 1.04)) return;
    var h = d.documentElement;
    var k = 1 / r;
    // Absolute lengths (for sizes and line-heights) and specified-size units
    // (for everything else). A number inside a name (--x2px, #12px) is not a
    // length; a leading minus IS part of one (letter-spacing:-.02em).
    var A = /(^|[^\w.#-])(-?\d*\.?\d+)(px|pt|pc|cm|mm|q|in|[sld]?v(?:w|h|i|b|min|max)|cq(?:w|h|i|b|min|max))(?![\w-])/gi;
    var E = /(^|[^\w.#-])(-?\d*\.?\d+)(r?em|ic)(?![\w-])/gi;
    var L = /\d(px|pt|pc|cm|mm|q|in|[sld]?v[whib]|vmin|vmax|cq)/i;
    // Properties that never hold a length, so their value is never read. NOT
    // mask, background, filter or clip-path: they can hold em (measured: the
    // product description's fade mask and the cart's row gradient drew a
    // line short when they were skipped).
    var SKIP = /^(color|display|position|z-index|opacity|visibility|overflow|cursor|content|order|flex-(grow|shrink|direction|wrap)|align|justify|place|transition|animation|will-change|pointer-events|user-select|white-space|text-(align|transform|decoration|overflow|rendering)|font-(weight|family|style|variant|feature|optical|synthesis|kerning|stretch)|object-fit|list-style-type|box-sizing|appearance|-webkit-(appearance|tap|user|line-clamp|box-orient|font-smoothing|text-size)|fill|stroke|mix-blend|isolation|direction|unicode|resize|outline-(style|color)|border-[a-z-]*(style|color)|caret|accent|scroll-behavior|touch-action|aspect-ratio|grid-(area|row|column|auto-flow|template-areas)|table-layout|content-visibility|contain$|backface|image-rendering|line-clamp|float|clear|hyphens|word-break|overflow-wrap|tab-size|quotes|counter|speak|forced)/;
    var SVG_TEXT = /(^|[\s>+~,(])(text|tspan|textpath)\b/i;
    var K = { 'xx-small': 9, 'x-small': 10, small: 13, medium: 16, large: 18, 'x-large': 24, 'xx-large': 32, 'xxx-large': 48 };
    var D = {};   // custom property -> 1 defined as a length, 2 as anything else, 3 both
    var U = [];   // var() line-heights waiting for their custom properties' definitions

    // Blink multiplies in float32 (FontBuilder: specified x zoom x
    // TextZoomFactor), and WebView's factor is percent / 100.0f. ex(t) finds
    // the float32 size whose product is exactly t, or the nearest one just
    // above it (at most 0.000004px over); a plain n * (1/1.3) lands a hair
    // under 13px and moved glyph advances by a tenth of a pixel per word.
    var F = Math.fround(Math.round(r * 100) / 100);
    var B = new Float32Array(1);
    var I = new Int32Array(B.buffer);
    function ex(t) {
        t = Math.fround(t);
        B[0] = t / F;
        var base = I[0], best = t * k, over = Infinity;
        for (var j = -4; j < 5; j++) {
            I[0] = base + j;
            var p = Math.fround(B[0] * F);
            if (p >= t && p < over) { over = p; best = B[0]; }
        }
        return +best.toPrecision(9);
    }

    // Where the size is only known at use time (var(), calc, vw), the product
    // cannot be solved in advance; a multiplier 3e-7 high lands it at or just
    // above the shop's own size, as ex() does when no float is exact, instead
    // of a hair under.
    var kUp = k * (1 + 3e-7);

    function scale(v, R, m) {
        var o = v.replace(R, function (x, a, n, u) {
            return a + (m === k && u.toLowerCase() === 'px' ? ex(+n) : n * (m === k ? kUp : m)) + u;
        });
        return o === v ? '' : o;
    }

    function size(v) {
        var w = v.trim().toLowerCase();
        if (K[w]) return ex(K[w]) + 'px';
        // No custom property on this shop holds an em length (BigTextGuardTest
        // checks), so a var() size is a length: divide its use, never its
        // definition.
        if (w.indexOf('var(') > -1) return /\d(r?em|ex|ch|lh|%)/.test(w) ? '' : 'calc(' + v + ' * ' + kUp + ')';
        return scale(v, A, k);
    }

    // One declaration block, once (marked), so no later pass repeats it.
    // `rule` is given for a stylesheet rule: its selector is read only when a
    // change is about to be made (SVG text is skipped), not for every rule.
    function fix(t, rule) {
        if (t.kbbTz) return;
        t.kbbTz = 1;
        var props = [], i, p, v, n;
        // Most declarations cannot hold a length (colours, display, grid
        // lines...): their value is never read.
        for (i = 0; i < t.length; i++) if (!SKIP.test(t[i])) props.push(t[i]);
        if (rule && props.length && rule.selectorText && SVG_TEXT.test(rule.selectorText)) return;
        for (i = 0; i < props.length; i++) {
            p = props[i];
            if (!(v = t.getPropertyValue(p))) continue;
            if (p.indexOf('--') === 0) { D[p] = (D[p] | 0) | (L.test(v) ? 1 : 2); continue; }
            if (p === 'line-height' && v.indexOf('var(') > -1) { U.push([t, v]); continue; }
            n = p === 'font-size' ? size(v) : p === 'line-height' ? scale(v, A, k) : scale(v, E, r);
            if (n) t.setProperty(p, n, t.getPropertyPriority(p));
        }
    }

    // var() line-heights: --sc-btn-h is 44px (the card button's height) and
    // --sc-name-lh is 1.32 (a number that must not be divided). Divide only
    // when every property named is defined with lengths; a name never defined
    // decides, at `load` (final), by whether the fallback is a length.
    function lineHeights(final) {
        for (var i = U.length; i--;) {
            var u = U[i], names = u[1].match(/--[\w-]+/g) || [], ok = 1;
            for (var m = 0; m < names.length; m++) {
                var x = D[names[m]];
                if (x > 1) { ok = -1; break; }
                if (!x) ok = final ? (L.test(u[1]) ? 1 : -1) : 0;
            }
            if (ok) {
                U.splice(i, 1);
                if (ok > 0) u[0].setProperty('line-height', 'calc(' + u[1] + ' * ' + kUp + ')', u[0].getPropertyPriority('line-height'));
            }
        }
    }

    function rules(list) {
        for (var i = 0; i < list.length; i++) {
            var x = list[i];
            var st = x.style;
            if (st) fix(st, x);
            if (x.cssRules) rules(x.cssRules);
            else if (x.styleSheet) sheet(x.styleSheet);
        }
    }

    function sheet(s) {
        if (s.kbbTz) return;
        try { rules(s.cssRules); s.kbbTz = 1; } catch (e) { /* cross-origin or not loaded yet: next pass */ }
    }

    function sweep() {
        for (var S = d.styleSheets, i = 0; i < S.length; i++) sheet(S[i]);
        lineHeights();
    }

    function one(e) { if (e.style && !e.ownerSVGElement) fix(e.style); }
    function inline(n) {
        if (n.nodeType !== 1) return;
        if (n.hasAttribute('style')) one(n);
        for (var Q = n.querySelectorAll('[style]'), i = 0; i < Q.length; i++) one(Q[i]);
    }

    function ua(x, m) { return ':where(' + x + '){margin-block:' + m * r + 'em}'; }
    var st = d.createElement('style');
    st.textContent = 'html{font-size:' + ex(16) + 'px}input,select,textarea,button{font-size:' + 13.3333 * k + 'px}'
        + ua('p,blockquote,figure,dl,ol,ul,menu,pre', 1) + ':where(:is(ul,ol,menu) :is(ul,ol,menu)){margin-block:0}'
        + ua('h1', .67) + ua('h2', .83) + ua('h3', 1) + ua('h4', 1.33) + ua('h5', 1.67) + ua('h6', 2.33) + ua('hr', .5);
    d.head.insertBefore(st, d.head.firstChild);
    st.sheet.kbbTz = 1;

    h.classList.add('kbb-bt');
    sweep();
    inline(h);
    lineHeights();

    // What the page adds later -- a body stylesheet, the cart drawer, search
    // suggestions, checkout's overlay. An observer, not a timer: nothing polls.
    new MutationObserver(function (M) {
        for (var i = 0; i < M.length; i++) {
            for (var a = M[i].addedNodes, j = 0; j < a.length; j++) {
                var n = a[j];
                if (n.nodeName === 'LINK') n.addEventListener('load', sweep);
                else inline(n);
            }
        }
        sweep();
    }).observe(h, { childList: true, subtree: true });
    d.addEventListener('DOMContentLoaded', sweep);
    addEventListener('load', function () { sweep(); lineHeights(1); });
})(document);
