// KBB Studio — brand kits <-> companies (workspaces).
//
// A company (workspaces/<slug>/workspace.json) is SEEDED from its brand kit (brands/<slug>/kit.json, R3): colours,
// fonts, logo, industries, contact line, end card, favourite recipes and caption style come from the kit, so the
// studio, the auto-reel layers and the caption styles all use the same brand. A NEW company writes its own kit,
// validated against brands/kit.schema.json, so the automation treats it exactly like the two built-in ones.

const HEX = /^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/;

/** recipe industry tags read from free text ("Immigration & study-visa consultancy" -> immigration, education, consultancy) */
export function industryTags(text) {
  const s = String(text || '').toLowerCase(), out = [];
  const add = (t) => { if (!out.includes(t)) out.push(t); };
  if (/beaut|cosmetic|skin|make-?up|salon|spa/.test(s)) add('beauty');
  if (/retail|shop|store|boutique|e-?commerce|fashion/.test(s)) add('retail');
  if (/immigra|visa|migrat|relocat/.test(s)) add('immigration');
  if (/study|educat|school|universit|college|student|course|academ/.test(s)) add('education');
  if (/consult|advis|agency|legal|law/.test(s)) add('consultancy');
  if (!out.length) add('generic');
  return out;
}

const firstOf = (v) => (Array.isArray(v) ? v[0] : v) || '';
const usable = (s) => (typeof s === 'string' && s && !/^<edit/.test(s) ? s : '');

/** a workspace from a brand kit (the seed for KBB and Planet Careers) */
export function workspaceFromKit(kit, base) {
  const ws = Object.assign({}, base);
  ws.id = kit.id; ws.brandRef = kit.id;
  ws.name = ws.name || kit.name;
  ws.fullName = kit.fullName || kit.name;
  ws.industry = kit.industry || ws.industry || '';
  ws.industries = Array.isArray(kit.industries) && kit.industries.length ? kit.industries.slice() : industryTags(ws.industry);
  ws.colors = Object.fromEntries(Object.entries(Object.assign({}, ws.colors, kit.colors)).filter(([, v]) => HEX.test(v)));
  ws.fonts = { heading: kit.fonts?.heading?.family || ws.fonts?.heading || 'system-ui', body: kit.fonts?.body?.family || ws.fonts?.body || 'system-ui' };
  if (kit.fonts?.arabic?.family) ws.fonts.arabic = kit.fonts.arabic.family;
  // the badge (a white tile): the app icon, else the colour mark
  const logo = kit.logos?.appIcon || kit.logos?.mark;
  if (logo) ws.logo = `brands/${kit.id}/${logo}`;
  if (kit.logos?.full) ws.logoFull = `brands/${kit.id}/${kit.logos.full}`;
  ws.contact = usable(kit.contactLine) || usable(ws.contact) || '';
  ws.endCard = { title: kit.endCard?.title || ws.fullName, sub: kit.endCard?.sub || '', cta: firstOf(kit.endCard?.cta), ctaAr: firstOf(kit.endCard?.ctaAr) };
  ws.favRecipes = (kit.defaults?.favRecipes || []).slice();
  ws.captions = Object.assign({}, ws.captions, kit.defaults?.captionStyle ? { style: kit.defaults.captionStyle } : {}, kit.defaults?.captionStyleAr ? { styleAr: kit.defaults.captionStyleAr } : {});
  if (kit.defaults?.aspect) ws.aspect = kit.defaults.aspect;
  ws.kitSeeded = 1;
  return ws;
}

/** a brand kit for a company made in the app (+ New company) */
export function kitFromWorkspace(w, { mark = 'logo-mark.svg', full } = {}) {
  const c = w.colors || {};
  const kit = {
    v: 1, id: w.id, name: w.name, fullName: w.fullName || w.name, industry: w.industry || '',
    industries: w.industries?.length ? w.industries : industryTags(w.industry),
    colors: { primary: c.primary, secondary: c.secondary, accent: c.accent, text: c.text, bg: c.bg },
    gradient: [c.primary, c.secondary].filter(Boolean),
    fonts: { heading: { family: w.fonts?.heading || 'system-ui', fallback: 'sans-serif' }, body: { family: w.fonts?.body || 'system-ui', fallback: 'sans-serif' } },
    logos: Object.assign({ mark }, full ? { full } : {}),
    endCard: { title: w.endCard?.title || w.name, sub: w.endCard?.sub || '', cta: [w.endCard?.cta || 'Learn more'] },
    contactLine: w.contact || '',
    defaults: { captionStyle: w.captions?.style || undefined, favRecipes: w.favRecipes || [], aspect: '9:16' },
  };
  if (!kit.defaults.captionStyle) delete kit.defaults.captionStyle;
  return kit;
}

/** a simple round mark with the company's initial (used when a new company has no logo yet) */
export function initialMarkSvg(w) {
  const ch = (String(w.name || '?').trim()[0] || '?').toUpperCase().replace(/[<&>"']/g, '');
  const bg = HEX.test(w.colors?.primary) ? w.colors.primary : '#444444', fg = HEX.test(w.colors?.text) ? w.colors.text : '#ffffff';
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200"><circle cx="100" cy="100" r="96" fill="${bg}"/><text x="100" y="100" dy=".35em" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-weight="700" font-size="110" fill="${fg}">${ch}</text></svg>`;
}

/**
 * Validate a value against the subset of JSON Schema brands/kit.schema.json uses
 * (type, required, properties, items, minItems, pattern, enum, const, $ref to #/$defs). Returns a list of problems.
 */
export function validateKit(kit, schema) {
  const errs = [];
  const typeOk = (v, t) => (Array.isArray(t) ? t.some((x) => typeOk(v, x)) : t === 'object' ? v && typeof v === 'object' && !Array.isArray(v) : t === 'array' ? Array.isArray(v) : t === 'number' ? typeof v === 'number' && isFinite(v) : t === 'integer' ? Number.isInteger(v) : typeof v === t);
  const walk = (v, s, at) => {
    if (!s) return;
    if (s.$ref) { const k = s.$ref.replace('#/$defs/', ''); return walk(v, schema.$defs?.[k], at); }
    if (s.const !== undefined && v !== s.const) errs.push(`${at} must be ${JSON.stringify(s.const)}`);
    if (s.enum && !s.enum.includes(v)) errs.push(`${at} must be one of ${s.enum.join(', ')}`);
    if (s.type && !typeOk(v, s.type)) { errs.push(`${at} must be ${[].concat(s.type).join(' or ')}`); return; }
    if (s.pattern && typeof v === 'string' && !new RegExp(s.pattern).test(v)) errs.push(`${at} "${v}" is not valid`);
    if (s.minItems && Array.isArray(v) && v.length < s.minItems) errs.push(`${at} needs at least ${s.minItems}`);
    if (s.items && Array.isArray(v)) v.forEach((x, i) => walk(x, s.items, `${at}[${i}]`));
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      for (const r of s.required || []) if (v[r] === undefined) errs.push(`${at ? at + '.' : ''}${r} is missing`);
      for (const [k, sub] of Object.entries(s.properties || {})) if (v[k] !== undefined) walk(v[k], sub, at ? `${at}.${k}` : k);
    }
  };
  walk(kit, schema, '');
  return errs;
}
