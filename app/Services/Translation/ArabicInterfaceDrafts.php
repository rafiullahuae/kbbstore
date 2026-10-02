<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Arabic for the interface, offered to the owner as DRAFTS.
 *
 * ── WHY THIS FILE EXISTS, AND WHAT IT IS NOT ────────────────────────────────
 *
 * Before this lane the repository shipped no Arabic at all. Not "some" — none:
 * a character-class scan of app/, database/, resources/, routes/ and tests/
 * finds Arabic script in nine files and every one of them is a slug
 * transliteration table, a currency symbol, or the word العربية used as the
 * name of a language. All 1,026 interface strings therefore fell through
 * DatabaseTranslationLoader's chain to the English default, on every /ar page,
 * since the day /ar was built.
 *
 * That is not an oversight. docs/BILINGUAL-PLAN.md decision 2 puts every typed
 * string in the `translations` table, and step 3 of its plan — "translate the
 * interface: ~30,000 characters of buttons and headings" — is costed as the
 * OWNER'S TIME. The measured total is 33,408 characters -- mb_strlen in UTF-8,
 * which is what TranslationEstimate counts and what Google bills -- so the
 * plan's estimate was right and the work simply had not been done.
 *
 * (33,691 is the same set measured in BYTES, and it is the wrong number: the
 * English carries …, —, · and a few emoji, and a byte count of source text is
 * neither what the estimate reports nor what anybody is charged for.)
 *
 * ── WHY DRAFTS, AND WHY THAT IS NOT TIMIDITY ────────────────────────────────
 *
 * resources/views/admin/partials/arabic-boxes.blade.php states the contract
 * this file had to fit inside, and two clauses of it decide the shape:
 *
 *   "BLANK MEANS NOT TRANSLATED YET ... That is the only reason the progress
 *    figure is countable rather than claimed."
 *   "Manual entry is PUBLISHED IMMEDIATELY. Only a machine drafts."
 *
 * Writing these in as PUBLISHED would put 1,026 unreviewed Arabic strings onto
 * the shop and simultaneously tell the owner, on his own progress screen, that
 * the interface was 100% translated by a human. Both halves of that are false,
 * and the second is worse than the first: it destroys the one number he uses to
 * know what is left.
 *
 * So they arrive the way a machine's work arrives, because that is what they
 * are — written by a model, not typed by a person who reads Arabic. As drafts:
 *
 *   - TranslationStore's fallback chain never reaches a draft, so /ar renders
 *     exactly what it rendered before this package. Applying it moves nothing.
 *   - TranslationEstimate::progress() counts `translated` and `drafts`
 *     SEPARATELY, so the honest figure stays 0% translated with 1,018 awaiting
 *     review, which is the truth.
 *   - Translation -> Strings already lists a draft marked as a draft beside
 *     its English, so reading them is a screen the owner already has; and
 *     Translation -> Progress already carries one "Approve all N drafts"
 *     button, wired to TranslationsApiController::publish() with no `field`,
 *     which approves the whole locale in one call and PRINTS THE COUNT IT IS
 *     ABOUT TO APPROVE on its own face.
 *
 * The owner reads them, corrects what he wants, and presses one button. That is
 * the workflow the plan designed; this file fills it.
 *
 * ── THE VOICE ───────────────────────────────────────────────────────────────
 *
 * Plain Modern Standard Arabic as a UAE shopper reads it, not as a dictionary
 * renders it: أضف إلى السلة, not the literal of "add to cart". Brand and
 * product names stay in Latin script, which is what the shop already does and
 * what Gulf beauty retail does. Prices stay `AED 199` and arrive already
 * formatted in :amount, so nothing here reverses a number or reaches for
 * Arabic-Indic digits — docs/BILINGUAL-PLAN.md is explicit about both.
 *
 * ── COUNTED STRINGS CARRY SIX FORMS, AND MUST ───────────────────────────────
 *
 * Arabic has six plural forms and Laravel knows it:
 * MessageSelector::getPluralIndex returns 0..5 for 'ar'. What it does when the
 * translation has fewer segments than the index it picked is the trap:
 *
 *     if (count($segments) === 1 || ! isset($segments[$pluralIndex])) {
 *         return $segments[0];
 *     }
 *
 * $segments[0] is the ZERO form. So an Arabic plural written with English's two
 * forms does not fall back to the plural — it renders "no products" for five
 * products, silently, on the shop. Every `|` string below therefore carries
 * exactly six segments in the order zero, one, two, few, many, other, and
 * ArabicInterfaceDraftsTest refuses any that does not.
 *
 * ── WHAT IS DELIBERATELY LEFT IN ENGLISH ────────────────────────────────────
 *
 * The eight `store.concern.intro_*` paragraphs, 342 to 802 characters each.
 * They are not interface text; they are the indexed body copy of eight SEO
 * landing pages, written in a particular voice to rank for a particular query.
 * An Arabic version of one is a copywriting job with its own keyword research,
 * not a translation of the English, and a machine-written Arabic paragraph on
 * an indexed page is worse than the English paragraph that is there now —
 * which at least reads as a shop that has not finished translating rather than
 * as a shop that writes badly. They stay absent, so the fallback serves the
 * English, and they are named in the lane report for the owner to commission.
 *
 * Everything else is here.
 *
 * ── ONE CONSEQUENCE OF THAT, WHICH THE OWNER SHOULD BE TOLD ────────────────
 *
 * TranslationEstimate::translatedSlots() counts a slot as done once ANY row
 * exists for it, draft or published -- deliberately, because re-sending text
 * that already has a translation is money spent overwriting work. So once this
 * file's 1,018 rows are in, the interface all but disappears from the
 * machine-translation quote: what is left for `group=ui` is exactly these eight
 * paragraphs, 5,367 characters, about USD 0.11 at Google's published rate.
 *
 * That is a saving -- docs/BILINGUAL-PLAN.md costed the whole interface as
 * machine work and it no longer is -- but it leaves a sharp edge: the only
 * interface strings the machine will now offer to translate are precisely the
 * eight this file judged a machine must not write. All eight are plain text, so
 * MachineTranslationRunner::isMachineSafe() does not refuse them. Nothing is
 * unsafe about it -- the output would arrive as a draft and want approving like
 * any other -- but "Translate everything" pointed at this shop's interface now
 * means "machine-translate the eight SEO paragraphs", and the owner should know
 * that before he presses it.
 */
final class ArabicInterfaceDrafts
{
    /**
     * Fully-qualified key => Arabic, in the shape InterfaceStrings::flat() uses.
     *
     * Split per area, matching InterfaceStrings' own methods one for one, so a
     * reviewer comparing the two reads them side by side.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_merge(
            self::chrome(),
            self::accountPanel(),
            self::catalogue(),
            self::cart(),
            self::home(),
            self::reviews(),
            self::product(),
            self::checkout(),
            self::orderReceived(),
            self::brands(),
            self::journal(),
            self::orders(),
            self::account(),
            self::addresses(),
            self::track(),
            self::quiz(),
            self::reviewWall(),
            self::routines(),
            self::ugc(),
            self::instagram(),
            self::newsletter(),
            self::js(),
            self::email(),
            self::invoice(),
        );
    }

    /** @return array<string, string> */
    private static function chrome(): array
    {
        return [
            'store.breadcrumb.home' => 'الرئيسية',
            'store.header.search_label' => 'بحث',
            'store.header.suggestions_label' => 'اقتراحات',
            'store.header.account_label' => 'حسابي',
            'store.header.wishlist_label' => 'المفضلة',
            'store.header.cart_label' => 'السلة',
            'store.header.trending_heading' => 'الأكثر رواجًا',
            /*
             * The flag bar (Lane FB). A DRAFT like every other line in this
             * file: nothing here reaches /ar until the owner approves it, and
             * until then the strip renders the English.
             *
             * "متجر الإمارات للجمال الكوري الأصلي" is the claim written the way
             * Arabic makes it — الأصلي (authentic/original) qualifies الجمال
             * الكوري, not the shop — and it is the wording a native reader
             * should be asked to check first, because it is the only one of the
             * three that a shopper reads rather than hears from a screen
             * reader.
             */
            'store.flagbar.text' => 'متجر الإمارات للجمال الكوري الأصلي',
            'store.flagbar.uae' => 'علم الإمارات العربية المتحدة',
            'store.flagbar.korea' => 'علم كوريا الجنوبية',
            'store.footer.tagline' => 'جمال كوري أصلي، مختار لدولة الإمارات.',
            'store.footer.whatsapp_cta' => 'تواصل عبر WhatsApp',
            'store.footer.shop_heading' => 'التسوق',
            'store.footer.link_all_products' => 'كل المنتجات',
            'store.footer.link_new_in' => 'وصل حديثًا',
            'store.footer.link_best_sellers' => 'الأكثر مبيعًا',
            'store.footer.link_super_sale' => 'تخفيضات كبرى',
            'store.footer.care_heading' => 'خدمة العملاء',
            'store.footer.link_track_order' => 'تتبع طلبي',
            'store.footer.link_delivery' => 'الشحن والتوصيل',
            'store.footer.link_returns' => 'معلومات الإرجاع',
            'store.footer.link_faqs' => 'الأسئلة الشائعة',
            'store.footer.link_contact' => 'اتصل بنا',
            'store.footer.account_heading' => 'حسابي',
            'store.footer.link_my_account' => 'حسابي',
            'store.footer.link_orders' => 'الطلبات',
            'store.footer.link_address' => 'عنوان الشحن',
            // :year and :store are placeholders so Arabic puts each where Arabic
            // puts it; the shop's name is a proper noun and stays Latin.
            'store.footer.copyright' => '© :year :store الإمارات',
            // Kept as the English abbreviation the payment strip already prints.
            'store.footer.pay_cod' => 'COD',
            'store.delivery.free_over' => 'توصيل مجاني للطلبات فوق :amount',
            'store.announcement.pay_later' => 'ادفع لاحقًا مع Tabby و Tamara',
            'store.mobile_menu.open_label' => 'القائمة',
            'store.mobile_menu.nav_label' => 'القائمة',
            'store.mobile_menu.close_label' => 'إغلاق',
            'store.mobile_menu.search_placeholder' => 'ابحث عن منتج أو ماركة…',
            'store.mobile_menu.hint' => 'اضغط على قسم لفتحه',
            'store.mobile_menu.no_matches' => 'لا توجد نتائج مطابقة.',
            'store.mobile_menu.link_account' => 'حسابي',
            'store.mobile_menu.link_orders' => 'الطلبات',
            'store.mobile_menu.link_sign_in' => 'تسجيل الدخول',
            'store.mobile_menu.link_register' => 'إنشاء حساب',
            'store.mobile_menu.link_wishlist' => 'المفضلة',
            'store.tabbar.label' => 'تنقل سريع',
            'store.tabbar.home' => 'الرئيسية',
            'store.tabbar.shop' => 'التسوق',
            'store.tabbar.quiz' => 'الاختبار',
            'store.tabbar.saved' => 'المحفوظة',
            'store.tabbar.bag' => 'الحقيبة',
            'store.language.switch' => 'اللغة',
            // Each language is named IN ITSELF, which is what a language switcher
            // does: an Arabic reader looking for English looks for "English".
            'store.language.english' => 'English',
            'store.language.arabic' => 'العربية',
            'store.breadcrumb.shop' => 'التسوق',
            'store.breadcrumb.brands' => 'الماركات',
            'store.breadcrumb.label' => 'مسار التصفح',
        ];
    }

    /** @return array<string, string> */
    private static function accountPanel(): array
    {
        return [
            'store.account_panel.default_name' => 'حسابي',
            'store.account_panel.link_account' => 'حسابي',
            'store.account_panel.link_orders' => 'الطلبات',
            'store.account_panel.link_wishlist' => 'المفضلة',
            'store.account_panel.link_addresses' => 'العناوين',
            'store.account_panel.link_track' => 'تتبع طلبي',
            'store.account_panel.sign_out' => 'تسجيل الخروج',
            'store.account_panel.tab_sign_in' => 'تسجيل الدخول',
            'store.account_panel.tab_register' => 'إنشاء حساب',
            'store.account_panel.field_name' => 'الاسم',
            'store.account_panel.field_email' => 'البريد الإلكتروني',
            'store.account_panel.field_password' => 'كلمة المرور',
            'store.account_panel.field_password_confirm' => 'تأكيد كلمة المرور',
            'store.account_panel.stay_signed_in' => 'إبقائي مسجلًا للدخول',
            'store.account_panel.forgotten' => 'نسيتها؟',
            'store.account_panel.sign_in_button' => 'تسجيل الدخول',
            'store.account_panel.register_button' => 'إنشاء حساب',
            'store.account_panel.terms_notice' => 'بإنشائك حسابًا فأنت توافق على :terms و:privacy.',
            'store.account_panel.terms_link' => 'الشروط',
            'store.account_panel.privacy_link' => 'سياسة الخصوصية',
        ];
    }

