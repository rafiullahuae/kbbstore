// KBB Studio · audio · the MUSIC LIBRARY window (the owner, 20 Nov: "upon double click on the background music, it should
// give proper library, upon click on anyone, it should preview us before apply").
//
//   openMusicLibrary(ed, { itemId, cat })   double-click a music clip on the timeline, or Inspector → 🎵 Music →
//                                           Replace song…, or search "music library"
//   closeMusicLibrary(use)                  ✓ Use this song (true) or Cancel / Esc / × (false)
//   musicLibraryState()                     what it is doing (the check script reads it)
//
// A side window docked over the right column, never over the video frame: "Preview with my video" plays the song
// under the reel, so the frame must stay in view (a centred dialog would cover it). Click a song: it plays ALONE from
// its start (one preview at a time; the row's waveform fills as it plays). "Preview with my video": the reel plays from
// the playhead with the timeline's music hushed and the song under it at his volume. Nothing in the project changes
// until ✓ Use this song, which replaces the song as ONE undo step and keeps his volume, fades, ducking and placement;
// Cancel, Esc and × change nothing. Favourites (★) are kept per company in workspaces/<company>/music-favourites.json.
import { h, clear, toast } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { findItem, findMedia } from '../core/project.js';
import { replaceSong, videoEnd, setLoop } from './music.js';

