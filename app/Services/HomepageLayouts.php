<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Homepage layouts — presets that set section order, visibility and grid skins
 * in one move.
 *
 * A layout is not a separate template. It writes into the same section
 * configuration the Homepage screen edits, so anything a preset does can be
 * adjusted afterwards, and nothing is locked away in code.
 */
class HomepageLayouts
{
    /**
     * key => [name, description, who it suits, [section => [order, desktop, mobile, skin]]]
     *
     * Sections omitted from a layout keep their registry defaults, so adding a
     * new section later does not silently disappear from every preset.
     */
    public const LAYOUTS = [
        /*
         * ── SIGNATURE IS THE OWNER'S HOMEPAGE NOW — Lane PF ─────────────────
         *
         * It read "The full store. Every section on, in the order the site
         * uses today" with an empty `off`, and that was true until row 55. On
         * 3 October the owner replaced the page — "don't include anything from
         * our existing homepage on extreabeauty, except banner" — and
         * HomepageSections::OFF_BY_DEFAULT switched the old sections off. This
         * preset kept switching them ALL back on: it is the default `current()`
         * and the first card on Appearance → Homepage → Layouts, so one press
         * of "Re-apply" on it would have put the routine builder, the quiz,
         * the old rails, the ticker, flash sale, reviews, trust and newsletter
         * back on his page and undone the homepage he had just approved.
         *
         * So Signature means the page he has: the picture banner and the nine
         * sections, everything else off. `off` IS OFF_BY_DEFAULT — the same
         * constant the shipped defaults and the row-55 migration use — so the
         * two cannot drift: a section he later switches off by default is off
         * in Signature too. `sections` stays array_keys(REGISTRY), so
         * orderIsDefault() still holds and "put it back" still puts back the
         * shipped ORDER as well as the shipped switches. The hero stays on for
         * the reason OFF_BY_DEFAULT gives: it is the banner's fallback and
         * draws only when the picture banner has nothing to show.
         */
        'signature' => [
            'name' => 'Signature',
            'blurb' => 'Your homepage: the picture banner, then Big savings bundles, Best Sellers, Brands, #KBeautyBliss Spotted, Trending, the Blog, Under AED 54, the two-column feature and About us. Every other section off.',
            'suits' => 'The page as approved on 3 October 2026 (master plan row 55). Re-applying it puts that page back.',
            'sections' => [
                /*
                 * `cards_banner` sits where the REGISTRY puts it and where
                 * store/home.blade.php draws it — Lane BN. Signature's whole
                 * claim is "every section on, in the order the site uses
                 * today", and HomepageSections::orderIsDefault() compares the
                 * applied key sequence against array_keys(REGISTRY): a key
                 * missing here, or placed anywhere else, makes applying
                 * Signature produce a page that is NOT the shipped order, which
                 * is what HomepageSectionOrderTest's "put it back" case checks.
                 *
                 * ▲ IT MOVED TO THE FRONT WITH THE REGISTRY.          (Lane SEC)
                 * The picture banner is the homepage's banner now and the hero
                 * yields to it — "the banner i need to change to simple image
                 * banners, not cards". Signature is the SHIPPED order, so this
                 * list is not a choice: it has to be array_keys(REGISTRY) or
                 * "put it back" puts back something else. The four other
                 * presets are orders the owner picks and are left as he picks
                 * them.
                 */
                // Lane HC: the two phone-only strips, at their REGISTRY positions.
                'topstrip', 'cards_banner', 'countries', 'hero', 'delivery', 'ticker', 'categories', 'bundles', 'bestselling', 'recommended',
                'routine', 'quiz', 'brands', 'spotted', 'videos', 'igembeds', 'trending', 'bestsellers', 'flash',
                'blog', 'under54', 'feature', 'about', 'reviews', 'trust', 'newsletter',
                // Row 55 (Lane HA): the four new sections, at their REGISTRY
                // positions, for the reason above.
            ],
            'skins' => ['bundles' => 'classic', 'recommended' => 'soft', 'bestsellers' => 'luxe', 'flash' => 'ribbon'],
            'off' => HomepageSections::OFF_BY_DEFAULT,
        ],
        /*
         * ── THE OTHER THREE KEEP THEIR CHARACTER, AND STOP CONTRADICTING ROW 55
         *                                                          (Lane PF)
         * They are alternative pages and bringing back sections is what they
         * are for, so their orders and their own `off` choices are kept. Three
         * things in each of them disagreed with what the owner decided on 3
         * October, and only those moved:
         *
         *   1. `routine` and `quiz` are OFF in all three. "don't include
         *      reoutine builder etc, that's not finished yet" — a preset must
         *      not put an unfinished section in front of shoppers. Still listed,
         *      at their old positions, so switching one on later lands it where
         *      the preset always put it.
         *   2. ONE best-sellers rail. The new `bestselling` takes the old
         *      `bestsellers` rail's place in the order and the old rail is off
         *      (listed last). Before, every preset drew both — two "best
         *      sellers" sections on one page, against "i dont want to repeat
         *      anything".
         *   3. The four row-55 sections the presets never mentioned —
         *      `bestselling`, `trending`, `under54`, `feature` — are PLACED
         *      rather than falling through payloadFor()'s "anything not
         *      mentioned, placed last" loop, which put them after the
         *      newsletter. Trending follows Best Sellers, Under AED 54 follows
         *      the flash sale (both are price-led), and the feature sits
         *      before About us.
         *
         * Boutique turns Under AED 54 off with the flash sale, for the reason
         * it gives for that one: fewer, calmer sections, no price-led rails.
         */
        'conversion' => [
            'name' => 'Conversion',
            'blurb' => 'Offers first. Flash sale, Under AED 54 and bundles above the fold, editorial pushed down.',
            'suits' => 'Sale periods and paid traffic, where the visit has one job.',
            'sections' => [
                'hero', 'ticker', 'delivery', 'cards_banner', 'flash', 'under54', 'bundles', 'categories',
                'bestselling', 'trending', 'recommended', 'quiz', 'reviews', 'trust',
                'brands', 'routine', 'spotted', 'videos', 'igembeds', 'newsletter', 'feature', 'about', 'blog',
                'bestsellers',
            ],
            'skins' => ['flash' => 'ribbon', 'bundles' => 'pricetag', 'bestsellers' => 'bold', 'recommended' => 'actions'],
            'off' => ['blog', 'routine', 'quiz', 'bestsellers'],
        ],
        'editorial' => [
            'name' => 'Editorial',
            'blurb' => 'Content leads. The journal and the brands early; products follow the story.',
            'suits' => 'Building trust with visitors who are researching rather than buying today.',
            'sections' => [
                'hero', 'delivery', 'cards_banner', 'routine', 'quiz', 'categories', 'bestselling', 'trending',
                'blog', 'brands', 'bundles', 'reviews', 'spotted', 'videos', 'igembeds', 'feature', 'about',
                'recommended', 'flash', 'under54', 'trust', 'newsletter', 'ticker',
                'bestsellers',
            ],
            'skins' => ['bundles' => 'editorial', 'recommended' => 'magazine', 'bestsellers' => 'minimal', 'flash' => 'outline'],
            'off' => ['ticker', 'routine', 'quiz', 'bestsellers'],
        ],
        'boutique' => [
            'name' => 'Boutique',
            'blurb' => 'Fewer, calmer sections. Generous spacing, no ticker, no flash sale.',
            'suits' => 'A curated range where restraint reads as quality.',
            'sections' => [
                'hero', 'categories', 'bestselling', 'trending', 'routine', 'brands',
                'reviews', 'feature', 'about', 'trust', 'newsletter',
                'delivery', 'bundles', 'recommended', 'quiz', 'spotted', 'videos', 'igembeds',
                'flash', 'under54', 'blog', 'ticker', 'cards_banner',
                'bestsellers',
            ],
            'skins' => ['bestsellers' => 'luxe', 'bundles' => 'frame', 'recommended' => 'soft', 'flash' => 'minimal'],
            /*
             * `videos` and `igembeds` are LISTED AND OFF here (Lane IG; `instagram`
             * was retired by Lane IGR and its place is `igembeds`'), where the
             * other three presets have them on. That is this preset's own argument
             * applied rather than an omission: "Fewer, calmer sections... restraint
             * reads as quality", and a scrolling video rail plus a nine-tile
             * Instagram grid are the two busiest bands on offer. `spotted` is off
             * for exactly the same reason and has been since this preset shipped.
             *
             * They are still in `sections` rather than left out of it, because
             * payloadFor() skips a key it does not find and a section missing from a
             * preset is a section whose ORDER that preset does not decide —
             * settle() would then place it from the registry index and the preview
             * would draw a row the apply does not produce, which is the fault
             * HomepageSections::settleKeys() exists to end.
             */
            /*
             * `cards_banner` joins that list (Lane BN) for the same argument in
             * the same words: a row of cards that scrolls itself is one of the
             * busiest bands on offer, and this preset's case is "fewer, calmer
             * sections ... restraint reads as quality". It is LISTED in
             * `sections` above and switched off here rather than left out, for
             * the reason the note above gives — a section missing from a preset
             * is a section whose order that preset does not decide.
             */
            'off' => ['ticker', 'flash', 'spotted', 'videos', 'igembeds', 'bundles', 'recommended', 'cards_banner',
                // Lane PF: see the note above Conversion.
                'routine', 'quiz', 'bestsellers', 'under54'],
        ],
    ];

