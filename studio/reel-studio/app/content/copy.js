// KBB Studio · content · post copy writer: the Instagram/TikTok caption, search keywords, 5 targeted hashtags and
// comment prompts, in English AND Arabic, written OFFLINE by a local language model in the browser
//   WebGPU (Mac GPU):  Qwen2.5-1.5B-Instruct, 4-bit   (models/manifest.json 'copy-llm')
//   WASM (CPU):        Qwen2.5-0.5B-Instruct, int8    ('copy-llm-lite')
// with a rule-based writer that always works (no model installed, or a reply that does not parse / breaks the rules).
import { engine } from '../core/engine.js';
import { exportsDir } from '../core/config.js';
import { catalogueFor } from './lib/catalogue.js';
import { keywordHits, topTerms } from './lib/keywords.js';
import { ops } from './lib/items.js';

export const MODELS = {
  gpu: { id: 'copy-llm', repo: 'onnx-community/Qwen2.5-1.5B-Instruct', device: 'webgpu', dtype: 'q4f16' },
  cpu: { id: 'copy-llm-lite', repo: 'onnx-community/Qwen2.5-0.5B-Instruct', device: 'wasm', dtype: 'q8' },
};
const AR_RE = /[؀-ۿ]/;

// ── facts about the reel the copy is written from ──
export async function copyFacts(ctx) {
  const p = ctx.project, ws = ctx.workspace || {};
  const words = p.captions?.words || [];
  const text = words.map((w) => w.w).join(' ').replace(/\s+([,.!?])/g, '$1');
  const cat = await catalogueFor(ws);
  const hits = keywordHits(words, { ...cat, minGap: 0 });
  const uniq = (a) => [...new Set(a.filter(Boolean))];
  const products = uniq(hits.filter((h) => h.kind === 'product' && h.strong).map((h) => h.label)).slice(0, 3);
  const countries = uniq(hits.filter((h) => h.kind === 'country').map((h) => h.item.code)).map((c) => cat.countries.find((x) => x.code === c)).filter(Boolean).slice(0, 3);
  const prices = uniq(hits.filter((h) => h.kind === 'price').map((h) => h.label)).slice(0, 2);
  const sentences = text.split(/(?<=[.!?؟])\s+/).filter((s) => s.length > 8);
  const pc = /planet|consult|immigra|visa|study/i.test(`${ws.id} ${ws.industry}`);
  const cta = [].concat(ws.endCard?.cta || [])[0] || (pc ? 'Book your free consultation' : 'Shop now · link in bio');
  return { text: text.slice(0, 1600), sentences, products, countries, prices, terms: topTerms(text, 12, [ws.name || '']), ws: { name: ws.fullName || ws.name || 'our brand', industry: ws.industry || '', contact: ws.contact || '', id: ws.id || p.workspace },
    pc, cta, series: p.meta?.content?.series || null, recipe: p.recipe || '' };
}

