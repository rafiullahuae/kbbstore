<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * VAT is a DISPLAY LINE ONLY (decision D-64).
 *
 * It never alters a total, nothing is charged, and no tax is stored against an
 * order. The checkout simply tells the customer that VAT is already included in
 * what they are paying. A real tax engine comes later; this is deliberately not
 * one, and should not quietly grow into one.
 *
 * Two bases are supported so the figure can be changed without a code change:
 *
 *   inclusive (default)  total x rate / (100 + rate)   AED 100 -> 4.76
 *   flat                 total x rate / 100            AED 100 -> 5.00
 *
 * "inclusive" is correct when the price genuinely already contains the tax, and
 * it matches what the KBB VAT module renders today. "flat" is available because
 * some merchants prefer the rounder number on the label.
 *
 * THE RATE CAN VARY BY COUNTRY, AND STILL CHANGES NOTHING BUT THE PRINTING.
 *
 * `vat_rate` is the rate that applies when the destination has no rate of its
 * own — the "all countries at once" control, and the only one there was until
 * now. `vat_country_rates` holds per-country overrides and ships EMPTY, so a
 * shop that never opens that screen behaves exactly as it did before.
 *
 * Read the consequence before raising one. Because the default basis is
 * INCLUSIVE and the line is display-only, giving Saudi Arabia 15% does not
 * raise what a Saudi shopper pays: on an AED 100 order the printed line moves
 * from 4.76 to 13.04 and the shop still receives AED 100 — so the net take on
 * that sale FALLS. That is a business decision for the owner and it is his to
 * make. It is not a bug in this class, and it is not to be "fixed" by making
 * VAT chargeable: D-64 is a recorded decision and overturning it is not a code
 * change anyone makes on the way past.
 *
 * Every country-aware method takes the country as an argument and defaults it
 * to null. Nothing here resolves a visitor's country — the checkout knows the
 * destination and passes it; the product page does not and does not pretend to,
 * so it gets the global rate. One resolver for that question lives elsewhere
 * (App\Support\ShopperCountry); two would be two answers to one question.
 */
final class VatDisplay
{
    /** The settings key holding the per-country overrides, as a JSON object. */
    public const COUNTRY_RATES_KEY = 'vat_country_rates';

    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('vat_enabled', true);
    }

    /**
     * The per-country overrides, as ISO code => percentage.
     *
     * DEFENSIVE ON THE WAY OUT, not merely on the way in. The admin validator
     * checks what it is given, but this row can also arrive from an older
     * build, a hand-edited database or a half-applied package, and the value it
     * feeds is a percentage PRINTED ON A RECEIPT. Anything that is not a
     * country this shop knows, paired with a percentage between 0 and 100, is
     * dropped rather than shown — the global rate is a sane answer and a
     * nonsense figure on an invoice is not.
     *
     * @return array<string, float>
     */
    public function countryRates(): array
    {
        $raw = $this->settings->get(self::COUNTRY_RATES_KEY, []);

        // SettingsService::decode() already turns a stored JSON object into an
        // array, but a caller that bypassed it would hand over the string.
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $code => $rate) {
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
     * The rate for a destination: its own override, or the global rate.
     *
     * The country defaults to null so every call site that predates per-country
     * rates keeps working untouched and keeps answering what it answered
     * before.
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

    /** The VAT portion, in fils. Informational — never added to the total. */
    public function amount(int $totalFils, ?string $country = null): int
    {
        $rate = $this->rate($country);
        if (! $this->enabled() || $rate <= 0 || $totalFils <= 0) {
            return 0;
        }

        return $this->settings->get('vat_basis', 'inclusive') === 'flat'
            ? (int) round($totalFils * $rate / 100)
            : (int) round($totalFils * $rate / (100 + $rate));
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

    /** Everything the checkout template needs, or null when the line is off. */
    public function line(int $totalFils, ?string $country = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $amount = $this->amount($totalFils, $country);
        if ($amount <= 0) {
            return null;
        }

        return [
            // One country for both halves, so the words and the figure can
            // never describe two different rates.
            'label' => $this->label($country),
            'amount' => $amount,
            'formatted' => Money::format($amount),
        ];
    }
}
