<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ExtendedDelivery;
use Illuminate\Http\Request;

/**
 * Which country is this visitor in? One answer, for the whole application.
 *
 * WHY THIS EXISTS AT ALL. The question was already being answered in two
 * places that could not see each other. CheckoutController::page() had a
 * four-step precedence of its own; store/home.blade.php had no country check
 * whatsoever and simply printed `delivery_default_text` — a UAE promise — to
 * every visitor on earth. A shopper in Riyadh was told on the home page that
 * delivery takes one to three days all over the UAE, then reached the checkout
 * and was correctly told nothing of the kind. Two screens of one shop
 * disagreeing about where the shopper is standing is the defect this class
 * ends, and VAT is expected to become a third caller.
 *
 * THE ORDER, MOST-TRUSTED FIRST, and why each step sits where it does:
 *
 *   1. EXPLICIT — the shopper said so on THIS request. That is the country
 *      field on the checkout form, read back through `old('billing_country')`
 *      after a redirect, or a saved address the caller already has in hand and
 *      passes in. A person who has stated where they are outranks every guess,
 *      always; nothing below may overturn it. The caller supplies the saved
 *      address rather than this class fetching it, because that is the only
 *      tier that costs a query and the storefront must not pay for it (see
 *      CHEAPNESS below).
 *
 *   2. SESSION — the shopper said so on an EARLIER request. Written only by
 *      remember(), and remember() is called only where a real choice was made:
 *      the checkout's country selector. A guess is deliberately NEVER written
 *      here. If it were, the next request would read its own guess back as
 *      though the shopper had said it, and "we think you are in Saudi Arabia"
 *      would harden into "you told us you are" without anybody having spoken.
 *      That laundering is the whole reason this tier is narrow.
 *
 *   3. HEADER — a geo signal on the request, delegated to
 *      ExtendedDelivery::detect(). It sits below both statements of fact
 *      because it is a guess, and above the store default because a guess
 *      about the visitor beats an assumption about the shop. It is delegated
 *      rather than reimplemented: that method already owns the header list and
 *      the time-zone-cookie fallback, its own docblock says detection is a
 *      general feature rather than an Extended one, and a second header list
 *      here would be a second answer that drifts from the first.
 *
 *   4. DEFAULT — `store_country`. Not a guess about the visitor at all, and
 *      labelled as such, so a screen can tell the difference between knowing
 *      and falling back.
 *
 * NO GEO SOURCE IS A FULLY SUPPORTED STATE. If no header is present, no cookie
 * has been set and the shopper has said nothing, this returns the store's own
 * country with source DEFAULT and every caller behaves exactly as it did
 * before this class existed. Nothing here is a hard dependency on a header that
 * may not be there. And nothing here calls out to a geo API: that would be a
 * network round trip inside a page render on shared hosting, which is slow when
 * it works and a white page when it does not.
 *
 * WHAT WE DO NOT KNOW ABOUT THIS HOST, stated rather than assumed. Whether
 * production actually receives `CF-IPCountry` has not been established: the
 * site is on Hostinger shared hosting, there is no trusted-proxy configuration
 * anywhere in the application, and no Cloudflare-specific handling exists in
 * this codebase beyond the optimistic header read in ExtendedDelivery. There is
 * no GeoIP extension binding, no IP-to-country table and no geo service in the
 * repository. So the header tier may well never fire in production, and this
 * class is written so that that costs nothing: it is one array lookup that
 * misses, and step 4 answers.
 *
 * LEGIBILITY. The source is returned alongside the code so a screen can say
 * "we think you're in Saudi Arabia" rather than asserting it. guessed() is the
 * short form of that question.
 *
 * CHEAPNESS, because this is called from Blade on every page. Steps 1-3 read
 * the request, the session and one header; step 4 reads `store_country`, which
 * SettingsService serves from a cached snapshot the page has already taken. No
 * tier here issues a query of its own. The whole answer is memoised on the
 * Request object for the life of the request — on the request rather than in a
 * class static deliberately, because a process-level static is precisely the
 * trap CLAUDE.md records against Setting::map(): correct under PHP-FPM, wrong
 * in a queue worker and wrong in a test process that serves many requests.
 */
final class ShopperCountry
{
    /** The shopper stated it on this request. */
    public const EXPLICIT = 'explicit';

    /** The shopper stated it on an earlier request. */
    public const SESSION = 'session';

