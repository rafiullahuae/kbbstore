<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * VAT: the rate for a destination, what it does to the total, and the line.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  D-64 IS OVERTURNED HERE. BY THE OWNER. ON 2026-09-16. READ THIS FIRST.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * This class used to open with: "VAT is a DISPLAY LINE ONLY (decision D-64).
 * It never alters a total, nothing is charged, and no tax is stored against an
 * order." That was true, it was written down, and the owner was told it twice.
 * He has now asked, in his own words, for the thing that is not that:
 *
 *   "Can we set the Tax rate accros each country? we need it. please make a
 *    reliable system for it. and create a seperate tab for 'Tax'"
 *
 *   "also i will need control to inclusive VAT or exclusive."
 *
 *   "across each country, for example for uae the vat i can set inclusive, for
 *    Saudi i can set exclusive and so on as per my requirements."
 *
 * EXCLUSIVE VAT HAS EXACTLY ONE MEANING: the tax is added on top of the price.
 * There is no way to offer an inclusive/exclusive switch and keep the line
 * display-only, because in inclusive mode the customer pays 100 and in
 * exclusive mode they pay 105. So D-64 is not being worked around, leaked past
 * or forgotten — it is being replaced, deliberately, by the person whose shop
 * it is. The next reader is asked not to "fix" this back to a display line.
 *
 * ── NOTHING CHANGES ON THE DAY THE PACKAGE IS APPLIED ───────────────────────
 *
 * One setting decides whether any of the above happens at all:
 *
 *   tax_mode = 'display'  (THE DEFAULT, and what every existing shop gets)
 *       Exactly the behaviour this class had before, to the fil. The line is
 *       printed, no total moves, `orders.tax_total` is written 0 and
 *       `orders.tax_rate` / `orders.tax_basis` are left NULL — so every reader
 *       downstream (invoice, receipt, Tabby, Tamara, refunds) takes the same
 *       branch it took yesterday.
 *
 *   tax_mode = 'live'
 *       The per-country rules below are applied and recorded. An `exclusive`
 *       country now charges more. An `inclusive` country charges exactly what
 *       it charged before and records the tax that was already inside it.
 *
 * The owner turns that switch on himself, in Store -> Ecommerce -> Tax, after
 * he has entered his rates. A package that silently started charging customers
 * more would be the worst available outcome, so it is a switch and not a
 * migration.
 *
 * ── WHAT IS TAXED ──────────────────────────────────────────────────────────
 *
 * The taxable base is SUBTOTAL - DISCOUNT + DELIVERY: exactly the figure this
 * class was already handed as `$totalFils` before any of this existed, so the
 * printed line does not move by a fil when the package lands. Tax is computed
 * after the coupon, because VAT is due on the consideration actually paid and
 * charging it on a discount nobody paid would be a real financial error.
 *
 * The COD surcharge and the gift-wrapping fee are OUTSIDE the base. That is
 * the pre-existing behaviour preserved, not a tax opinion — see the lane
 * report; whether those two are taxable supplies is the owner's question to
 * put to his accountant, and moving them in later is a one-line change here.
 *
 * ── PER COUNTRY: TWO SETTINGS, ONE TABLE ───────────────────────────────────
 *
 * `vat_country_rates`  {"SA":"15","AE":"5"}         — which countries differ
 * `vat_country_bases`  {"SA":"exclusive"}           — and what their rate does
 *
 * Two keys and not one because `vat_country_rates` already exists, is already
 * validated key-by-key by AdminController's `ratemap` rule, and is already
 * pinned by tests; a shop that has filled it in keeps every rate it entered.
 * THE RATE MAP IS THE AUTHORITY over which countries are listed. A basis for a
 * country with no rate of its own is ignored, because a country that is not in
 * the table is not in the table.
 *
 * `vat_rate` and `vat_basis` are the defaults for every country not listed —
 * the owner's "all countries at once", unchanged.
 *
 * ── THE COUNTRY IS ALWAYS AN ARGUMENT ──────────────────────────────────────
 *
 * Nothing here resolves a visitor's country. The checkout knows the
 * destination and passes it; the product page asks App\Support\ShopperCountry
 * and passes what it answers, which on a request carrying no geo signal at all
 * is the shop's own country and therefore the default rule — the behaviour this
 * paragraph used to describe as the product page's only option. A caller with
 * no country at all still passes null and still gets the default. The one
 * resolver for that question lives in ShopperCountry, and two would be two
 * answers to one question.
 *
 * ── DEFENSIVE ON THE WAY OUT ───────────────────────────────────────────────
 *
 * Both maps are checked when they are READ, not only when they are written.
 * The admin validator checks what it is given, but these rows can also arrive
 * from an older build, a hand-edited database or a half-applied package, and
 * what they feed is a percentage that is charged and printed on a receipt.
 * Anything that is not a country this shop knows paired with a percentage
 * between 0 and 100 is dropped, and an unrecognised basis falls back to
 * `inclusive` rather than `exclusive` — the reading that takes no extra money.
 */
