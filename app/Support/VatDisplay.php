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
 */
final class VatDisplay
{
    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('vat_enabled', true);
    }

    public function rate(): float
    {
        return (float) $this->settings->get('vat_rate', 5);
    }

    /** The VAT portion, in fils. Informational — never added to the total. */
    public function amount(int $totalFils): int
    {
        $rate = $this->rate();
        if (! $this->enabled() || $rate <= 0 || $totalFils <= 0) {
            return 0;
        }

        return $this->settings->get('vat_basis', 'inclusive') === 'flat'
            ? (int) round($totalFils * $rate / 100)
            : (int) round($totalFils * $rate / (100 + $rate));
    }

    /** e.g. "You're paying VAT (5%)" with {rate} substituted. */
    public function label(): string
    {
        $label = (string) $this->settings->get('vat_label', "You're paying VAT ({rate}%)");

        return str_replace('{rate}', rtrim(rtrim(number_format($this->rate(), 2, '.', ''), '0'), '.'), $label);
    }

    /** Everything the checkout template needs, or null when the line is off. */
    public function line(int $totalFils): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $amount = $this->amount($totalFils);
        if ($amount <= 0) {
            return null;
        }

        return [
            'label' => $this->label(),
            'amount' => $amount,
            'formatted' => Money::format($amount),
        ];
    }
}