    /**
     * A geo signal carried by the request guessed it.
     *
     * Named for the header because that is the signal the shop would prefer to
     * have, but it covers everything ExtendedDelivery::detect() consults —
     * which today also means the browser's own time zone in the `kbb_tz`
     * cookie. On this host that second one may be the only signal that ever
     * fires, so the two share a source deliberately: both are guesses about
     * the visitor, neither is something the visitor said, and a caller
     * deciding how much to trust the answer has no reason to tell them apart.
     */
    public const HEADER = 'header';

    /** Nothing knew; this is the shop's own country. */
    public const DEFAULT = 'default';

    /**
     * The session key, and the ONLY one.
     *
     * The checkout had no country session key before this — it carried the
     * choice in `old('billing_country')` and in the saved address — so this is
     * the one new name, rather than a second name beside an existing one.
     */
    public const SESSION_KEY = 'kbb_country';

    private const MEMO_PREFIX = 'kbb.shopper_country.';

    private function __construct(
        public readonly string $code,
        public readonly string $source,
    ) {}

    /**
     * Resolve the visitor's country.
     *
     * @param  string|null  $explicit  A country the caller already knows the
     *   shopper chose — the checkout passes its saved-address country here.
     *   Null from the storefront, which has no such value and must not buy one.
     */
    public static function for(Request $request, ?string $explicit = null): self
    {
        $memoKey = self::MEMO_PREFIX . ($explicit ?? '');

        $memo = $request->attributes->get($memoKey);

        if ($memo instanceof self) {
            return $memo;
        }

        $resolved = self::resolve($request, $explicit);

        $request->attributes->set($memoKey, $resolved);

        return $resolved;
    }

    private static function resolve(Request $request, ?string $explicit): self
    {
        // 1. Stated on this request. old() before the caller's value: a country
        //    just typed into the form outranks the address saved months ago,
        //    which is the precedence CheckoutController::page() already had.
        $old = self::clean(self::sessionAvailable($request) ? $request->old('billing_country') : null);

        if ($old !== null) {
            return new self($old, self::EXPLICIT);
        }

        $given = self::clean($explicit);

        if ($given !== null) {
            return new self($given, self::EXPLICIT);
        }

        // 2. Stated on an earlier request.
        if (self::sessionAvailable($request)) {
            $remembered = self::clean($request->session()->get(self::SESSION_KEY));

            if ($remembered !== null) {
                return new self($remembered, self::SESSION);
            }
        }

        // 3. Guessed from the request. Delegated; see the class header.
        $detected = self::clean(app(ExtendedDelivery::class)->detect($request));

        if ($detected !== null) {
            return new self($detected, self::HEADER);
        }

        // 4. The shop's own country.
        return new self(
            self::clean(app(\App\Services\SettingsService::class)->get('store_country', 'AE')) ?? 'AE',
            self::DEFAULT,
        );
    }

    /**
     * Record a country the shopper actually chose.
     *
     * Called from the checkout's country selector, and nowhere that is
     * guessing. See the SESSION tier in the class header for why that
     * restriction is the point rather than an oversight.
     */
    public static function remember(Request $request, string $code): void
    {
        $clean = self::clean($code);

        if ($clean === null || ! self::sessionAvailable($request)) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, $clean);

        // The memo was taken before this write and would now be stale for the
        // rest of the request.
        foreach (array_keys($request->attributes->all()) as $key) {
            if (str_starts_with((string) $key, self::MEMO_PREFIX)) {
                $request->attributes->remove((string) $key);
            }
        }
    }

    /** Is this a guess rather than something the shopper told us? */
    public function guessed(): bool
    {
        return $this->source === self::HEADER || $this->source === self::DEFAULT;
    }

    /** The country's name, falling back to its code when it is not on the list. */
    public function name(): string
    {
        return Countries::NAMES[$this->code] ?? $this->code;
    }

    /**
     * A two-letter uppercase code, or null.
     *
     * Everything reaching this class is user-controlled — a header, a cookie, a
     * form field, a session written by an older build — so nothing is taken on
     * its word. Anything that is not exactly two letters is not a country code
     * and is dropped rather than passed on to be compared against one.
     */
    private static function clean(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = strtoupper(trim($raw));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * Blade partials render on routes that may have no session at all — the
     * API group has no StartSession — and asking for one there throws. This is
     * what makes the class safe to call from a partial.
     */
    private static function sessionAvailable(Request $request): bool
    {
        return $request->hasSession();
    }
}
