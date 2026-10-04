/*
 * Lane HS screenshots and measurements.
 *
 *   sh tools/hs-preview.sh 10050 && node tools/hs-shots.cjs [port]
 *
 * BEFORE is the state this package replaces, set through the same settings the
 * admin writes: the Blog cards' "Reading time" and "Category tag" ON, and the
 * Spotted section on the Carousel layout (which draws nothing while no post is
 * ticked Homepage). AFTER is the shipped defaults, and AFTER-PICS the grid
 * with six pictures chosen. Every number is read in the browser from the
 * finished page; the shop itself measures nothing.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const APP = path.resolve(__dirname, '..');
const PORT = process.argv[2] || '10050';
const BASE = 'http://127.0.0.1:' + PORT;
const OUT = path.join(APP, 'docs/lane-hs-shots');
const DIR = path.join(APP, 'storage/framework/testing/hs-preview');

function php(code) {
  execFileSync('php', [path.join(APP, 'artisan'), 'tinker', '--execute=' + code], {
    env: Object.assign({}, process.env, {
      APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: DIR + '/preview.sqlite',
      CACHE_STORE: 'file', SESSION_DRIVER: 'file', KBB_PUBLIC_PATH: DIR + '/webroot',
      APP_CONFIG_CACHE: DIR + '/compiled/config.php', APP_ROUTES_CACHE: DIR + '/compiled/routes.php',
      APP_SERVICES_CACHE: DIR + '/compiled/services.php', APP_PACKAGES_CACHE: DIR + '/compiled/packages.php',
    }),
    stdio: 'pipe',
  });
}

function state(name) {
  const s = '$s=app(\\App\\Services\\SettingsService::class);';
  const flush = '\\App\\Services\\SpottedSettings::flush();\\Illuminate\\Support\\Facades\\Cache::flush();';
  if (name === 'before') {
    php(s + "$s->set('home_bl_read',true);$s->set('home_bl_tag',true);app(\\App\\Services\\SpottedSettings::class)->save(['home_layout'=>'carousel']);" + flush);
  } else {
    if (name === 'after-pics') {
      // Six stand-in portrait photographs (5:6), drawn here so the shot needs
      // no network: a warm wash with a darker figure, different per card.
      php("@mkdir(public_path('uploads/hs'),0755,true);foreach(range(1,6) as $n){$im=imagecreatetruecolor(500,600);"
        + "imagefilledrectangle($im,0,0,500,600,imagecolorallocate($im,235-$n*6,220-$n*9,215-$n*4));"
        + "imagefilledellipse($im,250,250,190,230,imagecolorallocate($im,200-$n*12,150-$n*8,140));"
        + "imagefilledrectangle($im,120,360,380,600,imagecolorallocate($im,90+$n*20,60+$n*12,110));"
        + "imagefilledrectangle($im,170,400,330,520,imagecolorallocate($im,214,90,120));"
        + "imagejpeg($im,public_path('uploads/hs/look-'.$n.'.jpg'),85);imagedestroy($im);}");
    }
    let pics = '';
    for (let n = 1; n <= 6; n++) pics += "'grid_" + n + "_img'=>" + (name === 'after-pics' ? "'/uploads/hs/look-" + n + ".jpg'" : "''") + ',';
    php(s + "$s->set('home_bl_read',false);$s->set('home_bl_tag',false);app(\\App\\Services\\SpottedSettings::class)->save(['home_layout'=>'grid'," + pics + "]);" + flush);
  }
}

async function shootHome(browser, name, w) {
  const page = await browser.newPage({ viewport: { width: w, height: w < 600 ? 844 : 900 } });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const m = await page.evaluate(() => {
    const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height) }; };
    const grid = document.querySelector('.spt-sgl');
    const card = document.querySelector('.spt-sgc');
    const post = document.querySelector('.hs-post');
    const h3 = post && post.querySelector('h3');
    return {
      scrollWidth: document.documentElement.scrollWidth,
      spotted: r(document.querySelector('section.spt')),
      grid: r(grid), card: r(card),
      cols: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : 0,
      gap: grid ? getComputedStyle(grid).columnGap : null,
      heading: (document.querySelector('#spt-h') || {}).textContent || null,
      headingSize: document.querySelector('#spt-h') ? getComputedStyle(document.querySelector('#spt-h')).fontSize : null,
      blogMeta: document.querySelectorAll('.hs-blog .hs-pmeta').length,
      blogCard: r(post),
      titleTop: h3 ? Math.round(h3.getBoundingClientRect().top - post.querySelector('.hs-pcb').getBoundingClientRect().top) : null,
    };
  });
  console.log(name, w, JSON.stringify(m));
  for (const [sel, tag] of [['section.spt', 'spotted'], ['section.hs-blog', 'blog']]) {
    const el = await page.$(sel);
    if (!el) continue;
    await el.scrollIntoViewIfNeeded();
    await page.waitForTimeout(150);
    // A full-page clip, so the sticky header does not sit over the section.
    // The camera (not the shop) hides fixed chrome — the WhatsApp bubble —
    // and returns to the top, so the sticky header sits above the clip.
    const b = await el.evaluate((n) => {
      document.querySelectorAll('body *').forEach((x) => { if (getComputedStyle(x).position === 'fixed') x.style.visibility = 'hidden'; });
      const r = n.getBoundingClientRect(); const y = r.top + window.scrollY; window.scrollTo(0, 0); return { y, h: r.height };
    });
    await page.waitForTimeout(150);
    await page.screenshot({ path: `${OUT}/${name}-${tag}-${w}.png`, fullPage: true, clip: { x: 0, y: Math.max(0, b.y - 8), width: w, height: b.h + 16 } });
  }
  await page.close();
}

async function login(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function shootAdmin(browser, w) {
  const page = await browser.newPage({ viewport: { width: w, height: w < 600 ? 3200 : 2600 } });
  await login(page);
  await page.goto(BASE + '/admin?go=spotted', { waitUntil: 'networkidle' });
  await page.evaluate(() => { try { if (typeof window.go === 'function') window.go('spotted'); } catch (e) {} });
  await page.waitForSelector('[data-spa-tab="grid"]', { timeout: 15000 });
  await page.click('[data-spa-tab="grid"]');
  await page.waitForTimeout(200);
  const card = await page.$('.spa-fields');
  await card.scrollIntoViewIfNeeded();
  await page.locator('[data-spa-savesettings]').evaluate((b) => b.closest('.spa-card').scrollIntoView());
  const sw = await page.evaluate(() => document.documentElement.scrollWidth);
  await page.locator('[data-spa-savesettings]').evaluate((b) => b.closest('.spa-card')).then(() => {});
  const settings = await page.$('[data-spa-savesettings]');
  const box = await settings.evaluateHandle((b) => b.closest('.spa-card'));
  await box.asElement().screenshot({ path: `${OUT}/admin-spotted-homepage-grid-${w}.png` });
  // The ↑ on card 2 swaps it with card 1 (client side, saved with Save settings).
  await page.click('[data-spa-gmove="2:-1"]');
  console.log('admin spotted', w, 'scrollWidth', sw);

  await page.evaluate(() => { try { window.go('hpcontent'); } catch (e) {} });
  await page.waitForTimeout(1200);
  await page.click('[data-hpc-tab="blog"]');
  await page.waitForTimeout(500);
  const sw2 = await page.evaluate(() => document.documentElement.scrollWidth);
  await page.screenshot({ path: `${OUT}/admin-homepage-content-blog-${w}.png`, clip: { x: 0, y: 0, width: w, height: w < 600 ? 2400 : 1500 } });
  console.log('admin hpcontent', w, 'scrollWidth', sw2);
  await page.close();
}

(async () => {
  require('fs').mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const name of ['before', 'after', 'after-pics']) {
    state(name);
    for (const w of [390, 1280]) await shootHome(browser, name, w);
  }
  state('after');
  for (const w of [1280, 390]) await shootAdmin(browser, w);
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
