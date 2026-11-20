// Audiences & ad types: what the autopilot can run, who it targets, and the
// low-CPC playbook. Read-only explanation of the brain's catalogue; the
// per-account links (Page, Instagram, pixel, catalog, WhatsApp) come from the engine.
import { tt } from '../i18n.js';
import { esc } from '../ui.js';
import { audiencesCard, loadFunnel } from './funnel-ui.js';

export const load = () => loadFunnel(false);

const AUD = [
  ['women_gcc_ar', 'Arabic-speaking women 18–45, GCC', 'نساء ١٨–٤٥ يتحدثن العربية، الخليج', 'Advantage+ audience with suggestions: female, 18–45, Arabic, GCC countries, skincare & K-beauty interests.', 'جمهور Advantage+ مع اقتراحات: نساء، ١٨–٤٥، العربية، دول الخليج، اهتمامات العناية والجمال الكوري.'],
  ['women_uae_en', 'English-speaking women 18–45, UAE', 'نساء ١٨–٤٥ يتحدثن الإنجليزية، الإمارات', 'For the English versions: residents and expats.', 'للنسخ الإنجليزية: المقيمات والوافدات.'],
  ['broad', 'Broad (creative finds the audience)', 'جمهور واسع (الإعلان يجد جمهوره)', 'Once creative is proven, Meta\'s Andromeda finds buyers from the creative itself.', 'بعد ثبوت نجاح الإعلان، تجد ميتا المشترين من الإعلان نفسه.'],
  ['retarget_viewers', 'Product viewers, 14 days', 'من شاهد المنتجات، ١٤ يوماً', 'Pixel ViewContent; catalog ads show the exact product.', 'من البكسل؛ إعلانات الكتالوج تعرض المنتج نفسه.'],
  ['retarget_cart', 'Cart abandoners, 7 days', 'من ترك السلة، ٧ أيام', 'Highest intent; small budget.', 'أعلى نية شراء؛ ميزانية صغيرة.'],
  ['retarget_engagers', 'Video viewers & engagers, 30 days', 'من شاهد الفيديو أو تفاعل، ٣٠ يوماً', 'Second touch after the videos.', 'لمسة ثانية بعد الفيديوهات.'],
  ['lookalike_buyers', 'Lookalike of buyers', 'جمهور مشابه للمشترين', 'From 100+ purchasers, per GCC market.', 'من أكثر من ١٠٠ مشترٍ لكل سوق خليجي.'],
  ['existing_customers', 'Existing customers (own ad set)', 'العملاء الحاليون (مجموعة مستقلة)', 'Meta\'s guidance: one ad set for existing customers, one excluding them.', 'إرشاد ميتا: مجموعة للعملاء الحاليين وأخرى تستبعدهم.'],
];
const FMT = [
  ['Single image', 'صورة واحدة', 'feed', ''], ['Single video', 'فيديو واحد', 'reels · stories · feed', ''], ['Reels & Stories 9:16', 'ريلز وستوريز ٩:١٦', 'reels · stories', ''],
  ['Carousel (2–10 products)', 'دوّار (٢–١٠ منتجات)', 'feed', ''], ['Collection / Instant Experience', 'مجموعة / تجربة فورية', 'feed · stories', ''], ['Advantage+ catalog ads', 'إعلانات الكتالوج', 'all', 'catalog_id'],
  ['Flexible format / dynamic creative', 'تنسيق مرن', 'all', ''],
  ['Partnership (creator) ads', 'إعلانات الشراكة', 'reels · feed', ''],
];
export function render(S, el) {
  const links = S.state.links || {};
  el.innerHTML = `<div class="page"><div class="page-h"><div><h1>${esc(tt('Audiences & ad types', 'الجماهير وأنواع الإعلانات'))}</h1><p>${esc(tt('Who the ads reach and every Meta ad type the autopilot can use. It picks per goal; you never have to.', 'من تصل إليه الإعلانات وكل أنواع إعلانات ميتا التي يستخدمها الطيار الآلي؛ يختار حسب الهدف.'))}</p></div></div>
  ${audiencesCard()}
  <div class="grid g2"><div class="card"><header><h2>${esc(tt('Audience types it uses', 'أنواع الجماهير المستخدمة'))}</h2></header><div class="list">${AUD.map(([id, en, ar, wen, war]) => `<div><span class="chip ${id === 'women_gcc_ar' ? 'acc' : ''}">${id === 'women_gcc_ar' ? esc(tt('default', 'الافتراضي')) : '·'}</span><span><b>${esc(tt(en, ar))}</b><br><span class="small ink2">${esc(tt(wen, war))}</span></span></div>`).join('')}</div></div>
  <div class="card"><header><h2>${esc(tt('Ad types', 'أنواع الإعلانات'))}</h2></header><table><thead><tr><th></th><th>${esc(tt('Placements', 'أماكن الظهور'))}</th><th>${esc(tt('Ready', 'جاهز'))}</th></tr></thead><tbody>
    ${FMT.map(([en, ar, pl, need]) => `<tr><td>${esc(tt(en, ar))}</td><td class="small muted">${esc(pl)}</td><td>${need ? (links[need] ? '<span class="chip acc">✓</span>' : `<span class="chip" data-tip="${esc(tt('Link it in Settings → Accounts', 'اربطيه في الإعدادات ← الحسابات'))}">${esc(tt('needs link', 'يحتاج ربط'))}</span>`) : '<span class="chip acc">✓</span>'}</td></tr>`).join('')}</tbody></table></div></div>
  <div class="card"><header><h2>${esc(tt('Low-cost playbook for this audience', 'خطة التكلفة المنخفضة لهذا الجمهور'))}</h2></header><ul class="ink2" style="margin:0;padding-inline-start:18px">
    <li>${esc(tt('Creative-first: many genuinely different creatives instead of narrow interests.', 'الإبداع أولاً: إعلانات متنوعة فعلاً بدل اهتمامات ضيقة.'))}</li>
    <li>${esc(tt('Reels and Stories first (9:16) — the cheapest attention; Advantage+ placements on, 4:5 for feed.', 'الريلز والستوريز أولاً (٩:١٦) — أرخص انتباه.'))}</li>
    <li>${esc(tt('Frequency kept under ~2.5 a week for new people; tired ads replaced.', 'تكرار أقل من ٢٫٥ أسبوعياً؛ استبدال الإعلانات المرهقة.'))}</li>
    <li>${esc(tt('Weak hooks (CTR credibly below the account) are stopped early, before they waste budget.', 'تُوقف الافتتاحيات الضعيفة مبكراً قبل هدر الميزانية.'))}</li>
    <li>${esc(tt('Retargeting only while its 7-day frequency stays under ~3.', 'إعادة الاستهداف فقط ما دام التكرار الأسبوعي أقل من ٣.'))}</li></ul></div></div>`;
}
