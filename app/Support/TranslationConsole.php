<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationProvider;
use Illuminate\Support\Facades\Auth;

/**
 * The sentences the Translation screens print, computed where the behaviour is.
 *
 * ── WHY THE PROSE IS HERE AND NOT IN THE BLADE ──────────────────────────────
 *
 * The Translation console's whole job is explaining state: what a switch did,
 * what a bare `/` serves, why a button is off, what it is about to cost. A
 * sentence typed into the screen is a second copy of an answer the server
 * already knows, and a second copy is wrong the first time the behaviour
 * changes — silently, because nothing compares the two.
 *
 * So the screen renders strings and the strings are built here, beside the code
 * they describe. TranslationsApiController::settings() and ::estimate() return
 * them; admin/partials/translation-screens.blade.php prints them and derives
 * none of its own.
 *
 * ── AND WHY IT IS NOT LOCALISED ─────────────────────────────────────────────
 *
 * These are admin strings and they are deliberately NOT run through __(). T8 —
 * translating the console itself — is deferred by the owner; the back office is
 * one operator and it speaks English. Locale::localisable() already excludes
 * the whole admin from the /ar prefix for the same reason.
 */
final class TranslationConsole
{
    /** The currency the machine-translation estimate is quoted in. */
    public const COST_CURRENCY = 'USD';

    /**
     * What a bare `/` serves, and what `/ar` is right now.
     *
     * Both halves of the owner's "default language, and what a bare / serves"
     * in one sentence pair, phrased from the actual switch rather than from a
     * screen's belief about it.
     */
    public static function rootServes(bool $arabicEnabled): string
    {
        if (! $arabicEnabled) {
            return 'A bare / serves English, and English is the only language being served. '
                .'/ar does not exist: no route answers it, no link, canonical or sitemap entry points at it, '
                .'and it returns the same 404 this shop returns today.';
        }

        return 'A bare / still serves English at the address it has always had — switching Arabic on moves no URL. '
            .'Arabic is served under /ar/, and /en/ answers with a 301 to the unprefixed form, so that address '
            .'resolves without creating a second copy of every page.';
    }

    /**
     * Why the default language is a fact rather than a dropdown.
     *
     * The owner asked for "default language" as a control. It is shown as one
     * value and an explanation instead, because changing it is not a setting —
     * it is a migration of every URL on the site, which is the one thing the
     * URL Contract exists to prevent happening twice. See App\Support\Locale.
     */
    public static function defaultLocaleNote(): string
    {
        $name = Locale::LOCALES[Locale::DEFAULT]['name'] ?? Locale::DEFAULT;

        return $name.' is the default language: it is the one served with no prefix, and it is not switchable here. '
            .'Every indexed address, every row in the redirects table, the sitemap and every link in every email '
            .'this shop has ever sent already assume it. Moving it would move all of them with it.';
    }

    /**
     * What the machine-translation service is doing, or not doing, and why.
     *
     * The "no key" case is the one that matters: with nothing configured the
     * screen must read as a feature that is switched off, not as a screen that
     * is broken, and it must say that the manual path is untouched — because
     * the manual path is the one that has to work on a shop that never buys a
     * key at all.
     */
    public static function providerNote(TranslationProvider $provider): string
    {
        if (! $provider->available()) {
            return 'No translation service is connected, so the machine-translation buttons are off. '
                .'Nothing else is affected: typing Arabic in by hand needs no key, costs nothing, and is '
                .'published the moment you save it. The machine switches on the moment your own API key is '
                .'saved, and stays off until then.';
        }

        return $provider->name().' is connected. It is billed per character of English text to your own account, '
            .'and everything it writes is a draft: no shopper sees a machine translation until you have read it '
            .'and approved it.';
    }

