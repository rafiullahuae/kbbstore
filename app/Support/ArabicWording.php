<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The shop's shipped English wording that lives in SETTINGS, in Arabic
 * (Lane AR round 2).
 *
 * The owner, 15 October: "i need every single thing ... nothing should be in
 * english." A walk of every /ar page found the same shape again and again: a
 * line the owner can edit -- a bundle tier's "2-pack bundle", the trust line
 * "100% authentic", the delivery promise "1–3 days fast delivery all over
 * UAE", a shipping method's "Free delivery", the review box's empty line --
 * stored as ONE English setting with no Arabic beside it, so /ar printed it in
 * English, and the bidi algorithm then reordered it ("pack bundle-2",
 * "days fast delivery all over UAE 3–1").
 *
 * Rather than grow an Arabic twin field on a dozen admin screens, each shipped
 * English default is an INTERFACE STRING (InterfaceStrings::storeWording(),
 * group `store`, key `wording.*`) with an Arabic draft beside it
 * (ArabicInterfaceDrafts). So the owner reviews, approves and rewords the
 * Arabic where he already does for every other word of the shop --
 * Translation → Strings -- and it publishes with "Approve all" like the rest.
 *
 * of() is the one reader, and its rule is the honest one:
 *   - English page: returns its argument untouched. Never calls the translator.
 *   - /ar: when the text IS a shipped default, its Arabic; when the owner has
 *     written his own English, his English -- a translation of the shipped
 *     default would no longer say what his line says.
 *
 * No query: the interface strings are already loaded for the page.
 */
