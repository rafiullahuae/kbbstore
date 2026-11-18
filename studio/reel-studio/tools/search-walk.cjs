// KBB Studio — the screens and tab states the ui-audit visits (tools/ui-audit.cjs), as one walk the search check can
// replay: walk(page, url, wsId, clip, onState(name)) opens each state in turn and calls onState once it has settled.
// Kept in step with ui-audit.cjs by hand; a state ui-audit gains belongs here too, so search coverage follows it.
'use strict';

async function walk(page, url, wsId, clip, onState) {
  const closeModal = async () => { for (let i = 0; i < 3 && await page.$('.modal-back'); i++) { await page.keyboard.press('Escape'); await page.waitForTimeout(150); } };
  const step = async (name, fn) => { try { await fn(); } catch (err) { await onState('ERROR:' + name + ': ' + String(err.message).split('\n')[0], true); } };

  await page.goto(url + '/app/');
  await page.waitForSelector('#su-later, .home', { timeout: 30000 });
  if (await page.$('#su-later')) { await onState('setup-screen'); await page.click('#su-later'); }
  await page.waitForSelector('.home'); await page.waitForTimeout(500);
  await onState('home');
  await step('company-menu', async () => { await page.click('.home .ws-switch'); await page.waitForSelector('.ws-menu'); await onState('company-menu'); await page.keyboard.press('Escape'); await page.evaluate(() => document.querySelector('.ws-menu')?.remove()); });
  await step('company-editor', async () => {
    await page.evaluate(() => [...document.querySelectorAll('.home button')].find((b) => /Edit company/.test(b.textContent))?.click());
    await page.waitForSelector('.modal .company-form'); await onState('company-editor'); await closeModal();
  });
  await step('models-dialog', async () => {
    await page.evaluate(() => [...document.querySelectorAll('.home button')].find((b) => /Models/.test(b.textContent))?.click());
    await page.waitForSelector('.modal .setup-form'); await onState('models-dialog'); await closeModal();
  });
  await step('configure', async () => {
    await page.click('#configure-btn'); await page.waitForSelector('.cfg-back [data-sec]');
    const secs = await page.$$eval('.cfg-nav [data-sec]', (b) => b.map((x) => x.dataset.sec));
    for (const s of secs) {
      await page.click(`.cfg-nav [data-sec="${s}"]`); await page.waitForTimeout(400); await onState('configure:' + s);
      if (await page.$('.cfg-how')) { await page.$$eval('.cfg-how', (d) => d.forEach((x) => { x.open = true; })); await page.waitForTimeout(150); await onState('configure:' + s + ':how-to'); }
    }
    await page.keyboard.press('Escape'); await page.waitForSelector('.cfg-back', { state: 'detached' });
  });
  await step('designs', async () => {
    const brief = wsId === 'kbb' ? 'National Day: 30% off Anua and BoJ, story and post, luxury' : 'Study in Germany, September intake open — story and post';
    await page.fill('#home-brief', brief); await page.click('#home-designs');
    await page.waitForSelector('.dz-card', { timeout: 60000 }); await page.waitForTimeout(600);
    await onState('designs');
    const tabs = await page.$$eval('.dz-left .dz-tabs [data-t], .dz-col .dz-tabs [data-t]', (b) => [...new Set(b.map((x) => x.dataset.t))]);
    for (const t of tabs) { await page.click(`.dz-tabs [data-t="${t}"]`); await page.waitForTimeout(700); await onState('designs:' + t); }
    await page.click('.dz-card'); await page.waitForSelector('#de-canvas'); await page.waitForTimeout(500);
    await onState('design-editor');
    await page.click('[data-el="headline"]').catch(() => {}); await page.waitForTimeout(300);
    await onState('design-editor:headline');
    await page.click('#de-export'); await page.waitForSelector('#de-x-go'); await onState('design-export'); await closeModal();
    await page.click('#de-back'); await page.waitForTimeout(400);
  });
  await openEditor(page, url, wsId, clip);
  await onState('editor');
  for (const t of await page.$$eval('.bin-tab', (b) => b.map((x, i) => i))) await step('bin:' + t, async () => { await page.click(`.bin-tabs .bin-tab:nth-child(${t + 1})`); await page.waitForTimeout(450); await onState('bin:' + (await page.$eval('.bin-tab.on', (x) => x.textContent.trim()))); });
  await page.click('.bin-tabs .bin-tab:nth-child(1)');
  // the inspector for nothing selected (the project), a text item, two items
  await step('inspector-project', async () => { await page.evaluate(() => { kbbEditor.select([]); kbbEditor.showSide('inspector'); }); await page.waitForTimeout(250); await onState('inspector:project'); });
  await step('inspector-text', async () => {
    await page.evaluate(async () => { const P = await import('/app/core/project.js'); const ed = kbbEditor; const it = P.createItem('text', { t0: 0, in: 0, out: 2, x: 100, y: 300, w: 800, h: 200, text: 'Hello', name: 'Text' }); ed.addItem(it, 'text'); ed.showSide('inspector'); window.__txt = it.id; });
    await page.waitForTimeout(250); await onState('inspector:text');
    await page.evaluate(() => { const ed = kbbEditor; const clip = ed.project.tracks.find((t) => t.kind === 'video').items[0]; ed.select([window.__txt, clip.id]); });
    await page.waitForTimeout(250); await onState('inspector:multi');
    await page.evaluate(() => { kbbEditor.history.undo(); kbbEditor.select([]); });
  });
  await page.click('.tl-item.t-clip').catch(() => {}); await page.waitForTimeout(200);
  const sides = await page.$$eval('.side-tab', (b) => b.map((x) => x.dataset.side));
  for (const s of sides) await step('side:' + s, async () => {
    await page.click(`.side-tab[data-side="${s}"]`); await page.waitForTimeout(s.startsWith('mod:') ? 900 : 500);
    await onState('side:' + s);
    if (s === 'captions') for (const t of await page.$$eval('.rs-cap-tabs button[data-tab]', (b) => b.map((x) => x.dataset.tab))) {
      await page.click(`.rs-cap-tabs button[data-tab="${t}"]`); await page.waitForTimeout(500); await onState('captions:' + t);
    }
  });
  await step('inspector-capsules', async () => {
    await page.evaluate(async () => { const TH = await import('/app/themes/index.js'); const it = TH.placeElement(kbbEditor.context(), 'capsules', { t0: 0 }); kbbEditor.select([it.id]); kbbEditor.showSide('inspector'); });
    await page.waitForSelector('[data-caps-insp] .cps-preset'); await page.waitForTimeout(600);
    await onState('inspector-capsules');
    await page.click('.cps-item .cps-grip'); await page.waitForSelector('[data-caps-sel-box]'); await page.waitForTimeout(200);
    await onState('inspector-capsules-selected');
    await page.click('[data-caps-add-countries]'); await page.waitForSelector('.cps-countries label[data-caps-country]'); await page.waitForTimeout(800); await onState('capsules-country-picker'); await closeModal();
    await page.click('[data-caps-add-jobs]'); await page.waitForSelector('.cps-jobs label'); await page.waitForTimeout(400); await onState('capsules-jobs-picker'); await closeModal();
    await page.click('[data-caps-add-custom="jobs"]'); await page.waitForSelector('.cps-icons button'); await onState('capsules-custom'); await closeModal();
    await page.evaluate(() => { kbbEditor.history.undo(); });
  });
  // a theme element's Text and Text style sections (themes/text-panel.js), with a word's Aa row open
  await step('inspector-theme-text', async () => {
    await page.evaluate(async () => { const TH = await import('/app/themes/index.js'); const it = TH.placeElement(kbbEditor.context(), 'lower', { t0: 0 }); kbbEditor.select([it.id]); kbbEditor.showSide('inspector'); });
    await page.waitForSelector('[data-th-style-sec]'); await page.click('[data-th-aa="name"]'); await page.waitForTimeout(300);
    await onState('inspector-theme-text');
    await page.evaluate(() => { kbbEditor.history.undo(); });
  });
  await page.click('.side-tab[data-side="captions"]');
  await page.click('.rs-cap-tabs button[data-tab="transcript"]').catch(() => {});
  await step('export', async () => { await page.click('.export-btn'); await page.waitForSelector('#x-go'); await page.waitForTimeout(400); await onState('export'); await closeModal(); });
  await step('shortcuts', async () => { await page.click('.topbar button[title^="Keyboard"]'); await page.waitForSelector('.modal'); await onState('shortcuts'); await closeModal(); });
  await step('editor-configure', async () => { await page.click('#configure-btn'); await page.waitForSelector('.cfg-back'); await onState('editor-configure'); await page.keyboard.press('Escape'); });
  await step('home-recent', async () => { await page.goto(url + '/app/'); await page.waitForSelector('.recent'); await page.waitForTimeout(300); await onState('home-recent'); });
}