    /**
     * The cost, with its currency spelled out.
     *
     * USD and NOT through App\Support\Money. Money renders this shop's own
     * currency — dirhams, and whole dirhams from this cycle onward — and this
     * figure is neither: it is Google's published USD list price, quoted per
     * million characters. Running it through Money would print a dirham sign on
     * a dollar amount and then round the cents off a number whose whole purpose
     * is to be small and precise before anything is spent.
     */
    public static function costDisplay(float $usd): string
    {
        return self::COST_CURRENCY.' '.number_format($usd, 2);
    }

    /** The free allowance, said in the same breath as the price. */
    public static function freeTierNote(): string
    {
        return 'Google translates the first '.number_format(TranslationEstimate::FREE_TIER_CHARACTERS)
            .' characters each calendar month at no charge, and bills '
            .self::COST_CURRENCY.' '.number_format(TranslationEstimate::USD_PER_MILLION, 2)
            .' per million after that. Both are published list prices held as constants here, not live figures — '
            .'this shop cannot see your billing account, so it cannot know how much of this month\'s allowance '
            .'you have already used.';
    }

    /**
     * What the signed-in admin may actually do on these screens.
     *
     * ── ASKED OF AdminCapabilities, NOT RESTATED ────────────────────────────
     *
     * Each answer is looked up by the METHOD AND PATH of the endpoint the
     * control posts to, so this cannot drift from AdminCapabilities::RULES the
     * way a second hand-written list of capability names would. Move
     * /translations/machine/run from store.settings to something else and this
     * follows it in the same commit, with nothing to remember.
     *
     * The owner short-circuit is repeated deliberately: EnforceAdminCapability
     * returns before it consults the map at all for an owner, so a screen that
     * asked the map instead would hide a control from the one person who can
     * always use it.
     *
     * @return array{settings: bool, machine_run: bool, strings: bool, machine_field: bool}
     */
    public static function capabilities(): array
    {
        $role = AdminCapabilities::canonicalRole(Auth::guard('admin')->user()?->role ?? null);

        return [
            'settings' => self::can($role, 'POST', 'admin-api/translations/settings'),
            'machine_run' => self::can($role, 'POST', 'admin-api/translations/machine/run'),
            'strings' => self::can($role, 'POST', 'admin-api/translations'),
            'machine_field' => self::can($role, 'POST', 'admin-api/translations/machine/field'),
        ];
    }

    private static function can(?string $role, string $method, string $uri): bool
    {
        if ($role === 'owner') {
            return true;
        }

        return AdminCapabilities::roleCan($role, AdminCapabilities::forPath($method, $uri));
    }

    /**
     * What to tell somebody who is looking at a screen they cannot fully use.
     *
     * Named plainly, and not as an error. A manager or an editor opening this
     * screen has not done anything wrong: the two levers they are missing are
     * owner-only because one of them spends the owner's money and the other
     * publishes a second language to every shopper at once. The alternative —
     * rendering the switches and letting the save 403 — teaches them that the
     * console is broken.
     *
     * @param  array{settings: bool, machine_run: bool, strings: bool, machine_field: bool}  $can
     */
    public static function capabilityNote(array $can): ?string
    {
        if ($can['settings'] && $can['machine_run']) {
            return null;
        }

        $locked = [];

        if (! $can['settings']) {
            $locked[] = 'the language switches';
            $locked[] = 'the API key';
        }

        if (! $can['machine_run']) {
            $locked[] = 'the batch translation run';
        }

        $note = 'Your role cannot change '.self::join($locked)
            .', so they are shown here as they stand rather than as controls. Ask the owner to change them.';

        if ($can['strings']) {
            $note .= ' Everything else on these screens is yours to use: you can read the progress figures and '
                .'write, edit and publish translations as normal.';
        }

        return $note;
    }

    /** @param list<string> $parts */
    private static function join(array $parts): string
    {
        if (count($parts) < 2) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        // "or" and not "and": this is a list of things the reader cannot do,
        // and "cannot change A and B" is read by some as "cannot change both".
        return implode(', ', $parts).' or '.$last;
    }
}
