<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

/**
 * The curated seed lexicon: the vocabulary of this trade, in English and Arabic.
 *
 * Two jobs. It RECOGNISES — which product type, ingredient, concern and skin
 * type a product name or ingredient list mentions — and it SEEDS — the phrases
 * Google Autocomplete is asked about. It is never published on its own: a page
 * only carries a lexicon phrase when the page itself is about that thing.
 *
 * Constants, not settings, so nothing here is operator input and every value is
 * already a clean keyword. The Sources tab shows it read-only.
 */
final class Lexicon
{
    /**
     * Product types: the word a shopper puts after "korean". Longest first, so
     * "cleansing oil" wins over "oil" and "eye cream" over "cream".
     *
     * @var array<string, array{0: string, 1: list<string>}> canonical => [arabic, aliases]
     */
    public const TYPES = [
        'cleansing balm' => ['بلسم تنظيف', ['cleansing balm']],
        'cleansing oil' => ['زيت تنظيف', ['cleansing oil']],
        'foam cleanser' => ['غسول رغوي', ['foam cleanser', 'foaming cleanser', 'cleansing foam']],
        'eye cream' => ['كريم العين', ['eye cream']],
        'sheet mask' => ['ماسك ورقي', ['sheet mask', 'mask sheet']],
        'sleeping mask' => ['ماسك ليلي', ['sleeping mask', 'sleeping pack']],
        'toner pad' => ['تونر باد', ['toner pad', 'toner pads', 'pads']],
        'pimple patch' => ['لصقات الحبوب', ['pimple patch', 'acne patch', 'spot patch']],
        'lip balm' => ['مرطب شفاه', ['lip balm', 'lip mask', 'lip sleeping']],
        'sunscreen' => ['واقي شمس', ['sunscreen', 'sun cream', 'sun stick', 'sun serum', 'sun essence', 'spf']],
        'cleanser' => ['غسول', ['cleanser', 'cleansing', 'face wash']],
        'toner' => ['تونر', ['toner', 'tonic']],
        'essence' => ['إيسنس', ['essence']],
        'serum' => ['سيروم', ['serum']],
        'ampoule' => ['أمبولة', ['ampoule']],
        'moisturizer' => ['مرطب', ['moisturizer', 'moisturiser', 'cream', 'gel cream', 'lotion', 'emulsion']],
        'mask' => ['ماسك', ['mask']],
        'exfoliator' => ['مقشر', ['exfoliator', 'peeling', 'scrub', 'exfoliating']],
        'mist' => ['بخاخ', ['mist']],
        'shampoo' => ['شامبو', ['shampoo']],
        'body lotion' => ['لوشن الجسم', ['body lotion', 'body wash', 'body cream']],
    ];

    /** @var array<string, string> ingredient => arabic */
    public const INGREDIENTS = [
        'snail mucin' => 'مخاط الحلزون',
        'centella' => 'سنتيلا',
        'niacinamide' => 'نياسيناميد',
        'hyaluronic acid' => 'حمض الهيالورونيك',
        'retinol' => 'ريتينول',
        'vitamin c' => 'فيتامين سي',
        'ceramide' => 'سيراميد',
        'rice' => 'الأرز',
        'propolis' => 'البروبوليس',
        'heartleaf' => 'هارتليف',
        'mugwort' => 'الشيح',
        'green tea' => 'الشاي الأخضر',
        'tea tree' => 'شجرة الشاي',
        'peptide' => 'الببتيد',
        'collagen' => 'الكولاجين',
        'panthenol' => 'بانثينول',
        'madecassoside' => 'ماديكاسوسيد',
        'arbutin' => 'أربوتين',
        'tranexamic acid' => 'حمض الترانيكساميك',
        'galactomyces' => 'جالاكتوميسيس',
        'bakuchiol' => 'باكوتشيول',
        'cica' => 'سيكا',
        'bha' => 'بي إتش إيه',
        'aha' => 'إيه إتش إيه',
        'pha' => 'حمض بولي هيدروكسي',
        'salicylic acid' => 'حمض الساليسيليك',
        'azelaic acid' => 'حمض الأزيليك',
        'probiotics' => 'البروبيوتيك',
        'ginseng' => 'الجينسنغ',
        'birch sap' => 'عصارة البتولا',
    ];

