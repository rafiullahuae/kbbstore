// Offline emoji suggestions for caption keywords (English + Arabic, beauty + consultancy + everyday).
const MAP = [
  ['✨', 'glow glowing shine shiny sparkle radiant bright magic new نضارة لمعان مشرق سحر جديد'],
  ['💧', 'hydrate hydrating hydration moisture moisturizer moisturising water serum dewy ترطيب مرطب ماء سيروم'],
  ['🧴', 'cream lotion sunscreen spf toner cleanser product bottle كريم لوشن واقي منظف منتج'],
  ['💄', 'lipstick lip lips makeup gloss tint مكياج شفاه أحمر روج'],
  ['💅', 'nails nail polish manicure أظافر مناكير'],
  ['🌸', 'flower floral rose blossom soft gentle pink ورد زهرة وردي ناعم'],
  ['🧖‍♀️', 'skincare routine spa mask facial pamper relax عناية روتين سبا ماسك'],
  ['☀️', 'sun summer sunny morning day شمس صيف صباح'],
  ['🌙', 'night evening sleep bedtime ليل مساء نوم'],
  ['🎁', 'gift free bonus present giveaway هدية مجانا مجاني'],
  ['🔥', 'hot fire sale deal amazing best viral trending خصم عرض حصري الأفضل رائع'],
  ['💸', 'price cheap save saving discount off budget aed dirham سعر وفر درهم تخفيض'],
  ['🛍️', 'shop shopping buy order cart store تسوق اشتري اطلب متجر'],
  ['📦', 'unboxing box package delivery shipping parcel توصيل شحن طلبية علبة'],
  ['🚚', 'delivery fast express shipping توصيل سريع'],
  ['❤️', 'love loved favorite favourite heart حب أحب مفضل قلب'],
  ['😍', 'obsessed gorgeous beautiful stunning pretty جميل رائعة جميلة مذهل'],
  ['👏', 'congratulations congrats well done bravo مبروك برافو'],
  ['✅', 'yes done approved approval accepted success check ready نعم تم قبول موافقة نجاح جاهز'],
  ['❌', 'no wrong mistake avoid dont never خطأ لا تجنب'],
  ['⚠️', 'warning careful deadline urgent important تحذير مهم عاجل موعد'],
  ['⏰', 'time hurry today now limited last chance وقت الآن اليوم محدود فرصة'],
  ['📅', 'date appointment book booking schedule calendar intake موعد حجز احجز تاريخ'],
  ['🎓', 'study student university college degree masters bachelor scholarship دراسة طالب جامعة منحة ماجستير'],
  ['✈️', 'travel fly flight visa trip abroad europe سفر طيران تأشيرة فيزا رحلة أوروبا'],
  ['🌍', 'world country countries international global عالم دولة دول دولي'],
  ['🇪🇺', 'eu schengen european الاتحاد شنغن'],
  ['🇩🇪', 'germany german ألمانيا'], ['🇫🇷', 'france french فرنسا'], ['🇮🇹', 'italy italian إيطاليا'],
  ['🇪🇸', 'spain spanish إسبانيا'], ['🇵🇹', 'portugal البرتغال'], ['🇵🇱', 'poland بولندا'],
  ['🇳🇱', 'netherlands dutch هولندا'], ['🇮🇪', 'ireland أيرلندا'], ['🇲🇹', 'malta مالطا'],
  ['📄', 'document documents passport papers form application cv وثائق مستندات جواز أوراق طلب'],
  ['💼', 'job work career business company office وظيفة عمل شركة مهنة'],
  ['🏠', 'home house apartment property بيت منزل شقة عقار'],
  ['📞', 'call contact phone whatsapp اتصل تواصل هاتف واتساب'],
  ['💬', 'question questions ask faq comment سؤال أسئلة اسأل'],
  ['💡', 'tip tips idea hack secret advice نصيحة فكرة سر'],
  ['👇', 'below link bio comment down تحت الرابط'],
  ['💪', 'strong strength power fitness workout gym قوة تمرين'],
  ['🍳', 'cook cooking recipe kitchen breakfast طبخ وصفة مطبخ فطور'],
  ['☕', 'coffee cafe tea قهوة شاي'],
  ['🎉', 'celebrate party event launch weekend احتفال حفلة فعالية إطلاق'],
  ['⭐', 'review rating stars star five تقييم نجوم'],
  ['🤔', 'why how what think wonder لماذا كيف ماذا'],
];

const INDEX = new Map();
for (const [e, words] of MAP) for (const w of words.split(/\s+/)) if (!INDEX.has(w)) INDEX.set(w, e);

const norm = (w) => w.toLocaleLowerCase().replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '');

/** Up to `max` distinct emoji for a list of word strings, strongest (most specific) first. */
export function suggestEmoji(words, max = 3) {
  const out = [];
  for (const raw of words) {
    const w = norm(raw);
    let e = INDEX.get(w);
    if (!e && /^\p{Script=Arabic}/u.test(w)) e = INDEX.get(w.replace(/^(و|ب|ل|ف)/u, '')) || INDEX.get(w.replace(/^(وال|بال|لل|فال)/u, 'ال'));
    if (!e && w.endsWith('s')) e = INDEX.get(w.slice(0, -1));
    if (e && !out.includes(e)) out.push(e);
    if (out.length >= max) break;
  }
  return out;
}
