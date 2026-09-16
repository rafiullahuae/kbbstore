/**
 * Two proofs, in a real browser, for the two changes LANE CR made to the admin
 * console. Prints one JSON object on stdout and nothing else.
 *
 * 1. THEME. Rules whose colour was typed in by hand cannot follow a theme, so
 *    parts of the console stayed light when the rest went dark. This reads the
 *    COMPUTED colour of each rule that was changed, under the light theme and
 *    under midnight, and of a control group that was deliberately NOT changed.
 *    A wrong token is invisible in a diff and obvious on screen -- and it is
 *    also a wrong number here, which is the point: the control group must come
 *    back identical in both themes, and the converted group must differ.
 *
 * 2. LABELS. Clicking the words beside a tick box did nothing, because a
 *    <label> forwards a click to a form control and these boxes are spans. The
 *    danger in fixing it is the double fire -- a global handler that calls
 *    .click() on something that has its own handler flips the state and flips
 *    it straight back. So this does not look at the box, it COUNTS CLASS
 *    TRANSITIONS with a MutationObserver: one click on the words must be
 *    exactly one transition, never two, in both of the shapes that exist.
 *
 * Config, all via environment:
 *   KBB_BROWSER_BASE      base URL of a running preview  (required)
 *   KBB_BROWSER_EMAIL     admin login                    (required)
 *   KBB_BROWSER_PASSWORD  admin password                 (required)
 *   KBB_BROWSER_CHROME    chromium executable            (required)
 *   KBB_BROWSER_SHOTS     directory for screenshots; skipped when unset
 */
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = process.env.KBB_BROWSER_BASE;
const EMAIL = process.env.KBB_BROWSER_EMAIL;
const PASSWORD = process.env.KBB_BROWSER_PASSWORD;
const EXE = process.env.KBB_BROWSER_CHROME;
const SHOTS = process.env.KBB_BROWSER_SHOTS || '';

const fail = (msg) => {
  process.stdout.write(JSON.stringify({ ok: false, error: String(msg).split('\n')[0] }));
  process.exit(0);
};

for (const [k, v] of Object.entries({
  KBB_BROWSER_BASE: BASE, KBB_BROWSER_EMAIL: EMAIL,
  KBB_BROWSER_PASSWORD: PASSWORD, KBB_BROWSER_CHROME: EXE,
})) {
  if (!v) fail(`${k} is not set`);
}

/* The rules LANE CR pointed at a token, and the rules it deliberately left
   alone. Both lists are measured; the second is the regression guard. */
const CONVERTED = [
  ['.iconbtn', 'backgroundColor'],
  ['.userchip', 'backgroundColor'],
  ['.btn.ghost', 'backgroundColor'],
  ['.modal', 'backgroundColor'],
  ['.flag', 'backgroundColor'],
  ['.paydot.green', 'backgroundColor'],
  ['.paydot.amber', 'backgroundColor'],
  ['.paydirty', 'color'],
];

const LEFT_ALONE = [
  ['.logo', 'color'],            // white ON accent
  ['.btn', 'color'],             // white ON accent
  ['.sev.crit', 'color'],        // white ON red
  ['.tog::after', 'background'], // the switch thumb, sliding on a colour
  ['.ectog::after', 'background'], // the other switch thumb
  ['.ppanel', 'backgroundColor'],  // search-panel preview of the storefront
  ['.appanel', 'backgroundColor'], // login-panel preview of the storefront
  ['.apd', 'backgroundColor'],     // its device buttons, on the storefront palette
  // Whole screens built on a palette of their own. Moving only their whites
  // leaves a dark panel behind light-grey borders, so they were left whole.
  ['.hplist', 'backgroundColor'],
  ['.mgm-menutab', 'backgroundColor'],
  ['.ecsec', 'backgroundColor'],
  ['.odcard', 'backgroundColor'],
  ['.kdlg-b', 'backgroundColor'],
];

const out = {
  ok: true,
  themes: {},
  overflow: {},
  probe: {},
  labels: null,
  pageErrors: [],
  httpLog: [],
  shots: [],
};

let browser;

