/*
 * Lane U1 — Content → Shoppable video → All clips → step 2, measured in a real
 * browser, before and after the upload-limit fix.
 *
 * IT REPORTS, IT DOES NOT JUDGE, following tests/browser/lane-v4-add-clip.mjs.
 * Every claim this lane makes about the screen is a claim about rendered output
 * on a server whose PHP really does stop at 2M, and no amount of server output
 * settles what the owner is shown.
 *
 * MEASURING HERE IS NOT WHAT RULE 4 FORBIDS. Rule 4 is about shipped JavaScript
 * sizing the page; this file is the instrument that proves the shipped CSS did.
 * UgcRailR3Test and lane-v4-add-clip.mjs both draw the same line.
 *
 * It is written to run UNCHANGED against either revision so the two runs are
 * comparable: the amber note, the Cancel button and the clock are all looked for
 * and reported absent rather than awaited.
 *
 * THE FIXTURES ARE THREE REAL MP4 HEADERS at three deliberate sizes, and the
 * middle two are chosen against THIS box's ini rather than picked round:
 *
 *   under-limit.mp4     1,992,294 B  under upload_max_filesize (2M): it uploads
 *   over-per-file.mp4   3,145,728 B  over 2M, under post_max_size (8M): mode A
 *   owners-8.4mb.mp4    8,808,038 B  the owner's own file's size: mode B
 *
 * Build them with a genuine ISO base media header, never UploadedFile::fake():
 *
 *   php -r '$h = pack("N",32)."ftyp"."isom".pack("N",512)."isomiso2avc1mp41";
 *           file_put_contents("under-limit.mp4", $h.str_repeat("\0", 1992294 - strlen($h)));'
 *
 *   KBB_U1_BASE=http://127.0.0.1:8971 \
 *   KBB_U1_EMAIL=owner@example.com KBB_U1_PASSWORD=secret-secret \
 *   KBB_U1_FIXTURES=/path/to/fixtures KBB_U1_OUT=/tmp/shots KBB_U1_TAG=after \
 *   KBB_U1_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-u1-upload-limits.mjs
 *
 * Serve the app the way docs/GJ-POSTS-AND-VERDICT.md §11 does — KBB_PUBLIC_PATH
 * at public-web-root, SESSION_DRIVER=file, `php -S 127.0.0.1:8971 -t
 * public-web-root public-web-root/index.php`. Note that using index.php as the
 * router means static files are NOT served, so a <video src> pointing at an
 * upload 404s in the console: that is the harness, not the screen.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.KBB_U1_BASE || 'http://127.0.0.1:8971';
const EMAIL = process.env.KBB_U1_EMAIL || 'owner@example.com';
const PASSWORD = process.env.KBB_U1_PASSWORD || 'secret-secret';
const FIXTURES = process.env.KBB_U1_FIXTURES || '/tmp';
const OUT = process.env.KBB_U1_OUT || '/tmp';
const TAG = process.env.KBB_U1_TAG || 'run';

fs.mkdirSync(OUT, { recursive: true });

const report = { tag: TAG, base: BASE, widths: [], console: [], error: null };
/* The bundled headless shell is not always the version this playwright expects,
   so the system Chromium is used when KBB_U1_CHROME names one. lane-v4's harness
   takes the same escape hatch for the same reason. */
const CHROME = process.env.KBB_U1_CHROME;
const browser = await chromium.launch(
  CHROME
    ? { executablePath: CHROME, args: ['--no-sandbox'] }
    : { args: ['--no-sandbox'] },
);

async function login(page) {
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  if (await page.locator('input[type=password]').count()) {
    await page.locator('input[type=email], input[name=email]').first().fill(EMAIL);
    await page.locator('input[type=password]').first().fill(PASSWORD);
    await page.locator('button[type=submit], input[type=submit]').first().click();
    await page.waitForLoadState('networkidle');
  }
}

/* ?go=ugcvideo lands on the Dashboard — app.blade.php's deep-link boot only
   routes ids in its own TITLES map. lane-v4-add-clip.mjs records that; this calls
   the screen's own entry point, which is what the tab strip calls. */
async function openStepTwo(page) {
  await page.evaluate(() => window.go('ugcvideo'));
  await page.waitForTimeout(1800);
  const tile = page.locator('[data-ugs-open]').first();
  if (await tile.count()) { await tile.click(); await page.waitForTimeout(1200); }
  const step = page.locator('[data-ugs-step="2"]').first();
  if (await step.count()) { await step.click(); await page.waitForTimeout(900); }
}