final class ArabicWording
{
    /** wording key => the shipped English it stands for. Spread into InterfaceStrings. */
    public const ENGLISH = [
        'wording.unit_1' => '1 unit',
        'wording.pack_2' => '2-pack bundle',
        'wording.pack_3' => '3-pack bundle',
        'wording.save_n' => 'Save {n}%',
        'wording.best_value' => 'Best value',
        'wording.authentic_100' => '100% authentic',
        'wording.authentic_kbeauty' => '100% authentic K-beauty',
        'wording.original_100' => '100% original',
        'wording.direct_brands' => 'Direct from brands and trusted suppliers',
        'wording.support_247' => '24/7 support',
        'wording.brands_note' => 'Korean brands, all sourced direct.',
        'wording.delivery_line' => '1–3 days fast delivery all over UAE',
        'wording.review_empty' => 'Be the first to share your thoughts ♡',
        'wording.menu_search' => 'Search the menu…',
        'wording.free_delivery' => 'Free delivery',
        'wording.delivery_charges' => 'Delivery Charges',
        'wording.shipping_charges' => 'Shipping Charges',
        'wording.cod' => 'COD',
        // Payment method names and lines as the checkout list shows them (shipped
        // provider titles and gateway defaults). Display only: the order record,
        // the gateways and every payment-lock test read the English untouched.
        'wording.pay_cod_title' => 'Cash on delivery',
        'wording.pay_cod_desc' => 'Pay in cash to the courier.',
        'wording.pay_cod_fee' => 'Pay in cash to the courier. A small :fee handling fee applies.',
        'wording.pay_card_title' => 'Credit / Debit Card',
        'wording.pay_card_default' => 'Credit or debit card',
        'wording.pay_tabby_title' => 'Tabby: Pay in 4 Installments',
        'wording.pay_tabby_default' => 'Pay in 4 with Tabby',
        'wording.pay_tabby_desc' => 'Split into 4 interest-free payments. No fees, no interest.',
        'wording.pay_tamara_title' => 'Pay Later with Tamara',
        'wording.pay_tamara_default' => 'Pay later with Tamara',
        'wording.pay_tamara_desc' => 'Split in up to 4 payments, or pay in 30 days. No interest.',
        'wording.site_name' => 'K-Beauty Bliss',
        // Lane ARF: the store name as the live shop has it (Store -> General), for
        // the footer's copyright line and every <title> on /ar.
        'wording.site_name_uae' => 'K-Beauty Bliss UAE',
        'wording.home_title' => 'K-Beauty Bliss · Authentic Korean skincare in the UAE',
        'wording.instagram' => 'Instagram',
        'wording.tiktok' => 'TikTok',
        'wording.facebook' => 'Facebook',
        'wording.whatsapp' => 'WhatsApp',
        'wording.messenger' => 'Messenger',
        'wording.pinterest' => 'Pinterest',
        'wording.telegram' => 'Telegram',
        'wording.snapchat' => 'Snapchat',
        'wording.shot_front' => 'Front',
        'wording.shot_texture' => 'Texture',
        'wording.shot_ingredients' => 'Ingredients',
        'wording.shot_on_skin' => 'On skin',
        'wording.shot_box' => 'Box',
        'wording.shot_video' => 'Video',
        'wording.tag_routine' => 'Routine',
        'wording.type_shipping' => 'Shipping',
        'wording.sf_brand' => 'K-BEAUTY BLISS',
        'wording.seo_default' => 'Shop Korean skincare in the UAE — serums, creams, moisturisers and beauty devices from Korean beauty brands.',
        'wording.sf_byline' => 'by FUSION DISTRICT GROUP.',
        'wording.sf_help' => 'Need Help?',
        'wording.sf_help_sub' => '24/7 Customer Support',
        'wording.sf_shipping' => 'Shipping & Delivery',
        'wording.sf_privacy' => 'Privacy policy',
        'wording.type_billing' => 'Billing',
        'wording.country_ae' => 'United Arab Emirates',
        'wording.country_sa' => 'Saudi Arabia',
        'wording.country_kw' => 'Kuwait',
        'wording.country_qa' => 'Qatar',
        'wording.country_bh' => 'Bahrain',
        'wording.country_om' => 'Oman',
        'wording.tag_routines' => 'Routines',
        'wording.tag_spf' => 'SPF',
        'wording.tag_news' => 'News',
        'wording.tag_how_to' => 'How-to',
        // Sentences built in code, kept word for word in English.
        'wording.view_of' => ':base, view :n of :total',
        'wording.hours_minutes' => ':h h :m m',
        'wording.vat_inclusive' => 'Inclusive of :rate% VAT',
        'wording.vat_added' => '+:rate% VAT added at checkout',
        'wording.vat_shown' => ':rate% VAT shown at checkout',
        'wording.vat_paying' => "You're paying VAT ({rate}%)",
        'wording.journal_title' => 'The Glow Journal',
        'wording.journal_desc' => 'Skincare tips and the K-beauty edit — honest guides on routines, ingredients and sun care, written for the UAE.',
        'wording.home' => 'Home',
        'wording.journal' => 'Journal',
        'wording.seo_category' => 'Shop :name at K-Beauty Bliss — :count.',
        'wording.seo_category_count' => ':total authentic Korean skincare picks',
        'wording.seo_category_none' => 'authentic Korean skincare',
        'wording.seo_search_one' => '":search" — :total result at K-Beauty Bliss, authentic Korean skincare.',
        'wording.seo_search_many' => '":search" — :total results at K-Beauty Bliss, authentic Korean skincare.',
        'wording.seo_shop' => 'Browse every K-Beauty Bliss product — :total authentic Korean skincare picks, from serums to beauty devices.',
        'wording.seo_collection' => ':intro :total authentic Korean skincare products at K-Beauty Bliss.',
        'wording.seo_collection_none' => ':intro Authentic Korean skincare at K-Beauty Bliss.',
        // Lane AR stage 2: what the storefront controllers say to a shopper (refusals,
        // confirmations), as keys; the English is the old literal, word for word.
        'wording.msg_login_failed' => 'Those details did not match our records.',
        'wording.msg_hc_wrong' => 'That sum was not right. Please try the new one.',
        'wording.msg_pw_changed' => 'Your password has been changed. Please sign in with it.',
        'wording.msg_reviews_closed' => 'Reviews are not being accepted at the moment.',
        'wording.msg_reviews_many' => 'Too many reviews submitted — please try again later.',
        'wording.msg_review_thanks' => 'Thank you for your review!',
        'wording.msg_review_pending' => 'Thank you! Your review is awaiting approval.',
        'wording.msg_votes_many' => 'Too many votes — please try again later.',
        'wording.msg_check_answer' => 'Please answer the quick check.',
        'wording.msg_check_expired' => 'That check expired — please try again.',
        'wording.msg_check_wrong' => 'That answer doesn\'t look right — please try again.',
        'wording.msg_email_confirmed' => 'Thank you — your email address is confirmed.',
        'wording.msg_email_already' => 'Your email address is already confirmed.',
        'wording.msg_email_cannot' => 'We could not send to the address on this account. Please update it first.',
        'wording.msg_email_sent' => 'We have sent a confirmation link to your email address.',
        'wording.msg_product_na' => 'That product is not available.',
        'wording.msg_option_na' => 'That option is not available.',
        'wording.msg_choose_option' => 'Choose an option before adding this product to your bag.',
        'wording.msg_sold_out' => 'That product is sold out.',
        'wording.msg_area_building' => 'Fill in the area or the building.',
        'wording.msg_default_address' => 'Default address updated.',
        'wording.msg_check_email' => 'Please check that email address and try again.',
        'wording.msg_unknown_product' => 'Unknown product.',
        'wording.msg_no_country' => 'We do not deliver to that country yet.',
        'wording.msg_method_na' => 'That payment method is not available.',
        'wording.msg_paid_already' => 'That payment has already gone through.',
        'wording.msg_cancel_failed' => 'We could not cancel that payment. Please refresh the page before trying again.',
        'wording.msg_gift_na' => 'Gift wrapping is not available.',
        'wording.msg_bag_empty' => 'Your bag is empty.',
        'wording.msg_no_deliver' => 'We do not deliver there yet.',
        'wording.msg_bag_restart' => 'Your bag is empty — please start again from the cart.',
        'wording.msg_product_gone' => 'That product is no longer available.',
        'wording.msg_has_options' => 'That product comes in options — please choose one on its own page.',
        'wording.msg_item_gone' => 'That item is no longer in your bag.',
        'wording.msg_cod_na' => 'Cash on delivery is not available.',
        'wording.msg_too_many' => 'Too many attempts. Try again in :seconds seconds.',
        'wording.msg_too_many_req' => 'Too many requests. Try again in :seconds seconds.',
        'wording.msg_link_sent_wait' => 'We have already sent a confirmation link. Try again in :seconds seconds.',
        'wording.msg_pw_sent' => 'If that address has an account, we have sent it a link to set a new password. Please check your inbox, and your spam folder.',
        'wording.msg_pw_invalid' => 'That link is no longer valid. Password reset links can only be used once, and expire after an hour. Please request a new one.',
        'wording.msg_verify_failed' => 'That confirmation link is not valid. It may have expired, or the address on the account may have changed since it was sent. Sign in and we will send you a new one.',
        'wording.msg_subscribe_confirm' => 'Almost there — check your inbox. If that address is not already on the list, we have just sent it a link to confirm. Please look in your spam folder too.',
        'wording.msg_stock_confirm' => 'Thank you — if that address is on the list for this product, we will email it once, as soon as the product is back.',
        'wording.msg_reminder_confirm' => 'Thank you — we will email you a reminder about this basket. There is an unsubscribe link in every message.',
        // Lane AR stage 2: the slim footer's arrow label and the sign-in human check.
        'wording.top_label' => 'Back to top',
        'wording.hc_what_is' => 'What is',
        // Lane AR stage 2: Appearance → Cart page wording as shipped (CartPage::SCHEMA), so /ar reads Arabic.
        'wording.cpage_00' => 'Recommended for you',
        'wording.cpage_01' => 'Order Value',
        'wording.cpage_02' => 'Express Delivery Charge',
        'wording.cpage_03' => 'Delivered the next working day where available. Chosen at checkout.',
        'wording.cpage_04' => 'Standard Delivery Charge',
        'wording.cpage_05' => 'Free on every order. Two to four working days.',
        'wording.cpage_06' => 'Free',
        'wording.cpage_07' => 'Delivery is calculated at checkout',
        'wording.cpage_08' => 'Service Fee',
        'wording.cpage_09' => 'Covers card processing and packing.',
        'wording.cpage_10' => 'Order Total',
        'wording.cpage_11' => 'Secure checkout',
        'wording.cpage_12' => 'Proceed to Checkout',
        'wording.cpage_13' => 'Please choose your delivery address',
        'wording.cpage_14' => '+ Address',
        'wording.cpage_15' => 'Change address',
        'wording.cpage_16' => 'Delivering to {tag}',
        'wording.cpage_17' => 'Choose location',
        'wording.cpage_18' => 'Add New Address',
        'wording.cpage_19' => 'Back to address',
        'wording.cpage_20' => '+ Add New Address',
        'wording.cpage_21' => 'Deliver here',
        'wording.cpage_22' => 'Area',
        'wording.cpage_23' => 'e.g. Jumeirah Village Circle',
        'wording.cpage_24' => 'Apartment / building',
        'wording.cpage_25' => 'e.g. Flat 802, Sunrise Residence',
        'wording.cpage_26' => 'City',
        'wording.cpage_27' => 'e.g. Sharjah',
        'wording.cpage_28' => 'Country',
        'wording.cpage_29' => 'from your location',
        'wording.cpage_30' => 'Country set from where you are. Change it if it is wrong.',
        'wording.cpage_31' => 'Mark this address',
        'wording.cpage_33' => 'Office',
        'wording.cpage_34' => 'Loading your addresses',
        'wording.cpage_35' => 'That could not be saved. Please try again.',
        'wording.cpage_36' => 'We keep your 3 most recent addresses on this device. Adding another replaces the oldest.',
        'wording.cpage_37' => 'Shipping calculated at checkout',
        // Lane AR round 3: the search box and its panel (placeholder, group headings, the
        // panel's own words, read by search.js through store.js.*).
        'wording.search_placeholder' => 'Search skincare, brands…',
        'wording.search_products' => 'Products',
        'wording.search_categories' => 'Categories',
        'wording.search_brands' => 'Brands',
        'wording.search_count' => ':count products',
        'js.search_no_matches' => 'No matches',
        'js.search_anyway' => 'Search anyway for',
        'js.search_view_all' => 'View all results',
        'js.search_found' => 'found',
        'js.search_trending' => 'Trending',
        'js.search_brands' => 'Popular Brands',
        'js.search_recent' => 'Most searched this week',
        'js.search_popular' => 'Popular right now',
    ];

