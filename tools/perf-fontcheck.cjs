/*
 * Lane PERF — does the page actually get its fonts?
 *
 * Written after an hour lost to an instrument bug: php -S's own static handler
 * answered some of a dozen concurrent small files with
 * net::ERR_INVALID_HTTP_RESPONSE, so Chromium reported four @font-face as
 * `status: "error"` and fell back to the system face -- on a shop whose font
 * files curl fetched happily one at a time. `document.fonts` says which faces
 * a page HAS and which it LOADED, and the two are not the same question.
 *
 *   node tools/perf-fontcheck.cjs http://127.0.0.1:8991/
 *
 * ── THE HEADLINE ANSWER USED TO BE A CALL THAT CANNOT FAIL ──── (Lane BG) ──
 *
 * This script's summary line was
 *
 *     check: document.fonts.check('13px Poppins'),
 *
 * which reads as "is Poppins available?" and is not that question. An unknown
 * family needs nothing loaded, so the answer is true. Measured on a page
 * declaring ZERO faces -- no stylesheet, no @font-face, document.fonts.size 0:
 *
 *     check('13px Poppins')               true
 *     check('13px Fraunces')              true
 *     check('13px KbbNoSuchFamily12345')  true
 *
 * So on the very failure this script was written to catch -- the faces erroring
 * and the page falling back to the system font -- that line still printed
 * `check: true`. It could only ever print true. The rest of the script (the
 * per-face `status`, the RESP lines, the requestfailed handler) was doing the
 * real work, and the one line presented as the verdict was the one line that
 * could not deliver one.
 *
 * It now asks the question by measuring: two rulers, one in the family alone
 * and one in a family that cannot exist, with every weight explicitly loaded
 * first. tools/font-probe.cjs holds those mechanics and the three traps they
 * exist for, so the next instrument gets them by requiring a file rather than
 * by remembering.
 */
const { chromium } = require('playwright');
const { probeFamily, declaredFaces } = require('./font-probe.cjs');

const FAMILY = process.env.PERF_FAMILY || 'Poppins';
const WEIGHTS = (process.env.PERF_WEIGHTS || '400,500,600,700,800').split(',').map(Number);

(async () => {
  const b = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: ['--ignore-certificate-errors'],
  });
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  const fails = [];
  p.on('requestfailed', (r) => fails.push(r.url() + ' :: ' + (r.failure() || {}).errorText));
  p.on('response', (r) => { if (r.url().includes('woff2')) console.log('RESP', r.status(), r.url()); });
  p.on('console', (m) => console.log('CONSOLE', m.type(), m.text().slice(0, 200)));

  await p.goto(process.argv[2], { waitUntil: 'networkidle' });
  await p.waitForTimeout(1500);

  console.log('failed:', fails);

  const declared = await declaredFaces(p);
  const probe = await probeFamily(p, FAMILY, WEIGHTS);

  console.log(JSON.stringify({
    declaredFaceCount: declared.size,
    facesOfThisFamily: probe.faces,
    /* THE VERDICT, and it can now be false. Per weight: did the named family
       actually put glyphs on the page, or did something else stand in for it? */
    rendered: probe.rendered,
    widths: probe.widths,
    controlWidths: probe.control,
    bodyFont: await p.evaluate(() => getComputedStyle(document.body).fontFamily),
  }, null, 2));

  const missing = WEIGHTS.filter((w) => !probe.rendered[w]);

  /*
   * ── THE VERDICT GOES TO stderr, AND THE EXIT CODE IS THE REAL ANSWER ─────
   *                                                                (Lane BG)
   * This script has NO callers -- nothing in CI, no shell script, no npm
   * script runs it; it is typed by a human. That is exactly why how it fails
   * matters: the first caller somebody writes will be a one-liner, and a
   * one-liner is where an answer gets lost.
   *
   * The FAIL line used to go to stdout beside the JSON, so
   * `node tools/perf-fontcheck.cjs URL > /dev/null` threw the verdict away and
   * kept only an exit code nobody was checking. It now goes to stderr, which
   * survives stdout redirection, while stdout stays clean JSON for a caller
   * that wants to pipe it.
   *
   * AND IT CANNOT SILENTLY PASS WHEN IT MEASURED NOTHING. Measured: pointed at
   * an unreachable URL, page.goto rejects and node exits 1 rather than printing
   * OK on an empty measurement.
   */
  if (missing.length > 0) {
    console.error(`\nFAIL: ${FAMILY} did not render at weight(s) ${missing.join(', ')}`
      + ' -- the ruler matched the control exactly, which is the fallback.');
    process.exitCode = 1;
  } else {
    console.error(`\nOK: ${FAMILY} rendered at every weight asked for (${WEIGHTS.join(', ')}).`);
  }

  await b.close();
})();
