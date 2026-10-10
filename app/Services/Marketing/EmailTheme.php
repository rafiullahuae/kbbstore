<?php

declare(strict_types=1);

namespace App\Services\Marketing;

/**
 * A campaign's LOOK and LANGUAGE (Lane EC) — chosen once per campaign or
 * template, not per block.
 *
 *   theme   standard  the shop's email kit (look A), as every campaign so far
 *           playful   design C, "Playful K-beauty": the owner picked it from
 *                     Lane ED's "New Look, Less Prices" previews on 10 October
 *                     — pastel cards with type stickers, the highlighter
 *                     headline, emoji benefit chips, the dark closing button.
 *           letter    Lane EP's personal letter (PersonalLetter): "Hi {first
 *                     name}," and a few paragraphs, the best chance a
 *                     campaign has at Gmail's Primary tab.
 *   locale  en | ar   ar renders right to left with the theme's Arabic words.
 *
 * Both are a select: clean*() stores one of its own options or the default,
 * and everything printed raw from here is a constant (colours, entities).
 *
 * THERE IS NO RETURNS LINK OR WORD ANYWHERE IN HERE. The owner, 10 October:
 * "remove the returns words completely, we don't offer returns." The preview's
 * footer row "Shipping & delivery · Returns · Privacy policy · Contact us" is
 * "Shipping & delivery · Privacy policy · Contact us".
 */
final class EmailTheme
{
    public const THEMES = [
        'standard' => 'Standard (the shop\'s email look)',
        'playful' => 'Playful K-beauty (New look)',
        // Lane EP: a short letter from a person (PersonalLetter).
        'letter' => 'Personal letter style (best chance for the Primary tab)',
    ];

    public const LOCALES = [
        'en' => 'English',
        'ar' => 'Arabic (right to left)',
    ];

    /** Design C's palette, as Lane ED's preview prints it. Constants: printed raw. */
    public const C = [
        'bg' => '#FFE4EC', 'card' => '#FFFFFF', 'pink' => '#C6395F', 'deep' => '#C13E63', 'ink3' => '#A82F53',
        'hl' => '#FFD3E0', 'text' => '#2A2228', 'text2' => '#5E545A', 'muted' => '#857A82', 'link' => '#6E646B',
        'rule' => '#F6C9D7',
    ];

    /** The six card tints, in turn, and what dark mode turns each into. */
    public const TINTS = ['#FFF0F5', '#F3EEFF', '#FFF1E8', '#EAF8F1', '#FFF8DC', '#EAF4FF'];

    public const TINTS_DARK = ['#3A2430', '#2E2840', '#3A2C26', '#22332B', '#36321F', '#232F3D'];

    /** The benefit chips' tints, in turn. */
    public const CHIP_TINTS = ['#FFF0F5', '#F3EEFF', '#FFF1E8', '#EAF8F1'];

    /** Outfit first (the kit serves it), then the system fonts; Arabic body fonts for ar. */
    public const SANS = "'Outfit',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    public const SANS_AR = "'IBM Plex Sans Arabic','Geeza Pro',Tahoma,'Outfit',Arial,sans-serif";

    /** The footer's pages: the shop's real routes. No returns page: the shop does not offer returns. */
    public const FOOTER_LINKS = [
        'delivery' => '/delivery/',
        'privacy' => '/privacy-policy/',
        'contact' => '/contact-us/',
    ];

    /**
     * The words the playful look prints itself, per language. :store is the
     * shop's name; everything else the owner types lives in the blocks.
     */
    public const WORDS = [
        'en' => [
            'tagline' => 'Authentic Korean beauty, delivered across the UAE',
            'delivery' => 'Shipping & delivery', 'privacy' => 'Privacy policy', 'contact' => 'Contact us',
            'unsubscribe' => 'Unsubscribe', 'preferences' => 'Email preferences',
            'why_customers' => 'You are receiving this because you shopped with :store before.',
            'why_subscribers' => 'You are receiving this because you subscribed to :store news.',
            'why_account' => 'You are receiving this because you have an account with :store.',
            'copyright' => '© :year :store. All rights reserved.',
            'view' => 'View this email in your browser',
            'text_unsubscribe' => 'To stop these emails, open:',
            'nav_shop' => 'Shop', 'nav_track' => 'Track order', 'nav_account' => 'My account',
        ],
        'ar' => [
            'tagline' => 'مستحضرات تجميل كورية أصلية، تصلك في جميع أنحاء الإمارات',
            'delivery' => 'الشحن والتوصيل', 'privacy' => 'سياسة الخصوصية', 'contact' => 'تواصلي معنا',
            'unsubscribe' => 'إلغاء الاشتراك', 'preferences' => 'تفضيلات البريد',
            'why_customers' => 'وصلتكِ هذه الرسالة لأنكِ تسوّقتِ من :store من قبل.',
            'why_subscribers' => 'وصلتكِ هذه الرسالة لأنكِ اشتركتِ في نشرة :store.',
            'why_account' => 'وصلتكِ هذه الرسالة لأن لديكِ حسابًا لدى :store.',
            'copyright' => 'جميع الحقوق محفوظة © :year :store',
            'view' => 'عرض الرسالة في المتصفح',
            'text_unsubscribe' => 'لإيقاف هذه الرسائل، افتحي:',
            'nav_shop' => 'المتجر', 'nav_track' => 'تتبّع الطلب', 'nav_account' => 'حسابي',
        ],
    ];

