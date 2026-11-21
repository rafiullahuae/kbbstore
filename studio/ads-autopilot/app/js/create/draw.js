// Create with Claude: the local drawing shared by the box (create.js) and the Claude-app connector's render jobs
// (remote.js). Everything is drawn here, on this Mac, with the existing renderer + quality gate; a design is drawn
// again only when its spec changed (specHash), and a saved picture is named by its content, so saving twice (a
// retried tool call, a double click) gives the same library creative, never a second one.
import { api } from '../api.js';
import { renderSpec, toPNG, assets } from '../creative/render.js';
import { score } from '../creative/quality.js';

export function specHash(d) {
  const s = JSON.stringify([d.lang, d.template, d.images, d.headline, d.sub, d.bullets, d.badge, d.cta, d.price, d.palette, d.numerals, d.bg && d.bg.url, d.photoURL]);
  let h = 2166136261;
  for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); }
  return (h >>> 0).toString(16).padStart(8, '0');
}

// the owner's attached photo replaces the product photo (it stays in this browser)
export function withPhoto(d, photoURL) {
  return photoURL && d.photo ? Object.assign({}, d, { images: [photoURL], photoURL: photoURL.slice(0, 64) }) : d;
}

const shrink = (cv, w) => { const s = document.createElement('canvas'); s.width = w; s.height = Math.round(cv.height * w / cv.width); s.getContext('2d').drawImage(cv, 0, 0, s.width, s.height); return s; };

// thumb: one format drawn at full size, scored on its real pixels, shown small.
export async function thumb(d, format, w = 360) {
  const A = await assets(d);
  const cv = await renderSpec(d, format, null, { assets: A });
  const q = await score(d, format, A);
  return { url: shrink(cv, w).toDataURL('image/jpeg', 0.84), q: { ok: q.ok, score: q.score, fails: q.fails }, full: cv };
}

// creativeID: cc-<content hash>-<format> (the engine treats the same id + content as the same creative)
export const creativeID = (d, f) => `cc-${specHash(d)}${(d.id || '').replace(/[^a-z0-9]/g, '').slice(-6)}-${f.replace(':', 'x')}`;

// saveDesign: every format through the quality gate; passes go to the library. Returns {ids, refused[]}.
export async function saveDesign(d, account) {
  const out = { ids: [], refused: [] };
  const A = await assets(d);
  for (const f of d.formats || ['1:1', '4:5', '9:16']) {
    const q = await score(d, f, A);
    if (!q.ok) { out.refused.push({ design: d.id, format: f, fails: q.fails }); continue; }
    const { b64 } = await toPNG(await renderSpec(d, f, null, { assets: A }));
    const r = await api('/api/creatives', { method: 'POST', account, body: { id: creativeID(d, f), lang: d.lang, format: f, png: b64, price: d.price || 'none', layout: 2, headline: d.headline, primary_text: d.primary_text || d.sub || d.headline, cta: d.cta || 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: d.product_ids || [], angle: d.angle || '', source: 'owner' } });
    out.ids.push(r.id);
  }
  return out;
}

export async function saveVideo(d, blob, account) {
  const b64 = await new Promise((ok) => { const fr = new FileReader(); fr.onload = () => ok(String(fr.result).split(',')[1]); fr.readAsDataURL(blob); });
  const r = await api('/api/creatives', { method: 'POST', account, body: { id: creativeID(d, 'mp4'), lang: d.lang, format: '9:16', video: b64, price: d.price || 'none', layout: 2, headline: d.headline, primary_text: d.primary_text || d.sub || d.headline, cta: d.cta || 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: d.product_ids || [], angle: d.angle || '', source: 'owner' } });
  return r.id;
}

// redrawSave: the owner's "Redraw & save" (21 Nov: "give me option to re-generate everything for specific idea ...
// few ads are now missing as i didn't want to change the design"). The SAME design drawn again in every size and
// saved — including a size the quality check would hold back (he chose it; it is reported as weak, never dropped),
// which is how sizes went missing before. A picture already in the library is replaced under its own id (live ads
// follow it); an identical picture changes nothing. Returns {ids, weak[]}.
export async function redrawSave(d, account) {
  const out = { ids: [], weak: [] };
  const A = await assets(d);
  for (const f of d.formats || ['1:1', '4:5', '9:16']) {
    const q = await score(d, f, A);
    if (!q.ok) out.weak.push({ design: d.id, format: f, fails: q.fails });
    const { b64 } = await toPNG(await renderSpec(d, f, null, { assets: A }));
    const id = creativeID(d, f), price = d.price || 'none';
    const r = await api('/api/creatives', { method: 'POST', account, body: { id, lang: d.lang, format: f, png: b64, price, layout: 2, headline: d.headline, primary_text: d.primary_text || d.sub || d.headline, cta: d.cta || 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: d.product_ids || [], angle: d.angle || '', source: 'owner' } });
    await api(`/api/creatives/${encodeURIComponent(r.id)}/media`, { method: 'PUT', account, body: { png: b64, price, layout: 2 } });
    out.ids.push(r.id);
  }
  return out;
}

// redrawVideo: the design's motion version made again and saved under its own id (replaced in place when it exists).
export async function redrawVideo(d, blob, account) {
  const id = await saveVideo(d, blob, account);
  const b64 = await new Promise((ok) => { const fr = new FileReader(); fr.onload = () => ok(String(fr.result).split(',')[1]); fr.readAsDataURL(blob); });
  await api(`/api/creatives/${encodeURIComponent(id)}/media`, { method: 'PUT', account, body: { video: b64, price: d.price || 'none', layout: 2 } });
  return id;
}