    /** The Arabic drafts, spread into ArabicInterfaceDrafts (as `store.` keys). */
    public const ARABIC = [
        'wording.unit_1' => 'قطعة واحدة',
        'wording.pack_2' => 'مجموعة من قطعتين',
        'wording.pack_3' => 'مجموعة من 3 قطع',
        'wording.save_n' => 'وفّري {n}%',
        'wording.best_value' => 'القيمة الأفضل',
        'wording.authentic_100' => 'أصلي 100%',
        'wording.authentic_kbeauty' => 'جمال كوري أصلي 100%',
        'wording.original_100' => 'منتجات أصلية 100%',
        'wording.direct_brands' => 'مباشرةً من العلامات التجارية والموردين الموثوقين',
        'wording.support_247' => 'دعم على مدار الساعة',
        'wording.brands_note' => 'علامات كورية، جميعها من المصدر مباشرةً.',
        'wording.delivery_line' => 'توصيل سريع خلال 1-3 أيام في كل أنحاء الإمارات',
        'wording.review_empty' => 'كوني أول من تشاركنا رأيها ♡',
        'wording.menu_search' => 'ابحثي في القائمة…',
        'wording.free_delivery' => 'توصيل مجاني',
        'wording.delivery_charges' => 'رسوم التوصيل',
        'wording.shipping_charges' => 'رسوم الشحن',
        'wording.cod' => 'الدفع عند الاستلام',
        'wording.pay_cod_title' => 'الدفع نقدًا عند الاستلام',
        'wording.pay_cod_desc' => 'ادفعي نقدًا لمندوب التوصيل.',
        'wording.pay_cod_fee' => 'ادفعي نقدًا لمندوب التوصيل. تُضاف رسوم خدمة بسيطة قدرها :fee.',
        'wording.pay_card_title' => 'بطاقة ائتمان / خصم',
        'wording.pay_card_default' => 'بطاقة ائتمان أو خصم مباشر',
        'wording.pay_tabby_title' => 'تابي: ادفعي على 4 أقساط',
        'wording.pay_tabby_default' => 'قسّميها على 4 دفعات مع تابي',
        'wording.pay_tabby_desc' => 'قسّمي المبلغ على 4 دفعات بلا فوائد، ومن دون أي رسوم.',
        'wording.pay_tamara_title' => 'ادفعي لاحقًا مع تمارا',
        'wording.pay_tamara_default' => 'الدفع لاحقًا مع تمارا',
        'wording.pay_tamara_desc' => 'قسّمي المبلغ على 4 دفعات أو ادفعي خلال 30 يومًا، بلا فوائد.',
        'wording.site_name' => 'كي بيوتي بليس',
        'wording.site_name_uae' => 'كي بيوتي بليس الإمارات',
        'wording.home_title' => 'كي بيوتي بليس · العناية الكورية الأصلية في الإمارات',
        'wording.instagram' => 'إنستغرام',
        'wording.tiktok' => 'تيك توك',
        'wording.facebook' => 'فيسبوك',
        'wording.whatsapp' => 'واتساب',
        'wording.messenger' => 'ماسنجر',
        'wording.pinterest' => 'بنترست',
        'wording.telegram' => 'تيليجرام',
        'wording.snapchat' => 'سناب شات',
        'wording.shot_front' => 'الواجهة',
        'wording.shot_texture' => 'القوام',
        'wording.shot_ingredients' => 'المكوّنات',
        'wording.shot_on_skin' => 'على البشرة',
        'wording.shot_box' => 'العلبة',
        'wording.shot_video' => 'فيديو',
        'wording.tag_routine' => 'الروتين',
        'wording.type_shipping' => 'للتوصيل',
        'wording.sf_brand' => 'كي بيوتي بليس',
        'wording.seo_default' => 'تسوّقي العناية الكورية بالبشرة في الإمارات — سيروم وكريمات ومرطبات وأجهزة تجميل من أشهر العلامات الكورية.',
        'wording.sf_byline' => 'من مجموعة فيوجن ديستركت.',
        'wording.sf_help' => 'تحتاجين إلى مساعدة؟',
        'wording.sf_help_sub' => 'خدمة العملاء على مدار الساعة',
        'wording.sf_shipping' => 'الشحن والتوصيل',
        'wording.sf_privacy' => 'سياسة الخصوصية',
        'wording.type_billing' => 'للفوترة',
        'wording.country_ae' => 'الإمارات العربية المتحدة',
        'wording.country_sa' => 'المملكة العربية السعودية',
        'wording.country_kw' => 'الكويت',
        'wording.country_qa' => 'قطر',
        'wording.country_bh' => 'البحرين',
        'wording.country_om' => 'عُمان',
        'wording.tag_routines' => 'روتينات',
        'wording.tag_spf' => 'واقي الشمس',
        'wording.tag_news' => 'أخبار',
        'wording.tag_how_to' => 'إرشادات',
        'wording.view_of' => ':base، الصورة :n من :total',
        'wording.hours_minutes' => ':h س :m د',
        'wording.vat_inclusive' => 'شامل ضريبة القيمة المضافة :rate%',
        'wording.vat_added' => 'تُضاف ضريبة القيمة المضافة +:rate% عند إتمام الطلب',
        'wording.vat_shown' => 'تظهر ضريبة القيمة المضافة :rate% عند إتمام الطلب',
        'wording.vat_paying' => 'ضريبة القيمة المضافة ({rate}%)',
        'wording.journal_title' => 'مدوّنة الإشراقة',
        'wording.journal_desc' => 'نصائح العناية بالبشرة وأفضل ما في الجمال الكوري — أدلة صادقة عن الروتين والمكوّنات والحماية من الشمس، مكتوبة للإمارات.',
        'wording.home' => 'الرئيسية',
        'wording.journal' => 'المدوّنة',
        'wording.seo_category' => 'تسوّقي :name من كي بيوتي بليس — :count.',
        'wording.seo_category_count' => ':total من منتجات العناية الكورية الأصلية',
        'wording.seo_category_none' => 'عناية كورية أصلية',
        'wording.seo_search_one' => '«:search» — :total نتيجة في كي بيوتي بليس، للعناية الكورية الأصلية.',
        'wording.seo_search_many' => '«:search» — :total نتائج في كي بيوتي بليس، للعناية الكورية الأصلية.',
        'wording.seo_shop' => 'تصفّحي كل منتجات كي بيوتي بليس — :total من منتجات العناية الكورية الأصلية، من السيروم إلى أجهزة التجميل.',
        'wording.seo_collection' => ':intro :total من منتجات العناية الكورية الأصلية في كي بيوتي بليس.',
        'wording.seo_collection_none' => ':intro عناية كورية أصلية في كي بيوتي بليس.',
        // Lane AR stage 2: the controllers' messages.
        'wording.msg_login_failed' => 'البيانات المُدخلة لا تطابق سجلاتنا.',
        'wording.msg_hc_wrong' => 'ناتج الجمع غير صحيح. جرّبي السؤال الجديد.',
        'wording.msg_pw_changed' => 'تم تغيير كلمة المرور. سجّلي الدخول بها الآن.',
        'wording.msg_reviews_closed' => 'لا نستقبل التقييمات في الوقت الحالي.',
        'wording.msg_reviews_many' => 'أُرسل عدد كبير من التقييمات — يُرجى المحاولة لاحقًا.',
        'wording.msg_review_thanks' => 'شكرًا على تقييمكِ!',
        'wording.msg_review_pending' => 'شكرًا لكِ! تقييمكِ بانتظار الموافقة.',
        'wording.msg_votes_many' => 'عدد كبير من التصويتات — يُرجى المحاولة لاحقًا.',
        'wording.msg_check_answer' => 'يُرجى الإجابة عن سؤال التحقق السريع.',
        'wording.msg_check_expired' => 'انتهت صلاحية التحقق — يُرجى المحاولة مجددًا.',
        'wording.msg_check_wrong' => 'الإجابة غير صحيحة — يُرجى المحاولة مجددًا.',
        'wording.msg_email_confirmed' => 'شكرًا لكِ — تم تأكيد بريدكِ الإلكتروني.',
        'wording.msg_email_already' => 'بريدكِ الإلكتروني مؤكَّد مسبقًا.',
        'wording.msg_email_cannot' => 'تعذّر الإرسال إلى بريد هذا الحساب. يُرجى تحديثه أولًا.',
        'wording.msg_email_sent' => 'أرسلنا رابط التأكيد إلى بريدكِ الإلكتروني.',
        'wording.msg_product_na' => 'هذا المنتج غير متوفر.',
        'wording.msg_option_na' => 'هذا الخيار غير متوفر.',
        'wording.msg_choose_option' => 'اختاري أحد الخيارات قبل إضافة هذا المنتج إلى حقيبتكِ.',
        'wording.msg_sold_out' => 'نفدت كمية هذا المنتج.',
        'wording.msg_area_building' => 'يُرجى إدخال المنطقة أو المبنى.',
        'wording.msg_default_address' => 'تم تحديث العنوان الافتراضي.',
        'wording.msg_check_email' => 'يُرجى التحقق من البريد الإلكتروني والمحاولة مجددًا.',
        'wording.msg_unknown_product' => 'منتج غير معروف.',
        'wording.msg_no_country' => 'لا نوصّل إلى هذه الدولة بعد.',
        'wording.msg_method_na' => 'طريقة الدفع هذه غير متاحة.',
        'wording.msg_paid_already' => 'تمت عملية الدفع هذه بالفعل.',
        'wording.msg_cancel_failed' => 'تعذّر إلغاء عملية الدفع. يُرجى تحديث الصفحة قبل المحاولة مجددًا.',
        'wording.msg_gift_na' => 'تغليف الهدايا غير متاح.',
        'wording.msg_bag_empty' => 'حقيبة التسوق فارغة.',
        'wording.msg_no_deliver' => 'لا نوصّل إلى هذا العنوان بعد.',
        'wording.msg_bag_restart' => 'حقيبتكِ فارغة — يُرجى البدء من جديد من السلة.',
        'wording.msg_product_gone' => 'هذا المنتج لم يعد متوفرًا.',
        'wording.msg_has_options' => 'لهذا المنتج خيارات — يُرجى اختيار أحدها من صفحته.',
        'wording.msg_item_gone' => 'هذا المنتج لم يعد في حقيبتكِ.',
        'wording.msg_cod_na' => 'خدمة الدفع عند الاستلام غير متاحة.',
        'wording.msg_too_many' => 'محاولات كثيرة. حاولي مجددًا بعد :seconds ثانية.',
        'wording.msg_too_many_req' => 'طلبات كثيرة. حاولي مجددًا بعد :seconds ثانية.',
        'wording.msg_link_sent_wait' => 'أرسلنا رابط التأكيد من قبل. حاولي مجددًا بعد :seconds ثانية.',
        'wording.msg_pw_sent' => 'إن كان لهذا البريد حساب لدينا، فقد أرسلنا إليه رابطًا لتعيين كلمة مرور جديدة. يُرجى التحقق من صندوق الوارد ومجلد الرسائل غير المرغوب فيها.',
        'wording.msg_pw_invalid' => 'هذا الرابط لم يعد صالحًا. يمكن استخدام رابط إعادة تعيين كلمة المرور مرة واحدة فقط، وتنتهي صلاحيته بعد ساعة. يُرجى طلب رابط جديد.',
        'wording.msg_verify_failed' => 'رابط التأكيد هذا غير صالح. ربما انتهت صلاحيته، أو تغيّر بريد الحساب منذ إرساله. سجّلي الدخول وسنرسل إليكِ رابطًا جديدًا.',
        'wording.msg_subscribe_confirm' => 'خطوة أخيرة — تفقّدي بريدكِ. إن لم يكن هذا البريد مسجّلًا في القائمة، فقد أرسلنا إليه رابطًا للتأكيد. يُرجى التحقق من مجلد الرسائل غير المرغوب فيها أيضًا.',
        'wording.msg_stock_confirm' => 'شكرًا لكِ — إن كان هذا البريد في قائمة هذا المنتج، فسنراسله مرة واحدة فور توفّر المنتج من جديد.',
        'wording.msg_reminder_confirm' => 'شكرًا لكِ — سنرسل إليكِ تذكيرًا بهذه السلة عبر البريد. في كل رسالة رابط لإلغاء الاشتراك.',
        // Lane AR stage 2: the slim footer's arrow label and the sign-in human check.
        'wording.top_label' => 'العودة إلى الأعلى',
        'wording.hc_what_is' => 'كم يساوي',
        // Lane AR stage 2: Appearance → Cart page wording.
        'wording.cpage_00' => 'مقترحة لكِ',
        'wording.cpage_01' => 'قيمة الطلب',
        'wording.cpage_02' => 'رسوم التوصيل السريع',
        'wording.cpage_03' => 'يصل في يوم العمل التالي حيث يتوفر. يُختار عند إتمام الطلب.',
        'wording.cpage_04' => 'رسوم التوصيل العادي',
        'wording.cpage_05' => 'مجاني لكل طلب. من يومين إلى أربعة أيام عمل.',
        'wording.cpage_06' => 'مجانًا',
        'wording.cpage_07' => 'تُحتسب رسوم التوصيل عند إتمام الطلب',
        'wording.cpage_08' => 'رسوم الخدمة',
        'wording.cpage_09' => 'تغطي معالجة البطاقة والتغليف.',
        'wording.cpage_10' => 'إجمالي الطلب',
        'wording.cpage_11' => 'إتمام طلب آمن',
        'wording.cpage_12' => 'المتابعة لإتمام الطلب',
        'wording.cpage_13' => 'يُرجى اختيار عنوان التوصيل',
        'wording.cpage_14' => '+ عنوان',
        'wording.cpage_15' => 'تغيير العنوان',
        'wording.cpage_16' => 'التوصيل إلى {tag}',
        'wording.cpage_17' => 'اختاري الموقع',
        'wording.cpage_18' => 'إضافة عنوان جديد',
        'wording.cpage_19' => 'العودة إلى العناوين',
        'wording.cpage_20' => '+ إضافة عنوان جديد',
        'wording.cpage_21' => 'التوصيل إلى هنا',
        'wording.cpage_22' => 'المنطقة',
        'wording.cpage_23' => 'مثال: قرية جميرا الدائرية',
        'wording.cpage_24' => 'الشقة / المبنى',
        'wording.cpage_25' => 'مثال: شقة 802، سنرايز ريزيدنس',
        'wording.cpage_26' => 'المدينة',
        'wording.cpage_27' => 'مثال: الشارقة',
        'wording.cpage_28' => 'الدولة',
        'wording.cpage_29' => 'من موقعكِ',
        'wording.cpage_30' => 'حُدّدت الدولة من موقعكِ. غيّريها إن كانت غير صحيحة.',
        'wording.cpage_31' => 'تصنيف هذا العنوان',
        'wording.cpage_33' => 'المكتب',
        'wording.cpage_34' => 'جارٍ تحميل عناوينكِ',
        'wording.cpage_35' => 'تعذّر الحفظ. يُرجى المحاولة مجددًا.',
        'wording.cpage_36' => 'نحفظ آخر 3 عناوين لكِ على هذا الجهاز. إضافة عنوان جديد تحلّ محل الأقدم.',
        'wording.cpage_37' => 'يُحتسب الشحن عند إتمام الطلب',
        // Lane AR round 3: the search box and its panel.
        'wording.search_placeholder' => 'ابحثي عن منتجات العناية والعلامات…',
        'wording.search_products' => 'منتجات',
        'wording.search_categories' => 'الفئات',
        'wording.search_brands' => 'العلامات',
        'wording.search_count' => 'المنتجات: :count',
        'js.search_no_matches' => 'لا توجد نتائج مطابقة',
        'js.search_anyway' => 'ابحثي على أي حال عن',
        'js.search_view_all' => 'عرض كل النتائج',
        'js.search_found' => 'نتيجة',
        'js.search_trending' => 'الأكثر رواجًا',
        'js.search_brands' => 'علامات رائجة',
        'js.search_recent' => 'الأكثر بحثًا هذا الأسبوع',
        'js.search_popular' => 'الأكثر طلبًا الآن',
    ];