try {
  browser = await chromium.launch({ executablePath: EXE });

  // setViewportSize does not work in this environment; the viewport has to be
  // set when the context is created.
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  page.on('pageerror', (e) => out.pageErrors.push(`[pageerror] ${e.message.split('\n')[0]}`));
  page.on('console', (m) => {
    if (m.type() === 'error') out.httpLog.push(`[console.error] ${m.text()}`);
  });
  // Recorded synchronously, and WITH the url: "Failed to load resource" on its
  // own names nothing and cannot be told from a real break.
  /* NETWORK NOISE IS KEPT APART FROM SCRIPT ERRORS, on purpose. The walk
     drives one screen after another, so a screen's own data request is still
     in flight when the next goto() cancels it, and the preview's database is
     empty besides. Those show up as failed requests and as a console line that
     names nothing ("Failed to load resource"), and they say nothing about the
     two changes under test. A THROWN SCRIPT is a different matter -- a click
     handler that blows up is exactly the failure this lane could cause -- so
     pageErrors carries only that, and the rest goes in httpLog for reading. */
  page.on('response', (r) => {
    if (r.status() >= 400) out.httpLog.push(`[http ${r.status()}] ${r.url()}`);
  });
  page.on('requestfailed', (r) => out.httpLog.push(`[requestfailed] ${r.url()}`));

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  if (!page.url().includes('/admin')) fail(`login did not reach the admin: ${page.url()}`);

  /* ---------- 1. the theme ------------------------------------------------ */

  const readColours = async (theme) => page.evaluate(({ theme, conv, left }) => {
    if (theme === 'aurora') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.dataset.theme = theme;

    /* Measured on a detached-from-the-screen but attached-to-the-document
       probe, so every rule is read even on a screen that does not draw it.
       The cascade is the same; only the position is off-screen. */
    const host = document.createElement('div');
    host.style.cssText = 'position:fixed;left:-9999px;top:0';
    document.body.appendChild(host);

    const read = (sel, prop) => {
      const bare = sel.replace(/::after$/, '');
      const el = document.createElement(bare === '.envtog button.on' ? 'button' : 'div');
      el.className = bare.replace(/^\./, '').split('.').join(' ');
      host.appendChild(el);
      const cs = getComputedStyle(el, sel.endsWith('::after') ? '::after' : null);
      const v = cs[prop === 'background' ? 'backgroundColor' : prop];
      return v;
    };

    const res = { converted: {}, leftAlone: {} };
    for (const [sel, prop] of conv) res.converted[sel + ':' + prop] = read(sel, prop);
    for (const [sel, prop] of left) res.leftAlone[sel + ':' + prop] = read(sel, prop);

    // The real chrome on the page, not a probe -- these exist in the markup.
    const live = {};
    for (const sel of ['.iconbtn', '.userchip']) {
      const el = document.querySelector(sel);
      if (el) live[sel] = getComputedStyle(el).backgroundColor;
    }
    res.live = live;

    host.remove();
    return res;
  }, { theme, conv: CONVERTED, left: LEFT_ALONE });

  await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => typeof window.go === 'function');
  await page.waitForTimeout(700);

  out.themes.aurora = await readColours('aurora');
  out.themes.midnight = await readColours('midnight');
  await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));

  /* ---------- 2. #content overflow, and a live probe ---------------------- */

  const measureContent = async (probeWidth) => page.evaluate((w) => {
    const c = document.querySelector('#content');
    if (!c) return null;
    let injected = null;
    if (w) {
      injected = document.createElement('div');
      injected.id = 'kbb-cr-probe';
      injected.style.cssText = `width:${w}px;height:8px`;
      c.appendChild(injected);
    }
    const r = { scrollWidth: c.scrollWidth, clientWidth: c.clientWidth };
    if (injected) injected.remove();
    return r;
  }, probeWidth);

  const shot = async (name) => {
    if (!SHOTS) return;
    const file = `${SHOTS}/${name}.png`;
    await page.screenshot({ path: file, fullPage: false });
    out.shots.push(name);
  };

  const SCREENS = ['dash', 'payship', 'seo', 'catalog', 'console'];

  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const p = await ctx.newPage();
    p.on('pageerror', (e) => out.pageErrors.push(`[pageerror ${width}] ${e.message.split('\n')[0]}`));

    await p.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', EMAIL);
    await p.fill('input[name="password"]', PASSWORD);
    await Promise.all([
      p.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      p.click('button[type="submit"], input[type="submit"]'),
    ]);

    for (const theme of ['aurora', 'midnight']) {
      for (const id of SCREENS) {
        await p.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
        await p.waitForFunction(() => typeof window.go === 'function');
        await p.evaluate((t) => {
          if (t === 'aurora') document.documentElement.removeAttribute('data-theme');
          else document.documentElement.dataset.theme = t;
        }, theme);
        try {
          await p.evaluate((h) => window.go(h), id);
        } catch (e) {
          out.pageErrors.push(`[go ${id}] ${String(e.message).split('\n')[0]}`);
          continue;
        }
        await p.waitForTimeout(1100);

        const m = await p.evaluate(() => {
          const c = document.querySelector('#content');
          return c ? { scrollWidth: c.scrollWidth, clientWidth: c.clientWidth } : null;
        });
        out.overflow[`${width}/${theme}/${id}`] = m;

        if (SHOTS) {
          const name = `${width}-${theme}-${id}`;
          await p.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
          out.shots.push(name);
        }
      }
    }

    // The probe: a deliberately oversized block must be SEEN. If this does not
    // exceed the column width, the measurement above is not live and every
    // clear result it gave is worthless.
    await p.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
    await p.waitForFunction(() => typeof window.go === 'function');
    await p.waitForTimeout(600);
    out.probe[width] = await p.evaluate(() => {
      const c = document.querySelector('#content');
      const d = document.createElement('div');
      d.style.cssText = 'width:2400px;height:8px';
      c.appendChild(d);
      const r = { scrollWidth: c.scrollWidth, clientWidth: c.clientWidth };
      d.remove();
      return r;
    });

    await ctx.close();
  }

  /* ---------- 3. one click on the words = one transition ------------------ */

  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const p2 = await ctx2.newPage();
  p2.on('pageerror', (e) => out.pageErrors.push(`[pageerror labels] ${e.message.split('\n')[0]}`));

  await p2.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
  await p2.fill('input[name="email"]', EMAIL);
  await p2.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    p2.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    p2.click('button[type="submit"], input[type="submit"]'),
  ]);

  await p2.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
  await p2.waitForFunction(() => typeof window.go === 'function');
  await p2.evaluate(() => window.go('seo'));
  await p2.waitForTimeout(2000);

  out.labels = await p2.evaluate(() => {
    /* Counts how many times the box's `on` class actually changes. Eyeballing
       the final state cannot tell one flip from two. */
    /* A MutationObserver delivers its records as a MICROTASK, so a callback
       that increments a counter has not run yet by the time act() returns and
       every count comes back 0. takeRecords() drains them synchronously, which
       is the only way to count inside one turn.

       Each record carries the class string as it was BEFORE that write, so the
       run of states is [oldValue of each record..., the state now], and a
       transition is any adjacent pair that differs. That distinguishes one
       flip from a flip-and-flip-back, which is the whole point: two writes
       leave the box looking untouched. */
    function countTransitions(box, act) {
      const hasOn = (cls) => /(^|\s)on(\s|$)/.test(cls || '');
      const mo = new MutationObserver(() => {});
      mo.observe(box, { attributes: true, attributeFilter: ['class'], attributeOldValue: true });

      const before = box.classList.contains('on');
      act();
      const recs = mo.takeRecords();
      mo.disconnect();
      const after = box.classList.contains('on');

      const states = recs.map((r) => hasOn(r.oldValue));
      states.push(after);

      let n = 0;
      for (let i = 1; i < states.length; i++) {
        if (states[i] !== states[i - 1]) n++;
      }
      return { n, before, after, writes: recs.length };
    }

    const results = {};

    /* --- shape A: siblings. smOpt() draws <div class="sm-opt"><span class=cbx>
       </span><div><span class="sm-opt-t">words</span></div></div> -- no label
       anywhere in it. --- */
    const optT = document.querySelector('#content .sm-opt .sm-opt-t');
    if (optT) {
      const row = optT.closest('.sm-opt');
      const box = row.querySelector('.cbx');
      results.siblings = {
        found: true,
        id: box.id || null,
        wordsClick: countTransitions(box, () => optT.click()),
        boxClick: countTransitions(box, () => box.click()),
        rowPaddingClick: countTransitions(box, () => row.click()),
      };
      const help = row.querySelector('.sm-help');
      if (help) results.siblings.helpClick = countTransitions(box, () => help.click());
    } else {
      results.siblings = { found: false };
    }

    /* A link in the words must still be a link, not a toggle. */
    const link = document.querySelector('#content .sm-opt a[href]');
    if (link) {
      const box = link.closest('.sm-opt').querySelector('.cbx');
      results.linkInWords = countTransitions(box, () => {
        const e = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.dispatchEvent(e);
      });
    }

    return results;
  });

  /* --- shape B: a <label> wrapping the box and its words. The catalog screen's
     column picker draws <label class="so-col"><span class=cbx></span>Name. --- */
  /* The product editor, which draws the wrapping-label shape in quantity --
     .catopt for the category list and .pdchk for the product-data panes -- and
     wires every one of them through wireCbx(), whose handler is a plain
     classList.toggle('on'). That is the shape and the handler this fix has to
     work with, and it does not depend on a list request having succeeded.

     (The catalog column picker is the same shape, but its boxes come back
     unwired when the products request fails, so a run there proves nothing
     either way.) */
  await p2.evaluate(() => window.go('catalog'));
  await p2.waitForTimeout(1500);
  await p2.evaluate(() => {
    if (typeof window.openProduct === 'function') window.openProduct(0);
  });
  await p2.waitForTimeout(2000);

  const labelShape = await p2.evaluate(() => {
    /* A MutationObserver delivers its records as a MICROTASK, so a callback
       that increments a counter has not run yet by the time act() returns and
       every count comes back 0. takeRecords() drains them synchronously, which
       is the only way to count inside one turn.

       Each record carries the class string as it was BEFORE that write, so the
       run of states is [oldValue of each record..., the state now], and a
       transition is any adjacent pair that differs. That distinguishes one
       flip from a flip-and-flip-back, which is the whole point: two writes
       leave the box looking untouched. */
    function countTransitions(box, act) {
      const hasOn = (cls) => /(^|\s)on(\s|$)/.test(cls || '');
      const mo = new MutationObserver(() => {});
      mo.observe(box, { attributes: true, attributeFilter: ['class'], attributeOldValue: true });

      const before = box.classList.contains('on');
      act();
      const recs = mo.takeRecords();
      mo.disconnect();
      const after = box.classList.contains('on');

      const states = recs.map((r) => hasOn(r.oldValue));
      states.push(after);

      let n = 0;
      for (let i = 1; i < states.length; i++) {
        if (states[i] !== states[i - 1]) n++;
      }
      return { n, before, after, writes: recs.length };
    }

    /* A box with no handler of its own proves nothing: it cannot double fire
       and it cannot single fire either. Only the wired ones are candidates. */
    const labels = Array.from(document.querySelectorAll('#content label'))
      .filter((l) => l.querySelectorAll('.cbx').length === 1)
      .filter((l) => typeof l.querySelector('.cbx').onclick === 'function')
      .filter((l) => l.textContent.trim().length > 1);
    if (!labels.length) return { found: false };

    const label = labels[0];
    const box = label.querySelector('.cbx');

    // Click the WORDS: the last text node in the label, not the box.
    const words = Array.from(label.childNodes)
      .filter((n) => n.nodeType === 3 && n.textContent.trim())
      .pop();

    const clickWords = () => {
      const r = words
        ? (() => { const rg = document.createRange(); rg.selectNodeContents(words); return rg.getBoundingClientRect(); })()
        : label.getBoundingClientRect();
      const e = new MouseEvent('click', { bubbles: true, cancelable: true, clientX: r.left + 2, clientY: r.top + 2 });
      (words ? label : label).dispatchEvent(e);
    };

    return {
      found: true,
      className: label.className,
      text: label.textContent.trim().slice(0, 40),
      wordsClick: countTransitions(box, clickWords),
      boxClick: countTransitions(box, () => box.click()),
    };
  });

  out.labels.label = labelShape;
  await ctx2.close();
} catch (e) {
  fail(e && e.message ? e.message : e);
} finally {
  if (browser) await browser.close().catch(() => {});
}

process.stdout.write(JSON.stringify(out));