    /**
     * The shop archive, the collections, the concern shelves and the card.
     *
     * The eight `concern.intro_*` paragraphs are NOT here. See the class doc:
     * they are indexed landing-page copy, not interface text, and the fallback
     * serves the English until a copywriter is commissioned.
     *
     * @return array<string, string>
     */
    private static function catalogue(): array
    {
        return [
            'store.shop.page_title' => ':title · K-Beauty Bliss',
            'store.shop.eyebrow' => 'الجمال الكوري · العناية بالبشرة',
            'store.shop.filters_heading' => 'التصفية',
            'store.shop.filters_hide' => 'إخفاء',
            'store.shop.filters_show' => 'إظهار التصفية',
            'store.shop.pages_label' => 'الصفحات',
            'store.shop.page_prev' => 'الصفحة السابقة',
            'store.shop.page_next' => 'الصفحة التالية',
            'store.shop.loading_more' => 'جارٍ تحميل المزيد من المنتجات…',
            'store.shop.facet_category' => 'الفئة',
            'store.shop.facet_brand' => 'الماركة',
            'store.shop.facet_price' => 'السعر',
            'store.shop.facet_offers' => 'العروض',
            'store.shop.facet_on_sale_only' => 'المخفَّضة فقط',
            'store.shop.facet_in_stock_only' => 'المتوفرة فقط',
            'store.shop.product_count' => 'لا منتجات|منتج واحد|منتجان|:formatted منتجات|:formatted منتجًا|:formatted منتج',
            'store.shop.columns_option' => 'بدون أعمدة|عمود واحد|عمودان|:count أعمدة|:count عمودًا|:count عمود',
            'store.shop.sort_label' => 'الترتيب',
            'store.shop.clear_all' => 'مسح الكل',
            'store.shop.empty_heading' => 'لا توجد منتجات تطابق هذه التصفية',
            'store.shop.empty_body' => 'جرّب إزالة أحد الخيارات أو مسح الكل.',
            'store.shop.sort_featured' => 'المميزة',
            'store.shop.sort_popularity' => 'الأكثر مبيعًا',
            'store.shop.sort_plow' => 'السعر: من الأقل إلى الأعلى',
            'store.shop.sort_phigh' => 'السعر: من الأعلى إلى الأقل',
            'store.shop.sort_rating' => 'الأعلى تقييمًا',
            'store.shop.sort_date' => 'الأحدث',
            // A–Z is the LATIN alphabet and the product names it sorts are in
            // Latin script on this shop, so the letters are left as they are.
            'store.shop.sort_name' => 'الاسم A–Z',
            'store.shop.price_u54' => 'أقل من AED 54',
            'store.shop.price_54_150' => 'AED 54 – 150',
            'store.shop.price_150_300' => 'AED 150 – 300',
            'store.shop.price_300p' => 'AED 300+',
            'store.shop.chip_on_sale' => 'مخفَّض',
            'store.shop.chip_in_stock' => 'متوفر',
            'store.shop.title_all' => 'تسوق الكل',
            'store.shop.title_search' => 'البحث: :term',
            'store.shop.sub_default' => 'عناية كورية أصلية بالبشرة، مختارة لدولة الإمارات.',
            'store.shop.sub_search' => 'نتائج من المنتجات والماركات.',
            'store.shop.crumb_category' => 'الفئة',
            'store.shop.crumb_search' => 'البحث',
            'store.shop.header_more' => 'اقرأ المزيد',
            'store.shop.header_less' => 'اقرأ أقل',

            'store.collection.page_title' => ':title · K-Beauty Bliss',
            'store.collection.product_count' => 'لا منتجات|منتج واحد|منتجان|:formatted منتجات|:formatted منتجًا|:formatted منتج',
            'store.collection.all_products' => 'كل المنتجات',
            'store.collection.empty' => 'لا يوجد شيء هنا بعد. :link.',
            'store.collection.empty_link' => 'تصفّح المجموعة كاملة',
            'store.collection.title_new_in' => 'وصل حديثًا',
            'store.collection.intro_new_in' => 'أحدث ما وصل من العناية الكورية بالبشرة، الأحدث أولًا.',
            'store.collection.title_best_sellers' => 'الأكثر مبيعًا',
            'store.collection.title_super_sale' => 'تخفيضات كبرى',
            'store.collection.intro_super_sale' => 'كل المنتجات المخفَّضة حاليًا.',
            'store.collection.title_under_54' => 'كل ما هو أقل من AED 54',
            'store.collection.intro_under_54' => 'أشياء صغيرة تسعد، بأسعار لطيفة.',
            'store.collection.intro_best_sellers_measured' => 'المنتجات التي يعود إليها عملاؤنا دائمًا.',
            'store.collection.intro_best_sellers_by_units' => 'الأكثر مبيعًا لدينا، بحسب عدد القطع المباعة.',

            'store.concern.title_acne' => 'عناية كورية للبشرة المعرَّضة لحب الشباب',
            'store.concern.title_hydration' => 'عناية كورية للبشرة الجافة والمتعطشة للترطيب',
            'store.concern.title_dark_spots' => 'عناية كورية للبقع الداكنة وتوحيد لون البشرة',
            'store.concern.title_ageing' => 'عناية كورية للخطوط الدقيقة وشد البشرة',
            'store.concern.title_sensitivity' => 'عناية كورية للبشرة الحساسة وسريعة التهيج',
            'store.concern.title_pores' => 'عناية كورية للمسام الواسعة والبشرة الدهنية',
            'store.concern.title_dullness' => 'عناية كورية للبشرة الباهتة وإعادة الإشراق',
            'store.concern.title_sun' => 'واقي الشمس الكوري والحماية اليومية',

            'store.product_card.badge_new' => 'جديد',
            'store.product_card.label_off' => 'خصم :percent%',
            'store.product_card.label_bestseller' => 'الأكثر مبيعًا',
            'store.product_card.quick_view' => 'عرض سريع',
            'store.product_card.save_label' => 'وفّر',
            'store.product_card.add_to_cart' => 'أضف إلى السلة',
            'store.product_card.view_product' => 'عرض المنتج',
            // :countk is a compact figure like "12k" — a number and its unit,
            // and the unit is read the same way here.
            'store.product_card.count_thousands' => ':countk',
            'store.product_card.price_range' => ':low – :high',
            'store.product_grid.view_all' => 'عرض الكل',

            'store.quick_view.dialog_label' => 'عرض سريع',
            'store.quick_view.close_label' => 'إغلاق',
            'store.quick_view.loading' => 'جارٍ التحميل…',
            'store.quick_view.load_failed' => 'عذرًا — تعذّر تحميل هذا المنتج.',
        ];
    }

    /**
     * The bag, its drawer, and the set panel that sits inside both.
     *
     * The arrows in `continue_shopping` and `checkout_cta` are flipped: an
     * arrow is a direction, not a glyph, and ← in a right-to-left sentence
     * points back the way the reader came from. App\Support\Bidi already makes
     * this rule for the shop's own chevrons.
     *
     * @return array<string, string>
     */
    private static function cart(): array
    {
        return [
            'store.cart.page_title' => 'السلة · K-Beauty Bliss',
            'store.cart.continue_shopping' => '→ متابعة التسوق',
            'store.cart.heading' => 'حقيبتك',
            'store.cart.item_count' => 'لا منتجات|منتج واحد|منتجان|:count منتجات|:count منتجًا|:count منتج',
            'store.cart.empty_heading' => 'حقيبتك فارغة',
            'store.cart.empty_body' => 'اكتشف منتجات كورية أصلية وابدأ إشراقتك.',
            'store.cart.empty_cta' => 'ابدأ التسوق',
            'store.cart.free_delivery_away' => 'يفصلك :amount عن :free_delivery',
            'store.cart.free_delivery_phrase' => 'التوصيل المجاني',
            'store.cart.free_delivery_unlocked' => 'حصلت على التوصيل المجاني!',
            'store.cart.decrease_quantity' => 'إنقاص الكمية',
            'store.cart.recommended_prev' => 'المنتجات المقترحة السابقة',
            'store.cart.recommended_next' => 'مزيد من المنتجات المقترحة',
            'store.cart.increase_quantity' => 'زيادة الكمية',
            'store.cart.remove_item' => 'إزالة',
            'store.cart.summary_heading' => 'ملخص الطلب',
            'store.cart.subtotal' => 'المجموع الفرعي',
            'store.cart.remove_coupon' => 'إزالة',
            'store.cart.coupon_placeholder' => 'رمز الخصم',
            'store.cart.coupon_apply' => 'تطبيق',
            'store.cart.total' => 'الإجمالي',
            'store.cart.delivery_at_checkout' => 'يُحتسب التوصيل عند إتمام الطلب',
            'store.cart.checkout_cta' => 'إتمام الطلب ←',
            'store.cart.continue_shopping_link' => 'أو تابع التسوق',
            // Lane SEC. Drafts, like everything else in this file: read them at
            // Translation -> Strings and correct anything you would say
            // differently. No markup in either, matching their English.
            'store.cart.set_took_the_last_one' => 'تمت إزالة :product من سلتك: آخر قطعة منه موجودة داخل :set الذي تشتريه، لذا لا يمكن شراؤه بشكل منفصل أيضًا.',
            'store.cart.set_took_some' => 'تم تخفيض :product إلى :left في سلتك: الباقي منه موجود داخل :set الذي تشتريه، ولا تكفي الكمية المتوفرة للاثنين.',

            'store.cart_drawer.browsed_sold_out' => 'نفدت الكمية',
            'store.cart_drawer.browsed_in_bag' => 'في حقيبتك — أضف واحدًا آخر',
            'store.cart_drawer.browsed_add' => 'أضف إلى السلة',

            'store.set.contents' => 'في هذه المجموعة · لا منتجات|في هذه المجموعة · منتج واحد|في هذه المجموعة · منتجان|في هذه المجموعة · :count منتجات|في هذه المجموعة · :count منتجًا|في هذه المجموعة · :count منتج',
            'store.set.saving' => 'توفّر :amount',
            'store.set.whats_inside' => 'ماذا بداخلها',
            'store.set.page_eyebrow' => 'المجموعة',
            'store.set.page_heading' => 'ماذا تضم هذه المجموعة',
            'store.set.page_separately' => 'عند الشراء منفصلًا',
            'store.set.page_set_price' => 'سعر المجموعة',
            'store.set.count_note' => 'لا منتجات|منتج واحد|منتجان|:count منتجات|:count منتجًا|:count منتج',
            'store.set.show_all' => 'عرض المزيد|عرض منتج واحد إضافي|عرض منتجين إضافيين|عرض :count منتجات إضافية|عرض :count منتجًا إضافيًا|عرض :count منتج إضافي',
            'store.set.show_fewer' => 'عرض أقل',
            // The set-contents popup's own close button (Lane SA2's hanging-photo
            // box). Every other Close on this shop is إغلاق -- store.ugc.close,
            // store.quick_view.close_label, store.mobile_menu.close_label and
            // store.reviews.close_label -- so this one is too. A second spelling
            // of the same word on the same page is how an interface starts
            // reading as a translation rather than as Arabic.
            'store.set.close' => 'إغلاق',

            'store.page.page_title' => ':title · K-Beauty Bliss',
            'store.page.last_updated' => 'آخر تحديث :date',

            'store.wishlist.breadcrumb_home' => 'الرئيسية',
            'store.wishlist.breadcrumb_current' => 'المفضلة',
            'store.wishlist.title' => 'المفضلة',
            'store.wishlist.saved_count' => ':count محفوظ',
            'store.wishlist.subtitle' => 'كل ما حفظته، الأحدث أولًا.',
            'store.wishlist.keep_browsing' => 'تابع التصفح',
            'store.wishlist.empty_title' => 'لم تحفظ شيئًا بعد',
            'store.wishlist.empty_body' => 'اضغط على القلب في أي منتج للاحتفاظ به هنا.',
            'store.wishlist.empty_cta' => 'ابدأ التصفح',
            'store.wishlist.grid_label' => 'المحفوظة',
            'store.wishlist.page_title' => 'المفضلة · K-Beauty Bliss',
        ];
    }