/* Everything this lane quotes a number for. */
async function measure(page) {
  return page.evaluate(() => {
    const scroller = document.querySelector('#content');
    const box = (sel) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height) };
    };
    const px = (sel, prop) => {
      const el = document.querySelector(sel);
      return el ? window.getComputedStyle(el)[prop] : null;
    };
    const textOf = (sel) => {
      const el = document.querySelector(sel);
      return el ? (el.innerText || '').replace(/\s+/g, ' ').trim() : null;
    };

    /* The sub-line under the video drop zone: the sentence that used to say
       "up to 64 MB" on a server that takes 2M. */
    const zones = Array.from(document.querySelectorAll('.ugs-drop'));
    const videoZone = zones.find((z) => z.getAttribute('data-ugs-drop') === 'clip');

    /* The amber note, identified by what it says rather than by a class it
       shares with four other notes. */
    const notes = Array.from(document.querySelectorAll('.ugs-note'));
    const capNote = notes.find((nn) => /upload_max_filesize/.test(nn.innerText || ''));

    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      contentScrollWidth: scroller ? scroller.scrollWidth : null,
      contentClientWidth: scroller ? scroller.clientWidth : null,
      videoZoneSub: videoZone
        ? (videoZone.querySelector('.ugs-drops')?.innerText || '').replace(/\s+/g, ' ').trim()
        : null,
      capNotePresent: !!capNote,
      capNoteText: capNote ? (capNote.innerText || '').replace(/\s+/g, ' ').trim() : null,
      capNoteBox: capNote
        ? { w: Math.round(capNote.getBoundingClientRect().width),
            h: Math.round(capNote.getBoundingClientRect().height) }
        : null,
      capNoteFontSize: capNote ? window.getComputedStyle(capNote).fontSize : null,
      uploadPanel: box('.ugs-up'),
      uploadPanelClasses: document.querySelector('.ugs-up')?.className || null,
      uploadPanelText: textOf('.ugs-up'),
      barWidth: px('.ugs-progb', 'width'),
      pct: textOf('[data-ugs-pct]'),
      sent: textOf('[data-ugs-sent]'),
      stage: textOf('[data-ugs-stage]'),
      cancelPresent: !!document.querySelector('[data-ugs-upcancel]'),
      retryPresent: !!document.querySelector('[data-ugs-upretry]'),
      dropZones: zones.length,
      fileInputs: document.querySelectorAll('input[type=file]').length,
      brokenImages: Array.prototype.filter.call(
        document.images, (i) => i.complete && i.naturalWidth === 0,
      ).length,
    };
  });
}

async function shoot(page, name) {
  const file = path.join(OUT, `${name}.png`);
  await page.screenshot({ path: file });
  return file;
}

try {
  for (const width of [1280, 390]) {
    const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
    const page = await context.newPage();
    page.on('console', (m) => { if (m.type() === 'error') report.console.push(`${width}: ${m.text()}`); });
    page.on('pageerror', (e) => report.console.push(`${width}: pageerror ${e.message}`));

    const block = { width, shots: [], steps: {} };

    await login(page);
    await openStepTwo(page);

    /* 1. The step as it sits, with whatever it says about the limit. */
    block.steps.resting = await measure(page);
    block.shots.push(await shoot(page, `${TAG}-${width}-1-step2-resting`));

    /* 2. The owner's own file size, refused. On the AFTER revision the
          pre-flight stops it here and says why; on BEFORE it goes up and comes
          back as "that file was not accepted". */
    const input = page.locator('input[type=file][data-ugs-upload="clip"]').first();
    if (await input.count()) {
      await input.setInputFiles(path.join(FIXTURES, 'owners-8.4mb.mp4'));
      await page.waitForTimeout(4000);
      block.steps.owners84 = await measure(page);
      block.shots.push(await shoot(page, `${TAG}-${width}-2-refused-8.4mb`));
    }

    /* 3. A 3 MB file: over upload_max_filesize, under post_max_size. */
    const input2 = page.locator('input[type=file][data-ugs-upload="clip"]').first();
    if (await input2.count()) {
      await input2.setInputFiles(path.join(FIXTURES, 'over-per-file.mp4'));
      await page.waitForTimeout(4000);
      block.steps.overPerFile = await measure(page);
      block.shots.push(await shoot(page, `${TAG}-${width}-3-refused-3mb`));
    }

    /*
     * 4. AN UPLOAD IN FLIGHT, THROTTLED, so the bar, the clock and Cancel can be
     *    seen at all. 1.9 MB at 60 KB/s is about half a minute, which is also
     *    long enough to cross STALL_AFTER and change the wording.
     */
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.emulateNetworkConditions', {
      offline: false, latency: 60,
      downloadThroughput: 4 * 1024 * 1024, uploadThroughput: 60 * 1024,
    });

    const input3 = page.locator('input[type=file][data-ugs-upload="clip"]').first();
    if (await input3.count()) {
      await input3.setInputFiles(path.join(FIXTURES, 'under-limit.mp4'));
      await page.waitForTimeout(6000);
      block.steps.inFlight = await measure(page);
      block.shots.push(await shoot(page, `${TAG}-${width}-4-in-flight`));

      /* Past STALL_AFTER, where the panel starts saying how long it has been. */
      await page.waitForTimeout(17000);
      block.steps.slow = await measure(page);
      block.shots.push(await shoot(page, `${TAG}-${width}-5-slow`));

      /* 5. Cancel, and the Try again it leaves behind. */
      const cancel = page.locator('[data-ugs-upcancel]').first();
      if (await cancel.count()) {
        await cancel.click();
        await page.waitForTimeout(1500);
        block.steps.cancelled = await measure(page);
        block.shots.push(await shoot(page, `${TAG}-${width}-6-cancelled`));
      }
    }

    await cdp.send('Network.emulateNetworkConditions', {
      offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1,
    });

    /* 6. A file the server really takes, arriving whole. */
    const input4 = page.locator('input[type=file][data-ugs-upload="clip"]').first();
    if (await input4.count()) {
      await input4.setInputFiles(path.join(FIXTURES, 'under-limit.mp4'));
      await page.waitForTimeout(6000);
      block.steps.accepted = await measure(page);
      block.shots.push(await shoot(page, `${TAG}-${width}-7-accepted`));
    }

    report.widths.push(block);
    await context.close();
  }
} catch (e) {
  report.error = `${e.message}`;
}

await browser.close();
fs.writeFileSync(path.join(OUT, `${TAG}-report.json`), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
