// The "Heard: Hindi 96%" bar at the top of the Captions panel (only once Auto captions has run on this project):
//   * the language Whisper heard, with its confidence (cap.detect, written by auto.js);
//   * Hindi / Urdu speech: a one-click "It's Urdu" / "It's Hindi" (listens again in the other language: the two are
//     one spoken language that Whisper tells apart by guessing the script) and the script switch
//     हिंदी · Roman · اردو (undoable, lossless — hinglish/script.js).
// Mounted by panel.js; never mutates the project (commands through `apply`).

import { SCRIPTS, setScript, currentScript, isHindustani, langLabel } from './script.js';

const h = (tag, attrs = {}, ...kids) => {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (v === true) el.setAttribute(k, '');
    else if (v !== false && v != null) el.setAttribute(k, v);
  }
  for (const c of kids.flat()) if (c != null && c !== false) el.append(c.nodeType ? c : document.createTextNode(String(c)));
  return el;
};
const NAME = { hi: 'Hindi', ur: 'Urdu' };

/** opts: { cap(), apply(cmd), relisten(lang), canvas() } -> { el, render() } */
export function mountLangNotice(opts) {
  const el = h('div', { class: 'rs-cap-detect', hidden: true, 'data-x': 'lang-notice' });
  function render() {
    const c = opts.cap(), d = c?.detect;
    const hu = isHindustani(c) && c.words?.length;
    el.hidden = !d && !hu;
    if (el.hidden) { el.replaceChildren(); return; }
    const kids = [];
    if (d) {
      const tip = d.probs ? 'Whisper heard: ' + Object.entries(d.probs).map(([k, v]) => `${k} ${Math.round(v * 100)}%`).join(' · ') : 'The language you chose';
      kids.push(h('span', { class: 'rs-cap-detect-what', title: tip }, d.chosen === 'auto' ? '🎧 Heard: ' : '🎧 Captions in: ', h('b', {}, langLabel(d))));
      if (d.alt && c.lang === d.lang) kids.push(h('button', { class: 'rs-cap-btn', 'data-x': 'relisten', title: `Hindi and Urdu sound the same; this listens again and writes it as ${NAME[d.alt]}`,
        onclick: () => opts.relisten(d.alt) }, `It's ${NAME[d.alt]}`));
    }
    if (hu) {
      const now = currentScript(c);
      kids.push(h('span', { class: 'rs-cap-scriptsw', role: 'group', 'aria-label': 'Caption script' },
        SCRIPTS.map((s) => h('button', { class: 'rs-cap-scriptbtn' + (s.id === now ? ' on' : ''), 'data-script': s.id, title: s.title, 'aria-pressed': String(s.id === now),
          onclick: () => { if (s.id !== currentScript(opts.cap())) opts.apply(setScript(s.id, opts.canvas?.())); } }, s.label))));
    }
    el.replaceChildren(...kids);
  }
  render();
  return { el, render };
}
