// KBB Studio · stock photos · the two APIs as pure functions (no DOM, no network) -- the panel and tools/stock-check.cjs
// both use them. Pexels API v1 and Unsplash API v1, with the owner's own free keys (the engine adds them; the app never
// holds them).
//
// Their rules, followed here:
//   Pexels   -- show a prominent "Photos provided by Pexels" link; credit the photographer where possible
//               (pexels.com/api/documentation, "Guidelines").
//   Unsplash -- hotlink: show the image URLs the API returns (urls.*), never re-host them for display; credit every photo
//               "Photo by <name> on Unsplash" with both links carrying utm_source=<app>&utm_medium=referral; when a photo is
//               downloaded / used, GET its links.download_location (help.unsplash.com, "API guidelines").
//   Both     -- free licences (Pexels License, Unsplash License) allow commercial use; KBB Studio still saves the
//               photographer, the licence and the source URL beside every saved photo (<file>.json).

export const SOURCES = {
  pexels: { name: 'Pexels', api: 'api.pexels.com', img: 'images.pexels.com', licence: 'Pexels License', licenceUrl: 'https://www.pexels.com/license/', home: 'https://www.pexels.com', keysUrl: 'https://www.pexels.com/api/' },
  unsplash: { name: 'Unsplash', api: 'api.unsplash.com', img: 'images.unsplash.com', licence: 'Unsplash License', licenceUrl: 'https://unsplash.com/license', home: 'https://unsplash.com', keysUrl: 'https://unsplash.com/developers' },
};
export const UTM = 'utm_source=kbb_studio&utm_medium=referral';
export const PER_PAGE = 24;
const ORIENT = { pexels: { portrait: 'portrait', landscape: 'landscape', square: 'square' }, unsplash: { portrait: 'portrait', landscape: 'landscape', square: 'squarish' } };

/** the search URL for one page (query trimmed to 100 chars; orientation optional; people adds "person" for Pexels) */
export function searchUrl(source, { query, page = 1, orientation = '', perPage = PER_PAGE } = {}) {
  const q = String(query || '').trim().slice(0, 100);
  if (!q) throw new Error('Type what to search for');
  const p = new URLSearchParams();
  if (source === 'pexels') {
    p.set('query', q); p.set('per_page', String(perPage)); p.set('page', String(page));
    if (ORIENT.pexels[orientation]) p.set('orientation', ORIENT.pexels[orientation]);
    return 'https://api.pexels.com/v1/search?' + p;
  }
  if (source === 'unsplash') {
    p.set('query', q); p.set('per_page', String(perPage)); p.set('page', String(page)); p.set('content_filter', 'high');
    if (ORIENT.unsplash[orientation]) p.set('orientation', ORIENT.unsplash[orientation]);
    return 'https://api.unsplash.com/search/photos?' + p;
  }
  throw new Error('unknown source ' + source);
}

/** the URL the "Test" button uses (one result) */
export const testUrl = (source) => searchUrl(source, { query: 'worker', perPage: 1 });

const httpsOn = (u, host) => { try { const x = new URL(u); return x.protocol === 'https:' && x.hostname === host && !x.port && !x.username ? x.href : ''; } catch { return ''; } };
const str = (v, n = 200) => (typeof v === 'string' ? v.slice(0, n) : '');
const withUtm = (u) => (u ? u + (u.includes('?') ? '&' : '?') + UTM : '');