    public static function on(): bool
    {
        return Locale::current() === 'ar';
    }

    /**
     * The header's Arabic wordmark, for the other places that draw the shop's
     * name in two parts (the footer's logo, Lane ARF). The same two words
     * HeaderSettings::lockup() prints on /ar.
     */
    public const LOCKUP_AR = ['كي بيوتي', 'بليس'];

    /**
     * The store name inside the footer's copyright line (Lane ARF).
     *
     * English: of() exactly, which is the text untouched. On /ar the line
     * itself already ends in «الإمارات» (store.footer.copyright), so a store
     * name whose Arabic ends in the country too would print it twice --
     * "© 2026 كي بيوتي بليس الإمارات الإمارات". Only a name this class
     * translated is trimmed; a name the owner typed in Arabic is his.
     *
     * The same twice-over in English (17 October 2026): the live store name is
     * "K-Beauty Bliss UAE" and store.footer.copyright is "© :year :store UAE",
     * so the footer read "© 2026 K-Beauty Bliss UAE UAE". The name's own
     * " UAE" is dropped only while the line itself ends ":store UAE" -- if the
     * line is ever reworded without the country, the name prints whole.
     */
    public static function copyrightStore(?string $store): string
    {
        $out = self::of($store);

        if (self::on() && $out !== (string) $store && str_ends_with($out, ' الإمارات')) {
            return mb_substr($out, 0, mb_strlen($out) - mb_strlen(' الإمارات'));
        }

        if (preg_match('/ UAE$/i', $out) === 1 && str_ends_with((string) __('store.footer.copyright'), ':store UAE')) {
            return substr($out, 0, -4);
        }

        return $out;
    }