export const CATS = [
  ['all', 'All'], ['fav', '★ Favourites'], ['viral', '🔥 Viral / Trending'], ['upbeat', 'Upbeat'], ['beauty', 'Beauty & glow'], ['luxury', 'Luxury'],
  ['chill', 'Chill'], ['arabic', 'Arabic / Oud'], ['corporate', 'Corporate / Careers'], ['emotional', 'Emotional'], ['hype', 'Hype / Sale'], ['lofi', 'Lo-fi'], ['user', 'Your music'],
];
// the starter library's moods (tools/r3-music.cjs) in the window's categories
const MOOD_CATS = { luxury: ['luxury', 'beauty'], 'upbeat-pop': ['upbeat', 'hype'], 'calm-spa': ['chill', 'beauty'], 'lo-fi': ['lofi', 'chill'], corporate: ['corporate'], 'arabic-oud': ['arabic'], inspiring: ['emotional', 'corporate'], travel: ['upbeat'], cinematic: ['emotional', 'hype'], user: ['user'] };
const GENRE = { phonk: 'Phonk-lite bounce', trap: 'Trap bounce', pophouse: 'Pop house', afro: 'Afro house', futurebass: 'Future bass', lofi: 'Lo-fi hip hop', arabicpop: 'Arabic pop · darbuka', cinematic: 'Cinematic rise + drop', stomp: 'Stomp & clap', rnb: 'Glossy R&B', deephouse: 'Deep house', amapiano: 'Amapiano', baile: 'Baile funk', piano: 'Emotional piano', pizz: 'Cute pizzicato', corporate: 'Corporate motivational' };
const MOOD_GENRE = { luxury: 'Luxury lounge', 'upbeat-pop': 'Upbeat pop', 'calm-spa': 'Calm spa', 'lo-fi': 'Lo-fi', corporate: 'Corporate', 'arabic-oud': 'Arabic oud', inspiring: 'Inspiring', travel: 'Travel', cinematic: 'Cinematic', user: 'Your track' };
export const catsOf = (t) => (t.source === 'user' ? ['user'] : t.cats || MOOD_CATS[t.mood] || []);
export const genreOf = (t) => t.style || GENRE[t.genre] || MOOD_GENRE[t.mood] || t.mood || '';
const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`;
const srcOf = (t) => t.src || 'library/music/' + t.file;
const url = (src) => '/' + src.split('/').map(encodeURIComponent).join('/');
const RECT_KEY = 'kbbstudio.musicLib.rect';
let OPEN = null;

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  document.head.appendChild(h('style', { id: 'kbb-mlib-css' }, `
.mlib { position: fixed; z-index: 70; display: flex; flex-direction: column; background: var(--panel, #14151c); border: 1px solid var(--line2, #343747); border-radius: 14px; box-shadow: 0 24px 64px rgba(0,0,0,.55), 0 2px 8px rgba(0,0,0,.4); color: var(--text, #f2f3f8); overflow: hidden; }
.mlib-head { display: flex; align-items: center; gap: 8px; padding: 9px 10px 8px 14px; border-bottom: 1px solid var(--line, #242634); background: var(--panel2, #1b1c25); cursor: grab; user-select: none; touch-action: none; }
.mlib-head .t { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.mlib-head b { font-size: 13.5px; } .mlib-head small { color: var(--muted, #a9aec0); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mlib-tools { padding: 8px 10px 6px; display: flex; flex-direction: column; gap: 7px; border-bottom: 1px solid var(--line, #242634); flex: none; }
.mlib-tools input[type=search] { width: 100%; }
.mlib-cats { display: flex; flex-wrap: wrap; gap: 4px; }
.mlib-cat { border: 1px solid var(--line2, #343747); background: transparent; color: var(--muted, #a9aec0); border-radius: 999px; padding: 3px 9px; font-size: 11.5px; font-weight: 650; cursor: pointer; white-space: nowrap; }
.mlib-cat:hover { color: var(--text, #f2f3f8); background: var(--panel3, #242632); }
.mlib-cat.on { color: var(--text, #f2f3f8); background: var(--accent-soft, rgba(209,72,125,.18)); border-color: var(--accent-line, rgba(208,72,125,.6)); }
.mlib-cat small { opacity: .7; font-weight: 500; margin-left: 3px; }
.mlib-list { flex: 1; min-height: 0; overflow: auto; padding: 4px 6px; }
.mlib-row { display: grid; grid-template-columns: 30px minmax(0, 1fr) 84px 26px; align-items: center; gap: 8px; padding: 6px 6px; border-radius: 9px; cursor: pointer; }
.mlib-row:hover { background: var(--panel3, #242632); }
.mlib-row.sel { background: var(--accent-soft, rgba(209,72,125,.18)); box-shadow: inset 0 0 0 1px var(--accent-line, rgba(208,72,125,.6)); }
.mlib-row.cur .nm::after { content: ' · in use'; color: var(--ok, #3ccf91); font-weight: 600; font-size: 11px; }
.mlib-play { width: 28px; height: 28px; border-radius: 50%; border: 0; background: var(--panel4, #2d2f3d); color: var(--text, #f2f3f8); font-size: 11px; padding: 0; }
.mlib-row.playing .mlib-play { background: var(--accent, #d1487d); }
.mlib-row .nm { font-size: 13px; font-weight: 650; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mlib-row .meta { font-size: 11px; color: var(--muted, #a9aec0); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mlib-row canvas { width: 84px; height: 26px; display: block; }
.mlib-star { border: 0; background: transparent; color: var(--dim, #9298ab); font-size: 15px; padding: 0; width: 26px; height: 26px; }
.mlib-star.on { color: var(--sel, #ffd166); }
.mlib-foot { flex: none; border-top: 1px solid var(--line, #242634); padding: 8px 12px 10px; display: flex; flex-direction: column; gap: 7px; background: var(--panel2, #1b1c25); }
.mlib-foot .now { font-size: 12px; color: var(--muted, #a9aec0); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mlib-foot .now b { color: var(--text, #f2f3f8); }
.mlib-foot .acts { display: flex; gap: 8px; } .mlib-foot .acts .btn { flex: 1; }
.mlib-empty { color: var(--muted, #a9aec0); padding: 16px 10px; font-size: 12.5px; }
`));
}

// ── where it sits: docked over the right column, never over the frame (as the text-behind editor) ──
const vw = () => window.innerWidth, vh = () => window.innerHeight;
const overlaps = (a, b) => a.x < b.right && a.x + a.w > b.left && a.y < b.bottom && a.y + a.h > b.top;
function dockRect(ed) {
  const r = (ed.inspEl || document.querySelector('.inspector.side'))?.getBoundingClientRect();
  if (!r || !r.width) return { x: Math.max(8, vw() - 408), y: 70, w: 400, h: vh() - 78 };
  const w = Math.max(Math.round(r.width), 380);
  return { x: Math.round(Math.min(r.left, vw() - w - 8)), y: Math.round(r.top), w, h: Math.round(vh() - r.top - 8) };
}
function fit(rect, ed) {
  const d = dockRect(ed), w = Math.min(Math.max(340, rect.w || d.w), vw() - 16), hh = Math.min(Math.max(360, rect.h || d.h), vh() - 16);
  const r = { x: Math.round(Math.min(Math.max(8, rect.x), vw() - w - 8)), y: Math.round(Math.min(Math.max(8, rect.y), vh() - hh - 8)), w, h: hh };
  const st = ed.preview?.stage?.getBoundingClientRect();
  if (st && st.width && overlaps(r, st)) {
    if (st.right + 8 + r.w <= vw() - 8) r.x = Math.round(st.right + 8);
    else if (st.left - 8 - r.w >= 8) r.x = Math.round(st.left - 8 - r.w);
    else return d;
    if (overlaps(r, st)) return d;
  }
  return r;
}
const savedRect = () => { try { const r = JSON.parse(localStorage.getItem(RECT_KEY) || 'null'); return r && Number.isFinite(r.x) ? r : null; } catch { return null; } };
const place = (O) => Object.assign(O.el.style, { left: O.rect.x + 'px', top: O.rect.y + 'px', width: O.rect.w + 'px', height: O.rect.h + 'px' });

// ── favourites, per company ──
const favPath = (ed) => `workspaces/${ed.project.workspace || ed.workspace?.id || 'default'}/music-favourites.json`;
async function loadFavs(ed) { try { if (ed.engine?.available && await ed.engine.exists(favPath(ed))) return new Set((await ed.engine.readFile(favPath(ed), 'json')).ids || []); } catch {} return new Set(); }
function saveFavs(ed, set) { try { if (ed.engine?.available) ed.engine.writeFile(favPath(ed), { v: 1, ids: [...set] }).catch(() => {}); } catch {} }

/** open the library for music item `itemId` (no item: ✓ adds the song at the start, looped to the video's end) */
export async function openMusicLibrary(ed, opts = {}) {
  if (!ed) return null;
  if (OPEN) closeMusicLibrary(false);
  css();
  const hit = opts.itemId ? findItem(ed.project, opts.itemId) : null;
  const item = hit?.item || null, m = item?.media ? findMedia(ed.project, item.media) : null;
  const O = { ed, itemId: item?.id || null, curSrc: m?.src || null, cat: opts.cat || 'all', q: '', sel: null, playing: null, withVideo: false, startedPlay: false, tracks: [], favs: new Set(), raf: 0, offs: [] };
  OPEN = O;
  O.audio = new Audio(); O.audio.preload = 'auto';
  O.audio.addEventListener('ended', () => stopPreview(O));
  // data-kbs: the search crawl and the hover cards leave this window alone (its controls carry their own titles)
  O.el = h('div.mlib', { role: 'dialog', 'aria-label': 'Music Library', dataset: { mlib: '1', kbs: 'mlib' } });
  O.head = h('div.mlib-head', h('span', '🎵'), h('div.t', h('b', 'Music Library'), h('small', item ? `Replacing “${(item.name || m?.name || '').replace(/^Music · /, '')}” · nothing changes until ✓ Use this song` : 'Pick a song: ✓ adds it under the whole video')),
    h('button.icon-btn', { title: 'Close (Esc) — nothing changes', 'aria-label': 'Close', dataset: { mlibClose: '1' }, on: { click: () => closeMusicLibrary(false) } }, '✕'));
  O.search = h('input', { type: 'search', placeholder: 'Search: phonk, beauty, oud, 120 bpm, sale…', 'aria-label': 'Search music', dataset: { mlibSearch: '1' } });
  O.search.addEventListener('input', () => { O.q = O.search.value.trim().toLowerCase(); renderList(O); });
  O.search.addEventListener('keydown', (e) => { if (e.key !== 'Escape') e.stopPropagation(); });
  O.catsEl = h('div.mlib-cats', { role: 'tablist' });
  O.importIn = h('input', { type: 'file', accept: 'audio/*,.m4a,.mp3,.wav,.aac,.flac,.ogg', multiple: true, hidden: true, on: { change: () => importFiles(O, [...O.importIn.files]) } });
  O.list = h('div.mlib-list', { dataset: { mlibList: '1' } });
  O.withIn = h('input', { type: 'checkbox', dataset: { mlibWith: '1' }, on: { change: () => { O.withVideo = O.withIn.checked; if (O.playing) play(O, O.playing); } } });
  O.now = h('div.now', 'Click a song to hear it.');
  O.useBtn = h('button.btn.primary', { disabled: true, dataset: { mlibUse: '1' }, on: { click: () => closeMusicLibrary(true) } }, item ? '✓ Use this song' : '✓ Add this song');
  O.el.append(O.head, h('div.mlib-tools', O.search, O.catsEl), O.list,
    h('div.mlib-foot', O.now,
      h('label.row.check', { title: 'Plays the reel from the playhead with this song under it instead of the current music (at your volume)' }, O.withIn, h('span', 'Preview with my video')),
      h('div.acts', h('button.btn', { dataset: { mlibCancel: '1' }, on: { click: () => closeMusicLibrary(false) } }, 'Cancel'), O.useBtn)), O.importIn);
  document.body.appendChild(O.el);
  O.rect = fit(savedRect() || dockRect(ed), ed); place(O);
  O.onResize = () => { O.rect = fit(O.rect, ed); place(O); }; window.addEventListener('resize', O.onResize);
  O.onKey = (e) => {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMusicLibrary(false); return; }
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && O.sel) { e.preventDefault(); e.stopPropagation(); closeMusicLibrary(true); return; }
    // keys typed in the window stay in it (the editor's space = play, S = split … must not fire under it)
    if (O.el.contains(document.activeElement) && e.key === ' ' && document.activeElement?.tagName !== 'INPUT') { e.preventDefault(); e.stopPropagation(); if (O.sel) (O.playing === O.sel ? stopPreview(O) : play(O, O.sel)); }
  };
  document.addEventListener('keydown', O.onKey, true);
  // the reel stopped (its end, or space) while the song played under it: the song stops with it
  O.offs.push(bus.on('pause', () => { if (O.withVideo && O.playing && !O.switching) stopPreview(O); }));
  drag(O);
  O.list.append(h('p.mlib-empty', 'Loading the library…'));
  try { const A = await import('../auto/index.js'); O.tracks = (await A.library(ed.context())).music.slice(); } catch (e) { console.warn('[music library]', e); O.tracks = []; }
  O.favs = await loadFavs(ed);
  if (OPEN !== O) return null;
  if (O.curSrc) { const cur = O.tracks.find((t) => srcOf(t) === O.curSrc); if (cur && O.cat === 'all') O.sel = null; }
  renderCats(O); renderList(O);
  setTimeout(() => O.search.focus({ preventScroll: true }), 0);
  return O.el;
}

function drag(O) {
  O.head.addEventListener('pointerdown', (e) => {
    if (e.target.closest('button')) return;
    const x0 = e.clientX - O.rect.x, y0 = e.clientY - O.rect.y; O.head.setPointerCapture(e.pointerId);
    const mv = (ev) => { O.rect = { ...O.rect, x: Math.round(Math.min(Math.max(0, ev.clientX - x0), vw() - 60)), y: Math.round(Math.min(Math.max(0, ev.clientY - y0), vh() - 40)) }; place(O); };
    const up = () => { O.head.removeEventListener('pointermove', mv); O.head.removeEventListener('pointerup', up); O.rect = fit(O.rect, O.ed); place(O); try { localStorage.setItem(RECT_KEY, JSON.stringify(O.rect)); } catch {} };
    O.head.addEventListener('pointermove', mv); O.head.addEventListener('pointerup', up);
  });
}

const matches = (O, t) => {
  const c = O.cat;
  if (c === 'fav' ? !O.favs.has(t.id) : c !== 'all' && !catsOf(t).includes(c)) return false;
  if (!O.q) return true;
  const hay = [t.title, t.mood, genreOf(t), ...(t.tags || []), ...catsOf(t).map((k) => CATS.find((x) => x[0] === k)?.[1] || k), t.bpm ? Math.round(t.bpm) + ' bpm' : '', t.energy, t.key].join(' ').toLowerCase();
  return O.q.split(/\s+/).every((w) => hay.includes(w));
};
function renderCats(O) {
  clear(O.catsEl);
  for (const [k, label] of CATS) {
    const n = O.tracks.filter((t) => (k === 'all' ? true : k === 'fav' ? O.favs.has(t.id) : catsOf(t).includes(k))).length;
    if (k === 'fav' && !n && O.cat !== 'fav') continue;
    O.catsEl.append(h('button.mlib-cat' + (O.cat === k ? '.on' : ''), { role: 'tab', title: k === 'all' ? 'Every song' : k === 'fav' ? 'Your ★ songs for this company' : k === 'user' ? 'Songs you imported (library/music/user)' : `${label.replace(/^\W+\s*/, '')} songs`, 'aria-selected': String(O.cat === k), dataset: { mlibCat: k }, on: { click: () => { O.cat = k; renderCats(O); renderList(O); } } }, label, h('small', String(n))));
  }
}
function renderList(O) {
  clear(O.list);
  if (O.cat === 'user') O.list.append(h('button.btn.wide', { dataset: { mlibImport: '1' }, title: 'Copies a song from your Mac into library/music/user (use only music you have the rights to)', on: { click: () => O.importIn.click() } }, '＋ Import a song…'));
  const rows = O.tracks.filter((t) => matches(O, t));
  if (!rows.length) { O.list.append(h('p.mlib-empty', O.cat === 'user' ? 'No songs of your own yet: import one (only music you have the rights to).' : O.cat === 'fav' ? 'No favourites yet: press ☆ on a song.' : 'No song matches. Try another word or “All”.')); return; }
  for (const t of rows) O.list.append(row(O, t));
}
function row(O, t) {
  const src = srcOf(t), cv = h('canvas', { width: 168, height: 52, 'aria-hidden': 'true' });
  const el = h('div.mlib-row' + (O.sel === t ? '.sel' : '') + (O.playing === t ? '.playing' : '') + (src === O.curSrc ? '.cur' : ''), { dataset: { mlibTrack: t.id }, title: `${t.title} — click to hear it alone` },
    h('button.mlib-play', { 'aria-label': (O.playing === t ? 'Stop ' : 'Play ') + t.title, on: { click: (e) => { e.stopPropagation(); select(O, t); if (O.playing === t) stopPreview(O); else play(O, t); } } }, O.playing === t ? '■' : '▶'),
    h('div', h('div.nm', t.title), h('div.meta', [genreOf(t), t.bpm ? Math.round(t.bpm) + ' bpm' : '', t.duration ? mmss(t.duration) : ''].filter(Boolean).join(' · '))),
    cv,
    h('button.mlib-star' + (O.favs.has(t.id) ? '.on' : ''), { title: O.favs.has(t.id) ? 'Remove from this company’s favourites' : 'Add to this company’s favourites', 'aria-label': 'Favourite', dataset: { mlibStar: t.id }, on: { click: (e) => { e.stopPropagation(); toggleFav(O, t); } } }, O.favs.has(t.id) ? '★' : '☆'));
  el.addEventListener('click', () => { select(O, t); if (O.playing !== t) play(O, t); });
  el.addEventListener('dblclick', () => { select(O, t); closeMusicLibrary(true); });
  t._cv = cv; drawWave(t, O.playing === t ? progress(O) : 0);
  return el;
}
function drawWave(t, prog) {
  const cv = t._cv; if (!cv) return;
  const g = cv.getContext('2d'), W = cv.width, H = cv.height, w = t.wave || '', n = w.length || 32;
  g.clearRect(0, 0, W, H);
  const acc = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#d1487d';
  for (let i = 0; i < n; i++) {
    const v = w ? parseInt(w[i], 36) / 35 : 0.35, bh = Math.max(2, v * (H - 4)), x = (i * W) / n;
    g.fillStyle = i / n < prog ? acc : 'rgba(255,255,255,.38)';
    g.fillRect(x + 0.5, (H - bh) / 2, Math.max(1, W / n - 1.5), bh);
  }
}
const progress = (O) => (O.audio.duration ? O.audio.currentTime / O.audio.duration : 0);

function select(O, t) {
  O.sel = t; O.useBtn.disabled = false;
  for (const r of O.list.querySelectorAll('.mlib-row')) r.classList.toggle('sel', r.dataset.mlibTrack === t.id);
  const it = O.itemId && findItem(O.ed.project, O.itemId)?.item;
  O.now.replaceChildren('Selected: ', h('b', t.title), ` · ${genreOf(t)}${it ? ` · your volume ${Math.round((it.kf?.volume?.length ? Math.max(...it.kf.volume.map((k) => k[1])) : (it.volume ?? 1)) * 100)} % is kept` : ''}`);
}
/** play `t`: alone from its start, or (Preview with my video) under the reel from the playhead */
function play(O, t) {
  const ed = O.ed, prev = O.playing;
  O.switching = true;
  O.audio.pause();
  if (O.startedPlay && !O.withVideo) { ed.pause(); O.startedPlay = false; }
  ed.preview.hushMusic = false;
  O.playing = t;
  if (prev !== t || O.audio.dataset.src !== srcOf(t)) { O.audio.src = url(srcOf(t)); O.audio.dataset.src = srcOf(t); }
  const it = O.itemId && findItem(ed.project, O.itemId)?.item;
  if (O.withVideo) {
    // the song under the reel, where the music clip would play it from (its start at the clip's start), at his volume
    const t0 = it ? it.t0 : 0, vol = it ? (it.kf?.volume?.length ? Math.max(...it.kf.volume.map((k) => k[1])) : (it.volume ?? 1)) : 1;
    let at = Math.max(0, ed.t - t0); if (t.duration && at >= t.duration) at = at % t.duration;
    O.audio.currentTime = at; O.audio.volume = Math.max(0, Math.min(1, it?.muted ? 0 : vol));
    ed.preview.hushMusic = true;
    if (!ed.playing) { ed.play(); O.startedPlay = true; }
  } else { O.audio.currentTime = 0; O.audio.volume = 1; if (ed.playing) ed.pause(); O.startedPlay = false; }
  O.switching = false;
  O.audio.play().catch((e) => { console.warn('[music library] preview', e.message); });
  refreshRows(O);
  cancelAnimationFrame(O.raf);
  const tick = () => { if (OPEN !== O || O.playing !== t) return; drawWave(t, progress(O)); O.raf = requestAnimationFrame(tick); };
  O.raf = requestAnimationFrame(tick);
}
function stopPreview(O) {
  const t = O.playing;
  O.audio.pause(); cancelAnimationFrame(O.raf); O.raf = 0;
  O.ed.preview.hushMusic = false;
  if (O.startedPlay) { O.ed.pause(); O.startedPlay = false; }
  O.playing = null;
  if (t) drawWave(t, 0);
  refreshRows(O);
}
function refreshRows(O) {
  for (const r of O.list.querySelectorAll('.mlib-row')) {
    const on = O.playing?.id === r.dataset.mlibTrack; r.classList.toggle('playing', on);
    const b = r.querySelector('.mlib-play'); b.textContent = on ? '■' : '▶';
  }
}
function toggleFav(O, t) {
  if (O.favs.has(t.id)) O.favs.delete(t.id); else O.favs.add(t.id);
  saveFavs(O.ed, O.favs); renderCats(O);
  if (O.cat === 'fav') renderList(O);
  else { const b = O.list.querySelector(`[data-mlib-star="${CSS.escape(t.id)}"]`); if (b) { b.classList.toggle('on', O.favs.has(t.id)); b.textContent = O.favs.has(t.id) ? '★' : '☆'; } }
}
async function importFiles(O, files) {
  const ed = O.ed; if (!files.length) return;
  if (!ed.engine?.available) { toast('Importing needs the studio engine running', 'error'); return; }
  let n = 0;
  for (const f of files) {
    if (!/\.(m4a|mp3|wav|aac|ogg|flac)$/i.test(f.name)) { toast(`${f.name}: not an audio file`, 'error'); continue; }
    const name = f.name.replace(/[^\w.\- ]+/g, '_').replace(/^\.+/, '').slice(0, 120) || 'song.m4a';
    try { await ed.engine.writeFile('library/music/user/' + name, f); n++; } catch (e) { toast(`${f.name}: ${e.message}`, 'error'); }
  }
  O.importIn.value = '';
  if (!n) return;
  toast(`Imported ${n} song${n === 1 ? '' : 's'} into Your music`, 'ok');
  const A = await import('../auto/index.js'); A.resetLibrary(); O.tracks = (await A.library(ed.context())).music.slice();
  O.cat = 'user'; renderCats(O); renderList(O);
}

/** ✓ Use this song (true): ONE undo step. Cancel (false): nothing in the project changes. Either way the preview stops. */
export async function closeMusicLibrary(use) {
  const O = OPEN; if (!O) return null;
  OPEN = null;
  stopPreview(O);
  O.audio.removeAttribute('src'); try { O.audio.load(); } catch {}
  document.removeEventListener('keydown', O.onKey, true);
  window.removeEventListener('resize', O.onResize);
  for (const off of O.offs) { try { off?.(); } catch {} }
  O.el.remove();
  const ed = O.ed, t = O.sel;
  if (!use || !t) return null;
  try {
    let r;
    if (O.itemId && findItem(ed.project, O.itemId)) r = await replaceSong(ed.context(), O.itemId, t);
    else r = await addSong(ed, t);
    ed.select([r.id]); ed.inspector?.render?.(true);
    toast(`🎵 ${t.title}${r.looped ? ' · loops to fill the video' : ''} · ⌘Z undoes it in one step`, 'ok');
    return r;
  } catch (e) { toast(e.message, 'error'); return null; }
}
/** no music clip yet: the song at 0 on the music track, as long as the video (looped if shorter) — one undo step */
async function addSong(ed, t) {
  const H = ed.history; H.begin(`🎵 ${t.title}`);
  try {
    let m = ed.project.media.find((x) => x.src === srcOf(t)); if (!m) m = await ed.media.importPath(srcOf(t));
    const it = ed.addMediaToTimeline(m, { t0: 0 });
    if (it && (m.dur || 0) < videoEnd(ed.project) - 0.05) await setLoop(ed.context(), it.id, true);
    else if (it) { const end = videoEnd(ed.project); if (end > 0.5 && end < it.out - it.in) ed.history.apply(ed.ops.setItems([{ id: it.id, props: { out: +end.toFixed(3), fadeOut: 1.5 } }], 'Fit music')); }
    return { id: it.id, looped: !!findItem(ed.project, it.id)?.item.loop };
  } finally { H.end(); }
}
export const musicLibraryState = () => (OPEN ? { open: true, itemId: OPEN.itemId, cat: OPEN.cat, selected: OPEN.sel?.id || null, playing: OPEN.playing?.id || null, paused: OPEN.audio.paused, src: OPEN.audio.dataset.src || null, time: OPEN.audio.currentTime, volume: OPEN.audio.volume, withVideo: OPEN.withVideo, rows: OPEN.list.querySelectorAll('.mlib-row').length, el: OPEN.el } : { open: false });