    /** @return array<string, string> */
    private static function home(): array
    {
        return [
            'store.home.cards_banner_nav' => 'بطاقات البانر',
            'store.home.cards_banner_go' => 'الانتقال إلى البطاقة :n',
            'store.home.slider_previous' => 'السابق',
            'store.home.slider_next' => 'التالي',
            'store.home.banner_slider_label' => 'عارض الصور',
            'store.home.banner_slider_prev' => 'الصورة السابقة',
            'store.home.banner_slider_next' => 'الصورة التالية',
            'store.home.banner_slider_bars' => 'اختر صورة',
            'store.home.banner_slider_go' => 'عرض الصورة :n',
            'store.home.banner_slider_slide' => 'الصورة :n من :total',
            'store.home.banner_slider_live' => 'الصورة :n من :total',
            'store.home.banner_slider_pause' => 'إيقاف العرض مؤقتًا',
            'store.home.banner_slider_play' => 'تشغيل العرض',
            'store.home.category_product_count' => 'لا منتجات|منتج واحد|منتجان|:count منتجات|:count منتجًا|:count منتج',
            'store.home.bundles_heading' => 'مجموعات بتوفير كبير',
            'store.home.bundles_count' => 'لا مجموعات|مجموعة واحدة|مجموعتان|:count مجموعات|:count مجموعة|:count مجموعة',
            'store.home.bundles_subtitle' => 'روتين متكامل بسعر أقل من مجموع قطعه.',
            'store.home.bundles_link' => 'كل المجموعات',
            'store.home.bundles_grid_label' => 'مجموعات العناية بالبشرة',
            'store.home.recommended_heading' => 'مقترح لك',
            'store.home.recommended_badge' => 'يُحدَّث يوميًا',
            'store.home.recommended_subtitle' => 'أساسيات كورية مختارة لبشرة مشرقة.',
            'store.home.recommended_link' => 'تسوق المزيد',
            'store.home.recommended_grid_label' => 'المقترحة',
            'store.home.routine_heading' => 'ابنِ روتينك',
            'store.home.routine_steps' => 'لا خطوات|خطوة واحدة|خطوتان|:count خطوات|:count خطوة|:count خطوة',
            'store.home.routine_link' => 'دليل الروتين',
            'store.home.routine_add_all' => 'أضف الروتين كاملًا · :amount',
            'store.home.quiz_kicker' => 'دقيقتان · مجانًا',
            // The <br> is layout, not words: it is what keeps the heading on two
            // lines in the band it sits in, so it stays where the Arabic breaks.
            'store.home.quiz_heading' => 'لا تعرف من<br>أين تبدأ؟',
            'store.home.quiz_body' => 'أجب عن خمسة أسئلة وسنبني لك روتينًا مما نوفّره فعلًا — مع سبب كل اختيار.',
            'store.home.quiz_stat_matched' => 'منتج مطابق',
            'store.home.quiz_stat_questions' => 'أسئلة سريعة',
            'store.home.quiz_stat_cost' => 'التكلفة، بلا تسجيل',
            'store.home.quiz_step' => 'السؤال :current من :total',
            'store.home.quiz_question' => 'كيف تشعر بشرتك عادةً بعد الظهر؟',
            'store.home.quiz_option_oily' => 'لامعة بالكامل',
            'store.home.quiz_skin_oily' => 'دهنية',
            'store.home.quiz_option_dry' => 'مشدودة أو متقشرة',
            'store.home.quiz_skin_dry' => 'جافة',
            'store.home.quiz_option_combo' => 'منطقة T دهنية والخدود جافة',
            'store.home.quiz_skin_combo' => 'مختلطة',
            'store.home.quiz_option_sensitive' => 'محمرّة أو تلسع',
            'store.home.quiz_skin_sensitive' => 'حساسة',
            'store.home.quiz_option_normal' => 'مرتاحة، بلا تغيّر',
            'store.home.quiz_skin_normal' => 'عادية',
            'store.home.quiz_hint' => 'اختر الأقرب',
            'store.home.quiz_continue' => 'متابعة ←',
            'store.home.brands_heading' => 'أبرز الماركات',
            'store.home.brands_count' => 'لا ماركات|ماركة واحدة|ماركتان|:count ماركات|:count ماركة|:count ماركة',
            'store.home.brands_link' => 'كل الماركات',
            // A hashtag is an address on Instagram, not a phrase. Translating it
            // would point at a tag that does not exist.
            'store.home.spotted_heading' => '#KBeautyBliss على إنستغرام',
            'store.home.spotted_badge' => 'قابل للتسوق',
            'store.home.spotted_subtitle' => 'روتين حقيقي من مجتمعنا.',
            'store.home.spotted_link' => 'اكتشف المزيد',
            'store.home.bestsellers_heading' => 'الأكثر مبيعًا',
            'store.home.bestsellers_badge' => 'هذا الشهر',
            'store.home.bestsellers_subtitle' => 'المنتجات التي يعود إليها العملاء دائمًا.',
            'store.home.bestsellers_link' => 'تسوق المزيد',
            'store.home.bestsellers_grid_label' => 'الأكثر مبيعًا',
            'store.home.flash_heading' => 'عرض سريع · خصم حتى 50%',
            'store.home.flash_badge' => 'حتى نفاد الكمية',
            'store.home.flash_subtitle' => 'خصومات كبيرة وكميات محدودة.',
            'store.home.flash_link' => 'عرض الكل',
            'store.home.flash_grid_label' => 'العرض السريع',
            'store.home.journal_heading' => 'دليل العناية بالبشرة',
            'store.home.journal_badge' => 'المدونة',
            'store.home.journal_subtitle' => 'اقرأ قبل أن تشتري.',
            'store.home.journal_link' => 'كل المقالات',
            'store.home.read_minutes' => 'قراءة سريعة|قراءة دقيقة واحدة|قراءة دقيقتين|قراءة :count دقائق|قراءة :count دقيقة|قراءة :count دقيقة',
            'store.home.about_heading' => 'عن K-Beauty Bliss',
            'store.home.about_body' => 'نحن شغوفون بتقديم أفضل ما في الجمال الكوري لعشّاق العناية بالبشرة في دولة الإمارات. كل منتج مختار بعناية ليلبي أعلى معايير الجودة والفعالية.',
            'store.home.about_stat_products' => 'منتج متوفر',
            'store.home.about_stat_brands' => 'ماركة كورية',
            'store.home.about_stat_reviews' => 'تقييم موثّق',
            'store.home.count_thousands_plus' => ':countk+',
            'store.home.about_link' => 'قصتنا',
            'store.home.reviews_heading' => 'ماذا يقول عملاؤنا',
            'store.home.reviews_average' => 'متوسط :rating',
            'store.home.reviews_subtitle' => 'لا تقييمات موثّقة من طلبات حقيقية.|:formatted تقييم موثّق من طلبات حقيقية.|:formatted تقييمان موثّقان من طلبات حقيقية.|:formatted تقييمات موثّقة من طلبات حقيقية.|:formatted تقييمًا موثّقًا من طلبات حقيقية.|:formatted تقييم موثّق من طلبات حقيقية.',
            'store.home.reviews_count' => 'لا تقييمات|تقييم واحد|تقييمان|:formatted تقييمات|:formatted تقييمًا|:formatted تقييم',
            'store.home.reviews_link' => 'اقرأ الكل',
            'store.home.trust_delivery_title' => 'التوصيل',
            'store.home.trust_free_over' => 'مجاني فوق :amount',
            'store.home.trust_payments_title' => 'دفع آمن',
            'store.home.trust_payments_text' => 'بطاقة، Tabby، Tamara والدفع عند الاستلام',
            'store.home.trust_support_text' => 'WhatsApp :phone',
        ];
    }

    /** @return array<string, string> */
    private static function reviews(): array
    {
        return [
            'store.reviews.filter_all' => 'الكل',
            'store.reviews.filter_photos' => 'مع صور',
            'store.reviews.filter_helpful' => 'الأكثر إفادة',
            'store.reviews.verified_badge' => '✓ موثّق',
            'store.reviews.eyebrow' => 'نال إعجابكم',
            'store.reviews.heading' => 'تقييمات العملاء',
            'store.reviews.short_heading' => 'التقييمات',
            'store.reviews.review_count' => 'لا تقييمات|تقييم واحد|تقييمان|:count تقييمات|:count تقييمًا|:count تقييم',
            'store.reviews.write_button' => '✎ اكتب تقييمًا',
            'store.reviews.write_link' => 'اكتب تقييمًا',
            'store.reviews.filter_with_photos' => 'مع صور',
            'store.reviews.reply_from' => 'رد من K-Beauty Bliss',
            'store.reviews.load_more' => 'عرض تقييمات أكثر',
            'store.reviews.close_label' => 'إغلاق',
            'store.reviews.form_heading' => 'شاركنا تجربتك ♡',
            'store.reviews.field_rating' => 'تقييمك',
            'store.reviews.field_name' => 'الاسم',
            'store.reviews.field_name_placeholder' => 'الاسم الأول',
            'store.reviews.field_email' => 'البريد الإلكتروني',
            'store.reviews.field_email_note' => '(لا يُعرض)',
            'store.reviews.field_email_placeholder' => 'you@email.com',
            'store.reviews.field_title' => 'العنوان',
            'store.reviews.field_title_placeholder' => 'اختصرها في سطر ✨',
            // NOT تقييمك, which is what field_rating above says. These are two
            // labels on ONE form -- the star picker and the textarea under it --
            // and giving both the same word leaves the shopper with two
            // identically-labelled controls.
            'store.reviews.field_review' => 'رأيك',
            'store.reviews.field_review_placeholder' => 'أخبرنا بما أعجبك…',
            'store.reviews.field_photos' => 'أضف صورًا',
            'store.reviews.field_photos_hint' => '(اختياري)|(اختياري، صورة واحدة كحد أقصى)|(اختياري، صورتان كحد أقصى)|(اختياري، حتى :count صور)|(اختياري، حتى :count صورة)|(اختياري، حتى :count صورة)',
            'store.reviews.photos_cta' => '📷 اضغط لإضافة صور',
            'store.reviews.field_captcha' => 'تحقق سريع:',
            'store.reviews.field_captcha_placeholder' => 'الإجابة',
            'store.reviews.submit' => 'إرسال التقييم',
            'store.reviews.empty' => 'لا توجد تقييمات بعد — كن أول من يقيّم هذا المنتج.',
            'store.reviews.loved_by' => 'لم يقيّمه أحد بعد|أعجب :formatted متسوق|أعجب :formatted متسوقين|أعجب :formatted متسوقين|أعجب :formatted متسوقًا|أعجب :formatted متسوق',
            'store.reviews.review_count_formatted' => 'لا تقييمات|تقييم واحد|تقييمان|:formatted تقييمات|:formatted تقييمًا|:formatted تقييم',
            'store.reviews.time_ago' => 'قبل :time',
        ];
    }

    /** @return array<string, string> */
    private static function product(): array
    {
        return [
            // `{n}` is Laravel's own explicit-count condition, not a placeholder
            // this file may rename — MessageSelector::extractFromString reads it
            // before any plural rule runs.
            'store.product.review_badge_label' => '{n} تقييمات',
            'store.product.sold_thousands' => 'بيع منها :countk+',
            'store.product.choose_option' => 'اختر الخيار المناسب',
            'store.product.option_fallback' => 'الخيار :number',
            'store.product.sold_out_tag' => 'نفدت الكمية',
            'store.product.save_percent' => 'وفّر :percent%',
            'store.product.bundles_note' => 'وفّر أكثر مع المجموعات',
            'store.product.stock_sold_out' => 'نفدت الكمية — تابعنا قريبًا',
            'store.product.stock_low' => 'نفدت الكمية|بقيت قطعة واحدة · سارع بالطلب|بقيت قطعتان · سارع بالطلب|بقيت :count قطع · سارع بالطلب|بقيت :count قطعة · سارع بالطلب|بقيت :count قطعة · سارع بالطلب',
            'store.product.stock_in' => 'متوفر · جاهز للشحن',
            'store.product.cutoff_delivery' => 'اطلب خلال :remaining ليصلك في :date',
            'store.product.cutoff_dispatch' => 'اطلب خلال :remaining ليُشحن في :ship',
            'store.product.buy_now' => 'اشترِ الآن',
            'store.product.trust_pay_later' => 'Tabby و Tamara',
            'store.product.details_eyebrow' => 'التفاصيل',
            'store.product.details_heading' => 'تفاصيل المنتج',
            'store.product.read_more' => 'اقرأ المزيد ↓',
            'store.product.tab_description' => 'الوصف',
            'store.product.tab_ingredients' => 'المكونات',
            'store.product.tab_how_to_use' => 'طريقة الاستخدام',
            'store.product.related_eyebrow' => 'أكمل روتينك',
            'store.product.related_heading' => 'قد يعجبك أيضًا',
            'store.product.save_to_wishlist' => 'أضف إلى المفضلة',
            'store.product.gallery_front' => 'الأمام',

            'store.fbt.title' => 'أكمل روتينك',
            'store.fbt.total' => 'الإجمالي: :amount',
            'store.fbt.add_selected' => 'أضف المحدد إلى السلة',

            'store.quick_view.in_stock' => 'متوفر',
            'store.quick_view.out_of_stock' => 'غير متوفر',
            'store.quick_view.view_full' => 'عرض التفاصيل كاملة',

            'store.notify_me.email_label' => 'بريدك الإلكتروني',
            'store.notify_me.email_placeholder' => 'you@example.com',
            'store.notify_me.submit' => 'أبلغني بالبريد',
            'store.notify_me.privacy' => 'سنراسلك مرة واحدة فقط عند توفر هذا المنتج. يُستخدم عنوانك لهذا الغرض وحده — لا يُضاف إلى قائمتنا البريدية، وكل رسالة تحتوي على رابط لإلغاء الاشتراك.',

            'store.cart_reminder.email_label' => 'بريدك الإلكتروني',
            'store.cart_reminder.email_placeholder' => 'you@example.com',
            'store.cart_reminder.submit' => 'ذكّرني',
            'store.cart_reminder.privacy' => 'سنحفظ بريدك الإلكتروني مع هذه السلة لنذكّرك بها. وإذا أتممت طلبك نتوقف. كل تذكير يحتوي على رابط لإلغاء الاشتراك، واستخدامه يوقف هذه الرسائل نهائيًا.',
        ];
    }

