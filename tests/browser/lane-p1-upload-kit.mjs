/*
 * Lane P1 — the shared upload kit, measured in a real browser against a real
 * throttled upload.
 *
 * Content → Shoppable video → Sections → (a section) → (a clip) → Files: the
 * screen the owner photographed, where the bar appeared to stick at 72%.
 *
 * IT REPORTS, IT DOES NOT JUDGE, the same way tests/browser/lane-v5-editor-cols.mjs
 * and lane-u1-upload-limits.mjs do. Every claim this lane makes about the panel
 * is a claim about a request in flight and about rendered layout, and the
 * server's output cannot settle any of them:
 *
 *   - whether the bar's percentage is the upload's own;
 *   - whether the sentence beside it carries a SPEED and a TIME REMAINING, which
 *     is the whole of the owner's complaint — a bar that cannot tell him whether
 *     it is slow or stuck;
 *   - whether the two clocks really are separate, which one screenshot caught
 *     Lane U1 getting wrong ("1s so far" on a 23-second upload);
 *   - whether both endings STAY on screen;
 *   - whether a dragged file lands, a wrong one is refused out loud, and a drag
 *     that is not a file leaves the zone alone;
 *   - and whether anything overflows sideways at 390px.
 *
 * MEASURING HERE IS NOT WHAT RULE 4 FORBIDS. Rule 4 is about SHIPPED JavaScript
 * sizing the page. This file is the instrument that proves the shipped CSS did it
 * without script — which is why UploadKitTest greps the kit for every
 * element-measuring API by name, and why the only reads below are in this file.
 *
 *   KBB_P1_BASE=http://127.0.0.1:8954 \
 *   KBB_P1_EMAIL=p1@example.test KBB_P1_PASSWORD=lane-p1-password \
 *   KBB_P1_CLIP=/tmp/anua-mist-spray-2.mp4 \
 *   KBB_P1_OUT=docs/upload-kit-shots KBB_P1_TAG=after \
 *   KBB_P1_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-p1-upload-kit.mjs
 *
 * KBB_P1_UPLOAD_KBPS throttles the upload through CDP so the bar can be sampled
 * in flight. It defaults to 300, which is the rate the owner's own panel implies
 * (6.0 MB in 20 s) and the rate every measurement in the kit's docblock was taken
 * at. Without a throttle an 8.4 MB body over loopback is gone before the first
 * sample and the bar's whole life is one event.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.KBB_P1_BASE || 'http://127.0.0.1:8954';
const EMAIL = process.env.KBB_P1_EMAIL || 'p1@example.test';
const PASSWORD = process.env.KBB_P1_PASSWORD || 'lane-p1-password';
const OUT = process.env.KBB_P1_OUT || '/tmp';
const TAG = process.env.KBB_P1_TAG || 'run';
const CLIP = process.env.KBB_P1_CLIP || '';
const CHROME = process.env.KBB_P1_CHROME;
const UPLOAD_KBPS = Number(process.env.KBB_P1_UPLOAD_KBPS || 300);
const WIDTHS = (process.env.KBB_P1_WIDTHS || '390,1280').split(',').map(Number);

fs.mkdirSync(OUT, { recursive: true });

const report = {
  tag: TAG, base: BASE, clip: CLIP, uploadKbps: UPLOAD_KBPS,
  widths: [], console: [], error: null,
};

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

/* ── the two numbers rule 2 asks for, plus the panel's own text ─────────── */
async function measure(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const panel = document.querySelector('.ugx-up');
    const zones = Array.from(document.querySelectorAll('[data-ugx-zone]'));

    const box = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height) };
    };

    return {
      scrollWidth: doc.scrollWidth,
      clientWidth: doc.clientWidth,
      /* The one assertion that matters at 390px: nothing may be wider than the
         viewport. A positive difference is a sideways scrollbar. */
      overflowsSideways: doc.scrollWidth > doc.clientWidth,
      zones: zones.map((z) => ({
        kind: z.getAttribute('data-ugx-zone'),
        accept: z.getAttribute('data-ugx-accept'),
        isZone: z.classList.contains('kbbu-zone'),
        isOver: z.classList.contains('is-over'),
        isBad: z.classList.contains('is-bad'),
        /* The resolved tracks the STYLESHEET produced, not anything the screen
           computed — the same reading lane-v5 takes. */
        columns: getComputedStyle(z).gridTemplateColumns,
        hintFontPx: (() => {
          const h = z.querySelector('.ugx-drophint');
          return h ? getComputedStyle(h).fontSize : null;
        })(),
        hint: (z.querySelector('.ugx-drophint') || {}).textContent || null,
        meta: ((z.querySelector('.ugx-dropmeta') || {}).textContent || '').trim(),
        msg: ((z.querySelector('[data-kbbu-zmsg]') || {}).textContent || '').trim(),
        box: box(z),
      })),
      panel: panel ? {
        present: true,
        isBad: panel.classList.contains('is-bad'),
        isSlow: panel.classList.contains('is-slow'),
        name: ((panel.querySelector('.ugx-upn') || {}).textContent || '').trim(),
        percent: ((panel.querySelector('[data-ugx-pct]') || {}).textContent || '').trim() || null,
        sent: ((panel.querySelector('[data-ugx-sent]') || {}).textContent || '').trim() || null,
        stage: ((panel.querySelector('[data-ugx-stage]') || {}).textContent || '').trim() || null,
        barWidthStyle: (panel.querySelector('.ugx-progb') || {}).style?.width || null,
        barBox: box(panel.querySelector('.ugx-progb')),
        hasCancel: !!panel.querySelector('[data-ugx-upcancel]'),
        hasRetry: !!panel.querySelector('[data-ugx-upretry]'),
        text: panel.textContent.replace(/\s+/g, ' ').trim(),
        box: box(panel),
      } : { present: false },
    };
  });
}

