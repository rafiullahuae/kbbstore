<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * The language a customer uses the shop in (Lane AE).
 *
 * The owner: "if users placed order from arabic language, everything need to
 * followed by arabic stuff for that specific users". An ORDER already carries
 * its language (OrderLocale); this is the same idea for the PERSON, for the
 * emails that belong to no order -- password reset, email confirmation, the
 * basket reminder, the back-in-stock alert, the account invite.
 *
 * ── THE RULES ──────────────────────────────────────────────────────────────
 *
 *   * Written when the customer registers, signs in or places an order: the
 *     language of the page they did it on ('ar' on /ar, 'en' on English). The
 *     latest one wins -- a shopper who moved to English is written to in
 *     English.
 *   * NEVER over an explicit admin choice. Store -> Customers -> a customer ->
 *     Language sets `locale_admin` = 1, and remember() then leaves the row
 *     alone for good (until the owner sets it back to "Automatic").
 *   * Costs nothing until there is a second language: with Arabic off,
 *     enabledCodes() is ['en'] and remember() returns before any query. The
 *     same guard OrderLocale's line-name hook uses.
 *   * Costs nothing before its migration: ready() is a settings flag the
 *     migration writes, read from the map the request already holds, so a
 *     package applied ahead of its migration neither queries the schema nor
 *     writes a column that is not there.
 */
final class CustomerLocale
{
    public const READY_KEY = 'customer_locale_schema';

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }

        try {
            return self::$ready = (string) app(\App\Services\SettingsService::class)->get(self::READY_KEY, '') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /** For the tests, which migrate between cases. */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    /**
     * Record the language this customer is using right now.
     *
     * One guarded UPDATE that writes only when the value changes and the owner
     * has not fixed it, so a sign-in in the same language twice is one
     * statement that touches no row. Never throws: a sign-in or a checkout
     * must not fail over a preference.
     */
    public static function remember(Customer|int|null $customer, ?string $locale = null): void
    {
        $id = $customer instanceof Customer ? (int) $customer->getKey() : (int) $customer;

        if ($id < 1 || ! self::ready() || count(Locale::enabledCodes()) < 2) {
            return;
        }

        $locale ??= Locale::current();

        if (! Locale::isSupported($locale)) {
            return;
        }

        try {
            DB::table('customers')
                ->where('id', $id)
                ->where(fn ($q) => $q->where('locale_admin', 0)->orWhereNull('locale_admin'))
                ->where(fn ($q) => $q->whereNull('locale')->orWhere('locale', '!=', $locale))
                ->update(['locale' => $locale]);

            if ($customer instanceof Customer && (int) ($customer->locale_admin ?? 0) !== 1) {
                $customer->setAttribute('locale', $locale);
                $customer->syncOriginalAttribute('locale');
            }
        } catch (\Throwable) {
            // A preference is never worth a failed sign-in.
        }
    }

    /**
     * The language to write to this customer in, or null for "no opinion"
     * (the caller's own language then applies). Only a language this shop
     * serves: a stored 'ar' on a shop that has switched Arabic off is English.
     */
    public static function of(?Customer $customer): ?string
    {
        if ($customer === null || ! self::ready()) {
            return null;
        }

        return self::usable($customer->getAttribute('locale'));
    }

    /** A stored code, if it is one this shop serves now; otherwise null. */
    public static function usable(mixed $locale): ?string
    {
        $locale = is_string($locale) ? trim($locale) : '';

        if ($locale === '' || ! Locale::isSupported($locale) || ! in_array($locale, Locale::enabledCodes(), true)) {
            return null;
        }

        return $locale;
    }

    /**
     * A product's name in the current language: its published translation on
     * an Arabic render, the English column otherwise (and whenever there is no
     * translation). Read from TranslationStore's in-memory map -- no query per
     * line. English returns $english untouched without looking anything up.
     */
    public static function productName(mixed $productId, string $english): string
    {
        if (Locale::current() === Locale::DEFAULT || ! is_numeric($productId) || (int) $productId < 1) {
            return $english;
        }

        try {
            $name = \App\Services\Translation\TranslationStore::get(Locale::current(), 'products', (int) $productId, 'name');
        } catch (\Throwable) {
            $name = null;
        }

        return is_string($name) && trim($name) !== '' ? $name : $english;
    }

    /**
     * Run a closure in a language, and put the previous one back -- in a
     * finally, so a throw cannot leave a queue worker in Arabic. A null or
     * unusable locale runs the closure in whatever language is current.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function render(?string $locale, \Closure $callback): mixed
    {
        $locale = self::usable($locale);

        if ($locale === null) {
            return $callback();
        }

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
