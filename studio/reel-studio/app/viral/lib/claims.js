// KBB Studio · viral · claim safety (pure ESM). Flags risky promises in the transcript, captions and on-screen text —
// guaranteed visas, 100 % approval, cures and medical claims, whitening, "no side effects" — and offers compliant
// wording. A guide, not legal advice: the panel says so, and the owner decides.

/** rules: rx (case-insensitive, matched on the plain text), category, severity, why, suggestion (EN) / suggestionAr */
export const RULES = [
  // ── immigration / study visas ──
  { id: 'visa-guarantee', cat: 'visa', sev: 'high', rx: /\b(guaranteed?|100\s*%|hundred percent)\s+(visa|visas|approval|acceptance|admission|success|pr|residency)\b|\b(visa|approval|acceptance|admission|pr)\s+(is\s+)?(guaranteed|100\s*%\s*guaranteed)\b/i,
    why: 'No consultant can guarantee a visa or an approval — only the embassy decides.', fix: 'strong application support' },
  { id: 'visa-100', cat: 'visa', sev: 'high', rx: /\b100\s*%\s*(success|approval|acceptance)(\s+rate)?\b/i, why: 'A 100 % success rate is not a claim anyone can prove.', fix: 'a strong track record' },
  { id: 'visa-never', cat: 'visa', sev: 'high', rx: /\b(no|zero)\s+(rejections?|refusals?)\b|\bnever\s+(rejected|refused)\b/i, why: 'Refusals happen; promising none misleads.', fix: 'fewer avoidable refusals' },
  { id: 'job-guarantee', cat: 'visa', sev: 'high', rx: /\b(guaranteed?\s+(job|jobs|employment|work\s+permit)|(job|employment)\s+guaranteed)\b/i, why: 'Jobs and work permits cannot be guaranteed.', fix: 'help finding work' },
  { id: 'visa-fast', cat: 'visa', sev: 'medium', rx: /\b(visa|pr|residency)\s+in\s+\d+\s+(days?|hours?)\b/i, why: 'Processing times are set by the authority and vary.', fix: 'typical processing times' },
  { id: 'visa-ar', cat: 'visa', sev: 'high', rx: /(فيزا|تأشيرة|التأشيرة|الفيزا|قبول|القبول)\s*(مضمونة|مضمون|١٠٠\s*٪|100\s*%)|ضمان\s*(القبول|الفيزا|التأشيرة)|نسبة\s*(نجاح|قبول)\s*(١٠٠|100)/,
    why: 'لا أحد يضمن الفيزا أو القبول — القرار للسفارة.', fix: 'دعم قوي لملفك', ar: true },
  // ── beauty / medical ──
  { id: 'cure', cat: 'medical', sev: 'high', rx: /\b(cures?|cured|curing|heals?|healing|treats?|treatment\s+for)\s+(acne|eczema|psoriasis|rosacea|melasma|hair\s*loss|scars?|wrinkles|pigmentation|disease|infections?|dermatitis)\b/i,
    why: 'Cosmetics may not claim to cure or treat a condition — that makes it a medicine.', fix: 'helps the look of' },
  { id: 'cure-bare', cat: 'medical', sev: 'high', rx: /\b(cures?|miracle\s+cure)\b/i, why: 'Cure claims are medical claims.', fix: 'helps' },
  { id: 'medical', cat: 'medical', sev: 'medium', rx: /\b(medical[- ]grade|prescription[- ]strength|clinically\s+proven|dermatologist[- ](approved|recommended)|fda[- ]approved)\b/i,
    why: 'Needs evidence you can show (a study, an approval) before it is said on camera.', fix: 'tested and loved' },
  { id: 'no-side', cat: 'medical', sev: 'high', rx: /\b(with\s+)?(no|zero)\s+side[- ]effects?\b|\b100\s*%\s*(safe|natural|organic)\b/i, why: 'Absolute safety claims cannot be supported.', fix: 'gentle on most skin types' },
  { id: 'permanent', cat: 'medical', sev: 'medium', rx: /\b(permanent(ly)?|forever)\s+(results?|removes?|removal|whiter|clear)\b|\bremoves?\s+(wrinkles|scars|pigmentation|dark\s+spots)\b/i, why: 'Permanent or "removes" results overpromise.', fix: 'visibly reduces the look of' },
  { id: 'whitening', cat: 'beauty', sev: 'medium', rx: /\b(whiten(ing|s)?|bleach(ing)?)\b(?!\s+teeth)/i, why: 'Whitening is restricted wording for skin in the UAE/GCC and reads badly; brands say brightening.', fix: 'brightening' },
  { id: 'instant', cat: 'beauty', sev: 'low', rx: /\b(instant(ly)?\s+(results?|clear|glow|lift)|overnight\s+results?)\b/i, why: 'Instant results invite complaints.', fix: 'visible results with regular use' },
  { id: 'miracle', cat: 'beauty', sev: 'low', rx: /\bmiracle\b/i, why: '"Miracle" is an unprovable claim.', fix: 'favourite' },
  { id: 'medical-ar', cat: 'medical', sev: 'high', rx: /(يعالج|تعالج|علاج\s+(حب\s+الشباب|الأكزيما|الإكزيما|التصبغات|الكلف|تساقط\s+الشعر)|يشفي|شفاء)/, why: 'مستحضرات التجميل لا تُعالج — هذا ادعاء طبي.', fix: 'يساعد على تحسين مظهر', ar: true },
  { id: 'whitening-ar', cat: 'beauty', sev: 'medium', rx: /(تبييض|تبيض|مبيض|يبيض)/, why: 'كلمة "تبييض" مقيّدة للبشرة؛ استخدم "تفتيح/إشراقة".', fix: 'إشراقة', ar: true },
  { id: 'safe-ar', cat: 'medical', sev: 'high', rx: /(بدون|بلا)\s*(أي\s*)?(آثار|اثار)\s*جانبية|آمن\s*(١٠٠|100)/, why: 'لا يمكن إثبات الأمان المطلق.', fix: 'لطيف على معظم أنواع البشرة', ar: true },
  { id: 'instant-ar', cat: 'beauty', sev: 'low', rx: /نتائج\s*(فورية|فوريه|مضمونة)/, why: 'النتائج الفورية أو المضمونة مبالغة.', fix: 'نتائج ملحوظة مع الاستخدام المنتظم', ar: true },
  // ── general advertising ──
  { id: 'best', cat: 'advertising', sev: 'low', rx: /\b(the\s+)?(best|cheapest|number\s*one|#1|no\.?\s*1)\s+in\s+(the\s+)?(world|uae|dubai|country|market)\b/i, why: 'Superlatives need proof (comparative-advertising rules).', fix: 'one of the most loved' },
  { id: 'risk-free', cat: 'advertising', sev: 'low', rx: /\brisk[- ]free\b/i, why: 'Say what the guarantee actually is.', fix: 'with a clear refund policy' },
];

const SEV = { high: 3, medium: 2, low: 1 };

/**
 * Scan texts. sources = [{ source:'captions'|'text'|'hook'|'cta', itemId?, text, map?(charIndex)->t }]
 * -> [{ id, source, itemId, t, match, cat, sev, why, suggestion, start, end }]  most severe first, then by time
 */
export function scanClaims(sources = []) {
  const out = [];
  for (const s of sources) {
    const txt = String(s.text || '');
    for (const r of RULES) {
      const rx = new RegExp(r.rx.source, r.rx.flags.includes('g') ? r.rx.flags : r.rx.flags + 'g');
      let m;
      while ((m = rx.exec(txt))) {
        if (!m[0].trim()) { rx.lastIndex++; continue; }
        // one finding per span: a later, broader rule over the same words is skipped
        if (out.some((o) => o.source === s.source && o.itemId === (s.itemId || null) && !(m.index >= o.end || m.index + m[0].length <= o.start))) continue;
        out.push({ id: r.id, source: s.source, itemId: s.itemId || null, t: s.map ? s.map(m.index) : (s.t ?? null), match: m[0], cat: r.cat, sev: r.sev, why: r.why,
          suggestion: suggestFor(r, m[0]), start: m.index, end: m.index + m[0].length });
      }
    }
  }
  return out.sort((a, b) => SEV[b.sev] - SEV[a.sev] || (a.t ?? 0) - (b.t ?? 0));
}

/** compliant wording for one match (keeps the parts that are fine: "cures acne" -> "helps the look of acne") */
function suggestFor(r, match) {
  if (r.id === 'cure') { const cond = match.replace(/^(cures?|cured|curing|heals?|healing|treats?|treatment\s+for)\s+/i, ''); return `${r.fix} ${cond}`; }
  if (r.id === 'permanent') { const what = (/(wrinkles|scars|pigmentation|dark\s+spots)/i.exec(match) || [])[1]; return what ? `${r.fix} ${what}` : 'visible results with regular use'; }
  if (r.id === 'medical-ar') { const cond = match.replace(/^(يعالج|تعالج|علاج|يشفي|شفاء)\s*/, ''); return cond ? `${r.fix} ${cond}` : r.fix; }
  if (r.id === 'whitening') return /^W/.test(match) ? 'Brightening' : 'brightening';
  if (r.id === 'no-side' && /^with\s/i.test(match)) return 'and is ' + r.fix;
  return r.fix;
}

/** the text with every finding's span replaced (findings of ONE source, any order) */
export function applyFixes(text, findings) {
  let out = String(text);
  for (const f of findings.slice().sort((a, b) => b.start - a.start)) out = out.slice(0, f.start) + f.suggestion + out.slice(f.end);
  return out;
}
