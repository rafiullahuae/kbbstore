/*
 * The exit popup (Lane GO, Marketing -> Gift offers -> Popup). A classic
 * script, no import or export: fetched ONLY by the inline watcher in
 * partials/gift-offers/popup.blade.php, at the moment of real exit intent, on
 * a page whose visitor has a bag and while the owner has the popup on. Never
 * in the head, never render-blocking, measures nothing.
 *
 * It clones the server-rendered <template data-kgo-pop> (the real markup and
 * styles), runs the popup's own countdown (one 1-second timer, stopped when
 * the popup closes or reaches zero), and on "Claim it now" posts once to
 * /gift-offer/popup -- the server decides the gift from the basket it holds,
 * never from anything sent here -- then shows the green tick and opens the
 * cart page. "Maybe later", the close button, Escape or a tap outside count
 * as dismissed and keep it away for 24 hours. Shown and dismissed are one
 * beacon each. In the admin's live preview (data-preview) nothing is posted.
 */
(function (d, w) {
    'use strict';

    var K = (w.kbbGo = w.kbbGo || {});

    K.open = function () {
        if (K.up) return;
        var t = d.querySelector('template[data-kgo-pop]');
        if (!t) return;
        K.up = true;

        var preview = t.hasAttribute('data-preview');
        var range = t.getAttribute('data-r') || '0';
        var minutes = +t.getAttribute('data-min') || 15;
        var host = d.createElement('div');
        host.className = 'kgo-host';
        host.appendChild(t.content.cloneNode(true));
        d.body.appendChild(host);

        var ov = host.querySelector('[data-kgo-ov]');
        var end = Date.now() + minutes * 60000;
        var done = false;
        var iv = setInterval(tick, 1000);

        function pad(n) { return (n < 10 ? '0' : '') + n; }

        function each(sel, fn) {
            var list = host.querySelectorAll(sel);
            for (var i = 0; i < list.length; i++) fn(list[i]);
        }

        function tick() {
            var s = Math.max(0, Math.round((end - Date.now()) / 1000));
            var m = Math.floor(s / 60), x = s % 60;
            each('[data-kgo-m]', function (e) { e.textContent = pad(m) + ':' + pad(x); });
            each('[data-kgo-mm]', function (e) { e.textContent = pad(m); });
            each('[data-kgo-ss]', function (e) { e.textContent = pad(x); });
            each('[data-kgo-bar]', function (e) { e.style.width = (s / (minutes * 60) * 100).toFixed(2) + '%'; });
            if (!s) clearInterval(iv);
        }

        function csrf() {
            var m = d.querySelector('meta[name="csrf-token"]');
            return (w.KBB && w.KBB.csrf) || (m && m.getAttribute('content')) || '';
        }

        function beacon(e) {
            if (preview || !navigator.sendBeacon) return;
            try {
                var f = new FormData();
                f.append('e', e);
                f.append('r', range);
                f.append('_token', csrf());
                navigator.sendBeacon(t.getAttribute('data-ev'), f);
            } catch (err) { /* a count is never worth an error */ }
        }

        function close(dismissed) {
            if (done) return;
            done = true;
            clearInterval(iv);
            d.removeEventListener('keydown', key);
            if (host.parentNode) host.parentNode.removeChild(host);
            K.up = false;
            if (dismissed) {
                beacon('dismissed');
                try { w.localStorage.setItem('kbbGoNo', String(Date.now())); } catch (err) { /* private mode */ }
            }
        }

        function key(e) { if (e.key === 'Escape') close(true); }

        // "When press Claim, the celebration should animate over the whole
        // screen" (the owner, 6 Nov): ~110 pieces of confetti, a burst from
        // the popup and a rain from the top, transform and opacity only, one
        // layer that ignores taps and removes itself. Nothing is measured;
        // reduced motion gets the green tick alone.
        function confetti() {
            var C = ['#c6395f', '#ff8fb1', '#ffd36e', '#1fa463', '#b48cff', '#ffffff', '#ff6b4a'];
            var R = Math.random, html = '', i, c, wd, ht;
            function piece(tag, extra) {
                c = C[(R() * C.length) | 0];
                wd = 6 + R() * 8; ht = R() < 0.3 ? wd : 10 + R() * 10;
                return '<' + tag + ' style="--c:' + c + ';--w:' + wd.toFixed(1) + 'px;--h:' + ht.toFixed(1) + 'px;--br:' + (ht === wd ? '50%' : '2px')
                    + ';--r:' + ((R() * 1440 - 720) | 0) + 'deg;' + extra + '"></' + tag + '>';
            }
            for (i = 0; i < 60; i++) {
                var a = R() * Math.PI * 2, dist = 18 + R() * 32;
                html += piece('b', '--bx:' + (Math.cos(a) * dist).toFixed(1) + 'vw;--by:' + (Math.sin(a) * dist - 12).toFixed(1) + 'vh;--d:' + (1.4 + R()).toFixed(2) + 's');
            }
            for (i = 0; i < 50; i++) {
                html += piece('i', '--x:' + (R() * 100).toFixed(1) + 'vw;--dx:' + ((R() * 30 - 15) | 0) + 'vw;--d:' + (1.8 + R() * 1.4).toFixed(2) + 's;--dl:' + (R() * 0.6).toFixed(2) + 's');
            }
            var cf = d.createElement('div');
            cf.className = 'kgo-cf';
            cf.setAttribute('aria-hidden', 'true');
            cf.innerHTML = html;
            host.appendChild(cf);
            setTimeout(function () { if (cf.parentNode) cf.parentNode.removeChild(cf); }, 3600);
        }

        function celebrate(then) {
            var tk = host.querySelector('[data-kgo-tick]');
            if (tk) tk.hidden = false;
            var reduce = w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!reduce) confetti();
            setTimeout(then, reduce ? 400 : 2200);
        }

        function claim(btn) {
            btn.disabled = true;
            if (preview) { celebrate(function () { if (!done) { var tk = host.querySelector('[data-kgo-tick]'); if (tk) tk.hidden = true; btn.disabled = false; } }); return; }
            fetch(t.getAttribute('data-claim'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok && j && j.ok, url: j && j.url }; });
            }).then(function (res) {
                if (!res.ok || !res.url) { close(false); return; }
                clearInterval(iv);
                celebrate(function () { w.location.href = res.url; });
            }).catch(function () { close(false); });
        }

        host.addEventListener('click', function (e) {
            var el = e.target;
            if (el === ov || (el.closest && el.closest('[data-kgo-later]'))) { close(true); return; }
            var open = el.closest && el.closest('[data-kgo-open]');
            if (open) {
                var step = host.querySelector('[data-kgo-step]'), rev = host.querySelector('[data-kgo-reveal]');
                if (step) step.hidden = true;
                if (rev) rev.hidden = false;
                var c = host.querySelector('[data-kgo-claim]');
                if (c) c.focus({ preventScroll: true });
                return;
            }
            var b = el.closest && el.closest('[data-kgo-claim]');
            if (b && !b.disabled) claim(b);
        });
        d.addEventListener('keydown', key);

        tick();
        beacon('shown');
        var first = host.querySelector('[data-kgo-claim],[data-kgo-open]');
        if (first) first.focus({ preventScroll: true });
    };
})(document, window);
