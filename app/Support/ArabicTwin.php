<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * An owner-written setting and its Arabic box (Lane AE).
 *
 * The basket reminder and the back-in-stock alert are written by the owner, in
 * English, under Store -> Ecommerce; an Arabic shopper got his English. Each of
 * those boxes now has a twin, `<key>_ar`, beside it, shipped with a sensible
 * Arabic default -- the `_ar` pattern CartPanel, CheckoutPage and AlsoLike use.
 *
 * THE RULES, in the order they are asked:
 *
 *   1. The ENGLISH box is still the switch. Blank English means the feature is
 *      off, exactly as its own help says ("Empty means nothing is ever sent"),
 *      in every language. An Arabic default must not switch on a message the
 *      owner never wrote.
 *   2. Not Arabic: the English, untouched -- and the `_ar` key is never even
 *      read, so an English page does not look up one more setting.
 *   3. Arabic: his Arabic box; if he has emptied it, his English. Never blank.
 */
final class ArabicTwin
{
    public static function pick(string $key, string $arabicDefault, ?string $locale = null): string
    {
        $settings = app(SettingsService::class);

        $english = trim((string) ($settings->get($key, '') ?? ''));

        if ($english === '' || ($locale ?? Locale::current()) !== 'ar') {
            return $english;
        }

        $arabic = trim((string) ($settings->get($key . '_ar', $arabicDefault) ?? ''));

        return $arabic !== '' ? $arabic : $english;
    }
}
