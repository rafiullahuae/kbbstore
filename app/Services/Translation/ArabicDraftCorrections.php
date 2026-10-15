<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * Lane AR round 2 — corrections to shipped Arabic interface drafts.
 *
 * The owner, 15 October: "do as per the arabic grammar and local language."
 * The drafts address the shopper in the feminine almost everywhere (تسوّقي،
 * اشتركي، تابعينا) but about a hundred lines slipped into the masculine
 * (اطلب، أضف، تحتاج، يمكنك … وأنت تعلم) — sometimes two forms on one screen,
 * "تواصلي معنا" in a heading over "تواصل عبر WhatsApp" in its button. A few
 * also carried Latin brand words the owner wants written in Arabic (Tabby,
 * Tamara, WhatsApp, K-Beauty Bliss).
 *
 * key => [the shipped draft, its correction]. ArabicInterfaceDrafts::all()
 * applies the correction, so a fresh shop seeds the right words; the migration
 * 2027_11_02_100100 rewrites an EXISTING row only while it still holds the
 * shipped draft word for word and is still a draft — anything the owner has
 * approved or edited is his and is left alone.
 *
 * Feminine imperative and agreement throughout; UAE shop wording (السلة،
 * إتمام الطلب، الدفع عند الاستلام); one spelling per brand (the owner's
 * glossary).
 */
