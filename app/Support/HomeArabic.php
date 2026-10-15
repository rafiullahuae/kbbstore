<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The homepage's own wording, in Arabic (Lane AR round 2).
 *
 * The owner: "i also need every single thing RTL for arabic. must match the
 * english version layout ... in any sections, anywhere". Every homepage
 * heading, line and button is an owner setting (HomeSections::SCHEMA, the hero
 * slides) and NONE of them had an Arabic twin, so /ar/ printed "Loved most",
 * "Best-Selling Korean Skincare in the UAE" and the whole hero in English in
 * the middle of an Arabic page -- where the bidi algorithm then hung each
 * English sentence's full stop on its left (".ranked by units sold").
 *
 * This is the same `<key>_ar` pattern HomeConcerns, واتسابButton and the cart
 * panel's coupon line already use, made one rule for the whole page:
 *
 *   - SCHEMA is spread into HomepageContent::SCHEMA and shown as ONE tab,
 *     Appearance → Homepage → Homepage content → Arabic wording. Each field
 *     ships with the shipped English default's Arabic.
 *   - pick() is the only reader. It answers ONLY on /ar: an English page never
 *     calls into the Arabic half, so English output is byte-identical.
 *
 * pick()'s honesty rule: the shipped Arabic is a translation of the shipped
 * ENGLISH. When the owner has rewritten the English and not touched the Arabic,
 * the shipped Arabic no longer says what his English says, so the English is
 * shown instead -- a correct sentence in the other language beats a confident
 * translation of copy that is gone. Once he types his own Arabic it always
 * wins. And an English field he emptied ("Empty hides the heading") stays
 * empty on /ar too: hiding a heading is a layout decision, not a wording one.
 *
 * No query, no settings read of its own: every value arrives in the `$c` map
 * the page already read once (SettingsRequestMemo).
 */
final class HomeArabic
{
    /** English key => its Arabic, as shipped. */
    public const TEXT = [
        'home_ab_eyebrow' => 'قصتنا',
        'home_ab_title' => 'عن كي بيوتي بليس الإمارات',
        'home_bs_eyebrow' => 'الأكثر حبًا',
        'home_bs_title' => 'العناية الكورية الأكثر مبيعًا في الإمارات',
        'home_bs_sub' => 'منتجات العناية الكورية التي تشتريها عميلاتنا أكثر من غيرها، مرتّبة حسب عدد القطع المبيعة.',
        'home_bs_btn' => 'تسوّقي الأكثر مبيعًا',
        'home_br_eyebrow' => 'علاماتنا التجارية',
        'home_br_title' => 'تسوّقي أشهر علامات الجمال الكورية',
        'home_br_sub' => 'تصفّحي علامات الجمال الكورية المتوفرة لدينا وتسوّقي كامل منتجات كل علامة في مكان واحد.',
        'home_br_btn' => 'تسوّقي كل العلامات',
        'home_tr_eyebrow' => 'رائج هذا الأسبوع',
        'home_tr_title' => 'الأكثر رواجًا في الجمال الكوري هذا الأسبوع',
        'home_tr_sub' => 'منتجات الجمال الكوري الأكثر طلبًا ومشاهدة في متجرنا خلال الأيام السبعة الماضية.',
        'home_tr_btn' => '',
        'home_bl_eyebrow' => 'المدوّنة',
        'home_bl_title' => 'نصائح وأدلة العناية الكورية بالبشرة',
        'home_bl_sub' => 'مقالات جديدة من مدوّنة كي بيوتي بليس تساعدكِ على اختيار منتجات العناية الكورية واستخدامها.',
        'home_bl_more' => 'اقرئي المقال',
        'home_bl_btn' => '',
        'home_u54_eyebrow' => 'رفاهية صغيرة',
        'home_u54_title' => 'جمال كوري بأقل من 54 درهمًا',
        'home_u54_sub' => 'اكتشافات من العناية والجمال الكوري لا يتجاوز سعر كل منها 54 درهمًا.',
        'home_u54_btn' => 'تسوّقي كل ما هو أقل من 54 درهمًا',
        'home_ft_l_title' => 'استقبلي الشمس بثقة!',
        'home_ft_l_text' => 'واقي الشمس اليومي هو الخطوة التي يُبنى عليها كل روتين كوري. تصفّحي واقيات الشمس الكورية لدينا واختاري القوام الذي يناسب بشرتكِ.',
        'home_ft_l_btn' => 'تسوّقي الآن',
        'home_ft_l_alt' => '',
        'home_ft_r_title' => 'أفضل اختيارات الجمال الكوري',
        'home_ft_r_text' => 'لا تعرفين من أين تبدئين؟ ابدئي بالمنتجات الأكثر مبيعًا في كي بيوتي بليس وابني روتينكِ انطلاقًا منها.',
        'home_ft_r_btn' => 'تسوّقي الآن',
        'home_ft_r_alt' => '',
    ];