    /**
     * The checkout, its four steps, its card fields and its totals.
     *
     * The phone placeholder keeps its Latin digits and its +971: it is the
     * SHAPE of a number the shopper is about to type, and a placeholder written
     * in Arabic-Indic digits would not match what their keyboard produces.
     * docs/BILINGUAL-PLAN.md makes the same call for prices.
     *
     * @return array<string, string>
     */
    private static function checkout(): array
    {
        return [
            'store.checkout.secure_badge' => 'دفع آمن',
            'store.checkout.page_title' => 'إتمام الطلب · K-Beauty Bliss',
            'store.checkout.back_to_shop' => '→ العودة إلى المتجر',
            'store.checkout.heading' => 'إتمام الطلب',
            'store.checkout.lead' => 'اقتربت من الإشراق — بقيت بعض التفاصيل.',
            'store.checkout.back_to_cart' => 'العودة إلى السلة',
            'store.checkout.coupon_prompt' => 'لديك رمز خصم؟',
            'store.checkout.coupon_placeholder' => 'أدخل رمز الخصم',
            'store.checkout.coupon_apply' => 'تطبيق',
            'store.checkout.step_contact' => 'بيانات التواصل',
            'store.checkout.step_shipping' => 'عنوان الشحن',
            'store.checkout.step_delivery' => 'التوصيل',
            'store.checkout.step_payment' => 'الدفع',
            'store.checkout.field_email' => 'البريد الإلكتروني',
            'store.checkout.field_email_placeholder' => 'you@email.com',
            'store.checkout.field_phone' => 'رقم الهاتف',
            'store.checkout.field_phone_placeholder' => '+971 5x xxx xxxx',
            'store.checkout.field_full_name' => 'الاسم الكامل',
            'store.checkout.field_full_name_placeholder' => 'الاسم الأول واسم العائلة',
            'store.checkout.field_first_name' => 'الاسم الأول',
            'store.checkout.field_last_name' => 'اسم العائلة',
            'store.checkout.field_address' => 'العنوان',
            'store.checkout.field_address_placeholder' => 'الشارع، رقم المبنى / الفيلا',
            'store.checkout.field_state' => 'الإمارة',
            'store.checkout.field_city' => 'المدينة / المنطقة',
            'store.checkout.field_city_placeholder' => 'مثال: جزيرة الريم',
            'store.checkout.field_country' => 'الدولة',
            'store.checkout.country_detected' => 'تم التعرف عليها',
            'store.checkout.field_notes' => 'ملاحظات التوصيل',
            'store.checkout.field_notes_placeholder' => 'تعليمات التوصيل، علامة مميزة، أو وقت مفضل',
            'store.checkout.optional_note' => '(اختياري)',
            'store.checkout.validate_required' => 'يرجى تعبئة هذا الحقل.',
            'store.checkout.validate_email' => 'يرجى إدخال بريد إلكتروني صحيح، مثل you@email.com.',
            'store.checkout.validate_state' => 'يرجى الاختيار من القائمة.',
            'store.checkout.validate_phone' => 'يرجى إدخال رقم هاتف صحيح، أو اترك الحقل فارغًا.',
            'store.checkout.create_account' => 'أنشئ حسابًا لإتمام طلبك بسرعة في المرة القادمة',
            'store.checkout.password_placeholder' => 'اختر كلمة مرور (8 أحرف أو أكثر)',
            'store.checkout.whatsapp_optin' => 'أرسلوا لي تحديثات الطلب والعروض الجديدة — عبر WhatsApp والبريد الإلكتروني.',
            'store.checkout.unserved_country' => 'لا نقوم بالتوصيل إلى :country حتى الآن. اختر دولة أخرى من الأعلى، أو تواصل معنا وسنرى ما يمكننا فعله.',
            'store.checkout.delivery_loading' => 'جارٍ تحميل خيارات التوصيل…',
            'store.checkout.gift_option' => 'هذا الطلب هدية',
            'store.checkout.gift_note_placeholder' => 'رسالتك، تُطبع على بطاقة الهدية',
            'store.checkout.gift_characters_left' => 'لم يتبقَ حرف|بقي حرف واحد|بقي حرفان|بقيت :remaining أحرف|بقي :remaining حرفًا|بقي :remaining حرف',
            'store.checkout.or_pay_with' => 'أو ادفع عبر',
            'store.checkout.no_payment_method' => 'لا تتوفر وسيلة دفع لإجمالي هذا الطلب. يرجى التواصل معنا وسنستلم طلبك مباشرة.',
            'store.checkout.card_secure_line' => 'آمن ومشفّر 100% — استخدم أي بطاقة',
            'store.checkout.card_number_label' => 'رقم البطاقة',
            'store.checkout.card_expiry_label' => 'تاريخ الانتهاء',
            'store.checkout.card_cvc_label' => 'رمز الحماية',
            'store.checkout.card_save' => 'احفظ هذه البطاقة لعمليات الشراء القادمة.',
            'store.checkout.card_return_to_basket' => 'إلغاء هذه العملية والعودة إلى سلتك',
            'store.checkout.card_not_ready' => 'ما زال نموذج البطاقة قيد التحميل. يرجى الانتظار لحظة والمحاولة مرة أخرى.',
            'store.checkout.card_generic_error' => 'تعذّر قبول هذه البطاقة. يرجى التحقق من البيانات أو تجربة بطاقة أخرى.',
            'store.checkout.card_working' => 'جارٍ تأكيد الدفع…',

            /*
             * THE WALLET ROW (Apple Pay / Google Pay), which landed after the
             * 1,018 were written and so had no Arabic at all.
             *
             * These are the only four wallet sentences a shopper can read that
             * this shop composes. Everything else in that row -- the button
             * label itself, and every decline reason -- is drawn by Stripe and
             * is NOT ours to key; see the note on locale in
             * partials/checkout/express-wallets.blade.php.
             *
             * wallet_working matches card_working word for word because the
             * English does: both are "Confirming your payment…" and a shopper
             * who taps Apple Pay and a shopper who types a card are being told
             * the same thing at the same moment.
             */
            'store.checkout.wallet_details_first' => 'يرجى إكمال بيانات التواصل والتوصيل بالأعلى أولًا، ثم اضغط مرة أخرى.',
            'store.checkout.wallet_total_moved' => 'تغيّر إجمالي طلبك أثناء فتح نافذة الدفع، ولم يُخصم أي مبلغ. سلتك محفوظة — يرجى مراجعة الإجمالي والمحاولة مرة أخرى.',
            'store.checkout.wallet_failed' => 'لم تتم عملية الدفع ولم يُخصم أي مبلغ. سلتك محفوظة — يرجى المحاولة مرة أخرى أو الدفع بالبطاقة بالأسفل.',
            'store.checkout.wallet_working' => 'جارٍ تأكيد الدفع…',
            'store.checkout.tab_summary' => 'ملخص الطلب',
            'store.checkout.tab_browsed' => 'تصفحتها',
            'store.checkout.browsed_heading' => 'تصفحتها مؤخرًا — أضفها بضغطة',
            'store.checkout.browsed_add' => 'أضف',
            'store.checkout.browsed_add_label' => 'أضف :product إلى السلة',
            'store.checkout.browsed_empty' => 'كل ما اطّلعت عليه موجود في حقيبتك بالفعل.',
            'store.checkout.remove_item_label' => 'إزالة :product',
            'store.checkout.view_summary_open' => 'عرض الملخص كاملًا ▾',
            'store.checkout.view_summary_close' => 'إخفاء الملخص ▴',
            'store.checkout.subtotal' => 'المجموع الفرعي',
            'store.checkout.delivery' => 'التوصيل',
            'store.checkout.free' => 'مجاني',
            'store.checkout.gift_wrapping' => 'تغليف الهدية',
            'store.checkout.cod_fee' => 'رسوم الدفع عند الاستلام',
            'store.checkout.total' => 'الإجمالي',
            // NOT إتمام الطلب, which is the page's own heading four keys up.
            // The heading names the page and the button names the act, and on
            // this page they are inches apart.
            'store.checkout.place_order' => 'تأكيد الطلب',
            'store.checkout.freeship_done' => 'تهانينا! حصلت على التوصيل المجاني',
            'store.checkout.thumbs_your_bag' => 'حقيبتك',
            'store.checkout.thumbs_your_order' => 'طلبك',
            'store.checkout.thumbs_youre_ordering' => 'أنت تطلب',
            'store.checkout.ssl_secure' => 'محمي بـ SSL',
            'store.checkout.arrives_in' => 'يصل خلال :eta',
            'store.checkout.discount' => 'الخصم',
            'store.checkout.payment_fee' => 'رسوم :method',

            // The place-order overlay (Lane PLC). جارٍ, not يتم: the shopper is
            // watching it happen, not being told that it is arranged.
            'store.checkout.placing_label' => 'جارٍ تنفيذ طلبك',
            'store.checkout.placing_title' => 'جارٍ تنفيذ طلبك…',
            'store.checkout.placing_note' => 'من فضلك أبقِ هذه الصفحة مفتوحة.',
            'store.checkout.placing_leaving' => 'جارٍ نقلك إلى :provider…',
            'store.checkout.placing_leaving_note' => 'وافق على الدفع هناك وستعود إلى هنا مباشرة.',
            'store.checkout.placing_done' => 'تم تنفيذ الطلب',
            'store.checkout.placing_failed' => 'تعذّر تنفيذ طلبك. من فضلك حاول مرة أخرى.',
            'store.checkout.placing_offline' => 'تعذّر الوصول إلى المتجر. تحقّق من اتصالك وحاول مرة أخرى.',
            'store.checkout.placing_expired' => 'هذه الصفحة مفتوحة منذ وقت طويل. من فضلك حدّثها وحاول مرة أخرى — لم يُخصم أي مبلغ.',
            'store.checkout.placing_no_answer' => 'لم نتلقَّ ردًا من المتجر. قد يكون طلبك قد نُفّذ بالفعل — من فضلك تحقّق من بريدك الإلكتروني قبل المحاولة مرة أخرى.',
            'store.checkout.placing_redirect_stuck' => 'تعذّر فتح :provider تلقائيًا.',
            'store.checkout.placing_redirect_link' => 'المتابعة إلى :provider',
            'store.checkout.return_not_completed' => 'لم يكتمل الدفع، لذلك لم يتم تنفيذ طلبك. ولم يُخصم أي مبلغ.',
            'store.checkout.return_not_completed_at' => 'لم يكتمل الدفع لدى :provider، لذلك لم يتم تنفيذ طلبك. ولم يُخصم أي مبلغ.',
            'store.checkout.restore_basket' => 'أعد حقيبتي',
            'store.checkout.restore_done' => 'عادت حقيبتك. تم إلغاء الطلب الذي لم يكتمل ولم يُخصم أي مبلغ.',
            'store.checkout.restore_gone' => 'لا يوجد ما يمكن إعادته. حقيبتك كما تركتها.',
        ];
    }

    /** @return array<string, string> */
    private static function orderReceived(): array
    {
        return [
            'store.order_received.page_title' => 'تم استلام الطلب · K-Beauty Bliss',
            'store.order_received.header_badge' => 'تم استلام الطلب',
            'store.order_received.thank_you' => 'شكرًا لك',
            'store.order_received.lead' => 'تم تأكيد طلبك :number. وسنرسل نسخة إلى :email.',
            'store.order_received.fact_order_number' => 'رقم الطلب',
            'store.order_received.fact_total_paid' => 'الإجمالي المدفوع',
            'store.order_received.fact_total_to_pay' => 'الإجمالي المستحق',
            'store.order_received.fact_payment' => 'الدفع',
            'store.order_received.fact_delivery' => 'التوصيل',
            'store.order_received.next_heading' => 'ما الخطوة التالية',
            'store.order_received.act_orders' => 'طلباتك',
            'store.order_received.act_orders_note' => 'كل الطلبات على حسابك، ومنها هذا الطلب',
            'store.order_received.act_sign_in' => 'سجّل الدخول إلى حسابك',
            'store.order_received.act_sign_in_note' => 'حسابك جاهز — استخدم :email',
            'store.order_received.act_track' => 'تتبع طلبك',
            'store.order_received.act_track_note' => 'الطلب رقم :number — سنطلب بريدك الإلكتروني للتأكد من هويتك',
            'store.order_received.act_home' => 'الذهاب إلى الرئيسية',
            'store.order_received.act_home_note' => 'العودة إلى واجهة المتجر',
            'store.order_received.account_heading' => 'أكمل إنشاء حسابك',
            'store.order_received.account_username' => ':email هو اسم المستخدم الخاص بك',
            'store.order_received.account_prompt' => 'اختر كلمة مرور لإتمام الإنشاء.',
            'store.order_received.account_password_label' => 'اختر كلمة مرور',
            'store.order_received.account_password_placeholder' => 'كلمة المرور (8 أحرف فأكثر)',
            'store.order_received.account_save' => 'حفظ',
            'store.order_received.summary_heading' => 'طلبك',
            'store.order_received.address_heading' => 'التوصيل إلى',
            'store.order_received.address_unknown' => 'سنؤكد عنوان التوصيل عبر البريد الإلكتروني.',
            'store.order_received.your_note' => 'ملاحظتك',
            'store.order_received.gift_wrapped' => 'مغلَّف كهدية 🎁',
            'store.order_received.gift_note' => '«:note» — مطبوعة على بطاقة الهدية.',
            'store.order_received.gift_no_note' => 'طلبك مغلَّف كهدية.',
            'store.order_received.not_found_heading' => 'لم يتم العثور على الطلب',
            'store.order_received.not_found_body' => 'تعذّر العثور على هذا الطلب. إذا كنت قد أنشأت طلبًا للتو، فرسالة التأكيد تحتوي على رابط يفتحه.',
            'store.order_received.not_found_track' => 'تتبع طلبًا ببريدك الإلكتروني ←',
            'store.order_received.show_more' => 'عرض المزيد|عرض منتج واحد إضافي|عرض منتجين إضافيين|عرض :count منتجات إضافية|عرض :count منتجًا إضافيًا|عرض :count منتج إضافي',
            'store.order_received.show_fewer' => 'عرض عدد أقل',

            // The return leg (Lane PLC).
            'store.order_received.placed_label' => 'تم تنفيذ الطلب',
            'store.order_received.placed_title' => 'تم تنفيذ الطلب',
            'store.order_received.confirming_title' => 'جارٍ تأكيد الدفع…',
            'store.order_received.confirming_note' => 'يستغرق ذلك عادةً بضع ثوانٍ.',
            'store.order_received.confirming_slow' => 'ما زال تأكيد الدفع جاريًا. تفاصيل طلبك بالأسفل، وسنراسلك بالبريد الإلكتروني فور اكتماله.',
        ];
    }

    /** @return array<string, string> */
    private static function brands(): array
    {
        return [
            'store.brands.all_heading' => 'كل الماركات',
            'store.brands.all_subtitle' => 'لا ماركات بعد.|ماركة واحدة، :stocked منها متوفرة في المتجر الآن.|ماركتان، :stocked منهما متوفرة في المتجر الآن.|:total ماركات، :stocked منها متوفرة في المتجر الآن.|:total ماركة، :stocked منها متوفرة في المتجر الآن.|:total ماركة، :stocked منها متوفرة في المتجر الآن.',
            'store.brands.none_yet' => 'لم تُضف أي ماركات بعد.',
            'store.brands.shop_all' => 'تسوق كل :brand',
            'store.brands.brand_empty' => 'لا يتوفر شيء من هذه الماركة في المتجر حاليًا.',
            'store.brands.popular_heading' => 'الأكثر رواجًا الآن',
            'store.brands.card_count' => 'لا منتجات|منتج واحد|منتجان|:count منتجات|:count منتجًا|:count منتج',
            'store.brands.card_coming_soon' => 'قريبًا',
        ];
    }

    /** @return array<string, string> */
    private static function journal(): array
    {
        return [
            'store.journal.nav_shop' => 'التسوق',
            'store.journal.nav_quiz' => 'اختبار البشرة',
            'store.journal.nav_journal' => 'المدونة',
            'store.journal.eyebrow' => 'مدونة الإشراق',
            'store.journal.heading' => 'نصائح العناية بالبشرة ومختارات الجمال الكوري',
            'store.journal.subtitle' => 'أدلة صادقة عن الروتين والمكونات والحماية من الشمس — مكتوبة لدولة الإمارات.',
            'store.journal.read_more' => 'اقرأ المزيد ←',
            'store.journal.empty' => 'لا مقالات بعد — تابعنا قريبًا.',
            'store.journal.footer_line' => '© K-Beauty Bliss · جمال كوري أصلي في الإمارات',
            'store.journal.more_heading' => 'المزيد من المدونة',
            'store.journal.back_to_index' => 'العودة إلى المدونة',
        ];
    }

    /** @return array<string, string> */
    private static function orders(): array
    {
        return [
            'store.orders.page_title' => 'الطلبات · K-Beauty Bliss',
            'store.orders.heading' => 'الطلبات',
            'store.orders.subtitle' => 'كل ما طلبته.',
            'store.orders.order_number' => 'الطلب رقم :number',
            'store.orders.detail_title' => 'الطلب رقم :number · K-Beauty Bliss',
            'store.orders.all_orders' => 'كل الطلبات',
            'store.orders.placed_on' => 'أُنشئ في :date',
            'store.orders.what_you_ordered' => 'ما طلبته',
            'store.orders.delivery_and_payment' => 'التوصيل والدفع',
            'store.orders.pager_label' => 'صفحات الطلبات',
            'store.orders.pager_newer' => 'الأحدث',
            'store.orders.pager_older' => 'الأقدم',
            'store.orders.pager_page' => 'صفحة :current من :total',

            'store.order_status.pending' => 'قيد الانتظار',
            'store.order_status.processing' => 'قيد التجهيز',
            'store.order_status.paid' => 'مدفوع',
            // Two spellings of one state, because the data carries both.
            'store.order_status.onhold' => 'معلّق',
            'store.order_status.on-hold' => 'معلّق',
            'store.order_status.completed' => 'مكتمل',
            'store.order_status.shipped' => 'تم الشحن',
            'store.order_status.cancelled' => 'ملغى',
            'store.order_status.refunded' => 'مسترد',
            'store.order_status.failed' => 'فشل',
        ];
    }