final class VatDisplay
{
    /** The settings key holding the per-country rates, as a JSON object. */
    public const COUNTRY_RATES_KEY = 'vat_country_rates';

    /** The settings key holding the per-country bases, as a JSON object. */
    public const COUNTRY_BASES_KEY = 'vat_country_bases';

    /** The settings key deciding whether any of this moves money. */
    public const MODE_KEY = 'tax_mode';

    /** Printed and never charged — the shipped default, and today's behaviour. */
    public const MODE_DISPLAY = 'display';

    /** The rules are applied to orders and recorded against them. */
    public const MODE_LIVE = 'live';

    /** @var list<string> */
    public const MODES = [self::MODE_DISPLAY, self::MODE_LIVE];

    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('vat_enabled', true);
    }

    /**
     * 'display' or 'live'. Anything else reads as 'display'.
     *
     * Unknown values fail CLOSED on purpose: a column holding junk must not be
     * the reason a shopper is charged tax, so the answer to "I do not
     * understand this" is the mode that takes no money.
     */
    public function mode(): string
    {
        $mode = (string) $this->settings->get(self::MODE_KEY, self::MODE_DISPLAY);

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_DISPLAY;
    }

    /** Are the rules applied to orders, or only printed? */
    public function live(): bool
    {
        return $this->mode() === self::MODE_LIVE;
    }

    /**
     * The per-country rates, as ISO code => percentage.
     *
     * @return array<string, float>
     */
    public function countryRates(): array
    {
        $out = [];

        foreach ($this->decodeMap(self::COUNTRY_RATES_KEY) as $code => $rate) {
            $code = strtoupper(trim((string) $code));

            if (! isset(Countries::NAMES[$code]) || ! is_scalar($rate)) {
                continue;
            }

            $rate = trim((string) $rate);

            // The same shape the 'pct' settings rule accepts, so a value that
            // could not have been saved through the screen is not honoured
            // just because it reached the column some other way.
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $rate) !== 1 || (float) $rate > 100) {
                continue;
            }

            $out[$code] = (float) $rate;
        }

        return $out;
    }

    /**
     * The per-country bases, as ISO code => basis.
     *
     * Only countries that have a RATE of their own appear: the rate map is the
     * table, and this one is a column on it. A basis stranded without a rate
     * is dropped rather than quietly applied to the global rate, which would
     * be a row the owner cannot see on his own screen.
     *
     * @return array<string, string>
     */
    public function countryBases(): array
    {
        $rates = $this->countryRates();
        $out = [];

        foreach ($this->decodeMap(self::COUNTRY_BASES_KEY) as $code => $basis) {
            $code = strtoupper(trim((string) $code));

            if (! isset($rates[$code]) || ! is_string($basis)) {
                continue;
            }

            $basis = strtolower(trim($basis));

            if (in_array($basis, TaxRule::BASES, true)) {
                $out[$code] = $basis;
            }
        }

        return $out;
    }

    /** The rule for every country that has none of its own. */
    public function defaultRule(): TaxRule
    {
        return TaxRule::make(
            $this->settings->get('vat_rate', 5),
            $this->settings->get('vat_basis', TaxRule::INCLUSIVE),
        );
    }

    /**
     * The rule for a destination: its own row, or the default.
     *
     * With the line switched off the rule is a zero rate rather than "no
     * rule". That is what stops a shop charging tax it does not print: one
     * switch governs both halves, so an invisible surcharge is not reachable.
     */
    public function ruleFor(?string $country = null): TaxRule
    {
        if (! $this->enabled()) {
            return new TaxRule(0.0, TaxRule::INCLUSIVE);
        }

        $default = $this->defaultRule();

        if ($country === null) {
            return $default;
        }

        $code = strtoupper(trim($country));
        $rates = $this->countryRates();

        if (! array_key_exists($code, $rates)) {
            return $default;
        }

        return TaxRule::make($rates[$code], $this->countryBases()[$code] ?? $default->basis);
    }

    /**
     * The rate for a destination: its own override, or the global rate.
     *
     * The country defaults to null so every call site that predates
     * per-country rates keeps working untouched and keeps answering what it
     * answered before.
     */
    public function rate(?string $country = null): float
    {
        if ($country !== null) {
            $rates = $this->countryRates();
            $code = strtoupper(trim($country));

            if (array_key_exists($code, $rates)) {
                return $rates[$code];
            }
        }

        return (float) $this->settings->get('vat_rate', 5);
    }

    /**
     * The VAT portion, in fils — the figure the line PRINTS.
     *
     * This is not "what is charged". In exclusive mode the same number is also
     * added to the total, and quote() is the method that says so; here it is
     * only the figure. Every caller that existed before this lane wanted the
     * printed figure and still gets it.
     */
    public function amount(int $baseFils, ?string $country = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        return $this->ruleFor($country)->taxOn($baseFils);
    }

    /**
     * Everything a money path needs for one destination, decided once.
     *
     * $baseFils is the TAXABLE BASE — subtotal minus discount plus delivery.
     * See the class header for why the fees are outside it.
     *
     *   printed   the figure on the receipt line
     *   charged   what goes in `orders.tax_total`: the tax INSIDE `total`.
     *             Zero in display mode (the shop is not running a tax engine)
     *             and zero on a `flat` basis (nothing is contained, the figure
     *             is only printed).
     *   added     true only when the total is higher because of this rule
     *   total     what the customer pays for this base
     *
     * @return array{mode:string,rate:float,basis:string,printed:int,charged:int,added:bool,total:int,label:string}
     */
    public function quote(int $baseFils, ?string $country = null): array
    {
        $rule = $this->ruleFor($country);
        $printed = $rule->taxOn($baseFils);
        $live = $this->live();
        $added = $live && $rule->addsToTotal();

        return [
            'mode' => $this->mode(),
            'rate' => $rule->rate,
            'basis' => $rule->basis,
            'printed' => $printed,
            // `flat` is printed, never contained and never added, so there is
            // nothing inside the total for it to claim.
            'charged' => $live && $rule->basis !== TaxRule::FLAT ? $printed : 0,
            'added' => $added,
            'total' => $baseFils + ($added ? $printed : 0),
            'label' => $this->label($country),
        ];
    }

    /**
     * The tax sentence for a SHELF PRICE — the line under the price on the
     * product page — or null when there is no tax to speak of.
     *
     * ── WHAT WAS WRONG, AND WHY IT WAS INVISIBLE ────────────────────────────
     *
     * Store\ProductController::vatLine() built this sentence itself, and it
     * built it as "Inclusive of {rate}% VAT" — the word "Inclusive" hard-coded,
     * printed WHATEVER `vat_basis` said. It read `vat_rate` straight out of the
     * settings table, so it also ignored `vat_country_rates` entirely and told
     * a shopper in Riyadh the UAE's rate.
     *
     * Nobody noticed because the shipped basis is `inclusive` and the shipped
     * mode is `display`, so the assertion happened to be true of the only
     * configuration that existed. It stops being true the first time an owner
     * uses the feature he asked for by name — "for uae the vat i can set
     * inclusive, for Saudi i can set exclusive" — and it stops being true on
     * EVERY product page at once, silently, with no error anywhere.
     *
     * The product page's own structured data already refused to make that
     * claim: MachineFacingClaimsTest pins `valueAddedTaxIncluded` to false
     * under an exclusive rule and omits it when countries disagree. So the
     * machine-facing half of this page was honest about the basis while the
     * sentence a human reads asserted the opposite. One authority, one answer.
     *
     * ── THE THREE SENTENCES, AND WHY THE THIRD IS NOT "INCLUSIVE" ───────────
     *
     *   the total goes up        "+5% VAT added at checkout"
     *     (live mode, exclusive) The one case where the shelf price is not what
     *                            is paid. Said before the buy button, not after
     *                            it, which is the whole point of saying it.
     *
     *   inclusive                "Inclusive of 5% VAT"
     *                            The tax is inside the price. This is the
     *                            shipped configuration and the wording that
     *                            shipped, so a shop that applies the package
     *                            and changes nothing sees the same line it has
     *                            always seen, to the character.
     *
     *   anything else            "5% VAT shown at checkout"
     *     (flat, or exclusive    Nothing is added and nothing is contained: a
     *      while the mode is     figure is printed beside a total it is not
     *      still `display`)      part of. "Shown" is deliberately neither
     *                            "included" nor "added", because on this branch
     *                            the shop is making neither claim.
     *
     * NULL, NOT AN EMPTY STRING, when the line is switched off or the rate is
     * zero — the same distinction TrustClaims draws, and the reason the product
     * template's `@if ($vatLine)` renders no empty div.
     *
     * NOT OWNER-EDITABLE, ON PURPOSE. This is not a claim about the business;
     * it is a description of arithmetic the owner has already configured on
     * Store → Ecommerce → Tax, and a text box here would be a second place to
     * say what the basis is — free to drift from the basis that is actually
     * charged. `vat_label` remains the editable wording for the CHECKOUT line,
     * where the figure beside it comes from the same quote.
     */
    public function shelfNote(?string $country = null): ?string
    {
        // ruleFor() already answers a zero rate when the line is switched off,
        // so "disabled" and "0%" take the same branch rather than two.
        $rule = $this->ruleFor($country);

        if ($rule->bp <= 0) {
            return null;
        }

        $rate = $rule->printableRate();

        // live() is asked as well as addsToTotal(): in `display` mode an
        // exclusive rule charges nobody anything, and promising a surcharge
        // that never arrives is its own untruth.
        if ($this->live() && $rule->addsToTotal()) {
            return '+' . $rate . '% VAT added at checkout';
        }

        if ($rule->basis === TaxRule::INCLUSIVE) {
            return 'Inclusive of ' . $rate . '% VAT';
        }

        return $rate . '% VAT shown at checkout';
    }

    /**
     * e.g. "You're paying VAT (5%)" with {rate} substituted.
     *
     * THE LABEL CARRIES THE RATE, so it takes the country too. A caller that
     * asked amount() for one country and label() for another would print a
     * receipt that contradicts itself — the 15% figure under the words "You're
     * paying VAT (5%)".
     */
    public function label(?string $country = null): string
    {
        $label = (string) $this->settings->get('vat_label', "You're paying VAT ({rate}%)");

        return str_replace('{rate}', $this->printableRate($this->rate($country)), $label);
    }

    /** 5.00 -> "5", 7.50 -> "7.5", 15.00 -> "15". */
    private function printableRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    /**
     * Everything the checkout template needs, or null when the line is off.
     *
     * `added` is the one new key and it is what tells the summary whether to
     * draw this row ABOVE the Total, where it is part of the sum, or BELOW it
     * as an "of which" note. Printing an exclusive tax under the total would
     * leave a column of figures that does not add up to what is charged.
     */
    public function line(int $baseFils, ?string $country = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $quote = $this->quote($baseFils, $country);

        if ($quote['printed'] <= 0) {
            return null;
        }

        return [
            // One country for both halves, so the words and the figure can
            // never describe two different rates.
            'label' => $quote['label'],
            'amount' => $quote['printed'],
            'formatted' => Money::format($quote['printed']),
            'added' => $quote['added'],
            'basis' => $quote['basis'],
        ];
    }

    /**
     * A stored JSON object, as an array, or [].
     *
     * SettingsService::decode() already turns a stored JSON object into an
     * array, but a caller that bypassed it would hand over the string.
     *
     * @return array<array-key, mixed>
     */
    private function decodeMap(string $key): array
    {
        $raw = $this->settings->get($key, []);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }
}
