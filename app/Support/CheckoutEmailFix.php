<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * Checkout: suggest a fix for a mistyped email. (Lane EH)
 *
 * The owner: shoppers type jovana8b@yahoo.co for yahoo.com and never receive
 * their order emails. He picked style A (docs/checkout-email-hint/) and asked
 * for it "turned off this default", with the switch "under Emails > settings
 * somewhere": Emails → Sending & delivery → At checkout.
 *
 * One setting, its own key, false until he turns it on. While it is off the
 * checkout prints nothing for it -- no attribute, no element, no script -- so
 * the page is byte-identical to the page before this existed
 * (CheckoutEmailTypoHintTest). On, contact-email.blade.php prints ONE marker,
 * `data-efix`, holding the translated sentence, and checkout.js imports the
 * hint's chunk because the marker is there.
 *
 * The read is SettingsService::get(), inside the request memo: a saved value
 * is an autoload row (no query), and an unsaved one is a miss on the snapshot
 * the checkout has already taken by then (no query either).
 */
final class CheckoutEmailFix
{
    public const KEY = 'checkout_email_fix';

    public const DEFAULT = false;

    public static function enabled(?SettingsService $settings = null): bool
    {
        $value = ($settings ?? app(SettingsService::class))->get(self::KEY, null);

        return $value === null ? self::DEFAULT : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** The sentence, translated, with `:email` left for the script to fill. */
    public static function wording(): string
    {
        return (string) __('store.checkout.email_fix');
    }
}