    /** @return array<string, string> */
    private static function account(): array
    {
        return [
            'store.account.dashboard_title' => 'حسابي',
            'store.account.card_orders' => 'الطلبات',
            'store.account.card_orders_note' => 'كل ما طلبته',
            'store.account.card_wishlist' => 'المفضلة',
            'store.account.card_wishlist_note' => 'محفوظة لوقت لاحق',
            'store.account.card_addresses' => 'العناوين',
            'store.account.card_addresses_note' => 'أين نوصّل',
            'store.account.card_track' => 'تتبع طلبًا',
            'store.account.card_track_note' => 'أين وصلت شحنتك',
            'store.account.recent_orders' => 'أحدث الطلبات',
            'store.account.orders_empty' => 'لا يوجد شيء هنا بعد. :link.',
            'store.account.orders_empty_link' => 'ابدأ التسوق',
            'store.account.see_all_orders' => 'عرض كل الطلبات',
            'store.account.sign_in_title' => 'تسجيل الدخول',
            'store.account.register_title' => 'إنشاء حساب',
            'store.account.sign_in_heading' => 'أهلًا بعودتك',
            'store.account.sign_in_lead' => 'سجّل الدخول لعرض طلباتك ومفضلتك.',
            'store.account.register_heading' => 'أنشئ حسابك',
            'store.account.register_lead' => 'لا يستغرق الأمر سوى دقيقة.',
            'store.account.forgot_link' => 'نسيت كلمة المرور؟',
            'store.account.new_here' => 'جديد هنا؟ :link',
            'store.account.new_here_link' => 'أنشئ حسابًا',
            'store.account.have_account' => 'لديك حساب بالفعل؟ :link',
            'store.account.password_hint' => 'ثمانية أحرف أو أكثر، مع مزيج من الحروف والأرقام.',
            'store.account.terms_notice' => 'بمتابعتك فأنت توافق على :terms و:privacy.',
            'store.account.aside_heading' => 'لماذا تنشئ حسابًا',
            'store.account.aside_point_orders' => 'طلباتك وحالتها في مكان واحد',
            'store.account.aside_point_checkout' => 'إتمام الطلب دون إعادة كتابة عنوانك',
            'store.account.aside_point_wishlist' => 'مفضلتك محفوظة على كل أجهزتك',
            'store.account.aside_point_restocks' => 'أسبقية عند عودة المنتجات',
            'store.account.aside_free_delivery' => 'توصيل مجاني للطلبات فوق :amount.',
            'store.account.forgot_title' => 'إعادة تعيين كلمة المرور',
            'store.account.forgot_heading' => 'أعد تعيين كلمة المرور',
            'store.account.forgot_lead' => 'أدخل بريدك الإلكتروني وسنرسل لك رابطًا لتعيين كلمة مرور جديدة.',
            'store.account.forgot_submit' => 'أرسل الرابط',
            'store.account.back_to_sign_in' => 'العودة إلى تسجيل الدخول',
            'store.account.reset_title' => 'تعيين كلمة مرور جديدة',
            'store.account.reset_new_link' => 'اطلب رابطًا جديدًا',
            'store.account.reset_lead' => 'اختر كلمة مرور لم تستخدمها هنا من قبل. 8 أحرف على الأقل.',
            'store.account.reset_legacy_note' => 'ملاحظة: ستتوقف كلمة مرورك الأصلية في K Beauty Bliss عن العمل بمجرد حفظ هذه.',
            'store.account.reset_field_password' => 'كلمة المرور الجديدة',
            'store.account.reset_field_confirm' => 'تأكيد كلمة المرور الجديدة',
            'store.account.reset_submit' => 'حفظ كلمة المرور الجديدة',
            'store.account.reset_signed_out_note' => 'سيتم إنهاء تسجيل دخولك في كل مكان آخر، لذا ستحتاج إلى تسجيل الدخول مجددًا على أجهزتك الأخرى.',
            'store.account.show_password' => 'إظهار كلمة المرور',
            'store.account.welcome_title' => 'عيّن كلمة المرور',
            'store.account.welcome_lead' => 'حسابك في :shop جاهز. اختر كلمة مرور لإكمال إعداده. 8 أحرف على الأقل.',
            'store.account.welcome_for' => 'الحساب: :email',
            'store.account.welcome_submit' => 'احفظ كلمة المرور وسجّل الدخول',
            'store.account.welcome_invalid' => 'هذا الرابط لم يعد صالحًا. روابط الحساب تعمل مرة واحدة وتنتهي صلاحيتها بعد بضعة أيام. لا يزال بإمكانك تعيين كلمة مرور برابط جديد.',

            'store.verify.notice_title' => 'أكّد بريدك الإلكتروني',
            'store.verify.already_done' => 'تم تأكيد عنوانك. لا يوجد ما تفعله هنا.',
            'store.verify.will_send' => 'سنرسل رابطًا إلى :email. افتحه ليتم تأكيد العنوان.',
            'store.verify.back_to_account' => 'العودة إلى حسابك',
            'store.verify.result_ok_title' => 'تم تأكيد البريد الإلكتروني',
            'store.verify.result_bad_title' => 'رابط التأكيد',
            'store.verify.result_bad_heading' => 'لم يعمل هذا الرابط',
            'store.verify.go_to_account' => 'الذهاب إلى حسابك',
        ];
    }

    /** @return array<string, string> */
    private static function addresses(): array
    {
        return [
            'store.addresses.page_title' => 'العناوين',
            'store.addresses.heading' => 'العناوين',
            'store.addresses.subtitle' => 'أين نوصّل. يُستخدم عنوانك الافتراضي أولًا عند إتمام الطلب.',
            'store.addresses.empty' => 'لا عناوين محفوظة بعد. أضف عنوانًا أدناه وسيُعرض عند إتمام الطلب.',
            'store.addresses.badge_default' => 'افتراضي',
            'store.addresses.edit' => 'تعديل',
            'store.addresses.make_default' => 'اجعله افتراضيًا',
            'store.addresses.delete' => 'حذف',
            'store.addresses.delete_confirm' => 'هل تريد حذف هذا العنوان؟',
            'store.addresses.form_heading_add' => 'إضافة عنوان',
            'store.addresses.form_heading_edit' => 'تعديل العنوان',
            'store.addresses.field_type' => 'النوع',
            'store.addresses.field_first_name' => 'الاسم الأول *',
            'store.addresses.field_last_name' => 'اسم العائلة',
            'store.addresses.field_company' => 'الشركة',
            'store.addresses.field_line1' => 'العنوان، السطر الأول *',
            'store.addresses.field_line2' => 'العنوان، السطر الثاني',
            'store.addresses.field_city' => 'المدينة *',
            'store.addresses.field_state' => 'الإمارة / المحافظة',
            'store.addresses.field_postcode' => 'الرمز البريدي',
            'store.addresses.field_country' => 'الدولة *',
            'store.addresses.field_phone' => 'رقم الهاتف',
            'store.addresses.use_as_default' => 'استخدمه افتراضيًا لهذا النوع',
            'store.addresses.save_changes' => 'حفظ التغييرات',
            'store.addresses.add_address' => 'إضافة عنوان',
            'store.addresses.cancel' => 'إلغاء',
        ];
    }

    /** @return array<string, string> */
    private static function track(): array
    {
        return [
            'store.track.page_title' => 'تتبع طلبي · K-Beauty Bliss',
            'store.track.heading' => 'تتبع طلبي',
            'store.track.lead' => 'رقم طلبك موجود في رسالة التأكيد. ونطلب البريد الإلكتروني كذلك حتى لا يرى مكان شحنتك أحد سواك.',
            'store.track.submit' => 'ابحث عن طلبي',
            'store.track.too_many_tries' => 'محاولات كثيرة. انتظر قليلًا وحاول مجددًا — أو سجّل الدخول إلى :link، حيث لا حاجة إلى رقم.|محاولات كثيرة. انتظر دقيقة واحدة وحاول مجددًا — أو سجّل الدخول إلى :link، حيث لا حاجة إلى رقم.|محاولات كثيرة. انتظر دقيقتين وحاول مجددًا — أو سجّل الدخول إلى :link، حيث لا حاجة إلى رقم.|محاولات كثيرة. انتظر :count دقائق وحاول مجددًا — أو سجّل الدخول إلى :link، حيث لا حاجة إلى رقم.|محاولات كثيرة. انتظر :count دقيقة وحاول مجددًا — أو سجّل الدخول إلى :link، حيث لا حاجة إلى رقم.|محاولات كثيرة. انتظر :count دقيقة وحاول مجددًا — أو سجّل الدخول إلى :link، حيث لا حاجة إلى رقم.',
            'store.track.too_many_tries_link' => 'طلباتك',
            'store.track.not_found' => 'تعذّر العثور على طلب يطابق هذا الرقم والبريد الإلكتروني. تحقق من كليهما وحاول مجددًا.',
            'store.track.row_placed' => 'أُنشئ',
            'store.track.row_completed' => 'اكتمل',
            'store.track.signed_up' => 'مسجّل لدينا؟ :link تحتوي على الفاتورة كاملة.',
            'store.track.signed_up_link' => 'طلباتك',
        ];
    }

    /**
     * The skin quiz. Every `js_` key is read by resources/js/kbb/quiz through
     * FrontEndStrings, so these are strings a script prints — which changes
     * nothing about the Arabic, but is why they are keyed at all.
     *
     * @return array<string, string>
     */
    private static function quiz(): array
    {
        return [
            'store.quiz.preview_flag' => 'معاينة · الواجهة فقط',
            'store.quiz.brand_heading' => 'K-Beauty Bliss :suffix',
            'store.quiz.brand_suffix' => '· اختبار البشرة',

            'store.quiz.js_about_a_minute' => '~دقيقة',
            'store.quiz.js_allergy_placeholder' => 'هل من شيء آخر يجب تجنّبه؟ (اختياري)',
            'store.quiz.js_avoiding' => 'سنتجنّب لك:',
            'store.quiz.js_back' => '› رجوع',
            'store.quiz.js_backend_preview' => 'ما يحفظه متجرك (معاينة الواجهة الخلفية)',
            'store.quiz.js_concern_link_cta' => 'تسوق :concern ←',
            'store.quiz.js_concern_link_lead' => 'كل ما يوفّره المتجر لـ :concern في مكان واحد — باختيارنا، لا بمرشّح آلي.',
            'store.quiz.js_continue' => 'متابعة',
            'store.quiz.js_err_email' => 'أدخل بريدًا إلكترونيًا صحيحًا',
            'store.quiz.js_err_name' => 'يرجى إدخال اسمك',
            'store.quiz.js_err_phone' => 'أدخل رقم هاتف صحيحًا',
            'store.quiz.js_expert_body' => 'أرسل إجاباتك إلى خبيرة بشرة في K-Beauty Bliss — سنراجع روتينك ونراسلك عبر WhatsApp.',
            'store.quiz.js_expert_heading' => 'تحب أن يراجعها إنسان؟',
            'store.quiz.js_expert_placeholder' => 'ملاحظة اختيارية (حساسية، حمل، منتجات حالية…)',
            'store.quiz.js_expert_reach' => 'ستتواصل معك خبيرة على :phone.',
            'store.quiz.js_expert_send' => 'أرسل الطلب إلى خبيرة البشرة',
            'store.quiz.js_expert_sent' => 'تم الإرسال — نتحدث قريبًا!',
            'store.quiz.js_eye_about' => 'عنك',
            'store.quiz.js_eye_almost' => 'اقتربنا',
            'store.quiz.js_eye_goals' => 'أهدافك',
            'store.quiz.js_eye_results' => 'نتائجك',
            'store.quiz.js_eye_routine' => 'روتينك',
            'store.quiz.js_eye_safety' => 'فحص الأمان',
            'store.quiz.js_eye_skin' => 'بشرتك',
            'store.quiz.js_label_email' => 'البريد الإلكتروني',
            'store.quiz.js_label_name' => 'الاسم',
            'store.quiz.js_label_phone' => 'الهاتف (WhatsApp)',
            'store.quiz.js_last_step' => 'الخطوة الأخيرة',
            'store.quiz.js_perk_expert' => 'مساعدة مجانية من خبيرة',
            'store.quiz.js_perk_minute' => '~دقيقة واحدة',
            'store.quiz.js_perk_routines' => 'روتين مخصص لك',
            'store.quiz.js_ph_name' => 'مثال: فاطمة',
            'store.quiz.js_q_age' => 'ما فئتك العمرية؟',
            'store.quiz.js_q_allergy' => 'هل لديك أي حساسية أو تحسس؟',
            'store.quiz.js_q_budget' => 'ما ميزانيتك تقريبًا؟',
            'store.quiz.js_q_contact' => 'إلى أين نرسل روتينك؟',
            'store.quiz.js_q_depth' => 'كم خطوة تناسبك؟',
            'store.quiz.js_q_goals' => 'على ماذا تريد العمل؟',
            'store.quiz.js_q_skin' => 'ما نوع بشرتك؟',
            'store.quiz.js_results_sub' => 'مصمم لبشرتك ولمناخ الإمارات · أُرسل إلى :email',
            'store.quiz.js_results_title' => ':name، هذه خطة إشراقتك',
            'store.quiz.js_retake' => '↺ أعد الاختبار',
            'store.quiz.js_routine_boosters_desc' => 'خطوات إضافية لأبرز اهتماماتك.',
            'store.quiz.js_routine_boosters_tag' => 'إضافات',
            'store.quiz.js_routine_boosters_title' => 'معززات موجّهة',
            'store.quiz.js_routine_essentials_desc' => 'الخطوات الأساسية لـ 90% من أهدافك.',
            'store.quiz.js_routine_essentials_tag' => 'ابدأ من هنا',
            'store.quiz.js_routine_essentials_title' => 'أساسيات كل يوم',
            'store.quiz.js_routine_glass_desc' => 'روتين الطبقات الكامل، بالترتيب.',
            'store.quiz.js_routine_glass_tag' => 'أفضل النتائج',
            'store.quiz.js_routine_glass_title' => 'روتين البشرة الزجاجية',
            'store.quiz.js_routine_link_cta' => 'ابنِ روتين :concern ←',
            'store.quiz.js_routine_link_lead' => 'روتين لـ :concern، خطوة بخطوة، مما يوفّره هذا المتجر. والخطوات التي لا نوفّر لها شيئًا تُعرض فارغة بدل ملئها بتخمين.',
            'store.quiz.js_see_routine' => 'اعرض روتيني ←',
            'store.quiz.js_shop_steps' => 'تسوق هذه الخطوات',
            'store.quiz.js_start_cta' => 'ابدأ الاختبار ←',
            'store.quiz.js_start_eye' => 'اختبار بشرة في دقيقة',
            'store.quiz.js_start_sub' => 'أسئلة سريعة ← روتين كوري مخصص، مطابق لاهتماماتك ولمناخ الإمارات.',
            'store.quiz.js_start_title' => 'اعثر على إشراقتك.',
            'store.quiz.js_step_barrier_desc' => 'طبقة مهدّئة ومرمّمة حين تكون البشرة سريعة التهيج.',
            'store.quiz.js_step_barrier_name' => 'حاجز البشرة',
            'store.quiz.js_step_boost_desc' => 'مكوّن فعّال ثانٍ لاهتمامك التالي.',
            'store.quiz.js_step_boost_name' => 'تعزيز',
            'store.quiz.js_step_cleanse_desc' => 'أزل واقي الشمس والعرق وأثر اليوم.',
            'store.quiz.js_step_cleanse_name' => 'تنظيف',
            'store.quiz.js_step_cleanse_oil_desc' => 'زيت أو بلسم لإذابة واقي الشمس، ثم غسول لطيف.',
            'store.quiz.js_step_cleanse_oil_name' => 'تنظيف (بالزيت أولًا)',
            // NOT a plural: the English carries no `|` either. skin-quiz.blade.php
            // prints it through the page's own t(key, fallback, params) helper,
            // which substitutes :count and nothing else -- a `|` here would be
            // printed literally, six forms and all.
            'store.quiz.js_step_count' => ':count خطوات',
            'store.quiz.js_step_eye_desc' => 'تركيبة أخف للبشرة الرقيقة حول العين.',
            'store.quiz.js_step_eye_name' => 'العين',
            'store.quiz.js_step_mask_desc' => 'قناع مرة أو مرتين أسبوعيًا، لا يوميًا.',
            'store.quiz.js_step_mask_name' => 'أسبوعيًا',
            'store.quiz.js_step_moisturise_desc' => 'احبس الماء في البشرة لتتحمل المكونات الفعالة.',
            'store.quiz.js_step_moisturise_name' => 'ترطيب',
            'store.quiz.js_step_of' => 'الخطوة :n / :total',
            'store.quiz.js_step_protect_desc' => 'واقي شمس كل صباح — شمس الإمارات هي الأساس كله.',
            'store.quiz.js_step_protect_name' => 'حماية',
            'store.quiz.js_step_rich_desc' => 'كريم أغنى للبشرة الجافة أو الناضجة.',
            'store.quiz.js_step_rich_name' => 'ترطيب (أغنى)',
            'store.quiz.js_step_tone_desc' => 'أعد التوازن وليّن البشرة قبل أي مكوّن فعّال.',
            'store.quiz.js_step_tone_name' => 'تونر',
            'store.quiz.js_step_treat_desc' => 'الخطوة الفعّالة لاهتمامك الأساسي.',
            'store.quiz.js_step_treat_name' => 'علاج',
            'store.quiz.js_sub_age' => 'يساعدنا على اختيار المكونات الفعالة المناسبة.',
            'store.quiz.js_sub_allergy' => 'لنتجنّب المكونات التي لا تناسبك. اختياري — اختر ما ينطبق عليك.',
            'store.quiz.js_sub_budget' => 'لتكون الاختيارات مناسبة لك.',
            'store.quiz.js_sub_contact' => 'سنحفظ نتائجك ونرسل خطتك بالبريد. بلا رسائل مزعجة، أبدًا.',
            'store.quiz.js_sub_depth' => 'سنجعله على قدر يومك.',
            'store.quiz.js_sub_goals' => 'اختر حتى 3.',
            'store.quiz.js_sub_skin' => 'اختر الأقرب إلى وصف بشرتك.',
            'store.quiz.js_tap_continue' => 'اضغط للمتابعة',
            'store.quiz.js_toast_expert_sent' => 'أُرسل إلى خبيرة بشرة ✓',
            'store.quiz.js_toast_max_three' => 'اختر حتى 3 اهتمامات',
            'store.quiz.js_your_plan' => 'خطتك ✨',
        ];
    }