/** one API response -> {total, pages, photos:[Photo]}; anything malformed or off its own hosts is dropped */
export function normalize(source, json) {
  const out = { total: 0, pages: 0, photos: [] };
  if (!json || typeof json !== 'object') return out;
  if (source === 'pexels') {
    out.total = +json.total_results || 0; out.pages = Math.ceil(out.total / (+json.per_page || PER_PAGE));
    for (const p of Array.isArray(json.photos) ? json.photos : []) {
      const s = p && p.src || {}, thumb = httpsOn(s.medium || s.small, 'images.pexels.com'), full = httpsOn(s.large2x || s.original, 'images.pexels.com');
      if (!p || !Number.isFinite(+p.id) || !thumb || !full) continue;
      out.photos.push({ source, id: String(+p.id), w: +p.width || 0, h: +p.height || 0, alt: str(p.alt), color: /^#[0-9a-f]{6}$/i.test(p.avg_color) ? p.avg_color : '#888888',
        thumb, full, photographer: str(p.photographer, 80) || 'Unknown', photographerUrl: httpsOn(p.photographer_url, 'www.pexels.com'), pageUrl: httpsOn(p.url, 'www.pexels.com'),
        licence: SOURCES.pexels.licence, licenceUrl: SOURCES.pexels.licenceUrl, downloadLocation: '' });
    }
  } else if (source === 'unsplash') {
    out.total = +json.total || 0; out.pages = +json.total_pages || 0;
    for (const p of Array.isArray(json.results) ? json.results : []) {
      const u = p && p.urls || {}, l = p && p.links || {}, us = p && p.user || {};
      const thumb = httpsOn(u.small, 'images.unsplash.com') || httpsOn(u.small, 'plus.unsplash.com');
      const raw = httpsOn(u.raw, 'images.unsplash.com') || httpsOn(u.raw, 'plus.unsplash.com');
      const full = raw ? raw + (raw.includes('?') ? '&' : '?') + 'w=2160&q=85&fm=jpg' : (httpsOn(u.full, 'images.unsplash.com') || httpsOn(u.regular, 'images.unsplash.com'));
      const dl = httpsOn(l.download_location, 'api.unsplash.com');
      if (!p || !/^[A-Za-z0-9_-]{1,40}$/.test(p.id || '') || !thumb || !full || !dl) continue;
      out.photos.push({ source, id: p.id, w: +p.width || 0, h: +p.height || 0, alt: str(p.alt_description || p.description), color: /^#[0-9a-f]{6}$/i.test(p.color) ? p.color : '#888888',
        thumb, full, photographer: str(us.name, 80) || 'Unknown', photographerUrl: withUtm(httpsOn(us.links && us.links.html, 'unsplash.com')), pageUrl: withUtm(httpsOn(l.html, 'unsplash.com')),
        licence: SOURCES.unsplash.licence, licenceUrl: SOURCES.unsplash.licenceUrl, downloadLocation: dl });
    }
  }
  return out;
}

/** the credit line the UI shows under a photo (and writes into the sidecar) */
export const credit = (p) => `Photo by ${p.photographer} on ${SOURCES[p.source].name}`;
export const sourceLink = (source) => SOURCES[source].home + (source === 'unsplash' ? '/?' + UTM : '');

const slug = (s) => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
/** where a downloaded photo goes: library/people/<occupation>/<source>-<id>-<photographer>.jpg (+ .json beside it) */
export function savePath(p, occupation) {
  const occ = /^[a-z0-9-]{2,40}$/.test(occupation || '') ? occupation : 'other';
  const base = `${p.source}-${String(p.id).replace(/[^A-Za-z0-9_-]/g, '')}${p.photographer ? '-' + slug(p.photographer) : ''}`;
  return { dir: `library/people/${occ}`, image: `library/people/${occ}/${base}.jpg`, sidecar: `library/people/${occ}/${base}.json` };
}

/** the sidecar JSON saved next to every photo */
export function sidecar(p, { occupation = '', query = '', downloadedFrom = '' } = {}) {
  return {
    app: 'kbb-studio', kind: 'stock-photo', source: p.source, sourceName: SOURCES[p.source].name, id: p.id,
    photographer: p.photographer, photographerUrl: p.photographerUrl, pageUrl: p.pageUrl,
    licence: p.licence, licenceUrl: p.licenceUrl, credit: credit(p), alt: p.alt,
    url: downloadedFrom || p.full, width: p.w, height: p.h, occupation: occupation || null, query, downloadedAt: new Date().toISOString(),
  };
}
