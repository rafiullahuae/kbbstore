// KBB Studio · a local stand-in for the Pexels and Unsplash APIs (TESTS ONLY -- this machine cannot reach them).
//
// The engine runs with  -test-photo-base http://127.0.0.1:<port>  and sends every photo-host request here as
// /<host><path>?<query>  (engine/photos.go). The responses follow the real APIs' documented shapes:
//
//   GET /api.pexels.com/v1/search?query&per_page&page[&orientation]      header  Authorization: <key>
//       -> { page, per_page, total_results, next_page, photos: [{ id, width, height, url, photographer, photographer_url,
//            photographer_id, avg_color, alt, src: { original, large2x, large, medium, small, portrait, landscape, tiny } }] }
//       401 { "error": ... } for a wrong / missing key                         (pexels.com/api/documentation)
//   GET /api.unsplash.com/search/photos?query&per_page&page[&orientation][&content_filter]
//       headers Authorization: Client-ID <access key>, Accept-Version: v1
//       -> { total, total_pages, results: [{ id, width, height, color, description, alt_description,
//            urls: { raw, full, regular, small, thumb }, links: { self, html, download, download_location },
//            user: { name, username, links: { html } } }] }
//       401 { "errors": ["OAuth error: The access token is invalid"] }       (unsplash.com/documentation)
//   GET /api.unsplash.com/photos/<id>/download?ixid=...  -> { url }  (the "trigger a download" endpoint; logged)
//   GET /images.pexels.com/... , /images.unsplash.com/...  -> image/jpeg (the pictures in tools/out/stock/pics/)
//   GET /__log -> every request seen (path, auth header, accept-version), for the check
//
//   const mock = await startMock({ pexelsKey, unsplashKey, pics: [file.jpg, ...] });  mock.url, mock.log, mock.close()
'use strict';
const http = require('http');
const fs = require('fs');

const NAMES = ['Amara Okafor', 'Rahul Verma', 'Maria Santos', 'Jonas Weber', 'Aisha Rahman', 'Kenji Sato', 'Lucía Gómez', 'Tomasz Nowak'];

function startMock({ pexelsKey, unsplashKey, pics }) {
  const log = [];
  const imgs = pics.map((f) => fs.readFileSync(f));
  const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x'), parts = u.pathname.split('/'), host = parts[1], path = '/' + parts.slice(2).join('/');
    log.push({ host, path, query: u.search, auth: req.headers.authorization || '', ver: req.headers['accept-version'] || '' });
    const json = (code, o) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
    if (u.pathname === '/__log') return json(200, log);
    const q = u.searchParams, per = Math.min(80, +q.get('per_page') || 15), page = +q.get('page') || 1, query = q.get('query') || '';
    const total = 61, n = Math.max(0, Math.min(per, total - (page - 1) * per));
    if (host === 'api.pexels.com' && path === '/v1/search') {
      if (req.headers.authorization !== pexelsKey) return json(401, { error: 'Please provide a valid API key' });
      const photos = Array.from({ length: n }, (_, i) => {
        const id = 1000000 + (page - 1) * per + i, k = id % imgs.length, nm = NAMES[id % NAMES.length], b = `https://images.pexels.com/photos/${id}/pexels-photo-${id}.jpeg`;
        return { id, width: 3000, height: 3750, url: `https://www.pexels.com/photo/${query.replace(/\W+/g, '-')}-${id}/`, photographer: nm, photographer_url: `https://www.pexels.com/@${nm.toLowerCase().replace(/\W+/g, '-')}`, photographer_id: id, avg_color: '#5A6B7C', alt: `${query} (mock ${k + 1})`, liked: false,
          src: { original: b, large2x: b + '?auto=compress&cs=tinysrgb&dpr=2&h=650&w=940', large: b + '?auto=compress&cs=tinysrgb&h=650&w=940', medium: b + '?auto=compress&cs=tinysrgb&h=350', small: b + '?auto=compress&cs=tinysrgb&h=130', portrait: b + '?auto=compress&cs=tinysrgb&fit=crop&h=1200&w=800', landscape: b + '?auto=compress&cs=tinysrgb&fit=crop&h=627&w=1200', tiny: b + '?auto=compress&cs=tinysrgb&dpr=1&fit=crop&h=200&w=280' } };
      });
      return json(200, { page, per_page: per, total_results: total, photos, next_page: page * per < total ? `https://api.pexels.com/v1/search/?page=${page + 1}&per_page=${per}&query=${encodeURIComponent(query)}` : undefined });
    }
    if (host === 'api.unsplash.com') {
      if (req.headers.authorization !== 'Client-ID ' + unsplashKey) return json(401, { errors: ['OAuth error: The access token is invalid'] });
      if (path === '/search/photos') {
        const results = Array.from({ length: n }, (_, i) => {
          const no = (page - 1) * per + i, id = 'mOcK' + String(no).padStart(7, '0'), nm = NAMES[(no + 3) % NAMES.length], un = nm.toLowerCase().replace(/\W+/g, ''), raw = `https://images.unsplash.com/photo-${1700000000000 + no}?ixid=M3x${no}&ixlib=rb-4.0.3`;
          return { id, width: 4000, height: 5000, color: '#40566B', description: null, alt_description: `${query} (mock ${no % imgs.length + 1})`,
            urls: { raw, full: raw + '&q=85&fm=jpg&crop=entropy&cs=srgb', regular: raw + '&q=80&w=1080', small: raw + '&q=80&w=400', thumb: raw + '&q=80&w=200' },
            links: { self: `https://api.unsplash.com/photos/${id}`, html: `https://unsplash.com/photos/${id}`, download: `https://unsplash.com/photos/${id}/download?ixid=M3x${no}`, download_location: `https://api.unsplash.com/photos/${id}/download?ixid=M3x${no}` },
            user: { name: nm, username: un, links: { html: `https://unsplash.com/@${un}` } } };
        });
        return json(200, { total, total_pages: Math.ceil(total / per), results });
      }
      const m = /^\/photos\/([\w-]+)\/download$/.exec(path);
      if (m) return json(200, { url: `https://images.unsplash.com/photo-x?ixid=${q.get('ixid')}` });
      return json(404, { errors: ['Not found'] });
    }
    if (host === 'images.pexels.com' || host === 'images.unsplash.com' || host === 'plus.unsplash.com') {
      const k = Math.abs([...path].reduce((a, c) => a * 31 + c.charCodeAt(0) | 0, 7)) % imgs.length;
      res.writeHead(200, { 'Content-Type': 'image/jpeg', 'Content-Length': imgs[k].length }); return res.end(imgs[k]);
    }
    json(404, { error: 'mock: unknown ' + host + path });
  });
  return new Promise((r) => srv.listen(0, '127.0.0.1', () => r({ url: 'http://127.0.0.1:' + srv.address().port, log, close: () => new Promise((c) => srv.close(c)) })));
}
module.exports = { startMock };