    /** @return array<string, string> */
    private static function reviewWall(): array
    {
        return [
            'store.review_wall.eyebrow' => 'تقييمات العملاء',
            'store.review_wall.heading' => 'ماذا قال العملاء',
            'store.review_wall.subtitle' => 'كل تقييم أدناه كتبه عميل لهذا المتجر ونُشر بعد المراجعة.',
            'store.review_wall.stars_label' => 'لا تقييم|:count من 5 نجوم|:count من 5 نجوم|:count من 5 نجوم|:count من 5 نجوم|:count من 5 نجوم',
            'store.review_wall.empty_heading' => 'لا تقييمات بعد',
            'store.review_wall.empty_body' => 'لم يقيّم أحد هذا المتجر بعد. وعندما يفعل العملاء ذلك ستظهر تقييماتهم هنا كما كُتبت تمامًا — فنحن لا ننشر تقييمات لم نتلقها.',
            'store.review_wall.empty_cta' => 'تصفّح المتجر',
            'store.review_wall.based_on' => 'بناءً على لا تقييمات معتمدة|بناءً على تقييم واحد معتمد|بناءً على تقييمين معتمدين|بناءً على :formatted تقييمات معتمدة|بناءً على :formatted تقييمًا معتمدًا|بناءً على :formatted تقييم معتمد',
            'store.review_wall.showing' => 'المعروض :count',
            'store.review_wall.showing_so_far' => 'المعروض حتى الآن :count',
            'store.review_wall.no_match' => 'لا تقييمات تطابق هذه التصفية. :link.',
            'store.review_wall.no_match_link' => 'عرض كل التقييمات',
            'store.review_wall.anonymous' => 'بدون اسم',
            'store.review_wall.on_product' => 'على :product',
            'store.review_wall.photo_alt' => 'صورة من التقييم',
            'store.review_wall.found_helpful' => '👍 لم يجدها أحد مفيدة بعد|👍 وجدها :formatted مفيدة|👍 وجدها :formatted مفيدة|👍 وجدها :formatted مفيدة|👍 وجدها :formatted مفيدة|👍 وجدها :formatted مفيدة',
            'store.review_wall.unbuilt_note' => ':lead تُكتب التقييمات في صفحة المنتج الذي اشتريته — افتحها واستخدم «اكتب تقييمًا» هناك. أما نموذج تقييم عام للمتجر، وتصفّح صور التقييمات، والتصويت على ما هو مفيد من هذه الصفحة فهي :not_built: لا خلل في متجرك ولا شيء ناقص فيه، هذه الأجزاء ببساطة غير موجودة هنا بعد. :link.',
            'store.review_wall.unbuilt_lead' => 'كتابة تقييم:',
            'store.review_wall.unbuilt_emphasis' => 'غير مبنية بعد',
            'store.review_wall.unbuilt_link' => 'ابحث عن منتج لتقييمه',
            'store.review_wall.too_few_count' => 'لا تقييمات معتمدة حتى الآن.|:formatted تقييم معتمد حتى الآن.|:formatted تقييمان معتمدان حتى الآن.|:formatted تقييمات معتمدة حتى الآن.|:formatted تقييمًا معتمدًا حتى الآن.|:formatted تقييم معتمد حتى الآن.',
            'store.review_wall.too_few_body' => 'لا يُعرض المتوسط قبل أن تبلغ التقييمات :minimum — فحفنة من التقييمات ليست تقييمًا للمتجر كله، وعرض متوسط لها يقول أكثر مما نعرف. وكل تقييم أدناه معروض كاملًا.',
        ];
    }

    /** @return array<string, string> */
    private static function routines(): array
    {
        return [
            'store.routines.breadcrumb' => 'ابنِ روتيني',
            'store.routines.heading' => 'ابنِ روتيني',
            'store.routines.lead' => 'اختر ما تريد العمل عليه. كل خطوة متوفرة فعلًا في هذا المتجر، ويمكنك استبدال أي منها.',
            'store.routines.empty_heading' => 'لا روتين بعد',
            'store.routines.empty_lead' => 'لم يُربط أي منتج في المتجر بخطوة روتين بعد. تصفّح المتجر في هذه الأثناء — كل ما فيه حقيقي ومتوفر.',
            'store.routines.browse_shop' => 'تصفّح المتجر',
            'store.routines.open' => 'اعرض هذا الروتين',
            'store.routines.back' => 'كل أنواع الروتين',
            'store.routines.steps_count' => 'لا خطوات|خطوة واحدة|خطوتان|:count خطوات|:count خطوة|:count خطوة',
            'store.routines.step_number' => 'الخطوة :number',
            'store.routines.title_for' => 'روتين :concern',
            'store.routines.blurb_for' => 'روتين كوري لـ :concern، بالترتيب الذي يُطبَّق به.',
            'store.routines.total_label' => 'الإجمالي للخطوات المعروضة|الإجمالي للخطوة المعروضة|الإجمالي للخطوتين المعروضتين|الإجمالي لـ :count خطوات معروضة|الإجمالي لـ :count خطوة معروضة|الإجمالي لـ :count خطوة معروضة',
            'store.routines.total_note' => 'أسعار المنتجات المختارة أعلاه. لا يُضاف شيء ولا يُخصم شيء.',
            'store.routines.swap_open' => 'استبدل هذه الخطوة',
            'store.routines.swap_none' => 'لا يتوفر بديل لهذه الخطوة حاليًا.',
            'store.routines.reset' => 'ابدأ هذا الروتين من جديد',
            'store.routines.gap_heading' => 'غير متوفر بعد',
            'store.routines.gap_lead' => 'لا يوجد في هذا المتجر ما يناسب هذه الخطوة حاليًا، لذا تُعرض الخطوة فارغة بدل ملئها بتخمين.',
            'store.routines.offer_percent' => 'استخدم الرمز :code للحصول على خصم :percent%.',
            'store.routines.offer_amount' => 'استخدم الرمز :code للحصول على خصم :amount.',
            'store.routines.offer_shipping' => 'استخدم الرمز :code للحصول على توصيل مجاني.',
            'store.routines.offer_minimum' => 'على الطلبات بقيمة :amount فأكثر.',
            'store.routines.offer_expires' => 'ينتهي في :date.',
            'store.routines.concern_hydration' => 'الترطيب',
            'store.routines.concern_dark-spots' => 'البقع الداكنة وتوحيد اللون',
            'store.routines.concern_acne' => 'حب الشباب والبثور',
            'store.routines.concern_ageing' => 'الخطوط الدقيقة وعلامات التقدم في السن',
            'store.routines.concern_sensitivity' => 'الاحمرار والحساسية',
            'store.routines.concern_pores' => 'المسام والدهون',
            'store.routines.concern_dullness' => 'البهتان والإشراق',
            'store.routines.concern_sun' => 'الحماية من الشمس',
            'store.routines.role_cleanse' => 'تنظيف',
            'store.routines.role_cleanse_help' => 'أزل واقي الشمس والعرق وأثر اليوم.',
            'store.routines.role_tone' => 'تونر',
            'store.routines.role_tone_help' => 'أعد التوازن وليّن البشرة قبل أي مكوّن فعّال.',
            'store.routines.role_treat' => 'علاج',
            'store.routines.role_treat_help' => 'الخطوة الفعّالة لما جئت من أجله.',
            'store.routines.role_moisturise' => 'ترطيب',
            'store.routines.role_moisturise_help' => 'احبس الماء في البشرة لتتحمل المكونات الفعالة.',
            'store.routines.role_protect' => 'حماية',
            'store.routines.role_protect_help' => 'واقي شمس كل صباح — شمس الإمارات هي الأساس كله.',
        ];
    }

    /**
     * The shoppable-video rail.
     *
     * `next` and `previous` are the rail's own arrow labels and are read by a
     * screen reader, never printed: they say which video, not which direction,
     * so they do not flip with the document.
     *
     * @return array<string, string>
     */
    private static function ugc(): array
    {
        return [
            'store.ugc.play' => 'شغّل هذا الفيديو',
            'store.ugc.pause' => 'إيقاف مؤقت',
            'store.ugc.add' => 'أضف',
            'store.ugc.rating_aria' => 'حاصل على :rating من 5 بناءً على :count تقييمات',
            'store.ugc.close' => 'إغلاق',
            'store.ugc.products_heading' => 'في هذا الفيديو',
            'store.ugc.view_original' => 'شاهد المنشور الأصلي',
            'store.ugc.like' => 'أعجبني هذا الفيديو',
            'store.ugc.liked' => 'أعجبك هذا',
            'store.ugc.likes_label' => 'إعجاب',
            'store.ugc.comments_label' => 'تعليق',
            'store.ugc.views_label' => 'مشاهدة',
            'store.ugc.next' => 'الفيديو التالي',
            'store.ugc.previous' => 'الفيديو السابق',
        ];
    }

    /** @return array<string, string> */
    private static function instagram(): array
    {
        return [
            'store.instagram.followers' => 'لا متابعين|متابع واحد|متابعان|:formatted متابعين|:formatted متابعًا|:formatted متابع',
            'store.instagram.posts' => 'لا منشورات|منشور واحد|منشوران|:formatted منشورات|:formatted منشورًا|:formatted منشور',
            'store.instagram.follow' => 'متابعة',
            'store.instagram.post_alt' => 'منشور إنستغرام',
            'store.instagram.open_post' => 'افتح هذا المنشور على إنستغرام',
        ];
    }

