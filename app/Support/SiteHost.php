<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * What kind of host is this request arriving on?
 *
 * One shop, four addresses:
 *
 *     extrabeauty.ae            the live site            CANONICAL
 *     www.extrabeauty.ae        the same shop            ALIAS    -> 301
 *     kbeautybliss.com          the old shop             ALIAS    -> 301
 *     staging.extrabeauty.ae    the same code, ahead     UNLISTED -> noindex
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS AN ALLOW-LIST OF ALIASES AND NOT "REDIRECT ANYTHING THAT IS NOT
 * CANONICAL", WHICH IS WHAT EVERY OTHER IMPLEMENTATION OF THIS DOES
 * ---------------------------------------------------------------------------
 *
 * Because there is no shell on this host, and a redirect rule that sends the
 * admin panel somewhere unreachable cannot be undone from inside the admin
 * panel. CLAUDE.md's constraint outranks the feature: an admin nobody can
 * reach cannot be repaired.
 *
 * "Redirect everything that is not canonical" fails catastrophically on a
 * typo. Set the canonical host to `extrabeauy.ae` and every request to the
 * real site 301s to a domain that does not exist -- the storefront, the admin
 * and the login form all at once, with no way back in.
 *
 * This class inverts it. The ONLY hosts that are ever redirected are the ones
 * somebody typed into the alias list. A typo in the canonical host makes those
 * aliases land somewhere wrong -- visibly, and on hosts nobody uses as their
 * way in -- while the real host keeps serving normally, because it is not on
 * the list and is therefore never touched. The failure mode is "the old domain
 * stopped forwarding", which is a support email. The other design's failure
 * mode is "my shop is gone", which is a lost weekend.
 *
 * ---------------------------------------------------------------------------
 * THE SECOND SAFETY: EMPTY MEANS OFF
 * ---------------------------------------------------------------------------
 *
 * With no canonical host configured, classify() answers CANONICAL for
 * everything. Nothing redirects, nothing is marked noindex, and the middleware
 * is a no-op. That is the shipped default, so applying the package that
 * introduces this changes the behaviour of precisely nothing until somebody
 * fills the field in.
 *
 * ---------------------------------------------------------------------------
 * WWW IS DERIVED, NOT TYPED
 * ---------------------------------------------------------------------------
 *
 * `www.` is NOT normalised away, because folding it would make the apex and
 * the www form compare equal and the redirect that is the entire point would
 * never fire. Instead the counterpart is derived: a canonical host without
 * `www.` implies `www.` + itself is an alias, and one with `www.` implies the
 * bare form is. Nobody has to know to type it, and the pair can never be
 * configured inconsistently.
 */
final class SiteHost
{
    /** The live site. Serve normally. */
    public const CANONICAL = 'canonical';

    /** A host listed to be forwarded to the canonical one. */
    public const ALIAS = 'alias';

    /**
     * Anything else that resolves here -- staging, a preview, a domain
     * somebody pointed at this server. Served, never redirected, never
     * indexed.
     */
    public const UNLISTED = 'unlisted';

    /** Settings keys. Private by design -- SettingController::PUBLIC_KEYS is an allow-list. */
    public const KEY_CANONICAL = 'canonical_host';
    public const KEY_ALIASES = 'host_aliases';
    public const KEY_REDIRECT = 'host_redirect_enabled';

    /**
     * "Discourage search engines from indexing this site" -- one switch, and
     * WordPress's wording on purpose, because that is the control every site
     * owner installing this has already met.
     *
     * IT IS NOT DERIVED FROM THE HOST, and that is the whole reason it exists.
     * Inferring privacy from "this host is not the canonical one" covers a
     * staging site that lives BESIDE production (staging.example.com while
     * example.com is canonical) and silently fails for one that lives on a
     * domain of its own -- where it IS the canonical host, classifies as
     * CANONICAL, and gets indexed. That is the arrangement the vendor console
     * and the release-staging site actually use, so the inference would have
     * been wrong for exactly the two sites this was asked for.
     *
     * So: an explicit switch, defaulting to public, which every non-production
     * install turns on once and never thinks about again. The host inference
     * below stays as well, as the automatic half that catches a domain
     * somebody pointed here without telling anyone.
     */
    public const KEY_VISIBILITY = 'search_visibility';

    private static ?string $memoHost = null;

    private static ?string $memoVerdict = null;

