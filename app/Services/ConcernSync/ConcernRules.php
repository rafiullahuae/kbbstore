<?php

declare(strict_types=1);

namespace App\Services\ConcernSync;

/**
 * The rule-based suggestions: no API, no key, no cost. (Lane SC)
 *
 * An ingredient or claim named on the product suggests the concern it is
 * known for. Deliberately conservative -- a word that appears on nearly every
 * label ("moisturising", "glycerin") suggests nothing, and a product whose
 * NAME says it is hair, body, lip, make-up or a device gets no suggestion at
 * all, because the seven concerns are face skincare. The owner ticks; this
 * only proposes.
 */
final class ConcernRules
{
    /** slug => [[regex, why]]. The first term that matches is the reason shown. */
    public const RULES = [
        'acne-blemish-care' => [
            ['salicylic acid', 'a BHA that clears blemishes'],
            ['\bbha\b', 'a BHA that clears blemishes'],
            ['azelaic', 'calms blemishes and post-acne marks'],
            ['tea[ -]?tree', 'a classic for blemish-prone skin'],
            ['benzoyl', 'targets breakouts'],
            ['\bacne\b', 'the product says it is for acne'],
            ['blemish', 'the product says it is for blemishes'],
        ],
        'pigmentation' => [
            ['tranexamic|\btxa\b', 'fades dark spots'],
            ['arbutin', 'fades dark spots'],
            ['kojic', 'fades discolouration'],
            ['niacinamide', 'evens tone and fades dark spots'],
            ['vitamin c|ascorbic', 'brightens and fades dark spots'],
            ['glutathione', 'brightens uneven tone'],
            ['dark spot|hyperpigment|melasma', 'the product says it is for dark spots'],
        ],
        'anti-aging' => [
            ['retinol|retinal\b|retinoid|retinyl', 'a retinoid for fine lines'],
            ['bakuchiol', 'a gentle retinol alternative for fine lines'],
            ['peptide', 'peptides for firmness and fine lines'],
            ['\bpdrn\b', 'PDRN for repair and elasticity'],
            ['collagen', 'collagen for a firmer look'],
            ['adenosine', 'adenosine for wrinkles'],
            ['wrinkle|fine lines|anti-?ag(e)?ing', 'the product says it is anti-ageing'],
        ],
        'hydration-glow' => [
            ['hyaluronic|sodium hyaluronate', 'hyaluronic acid for hydration'],
            ['snail mucin|mucin', 'mucin for hydration and glow'],
            ['glass skin|dewy|\bglow\b', 'the product promises a glow'],
            ['deep hydration|intensive hydration|dehydrat', 'the product says it is for dehydrated skin'],
        ],
        'pore-care' => [
            ['\bpores?\b|poreless', 'the product says it is for pores'],
            ['blackhead|whitehead|sebaceous', 'clears blackheads'],
            ['salicylic acid|\bbha\b|\blha\b', 'exfoliates inside the pore'],
            ['\bclay\b|kaolin|volcanic', 'clay draws out pores'],
        ],
        'oily-skin' => [
            ['sebum|oil[- ]control|oily skin|mattif|excess oil', 'the product says it controls oil'],
        ],
        'redness-sensitive-skin' => [
            ['centella|\bcica\b|madecass', 'centella calms sensitive skin'],
            ['panthenol|vitamin b5', 'panthenol soothes and repairs'],
            ['ceramide', 'ceramides repair the barrier'],
            ['heartleaf|houttuynia', 'heartleaf calms redness'],
            ['mugwort|artemisia', 'mugwort calms irritation'],
            ['redness|sensitive skin|irritat', 'the product says it is for sensitive skin'],
        ],
    ];

    /** A name matching this is not face skincare: no suggestion at all. */
    public const NOT_FACE = '/\b(shampoo|conditioner|hair|scalp|lash|brow|lip|body|hand|foot|feet|nail|deodorant|perfume|fragrance|device|brush|led|steamer|mascara|cushion|foundation|tint|blush|concealer|powder|primer|jelly stick|supplement|curl|styler|spray)\b/i';

    /**
     * @param  array<string, mixed>  $product  as ConcernSync::unassigned() returns it
     * @param  array<string, array{id:int, name:string}>  $concerns
     * @return list<array{slug:string, reason:string}>
     */
    public static function suggest(array $product, array $concerns): array
    {
        if (preg_match(self::NOT_FACE, (string) $product['name']) === 1) {
            return [];
        }

        $text = mb_strtolower(implode(' ', [
            $product['name'] ?? '', $product['short'] ?? '', $product['ingredients'] ?? '', $product['description'] ?? '',
        ]));

        $out = [];

        foreach (self::RULES as $slug => $rules) {
            if (! isset($concerns[$slug])) {
                continue;
            }

            foreach ($rules as [$pattern, $why]) {
                if (preg_match('/'.$pattern.'/iu', $text, $m) === 1) {
                    $out[] = ['slug' => $slug, 'reason' => 'Mentions “'.trim($m[0]).'” — '.$why.'.'];
                    break;
                }
            }
        }

        return $out;
    }
}
