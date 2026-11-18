// KBB Studio · BRIEF → DESIGNS (round 3). The editor imports this file (core/modules.js MODULE_ENTRIES). It registers:
//   home: a "Brief → Designs" card on the home screen (type a brief → the Designs screen)
//   tab : "🖼 Designs" in the reel editor's right column -- the company's saved designs, "+ Add to reel" puts one at the
//         playhead (as a picture clip), "Open Designs" goes to the Designs screen
import { registerModule } from '../core/modules.js';
import { h, clear, toast } from '../editor/ui.js';
import { engine } from '../core/engine.js';
const ap = (el, ...k) => { el.append(...k.flat(Infinity).filter((x) => x != null && x !== false)); return el; };

registerModule({
  id: 'designs', label: 'Designs', icon: '🖼',
  tab: { label: 'Designs', icon: '🖼', order: 75 },
  home: (el, home) => {
    const ta = h('textarea#home-brief', { dir: 'auto', rows: 3, placeholder: home.workspace?.industry?.match(/consult|visa|immigra/i) ? 'e.g. Study in Germany, September intake open — story and post' : 'e.g. National Day: 30% off Anua and BoJ, story and post, luxury', 'aria-label': 'Brief for designs', style: { width: '100%', resize: 'vertical', unicodeBidi: 'plaintext' } });
    const go = () => import('./screen.js').then((m) => m.openDesigns(document.getElementById('app'), { home, brief: ta.value.trim(), auto: !!ta.value.trim() }));
    // a start tile like the home screen's own (editor.css .tile-head / .tile-actions): the two buttons share the row
    // evenly and wrap when it is narrow, never squeezing each other (17 Nov: "✨ Make de" clipped under "Open Designs")
    ap(el, h('div.tile-head', h('span.tile-ic', { 'aria-hidden': 'true' }, '🖼'), h('div', h('h2', 'Brief → Designs'), h('small', 'Quick ads from a sentence · TV-style and family templates'))),
      h('p.muted.tile-note', 'Describe an ad in your own words (English or Arabic). You get 6–8 designs — story, post and square — checked against the design rules, ready to edit and export. Your saved ads and full templates are in 🎨 Ad Studio.'),
      ta, h('div.tile-actions', h('button.btn.primary#home-designs', { on: { click: go } }, '✨ Make designs'), h('button.btn', { on: { click: () => import('./screen.js').then((m) => m.openDesigns(document.getElementById('app'), { home })) } }, '🗂 Open Designs')));
  },
  panel: (el, ctx) => {
    const box = h('div', { style: { padding: '10px', display: 'grid', gap: '10px' } });
    el.appendChild(box);
    const paint = async () => {
      clear(box);
      const { listSaved, exportDesign } = await import('./editor.js');
      const ws = ctx.workspace?.id;
      const list = ws ? await listSaved(ws) : [];
      ap(box, h('p.muted', { style: { margin: 0, fontSize: '12px' } }, list.length ? 'Your saved designs. “+ Add to reel” puts one at the playhead.' : 'No saved designs yet. Make some from the home screen: 🖼 Brief → Designs.'));
      for (const d of list.slice(0, 30)) {
        const add = h('button.btn.small', { on: { click: async () => {
          try {
            const D = await engine.readFile(d.path, 'json');
            const size = ctx.project.canvas.aspect === '1:1' ? 'square' : ctx.project.canvas.aspect === '4:5' ? 'post' : ctx.project.canvas.aspect === '16:9' ? 'wide' : 'story';
            const [x] = await exportDesign(D, { sizes: [D.sizes.includes(size) ? size : D.sizes[0]] });
            const { ops } = await import('../core/history.js'), { createTrack } = await import('../core/project.js'), { clipFromMedia } = await import('../editor/items.js');
            const m = await ctx.media.importPath(x.path, D.name || 'Design');
            const p = ctx.project; let track = p.tracks.find((t) => t.kind === 'video' && !t.locked); const cmds = [];
            if (!track) { track = createTrack('video'); cmds.push(ops.addTrack(track)); }
            cmds.push(ops.addMedia(m), ops.addItems([{ trackId: track.id, item: clipFromMedia(p, m, track.kind, ctx.playhead()) }]));
            ctx.history.apply(ops.batch('Add design', cmds));
            toast('Design added at the playhead', 'ok');
          } catch (e) { toast('Not added: ' + e.message, 'error'); }
        } } }, '+ Add to reel');
        box.appendChild(h('div', { style: { display: 'grid', gap: '4px' } }, h('img', { src: '/' + d.thumb + '?v=' + d.mtime, alt: d.name, style: { width: '100%', borderRadius: '8px' } }), add));
      }
    };
    paint();
    return { destroy() {} };
  },
});