async function shot(page, name, width) {
  const file = path.join(OUT, `${TAG}-${width}-${name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  return file;
}

/* Click the first visible element whose text matches. */
async function clickAny(page, rx) {
  const els = page.locator('button, a[role=button], label, [role=button]');
  const n = await els.count();
  for (let i = 0; i < n; i += 1) {
    const b = els.nth(i);
    if (!(await b.isVisible().catch(() => false))) continue;
    const text = ((await b.innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
    if (rx.test(text)) { await b.click(); return text; }
  }
  return null;
}

/*
 * A SYNTHETIC DROP, because Playwright cannot drag a file off the desktop.
 *
 * The DataTransfer is built in the page and a real `drop` event is dispatched
 * with it, so the kit's own handler runs against a real event with real
 * dataTransfer.files — which is the thing under test. `types` is set by the
 * DataTransfer itself when a file is added, so carriesFiles() sees exactly what
 * it would see from a mouse.
 */
async function dropFile(page, selector, name, type, bytes) {
  return page.evaluate(async ([sel, fname, ftype, size]) => {
    const el = document.querySelector(sel);
    if (!el) return { ok: false, why: 'no element' };
    const dt = new DataTransfer();
    dt.items.add(new File([new Uint8Array(size)], fname, { type: ftype }));
    const seenTypes = Array.from(dt.types);
    el.dispatchEvent(new DragEvent('dragenter', { dataTransfer: dt, bubbles: true, cancelable: true }));
    el.dispatchEvent(new DragEvent('dragover', { dataTransfer: dt, bubbles: true, cancelable: true }));
    const overAfterDragover = el.classList.contains('is-over');
    el.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
    return { ok: true, seenTypes, overAfterDragover };
  }, [selector, name, type, bytes]);
}

/* A drag that carries TEXT and not files — the product-reorder drag. The zone
   must not light up for it. */
async function dragText(page, selector) {
  return page.evaluate(async ([sel]) => {
    const el = document.querySelector(sel);
    if (!el) return { ok: false };
    const dt = new DataTransfer();
    dt.setData('text/plain', '2');
    el.dispatchEvent(new DragEvent('dragenter', { dataTransfer: dt, bubbles: true, cancelable: true }));
    el.dispatchEvent(new DragEvent('dragover', { dataTransfer: dt, bubbles: true, cancelable: true }));
    return { ok: true, seenTypes: Array.from(dt.types), isOver: el.classList.contains('is-over') };
  }, [selector]);
}

try {
  for (const width of WIDTHS) {
    const block = { width, steps: {}, uploadSamples: [], drops: {}, shots: [] };
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();

    page.on('console', (m) => {
      if (m.type() === 'error') report.console.push({ width, text: m.text() });
    });
    page.on('pageerror', (e) => report.console.push({ width, pageerror: String(e) }));

    /* ── in ─────────────────────────────────────────────────────────────── */
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[type=email], input[name=email]', EMAIL);
    await page.fill('input[type=password], input[name=password]', PASSWORD);
    await Promise.all([
      page.waitForLoadState('networkidle').catch(() => {}),
      clickAny(page, /sign in|log in|login/i),
    ]);
    await page.waitForTimeout(1200);

    /* ── the screen he photographed ─────────────────────────────────────── */
    await page.goto(`${BASE}/admin?go=ugcsections`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2200);
    block.steps.sections = await measure(page);
    block.shots.push(await shot(page, 'sections', width));

    /* Open the first section, then the first clip inside it. The section's own
       control is "Open" — the title is a heading and not a button. */
    block.steps.openedSection = await page.locator('[data-ugx-open]').first().click().then(() => 'Open').catch(() => null);
    await page.waitForTimeout(1800);
    block.shots.push(await shot(page, 'section-open', width));

    /* The clip row inside the section. Its control carries data-ugx-vopen; fall
       back to clicking the row by name where the markup differs. */
    const clipBtn = page.locator('[data-ugx-edit]');
    if (await clipBtn.count()) {
      await clipBtn.first().click();
    } else {
      block.steps.openedClip = await clickAny(page, /anua mist spray|edit|open/i);
    }
    await page.waitForTimeout(1800);
    block.shots.push(await shot(page, 'clip-open', width));

    /* ── the Files tab ──────────────────────────────────────────────────── */
    const filesTab = page.locator('[data-ugx-mtab="files"]');
    if (await filesTab.count()) {
      await filesTab.first().click();
      await page.waitForTimeout(900);
    }
    block.steps.filesTab = await measure(page);
    block.shots.push(await shot(page, 'files-tab', width));

    /* ── the drop zone, three ways ──────────────────────────────────────── */

    /* 1. a drag that is NOT a file must leave the zone alone. */
    block.drops.textDrag = await dragText(page, '[data-ugx-zone="clip"]');
    block.drops.textDragState = (await measure(page)).zones.find((z) => z.kind === 'clip');

    /* 2. a wrong type must be REFUSED OUT LOUD rather than swallowed. */
    block.drops.wrongType = await dropFile(
      page, '[data-ugx-zone="clip"]', 'notes.pdf', 'application/pdf', 2048,
    );
    await page.waitForTimeout(500);
    block.drops.wrongTypeState = (await measure(page)).zones.find((z) => z.kind === 'clip');
    block.shots.push(await shot(page, 'drop-refused', width));

    /* 3. the poster zone must accept a picture and refuse a video. */
    block.drops.posterWrong = await dropFile(
      page, '[data-ugx-zone="poster"]', 'clip.mp4', 'video/mp4', 2048,
    );
    await page.waitForTimeout(400);
    block.drops.posterWrongState = (await measure(page)).zones.find((z) => z.kind === 'poster');

    /* ── a file OVER this server's ceiling: refused before a byte is sent ── */
    const TOOBIG = process.env.KBB_P1_TOOBIG || '';
    if (TOOBIG && fs.existsSync(TOOBIG)) {
      await page.setInputFiles('[data-ugx-upload="clip"]', TOOBIG);
      await page.waitForTimeout(1200);
      const m = await measure(page);
      block.overCeiling = {
        panel: m.panel,
        /* The whole point: NO Try again, because the same file over the same
           ceiling fails identically and the button could only ever lie. */
        offeredRetry: m.panel.present ? m.panel.hasRetry : null,
      };
      block.shots.push(await shot(page, 'over-ceiling', width));
    }

    /* ── the real upload, throttled so the bar has a life ───────────────── */
    if (CLIP && fs.existsSync(CLIP)) {
      const cdp = await ctx.newCDPSession(page);
      await cdp.send('Network.enable');
      await cdp.send('Network.emulateNetworkConditions', {
        offline: false, latency: 20, downloadThroughput: -1,
        uploadThroughput: UPLOAD_KBPS * 1024,
      });

      /* Through the CLICK path — the file input inside the label — because that
         is the path that already worked and must not have been broken. The drop
         path is exercised above. */
      await page.setInputFiles('[data-ugx-upload="clip"]', CLIP);

      const t0 = Date.now();
      let sawSending = false, sawServer = false, lastPct = null;

      /* 250 ms, not 700: the server stage on a fast box can be shorter than one
         sample, and it is the stage whose wording this lane rewrote. */
      for (let i = 0; i < 240; i += 1) {
        await page.waitForTimeout(250);
        const m = await measure(page);
        const p = m.panel;
        if (!p.present) break;

        block.uploadSamples.push({
          at: Number(((Date.now() - t0) / 1000).toFixed(1)),
          percent: p.percent, sent: p.sent, stage: p.stage,
          barWidthStyle: p.barWidthStyle, isSlow: p.isSlow,
          hasCancel: p.hasCancel, inFlight: !!p.percent,
        });

        if (p.stage && /Sending to the server/.test(p.stage)) sawSending = true;
        if (p.stage && /left your browser/.test(p.stage)) sawServer = true;

        /* One picture while it is genuinely mid-flight, and one at the handover. */
        if (p.percent && !sawServer && i === 4) {
          block.shots.push(await shot(page, 'upload-in-flight', width));
        }
        if (sawServer && !block.shots.some((s) => s.includes('upload-server'))) {
          block.shots.push(await shot(page, 'upload-server-stage', width));
        }

        lastPct = p.percent;
        if (!p.percent && !p.hasCancel) break;   // an ending is drawn
      }

      block.uploadSawSendingStage = sawSending;
      block.uploadSawServerStage = sawServer;
      block.uploadLastPercent = lastPct;

      await cdp.send('Network.emulateNetworkConditions', {
        offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1,
      });

      await page.waitForTimeout(2600);
      block.steps.afterUpload = await measure(page);
      block.shots.push(await shot(page, 'after-upload', width));
    }

    report.widths.push(block);
    await ctx.close();
  }
} catch (e) {
  report.error = String(e && e.stack ? e.stack : e);
}

await browser.close();

const file = path.join(OUT, 'measurements.json');
let all = {};
if (fs.existsSync(file)) { try { all = JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { all = {}; } }
all[TAG] = report;
fs.writeFileSync(file, JSON.stringify(all, null, 2));

console.log(JSON.stringify(report, null, 2));