    /**
     * A product's type sticker ("Set", "Mist", "Eye care"…), read from its
     * name — no query, no setting. First match wins, so "Eye Mask" is eye care
     * and not a mask. No match, no sticker.
     */
    private const KINDS = [
        'set' => '/\b(set|kit|duo|trio|bundle|routine)\b/i',
        'eye' => '/\beye\b|under[- ]eye/i',
        'sun' => '/\b(sun|spf\s*\d*|sunscreen|sunblock)\b/i',
        'mist' => '/\bmist\b/i',
        'cleanser' => '/\b(cleans\w*|foam|wash|cleansing)\b/i',
        'toner' => '/\btoner\b/i',
        'pads' => '/\bpads?\b/i',
        'essence' => '/\bessence\b/i',
        'ampoule' => '/\bampoule\b/i',
        'serum' => '/\bserum\b/i',
        'booster' => '/\b(booster|shot)\b/i',
        'mask' => '/\bmasks?\b/i',
        'cream' => '/\b(cream|moisturi[sz]er|lotion|gel)\b/i',
        'lip' => '/\blip\b/i',
    ];

    public const KIND_WORDS = [
        'en' => [
            'set' => 'Set', 'eye' => 'Eye care', 'sun' => 'Sun care', 'mist' => 'Mist', 'cleanser' => 'Cleanser',
            'toner' => 'Toner', 'pads' => 'Pads', 'essence' => 'Essence', 'ampoule' => 'Ampoule', 'serum' => 'Serum',
            'booster' => 'Booster', 'mask' => 'Mask', 'cream' => 'Cream', 'lip' => 'Lip care',
        ],
        'ar' => [
            'set' => 'مجموعة', 'eye' => 'للعيون', 'sun' => 'واقي شمس', 'mist' => 'بخاخ', 'cleanser' => 'منظّف',
            'toner' => 'تونر', 'pads' => 'بادات', 'essence' => 'إيسنس', 'ampoule' => 'أمبولة', 'serum' => 'سيروم',
            'booster' => 'معزّز', 'mask' => 'قناع', 'cream' => 'كريم', 'lip' => 'للشفاه',
        ],
    ];

    public static function cleanTheme(mixed $v): string
    {
        return is_string($v) && isset(self::THEMES[$v]) ? $v : 'standard';
    }

    public static function cleanLocale(mixed $v): string
    {
        return is_string($v) && isset(self::LOCALES[$v]) ? $v : 'en';
    }

    public static function dir(string $locale): string
    {
        return $locale === 'ar' ? 'rtl' : 'ltr';
    }

    /** A word of the playful look in $locale, with :store / :year filled in. */
    public static function word(string $locale, string $key, array $vars = []): string
    {
        $s = self::WORDS[self::cleanLocale($locale)][$key] ?? self::WORDS['en'][$key] ?? '';

        foreach ($vars as $k => $v) {
            $s = str_replace(':' . $k, (string) $v, $s);
        }

        return $s;
    }

    /** The sticker key for a product, or null. */
    public static function kind(string $name, string $type = ''): ?string
    {
        if ($type === 'set') {
            return 'set';
        }

        foreach (self::KINDS as $key => $re) {
            if (preg_match($re, $name) === 1) {
                return $key;
            }
        }

        return null;
    }

    public static function kindWord(?string $kind, string $locale): string
    {
        return $kind === null ? '' : (self::KIND_WORDS[self::cleanLocale($locale)][$kind] ?? '');
    }

    /** "Medicube PDRN Collagen Set" under the brand line "Medicube" reads "PDRN Collagen Set". */
    public static function shortName(string $name, string $brand): string
    {
        $name = trim($name);
        $brand = trim($brand);

        if ($brand !== '' && mb_strlen($name) > mb_strlen($brand) + 2 && mb_stripos($name, $brand) === 0) {
            $rest = trim(mb_substr($name, mb_strlen($brand)), " \t-–—:|·,");

            return $rest !== '' ? $rest : $name;
        }

        return $name;
    }

    /** "AED 358" → "358" for the struck-through price beside the new one. */
    public static function bareAmount(?string $money): ?string
    {
        if ($money === null || $money === '') {
            return null;
        }

        $n = trim((string) preg_replace('/[^\d.,]+/u', ' ', $money));

        return $n !== '' ? $n : $money;
    }
}
