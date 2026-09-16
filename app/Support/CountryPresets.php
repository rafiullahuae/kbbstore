<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One-click rows for a per-country table in the admin console.
 *
 * WHAT THIS IS FOR, in the owner's words: "i want you to make the deliver lines
 * automatic same in tax. like user just just click to select." Two screens in
 * this console ask him to fill in a table with one row per country — the
 * delivery line each country reads, and the VAT rate each country is shown —
 * and on both of them the countries that matter are the same six. Typing six
 * sentences by hand, twice, is the work he asked to be rid of.
 *
 * SO THE MECHANISM IS THE REUSABLE PART, NOT THE WORDING. A group here is a
 * named set of suggested values for a named set of countries, and the console
 * renders any group with the same chips, the same "apply all" button and the
 * same promise: a chip FILLS THE FORM and saves nothing. The delivery group is
 * the only one registered today. A tax lane adds a 'tax' group beside it —
 * same shape, its own values — and calls the same console helper; it does not
 * need a line of new UI and it must not reach into the delivery group.
 *
 * NOTHING HERE IS A MEASUREMENT AND NOTHING HERE IS SAVED.
 *
 * DeliveryLine's own doc comment records why this file could not have existed
 * a month ago: no delivery window outside the UAE had ever been measured, so
 * the screen shipped empty on purpose and a plausible-looking figure typed in
 * to fill the gap would have been a promise the shop then had to keep. What
 * changed is not that somebody measured one. It is that the OWNER supplied the
 * figures himself — one to three days for the UAE, three to five for the rest
 * of the Gulf including Saudi Arabia — and they are therefore his to state and
 * this file's job to reproduce faithfully rather than to improve on. He gave
 * the rest of the Gulf and Saudi Arabia the same window; no distinction is
 * invented between them here because he drew none.
 *
 * And none of it reaches a shopper by being in this file. A preset is offered,
 * expanded into the form where he can read the exact words, and written to the
 * database only when he presses Save — which is also why applying the package
 * that carries this class changes nothing any customer is currently told.
 *
 * THE COUNTRY SET IS NOT RESTATED. A group names a region key and the codes
 * come from Countries::REGIONS, which is the same table the delivery picker and
 * the Extended tab are built from. 'GCC' there is AE, SA, KW, QA, BH and OM. A
 * seventh Gulf country added to that list one day appears in the chips without
 * anybody remembering this file exists.
 */
final class CountryPresets
{
    /** The group the Delivery lines screen offers. */
    public const DELIVERY = 'delivery';

    /** The group the Tax tab offers: a VAT rate per country. */
    public const TAX = 'tax';

    /**
     * The registered groups.
     *
     * - region      a key of Countries::REGIONS; the codes are read from there.
     * - template    the sentence used for every country in the region, with
     *               CountryTemplate::PLACEHOLDER standing in for the name.
     * - per_country a country whose suggestion is not the shared template.
     *               The UAE is one: the owner wrote its line naming the country
     *               in its short form, so its template carries no placeholder
     *               and comes back unchanged for every caller.
     * - label/note  what the console prints above the chips.
     *
     * @var array<string, array{label:string, region:string, template:string, per_country:array<string,string>, note:string}>
     */
    private const GROUPS = [
        self::DELIVERY => [
            'label' => 'Fill in the Gulf in one click',
            'region' => 'GCC',
            'template' => '3–5 days delivery all over {country}',
            'per_country' => [
                'AE' => '1–3 days delivery all over UAE',
            ],
            'note' => 'These are your own delivery times, not measured ones. Clicking fills the boxes below; nothing reaches the shop until you press Save changes.',
        ],

        /*
         * VAT RATES, AND THEY ARE NOT IN THE SAME CATEGORY AS THE LINES ABOVE.
         *
         * A delivery time is the owner's to state: he knows how long his
         * parcels take, so the delivery group reproduces his own figures
         * faithfully and there is nothing for anyone to check. A tax rate is a
         * FACT ABOUT THE WORLD that he is answerable for, it changes, this shop
         * is not a tax authority, and a wrong percentage prints on a receipt
         * somebody files. He wrote "Saudi there's 15% i think" — which is
         * exactly the confidence these figures deserve.
         *
         * So the mechanism is shared and the TONE is not. The note below says
         * in the owner's own reading order what these are, who has to check
         * them, and that clicking a chip changes nothing until he saves. The
         * chips fill the boxes; the boxes are what he reads; Save is what
         * publishes.
         *
         * THE SOURCE: the standard rates commonly quoted for the Gulf as at
         * September 2026 — UAE 5%, Saudi Arabia 15%, Bahrain 10%, Oman 5%, with
         * Kuwait and Qatar having implemented no domestic VAT, which is why the
         * shared template is '0' and only the four with a rate override it. A
         * zero rate prints no line at all, so offering those two costs nothing
         * and stops the owner wondering whether they were forgotten. The
         * citation lives in the lane's report rather than as a URL here, which
         * would rot without anyone noticing.
         *
         * A RATE IS ONLY HALF A ROW. The other half is the BASIS — inclusive,
         * exclusive or printed-only — and no preset here sets it: the console
         * lands every filled row on the shop's own default basis. A preset
         * knows a rate; it cannot know how this business prices, and a preset
         * that could turn a country exclusive would be a one-click change to
         * what customers are charged.
         */
        self::TAX => [
            'label' => 'Fill in the Gulf in one click',
            'region' => 'GCC',
            'template' => '0',
            'per_country' => [
                'AE' => '5',
                'SA' => '15',
                'BH' => '10',
                'OM' => '5',
            ],
            'note' => 'These are the rates commonly quoted for the Gulf, and they are not tax advice. Confirm them against your own registration before you save, because they print on your receipts and it is your business they describe. Clicking fills the boxes below on your default basis; nothing reaches the shop until you press Save changes.',
        ],
    ];