    /** HomeSections::ABOUT_DEFAULT, paragraph for paragraph. */
    public const ABOUT = "في كي بيوتي بليس الإمارات، نحن شغوفون بتقديم أفضل ما في الجمال الكوري لعاشقات العناية بالبشرة في كل أنحاء الإمارات. مهمتنا أن نوفّر لكِ منتجات كورية أصلية وعالية الجودة تمنحكِ نتائج حقيقية. سواء كنتِ تبحثين عن تطوير روتين العناية، أو اكتشاف أحدث صيحات الجمال، أو إيجاد حلول فعّالة لمشاكل بشرتكِ، فنحن هنا من أجلكِ.\n\n"
        ."نختار تشكيلتنا بعناية لنضمن أن كل منتج يلبّي أعلى معايير الجودة والفعالية. من العناية بالبشرة والشعر إلى المكياج ومجموعات الجمال، نقدّم لكِ تشكيلة واسعة صُمّمت لتلبّي كل احتياجاتكِ.\n\n"
        ."رضاكِ في قلب كل ما نقوم به. مع خيارات توصيل سريعة، منها التوصيل خلال 1-3 أيام في كل أنحاء الإمارات والشحن المجاني للطلبات فوق 199 درهمًا، نجعل حصولكِ على منتجاتكِ المفضّلة سهلًا ومريحًا.\n\n"
        .'انضمّي إلى مجتمع كي بيوتي بليس وعيشي ثورة الجمال التي تجتاح العالم. رحلتكِ نحو بشرة مشرقة وخالية من العيوب تبدأ من هنا!';

    /** The three shipped hero slides' Arabic, by position (HomepageContent::DEFAULT_SLIDES). */
    public const SLIDES = [
        ['kicker_ar' => 'ميديكوب · عرض لفترة محدودة', 'heading_ar' => "جهاز إيج-آر بوستر برو\nمع مجموعة هدايا مجانية",
            'text_ar' => 'الجهاز الذي يسأل عنه الجميع، مع مجموعة بي دي آر إن للإشراقة.', 'button_ar' => 'تسوّقي ميديكوب'],
        ['kicker_ar' => '{brands} علامة · مباشرة من المصدر', 'heading_ar' => "متجر الجمال\nالكوري الأصلي",
            'text_ar' => 'كل منتج أصلي، وكل طلب نتحقق منه. هدايا مجانية مع كل طلب.', 'button_ar' => 'تسوّقي كل العلامات'],
        ['kicker_ar' => 'تابي · تمارا · الدفع عند الاستلام', 'heading_ar' => "ادفعي لاحقًا،\nوالتوصيل خلال 1–3 أيام",
            'text_ar' => "قسّمي أي طلب على أربع دفعات.\nتوصيل مجاني في كل أنحاء الإمارات للطلبات فوق {free_from}.", 'button_ar' => 'تسوّقي التخفيضات الكبرى'],
    ];

    /** The four slide fields that carry words, each with its Arabic twin's schema. */
    public const SLIDE_SCHEMA = [
        'kicker_ar' => ['type' => 'text', 'label' => 'Eyebrow · Arabic', 'default' => '', 'help' => 'Shown on the Arabic shop (/ar/). Empty: the English eyebrow.'],
        'heading_ar' => ['type' => 'textarea', 'label' => 'Headline · Arabic', 'default' => '', 'help' => 'Shown on /ar/. Press Enter for a line break. Empty: the English headline.'],
        'text_ar' => ['type' => 'textarea', 'label' => 'Supporting line · Arabic', 'default' => '', 'help' => 'Shown on /ar/. {brands} and {free_from} work here too. Empty: the English line.'],
        'button_ar' => ['type' => 'text', 'label' => 'Button wording · Arabic', 'default' => '', 'help' => 'Shown on /ar/. Empty: the English wording.'],
    ];

