<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * An order's billing or shipping snapshot, as the order screen edits it.
 * (Lane PU)
 *
 * ONE SHAPE. Every writer in this shop stores the same keys -- the checkout
 * (Store\CheckoutController::place), New Order (ManualOrderBuilder::
 * addressPayload) and the WooCommerce import (OrderImporter::addressSnapshot):
 * first_name, last_name, company, line1, line2, city, state, postcode, country,
 * phone. The order screen was the one reader that did not: it printed `a.name`
 * and `a.emirate`, which nothing writes, so the shipping name was blank and the
 * emirate missing on every order in the shop -- and its only editor was a raw
 * JSON textarea that saved whatever array it was given.
 *
 * Keys outside FIELDS are kept as they are on save (a legacy `name`, an `email`
 * an importer carried). The editor rewrites what it shows and nothing else.
 */
final class OrderAddress
{
    /** field => label, in the order the form and the note list them. */
    public const FIELDS = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'company' => 'Company',
        'line1' => 'Address line 1',
        'line2' => 'Address line 2',
        'city' => 'City',
        'state' => 'State / Emirate',
        'postcode' => 'Postcode',
        'country' => 'Country',
        'phone' => 'Phone',
    ];

    public const EMIRATES = [
        'Abu Dhabi', 'Dubai', 'Sharjah', 'Ajman', 'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah',
    ];

    /** No control characters, no angle brackets: these are printed on labels. */
    private const TEXT = '/^[^\x00-\x1F\x7F<>]*$/u';

    /** @return array<string, array<int, mixed>> */
    public static function rules(string $prefix = 'address'): array
    {
        $text = fn (int $max) => ['nullable', 'string', 'max:' . $max, 'regex:' . self::TEXT];

        return [
            $prefix => ['required', 'array'],
            $prefix . '.first_name' => $text(100),
            $prefix . '.last_name' => $text(100),
            $prefix . '.company' => $text(150),
            $prefix . '.line1' => $text(200),
            $prefix . '.line2' => $text(200),
            $prefix . '.city' => $text(100),
            $prefix . '.state' => $text(100),
            $prefix . '.postcode' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 \-]*$/'],
            $prefix . '.country' => ['nullable', 'string', Rule::in(array_keys(Countries::NAMES))],
            $prefix . '.phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]*$/'],
        ];
    }

    /**
     * The stored snapshot with the edited fields written over it. Blank means
     * "remove", so a cleared company line does not survive as an empty string.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>       $input
     * @return array<string, mixed>
     */
    public static function merge(?array $old, array $input): array
    {
        $out = is_array($old) ? $old : [];

        foreach (array_keys(self::FIELDS) as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = trim(preg_replace('/\s+/u', ' ', (string) ($input[$key] ?? '')) ?? '');

            if ($key === 'country') {
                $value = strtoupper($value);
            }

            if ($value === '') {
                unset($out[$key]);
            } else {
                $out[$key] = $value;
            }
        }

        // A first/last name written over a legacy single `name` replaces it,
        // or the old one would go on being printed by anything that reads it.
        if (isset($out['name']) && (isset($input['first_name']) || isset($input['last_name']))) {
            unset($out['name']);
        }

        return $out;
    }

    /**
     * "City: Dubai → Sharjah" for every field that moved, for the order note.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>       $new
     * @return list<string>
     */
    public static function changes(?array $old, array $new): array
    {
        $old = is_array($old) ? $old : [];
        $lines = [];

        foreach (self::FIELDS as $key => $label) {
            $was = trim((string) ($old[$key] ?? ''));
            $now = trim((string) ($new[$key] ?? ''));

            if ($was !== $now) {
                $lines[] = $label . ': ' . ($was === '' ? '(blank)' : $was) . ' → ' . ($now === '' ? '(blank)' : $now);
            }
        }

        return $lines;
    }
}
