<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * The English wording of every interface string, as code.
 *
 * ── WHY THIS IS A PHP CLASS AND NOT lang/en/store.php ───────────────────────
 *
 * Laravel's conventional home for these is lang/. On this host that directory
 * cannot be shipped to: App\Services\Update\UpdateGuard::ALLOWED_PREFIXES is
 * app/, config/, database/migrations/, database/seeders/, resources/, routes/
 * and public/build/, and `lang/` is on none of them — an update package
 * containing lang/en/store.php is REJECTED by the updater before a single file
 * is written. So the English source of truth lives under app/, where a package
 * can reach it, and the file loader is left in place underneath for anything
 * the framework itself supplies (validation messages, pagination).
 *
 * ── WHAT BELONGS HERE AND WHAT DOES NOT ─────────────────────────────────────
 *
 * Here: the fixed wording of the interface — buttons, headings, empty states,
 * the sentences in transactional emails. Finite, keyed, and the same for every
 * shopper.
 *
 * NOT here: anything in the catalogue. A product name is a row, not a key, and
 * lives in the translations table against its own id. See
 * App\Support\HasTranslations.
 *
 * NOT here either: the back office. Store → anything is one operator on a
 * screen that is not indexed and not customer-facing, and the owner has
 * deferred it to its own phase. The machinery does not exclude it — an 'admin'
 * group would drop straight in — but nothing here is written for it.
 *
 * ── HOW A KEY IS NAMED ──────────────────────────────────────────────────────
 *
 * group.page.thing — 'store.wishlist.empty_title'. The FIRST segment is the
 * Laravel translation group, which is what the loader is asked for, so a page
 * costs one array lookup and never a table scan. Lowercase throughout, because
 * TranslationStore::normaliseKey() lowercases every key on the way into the
 * database and a key that differs only in case would be the same row.
 *
 * ── THE SET BELOW IS A DEMONSTRATION, NOT THE SHOP ──────────────────────────
 *
 * These are the strings of one storefront page and two lines of one email,
 * converted end to end to prove the pattern works — resolution, fallback,
 * caching, the admin write path and the machine-translation draft. The other
 * ~95 Blade files are Phase 2 and are deliberately untouched: converting them
 * before the shape was settled would have meant converting them twice.
 */
final class InterfaceStrings
{
    /**
     * group => key => English.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        return [
            'store' => [
                // resources/views/store/wishlist.blade.php — converted in full.
                'wishlist.breadcrumb_home' => 'Home',
                'wishlist.breadcrumb_current' => 'Wishlist',
                'wishlist.title' => 'Wishlist',
                'wishlist.saved_count' => ':count saved',
                'wishlist.subtitle' => 'Everything you have saved, newest first.',
                'wishlist.keep_browsing' => 'Keep browsing',
                'wishlist.empty_title' => 'Nothing saved yet',
                'wishlist.empty_body' => 'Tap the heart on any product to keep it here for later.',
                'wishlist.empty_cta' => 'Start browsing',
                'wishlist.grid_label' => 'Saved',
                'wishlist.page_title' => 'Wishlist · K-Beauty Bliss',

                // The language switcher itself.
                'language.switch' => 'Language',
                'language.english' => 'English',
                'language.arabic' => 'العربية',
            ],

            'email' => [
                // resources/views/emails/order-status.blade.php
                'order_status.order_label' => 'Order',
                'order_status.view_order' => 'View your order',
            ],
        ];
    }

    /**
     * One Laravel translation group's English strings.
     *
     * @return array<string, string>
     */
    public static function group(string $group): array
    {
        return self::all()[$group] ?? [];
    }

    /**
     * Every key, fully qualified, with its English text.
     *
     * This is what the admin editing screen lists and what the character-count
     * estimate measures: the complete, finite set of interface strings that
     * exist to be translated.
     *
     * @return array<string, string> 'store.wishlist.title' => 'Wishlist'
     */
    public static function flat(): array
    {
        $out = [];

        foreach (self::all() as $group => $strings) {
            foreach ($strings as $key => $english) {
                $out[$group . '.' . $key] = $english;
            }
        }

        return $out;
    }

    /** The English source for one fully-qualified key, or null. */
    public static function english(string $key): ?string
    {
        return self::flat()[TranslationStore::normaliseKey($key)] ?? null;
    }
}