    /** Is this a group the console can render? */
    public static function has(string $group): bool
    {
        return array_key_exists($group, self::GROUPS);
    }

    /**
     * The countries a group covers, in the order Countries::REGIONS lists them
     * — which puts the shop's own country first rather than alphabetising it
     * into the middle.
     *
     * @return array<int, string>
     */
    public static function codes(string $group): array
    {
        if (! self::has($group)) {
            return [];
        }

        $region = self::GROUPS[$group]['region'];

        return array_values(array_filter(
            Countries::REGIONS[$region] ?? [],
            static fn (string $code): bool => isset(Countries::NAMES[$code]),
        ));
    }

    /**
     * The sentence a group suggests for a country, placeholder and all.
     *
     * This is the value the console writes into the row when the owner has
     * asked for the country name to stay automatic: the shape, not the result.
     */
    public static function template(string $group, string $code): string
    {
        if (! self::has($group)) {
            return '';
        }

        $code = strtoupper(trim($code));

        return (string) (self::GROUPS[$group]['per_country'][$code] ?? self::GROUPS[$group]['template']);
    }

    /**
     * The sentence with the country's name already in it — the literal words
     * that will print, which is what a chip shows and what it fills in.
     */
    public static function value(string $group, string $code): string
    {
        return CountryTemplate::fill(self::template($group, $code), $code);
    }

    /**
     * Everything the console needs to draw one group, and nothing it would
     * have to restate.
     *
     * Emitted into the page rather than fetched, for the reason the Delivery
     * lines block already gives about the country list: the endpoint that
     * would serve it deliberately omits the zone countries, and the zone
     * countries are exactly the six this group is about.
     *
     * @return array{key:string, label:string, note:string, template:string, rows:array<int, array{code:string, name:string, template:string, value:string, dynamic:bool}>}
     */
    public static function forConsole(string $group): array
    {
        if (! self::has($group)) {
            return ['key' => $group, 'label' => '', 'note' => '', 'template' => '', 'rows' => []];
        }

        $rows = [];

        foreach (self::codes($group) as $code) {
            $template = self::template($group, $code);

            $rows[] = [
                'code' => $code,
                'name' => CountryTemplate::name($code),
                'template' => $template,
                'value' => CountryTemplate::fill($template, $code),
                'dynamic' => CountryTemplate::isDynamic($template),
            ];
        }

        return [
            'key' => $group,
            'label' => (string) self::GROUPS[$group]['label'],
            'note' => (string) self::GROUPS[$group]['note'],
            'template' => (string) self::GROUPS[$group]['template'],
            'rows' => $rows,
        ];
    }
}
