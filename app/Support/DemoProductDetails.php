<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Description, Ingredients and How to use of a DEMO product.
 *                                                              (Lane PDP2, R4)
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * The owner asked to see the product page's detail tabs working:
 *
 *   "also put some demo tabs on the product page, so i can see in action."
 *
 * He never had. `partials/product-tabs.blade.php` builds its tab row from
 * App\Support\ProductTabs, whose three built-ins read `description`,
 * `ingredients` and `how_to_use` — and DemoCatalogueSeeder set NONE of the
 * three. It set `short_description` only. ProductTabs drops any entry whose
 * body is empty (`trim(strip_tags($body)) !== ''`), Description falls back to
 * the short description, so every demo product rendered EXACTLY ONE tab and the
 * strip had nothing to switch between. Demo content is off by default, so
 * DemoContent::tabs()'s top-up never ran either.
 *
 * ── WHY IT IS A CLASS AND NOT TEXT IN THE SEEDER ────────────────────────────
 *
 * Because two things need the same words. The seeder fixes a FRESH install; the
 * owner's shop is already seeded, so a seeder edit would change nothing he can
 * see — the migration beside it is what reaches his running shop. Two copies of
 * this copy would be two copies to keep in step, and this project has paid for
 * that shape twice (HomepageLayouts::summaries(), ProductStyles' image ratio).
 * One source, two readers, and DemoProductTabsTest asserts the migration writes
 * what this class says.
 *
 * ── IT STATES NO CLAIM, AND THAT IS A RULE HERE ─────────────────────────────
 *
 * Same rule App\Services\DemoContent's header sets out and for the same reason:
 * a stand-in is a piece of LAYOUT, and a percentage, a clinical figure, a
 * certification or a named ingredient concentration is a CLAIM — which does not
 * become true because a preview aid made it. So there is not one number in any
 * string below, no "dermatologically tested", no "clinically proven", and every
 * Description ends with the same sentence saying what it is. The ingredient
 * lists are ordinary INCI names with no strengths attached.
 *
 * ── FAMILY, FROM THE NAME ───────────────────────────────────────────────────
 *
 * The family is read out of the product's own NAME rather than out of its
 * category, because the migration has a name in hand and would otherwise need a
 * join to answer the same question. Order matters in the match below: "Rice
 * Probiotics Toner" is a toner and "Relief Sun Rice + Probiotics SPF50+" is a
 * sunscreen, so `spf`/`sun` is tested before `toner`. Anything unrecognised
 * lands on `other`, which is a complete entry rather than a blank — a product
 * whose name this class does not know still gets three tabs.
 */
final class DemoProductDetails
{
    /** The sentence every demo Description ends on. */
    public const DISCLOSURE = 'This is placeholder copy for layout testing and will be replaced by the WordPress migration.';

    /*
     * ── HOW A DEMO PRODUCT IS RECOGNISED, AND WHY IT IS THREE TESTS ─────────
     *
     * The backfill migration beside this class must reach the owner's running
     * shop and must not touch a single real product when the WordPress import
     * lands. DemoCatalogueSeeder leaves three marks and they are independent:
     *
     *   wc_id IS NULL             the seeder's own header names this as the
     *                             mark the Migrator keys on — it upserts on
     *                             wc_id, so a seeded row can never be mistaken
     *                             for an imported one. NOT ENOUGH ON ITS OWN:
     *                             a product typed into the admin has it too.
     *   sku LIKE 'DEMO-%'         'DEMO-0001' … 'DEMO-0024', written by the
     *                             seeder and by nothing else.
     *   short_description = …     the exact sentence below, on all 24 rows and
     *                             on nothing an owner would write. CLAUDE.md's
     *                             brief for this round names this one as the
     *                             unambiguous fallback; it is used as one of
     *                             three rather than instead of them.
     *
     * ALL THREE MUST MATCH. Any one of them alone is a defensible answer and
     * the conjunction is a stricter one: a real product would have to carry a
     * DEMO- SKU, no WooCommerce id AND that exact sentence to be caught, and if
     * it did it would be a demo product. DemoProductBackfillTest seeds a real
     * product that breaks each mark in turn and asserts it is left alone.
     */
    public const SEEDED_SHORT_DESCRIPTION = 'Demo product for layout testing. Replaced by the WordPress migration.';

    /** The SKU prefix DemoCatalogueSeeder writes and nothing else does. */
    public const SKU_PREFIX = 'DEMO-';

    /**
     * family => [what it is, what it is for, the INCI list, the three steps]
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>, 3: list<string>}>
     */
    private const FAMILIES = [
        'sunscreen' => [
            'a daily sun fluid',
            'It finishes light rather than heavy, sits under make-up without pilling, and is meant for the last step of a morning routine.',
            ['Aqua', 'Ethylhexyl Methoxycinnamate', 'Glycerin', 'Dibutyl Adipate', 'Niacinamide', 'Oryza Sativa Extract', 'Butylene Glycol', 'Panthenol', 'Tocopherol'],
            ['Use as the last step of your morning routine, after moisturiser.',
                'Spread an even layer over the face and neck and let it settle before make-up.',
                'Reapply through the day when you are outdoors.'],
        ],
        'toner' => [
            'a watery toner',
            'It goes on straight after cleansing to settle the skin and leave it damp for whatever follows.',
            ['Aqua', 'Houttuynia Cordata Extract', 'Butylene Glycol', 'Glycerin', 'Panthenol', 'Sodium Hyaluronate', 'Allantoin', 'Betaine'],
            ['Use after cleansing, before serums and creams.',
                'Pat a little over the face with your palms, or press it in on a cotton pad.',
                'Move on to the next step while the skin is still damp.'],
        ],
        'serum' => [
            'a lightweight serum',
            'It is the step between the toner and the cream, and is meant to be worn under both morning and night.',
            ['Aqua', 'Propanediol', 'Niacinamide', 'Glycerin', 'Sodium Hyaluronate', 'Panthenol', 'Adenosine', 'Centella Asiatica Extract'],
            ['Use after toner and before moisturiser.',
                'Warm a few drops in the palm and press them over the face.',
                'Follow with a cream to seal it in.'],
        ],
        'essence' => [
            'a hydrating essence',
            'A thin, slightly slippery layer worn between the toner and the serum, for skin that reads tight after cleansing.',
            ['Aqua', 'Snail Secretion Filtrate', 'Butylene Glycol', 'Sodium Hyaluronate', 'Panthenol', 'Allantoin', 'Betaine', 'Arginine'],
            ['Use after toner, before serum.',
                'Pat a thin layer over the whole face.',
                'Let it sink in before the next step.'],
        ],
        'cleanser' => [
            'a daily cleanser',
            'It is meant for the first or second cleanse of the day and rinses away without leaving the skin squeaking.',
            ['Aqua', 'Glycerin', 'Cocamidopropyl Betaine', 'Sodium Cocoyl Isethionate', 'Butylene Glycol', 'Panthenol', 'Camellia Sinensis Leaf Extract'],
            ['Wet the face with lukewarm water.',
                'Work a small amount into a lather and massage it over the skin.',
                'Rinse well and pat dry before the next step.'],
        ],
        'mask' => [
            'a leave-on mask',
            'A thicker layer worn at the end of an evening routine, for the nights when the rest of the routine is not quite enough.',
            ['Aqua', 'Glycerin', 'Butylene Glycol', 'Hydrolyzed Collagen', 'Sodium Hyaluronate', 'Cetearyl Alcohol', 'Panthenol', 'Allantoin'],
            ['Use at the end of an evening routine, after serum.',
                'Spread a generous layer over the face and leave it on overnight.',
                'Rinse in the morning and carry on as usual.'],
        ],
        'moisturiser' => [
            'a daily moisturiser',
            'It is the step that closes a routine, and is meant to be worn over whatever serum came before it.',
            ['Aqua', 'Glycerin', 'Caprylic/Capric Triglyceride', 'Cetearyl Alcohol', 'Ceramide NP', 'Butylene Glycol', 'Panthenol', 'Squalane'],
            ['Use as the last step at night, or before sunscreen in the morning.',
                'Warm a small amount between the fingers and press it over the face and neck.',
                'Add a second layer anywhere that still feels tight.'],
        ],
        'device' => [
            'a handheld device',
            'It is used over a routine rather than instead of one, on clean skin and for a few minutes at a time.',
            ['Charging cradle', 'USB-C cable', 'Conductive gel sachet', 'Quick start card'],
            ['Cleanse and dry the face before you start.',
                'Apply the conductive gel and work over one area at a time.',
                'Wipe the head clean afterwards and let it charge.'],
        ],
        'other' => [
            'a daily skincare step',
            'It is meant to sit inside an ordinary routine rather than replace one, morning or night.',
            ['Aqua', 'Glycerin', 'Butylene Glycol', 'Panthenol', 'Sodium Hyaluronate', 'Allantoin', 'Tocopherol'],
            ['Use on clean skin, in the order your routine already follows.',
                'Apply an even layer over the face, avoiding the eye area.',
                'Follow with the next step in your routine.'],
        ],
    ];

    /**
     * The family a demo product's name puts it in.
     *
     * ORDER IS THE WHOLE OF THIS FUNCTION, and two of the seeder's own 24 names
     * are why. Each carries two family words and the FIRST test wins:
     *
     *   "Birch Juice Moisturizing Sunscreen"   sunscreen, not moisturiser —
     *                                          `sunscreen` is tested first.
     *   "Ginseng Essence Water"                toner, not essence — `toner`
     *                                          claims "essence water" before
     *                                          `essence` sees the word.
     *
     * Reorder either pair and the product changes shelf with no error anywhere.
     * DemoProductTabsTest pins both and its mutation note names the swap. The
     * space-padded ' sun ' needle is the third ordering decision: it is there
     * so "Hyaluronic Acid Watery Sun Gel" is a sunscreen without "Ginseng"
     * matching on its middle syllable.
     */
    public static function family(string $name): string
    {
        $n = mb_strtolower($name);

        foreach ([
            'device' => ['device', 'booster pro'],
            'sunscreen' => ['spf', 'sunscreen', ' sun '],
            'mask' => ['mask'],
            'cleanser' => ['cleanser', 'cleansing', 'foaming'],
            'toner' => ['toner', 'essence water'],
            'essence' => ['essence'],
            'serum' => ['serum', 'ampoule'],
            'moisturiser' => ['cream', 'moistur', 'lotion'],
        ] as $family => $needles) {
            foreach ($needles as $needle) {
                // The space-padded needle is matched against a padded name so
                // " sun " can find a word at either end of the string.
                if (str_contains(' '.$n.' ', $needle)) {
                    return $family;
                }
            }
        }

        return 'other';
    }

    /**
     * The three bodies for one demo product, as HTML.
     *
     * @return array{description: string, ingredients: string, how_to_use: string}
     */
    public static function for(string $name): array
    {
        [$what, $why, $inci, $steps] = self::FAMILIES[self::family($name)];

        $safe = e($name);

        return [
            'description' => '<p><b>'.$safe.'</b> is '.$what.', shown here so the product page has something to lay out. '
                .$why.'</p>'
                .'<p>Everything on this page — the gallery, the buy column, the trust lines and these tabs — is drawn from '
                .'the real templates, so what you see is what a real product will look like once the catalogue is imported. '
                .self::DISCLOSURE.'</p>',
            'ingredients' => '<p>A sample list, in the order an ingredients panel is normally written.</p><ul>'
                .implode('', array_map(static fn (string $i): string => '<li>'.e($i).'</li>', $inci))
                .'</ul>',
            'how_to_use' => '<ul>'
                .implode('', array_map(static fn (string $s): string => '<li>'.e($s).'</li>', $steps))
                .'</ul>',
        ];
    }
}