    public function __construct(private SettingsService $settings) {}

    /** Which layout was applied last, for highlighting in the admin. */
    public function current(): string
    {
        $key = (string) $this->settings->get('homepage_layout', 'signature');

        return isset(self::LAYOUTS[$key]) ? $key : 'signature';
    }

    public function exists(string $key): bool
    {
        return isset(self::LAYOUTS[$key]);
    }

    /** @return array<string,array> the section payload this layout implies */
    public function payloadFor(string $key): array
    {
        $layout = self::LAYOUTS[$key] ?? self::LAYOUTS['signature'];
        $out = [];
        $order = 0;

        /*
         * Lane HC: the two strips belong at the TOP of every preset — the top
         * strip under the header, the countries strip under the banner — and
         * phones only. Inserted here rather than into four lists, so a preset
         * written tomorrow gets them too; a preset that names one keeps its own
         * position for it.
         */
        $list = $layout['sections'];

        if (! in_array('countries', $list, true)) {
            $at = array_search('cards_banner', $list, true);
            array_splice($list, $at === false ? 0 : $at + 1, 0, ['countries']);
        }

        if (! in_array('topstrip', $list, true)) {
            array_unshift($list, 'topstrip');
        }

        foreach ($list as $section) {
            if (! isset(HomepageSections::registry()[$section])) {
                continue;
            }

            $on = ! in_array($section, $layout['off'], true);
            $hasGrid = HomepageSections::registry()[$section][2];
            $phoneOnly = in_array($section, HomepageSections::MOBILE_ONLY_BY_DEFAULT, true);

            $out[$section] = [
                'desktop' => $on && ! $phoneOnly,
                'mobile' => $on,
                'order' => $order++,
                'skin' => $hasGrid
                    ? ($layout['skins'][$section] ?? HomepageSections::registry()[$section][3])
                    : null,
            ];
        }

        /*
         * Anything the preset did not mention keeps its default, placed last.
         *
         * `registry()` AND NOT THE CONST — Lane GS, and it is one token with a
         * real consequence behind it. The owner's reusable product grid puts one
         * row per built instance into HomepageSections::registry(); read against
         * the const, an instance is in NEITHER loop, so applying a layout preset
         * writes a payload that does not mention it and save() drops its stored
         * row. all() then re-merges it from the registry with its switches back
         * ON and its saved order gone — a section that moved and switched itself
         * on because the owner pressed an unrelated button, with nothing said.
         *
         * Named here it is treated exactly as a shipped section the preset does
         * not mention — kept, defaulted, placed last — which is the behaviour
         * this loop already promises for `cards_banner` and the rest.
         *
         * Pinned by GridSectionHomepagePresetTest.
         */
        foreach (HomepageSections::registry() as $section => $meta) {
            if (! isset($out[$section])) {
                $out[$section] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => $meta[3]];
            }
        }