final class ArabicDraftCorrections
{
    /** @var array<string, array{0: string, 1: string}> */
    public const ROUND2 = [
        'store.push.order_onhold_body' => ['اضغط لمعرفة ما نحتاجه للمتابعة.', 'اضغطي لمعرفة ما نحتاجه للمتابعة.'],
        'store.push.order_shipped_body' => ['طلبك الآن مع شركة التوصيل. اضغط لمتابعته.', 'طلبكِ الآن مع شركة التوصيل. اضغطي لمتابعته.'],
        'store.push.order_completed_body' => ['نتمنى أن ينال إعجابك. اضغط لعرض طلبك.', 'نتمنى أن ينال إعجابكِ. اضغطي لعرض طلبكِ.'],
        'store.push.order_cancelled_body' => ['اضغط لعرض التفاصيل.', 'اضغطي لعرض التفاصيل.'],
        'store.push.order_failed_body' => ['اضغط للمحاولة مرة أخرى.', 'اضغطي للمحاولة مرة أخرى.'],
        'store.push.stock_body' => [':product متوفر من جديد. احصل عليه قبل أن ينفد مرة أخرى.', ':product متوفر من جديد. احصلي عليه قبل أن ينفد مرة أخرى.'],
        'store.push.cart_body' => ['اختياراتك بانتظارك. اضغط لإتمام طلبك.', 'اختياراتكِ بانتظاركِ. اضغطي لإتمام طلبكِ.'],
        'store.site_app.push_body' => ['اسمح بالإشعارات لتصلك أخبار K-Beauty Bliss على هذا الهاتف. يمكنك إيقافها في أي وقت من إعدادات هاتفك.', 'اسمحي بالإشعارات لتصلكِ أخبار كي بيوتي بليس على هذا الهاتف. يمكنكِ إيقافها في أي وقت من إعدادات هاتفكِ.'],
        'store.whatsapp.welcome' => ['أهلًا 👋 مرحبًا بك في K-Beauty Bliss', 'أهلًا 👋 مرحبًا بكِ في كي بيوتي بليس'],
        'store.whatsapp.support' => ['هل تحتاج إلى مساعدة في الاختيار؟ فريق الجمال لدينا متاح على واتساب 24/7.', 'هل تحتاجين إلى مساعدة في الاختيار؟ فريق الجمال لدينا متاح على واتساب على مدار الساعة.'],
        'store.whatsapp.capsule_title' => ['تحدّث معنا', 'تحدّثي معنا'],
        'store.whatsapp.open_label' => ['تحدّث معنا على واتساب', 'تحدّثي معنا على واتساب'],
        'store.blocked.contact' => ['إذا كنت عميلًا وحدث هذا عن طريق الخطأ، تواصل معنا واذكر الرقم المرجعي أدناه وسنحل الأمر.', 'إذا كنتِ عميلتنا وحدث هذا عن طريق الخطأ، تواصلي معنا واذكري الرقم المرجعي أدناه وسنحلّ الأمر.'],
        'store.mkt_unsub.lead' => ['هل تريد إيقاف إرسال عروضنا وأخبارنا إلى هذا العنوان؟', 'هل تريدين إيقاف إرسال عروضنا وأخبارنا إلى هذا العنوان؟'],
        'store.mkt_unsub.bad_note' => ['جرّب فتحه مرة أخرى من الرسالة مباشرة. وإن لم يعمل، ردّ على أي رسالة منا وسنحذف العنوان يدويًا.', 'جرّبي فتحه مرة أخرى من الرسالة مباشرةً. وإن لم يعمل، ردّي على أي رسالة منّا وسنحذف العنوان يدويًا.'],
        'store.trust_strip.pay_sub' => ['ادفع لاحقاً أو بالبطاقة أو عند الاستلام', 'ادفعي لاحقاً أو بالبطاقة أو عند الاستلام'],
        'store.trust_strip.label' => ['لماذا تتسوّق معنا', 'لماذا تتسوّقين معنا'],
        'store.footer.whatsapp_cta' => ['تواصل عبر WhatsApp', 'تواصلي عبر واتساب'],
        'store.footer.help_sub' => ['اسألينا عن بشرتك أو أي منتج أو طلبك — نرد عبر WhatsApp.', 'اسألينا عن بشرتكِ أو أي منتج أو طلبكِ — نردّ عبر واتساب.'],
        'store.announcement.pay_later' => ['ادفع لاحقًا مع Tabby و Tamara', 'ادفعي لاحقًا مع تابي وتمارا'],
        'store.mobile_menu.hint' => ['اضغط على قسم لفتحه', 'اضغطي على قسم لفتحه'],
        'store.account_panel.terms_notice' => ['بإنشائك حسابًا فأنت توافق على :terms و:privacy.', 'بإنشائكِ حسابًا فأنتِ توافقين على :terms و:privacy.'],
        'store.shop.empty_body' => ['جرّب إزالة أحد الخيارات أو مسح الكل.', 'جرّبي إزالة أحد الخيارات أو مسح الكل.'],
        'store.shop.title_all' => ['تسوق الكل', 'تسوّقي الكل'],
        'store.shop.header_more' => ['اقرأ المزيد', 'اقرئي المزيد'],
        'store.shop.header_less' => ['اقرأ أقل', 'عرض أقل'],
        'store.product_card.add_to_cart' => ['أضف إلى السلة', 'أضيفي إلى السلة'],
        'store.cart.empty_body' => ['اكتشف منتجات كورية أصلية وابدأ إشراقتك.', 'اكتشفي منتجات كورية أصلية وابدئي إشراقتكِ.'],
        'store.cart.empty_cta' => ['ابدأ التسوق', 'ابدئي التسوق'],
        'store.cart.continue_shopping_link' => ['أو تابع التسوق', 'أو تابعي التسوق'],
        'store.cart_drawer.browsed_in_bag' => ['في حقيبتك — أضف واحدًا آخر', 'في حقيبتكِ — أضيفي قطعة أخرى'],
        'store.cart_drawer.browsed_add' => ['أضف إلى السلة', 'أضيفي إلى السلة'],
        'store.wishlist.keep_browsing' => ['تابع التصفح', 'تابعي التصفح'],
        'store.wishlist.empty_body' => ['اضغط على القلب في أي منتج للاحتفاظ به هنا.', 'اضغطي على القلب في أي منتج للاحتفاظ به هنا.'],
        'store.wishlist.empty_cta' => ['ابدأ التصفح', 'ابدئي التصفح'],
        'store.home.banner_slider_bars' => ['اختر صورة', 'اختاري صورة'],
        'store.home.recommended_link' => ['تسوق المزيد', 'تسوّقي المزيد'],
        'store.home.routine_add_all' => ['أضف الروتين كاملًا · :amount', 'أضيفي الروتين كاملًا · :amount'],
        'store.home.quiz_hint' => ['اختر الأقرب', 'اختاري الأقرب'],
        'store.home.spotted_link' => ['اكتشف المزيد', 'اكتشفي المزيد'],
        'store.home.bestsellers_link' => ['تسوق المزيد', 'تسوّقي المزيد'],
        'store.home.journal_subtitle' => ['اقرأ قبل أن تشتري.', 'اقرئي قبل أن تشتري.'],
        'store.home.reviews_link' => ['اقرأ الكل', 'اقرئي الكل'],
        'store.reviews.write_button' => ['✎ اكتب تقييمًا', '✎ اكتبي تقييمًا'],
        'store.reviews.write_link' => ['اكتب تقييمًا', 'اكتبي تقييمًا'],
        'store.reviews.form_heading' => ['شاركنا تجربتك ♡', 'شاركينا تجربتكِ ♡'],
        'store.reviews.field_photos' => ['أضف صورًا', 'أضيفي صورًا'],
        'store.reviews.photos_cta' => ['📷 اضغط لإضافة صور', '📷 اضغطي لإضافة صور'],
        'store.reviews.empty' => ['لا توجد تقييمات بعد — كن أول من يقيّم هذا المنتج.', 'لا توجد تقييمات بعد — كوني أول من تقيّم هذا المنتج.'],
        'store.product.choose_option' => ['اختر الخيار المناسب', 'اختاري الخيار المناسب'],
        'store.product.stock_sold_out' => ['نفدت الكمية — تابعنا قريبًا', 'نفدت الكمية — تابعينا قريبًا'],
        'store.product.cutoff_delivery' => ['اطلب خلال :remaining ليصلك في :date', 'اطلبي خلال :remaining ليصلكِ يوم :date'],
        'store.product.cutoff_dispatch' => ['اطلب خلال :remaining ليُشحن في :ship', 'اطلبي خلال :remaining ليُشحن يوم :ship'],
        'store.product.read_more' => ['اقرأ المزيد ↓', 'اقرئي المزيد ↓'],
        'store.product.related_eyebrow' => ['أكمل روتينك', 'أكملي روتينكِ'],
        'store.product.recs_recent_heading' => ['تابع التسوق', 'تابعي التسوق'],
        'store.product.save_to_wishlist' => ['أضف إلى المفضلة', 'أضيفي إلى المفضلة'],
        'store.product.pts_card_msg' => ['شاهد ما وجدته في K-Beauty Bliss 💖', 'شاهدي ما وجدته في كي بيوتي بليس 💖'],
        'store.product.pts_auth_text' => ["ندرك أهمية الأصالة عندما يتعلق الأمر بالعناية بالبشرة. لذلك نبذل جهدًا إضافيًا للتحقق من أصالة كل منتج عبر فحوصات جودة دقيقة والشراء مباشرة من موردين موثوقين.\n\nعندما تختار K-Beauty Bliss، يمكنك التسوق بثقة وأنت تعلم أنك ستحصل على الأفضل فقط: منتجات عناية بالبشرة وتجميل أصلية 100%.",
            "ندرك أهمية الأصالة عندما يتعلق الأمر بالعناية بالبشرة. لذلك نبذل جهدًا إضافيًا للتحقق من أصالة كل منتج عبر فحوصات جودة دقيقة والشراء مباشرةً من موردين موثوقين.\n\nعندما تختارين كي بيوتي بليس، يمكنكِ التسوق بثقة وأنتِ تعلمين أنكِ ستحصلين على الأفضل فقط: منتجات عناية بالبشرة وتجميل أصلية 100%."],
        'store.product.paylater_tabby' => ['قسّم مشترياتك على دفعات شهرية', 'قسّمي مشترياتكِ على دفعات شهرية'],
        'store.product.bundles_note' => ['وفّر أكثر مع المجموعات', 'وفّري أكثر مع المجموعات'],
        'store.fbt.title' => ['أكمل روتينك', 'أكملي روتينكِ'],
        'store.fbt.add_selected' => ['أضف المحدد إلى السلة', 'أضيفي المحدد إلى السلة'],
        'store.buy_together.button_one' => ['أضف منتجًا واحدًا إلى السلة', 'أضيفي منتجًا واحدًا إلى السلة'],
        'store.buy_together.button_none' => ['اختر منتجًا واحدًا على الأقل', 'اختاري منتجًا واحدًا على الأقل'],
        'store.buy_together.choose_option' => ['اختر خيارًا لـ :name أولًا.', 'اختاري خيارًا لـ :name أولًا.'],
        'store.buy_together.saving' => ['أنت توفّر', 'أنتِ توفّرين'],
        'store.checkout.coupon_placeholder' => ['أدخل رمز الخصم', 'أدخلي رمز الخصم'],
        'store.checkout.coupon_prompt' => ['لديك رمز خصم؟', 'لديكِ رمز خصم؟'],
        'store.checkout.coupon_err_already_used' => ['لقد استخدمت هذا الرمز من قبل.', 'لقد استخدمتِ هذا الرمز من قبل.'],
        'store.checkout.signin_member' => ['لديك حساب بالفعل؟', 'لديكِ حساب بالفعل؟'],
        'store.checkout.field_state_select' => ['اختر', 'اختاري'],
        'store.checkout.validate_phone' => ['يرجى إدخال رقم هاتف صحيح، أو اترك الحقل فارغًا.', 'يُرجى إدخال رقم هاتف صحيح، أو ترك الحقل فارغًا.'],
        'store.checkout.password_placeholder' => ['اختر كلمة مرور (8 أحرف أو أكثر)', 'اختاري كلمة مرور (8 أحرف أو أكثر)'],
        'store.checkout.unserved_country' => ['لا نقوم بالتوصيل إلى :country حتى الآن. اختر دولة أخرى من الأعلى، أو تواصل معنا وسنرى ما يمكننا فعله.', 'لا نوصل إلى :country حتى الآن. اختاري دولة أخرى من الأعلى، أو تواصلي معنا وسنرى ما يمكننا فعله.'],
        'store.checkout.signin_forgot_lead' => ['أدخل بريدك الإلكتروني وسنرسل لك رابطًا لاختيار كلمة مرور جديدة.', 'أدخلي بريدكِ الإلكتروني وسنرسل لكِ رابطًا لاختيار كلمة مرور جديدة.'],
        'store.checkout.card_secure_line' => ['آمن ومشفّر 100% — استخدم أي بطاقة', 'آمن ومشفّر 100% — استخدمي أي بطاقة'],
        'store.checkout.card_save' => ['احفظ هذه البطاقة لعمليات الشراء القادمة.', 'احفظي هذه البطاقة لعمليات الشراء القادمة.'],
        'store.checkout.tab_browsed' => ['تصفحتها', 'تصفّحتِها'],
        'store.checkout.browsed_heading' => ['تصفحتها مؤخرًا — أضفها بضغطة', 'تصفّحتِها مؤخرًا — أضيفيها بضغطة'],
        'store.checkout.browsed_add' => ['أضف', 'أضيفي'],
        'store.checkout.browsed_add_label' => ['أضف :product إلى السلة', 'أضيفي :product إلى السلة'],
        'store.checkout.thumbs_youre_ordering' => ['أنت تطلب', 'أنتِ تطلبين'],
        'store.checkout.restore_basket' => ['أعد حقيبتي', 'أعيدي حقيبتي'],
        'store.order_received.act_sign_in' => ['سجّل الدخول إلى حسابك', 'سجّلي الدخول إلى حسابكِ'],
        'store.order_received.act_sign_in_note' => ['حسابك جاهز — استخدم :email', 'حسابكِ جاهز — استخدمي :email'],
        'store.order_received.account_heading' => ['أكمل إنشاء حسابك', 'أكملي إنشاء حسابكِ'],
        'store.order_received.account_prompt' => ['اختر كلمة مرور لإتمام الإنشاء.', 'اختاري كلمة مرور لإتمام الإنشاء.'],
        'store.order_received.account_password_label' => ['اختر كلمة مرور', 'اختاري كلمة مرور'],
        'store.brands.shop_all' => ['تسوق كل :brand', 'تسوّقي كل منتجات :brand'],
        'store.brands.read_more' => ['اقرأ المزيد', 'اقرئي المزيد'],
        'store.brands.read_less' => ['اقرأ أقل', 'عرض أقل'],
        'store.journal.read_more' => ['اقرأ المزيد ←', 'اقرئي المزيد ←'],
        'store.journal.empty' => ['لا مقالات بعد — تابعنا قريبًا.', 'لا مقالات بعد — تابعينا قريبًا.'],
        'store.account.orders_empty_link' => ['ابدأ التسوق', 'ابدئي التسوق'],
        'store.account.sign_in_lead' => ['سجّل الدخول لعرض طلباتك ومفضلتك.', 'سجّلي الدخول لعرض طلباتكِ ومفضلتكِ.'],
        'store.account.terms_notice' => ['بمتابعتك فأنت توافق على :terms و:privacy.', 'بمتابعتكِ فأنتِ توافقين على :terms و:privacy.'],
        'store.account.have_account' => ['لديك حساب بالفعل؟ :link', 'لديكِ حساب بالفعل؟ :link'],
        'store.account.forgot_heading' => ['أعد تعيين كلمة المرور', 'أعيدي تعيين كلمة المرور'],
        'store.account.forgot_lead' => ['أدخل بريدك الإلكتروني وسنرسل لك رابطًا لتعيين كلمة مرور جديدة.', 'أدخلي بريدكِ الإلكتروني وسنرسل لكِ رابطًا لتعيين كلمة مرور جديدة.'],
        'store.account.reset_new_link' => ['اطلب رابطًا جديدًا', 'اطلبي رابطًا جديدًا'],
        'store.account.reset_lead' => ['اختر كلمة مرور لم تستخدمها هنا من قبل. 8 أحرف على الأقل.', 'اختاري كلمة مرور لم تستخدميها هنا من قبل. 8 أحرف على الأقل.'],
        'store.addresses.empty' => ['لا عناوين محفوظة بعد. أضف عنوانًا أدناه وسيُعرض عند إتمام الطلب.', 'لا عناوين محفوظة بعد. أضيفي عنوانًا أدناه وسيظهر عند إتمام الطلب.'],
        'store.addresses.delete_confirm' => ['هل تريد حذف هذا العنوان؟', 'هل تريدين حذف هذا العنوان؟'],
        'store.addresses.use_as_default' => ['استخدمه افتراضيًا لهذا النوع', 'استخدميه افتراضيًا لهذا النوع'],
        'store.track.not_found' => ['تعذّر العثور على طلب يطابق هذا الرقم والبريد الإلكتروني. تحقق من كليهما وحاول مجددًا.', 'تعذّر العثور على طلب يطابق هذا الرقم والبريد الإلكتروني. تحقّقي من كليهما وحاولي مجددًا.'],
        'store.ugc.view_original' => ['شاهد المنشور الأصلي', 'شاهدي المنشور الأصلي'],
        'store.newsletter.done_subscribed_heading' => ['أنت على القائمة', 'أنتِ على القائمة'],
        'store.js.fbt_pick_one' => ['اختر منتجًا واحدًا على الأقل.', 'اختاري منتجًا واحدًا على الأقل.'],
        'store.js.subscribed' => ['أنت على القائمة ✓', 'أنتِ على القائمة ✓'],
        'store.js.review_failed' => ['تعذّر الإرسال — تحقق من اتصالك وحاول مرة أخرى.', 'تعذّر الإرسال — تحقّقي من اتصالكِ وحاولي مرة أخرى.'],
        'store.js.read_less' => ['اقرأ أقل ↑', 'عرض أقل ↑'],
        'store.footer.pay_cod' => ['COD', 'الدفع عند الاستلام'],
        // Latin-only drafts the owner now wants in Arabic ("nothing should be in english").
        'store.shop.page_title' => [':title · K-Beauty Bliss', ':title · كي بيوتي بليس'],
        'store.collection.page_title' => [':title · K-Beauty Bliss', ':title · كي بيوتي بليس'],
        'store.page.page_title' => [':title · K-Beauty Bliss', ':title · كي بيوتي بليس'],
        'store.shop.price_54_150' => ['AED 54 – 150', 'من 54 إلى 150 د.إ'],
        'store.shop.price_150_300' => ['AED 150 – 300', 'من 150 إلى 300 د.إ'],
        'store.shop.price_300p' => ['AED 300+', 'أكثر من 300 د.إ'],
        'store.home.trust_support_text' => ['WhatsApp :phone', 'واتساب :phone'],
        'store.quiz.brand_heading' => ['K-Beauty Bliss :suffix', 'كي بيوتي بليس :suffix'],
        'store.shop.price_u54' => ['أقل من AED 54', 'أقل من 54 د.إ'],
        'store.collection.title_under_54' => ['كل ما هو أقل من AED 54', 'كل ما هو أقل من 54 د.إ'],
    ];