const camel = (s) => String(s).normalize('NFKD').replace(/[^\p{L}\p{N}\s]/gu, ' ').trim().split(/\s+/).map((w) => w[0].toUpperCase() + w.slice(1)).join('');
const arTag = (s) => String(s).replace(/[^\p{L}\p{N}\s]/gu, ' ').trim().replace(/\s+/g, '_');
export function cleanTags(list, max = 5) {
  const out = [];
  for (let t of list || []) {
    t = String(t || '').trim().replace(/^#+/, ''); if (!t) continue;
    t = AR_RE.test(t) ? arTag(t) : camel(t); if (!t || t.length > 30) continue;
    if (!out.some((x) => x.toLowerCase() === ('#' + t).toLowerCase())) out.push('#' + t);
    if (out.length >= max) break;
  }
  return out;
}

const AR_WORDS = { skin: 'البشرة', skincare: 'العناية بالبشرة', serum: 'سيروم', cream: 'كريم', sunscreen: 'واقي الشمس', glow: 'النضارة', routine: 'روتين', visa: 'التأشيرة', study: 'الدراسة', student: 'الطلاب', university: 'الجامعة', documents: 'المستندات', consultation: 'استشارة' };

/** the rule-based writer (always available) */
export function ruleCopy(f, lang = 'en') {
  const hook = f.sentences.find((s) => /\?|you|your|how|why|secret|best|never|free|only/i.test(s)) || f.sentences[0] || '';
  const brandTag = camel(f.ws.name);
  if (lang === 'ar') {
    const c = f.countries[0];
    const head = f.series ? `${f.series.title} 🎓` : f.pc ? (c ? `تبي تدرس في ${c.nameAr || c.name}؟ 🎓` : 'حلمك بالدراسة في الخارج يبدأ من هنا 🎓') : (f.products[0] ? `سر البشرة النضرة: ${f.products[0]} ✨` : 'روتين بشرة كوري يغير كل شيء ✨');
    const body = f.pc ? 'كل الخطوات والمستندات اللي تحتاجها، باختصار وبدون تعقيد.' : `منتجات كورية أصلية${f.prices[0] ? ' بسعر ' + f.prices[0] : ''}، توصيل لكل الإمارات.`;
    const cta = f.pc ? 'احجز استشارتك المجانية اليوم 📩' : 'اطلبي الآن، الرابط في البايو 🛍️';
    const kw = f.pc ? ['الدراسة في الخارج', 'تأشيرة دراسة', c ? `الدراسة في ${c.nameAr || c.name}` : 'منح دراسية', 'استشارة تعليمية', 'قبول جامعي'] : ['العناية بالبشرة', 'منتجات كورية', 'روتين البشرة', 'سكين كير دبي', f.products[0] || 'سيروم'];
    const tags = cleanTags([f.ws.name.includes(' ') ? brandTag : f.ws.name, ...(f.pc ? ['الدراسة في الخارج', 'تأشيرة', c ? (c.nameAr || c.name) : 'منح دراسية', 'دبي'] : ['العناية بالبشرة', 'منتجات كورية', 'دبي', 'روتين البشرة'])]);
    const comments = f.pc ? ['اكتب "فيزا" ونرسل لك قائمة المستندات 📄', 'أي دولة تحلم تدرس فيها؟ 🌍', 'منشن صديقك اللي يخطط للدراسة بالخارج 👇'] : ['اكتبي "نضارة" ونرسل لك الروتين كامل ✨', 'أي منتج تجربين أول؟ 1 أو 2 أو 3؟', 'منشني صديقتك اللي تحتاج هذا الروتين 💕'];
    return { caption: `${head}\n${body}\n${cta}`, keywords: kw, hashtags: tags, comments };
  }
  const ent = [...f.products, ...f.countries.map((c) => c.name), ...f.prices];
  const head = f.series ? `${f.series.title}: ${hook.slice(0, 90)}` : hook.slice(0, 120);
  const body = ent.length ? '✨ ' + ent.slice(0, 3).join(' · ') : (f.terms.slice(0, 3).join(' · '));
  const geo = f.pc ? 'StudyAbroad' : 'DubaiSkincare';
  const topic = f.pc ? (f.countries[0] ? 'StudyIn' + camel(f.countries[0].name) : 'StudentVisa') : (f.products[0] ? camel(f.products[0].split(' ').slice(1, 3).join(' ')) : 'KBeauty');
  const niche = f.pc ? 'StudentVisa' : 'KoreanSkincare';
  const tags = cleanTags([brandTag, topic, niche, geo, f.series?.hashtag || (f.pc ? 'VisaTips' : 'SkincareRoutine'), f.terms[0]]);
  const keywords = [...new Set([...ent.map((e) => e.toLowerCase()), ...(f.pc ? ['study abroad', 'student visa', 'visa requirements'] : ['korean skincare dubai', 'skincare routine', 'k-beauty uae']), ...f.terms.slice(0, 4)])].slice(0, 8);
  const word = (f.pc ? 'VISA' : (f.terms.find((t) => t.length > 3 && t.length < 9) || 'GLOW')).toUpperCase();
  const comments = [`Comment "${word}" and we'll send you the full ${f.pc ? 'checklist' : 'routine'} 📩`, f.pc ? 'Which country is on your list? 🌍' : 'Which one would you try first: 1, 2 or 3?', `Tag a friend who ${f.pc ? 'wants to study abroad' : 'needs this routine'} 👇`];
  return { caption: `${head}\n${body}\n${f.cta} 👉`, keywords, hashtags: tags, comments };
}

// ── the model ──
let W = null, seq = 0; const pending = new Map(), onProg = new Map();
function worker() {
  if (W) return W;
  W = new Worker(new URL('./llm-worker.js', import.meta.url), { type: 'module' });
  W.onmessage = (e) => { const d = e.data; if (d.progress) { onProg.get(d.id)?.(d.progress); return; } const p = pending.get(d.id); if (!p) return; pending.delete(d.id); onProg.delete(d.id); d.error ? p.rej(new Error(d.error)) : p.res(d.result); };
  W.onerror = (e) => { e.preventDefault?.(); for (const p of pending.values()) p.rej(new Error(e.message || 'writer failed')); pending.clear(); W = null; };
  return W;
}
const call = (msg, prog) => new Promise((res, rej) => { const id = ++seq; pending.set(id, { res, rej }); if (prog) onProg.set(id, prog); worker().postMessage({ id, ...msg }); });

/** which model this Mac can run AND has installed: {id, repo, device, dtype} or null */
export async function pickModel(prefer = 'auto') {
  let gpu = false;
  if (prefer !== 'cpu' && navigator.gpu) { try { const a = await navigator.gpu.requestAdapter(); gpu = !!a && (a.features?.has?.('shader-f16') ?? false); } catch {} }
  let st; try { st = await engine.modelsStatus(); } catch { return null; }
  const have = (id) => !!st.models.find((m) => m.id === id)?.installed;
  if (gpu && have(MODELS.gpu.id)) return MODELS.gpu;
  if (have(MODELS.cpu.id)) return MODELS.cpu;
  return null;
}

function prompt(f, lang) {
  const L = lang === 'ar' ? 'Arabic (Gulf-friendly Modern Standard Arabic, Arabic script)' : 'English';
  const facts = [`Company: ${f.ws.name} (${f.ws.industry || (f.pc ? 'study abroad / visa consultancy' : 'Korean skincare shop in the UAE')})`,
    f.series ? `Series episode: ${f.series.title}` : '', f.products.length ? `Products: ${f.products.join('; ')}` : '', f.countries.length ? `Countries: ${f.countries.map((c) => c.name).join(', ')}` : '',
    f.prices.length ? `Prices: ${f.prices.join(', ')}` : '', `Call to action: ${f.cta}`, `What is said in the video: "${f.text.slice(0, 900)}"`].filter(Boolean).join('\n');
  const ex = lang === 'ar'
    ? '{"caption": "بشرة ناعمة في ٧ أيام ✨\\nكريم كوري خفيف يرطب طول اليوم.\\nاطلبي الآن، الرابط في البايو 🛍️", "keywords": ["كريم مرطب", "عناية بالبشرة دبي"], "hashtags": ["#عناية_بالبشرة", "#دبي"], "comments": ["أي منتج تجربين أول؟"]}'
    : '{"caption": "Soft skin in 7 days ✨\\nA light Korean cream that hydrates all day.\\nShop now, link in bio 🛍️", "keywords": ["korean moisturiser", "skincare dubai"], "hashtags": ["#KoreanSkincare", "#DubaiSkincare"], "comments": ["Which one would you try first?"]}';
  return [
    { role: 'system', content: `You write short, punchy Instagram and TikTok post copy in ${L}. You reply with ONE JSON object only, in this shape (an example of the shape; never copy its words):\n${ex}` },
    { role: 'user', content: `${facts}\n\nWrite the post for this video in ${L}: "caption" = a hook line, one line of value, then the call to action (under 300 characters, 1-3 emoji); "keywords" = 6 short search phrases; "hashtags" = EXACTLY 5 hashtags for the topic, the audience and the place (no #love, #instagood); "comments" = 3 questions that make people comment. Never promise guaranteed results or visas.` },
  ];
}
/** the start of the reply, written for the model so even a small one stays in the JSON shape */
export const REPLY_PREFIX = '{"caption": "';
export function parseReply(text) {
  const s = String(text || ''), a = s.indexOf('{'), b = s.lastIndexOf('}');
  if (a < 0 || b <= a) return null;
  try { return JSON.parse(s.slice(a, b + 1)); } catch { try { return JSON.parse(s.slice(a, b + 1).replace(/,\s*([}\]])/g, '$1').replace(/[“”]/g, '"')); } catch { return null; } }
}
const SAFE = [[/guarantee(d)?\s+(visa|approval|results?)/gi, 'real support for your $2'], [/100\s*%\s*(approval|success|guaranteed)/gi, 'strong $1 record'], [/\bcures?\b/gi, 'cares for']];
const safe = (s) => SAFE.reduce((x, [r, w]) => x.replace(r, w), String(s));

