/**
 * Store → Store Import / Export → Import progress, photographed — Lane PX.
 *
 * Nothing here pokes the API to fake a state. It signs in, uploads a real
 * export, presses Import, and photographs the page while the import is
 * genuinely part-done and again when it has genuinely finished — because the two
 * things this lane added to that page are a per-file "Fields dropped" count and
 * a closing reconciliation, and the second of those is SUPPOSED to look
 * different mid-run. A screenshot of a hand-built payload could not show that.
 *
 * ── RUNNING IT ──────────────────────────────────────────────────────────────
 *
 *   sh tools/px-progress-preview.sh 8992
 *   KBB_PX_URL=http://127.0.0.1:8992 KBB_PX_OUT=docs/px-progress-shots \
 *     node tools/px-progress-shots.mjs
 *
 * It IMPORTS INTO the shop it points at. Point it at the preview, never at
 * production.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync, readdirSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.KBB_PX_URL || 'http://127.0.0.1:8992';
const OUT = process.env.KBB_PX_OUT || 'docs/px-progress-shots';
const CHROME = process.env.KBB_PX_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const EMAIL = process.env.KBB_PX_EMAIL || 'px@preview.test';
const PASSWORD = process.env.KBB_PX_PASSWORD || 'preview-secret-1';

mkdirSync(OUT, { recursive: true });

/* ── the export, with a products.csv wide enough to be worth photographing ── */

const FIXTURE = 'tests/Fixtures/kbb-export';
const work = mkdtempSync(join(tmpdir(), 'kbb-px-shots-'));

for (const name of readdirSync(FIXTURE)) {
  writeFileSync(join(work, name), readFileSync(join(FIXTURE, name)));
}

const browser = await chromium.launch({ executablePath: CHROME });

async function shoot(label, width, height) {
  const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 2 });
  const page = await context.newPage();

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[type="email"]', EMAIL);
  await page.fill('input[type="password"]', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');

  await page.goto(`${BASE}/admin-api/import/background-page`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);

  const measured = await page.evaluate(() => {
    const table = document.querySelector('#files table');
    const header = table ? [...table.querySelectorAll('thead th')].map(th => th.textContent.trim()) : [];
    const reconcile = document.querySelector('#reconcile');
    const alert = reconcile ? reconcile.querySelector('.alert') : null;
    const dropped = table
      ? [...table.querySelectorAll('tbody tr')].map(tr => {
          const cells = [...tr.querySelectorAll('td')];
          return { file: cells[0]?.textContent.trim(), dropped: cells[8]?.textContent.trim() };
        })
      : [];

    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      bodyFontSize: getComputedStyle(document.body).fontSize,
      filesHeader: header,
      droppedColumn: dropped,
      reconcileVisible: reconcile ? getComputedStyle(reconcile).display !== 'none' : false,
      reconcileClass: alert ? alert.className : null,
      reconcileHeight: alert ? Math.round(alert.getBoundingClientRect().height) : null,
      reconcileText: alert ? alert.textContent.trim() : null,
      droppedTooltip: table
        ? (table.querySelector('tbody span[title]')?.getAttribute('title') ?? null)
        : null,
    };
  });

  await page.screenshot({ path: join(OUT, `${label}-${width}.png`), fullPage: true });
  console.log(JSON.stringify({ shot: `${label}-${width}`, ...measured }, null, 2));
  await context.close();
  return measured;
}

/* ── 1. mid-run ───────────────────────────────────────────────────────────── */

console.log('### seeding a part-done import');
execFileSync('php', ['tools/px-progress-seed.php', work, 'partial'], { stdio: 'inherit' });

await shoot('midrun', 390, 900);
await shoot('midrun', 1280, 900);

/* ── 2. finished ──────────────────────────────────────────────────────────── */

console.log('### finishing the import');
execFileSync('php', ['tools/px-progress-seed.php', work, 'finish'], { stdio: 'inherit' });

await shoot('finished', 390, 1000);
await shoot('finished', 1280, 1000);

await browser.close();