    /**
     * Lower-case, no port, no trailing dot.
     *
     * Deliberately NOT stripping `www.` -- see the class comment.
     */
    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));

        // Strip a port, but not the colon of an IPv6 literal in brackets.
        if (! str_starts_with($host, '[') && ($colon = strrpos($host, ':')) !== false) {
            $host = substr($host, 0, $colon);
        }

        return rtrim($host, '.');
    }

    /** The configured canonical host, normalised. Empty string means the feature is off. */
    public static function canonical(): string
    {
        $map = Setting::map();

        return self::normalise((string) ($map[self::KEY_CANONICAL] ?? ''));
    }

    /**
     * Every host that forwards to the canonical one: what was typed, plus the
     * derived www counterpart of the canonical host.
     *
     * @return list<string>
     */
    public static function aliases(): array
    {
        $canonical = self::canonical();

        if ($canonical === '') {
            return [];
        }

        $map = Setting::map();
        $raw = (string) ($map[self::KEY_ALIASES] ?? '');

        $out = [];

        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $host = self::normalise((string) $line);

            if ($host !== '' && $host !== $canonical) {
                $out[$host] = true;
            }
        }

        // The derived counterpart, so nobody has to know to type it.
        $counterpart = str_starts_with($canonical, 'www.')
            ? substr($canonical, 4)
            : 'www.'.$canonical;

        if ($counterpart !== '') {
            $out[$counterpart] = true;
        }

        return array_keys($out);
    }

    /** Is the forwarding switch on? Off by default, and meaningless without a canonical host. */
    public static function redirectEnabled(): bool
    {
        $map = Setting::map();

        return self::canonical() !== ''
            && in_array((string) ($map[self::KEY_REDIRECT] ?? '0'), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * CANONICAL, ALIAS or UNLISTED.
     *
     * Answers CANONICAL for everything when no canonical host is configured,
     * which is how this whole feature ships switched off.
     */
    public static function classify(string $host): string
    {
        $canonical = self::canonical();

        if ($canonical === '') {
            return self::CANONICAL;
        }

        $host = self::normalise($host);

        if ($host === $canonical) {
            return self::CANONICAL;
        }

        return in_array($host, self::aliases(), true) ? self::ALIAS : self::UNLISTED;
    }

    /**
     * The verdict for the request being served, memoised per process.
     *
     * Seo::render() and SeoFilesController both ask, and a page render must not
     * pay for the settings map twice. Memoised on the host it was computed for,
     * so a second host in the same process -- which is every test that checks
     * two of them -- recomputes rather than answering for the first.
     */
    public static function current(): string
    {
        $host = self::normalise((string) request()?->getHost());

        if (self::$memoHost === $host && self::$memoVerdict !== null) {
            return self::$memoVerdict;
        }

        self::$memoHost = $host;

        return self::$memoVerdict = self::classify($host);
    }

    /**
     * Has the owner switched this whole install to private?
     *
     * The deliberate half of isPrivate(). Set once on a staging site or a
     * console and it holds whatever host the request arrives on.
     */
    public static function visibilityIsPrivate(): bool
    {
        $map = Setting::map();

        return in_array(
            strtolower(trim((string) ($map[self::KEY_VISIBILITY] ?? 'public'))),
            ['private', '1', 'true', 'on', 'yes', 'noindex'],
            true
        );
    }

    /**
     * Should this request be kept out of every search index?
     *
     * Two independent reasons, and either is enough:
     *
     *   THE OWNER SAID SO -- the switch above. This is how a staging site or a
     *   console on a domain of its own stays out, and it is the one that has to
     *   be explicit because on its own domain such a site is canonical.
     *
     *   THE HOST IS NOT ONE WE KNOW -- an UNLISTED host. A staging subdomain
     *   beside production, a preview, a domain somebody pointed at this server.
     *   Automatic, so an unknown address cannot quietly become a second indexed
     *   copy of the shop before anyone notices it resolves.
     *
     * An ALIAS is forwarded rather than hidden: the 301 is what carries its
     * ranking to the canonical address, and marking it noindex would throw that
     * away.
     */
    public static function isPrivate(): bool
    {
        return self::visibilityIsPrivate() || self::current() === self::UNLISTED;
    }

    /** Is this the live site? */
    public static function isCanonical(): bool
    {
        return self::current() === self::CANONICAL;
    }

    /** For tests, and for the request after a settings write. */
    public static function forget(): void
    {
        self::$memoHost = null;
        self::$memoVerdict = null;
    }
}