/** merge a model reply with the rules: every field validated, anything missing or broken comes from the rules */
const EXAMPLE_WORDS = /soft skin in 7 days|light korean cream that hydrates|بشرة ناعمة في ٧ أيام|كريم كوري خفيف/i;
/** a small model drifts: a caption must be about THIS reel (names a product, country, price, the company or a topic
 *  word), must not repeat itself or copy the prompt's example, and must fit a post */
export function grounded(text, f) {
  const s = String(text || ''), low = s.toLowerCase();
  if (!s.trim() || s.length > 700 || EXAMPLE_WORDS.test(s)) return false;
  const sents = s.split(/[.!?؟\n]+/).map((x) => x.trim()).filter((x) => x.length > 12);
  if (new Set(sents).size < sents.length) return false;
  if (!f) return true;
  const terms = [f.ws.name, ...f.products.flatMap((p) => p.split(' ').slice(0, 2)), ...f.countries.flatMap((c) => [c.name, c.nameAr]), ...f.prices, ...f.terms.slice(0, 6),
    ...(f.pc ? ['visa', 'study', 'تأشيرة', 'الدراسة', 'فيزا'] : ['skin', 'serum', 'بشرة', 'البشرة', 'سيروم'])].filter((x) => x && String(x).length > 2);
  return terms.some((t) => low.includes(String(t).toLowerCase()));
}

