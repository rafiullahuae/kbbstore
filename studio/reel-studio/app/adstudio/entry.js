// KBB Studio — the Ad Studio's way in (18 Nov 2026). The owner: "why i can't see any ads studio here, like the one u gave
// me and i made several ads from it like national day, along with video editor, i need also the Ads studio too."
//
// The Ad Studio is docs/plans/ad-studio, built by its build.py into this folder (ad-studio.html + its cut-out file). It
// opens in this tab, served by the engine at the studio's fixed origin; inside KBB Studio it saves to
// ~/KBB Studio Data/adstudio (its src/kbbdisk.js) and its "◀ KBB Studio" button comes back here. The company picked here
// is the one it opens on (the same ids, read from this origin's own storage), and a switch there is kept here.

import { h } from '../editor/ui.js';

export const AD_STUDIO_URL = '/app/adstudio/ad-studio.html';

export function openAdStudio() { location.assign(AD_STUDIO_URL); }

/** the top-bar entry (next to Configure / Models) */
export const adStudioButton = () =>
  h('button.btn.ghost#adstudio-btn', { title: 'Ad Studio: the full ad editor with your templates and saved ads', on: { click: openAdStudio } }, '🎨 Ad Studio');

/** the home tile: says what it is, and how it differs from Brief → Designs */
export function adStudioTile() {
  return h('section.card.ad-card#home-adstudio', { style: { borderColor: 'color-mix(in srgb, var(--accent) 45%, var(--line))', background: 'linear-gradient(160deg, color-mix(in srgb, var(--accent) 12%, var(--panel)), var(--panel) 60%)' } },
    h('div.tile-head', h('span.tile-ic', { 'aria-hidden': 'true' }, '🎨'), h('div', h('h2', 'Ad Studio'), h('small', 'The full ad editor · your templates and saved ads'))),
    h('p.muted.tile-note', 'KBB, National Day, Planet Careers and Work Abroad templates, your companies, layers and groups, video mode and the ✂ cut-out. Everything you make is saved in KBB Studio Data on this Mac.'),
    h('div.tile-actions', h('button.btn.primary#home-adstudio-open', { on: { click: openAdStudio } }, '🎨 Open Ad Studio')));
}
