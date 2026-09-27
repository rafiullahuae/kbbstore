/* Lane M1 — the two screens, at 390 and 1280, with the numbers that matter. */
const { chromium } = require('playwright');
const BASE = process.env.M1_BASE || 'http://127.0.0.1:8963';
const OUT = process.env.M1_OUT;

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = {};

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.error('PAGEERROR', String(e).slice(0, 300)));
    page.on('requestfailed', r => console.error('REQFAILED', r.method(), r.url().slice(0, 120), r.failure()?.errorText));
    page.on('response', async r => {
      if (r.url().includes('/upload')) {
        let body = ''; try { body = (await r.text()).slice(0, 400); } catch (e) { body = '(unreadable)'; }
        console.error('UPLOAD RESPONSE', r.status(), body);
      }
    });

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type=submit], input[type=submit]'),
    ]);

    /* ── 1. the section editor, where the owner drew the box ── */
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    await page.evaluate(() => window.go('ugcsections'));
    await page.waitForTimeout(1500);
    // Open the section.
    await page.waitForSelector('[data-ugx-open]', { timeout: 15000 });
    await page.click('[data-ugx-open]');
    await page.waitForSelector('.ugx-newup', { timeout: 15000 });
    await page.waitForTimeout(900);

    results['section_' + width] = await page.evaluate(() => {
      const px = (el, p) => el ? Math.round(parseFloat(getComputedStyle(el)[p])) : null;
      const r = el => el ? { w: Math.round(el.getBoundingClientRect().width), h: Math.round(el.getBoundingClientRect().height), top: Math.round(el.getBoundingClientRect().top) } : null;
      const up = document.querySelector('.ugx-newup');
      const head = document.querySelector('.ugx-ehtop');
      const title = document.querySelector('.ugx-ehtop .ugx-ehtitle .ugx-title');
      const input = document.querySelector('[data-ugx-newupload]');
      return {
        viewport: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        controls: document.querySelectorAll('.ugx-newup').length,
        controlBox: r(up),
        headBox: r(head),
        titleBox: r(title),
        controlRightEdge: up ? Math.round(up.getBoundingClientRect().right) : null,
        headRightEdge: head ? Math.round(head.getBoundingClientRect().right) : null,
        wrapped: (up && title) ? up.getBoundingClientRect().top > title.getBoundingClientRect().top : null,
        nameFontSize: px(document.querySelector('.ugx-newupname'), 'fontSize'),
        metaFontSize: px(document.querySelector('.ugx-newupmeta'), 'fontSize'),
        accept: input ? input.getAttribute('accept') : null,
        inputVisiblyHidden: input ? getComputedStyle(input).opacity : null,
      };
    });

    await page.screenshot({ path: `${OUT}/section-${width}.png`, fullPage: true });

    /* Scrolled to the card the owner drew on, so the control is the subject
       rather than something a reader has to hunt for in a full-page shot. */
    await page.evaluate(() => document.querySelector('.ugx-newup')
      .scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/section-card-${width}.png` });

    /* ── 1b. THE UPLOAD, IN FLIGHT. The POST is held open by a route handler
       so the bar, the percentage, the two-stage sentence and Cancel are all on
       screen when the shot is taken — the "live progress" half of the ask. */
    /*
     * THROTTLED THROUGH CDP, NOT HELD BY A ROUTE HANDLER.
     *
     * The obvious trick — page.route() on the upload URL and continue() after a
     * delay — does not work for this request: playwright re-issues a continued
     * request from its own stack and the multipart body is dropped, so the POST
     * never reaches the server at all and the panel waits forever. Measured:
     * zero POSTs in the preview's access log.
     *
     * Network.emulateNetworkConditions throttles the REAL request instead, which
     * is both what works and what the shot should show: 200 KB/s upstream is a
     * bad mobile uplink, and a bar that only ever ran at LAN speed would never
     * show the two stages or the speed at all.
     */
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.emulateNetworkConditions', {
      offline: false, latency: 40,
      downloadThroughput: 2 * 1024 * 1024,
      uploadThroughput: 200 * 1024,
    });

    const fs = require('fs');
    const tmp = `${OUT}/anua mist spray 2.mp4`;
    const tooBig = `${OUT}/two-minute-original.mp4`;
    // A genuine ISO base media header, then padding: the server reads the bytes,
    // not the name. 3 MB so the bar has something to travel across.
    const head = Buffer.concat([
      Buffer.from([0,0,0,32]), Buffer.from('ftyp'), Buffer.from('isom'),
      Buffer.from([0,0,2,0]), Buffer.from('isomiso2avc1mp41'),
    ]);
    /* 1.5 MB: under this box's upload_max_filesize (measured at 2M) so it
       really lands, and 7.5 seconds at the 200 KB/s throttled above — long
       enough for the bar, the speed and the two stages to be photographed. */
    fs.writeFileSync(tmp, Buffer.concat([head, Buffer.alloc(1536 * 1024 - head.length)]));
    /* And one the SERVER will refuse, for the refusal shot. */
    fs.writeFileSync(tooBig, Buffer.concat([head, Buffer.alloc(3 * 1024 * 1024)]));

    await page.setInputFiles('[data-ugx-newupload]', tmp);
    await page.waitForSelector('[data-ugx-nupbox]', { timeout: 10000 });
    await page.waitForTimeout(3500);

    results['inflight_' + width] = await page.evaluate(() => {
      const box = document.querySelector('[data-ugx-nupbox]');
      const bar = document.querySelector('[data-ugx-nbar]');
      return {
        scrollWidth: document.documentElement.scrollWidth,
        panelWidth: box ? Math.round(box.getBoundingClientRect().width) : null,
        pct: document.querySelector('[data-ugx-npct]')?.textContent ?? null,
        barWidthStyle: bar ? bar.style.width : null,
        barPixels: bar ? Math.round(bar.getBoundingClientRect().width) : null,
        sent: document.querySelector('[data-ugx-nsent]')?.textContent ?? null,
        stage: document.querySelector('[data-ugx-nstage]')?.textContent ?? null,
        cancel: !!document.querySelector('[data-ugx-nupcancel]'),
      };
    });

    await page.evaluate(() => document.querySelector('[data-ugx-nupbox]')
      .scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/upload-inflight-${width}.png` });

    /* Back to full speed for the rest of the walk. */
    await cdp.send('Network.emulateNetworkConditions', {
      offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1,
    });

    /* ── 1c. AND WHAT ARRIVED: the preview, the draft notice, the blockers. */
    try {
      await page.waitForSelector('.ugx-newpv video', { timeout: 30000 });
    } catch (e) {
      console.error('PANEL STATE', await page.evaluate(() => (document.querySelector('.ugx-up') || {}).innerText || '(no panel)'));
      throw e;
    }
    await page.waitForTimeout(1200);

    results['arrived_' + width] = await page.evaluate(() => {
      const pv = document.querySelector('.ugx-newpv');
      const v = pv ? pv.querySelector('video') : null;
      const note = document.querySelector('.ugx-up .ugx-note');
      const rows = [...document.querySelectorAll('.ugx-vid .ugx-rowname')].map(n => n.textContent);
      return {
        scrollWidth: document.documentElement.scrollWidth,
        previewBox: pv ? { w: Math.round(pv.getBoundingClientRect().width), h: Math.round(pv.getBoundingClientRect().height) } : null,
        previewSrc: v ? v.getAttribute('src') : null,
        previewPreload: v ? v.getAttribute('preload') : null,
        heading: document.querySelector('.ugx-up .ugx-upn')?.textContent ?? null,
        result: document.querySelector('.ugx-up .ugx-uph span:last-child')?.textContent ?? null,
        draftNotice: note ? note.innerText.replace(/\n/g, ' | ') : null,
        clipsInSection: rows,
      };
    });

    await page.evaluate(() => document.querySelector('.ugx-newpv')
      .scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/upload-arrived-${width}.png` });
    await page.screenshot({ path: `${OUT}/section-after-${width}.png`, fullPage: true });

    /* ── 1d. AND THE HONEST REFUSAL, which is the other ending that matters.
       This box's upload_max_filesize is 2M, so a 3 MB file is thrown away by
       PHP before the shop reads a byte — the exact defect App\Support\
       UploadArrival was written for. The panel has to name the server's own
       limit rather than blame the file. */
    await page.setInputFiles('[data-ugx-newupload]', tooBig);
    await page.waitForSelector('.ugx-up.is-bad', { timeout: 20000 });
    await page.waitForTimeout(600);

    results['refusal_' + width] = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      heading: document.querySelector('.ugx-up.is-bad .ugx-upn')?.textContent ?? null,
      verdict: document.querySelector('.ugx-up.is-bad .ugx-uph span:last-child')?.textContent ?? null,
      message: document.querySelector('.ugx-up.is-bad .ugx-upm span')?.textContent ?? null,
      retryOffered: !!document.querySelector('[data-ugx-nupretry]'),
    }));

    await page.evaluate(() => document.querySelector('.ugx-up.is-bad')
      .scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/upload-refused-${width}.png` });

    /* ── 2. the media library, now holding videos ── */
    await page.evaluate(() => window.go('media'));
    await page.waitForTimeout(2000);

    results['library_' + width] = await page.evaluate(() => {
      const px = (el, p) => el ? Math.round(parseFloat(getComputedStyle(el)[p])) : null;
      const tiles = [...document.querySelectorAll('.mlib-tile')];
      const film = document.querySelector('.mlib-film');
      const img = document.querySelector('.mlib-thumb img');
      return {
        viewport: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        tiles: tiles.length,
        videoTiles: document.querySelectorAll('.mlib-film').length,
        imageTiles: document.querySelectorAll('.mlib-thumb img').length,
        brokenImages: [...document.querySelectorAll('.mlib-thumb img')]
          .filter(i => i.complete && i.naturalWidth === 0).length,
        videoPills: [...document.querySelectorAll('.mlib-pill.is-video')].map(p => p.textContent),
        filmBox: film ? { w: Math.round(film.getBoundingClientRect().width), h: Math.round(film.getBoundingClientRect().height) } : null,
        filmLabel: film ? film.querySelector('b')?.textContent : null,
        thumbBox: img ? { w: Math.round(img.getBoundingClientRect().width), h: Math.round(img.getBoundingClientRect().height) } : null,
        kindSelect: document.querySelector('#mlib-kind') ? [...document.querySelectorAll('#mlib-kind option')].map(o => o.value + ':' + o.textContent) : null,
        tileTitleFontSize: px(document.querySelector('.mlib-name'), 'fontSize'),
      };
    });

    await page.screenshot({ path: `${OUT}/library-${width}.png`, fullPage: true });

    /* Open the More filters panel so the new Show control is visible in the shot. */
    await page.evaluate(() => { const b = document.getElementById('mlib-more'); if (b) b.click(); });
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${OUT}/library-filters-${width}.png`, fullPage: true });

    /* And the detail panel on a video, where the player lives. */
    await page.evaluate(() => {
      const t = [...document.querySelectorAll('.mlib-tile')].find(x => x.querySelector('.mlib-film'));
      if (t) t.click();
    });
    await page.waitForTimeout(1200);
    results['detail_' + width] = await page.evaluate(() => {
      const v = document.querySelector('.mlib-preview video');
      return {
        hasPlayer: !!v,
        playerSrc: v ? v.getAttribute('src') : null,
        playerControls: v ? v.hasAttribute('controls') : null,
        playerPreload: v ? v.getAttribute('preload') : null,
        playerBox: v ? { w: Math.round(v.getBoundingClientRect().width), h: Math.round(v.getBoundingClientRect().height) } : null,
        scrollWidth: document.documentElement.scrollWidth,
      };
    });
    await page.screenshot({ path: `${OUT}/library-detail-${width}.png`, fullPage: true });

    await ctx.close();
  }

  console.log(JSON.stringify(results, null, 1));
  await browser.close();
})();
