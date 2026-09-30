/*
 * THE HONEST WAY TO ASK WHETHER A PAGE IS ACTUALLY RENDERING A FONT.
 *                                                                    (Lane BG)
 *
 *   const { probeFamily, declaredFaces } = require('./font-probe.cjs');
 *   const r = await probeFamily(page, 'Poppins', [400, 500, 600]);
 *   //  r.rendered[500] === false  means the page showed something else
 *
 * This module exists because TWO different instruments in this repository
 * reported a font as present on pages that did not have it, in two different
 * ways, and both reported it as a plain fact with no hedge. The mechanics that
 * stop that are three lines each and they belong in ONE place, which is why
 * this file is a module and not a fourth copy.
 *
 * ── TRAP 1: A RULER WITH A FALLBACK LIST MEASURES THE FALLBACK ─────────────
 *
 * A probe set in
 *
 *     font-family: Poppins, system-ui, sans-serif
 *
 * reports a width on every page. On a page that HAS Poppins it is Poppins's
 * width; on a page that does not, it is silently the system font's -- and,
 * because it is the same system font on every such page, it reports the SAME
 * plausible numbers across all of them. That is what makes it so hard to spot:
 * the output looks like a clean measurement of a stable thing.
 *
 * Measured: an earlier version of tools/bg-weight500.cjs reported 543.28px at
 * weight 400 and 556.77px at 600 on all eight storefront pages, four of which
 * had no Poppins at all. Three numbers derived from that reached a docblock and
 * a commit message as fact.
 *
 * THE FIX IS A SECOND RULER, set in a family that cannot exist. Equal widths
 * mean the named family never rendered. One ruler cannot tell you this at all,
 * however it is written.
 *
 * ── TRAP 2: document.fonts.check() ANSWERS TRUE FOR FONTS THAT DO NOT EXIST ─
 *
 * `document.fonts.check('13px Poppins')` reads like "is Poppins available?" and
 * is not that question. An unknown family needs nothing loaded, so the answer
 * is true. Measured on a page declaring ZERO faces (`document.fonts.size` 0,
 * no stylesheet, no @font-face):
 *
 *     check('13px Poppins')               true
 *     check('13px Fraunces')              true
 *     check('13px KbbNoSuchFamily12345')  true
 *
 * — while the two rulers on that same page correctly read 436.63px against
 * 436.63px. tools/perf-fontcheck.cjs, whose whole subject is "does the page
 * actually get its fonts?", had that call as its headline answer.
 *
 * ── TRAP 3: display:swap MEANS AN UNUSED FACE IS NOT THERE YET ─────────────
 *
 * `document.fonts.ready` resolves when the faces the page's OWN LAYOUT needed
 * have loaded. A probe for a weight nothing on the page uses is not covered by
 * it: the face has never been requested, so the probe measures the fallback and
 * the page looks like it is missing a weight it serves perfectly well.
 *
 * This bit twice in one afternoon. /cart/ and /reviews/ read as outliers in a
 * table where every other page agreed, and the hunt went looking for a cascade
 * bug that did not exist: those are simply the two pages with no visible text
 * at weight 500, so 500 had not been fetched when the probe ran.
 *
 * THE FIX IS TO ASK FOR EACH WEIGHT EXPLICITLY, with document.fonts.load(),
 * BEFORE measuring anything. It is a no-op for a face the page already uses.
 *
 * ── WHY THE RULER STRING IS WHAT IT IS ────────────────────────────────────
 *
 * `Hydrating Serum AED 149` is ASCII-only and on purpose: every glyph is in the
 * `latin` subset, so the measurement cannot be changed by which of a family's
 * unicode-range files happens to have arrived. It is long enough that a 1%
 * difference in advance width is several pixels rather than a rounding error,
 * and it carries both letters and digits because some families ship
 * tabular figures at one weight and not another.
 */
'use strict';

/** A family name no foundry will ever ship, used as the "nothing rendered" control. */
const CONTROL_FAMILY = 'KbbNoSuchFamily12345';

/** ASCII only — see the note above. */
const RULER = 'Hydrating Serum AED 149';

/** The size the rulers are set at. Big enough that small differences are visible. */
const RULER_PX = 40;

/**
 * Measure one family at a set of weights, and say for each whether it RENDERED.
 *
 * @param {import('playwright').Page} page
 * @param {string} family   the family to measure, e.g. 'Poppins'
 * @param {number[]} weights
 * @returns {Promise<{family: string, widths: Object, control: Object,
 *                    rendered: Object, renderedAny: boolean, faces: string[]}>}
 */
async function probeFamily(page, family, weights) {
  return page.evaluate(async ([family, weights, controlFamily, ruler, px]) => {
    /* TRAP 3. Ask for every weight before measuring any of them. A face the
       page already uses is already there and this costs nothing; a face it does
       not use is fetched here rather than being reported as missing. */
    for (const w of weights) {
      try {
        await document.fonts.load(`${w} ${px}px "${family}"`);
      } catch (e) {
        /* No such face. That is an answer, not an error — the widths below say
           what the browser did about it. */
      }
    }

    await document.fonts.ready;

    const make = (fam) => {
      const s = document.createElement('span');
      s.style.cssText = 'position:absolute;left:-9999px;top:0;white-space:pre;'
        + `font-size:${px}px;font-family:"${fam}"`;
      s.textContent = ruler;
      document.body.appendChild(s);
      return s;
    };

    /* TRAP 1. Two rulers. The named family ALONE — never a fallback list, or
       this measures the fallback and cannot tell you so. */
    const real = make(family);
    const control = make(controlFamily);

    const widths = {};
    const controlWidths = {};
    const rendered = {};

    for (const w of weights) {
      real.style.fontWeight = String(w);
      control.style.fontWeight = String(w);
      const a = Math.round(real.getBoundingClientRect().width * 100) / 100;
      const b = Math.round(control.getBoundingClientRect().width * 100) / 100;
      widths[w] = a;
      controlWidths[w] = b;
      /* Equal to the hundredth of a pixel is the family not rendering. It is
         not a threshold: two different typefaces do not agree to 0.01px on a
         23-character string by accident. */
      rendered[w] = a !== b;
    }

    real.remove();
    control.remove();

    return {
      family,
      widths,
      control: controlWidths,
      rendered,
      renderedAny: weights.some((w) => rendered[w]),
      /* The faces the DOCUMENT declares, with the status the browser gave each.
         This is the real version of the question document.fonts.check() only
         looks like it answers. */
      faces: [...document.fonts]
        .filter((f) => f.family.replace(/["']/g, '') === family)
        .map((f) => `${f.weight}/${f.status}`),
    };
  }, [family, weights, CONTROL_FAMILY, RULER, RULER_PX]);
}

/**
 * Every face the document declares, whatever the family, with its load status.
 *
 * `status` is the honest signal document.fonts.check() is mistaken for: a face
 * the page declared and FAILED to fetch reads 'error' here and true there.
 */
async function declaredFaces(page) {
  return page.evaluate(async () => {
    await document.fonts.ready;

    return {
      size: document.fonts.size,
      faces: [...document.fonts].map((f) => ({
        family: f.family.replace(/["']/g, ''),
        weight: f.weight,
        status: f.status,
      })),
    };
  });
}

module.exports = { probeFamily, declaredFaces, CONTROL_FAMILY, RULER, RULER_PX };