/** a fresh reel with the clip and a line of captions, the playhead at 1.2 s (as ui-audit.cjs builds it) */
async function openEditor(page, url, wsId, clip) {
  await page.goto(url + '/app/'); await page.waitForSelector('.home');
  await page.setInputFiles('.home-drop input[type=file]', clip);
  await page.waitForSelector('.tl-item');
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  await page.evaluate(async (ar) => {
    const C = await import('/app/captions/commands.js'); const P = await import('/app/core/project.js'); const ed = kbbEditor, p = ed.project;
    const tr = p.tracks.find((t) => t.kind === 'caption');
    const text = ar ? 'مرحبا بكم في الدراسة في ألمانيا التسجيل مفتوح الآن احجز استشارة مجانية اليوم' : 'Welcome to your glow routine this serum is the secret for soft and bright skin every single day';
    const words = text.split(' ').map((w, i) => ({ w, t0: 0.3 + i * 0.45, t1: 0.3 + i * 0.45 + 0.4, conf: 1 }));
    ed.history.apply(ed.ops.batch('Captions', [C.setCaptions({ style: 'word-box', lang: ar ? 'ar' : 'en', words, soundTags: 'hide' }, p.canvas),
      ed.ops.addItems([{ trackId: tr.id, item: P.createItem('caption', { t0: 0, in: 0, out: P.projectDuration(p), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'Captions' }) }])]));
    ed.seek(1.2);
  }, wsId === 'planet-careers');
  await page.waitForTimeout(600);
}

module.exports = { walk, openEditor };
