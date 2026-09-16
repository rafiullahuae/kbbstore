<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The delivery promise for one destination, wherever it is shown.
 *
 * THE DEFAULT IS A UAE PROMISE AND IS ONLY OFFERED TO THE UAE.
 *
 * `delivery_default_text` is "1–3 days fast delivery all over UAE" — the
 * owner's own wording, and true of the only destination it names. It used to be
 * the fallback for EVERY country at the checkout, so a shopper in Saudi Arabia
 * being charged the AED 150 Gulf rate on that very screen was told, in writing,
 * that their order arrives in one to three days anywhere in the UAE. Not merely
 * irrelevant to them: it is a delivery promise, and it was the wrong one.
 *
 * That is the same repair App\Mail\OrderStatusChanged already made to the
 * dispatch email, for the same reason and with the same restraint: nothing is
 * invented to put in its place. No Gulf delivery window has been measured, and
 * a "five to eight days" written here to fill the gap would be the same class
 * of untruth in the other direction. The line is simply not shown —
 * partials/checkout/delivery-line.blade.php already renders nothing for an
 * empty string — until the owner writes one.
 *
 * AND THEY CAN. An explicit `delivery_texts` row still wins for any country,
 * including the UAE, so the escape hatch for the Gulf is a screen rather than a
 * code change. It is now a screen that actually exists: Store → Delivery &
 * Shipping → Delivery lines. Before that tab, `delivery_texts` had exactly one
 * reader in the whole codebase and no writer anywhere — no admin form, no
 * seeder row, no validation rule — so the escape hatch this comment promised
 * could not be reached by the person it was promised to.
 *
 * WHY THIS IS A CLASS AND NOT A METHOD ON CheckoutController. The home page
 * prints a delivery line too, and printed `delivery_default_text` there
 * unconditionally, to every visitor in the world, with no country check of any
 * kind. The rule above was therefore true of the checkout and false of the
 * first page of the shop, which is worse than it being false in both places:
 * the shop contradicted itself, and the more visible page was the one telling
 * a shopper in Riyadh about UAE delivery. One rule, one reader.
 *
 * SHIPPED EMPTY, ON PURPOSE. `delivery_texts` has no seeded rows and no
 * invented defaults. Until the owner types a line for a country, that country
 * gets the behaviour it has today — the UAE its promise, everywhere else
 * silence.
 *
 * STILL SHIPPED EMPTY, NOW THAT THE SCREEN OFFERS SUGGESTIONS.
 * App\Support\CountryPresets holds a set of Gulf sentences the owner can drop
 * into the form in one click, built from figures he supplied himself. They are
 * offered by a screen, not seeded by a migration and not defaulted here: the
 * column is untouched until he presses Save, so a shop that applies the package
 * and never opens the tab tells every shopper exactly what it told them before.
 */
final class DeliveryLine
{
    public const SETTING = 'delivery_texts';

    public function __construct(private SettingsService $settings) {}

    /**
     * The line for wherever this visitor is, resolved through ShopperCountry.
     *
     * The storefront's one-call entry point, and the reason this is safe to
     * call straight from a Blade view: it needs nothing passed in, it issues no
     * query of its own (every value it reads comes from the settings snapshot
     * the page has already taken), and both halves of it answer for a request
     * that has no session and no geo header at all.
     */
    public static function here(?\Illuminate\Http\Request $request = null): string
    {
        $request ??= request();

        return app(self::class)->for(ShopperCountry::for($request)->code);
    }

    /**
     * The line for a destination, or an empty string when there is nothing true
     * to say.
     *
     * ONE PLACEHOLDER, SUBSTITUTED ON THE WAY OUT. The owner asked for "the
     * full sentence and the country name will auto change according", so a
     * stored line may contain `{country}` and CountryTemplate::fill() replaces
     * it with the destination's name — the same convention VatDisplay::label()
     * already uses for `{rate}`, spelled the same way.
     *
     * This cannot change what any country is told today. Every line stored
     * before this existed was typed as literal words and contains no
     * placeholder, and a sentence with no placeholder comes back byte for byte
     * unchanged. The substitution is a no-op until somebody writes one.
     */
    public function for(string $country): string
    {
        $country = strtoupper(trim($country));

        foreach (self::rows($this->settings) as $row) {
            if (strtoupper((string) ($row['country'] ?? '')) === $country) {
                return CountryTemplate::fill((string) ($row['text'] ?? ''), $country);
            }
        }

        if ($country !== strtoupper((string) $this->settings->get('store_country', 'AE'))) {
            return '';
        }

        return CountryTemplate::fill(
            (string) $this->settings->get('delivery_default_text', '1–3 days fast delivery all over UAE'),
            $country,
        );
    }

    /**
     * The saved rows, normalised.
     *
     * The setting is written by an admin screen and read back through
     * SettingsService, which json-decodes an array value — but a row written by
     * an older build, or a value that failed to decode, must not take the
     * storefront down. Anything that is not a `['country' => .., 'text' => ..]`
     * row is dropped rather than reached into.
     *
     * @return array<int, array{country:string, text:string}>
     */
    public static function rows(SettingsService $settings): array
    {
        $raw = $settings->get(self::SETTING, []);

        // A value that reached the column as JSON text rather than as an array
        // — which is how Setting::map() hands it back, and how a package or a
        // hand-edited row can leave it.
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['country'] ?? '')));
            $text = trim((string) ($row['text'] ?? ''));

            if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                continue;
            }

            $out[] = ['country' => $code, 'text' => $text];
        }

        return $out;
    }
}