    /** @return array<string, string> */
    private static function newsletter(): array
    {
        return [
            'store.newsletter.confirm_title' => 'أكّد اشتراكك',
            'store.newsletter.confirm_heading' => 'ضغطة واحدة أخرى',
            'store.newsletter.confirm_lead' => 'أكّد رغبتك في تلقي رسائلنا على هذا العنوان.',
            'store.newsletter.confirm_button' => 'نعم، اشترِكوني',
            'store.newsletter.confirm_note' => 'إذا لم تطلب هذا فأغلق الصفحة. لا يُضاف شيء ما لم تضغط الزر.',
            'store.newsletter.unsub_title' => 'إلغاء الاشتراك',
            'store.newsletter.unsub_lead' => 'هل نوقف إرسال الرسائل التسويقية إلى هذا العنوان؟',
            'store.newsletter.unsub_button' => 'نعم، ألغوا اشتراكي',
            'store.newsletter.unsub_note' => 'تأكيدات الطلبات وتحديثات التوصيل والفواتير ليست تسويقًا وستظل تصلك. فهي الطريقة التي تعرف بها ما يحدث لشيء دفعت ثمنه.',
            'store.newsletter.done_subscribed_title' => 'تم اشتراكك',
            'store.newsletter.done_subscribed_heading' => 'أنت على القائمة',
            'store.newsletter.done_subscribed_lead' => 'شكرًا لك — تم التأكيد. وسنراسلك عندما يكون هناك ما يستحق رسالة.',
            'store.newsletter.done_subscribed_note' => 'كل رسالة نرسلها تحتوي على رابط لإلغاء الاشتراك، وسيعمل دائمًا.',
            'store.newsletter.done_unsub_title' => 'تم إلغاء الاشتراك',
            'store.newsletter.done_unsub_lead' => 'تم. أُزيل هذا العنوان من قائمتنا التسويقية ولن نعيده ما لم تطلب ذلك.',
            'store.newsletter.done_unsub_note' => 'تأكيدات الطلبات وتحديثات التوصيل ليست تسويقًا وستظل تصلك.',
            'store.newsletter.bad_link_title' => 'لم يعمل هذا الرابط',
            'store.newsletter.bad_link_lead' => 'قد تكون صلاحيته انتهت، أو قد يكون نُسخ ناقصًا — فالروابط تنقسم بشكل سيئ في بعض برامج البريد.',
            'store.newsletter.bad_link_note' => 'جرّب فتحه مرة أخرى مباشرة من الرسالة. وإن لم يعمل، فاشترك مرة أخرى من الصفحة الرئيسية وسنرسل رابطًا جديدًا.',

            'store.mail_prefs.title' => 'تفضيلات البريد',
            'store.mail_prefs.lead' => 'هل نوقف إرسال تنبيهات التوفر وتذكيرات السلة إلى هذا العنوان؟',
            'store.mail_prefs.button' => 'نعم، أوقفوا هذه الرسائل',
            'store.mail_prefs.confirm_note' => 'هذا يوقف تنبيهات عودة التوفر وتذكيرات السلة معًا، نهائيًا، على هذا العنوان. أما تأكيدات الطلبات وتحديثات التوصيل والفواتير فلا تتأثر — فهي الطريقة التي تعرف بها ما يحدث لشيء دفعت ثمنه.',
            'store.mail_prefs.done_title' => 'تم الإيقاف',
            'store.mail_prefs.done_lead' => 'تم. لن نرسل تنبيهات التوفر أو تذكيرات السلة إلى هذا العنوان مجددًا، وأُلغي كل ما كان منها في الانتظار.',
            'store.mail_prefs.done_note' => 'تأكيدات الطلبات وتحديثات التوصيل والفواتير لا تتأثر — فهي الطريقة التي تعرف بها ما يحدث لشيء دفعت ثمنه.',
            'store.mail_prefs.bad_link_note' => 'جرّب فتحه مرة أخرى مباشرة من الرسالة. وإن لم يعمل، فرُدّ على أي رسالة منا وسنزيل العنوان يدويًا.',
        ];
    }

    /** @return array<string, string> */
    private static function js(): array
    {
        return [
            'store.js.blog_tag_all' => 'الكل',
            'store.js.generic_error' => 'حدث خطأ ما — يرجى المحاولة مرة أخرى.',
            'store.js.no_connection' => 'لا يوجد اتصال — يرجى المحاولة مرة أخرى.',
            'store.js.session_expired' => 'انتهت جلستك — يرجى إعادة تحميل الصفحة.',
            'store.js.added' => 'تمت الإضافة',
            'store.js.add_failed' => 'تعذّرت الإضافة الآن — يرجى المحاولة مرة أخرى.',
            'store.js.add_failed_short' => 'تعذّرت الإضافة.',
            'store.js.bag_failed' => 'تعذّر تحديث حقيبتك — يرجى المحاولة مرة أخرى.',
            'store.js.coupon_failed' => 'تعذّر تطبيق هذا الرمز — يرجى المحاولة مرة أخرى.',
            'store.js.coupon_rejected' => 'تعذّر تطبيق هذا الرمز.',
            'store.js.delivery_failed' => 'تعذّر تحديث التوصيل لهذه الدولة.',
            'store.js.delivery_retry' => 'تعذّر تحديث التوصيل لهذه الدولة — يرجى المحاولة مرة أخرى.',
            'store.js.variant_sold_out' => 'نفدت كمية هذا الخيار.',
            'store.js.variant_sold_out_named' => 'نفدت كمية :name — يرجى اختيار خيار آخر.',
            'store.js.fbt_pick_one' => 'اختر منتجًا واحدًا على الأقل.',
            'store.js.fbt_added' => 'تمت إضافة :count منتجات ✓',
            'store.js.fbt_failed' => 'تعذّرت إضافتها — يرجى المحاولة مرة أخرى.',
            'store.js.wishlist_saved' => 'حُفظ في مفضلتك',
            'store.js.wishlist_removed' => 'أُزيل من مفضلتك',
            'store.js.wishlist_failed' => 'تعذّر تحديث مفضلتك.',
            'store.js.wishlist_retry' => 'تعذّر تحديث مفضلتك — يرجى المحاولة مرة أخرى.',
            'store.js.subscribed' => 'أنت على القائمة ✓',
            'store.js.subscribe_failed' => 'تعذّر تسجيلك — يرجى المحاولة مرة أخرى.',
            'store.js.already_helpful' => 'سبق أن اعتبرت هذا مفيدًا.',
            'store.js.review_failed' => 'تعذّر الإرسال — تحقق من اتصالك وحاول مرة أخرى.',
            'store.js.close_search' => 'إغلاق البحث',
            'store.js.read_less' => 'اقرأ أقل ↑',
            'store.js.hide_password' => 'إخفاء كلمة المرور',
            'store.js.password_common' => 'شائعة جدًا',
            'store.js.password_simple' => 'بسيطة جدًا',
            'store.js.password_weak' => 'ضعيفة',
            'store.js.password_fair' => 'مقبولة',
            'store.js.password_good' => 'جيدة',
            'store.js.password_strong' => 'قوية',
        ];
    }

