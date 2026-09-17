<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Translation;
use App\Support\Locale;
use Illuminate\Contracts\Translation\Loader;

/**
 * Makes Laravel's own __() read this shop's database.
 *
 * ── WHY EXTEND THE FRAMEWORK RATHER THAN INVENT A HELPER ────────────────────
 *
 * The obvious alternative is a kbb_t() helper. It would have worked, and it
 * would have been wrong, because ninety-five Blade files are about to be
 * converted by other lanes and every one of those conversions is cheaper if the
 * thing being typed is the thing every Laravel developer and every future
 * contributor already knows.
 *
 * Using __() also inherits, free and already tested by the framework:
 *
 *   - :placeholder replacement, with the :Placeholder / :PLACEHOLDER casing
 *     rules, which a hand-rolled helper reimplements badly;
 *   - trans_choice() pluralisation, which Arabic genuinely needs — it has SIX
 *     plural forms where English has two, and Laravel's MessageSelector already
 *     knows that;
 *   - fallback to the fallback locale when a key is missing;
 *   - @lang in Blade, and locale-aware Mailables via $mailable->locale().
 *
 * ── THE DECORATION, AND ITS ORDER ───────────────────────────────────────────
 *
 * This wraps the framework's FileLoader rather than replacing it, so anything
 * the framework supplies for itself — validation messages, pagination wording —
 * keeps working exactly as before. Three layers, lowest first:
 *
 *   1. FILE       whatever the framework or a package ships
 *   2. CODE       App\Services\Translation\InterfaceStrings, English only
 *   3. DATABASE   published rows the owner has typed, for THIS locale
 *
 * So the database always wins, which is the point: the owner has no shell, and
 * a correction he can type has to beat a string that needs a release. It also
 * means he can fix an English wording without a release — the same row, in the
 * 'en' locale.
 *
 * ── WHAT AN UNTRANSLATED STRING LOOKS LIKE ──────────────────────────────────
 *
 * Nothing at all in the Arabic layer means Laravel falls through to the
 * fallback locale and the shopper sees English. That is deliberate. The shop is
 * live while the translation is being typed, and a half-Arabic page still sells
 * something; a page full of ⟪brackets⟫ does not.
 *
 * ⟪Brackets⟫ exist for the person doing the typing, behind the setting
 * `translation_highlight_missing`. With it on, every string on the Arabic site
 * that has not been translated yet renders as ⟪English⟫ — so finding what is
 * left is walking the shop rather than reading a list. Off by default and
 * off in production; the admin screen says so beside the switch.
 *
 * WHY THE HIGHLIGHT IS BUILT HERE and not at the call site: Laravel's fallback
 * happens inside Translator::get(), so by the time a value reaches a view it is
 * impossible to tell an English string that was CHOSEN from one that was fallen
 * back to. Filling the gaps in the Arabic group itself is the only place the
 * distinction still exists.
 */
final class DatabaseTranslationLoader implements Loader
{
    public function __construct(private readonly Loader $inner) {}

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        $base = $this->inner->load($locale, $group, $namespace);

        // A namespaced group belongs to a package (vendor/foo::messages).
        // Nothing in this shop publishes one, and overriding another package's
        // strings from the shop's own table is not a thing anyone asked for.
        if ($namespace !== null && $namespace !== '*') {
            return $base;
        }

        $english = InterfaceStrings::group($group);

        // The code defaults are the ENGLISH source. They are layered in only
        // for the default locale, so an Arabic group that has no row for a key
        // stays genuinely empty and Laravel's own fallback runs.
        $code = $locale === Locale::DEFAULT ? $english : [];

        $database = $this->fromDatabase($locale, $group);

        $merged = array_replace($base, $code, $database);

        if ($locale !== Locale::DEFAULT && $this->highlightMissing()) {
            foreach ($english as $key => $source) {
                if (! array_key_exists($key, $database)) {
                    $merged[$key] = '⟪' . $source . '⟫';
                }
            }
        }

        return $merged;
    }

    /**
     * Published rows for one group, with the group prefix stripped.
     *
     * The table stores a key whole — 'store.wishlist.title' — and Laravel asks
     * for group 'store' and then looks up 'wishlist.title' inside it. Storing
     * the whole key means the admin screen, the progress query and the
     * machine-translation runner all deal in one string rather than in a pair,
     * and the prefix comes off here, once.
     *
     * Flat dotted keys are returned rather than a nested array on purpose:
     * Illuminate\Support\Arr::get() checks array_key_exists() before it starts
     * splitting on dots, so a flat map resolves in one hash lookup and cannot
     * be broken by a key whose own name contains a dot.
     *
     * @return array<string, string>
     */
    private function fromDatabase(string $locale, string $group): array
    {
        $prefix = TranslationStore::normaliseKey($group) . '.';

        $out = [];

        foreach (TranslationStore::uiMap($locale) as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $out[substr($key, strlen($prefix))] = $value;
            }
        }

        return $out;
    }

    /**
     * Is the owner walking the shop looking for gaps right now?
     *
     * Read through SettingsService so it is one cached table read, and guarded
     * so a request that arrives before the settings table exists — during the
     * migration that creates it — cannot take a page down over a review tool.
     */
    private function highlightMissing(): bool
    {
        try {
            return (bool) app(\App\Services\SettingsService::class)
                ->get('translation_highlight_missing', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param  string  $locale */
    public function addNamespace($namespace, $hint): void
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->inner->addJsonPath($path);
    }

    /** @return array<string, string> */
    public function namespaces(): array
    {
        return $this->inner->namespaces();
    }
}