    /**
     * A setting's text as /ar should print it. English: untouched.
     */
    public static function of(?string $text): string
    {
        $text = (string) $text;

        if (! self::on() || $text === '') {
            return $text;
        }

        $key = self::keyFor(trim($text));

        return $key === null ? $text : (string) __('store.'.$key);
    }

    /**
     * A payment method's line in the checkout list (printed unescaped there:
     * it carries the fee's markup). English passes through untouched; on /ar a
     * shipped default comes back in Arabic, and the cash-on-delivery fee
     * sentence -- which carries a price -- is matched by shape and rebuilt
     * from its own key, its price still the markup the gateway made.
     */
    public static function payLine(?string $text): string
    {
        $text = (string) $text;

        if (! self::on() || $text === '') {
            return $text;
        }

        // The Arabic is a stored translation, so it is ESCAPED here (the line is
        // printed raw); only the gateway's own fee markup passes through as is.
        if (preg_match('/^Pay in cash to the courier\. A small (.+) handling fee applies\.$/u', $text, $m)) {
            return str_replace(':fee', $m[1], e((string) __('store.wording.pay_cod_fee', ['fee' => ':fee'])));
        }

        return self::keyFor(trim($text)) === null ? $text : e(self::of($text));
    }

    /** The wording key whose shipped English is exactly this, or null. */
    public static function keyFor(string $english): ?string
    {
        static $flip = null;
        $flip ??= array_flip(self::ENGLISH);

        return $flip[$english] ?? null;
    }
}
