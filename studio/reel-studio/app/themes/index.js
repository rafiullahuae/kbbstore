// KBB Studio · themes module: animated themes that run throughout a reel (Europe, 33 country packs, travel, study, work
// abroad, beauty/KBB, luxury, offers, seasonal, corporate, food, fitness, real estate), each with its intro sting, outro,
// transition style, text animation and sounds — plus the hover previews every gallery uses (./hover.js).
//
//   import * as themes from './themes/index.js';
//   themes.suggest(ctx)                              top 3 themes for this reel [{id, name, score, why}]
//   await themes.apply(ctx, 'europe', opts)          ONE undo step (overlay + sting + outro + transitions + title + sfx)
//   themes.tune(ctx, {intensity:'bold'}) · themes.tuneLayer(ctx, 'petals', {density:1.5}) · themes.remove(ctx)
import { registerModule } from '../core/modules.js';
import { bus } from '../core/bus.js';
import './draw.js';
import { THEMES, GROUPS, themeById } from './themes.js';
import { suggestThemes, countryOf } from './suggest.js';
import { applyTheme, tuneTheme, tuneLayer as tuneL, removeTheme, resyncBeats, followReel, reelEnd, overlayOf, detectFaces, beatsOf, themeStyle, placeElement, setWhole, isWhole, wholeItems, signatureOf } from './apply.js';
import { recipeById } from '../auto/recipes/recipes.js';

export { THEMES, GROUPS, themeById, suggestThemes, countryOf, detectFaces, beatsOf, overlayOf, themeStyle, followReel, reelEnd, placeElement, setWhole, isWhole, wholeItems, signatureOf };
export { ELEMENTS, elementById, boxFor } from './elements.js';
export { kitFor } from './kit.js';
export { TRANSITIONS, placeTransition, setTransitionDuration, cutsOf as cutPairs, transitionOf } from './transitions.js';
export { pulseAt, renderThemeLayer, avoidBoxes, loadAssets, INTENSITY, ELEMENT_KINDS } from './draw.js';

const textOf = (p) => (p.captions?.words || []).map((w) => w.w).join(' ');
export function suggest(ctx, limit = 3) {
  const recipe = recipeById(ctx.project.recipe), text = textOf(ctx.project);
  return suggestThemes({ text, recipe, workspace: ctx.workspace, country: countryOf({ text, recipe }), limit });
}
export const apply = (ctx, id, opts) => applyTheme(ctx, id, opts);
export const tune = (ctx, patch, label) => tuneTheme(ctx, patch, label);
export const tuneLayer = (ctx, id, patch, label) => tuneL(ctx, id, patch, label);
export const remove = (ctx) => removeTheme(ctx);
export const resync = (ctx) => resyncBeats(ctx);

// the theme follows the reel: after every apply / undo / redo the overlay is stretched or cut to the reel's end and the
// outro moved to close it. One listener on the change the editor already announces; no timer. It is registered when this
// module loads (before the editor exists), so it runs before the editor's own redraw of the same change.
let live = null;
bus.on('project:load', (e) => { live = e?.project || null; followReel(live); });
bus.on('project:change', () => { followReel(live); });

registerModule({
  id: 'themes', label: 'Themes', icon: '✨',
  tab: { label: 'Themes', icon: '✨', order: 12 },
  panel: (el, ctx) => {
    let inst = null, dead = false;
    import('./panel.js').then((m) => { if (!dead) inst = m.mountPanel(el, ctx); }).catch((e) => console.warn('[themes] panel', e));
    return { destroy() { dead = true; inst?.destroy?.(); } };
  },
});