export function finish(reply, rules, lang, f = null) {
  const okText = (s) => typeof s === 'string' && s.trim().length > 10 && (lang !== 'ar' || AR_RE.test(s)) && grounded(s, null);
  const arr = (a) => (Array.isArray(a) ? a.map(String).map((x) => x.trim()).filter(Boolean) : []);
  const out = { caption: okText(reply?.caption) && grounded(reply.caption, f) ? safe(reply.caption.trim()).slice(0, 600) : rules.caption, keywords: arr(reply?.keywords).slice(0, 8), hashtags: cleanTags(arr(reply?.hashtags)), comments: arr(reply?.comments).filter(okText).map(safe).slice(0, 3) };
  const used = { caption: out.caption !== rules.caption, keywords: out.keywords.length >= 3, hashtags: out.hashtags.length >= 3, comments: out.comments.length >= 2 };
  if (!used.keywords) out.keywords = rules.keywords;
  if (out.hashtags.length < 5) out.hashtags = cleanTags([...out.hashtags, ...rules.hashtags]);
  if (!used.comments) out.comments = rules.comments;
  out.from = Object.values(used).some(Boolean) ? 'model' : 'rules';
  out.fromModel = Object.keys(used).filter((k) => used[k]);
  return out;
}

/**
 * Write the post copy (EN + AR). opts: { useModel=true, prefer:'auto'|'cpu', onProgress, save=true }.
 * Returns { en, ar, model:{id,device}|null, ms }.
 */
export async function writeCopy(ctx, opts = {}) {
  const f = await copyFacts(ctx), t0 = performance.now();
  const res = { model: null, ms: 0 };
  const m = opts.useModel === false ? null : await pickModel(opts.prefer);
  if (m) {
    try {
      opts.onProgress?.('Loading the writing model…', 0);
      await call({ op: 'load', repo: m.repo, device: m.device, dtype: m.dtype, modelsUrl: new URL('/models/', location.href).href, vendorUrl: new URL('../../vendor/transformers/', import.meta.url).href },
        (p) => opts.onProgress?.('Loading the writing model…', p.total ? p.loaded / p.total : null));
      res.model = { id: m.id, device: m.device };
    } catch (e) { console.warn('[content] writing model unavailable, using the rule-based writer:', e.message); }
  }
  for (const lang of ['en', 'ar']) {
    const rules = ruleCopy(f, lang);
    let reply = null, raw = '';
    if (res.model) {
      opts.onProgress?.(lang === 'ar' ? 'Writing the Arabic post…' : 'Writing the English post…', null);
      try { const r = await call({ op: 'gen', messages: prompt(f, lang), prefix: REPLY_PREFIX, max: lang === 'ar' ? 420 : 320 }); raw = r.text; reply = parseReply(raw); } catch (e) { console.warn('[content] writer:', e.message); }
    }
    res[lang] = { ...finish(reply, rules, lang, f), raw: raw.slice(0, 2000) };
  }
  res.ms = Math.round(performance.now() - t0);
  const p = ctx.project;
  if (opts.save !== false) {
    const txt = ['en', 'ar'].map((l) => `── ${l === 'en' ? 'English' : 'العربية'} ──\n${res[l].caption}\n\n${res[l].hashtags.join(' ')}\n\nSearch keywords: ${res[l].keywords.join(', ')}\n\nComment prompts:\n${res[l].comments.map((c) => '• ' + c).join('\n')}`).join('\n\n');
    const base = String(p.name || 'reel').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').slice(0, 40) || 'reel';
    if (ctx.engine?.available) { res.file = `${exportsDir(p.workspace)}/${base}-post.txt`; await ctx.engine.writeFile(res.file, txt).catch(() => { res.file = null; }); }
    ctx.history.apply(ops.setProject({ meta: { ...p.meta, content: { ...(p.meta?.content || {}), copy: { en: strip(res.en), ar: strip(res.ar), model: res.model?.id || 'rules', at: new Date().toISOString() } } } }, 'Post copy'));
  }
  return res;
}
const strip = ({ raw, ...x }) => x;
export function closeWriter() { W?.terminate(); W = null; }