    /**
     * The transactional emails, both the HTML and the plain-text bodies.
     *
     * `email.text.*` are the plain-text twins and they carry no markup by
     * design, so the Arabic carries none either — the difference between a pair
     * is punctuation, never wording, exactly as the English pairs differ.
     *
     * @return array<string, string>
     */
    private static function email(): array
    {
        return [
            'email.order_status.order_label' => 'الطلب',
            'email.order_status.view_order' => 'عرض طلبك',
            'email.greeting.hello' => 'مرحبًا،',
            'email.greeting.hello_named' => 'مرحبًا :name،',
            'email.common.paste_link' => 'أو انسخ هذا الرابط والصقه في المتصفح:',
            'email.customer_invite.button' => 'عيّن كلمة المرور',
            'email.customer_invite.why' => 'تصلك هذه الرسالة لأنك طلبت من :shop باستخدام عنوان البريد هذا. إن لم تكن أنت، تجاهل هذه الرسالة: لن يحدث شيء ما لم يُستخدم الرابط.',
            'email.common.unsubscribe' => 'إلغاء الاشتراك',
            'email.common.sign_off' => '— K Beauty Bliss',
            'email.layout.masthead_tagline' => 'جمال كوري أصلي، مختار لك',
            'email.layout.support_heading' => 'نحن هنا إن احتجت إلينا',
            'email.layout.support_body' => 'يرد عليك شخص حقيقي. اسألنا عن أي شيء — سؤال عن طلبك، أو عمّا يُستخدم معه.',
            'email.layout.footer_customer' => 'تصلك هذه الرسالة لأن طلبًا أُنشئ لدى :store باستخدام هذا البريد الإلكتروني.',
            // The two admin paths are the names of screens in the back office,
            // which is deliberately not localised (App\Support\Locale), so they
            // stay exactly as the operator sees them.
            'email.layout.footer_merchant' => 'هذا تنبيه الطلبات الجديدة الخاص بمتجرك. يُرسل إلى العنوان المحدد في Store → Mail، ويمكنك إيقافه من Store → Modules → Order emails.',

            'email.items.col_item' => 'المنتج',
            'email.items.col_qty' => 'الكمية',
            'email.items.col_total' => 'الإجمالي',
            'email.items.sku' => 'SKU :sku',
            'email.items.each' => 'للقطعة',

            'email.totals.subtotal' => 'المجموع الفرعي',
            'email.totals.discount' => 'الخصم',
            'email.totals.discount_coupon' => 'الخصم (:code)',
            'email.totals.delivery' => 'التوصيل',
            'email.totals.gift_wrapping' => 'تغليف الهدية',
            'email.totals.payment_fee' => 'رسوم :method',
            'email.totals.vat' => 'ضريبة القيمة المضافة',
            'email.totals.vat_at_rate' => 'ضريبة القيمة المضافة :rate%',
            'email.totals.total' => 'الإجمالي',

            'email.order_status.shipped_subject' => 'طلبك :number لدى :store في طريقه إليك',
            'email.order_status.cancelled_subject' => 'تم إلغاء طلبك :number لدى :store',
            'email.order_status.shipped_heading' => 'طلبك في طريقه إليك',
            'email.order_status.shipped_body' => 'غادر طلبك مستودعنا وهو الآن مع شركة الشحن. ويستغرق التوصيل داخل الإمارات عادةً من يوم إلى ثلاثة أيام عمل من تاريخ الشحن.',
            'email.order_status.cancelled_heading' => 'تم إلغاء طلبك',
            'email.order_status.cancelled_body' => 'أُلغي هذا الطلب ولن يُرسل شيء بعد ذلك.',
            'email.order_status.shipped_dispatched' => 'غادر طلبك مستودعنا وهو الآن مع شركة الشحن.',
            'email.order_status.shipped_abroad' => 'غادر طلبك مستودعنا وهو الآن مع شركة الشحن. والشحنات خارج الإمارات تستغرق وقتًا أطول من المحلية وتنتظر كذلك التخليص الجمركي في بلدك، لذا يرجى احتساب بضعة أيام إضافية.',
            'email.order_status.cancelled_refunded' => 'سُجّل استرداد بقيمة :amount على هذا الطلب.',
            'email.order_status.cancelled_nothing_taken' => 'تشير سجلاتنا إلى عدم تحصيل أي مبلغ على هذا الطلب، فلا يوجد ما يُسترد.',
            'email.order_status.cancelled_unrefunded' => 'تشير سجلاتنا إلى دفع :amount على هذا الطلب ولم يُسجَّل استرداد عليه بعد.',
            'email.order_status.device_note' => 'يفتح هذا الرابط على الجهاز الذي طلبت منه. ومن أي مكان آخر، :link وابحث عن :number.',

            'email.delivery.address_heading' => 'عنوان التوصيل',
            'email.delivery.method_heading' => 'طريقة التوصيل',
            'email.delivery.payment_heading' => 'طريقة الدفع',
            'email.delivery.gift_heading' => 'رسالة الهدية',
            'email.delivery.note_heading' => 'ملاحظة الطلب',
            'email.delivery.not_recorded' => 'غير مسجّل',

            'email.confirmation.greeting' => 'شكرًا لك ✨',
            'email.confirmation.greeting_named' => 'شكرًا لك، :name ✨',
            'email.confirmation.lead' => 'وصلنا طلبك ونجهّزه بعناية. وكل ما اخترته مدرج أدناه كما كان وقت الطلب تمامًا — احتفظ بهذه الرسالة، فهي فاتورتك.',
            'email.confirmation.placed_on' => 'أُنشئ في :date',
            'email.confirmation.track_button' => 'تتبع طلبك',
            'email.confirmation.sign_in_link' => 'سجّل الدخول إلى حسابك',
            'email.confirmation.device_note' => 'يفتح هذا الرابط على الجهاز الذي طلبت منه. ومن أي مكان آخر، :link وستجد كل طلباتك مدرجة هناك تحت :number.',

            'email.refunded.heading_sent' => 'مبلغ الاسترداد في طريقه إليك',
            'email.refunded.heading_approved' => 'تمت الموافقة على الاسترداد',
            'email.refunded.sent_body' => 'أعدنا :amount إلى وسيلة الدفع التي استخدمتها في الطلب :number.',
            'email.refunded.approved_body' => 'وافقنا على استرداد :amount على الطلب :number.',
            'email.refunded.partial_note' => 'هذا استرداد جزئي — وبقية الطلب لا تتأثر.',
            'email.refunded.statement_note' => 'تظهر المبالغ المستردة عادةً في كشف حساب البطاقة خلال خمسة إلى عشرة أيام عمل، حسب بنكك.',
            'email.refunded.manual_note' => 'دفعت عبر :method، وهي وسيلة لا يمكننا الاسترداد إليها تلقائيًا، لذا سنرتب المبلغ معك مباشرة. وإن لم تصلك رسالة منا فرُدّ على هذه الرسالة وسنتولى الأمر.',
            'email.refunded.row_refunded' => 'المسترد',
            'email.refunded.row_order_total' => 'إجمالي الطلب',
            'email.refunded.row_paid_by' => 'الدفع عبر',
            'email.refunded.chase_note' => 'إذا لم يصلك المبلغ خلال عشرة أيام عمل فرُدّ على هذه الرسالة برقم الطلب :number وسنتابعه.',

            'email.alert.heading' => 'وصل طلب جديد.',
            'email.alert.items_heading' => 'المنتجات المطلوب تجهيزها',
            'email.alert.next_step' => 'افتح Orders في لوحة متجرك وابحث عن :number لتجهيزه وتغليفه ووضع علامة الشحن عليه.',

            'email.back_in_stock.why' => 'طلبت أن نخبرك عند عودة هذا المنتج للتوفر. وهذه هي تلك الرسالة الوحيدة — ولن نراسلك بشأنه مجددًا ما لم تطلب ذلك.',
            'email.back_in_stock.why_text' => 'طلبت أن نخبرك عند عودة هذا المنتج للتوفر. وهذه هي تلك الرسالة الوحيدة - ولن نراسلك بشأنه مجددًا ما لم تطلب ذلك.',
            'email.back_in_stock.unsubscribe_prompt' => 'لا ترغب في رسائل كهذه؟',

            'email.cart_recovery.view_basket' => 'اعرض سلتك',
            'email.cart_recovery.why' => 'طلبت منا أن نذكّرك بهذه السلة حين تركت بريدك الإلكتروني لدينا. وإن كنت قد أتممت طلبك منذ ذلك الحين فشكرًا لك — يرجى تجاهل هذه الرسالة.',
            'email.cart_recovery.why_text' => 'طلبت منا أن نذكّرك بهذه السلة حين تركت بريدك الإلكتروني لدينا. وإن كنت قد أتممت طلبك منذ ذلك الحين فشكرًا لك - يرجى تجاهل هذه الرسالة.',
            'email.cart_recovery.unsubscribe_prompt' => 'لا ترغب في هذه التذكيرات؟',
            'email.cart_recovery.unsubscribe_tail' => 'وسنتوقف، نهائيًا.',

            'email.quiz_plan.subject' => 'خطة اختبار البشرة الخاصة بك لدى :store',
            'email.quiz_plan.greeting' => 'هذه خطتك ✨',
            'email.quiz_plan.greeting_named' => 'هذه خطتك، :name ✨',
            'email.quiz_plan.lead' => 'أجبت عن اختبار البشرة لدينا وطلبت أن نرسل روتينك بالبريد. وها هو — الخطوات، بالترتيب الذي تُطبَّق به.',
            'email.quiz_plan.skin_type' => 'نوع البشرة',
            'email.quiz_plan.concerns' => 'العمل على',
            'email.quiz_plan.steps_note' => 'هذه خطوات، لا منتجات. لم يُختر شيء ولم يُحجز ولم يُحصَّل أي مبلغ — اختر ما يناسبك من المتجر، حيث الأسعار.',
            'email.quiz_plan.steps_note_text' => 'هذه خطوات، لا منتجات. لم يُختر شيء ولم يُحجز ولم يُحصَّل أي مبلغ - اختر ما يناسبك من المتجر، حيث الأسعار.',
            'email.quiz_plan.shop_button' => 'تصفّح المتجر',
            'email.quiz_plan.routine_button' => 'ابنِ روتيني',
            'email.quiz_plan.concern_button' => 'تسوق لاهتمامك',
            'email.quiz_plan.why' => 'تصلك هذه الرسالة لأن هذا العنوان كُتب في اختبار البشرة على موقعنا، وقد ذكر النموذج أننا سنرسل الخطة بالبريد. وهذه هي تلك الرسالة الوحيدة — ولم يُضف العنوان إلى أي قائمة.',
            'email.quiz_plan.why_text' => 'تصلك هذه الرسالة لأن هذا العنوان كُتب في اختبار البشرة على موقعنا، وقد ذكر النموذج أننا سنرسل الخطة بالبريد. وهذه هي تلك الرسالة الوحيدة - ولم يُضف العنوان إلى أي قائمة.',

            /*
             * The FALLBACK for :store when the shop has no name set, and it has
             * to read inside somebody_asked's sentence rather than translate the
             * English word. English builds "asked for OUR emails"; the Arabic
             * builds "أن تُرسل رسائل :store", so :store wants the name -- or, with
             * none, "منّا": "رسائل منّا". Translating it as رسائل, which is what
             * the English word points at, produced "رسائل رسائل".
             */
            'email.newsletter.our' => 'منّا',
            'email.newsletter.somebody_asked' => 'طلب أحدهم — ونأمل أن تكون أنت — أن تُرسل رسائل :store إلى هذا العنوان.',
            'email.newsletter.somebody_asked_text' => 'طلب أحدهم -- ونأمل أن تكون أنت -- أن تُرسل رسائل :store إلى هذا العنوان.',
            'email.newsletter.not_yet' => ':emphasis اضغط الزر أدناه وستكون كذلك.',
            'email.newsletter.not_yet_emphasis' => 'أنت لست على القائمة بعد.',
            'email.newsletter.not_yet_text' => 'أنت لست على القائمة بعد. افتح الرابط أدناه وستكون كذلك.',
            'email.newsletter.link_expiry' => 'الرابط غير صالح.|الرابط صالح ليوم واحد.|الرابط صالح ليومين.|الرابط صالح لـ :count أيام.|الرابط صالح لـ :count يومًا.|الرابط صالح لـ :count يوم.',
            'email.newsletter.do_nothing' => 'إن لم تكن أنت فلا تفعل شيئًا. فبدون تلك الضغطة لن نضيف هذا العنوان، ولن تصلك منا رسالة أخرى.',
            'email.newsletter.unsubscribe_prompt' => 'لا ترغب في رسائل منا على هذا العنوان؟',

            'email.invoice.reference' => 'الفاتورة :reference',
            'email.invoice.order' => 'الطلب :number',
            'email.invoice.issued' => 'صدرت في :date',
            'email.invoice.ordered' => 'تاريخ الطلب :date',
            // The Tax Registration Number's label is the abbreviation printed on
            // every UAE tax invoice, in Latin, and the FTA's own forms use it.
            'email.invoice.trn' => 'TRN :trn',
            'email.invoice.bill_to' => 'الفاتورة إلى',
            'email.invoice.deliver_to' => 'التوصيل إلى',
            'email.invoice.same_as_billing' => 'نفس عنوان الفاتورة',
            'email.invoice.col_unit' => 'سعر القطعة',
            'email.invoice.col_amount' => 'المبلغ',
            'email.invoice.paid_by' => 'الدفع عبر :method',
            'email.invoice.paid_by_on' => 'الدفع عبر :method في :date',
            'email.invoice.payment_method' => 'طريقة الدفع :method',

            'email.verify.lead' => 'يرجى تأكيد أن هذا بريدك الإلكتروني لنتمكن من إرسال تحديثات الطلب إليك.',
            'email.verify.button' => 'أكّد بريدي الإلكتروني',
            'email.verify.expiry' => 'انتهت صلاحية الرابط.|تنتهي صلاحية الرابط خلال ساعة واحدة. والتأكيد لا يغيّر كلمة مرورك ولا يسجّل دخولك.|تنتهي صلاحية الرابط خلال ساعتين. والتأكيد لا يغيّر كلمة مرورك ولا يسجّل دخولك.|تنتهي صلاحية الرابط خلال :count ساعات. والتأكيد لا يغيّر كلمة مرورك ولا يسجّل دخولك.|تنتهي صلاحية الرابط خلال :count ساعة. والتأكيد لا يغيّر كلمة مرورك ولا يسجّل دخولك.|تنتهي صلاحية الرابط خلال :count ساعة. والتأكيد لا يغيّر كلمة مرورك ولا يسجّل دخولك.',
            'email.verify.not_you' => 'إذا لم تنشئ حسابًا لدينا فيمكنك تجاهل هذه الرسالة.',

            'email.reset.lead' => 'طلب أحدهم إعادة تعيين كلمة المرور على حسابك في K Beauty Bliss. إن كنت أنت فافتح الرابط أدناه واختر كلمة مرور جديدة.',
            'email.reset.button' => 'تعيين كلمة مرور جديدة',
            'email.reset.expiry' => 'انتهت صلاحية الرابط.|يمكن استخدام الرابط مرة واحدة، وتنتهي صلاحيته خلال دقيقة واحدة.|يمكن استخدام الرابط مرة واحدة، وتنتهي صلاحيته خلال دقيقتين.|يمكن استخدام الرابط مرة واحدة، وتنتهي صلاحيته خلال :count دقائق.|يمكن استخدام الرابط مرة واحدة، وتنتهي صلاحيته خلال :count دقيقة.|يمكن استخدام الرابط مرة واحدة، وتنتهي صلاحيته خلال :count دقيقة.',
            'email.reset.retires_old' => 'بمجرد تعيين كلمة مرور جديدة ستتوقف كلمتك السابقة — بما فيها التي كنت تستخدمها على موقعنا القديم — عن العمل، وسيتم تسجيل خروجك من أي جهاز آخر.',
            'email.reset.not_you' => 'إذا لم تطلب هذا فيمكنك تجاهل الرسالة. لم تتغير كلمة مرورك ولم يُمنح أحد صلاحية الوصول إلى حسابك.',

            'email.text.order_line' => 'الطلب :number',
            'email.text.item_line' => 'الكمية :quantity  ·  :unit للقطعة  ·  إجمالي السطر :line',
            'email.text.totals_heading' => 'الإجماليات',
            'email.text.items_heading' => 'المنتجات',
            'email.text.from_heading' => 'من',
            'email.text.delivery_method' => 'طريقة التوصيل: :method',
            'email.text.payment_method' => 'طريقة الدفع: :method',
            'email.text.thank_you' => 'شكرًا لك',
            'email.text.thank_you_named' => 'شكرًا لك، :name',
            'email.text.device_note_confirmation' => 'يفتح هذا الرابط على الجهاز الذي طلبت منه. ومن أي مكان آخر، سجّل الدخول إلى حسابك وستجد كل طلباتك مدرجة هناك تحت :number:',
            'email.text.device_note_status' => 'يفتح هذا الرابط على الجهاز الذي طلبت منه. ومن أي مكان آخر، سجّل الدخول إلى حسابك وابحث عن :number:',
            'email.text.alert_heading' => 'وصل طلب جديد',
            'email.text.customer' => 'العميل: :email',
            'email.text.refunded_row' => 'المسترد: :amount',
            'email.text.order_total_row' => 'إجمالي الطلب: :amount',
            'email.text.paid_by_row' => 'الدفع عبر: :method',
            'email.text.your_basket' => 'سلتك:',
            'email.text.view_your_basket' => 'اعرض سلتك:',
            'email.text.unsubscribe_here_like_this' => 'لا ترغب في رسائل كهذه؟ ألغِ الاشتراك من هنا:',
            'email.text.unsubscribe_here_reminders' => 'لا ترغب في هذه التذكيرات؟ ألغِ الاشتراك من هنا وسنتوقف نهائيًا:',
            'email.text.unsubscribe_here_from_us' => 'لا ترغب في رسائل منا على هذا العنوان؟ ألغِ الاشتراك من هنا:',
        ];
    }

    /**
     * The printed documents: the invoice, the packing slip, the delivery note
     * and the dispatch label.
     *
     * These print in the CUSTOMER'S language, which is what OrderLocale and
     * StandaloneDocumentLocaleTest are about — the delivery note is signed by
     * the person receiving the parcel, so it has to be in the language they
     * ordered in, whatever language the operator printing it is working in.
     *
     * @return array<string, string>
     */
    private static function invoice(): array
    {
        return [
            'invoice.document.print_hint' => 'اطبع الصفحة، أو اختر «حفظ كملف PDF» من نافذة الطباعة.',
            'invoice.document.print_button' => 'طباعة',
            'invoice.document.barcode_label' => 'الرمز الشريطي لـ :value',

            'invoice.doc.invoice' => 'فاتورة',
            'invoice.doc.packing_slip' => 'قائمة تعبئة',
            'invoice.doc.delivery_note' => 'إشعار تسليم',
            'invoice.doc.dispatch_label' => 'ملصق الشحن',

            'invoice.bulk.title.invoice' => 'الفواتير',
            'invoice.bulk.title.packing-slip' => 'قوائم التعبئة',
            'invoice.bulk.title.delivery-note' => 'إشعارات التسليم',
            'invoice.bulk.title.dispatch-label' => 'ملصقات الشحن',
            'invoice.bulk.subject' => ':count طلبات',
            'invoice.bulk.sheet_of' => 'الورقة :n من :total',
            'invoice.bulk.missing_headline' => 'تعذّر العثور على :count من الطلبات التي اخترتها',
            'invoice.bulk.missing_body' => 'لم يُطبع لها شيء، وبقية الطلبات أدناه. معرفات الطلبات: :ids. والأرجح أنها حُذفت نهائيًا بعد تحميل هذه القائمة.',
            'invoice.bulk.refused_title' => 'لم يُطبع شيء',
            'invoice.bulk.refused_close' => 'أغلق هذه الصفحة',

            'invoice.invoice.stamp_paid' => 'مدفوعة',
            'invoice.invoice.label_payment' => 'الدفع',
            'invoice.invoice.label_delivery' => 'التوصيل',
            'invoice.invoice.label_phone' => 'الهاتف',
            'invoice.invoice.label_currency' => 'العملة',
            'invoice.invoice.col_unit_price' => 'سعر القطعة',

            'invoice.packing.doctype' => 'قائمة تعبئة',
            'invoice.packing.stamp_gift' => 'هدية',
            'invoice.packing.ordered_by' => 'الطلب من',
            'invoice.packing.label_items' => 'المنتجات',
            'invoice.packing.label_status' => 'الحالة',
            'invoice.packing.col_sku' => 'SKU',
            'invoice.packing.col_picked' => 'تم التجهيز',
            'invoice.packing.gift_message' => 'رسالة الهدية — اكتبها على البطاقة',
            'invoice.packing.customer_note' => 'ملاحظة من العميل',
            'invoice.packing.footer' => 'لا تظهر أي أسعار على هذه الورقة. ويمكن وضعها في الطرد بأمان، حتى لو كان هدية.',

            'invoice.delivery_note.doctype' => 'إشعار تسليم',
            'invoice.delivery_note.delivered_to' => 'سُلّم إلى',
            'invoice.delivery_note.col_quantity' => 'الكمية',
            'invoice.delivery_note.received_by' => 'استلمه',
            'invoice.delivery_note.print_name' => 'الاسم بخط واضح',
            'invoice.delivery_note.signature' => 'التوقيع',
            'invoice.delivery_note.on_delivery' => 'عند التسليم',
            'invoice.delivery_note.date' => 'التاريخ',
            'invoice.delivery_note.date_format' => 'يوم / شهر / سنة',
            'invoice.delivery_note.footer' => 'هذا إشعار تسليم وليس فاتورة: لا تظهر عليه أي أسعار، ولا يُطلب به أي دفع. وتصدر فاتورة هذا الطلب بشكل منفصل.',

            'invoice.label.tel' => 'هاتف :phone',
            'invoice.label.strip_order' => 'الطلب',
            'invoice.label.strip_items' => 'المنتجات',
            'invoice.label.strip_service' => 'الخدمة',
            'invoice.label.cod_collect' => 'الدفع عند الاستلام — يُحصَّل',
            'invoice.label.from' => 'من: :name',
        ];
    }
}