    /**
     * The `<key>_ar` fields, spread into HomepageContent::SCHEMA. Literal, so
     * the console's generic renderer and ModuleSchema read them like any other.
     */
    public const SCHEMA = [
        'about_text_ar' => ['type' => 'textarea', 'label' => 'About us paragraphs · Arabic', 'default' => self::ABOUT, 'store' => 'setting', 'help' => 'Shown on /ar/. A blank line starts a new paragraph. Empty: the English paragraphs.'],
        'home_ab_eyebrow_ar' => ['type' => 'text', 'label' => 'About us · small line above the heading · Arabic', 'default' => self::TEXT['home_ab_eyebrow'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_ab_title_ar' => ['type' => 'text', 'label' => 'About us · heading · Arabic', 'default' => self::TEXT['home_ab_title'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English heading.'],
        'home_bs_eyebrow_ar' => ['type' => 'text', 'label' => 'Best Sellers · small line above the heading · Arabic', 'default' => self::TEXT['home_bs_eyebrow'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_bs_title_ar' => ['type' => 'text', 'label' => 'Best Sellers · heading · Arabic', 'default' => self::TEXT['home_bs_title'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English heading.'],
        'home_bs_sub_ar' => ['type' => 'text', 'label' => 'Best Sellers · line under the heading · Arabic', 'default' => self::TEXT['home_bs_sub'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_bs_btn_ar' => ['type' => 'text', 'label' => 'Best Sellers · button · Arabic', 'default' => self::TEXT['home_bs_btn'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English wording.'],
        'home_br_eyebrow_ar' => ['type' => 'text', 'label' => 'Brands · small line above the heading · Arabic', 'default' => self::TEXT['home_br_eyebrow'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_br_title_ar' => ['type' => 'text', 'label' => 'Brands · heading · Arabic', 'default' => self::TEXT['home_br_title'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English heading.'],
        'home_br_sub_ar' => ['type' => 'text', 'label' => 'Brands · line under the heading · Arabic', 'default' => self::TEXT['home_br_sub'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_br_btn_ar' => ['type' => 'text', 'label' => 'Brands · button · Arabic', 'default' => self::TEXT['home_br_btn'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English wording.'],
        'home_tr_eyebrow_ar' => ['type' => 'text', 'label' => 'Trending · small line above the heading · Arabic', 'default' => self::TEXT['home_tr_eyebrow'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_tr_title_ar' => ['type' => 'text', 'label' => 'Trending · heading · Arabic', 'default' => self::TEXT['home_tr_title'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English heading.'],
        'home_tr_sub_ar' => ['type' => 'text', 'label' => 'Trending · line under the heading · Arabic', 'default' => self::TEXT['home_tr_sub'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_tr_btn_ar' => ['type' => 'text', 'label' => 'Trending · button · Arabic', 'default' => '', 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English wording.'],
        'home_bl_eyebrow_ar' => ['type' => 'text', 'label' => 'Journal · small line above the heading · Arabic', 'default' => self::TEXT['home_bl_eyebrow'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_bl_title_ar' => ['type' => 'text', 'label' => 'Journal · heading · Arabic', 'default' => self::TEXT['home_bl_title'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English heading.'],
        'home_bl_sub_ar' => ['type' => 'text', 'label' => 'Journal · line under the heading · Arabic', 'default' => self::TEXT['home_bl_sub'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_bl_more_ar' => ['type' => 'text', 'label' => 'Journal · link under each article · Arabic', 'default' => self::TEXT['home_bl_more'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English wording.'],
        'home_bl_btn_ar' => ['type' => 'text', 'label' => 'Journal · button · Arabic', 'default' => '', 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English wording.'],
        'home_u54_eyebrow_ar' => ['type' => 'text', 'label' => 'Under AED 54 · small line above the heading · Arabic', 'default' => self::TEXT['home_u54_eyebrow'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_u54_title_ar' => ['type' => 'text', 'label' => 'Under AED 54 · heading · Arabic', 'default' => self::TEXT['home_u54_title'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English heading.'],
        'home_u54_sub_ar' => ['type' => 'text', 'label' => 'Under AED 54 · line under the heading · Arabic', 'default' => self::TEXT['home_u54_sub'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English line.'],
        'home_u54_btn_ar' => ['type' => 'text', 'label' => 'Under AED 54 · button · Arabic', 'default' => self::TEXT['home_u54_btn'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English wording.'],
        'home_ft_l_title_ar' => ['type' => 'text', 'label' => 'Two features · left title · Arabic', 'default' => self::TEXT['home_ft_l_title'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English title.'],
        'home_ft_l_text_ar' => ['type' => 'textarea', 'label' => 'Two features · left text · Arabic', 'default' => self::TEXT['home_ft_l_text'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English text.'],
        'home_ft_l_btn_ar' => ['type' => 'text', 'label' => 'Two features · left link text · Arabic', 'default' => self::TEXT['home_ft_l_btn'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English wording.'],
        'home_ft_l_alt_ar' => ['type' => 'text', 'label' => 'Two features · left photo description · Arabic', 'default' => '', 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English description.'],
        'home_ft_r_title_ar' => ['type' => 'text', 'label' => 'Two features · right title · Arabic', 'default' => self::TEXT['home_ft_r_title'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English title.'],
        'home_ft_r_text_ar' => ['type' => 'textarea', 'label' => 'Two features · right text · Arabic', 'default' => self::TEXT['home_ft_r_text'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English text.'],
        'home_ft_r_btn_ar' => ['type' => 'text', 'label' => 'Two features · right link text · Arabic', 'default' => self::TEXT['home_ft_r_btn'], 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English wording.'],
        'home_ft_r_alt_ar' => ['type' => 'text', 'label' => 'Two features · right photo description · Arabic', 'default' => '', 'store' => 'setting', 'help' => 'Shown on /ar/. Empty: the English description.'],
    ];

    /** Appearance → Homepage → Homepage content → Arabic wording. Spread into HomepageContent::TABS. */
    public const TABS = [
        'arabic' => ['Arabic wording', 'What the Arabic shop (/ar/) prints for each homepage heading, line and button. Ships with the Arabic of the shipped English. Empty: the English text. Change the English without changing the Arabic and the English is shown on /ar/ until you write its Arabic.', [
            // In SCHEMA's own order: the live editor claims SCHEMA keys in order
            // (HomepageLiveEditTest), and this is a page-wide tab (PAGE_TABS).
            'about_text_ar', 'home_ab_eyebrow_ar', 'home_ab_title_ar',
            'home_bs_eyebrow_ar', 'home_bs_title_ar', 'home_bs_sub_ar', 'home_bs_btn_ar',
            'home_br_eyebrow_ar', 'home_br_title_ar', 'home_br_sub_ar', 'home_br_btn_ar',
            'home_tr_eyebrow_ar', 'home_tr_title_ar', 'home_tr_sub_ar', 'home_tr_btn_ar',
            'home_bl_eyebrow_ar', 'home_bl_title_ar', 'home_bl_sub_ar', 'home_bl_more_ar', 'home_bl_btn_ar',
            'home_u54_eyebrow_ar', 'home_u54_title_ar', 'home_u54_sub_ar', 'home_u54_btn_ar',
            'home_ft_l_title_ar', 'home_ft_l_text_ar', 'home_ft_l_btn_ar', 'home_ft_l_alt_ar',
            'home_ft_r_title_ar', 'home_ft_r_text_ar', 'home_ft_r_btn_ar', 'home_ft_r_alt_ar',
        ]],
    ];

    /** Is this request the Arabic shop? One place, so every reader agrees. */
    public static function on(): bool
    {
        return Locale::current() === 'ar';
    }

    /**
     * The words to print on /ar for one setting, given the English the page
     * would print. See the class docblock for the rule.
     *
     * @param  array<string, mixed>  $c  the homepage settings map the page already holds
     */
    public static function pick(array $c, string $key, string $english, string $englishDefault): string
    {
        if ($english === '' || ! array_key_exists($key, self::TEXT)) {
            return $english;
        }

        $shipped = self::TEXT[$key];
        $arabic = trim(RichText::toText((string) ($c[$key.'_ar'] ?? $shipped)));

        if ($arabic === '' || ($arabic === $shipped && trim($english) !== trim(RichText::toText($englishDefault)))) {
            return $english;
        }

        return $arabic;
    }

    /** The About us paragraphs on /ar, by the same rule. */
    public static function about(array $c, string $english): string
    {
        if ($english === '') {
            return '';
        }

        $arabic = trim((string) ($c['about_text_ar'] ?? self::ABOUT));

        if ($arabic === '' || ($arabic === self::ABOUT && trim($english) !== trim(HomeSections::ABOUT_DEFAULT))) {
            return $english;
        }

        return $arabic;
    }

    /**
     * One hero slide's words on /ar: each of the four fields its Arabic twin
     * when that is set, the English otherwise. An emptied English field stays
     * empty. The slide's links and colours are never touched.
     *
     * @param  array<string, string>  $slide
     * @return array<string, string>
     */
    public static function slide(array $slide): array
    {
        foreach (['kicker', 'heading', 'text', 'button'] as $field) {
            $arabic = trim((string) ($slide[$field.'_ar'] ?? ''));

            if ($arabic !== '' && trim((string) ($slide[$field] ?? '')) !== '') {
                $slide[$field] = $arabic;
            }
        }

        return $slide;
    }
}
