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

    /**
     * The free allowance, said in the same breath as the price — and said the
     * way Google actually delivers it.
     *
     * ── IT IS A CREDIT, NOT A CAP, AND THE DIFFERENCE IS THE OWNER'S MONEY ──
     *
     * This used to read "translates the first 500,000 characters each calendar
     * month at no charge", which is how everybody describes it and is not what
     * happens. Google's published pricing says the first 500,000 characters a
     * month are "Free (applied as a $10 credit every month)". So:
     *
     *   - a billing account is REQUIRED before a single character is
     *     translated, free ones included. The key will not work without one,
     *     which is why the setup guide cannot skip that step;
     *   - nothing stops at the allowance. Going past it bills the card on the
     *     account rather than refusing the request.
     *
     * An owner told "the first 500,000 are free" reasonably concludes he cannot
     * be charged by accident. He can. The run screen shows him the character
     * count before he presses anything precisely because of that, and this
     * sentence has to agree with it.
     */
    public static function freeTierNote(): string
    {
        return 'Google applies a '.self::COST_CURRENCY.' 10.00 credit to your account each calendar month, '
            .'which covers about the first '.number_format(TranslationEstimate::FREE_TIER_CHARACTERS)
            .' characters. After the credit runs out it bills '
            .self::COST_CURRENCY.' '.number_format(TranslationEstimate::USD_PER_MILLION, 2)
            .' per million characters to your own card — it does not stop at the free allowance, so check the '
            .'character count on this screen before a large run. Both figures are Google\'s published list '
            .'prices held as constants here, not live ones: this shop cannot see your billing account, so it '
            .'cannot know how much of this month\'s credit you have already spent.';
    }

    /**
     * How to get a key, in the order the screens actually appear.
     *
     * ── WHY THIS LIVES BESIDE providerNote() AND NOT IN THE BLADE ───────────
     *
     * These steps are true of ONE provider. App\Services\Translation\
     * TranslationProvider exists because the owner asked for "translate from
     * Google" and meant "I do not want to type 671 descriptions" — DeepL and
     * Azure do the same job at different prices, and swapping the binding would
     * make every step below wrong. Keeping the steps next to the note that
     * already describes the provider means they move together.
     *
     * ── WHY NullProvider STILL GETS GOOGLE'S STEPS ──────────────────────────
     *
     * The provider bound when there is no key is NullProvider, and that is
     * EXACTLY when this guide is read. Asking the bound provider would answer
     * "None configured" to the one question the screen is open to settle. So
     * the steps describe the provider that binds once a key is saved, which is
     * GoogleProvider, and say so in the heading.
     *
     * ── THE CLICK PATH IS GIVEN AS WELL AS THE LINK, ON PURPOSE ─────────────
     *
     * A deep link into somebody else's console is the part of this that rots
     * first. Every step that has a link also names the menu path, so a moved
     * page costs the owner one extra look rather than stopping him.
     *
     * The endpoint these keys are used against is v2 (the Basic tier) — see
     * GoogleProvider::ENDPOINT — which takes a plain API key as a query
     * parameter. That is why step 5 creates an API KEY and not a service
     * account: a service account JSON is what v3 wants and it will not work
     * here.
     *
     * @return array{heading:string, steps:list<array{title:string,body:string,url:?string,link_label:?string}>, closing:string}
     */
    public static function setupGuide(): array
    {
        return [
            'heading' => 'How to get a Google Cloud Translation key',
            'steps' => [
                [
                    'title' => 'Sign in to Google Cloud with the account that should be billed',
                    'body' => 'Any Google account works, including an ordinary Gmail one. Use the account you '
                        .'are happy to have a card on — this is the account the translation is charged to.',
                    'url' => 'https://console.cloud.google.com/',
                    'link_label' => 'console.cloud.google.com',
                ],
                [
                    'title' => 'Create a project',
                    'body' => 'A project is just a folder for the things you switch on. Call it anything — '
                        .'"K-Beauty Bliss" is fine. If you already have one, use it.',
                    'url' => 'https://console.cloud.google.com/projectcreate',
                    'link_label' => 'New project',
                ],
                [
                    'title' => 'Add a billing account to that project',
                    'body' => 'This step cannot be skipped, even though the first characters each month are '
                        .'covered. Google delivers the free allowance as a monthly credit against a billing '
                        .'account, so without one the key is created but every translation is refused. '
                        .'Menu path: Billing → Link a billing account.',
                    'url' => 'https://console.cloud.google.com/billing',
                    'link_label' => 'Billing',
                ],
                [
                    'title' => 'Switch on the Cloud Translation API',
                    'body' => 'Find "Cloud Translation API" and press Enable. Menu path: APIs & Services → '
                        .'Library → search for Cloud Translation. Enabling it does not cost anything on its own.',
                    'url' => 'https://console.cloud.google.com/apis/library/translate.googleapis.com',
                    'link_label' => 'Cloud Translation API',
                ],
                [
                    'title' => 'Create an API key — not a service account',
                    'body' => 'Menu path: APIs & Services → Credentials → Create credentials → API key. Google '
                        .'shows the key once, as a long string starting AIza. Copy it. If it offers you a '
                        .'service account or a JSON file instead, that is the wrong kind of credential for this '
                        .'shop and will not work.',
                    'url' => 'https://console.cloud.google.com/apis/credentials',
                    'link_label' => 'Credentials',
                ],
                [
                    'title' => 'Restrict the key before you leave the page',
                    'body' => 'Press Edit API key, and under "API restrictions" choose Restrict key and tick '
                        .'Cloud Translation API. A key with no restriction can be used against anything on your '
                        .'account by anyone who gets hold of it. This shop stores the key encrypted and never '
                        .'shows it again, but the restriction is what limits the damage if it leaks somewhere '
                        .'else.',
                    'url' => null,
                    'link_label' => null,
                ],
                [
                    'title' => 'Paste it into the box above and press Save key',
                    'body' => 'The Translate buttons switch on straight away. Nothing is translated until you '
                        .'press one, and every machine translation is saved as a draft that no shopper sees '
                        .'until you have read it and approved it.',
                    'url' => null,
                    'link_label' => null,
                ],
            ],
            'closing' => 'Google publishes its own prices, and they are the figures this screen quotes. '
                .'If you would rather not connect anything at all, you do not have to: typing Arabic in by '
                .'hand needs no key, no account and no card, and is what the Strings screen and the Arabic '
                .'boxes in every editor are for.',
        ];
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
