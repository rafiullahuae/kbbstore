// UI language: English / Arabic (RTL). Content that IS Arabic (ad copy,
// reports) is always shown RTL whatever the UI language.
const AR = {
  'Portfolio': 'المحفظة', 'Dashboard': 'لوحة التحكم', 'Campaigns': 'الحملات', 'Creatives': 'الإعلانات', 'Research': 'أبحاث السوق', 'Plan': 'الخطة',
  'Learned': 'ما تعلّمه', 'Activity': 'السجل', 'Settings': 'الإعدادات', 'Run cycle': 'تشغيل دورة', 'Kill switch': 'إيقاف كل شيء',
  'off': 'متوقف', 'shadow': 'مراقبة', 'auto': 'تلقائي', 'Next cycle': 'الدورة التالية', 'Today': 'اليوم', '7 days': '٧ أيام', '30 days': '٣٠ يوماً',
  'Spend': 'الإنفاق', 'Orders': 'الطلبات', 'Revenue': 'الإيرادات', 'ROAS': 'العائد على الإنفاق', 'CPA': 'تكلفة الطلب', 'CPC': 'تكلفة النقرة', 'CTR': 'نسبة النقر', 'CPM': 'تكلفة الألف ظهور',
  'target': 'المستهدف', 'Alerts': 'تنبيهات', 'What it did': 'ما فعله', 'Last 24 hours': 'آخر ٢٤ ساعة', 'Arabic vs English': 'العربية مقابل الإنجليزية',
  'Arabic': 'العربية', 'English': 'الإنجليزية', 'Cost trends': 'اتجاهات التكلفة', 'Spend and revenue, 30 days': 'الإنفاق والإيرادات خلال ٣٠ يوماً',
  'Placements': 'أماكن الظهور', 'Images': 'الصور', 'Videos': 'الفيديوهات', 'Motion ads': 'إعلانات متحركة', 'Generate new': 'إنشاء جديد', 'Preview': 'معاينة',
  'Save': 'حفظ', 'Cancel': 'إلغاء', 'Undo': 'تراجع', 'Advanced': 'متقدم', 'Connections': 'الاتصالات', 'Limits': 'الحدود', 'Accounts': 'الحسابات',
  'Daily limit (AED)': 'الحد اليومي (درهم)', 'Monthly limit (AED)': 'الحد الشهري (درهم)', 'Autopilot': 'الطيار الآلي', 'Ad wording rules': 'قواعد صياغة الإعلانات',
  'Strict': 'صارمة', 'Balanced': 'متوازنة', 'Relaxed': 'مرنة', 'Start autopilot': 'تشغيل الطيار الآلي', 'Back': 'رجوع', 'Continue': 'متابعة',
  'This week\'s tests': 'اختبارات هذا الأسبوع', 'Budget split': 'توزيع الميزانية', 'Seasonal pushes': 'المواسم القادمة', 'Daily report': 'التقرير اليومي',
  'Why': 'السبب', 'Status': 'الحالة', 'Account': 'الحساب', 'Automate': 'أتمتة', 'Mode': 'الوضع', 'History': 'السجل التاريخي', 'Sources': 'المصادر',
  'Drop videos here or choose files': 'اسحبي الفيديوهات هنا أو اختاري ملفات', 'New to run': 'جديد للتشغيل', 'Used before': 'استُخدم سابقاً', 'Analyse': 'تحليل',
  'Score': 'التقييم', 'Hook': 'الافتتاحية', 'Suggestions': 'اقتراحات', 'Issues': 'ملاحظات', 'Make motion ad': 'صنع إعلان متحرك', 'Render': 'إنشاء',
  'Search': 'بحث', 'All': 'الكل', 'Proposals': 'المقترحات', 'Winners': 'الرابحون', 'Evidence': 'الأدلة', 'Baselines': 'المعدلات الأساسية',
  'What it is doing now': 'ما يفعله الآن', 'Not connected': 'غير متصل', 'Connected': 'متصل', 'Test': 'اختبار', 'Keyboard': 'لوحة المفاتيح',
};
let lang = 'en';
try { lang = localStorage.getItem('kaa.lang') === 'ar' ? 'ar' : 'en'; } catch {}
export const getLang = () => lang;
export function setLang(l) {
  lang = l === 'ar' ? 'ar' : 'en';
  try { localStorage.setItem('kaa.lang', lang); } catch {}
  document.documentElement.lang = lang;
  document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
}
export const t = (s) => (lang === 'ar' && AR[s]) || s;
// pick the Arabic or English variant of a bilingual pair
export const tt = (en, ar) => (lang === 'ar' && ar ? ar : en);
setLang(lang);