    /**
     * Lane ARF — the owner, 15 October: "i need the full translation by
     * default ... without such weird arrow brackets", and of the cart and
     * checkout, "i don't need anything in english in arabic version". The
     * drafts still carrying a Latin word a shopper reads: the shop's name
     * (as the header's Arabic lockup writes it), WhatsApp (واتساب, as
     * ROUND2 already writes it), "A–Z", the T-zone and the SSL line; the
     * phone placeholder's x's become digits. One masculine imperative is
     * made feminine. Left Latin on purpose: e-mail address examples, browser
     * and file-format names, SKU / TRN, payment brands, "English" on the
     * language switch, and the merchant's own alert emails.
     *
     * Same shape and same rule as ROUND2. 2027_11_06_100000 rewrites an
     * existing row only while it is still a draft holding the shipped words.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ROUND3 = [
        'store.shop.sort_name' => ['الاسم A–Z', 'الاسم أبجديًا'],
        'store.cart.page_title' => ['السلة · K-Beauty Bliss', 'السلة · كي بيوتي بليس'],
        'store.wishlist.page_title' => ['المفضلة · K-Beauty Bliss', 'المفضلة · كي بيوتي بليس'],
        'store.home.quiz_option_combo' => ['منطقة T دهنية والخدود جافة', 'الجبهة والأنف دهنيان والخدود جافة'],
        'store.spotted.seo_desc' => ['روتينات حقيقية للعناية الكورية بالبشرة وفتح علب ونتائج يشاركها عملاء K-Beauty Bliss في الإمارات. شاهدي منشوراتهم وتسوقي المنتجات التي يحبونها.', 'روتينات حقيقية للعناية الكورية بالبشرة وفتح علب ونتائج يشاركها عملاء كي بيوتي بليس في الإمارات. شاهدي منشوراتهم وتسوقي المنتجات التي يحبونها.'],
        'store.home.about_heading' => ['عن K-Beauty Bliss', 'عن كي بيوتي بليس'],
        'store.reviews.reply_from' => ['رد من K-Beauty Bliss', 'رد من كي بيوتي بليس'],
        'store.checkout.page_title' => ['إتمام الطلب · K-Beauty Bliss', 'إتمام الطلب · كي بيوتي بليس'],
        'store.checkout.field_phone_placeholder' => ['+971 5x xxx xxxx', '+971 50 123 4567'],
        'store.checkout.whatsapp_optin' => ['أرسلوا لي تحديثات الطلب والعروض الجديدة — عبر WhatsApp والبريد الإلكتروني.', 'أرسلوا لي تحديثات الطلب والعروض الجديدة — عبر واتساب والبريد الإلكتروني.'],
        'store.checkout.ssl_secure' => ['محمي بـ SSL', 'اتصال آمن ومشفّر'],
        'store.order_received.page_title' => ['تم استلام الطلب · K-Beauty Bliss', 'تم استلام الطلب · كي بيوتي بليس'],
        'store.journal.footer_line' => ['© K-Beauty Bliss · جمال كوري أصلي في الإمارات', '© كي بيوتي بليس · جمال كوري أصلي في الإمارات'],
        'store.orders.page_title' => ['الطلبات · K-Beauty Bliss', 'الطلبات · كي بيوتي بليس'],
        'store.orders.detail_title' => ['الطلب رقم :number · K-Beauty Bliss', 'الطلب رقم :number · كي بيوتي بليس'],
        'store.account.reset_legacy_note' => ['ملاحظة: ستتوقف كلمة مرورك الأصلية في K Beauty Bliss عن العمل بمجرد حفظ هذه.', 'ملاحظة: ستتوقف كلمة مروركِ الأصلية في كي بيوتي بليس عن العمل بمجرد حفظ هذه.'],
        'store.track.page_title' => ['تتبع طلبي · K-Beauty Bliss', 'تتبع طلبي · كي بيوتي بليس'],
        'store.quiz.js_expert_body' => ['أرسل إجاباتك إلى خبيرة بشرة في K-Beauty Bliss — سنراجع روتينك ونراسلك عبر WhatsApp.', 'أرسلي إجاباتكِ إلى خبيرة بشرة في كي بيوتي بليس — سنراجع روتينكِ ونراسلكِ عبر واتساب.'],
        'store.quiz.js_label_phone' => ['الهاتف (WhatsApp)', 'الهاتف (واتساب)'],
        'email.common.sign_off' => ['— K Beauty Bliss', '— كي بيوتي بليس'],
        'email.reset.lead' => ['طلب أحدهم إعادة تعيين كلمة المرور على حسابك في K Beauty Bliss. إن كنت أنت فافتح الرابط أدناه واختر كلمة مرور جديدة.', 'طلب أحدهم إعادة تعيين كلمة المرور على حسابكِ في كي بيوتي بليس. إن كنتِ أنتِ فافتحي الرابط أدناه واختاري كلمة مرور جديدة.'],
        'store.push.order_processing_body' => ['نقوم بتجهيز طلبك من K-Beauty Bliss.', 'نقوم بتجهيز طلبكِ من كي بيوتي بليس.'],
    ];

    /**
     * The drafts with their corrections applied.
     *
     * @param  array<string, string>  $drafts
     * @return array<string, string>
     */
    public static function apply(array $drafts): array
    {
        foreach ([...self::ROUND2, ...self::ROUND3] as $key => [$old, $new]) {
            if (($drafts[$key] ?? null) === $old) {
                $drafts[$key] = $new;
            }
        }

        return $drafts;
    }
}