        return $out;
    }

    /**
     * Apply a layout by writing its payload into the section configuration.
     *
     * ── THE FRAME SETTINGS ARE CARRIED ACROSS, AND A PRESET DOES NOT TOUCH
     *    THEM ───────────────────────────────────────────────────── Lane BG ──
     *
     * A preset is described on the screen as "order, visibility and grid
     * styles in one move", and that sentence is the contract. `background` and
     * `width` are neither: they are the panel behind a section and how wide it
     * runs, and none of the four presets has an opinion about either.
     *
     * save() REPLACES the stored payload, so without this the two would fall
     * back to their defaults on every Apply — a section the owner had given its
     * panel back would lose it, silently, because he pressed a button about
     * something else. That is the same "a control moved and nothing said so"
     * shape the comment inside payloadFor() describes one method up, and it is
     * why the values are read off the CURRENT configuration rather than being
     * left to the default.
     *
     * Read through all(), so the values are already cast: a stored token that
     * is not a key of BACKGROUNDS / WIDTHS has been replaced by the default
     * before it gets here, and a nested row's null stays null.
     */
    public function apply(string $key, HomepageSections $sections): void
    {
        $payload = $this->payloadFor($key);
        $current = $sections->all();

        foreach ($payload as $section => $row) {
            $payload[$section]['background'] = $current[$section]['background'] ?? null;
            $payload[$section]['width'] = $current[$section]['width'] ?? null;
        }

        $sections->save($payload);
        $this->settings->set('homepage_layout', $key);
    }

    /**
     * A compact description for the admin, including a section preview list.
     *
     * ── THE PREVIEW DRAWS WHAT APPLYING THE PRESET PRODUCES — Lane FW ────────
     *
     * `order` feeds the wire-frame the Layouts cards show, and it used to be
     * read straight off `$layout['sections']`. That list is what the preset
     * ASKS for, and two of the four ask for something the hero's markup cannot
     * do: Conversion orders `hero, ticker, delivery` and Boutique puts the
     * delivery strip tenth, while both of those rows are drawn inside the
     * hero's own `<section>` and render wherever it renders.
     * HomepageSections::all() settles them back behind their host on every
     * read, so the preset applied fine and the wire-frame beside it was drawing
     * a page that never existed — named, and left, in
     * docs/FR-HOMEPAGE-ORDER.md.
     *
     * So the sequence is now taken through payloadFor() and settleKeys(): the
     * same two functions that decide what applying the preset actually does.
     * The preview cannot disagree with the result any more, because it is
     * computed from it. The SET of visible sections is unchanged — `off` still
     * decides that — so `count` and `off` answer exactly what they did.
     */
    public function summaries(): array
    {
        $out = [];

        foreach (self::LAYOUTS as $key => $layout) {
            $payload = $this->payloadFor($key);

            $visible = array_values(array_filter(
                HomepageSections::settleKeys(array_keys($payload)),
                fn ($s) => $payload[$s]['desktop'] || $payload[$s]['mobile']
            ));

            $out[] = [
                'key' => $key,
                'name' => $layout['name'],
                'blurb' => $layout['blurb'],
                'suits' => $layout['suits'],
                'count' => count($visible),
                'order' => array_map(fn ($s) => HomepageSections::REGISTRY[$s][0], array_slice($visible, 0, 8)),
                'off' => array_map(
                    fn ($s) => HomepageSections::REGISTRY[$s][0] ?? $s,
                    array_values(array_filter($layout['off'], fn ($s) => isset(HomepageSections::REGISTRY[$s])))
                ),
            ];
        }

        return $out;
    }
}
