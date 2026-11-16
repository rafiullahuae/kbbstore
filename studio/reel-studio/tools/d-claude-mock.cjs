// KBB Studio · a local stand-in for the Claude Messages API (tests only; the engine is pointed at it with
// -test-claude-base http://127.0.0.1:<port>). It answers exactly the shape the engine expects and records every request.
//
// Request (what the engine sends):  POST /v1/messages
//   headers: x-api-key: <key>, anthropic-version: 2026-10-21, content-type: application/json,
//            anthropic-beta: server-side-fallback-2026-07-01 (Sonnet / Opus 5.5 only)
//   body:    {model, max_tokens, system?, messages:[{role:'user', content:<string>}],
//             output_config:{effort:'low', format?:{type:'json_schema', schema}}, fallbacks?:'default'}
// Response: {id, type:'message', role:'assistant', model, stop_reason:'end_turn', content:[{type:'thinking', thinking:''},
//            {type:'text', text:<JSON string matching the schema, or "ok" for the test>}], usage:{...}}
// Errors:   a wrong key -> 401 {type:'error', error:{type:'authentication_error', message}}
'use strict';
const http = require('http');

function startClaudeMock({ key }) {
  const log = [];
  const srv = http.createServer((req, res) => {
    let body = '';
    req.on('data', (d) => { body += d; });
    req.on('end', () => {
      let j = null; try { j = JSON.parse(body); } catch {}
      log.push({ method: req.method, url: req.url, headers: req.headers, body: j, raw: body });
      const send = (code, obj) => { res.writeHead(code, { 'content-type': 'application/json' }); res.end(JSON.stringify(obj)); };
      if (req.method !== 'POST' || req.url !== '/v1/messages') return send(404, { type: 'error', error: { type: 'not_found_error', message: 'Not found' } });
      if (req.headers['x-api-key'] !== key) return send(401, { type: 'error', error: { type: 'authentication_error', message: 'invalid x-api-key' } });
      const user = j?.messages?.[0]?.content || '';
      let text = 'ok';
      if (j?.output_config?.format && /Brief:/.test(user)) {
        const ar = /[؀-ۿ]/.test(user.split('Brief:')[1] || '');
        const isPc = /Planet Careers/.test(user);
        text = JSON.stringify(isPc ? {
          kind: 'study', occasion: '', offer: '', products: [], brands: [], countries: ['de', 'zz'], visa: 'study', intake: 'Sep', occupations: [], audience: 'graduates', tone: 'professional', languages: ['en', 'ar'], sizes: ['story', 'post'], cta: 'Book a free consultation',
          copy: [{ lang: 'en', headline: 'Your Future Starts in Germany', offer: 'September intake open', cta: 'Book a call' }, { lang: 'ar', headline: 'مستقبلك يبدأ في ألمانيا', offer: 'التسجيل مفتوح لسبتمبر', cta: 'احجز مكالمة' }, { lang: 'en', headline: 'Guaranteed visa in Germany', offer: '100% approval', cta: 'Apply' }],
        } : {
          kind: 'offer', occasion: 'uae-national-day', offer: '30% off', products: ['anua', 'anuacream', 'fakeprod'], brands: ['Anua', 'Beauty of Joseon'], countries: [], visa: '', intake: '', occupations: [], audience: 'women 20-40', tone: 'luxury', languages: ['en', 'ar'], sizes: ['story', 'post'], cta: 'Shop the offer',
          copy: [{ lang: 'en', headline: ar ? 'Glow for the Union' : 'Glow for the Union', offer: '30% OFF Anua & Beauty of Joseon', cta: 'Shop the offer' }, { lang: 'ar', headline: 'تألّقي في اليوم الوطني', offer: 'خصم 30% على أنوا وبيوتي أوف جوسون', cta: 'تسوقي الآن' }, { lang: 'en', headline: 'Mega 50% Sale', offer: '50% off everything', cta: 'Shop' }],
        });
      } else if (j?.output_config?.format && /Translate from/.test(user)) {
        const lines = JSON.parse(user.slice(user.indexOf('['))); text = JSON.stringify({ lines: lines.map((l) => '[ar] ' + l) });
      }
      send(200, { id: 'msg_mock_' + log.length, type: 'message', role: 'assistant', model: j?.model || 'claude-sonnet-5-5', stop_reason: 'end_turn', content: [{ type: 'thinking', thinking: '' }, { type: 'text', text }], usage: { input_tokens: 100, output_tokens: 50 } });
    });
  });
  return new Promise((r) => srv.listen(0, '127.0.0.1', () => r({ url: `http://127.0.0.1:${srv.address().port}`, log, close: () => srv.close() })));
}
module.exports = { startClaudeMock };
