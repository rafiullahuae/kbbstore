/* Lane CK2 (23 October 2026): does the on-demand inline block draw EXACTLY
   what Lane CK's always-shipped twin rules drew, for the same settings?

   Two previews (tools/ck-preview.sh), one of commit 0e27fb0f (Lane CK) and
   one of this branch, each given the same scenario by tools/ck2-state.sh. The
   checkout, the cart page and the opened cart panel are drawn in both, EN and
   AR, at each width, and every element's computed font-size, padding, margin,
   gaps, line-height and box height are compared.

     OLD=http://127.0.0.1:A NEW=http://127.0.0.1:B OLD_TREE=<dir> NEW_TREE=<dir> \
       node tools/ck2-same.cjs <out.json>

   Local previews only. */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const OLD = process.env.OLD, NEW = process.env.NEW;
for (const u of [OLD, NEW]) if (!u || !/^http:\/\/127\.0\.0\.1:\d+$/.test(u)) throw new Error('OLD and NEW must be http://127.0.0.1:PORT');
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const PROPS = ['fontSize', 'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight', 'marginTop', 'marginBottom', 'marginLeft', 'marginRight', 'rowGap', 'columnGap', 'lineHeight', 'color'];

const SCENARIOS = {
  defaults: {},
  overall: {
    checkout: { d_ov_text: 10, m_ov_text: 15 }, cartpage: { tx_ov_text: 8, tx_ov_text_m: 12 },
    cartpanel: { ov_text: 10, ov_text_m: 20 },
  },
  roles: {
    checkout: { d_t_rows: 120, d_t_total: 130, d_t_coupon: 115, d_t_help: 125, d_t_dlv: 110, d_t_pay: 120, d_t_tick: 130, d_t_flift: 120,
      m_t_rows: 125, m_t_total: 135, m_t_coupon: 110, m_t_help: 120, m_t_dlv: 115, m_t_pay: 125, m_t_tick: 120, m_t_flift: 130 },
    cartpage: { tx_sumhead: 120, tx_rows: 115, tx_total: 130, tx_coupon: 120, tx_help: 125, tx_sumhead_m: 125, tx_rows_m: 120, tx_total_m: 130, tx_coupon_m: 115, tx_help_m: 120 },
    cartpanel: { tabs_size: 120, sum_size: 125, help_size: 115, coupon_size: 120, tabs_size_m: 115, sum_size_m: 130, help_size_m: 120, coupon_size_m: 125 },
  },
  spacing: {
    checkout: { d_ov_link: false, d_ov_space: 10, m_ov_link: false, m_ov_space: -5 },
    cartpage: { tx_ov_link: false, tx_ov_space: 10, tx_ov_link_m: false, tx_ov_space_m: 15 },
    cartpanel: { ov_link: false, ov_space: 20, ov_link_m: false, ov_space_m: -10 },
  },
  arabic: {
    checkout_ar: { m_t_rows: 130, d_t_title: 120, m_ov_text: 10, d_t_pay: 115 },
    cartpage_ar: { tx_total_m: 125, tx_ov_text: 5 }, cartpanel_ar: { ov_text_m: 15, tabs_size: 120 },
  },
  phones: {
    checkout: { m_ov_text: 20, m_t_title: 120, m_t_total: 130, m_t_flift: 125 },
    cartpage: { tx_ov_text_m: 20, tx_total_m: 130 }, cartpanel: { ov_text_m: 20, sum_size_m: 130, name_size_m: 15 },
  },
};

function dump(props) {
  const out = [];
  document.querySelectorAll('body *').forEach((el) => {
    if (el.closest('script,style,noscript,svg,template')) return;
    const cs = getComputedStyle(el);
    const row = [el.tagName + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')];
    props.forEach((p) => row.push(cs[p]));
    row.push(Math.round(el.getBoundingClientRect().height * 10) / 10);
    out.push(row);
  });
  return out;
}

async function draw(browser, base, lang, w, where) {
  const m = w < 700;
  const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, isMobile: m, hasTouch: m, userAgent: m ? UA_M : UA_D, serviceWorkers: 'block' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  const pre = lang === 'ar' ? '/ar' : '';
  await page.goto(base + pre + '/cart/', { waitUntil: 'networkidle' });
  const ids = await (await page.request.get(base + '/ck-ids.json')).json();
  for (const id of ids.slice(0, 3)) {
    await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
  }
  await page.goto(base + pre + where, { waitUntil: 'networkidle' });
  if (where === '/cart/') await page.evaluate(() => { const c = document.getElementById('cart'); if (c) c.classList.add('on'); });
  await page.waitForTimeout(400);
  const rows = await page.evaluate(dump, PROPS);
  const block = await page.evaluate(() => [...document.querySelectorAll('style[id^="kbb-"][id$="type"]')].map((s) => s.id + ':' + s.textContent.length));
  await ctx.close();
  return { rows, errors, block };
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = [];
  for (const [name, set] of Object.entries(SCENARIOS)) {
    for (const tree of [process.env.OLD_TREE, process.env.NEW_TREE]) {
      execFileSync('sh', [path.resolve(__dirname, 'ck2-state.sh'), tree], { env: { ...process.env, CK2_SET: JSON.stringify(set) }, stdio: 'pipe' });
    }
    const widths = name === 'phones' ? [330, 360, 390, 1280] : [390, 1280];
    for (const lang of ['en', 'ar']) {
      for (const w of widths) {
        for (const where of ['/checkout/', '/cart/']) {
          const a = await draw(browser, OLD, lang, w, where);
          const b = await draw(browser, NEW, lang, w, where);
          const diffs = [];
          if (a.rows.length !== b.rows.length) diffs.push(`element count ${a.rows.length} -> ${b.rows.length}`);
          for (let i = 0; i < Math.min(a.rows.length, b.rows.length); i++) {
            for (let k = 1; k < a.rows[i].length; k++) {
              if (a.rows[i][k] !== b.rows[i][k]) diffs.push(`${b.rows[i][0]} ${k - 1 < PROPS.length ? PROPS[k - 1] : 'height'}: ${a.rows[i][k]} -> ${b.rows[i][k]}`);
            }
          }
          report.push({ scenario: name, lang, w, where, elements: b.rows.length, differences: diffs.length, block: b.block, errors: b.errors, sample: diffs.slice(0, 12) });
          console.log(name, lang, w, where, 'elements', b.rows.length, 'diffs', diffs.length, b.block.join(' '), b.errors.length ? 'ERRORS ' + b.errors.join(' | ') : '');
        }
      }
    }
  }
  await browser.close();
  fs.writeFileSync(process.argv[2], JSON.stringify(report, null, 1));
})();
