// KBB Studio · the six Work Abroad reel recipes (app/auto/recipes/work-abroad.js): data checks, in node.
//  - in RECIPES, Planet Careers-first, each with structure (shares sum to 1), a real caption style EN + AR, music moods
//    the library has, real sfx names, an end card with EN + AR calls to action
//  - honest: no digit, currency, % or "guarantee" in any hint or call to action
//  - the matcher picks them: "we're hiring welders for Poland" -> wa-we-are-hiring; documents -> work-permit docs; ...
//  - resolveRecipe() turns each into a plan without throwing (15 / 30 / 60 s, EN and AR)
// node tools/wa-recipes-check.cjs
'use strict';
const path = require('path'), fs = require('fs');
const ROOT = path.resolve(__dirname, '..');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
(async () => {
  const R = await import(path.join(ROOT, 'app/auto/recipes/recipes.js'));
  const W = await import(path.join(ROOT, 'app/auto/recipes/work-abroad.js'));
  const E = await import(path.join(ROOT, 'app/auto/recipes/engine.js'));
  const CS = await import(path.join(ROOT, 'app/auto/recipes/caption-styles.js'));
  const S = await import(path.join(ROOT, 'app/captions/styles.js'));
  const styles = new Set(S.STYLES.map((s) => s.id));
  const lib = JSON.parse(fs.readFileSync(path.join(ROOT, 'library/music/library.json'), 'utf8')), moods = new Set((lib.tracks || lib).flatMap((t) => t.moods || [t.mood]).filter(Boolean));
  const sfxLib = JSON.parse(fs.readFileSync(path.join(ROOT, 'library/sfx/library.json'), 'utf8')), sfx = new Set((sfxLib.sfx || sfxLib.items || sfxLib).map((x) => x.id));
  const wa = W.WORK_ABROAD_RECIPES;
  ok(wa.length === 6 && wa.every((r) => R.RECIPES.includes(r)) && new Set(R.RECIPES.map((r) => r.id)).size === R.RECIPES.length, `6 Work Abroad recipes in RECIPES (${R.RECIPES.length} in all, ids unique)`);
  const bad = [];
  for (const r of wa) {
    const sum = r.structure.reduce((s, p) => s + p.share, 0); if (Math.abs(sum - 1) > 1e-6) bad.push(r.id + ' shares ' + sum);
    for (const lang of ['en', 'ar']) { const id = CS.captionStyleFor(r, lang); if (!styles.has(id)) bad.push(r.id + ' caption ' + id); }
    r.music.moods.forEach((m) => { if (!moods.has(m)) bad.push(r.id + ' mood ' + m); });
    Object.values(r.effects.sfx).forEach((x) => { if (!sfx.has(x)) bad.push(r.id + ' sfx ' + x); });
    if (!r.endCard.cta.length || r.endCard.cta.length !== r.endCard.ctaAr.length) bad.push(r.id + ' cta');
    if (!r.workspaces.includes('planet-careers') || !r.nameAr) bad.push(r.id + ' workspace / Arabic name');
    const words = [...r.structure.map((p) => p.hint), ...r.endCard.cta, r.name].join(' ').replace(/‹[^›]*›/g, '');
    if (/[0-9]|AED|€|\$|£|%|guarantee/i.test(words.replace(/\b3 things\b/, ''))) bad.push(r.id + ' claim? ' + words.match(/[0-9]+|AED|€|\$|£|%|guarantee/i)[0]);
    for (const len of r.lengths) for (const lang of ['en', 'ar']) { try { E.resolveRecipe(r, { length: len, lang }); } catch (e) { bad.push(r.id + ' resolve ' + len + lang + ' ' + e.message); } }
  }
  ok(bad.length === 0, 'each: shares sum to 1, real caption styles EN/AR, library moods and sounds, EN/AR calls to action, honest hints, resolves at every length: ' + bad.slice(0, 6).join(' | '));
  const pick = (text) => (E.suggestRecipes({ text, workspace: { id: 'planet-careers' }, limit: 3 })[0] || {}).recipe?.id;
  const cases = [['We are hiring welders and electricians for Poland, urgent openings, apply now', 'wa-we-are-hiring'], ['Which documents do you need for a work permit? passport, experience certificate, medical, police clearance', 'wa-work-permit-docs'],
    ['Interview tips: three questions employers ask, how to answer and prepare for the trade test', 'wa-interview-tips'], ['A day in my life as a nurse in Germany: morning shift, routine, my team', 'wa-day-at-work'],
    ['My journey from home to Canada, my family, my dream, I finally arrived and started work', 'wa-home-to-abroad'], ['مطلوب عمال للعمل في بولندا فرص عاجلة شواغر', 'wa-we-are-hiring']];
  const got = cases.map(([t, want]) => [want, pick(t)]);
  ok(got.filter(([w, g]) => w === g).length >= 5, 'the matcher picks the Work Abroad recipe from what is said: ' + got.map(([w, g]) => (w === g ? '✓ ' : '✗ ') + w + (w === g ? '' : ' (got ' + g + ')')).join(' · '));
  console.log(fails ? fails + ' FAILED' : 'ALL PASSED'); process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
