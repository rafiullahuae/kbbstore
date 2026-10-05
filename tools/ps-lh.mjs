/* Lane PS — one Lighthouse run against a local preview, written as JSON +
 * a one-line summary. Lighthouse is NOT a dependency of this repository
 * (CLAUDE.md: no new dependency); point LH_DIR at any directory with
 * `lighthouse` installed in node_modules.
 *
 *   LH_DIR=/path node tools/ps-lh.mjs <label> <mobile|desktop> <url>
 *
 * Mobile = Lighthouse's own default (what the brief asks for): simulated Slow
 * 4G (150 ms RTT, 1,638 kb/s), 4x CPU, 412 x 823 Moto G Power. Desktop = the
 * shipped desktop preset. Output under storage/ps-logs/lh/ inside the
 * worktree, never the shared scratchpad. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const LH = process.env.LH_DIR;
if (!LH) { console.error('set LH_DIR'); process.exit(2); }
const req = createRequire(path.join(LH, 'package.json'));
const lighthouse = (await import(pathToFileURL(req.resolve('lighthouse')).href)).default;
const chromeLauncher = await import(pathToFileURL(req.resolve('chrome-launcher')).href);
const desktopConfig = (await import(pathToFileURL(path.join(path.dirname(req.resolve('lighthouse/package.json')), 'core/config/desktop-config.js')).href)).default;

const [label, profile = 'mobile', url] = process.argv.slice(2);
const out = path.join(APP, 'storage/ps-logs/lh');
fs.mkdirSync(out, { recursive: true });

const chrome = await chromeLauncher.launch({
  chromePath: process.env.CHROME_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  chromeFlags: ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
});
try {
  const config = profile === 'desktop' ? desktopConfig : { extends: 'lighthouse:default' };
  const runner = await lighthouse(url, { logLevel: 'error', output: 'json', port: chrome.port }, config);
  const lhr = runner.lhr;
  fs.writeFileSync(path.join(out, `${label}-${profile}.json`), runner.report);
  const a = (id) => lhr.audits[id]?.numericValue;
  const c = (id) => Math.round((lhr.categories[id]?.score ?? 0) * 100);
  const row = { label, profile, url, perf: c('performance'), a11y: c('accessibility'), bp: c('best-practices'), seo: c('seo'),
    fcp: Math.round(a('first-contentful-paint')), lcp: Math.round(a('largest-contentful-paint')), si: Math.round(a('speed-index')),
    tbt: Math.round(a('total-blocking-time')), cls: +(a('cumulative-layout-shift') ?? 0).toFixed(3),
    kib: Math.round((a('total-byte-weight') ?? 0) / 1024) };
  fs.appendFileSync(path.join(out, 'runs.jsonl'), JSON.stringify(row) + '\n');
  console.log(JSON.stringify(row));
} finally {
  await chrome.kill();
}
