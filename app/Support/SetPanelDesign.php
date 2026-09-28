<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * WHICH DRAWING OF A SET'S CONTENTS THE PRODUCT PAGE USES. (Lane SF)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * FOUR DESIGNS, ONE SETTING, AND THE SETTING SHIPS AT THE PAGE AS IT IS TODAY.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner: "there should be list of products which are inside the set,
 * present it beautifully. better to preview me the set product front-end
 * preview. so i can choose from."
 *
 * So the choice is his, made on a screen, without a release — and until he
 * makes it the page renders exactly what it rendered before this class
 * existed. `DEFAULT` is GRID, which IS the panel this repository already
 * shipped: `repeat(auto-fill, minmax(min(100%,150px), 1fr))`, two columns on a
 * phone and seven or eight on a desktop. Applying the package moves nothing.
 *
 * ── WHY A SETTING HERE AND A CONSTANT IN App\Support\SetDesign ──────────────
 *
 * SetDesign — the BASKET row's four drawings — argues at length that a setting
 * would be "a fifth thing the owner has to find and set ... to choose between
 * four options only one of which will ever be wanted once he has chosen". That
 * argument is right for the basket row and wrong here, for one reason: he had
 * already chosen the fanned stack, in as many words, before that class was
 * written. He has NOT chosen this one, and asked in this brief to be shown the
 * options and to pick from pictures. A constant would mean a release per
 * opinion, on a shop whose releases are hand-applied zips.
 *
 * ── A SELECT STORES ONE OF ITS OWN OPTIONS OR THE DEFAULT ──────────────────
 *
 * CLAUDE.md rule 5, and it is enforced TWICE on purpose:
 *
 *   - on the way IN, by SetContentsApiController, which refuses a key that is
 *     not in DESIGNS with a 422 rather than storing it;
 *   - on the way OUT, by current() below, which answers DEFAULT for anything
 *     that is not a key of DESIGNS.
 *
 * The second is the load-bearing one. `settings` is a table, and a row in it
 * can arrive from an import, a restored backup, a future screen or a hand-run
 * UPDATE. current() is what the Blade switches on, so a junk row must draw the
 * default panel and never a blank page — and `@include('partials.set-contents.'
 * . $design)` with an unvalidated $design is a view-name injection, which is
 * why the template switches on a fixed list of four @case branches rather than
 * interpolating whatever this returns.
 *
 * ── THE KEYS ARE ALSO VIEW NAMES, AND THAT IS DELIBERATE ───────────────────
 *
 * resources/views/partials/set-contents/{key}.blade.php. One word, lowercase,
 * no punctuation — SetContentsDesignTest asserts every key has a partial and
 * every partial has a key, so a design added to one and not the other fails by
 * name rather than by rendering nothing.
 */
final class SetPanelDesign
{
    /** The compact card grid the set page has drawn since Lane SP. */
    public const GRID = 'grid';

    /** One row per member: thumbnail, brand, name, quantity, price. */
    public const LIST = 'list';

    /** A large card per member, one column on a phone. */
    public const CARDS = 'cards';

    /** The FBT-style equation: member + member + member = the set's price. */
    public const STACK = 'stack';

    /**
     * What the shop renders when nobody has chosen.
     *
     * ▲ THIS IS THE PANEL THE PAGE ALREADY DRAWS. Changing it is a visible
     *   change to every set page on the shop, and CLAUDE.md rule 1 says a new
     *   setting ships at the value the page already has. If the owner picks
     *   another design, he picks it on the screen — this constant does not
     *   move.
     */
    public const DEFAULT = self::GRID;

    /** The settings row. */
    public const KEY = 'set_panel_design';

    /**
     * key => [label, one-sentence description for the admin screen]
     *
     * The order is the order the screen offers them in, and it is by how far
     * each departs from what is on the shop today: the one that ships first,
     * then the safe list, then the catalogue cards, then the equation.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const DESIGNS = [
        self::GRID => [
            'Compact grid',
            'What the page draws today. A small square photograph per product, as many across as fit — two on a phone, seven or eight on a desktop. The most products in the least height.',
        ],
        self::LIST => [
            'List',
            'One row per product: a thumbnail, the brand, the name, how many are in the box and what that product costs on its own. The densest to read and the easiest to scan when the box holds ten or twelve things.',
        ],
        self::CARDS => [
            'Cards',
            'A large card per product with a photograph worth looking at, one column on a phone and three or four on a desktop. The shoppable answer: it reads like the shop\'s own product grid, so each member looks like something to click.',
        ],
        self::STACK => [
            'The stack',
            'The products as a sum — one plus the next plus the next, then the set\'s own price and what it saves. It is the grammar Frequently Bought Together already uses further down the same page, and it makes the reason to buy the box the thing the shopper reads first.',
        ],
    ];

    /**
     * The live design.
     *
     * NO DATABASE READ OF ITS OWN WORTH COUNTING: SettingsService::get() reads
     * the whole settings table once per request and answers from a static memo
     * after that, which is the same read the fifty other settings on a product
     * page go through. This adds no query to StorefrontQueryBudgetTest's
     * ceiling and no query per member to anything.
     */
    public static function current(?SettingsService $settings = null): string
    {
        $raw = ($settings ?? app(SettingsService::class))->get(self::KEY, self::DEFAULT);

        return self::valid($raw) ? (string) $raw : self::DEFAULT;
    }

    /** Is this one of the four? Anything else — null, an array, junk — is not. */
    public static function valid(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::DESIGNS);
    }

    /**
     * The four, as the admin screen draws them.
     *
     * @return list<array{key: string, label: string, description: string, current: bool}>
     */
    public static function options(?SettingsService $settings = null): array
    {
        $current = self::current($settings);
        $out = [];

        foreach (self::DESIGNS as $key => [$label, $description]) {
            $out[] = [
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'current' => $key === $current,
            ];
        }

        return $out;
    }
}