    /**
     * Concerns, keyed by App\Support\RoutineConcerns slugs so a product's
     * routine_concerns feed straight in. [for-phrase, benefit adjective, arabic for-phrase, words that signal it in a name].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public const CONCERNS = [
        'hydration' => ['dry skin', 'hydrating', 'للبشرة الجافة', ['hydrat', 'moistur', 'aqua', 'water', 'hyaluronic']],
        'dark-spots' => ['dark spots', 'brightening', 'للبقع الداكنة', ['bright', 'dark spot', 'glow', 'vitamin c', 'arbutin', 'niacinamide']],
        'acne' => ['acne', 'anti acne', 'لحب الشباب', ['acne', 'blemish', 'pimple', 'trouble', 'bha', 'salicylic', 'tea tree']],
        'ageing' => ['anti aging', 'anti aging', 'لمكافحة الشيخوخة', ['aging', 'ageing', 'wrinkle', 'firming', 'retinol', 'peptide', 'collagen']],
        'sensitivity' => ['sensitive skin', 'soothing', 'للبشرة الحساسة', ['sensitive', 'sooth', 'calm', 'relief', 'centella', 'cica', 'mugwort', 'heartleaf']],
        'pores' => ['pores', 'pore care', 'للمسام', ['pore', 'sebum', 'oil control', 'bha']],
        'dullness' => ['dull skin', 'glow', 'للبشرة الباهتة', ['glow', 'radiance', 'dull', 'rice', 'galactomyces']],
        'sun' => ['sun protection', 'spf', 'للحماية من الشمس', ['spf', 'sun', 'uv']],
    ];

    /** @var array<string, string> skin type => arabic */
    public const SKIN_TYPES = [
        'oily skin' => 'للبشرة الدهنية',
        'dry skin' => 'للبشرة الجافة',
        'sensitive skin' => 'للبشرة الحساسة',
        'combination skin' => 'للبشرة المختلطة',
    ];

    /** Industry phrasing. [english, arabic] */
    public const INDUSTRY = [
        ['korean skincare', 'العناية بالبشرة الكورية'],
        ['k-beauty', 'كي بيوتي'],
        ['korean beauty products', 'منتجات التجميل الكورية'],
        ['korean cosmetics', 'مستحضرات التجميل الكورية'],
    ];

    /**
     * UAE intent modifiers. "near me" is deliberately absent: this is an
     * online shop, and a page claiming a "near me" intent it cannot serve is
     * exactly the mismatch Google demotes.
     */
    public const UAE = [
        'en' => ['uae', 'dubai', 'abu dhabi', 'sharjah', 'price in uae', 'buy online', 'original'],
        'ar' => ['الامارات', 'دبي', 'أبوظبي', 'الشارقة', 'سعر في الامارات', 'شراء اونلاين', 'أصلي'],
    ];

    /** Seeds asked of Autocomplete before anything catalogue-derived. */
    public const SEEDS = [
        'en' => [
            'korean skincare', 'korean skincare uae', 'korean skincare dubai', 'k beauty uae',
            'korean sunscreen', 'korean serum', 'korean toner', 'korean cleanser', 'korean moisturizer',
            'korean skincare for acne', 'korean skincare for dry skin', 'korean skincare for oily skin',
            'korean skincare routine', 'best korean skincare',
        ],
        'ar' => [
            'منتجات كورية للبشرة', 'العناية بالبشرة الكورية', 'واقي شمس كوري', 'سيروم كوري',
            'تونر كوري', 'غسول كوري', 'مرطب كوري', 'منتجات كورية في الامارات', 'منتجات كورية دبي',
        ],
    ];

    /** Words that never anchor a match on their own. */
    public const STOP = [
        'the', 'and', 'for', 'with', 'of', 'in', 'to', 'a', 'an', 'by', 'on', 'new', 'best', 'set',
        'ml', 'g', 'ea', 'pcs', 'mini', 'special', 'edition', 'x', '+', 'من', 'في', 'على', 'مع',
    ];

    /** @return array{kind: ?string, ingredients: list<string>, concerns: list<string>, skin: list<string>} */
    public static function recognise(string $text, array $concernSlugs = []): array
    {
        $hay = ' '.KeywordText::norm($text).' ';
        $type = null;

        foreach (self::TYPES as $canon => [, $aliases]) {
            foreach ($aliases as $alias) {
                if (str_contains($hay, ' '.$alias.' ') || str_contains($hay, ' '.$alias.'s ')) {
                    $type = $canon;
                    break 2;
                }
            }
        }

        $ingredients = [];
        foreach (array_keys(self::INGREDIENTS) as $ing) {
            if (str_contains($hay, ' '.$ing.' ') || ($ing === 'snail mucin' && str_contains($hay, ' snail '))) {
                $ingredients[] = $ing;
            }
        }

        $concerns = array_values(array_filter($concernSlugs, static fn ($c) => isset(self::CONCERNS[$c])));
        foreach (self::CONCERNS as $slug => [, , , $signals]) {
            if (in_array($slug, $concerns, true)) {
                continue;
            }
            foreach ($signals as $signal) {
                if (str_contains($hay, $signal)) {
                    $concerns[] = $slug;
                    break;
                }
            }
        }

        $skin = [];
        foreach (array_keys(self::SKIN_TYPES) as $st) {
            if (str_contains($hay, ' '.$st.' ')) {
                $skin[] = $st;
            }
        }

        return [
            'kind' => $type,
            'ingredients' => array_slice($ingredients, 0, 3),
            'concerns' => array_slice($concerns, 0, 3),
            'skin' => $skin,
        ];
    }

    public static function typeAr(?string $type): ?string
    {
        return $type !== null ? (self::TYPES[$type][0] ?? null) : null;
    }
}
