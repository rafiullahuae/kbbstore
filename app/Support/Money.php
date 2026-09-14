<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;

/**
 * Money is stored as integer minor units (fils for AED) everywhere, which
 * removes float drift from every total. Nothing in here converts through a
 * float on the way to the screen.
 *
 * WHAT CHANGED, AND WHAT DELIBERATELY DID NOT
 *
 * The currency used to be the constant below and nothing else. It is now read
 * from settings at call time, so the store can be repriced without a package.
 * The constant stays as the last-resort fallback: an unlisted currency code
 * with no symbol configured still renders something rather than nothing.
 *
 * WITH NO CURRENCY SETTINGS ROWS PRESENT, OUTPUT IS BYTE-FOR-BYTE WHAT IT WAS,
 * apart from the AED symbol itself. That is pinned by a test. Specifically:
 *
 *   - no `currency_decimals` row  -> minor units are hundredths (fils) and the
 *     storefront prints whole dirhams, which is how WooCommerce is configured
 *     on the live site. Set the row and it drives both halves together.
 *   - no `currency_position` row  -> symbol immediately before the number, no
 *     space, exactly as before.
 *   - no `currency_symbol` row    -> Currencies::symbolFor('AED'), which is the
 *     real U+20C3 dirham sign the owner asked for.
 *
 * THE BIDI BUG THIS FIXES
 *
 * The old markup emitted `د.إ199` as bare characters inside an ordinary LTR
 * sentence. Arabic letters are strongly RTL, digits are weakly directional, so
 * the Unicode bidirectional algorithm merged the symbol and the number into one
 * run and reordered them: "Free delivery over د.إ199" rendered as `199|د.إ`,
 * with the symbol on the wrong side. Nothing was wrong with the string — the
 * markup simply never said which way round it went.
 *
 * Both halves are now stated explicitly:
 *
 *   - the whole price carries dir="ltr", which per the HTML rendering rules
 *     also applies `unicode-bidi: isolate`, so the surrounding sentence cannot
 *     reach into it;
 *   - the symbol sits in its own isolate (dir="auto"), so an RTL symbol renders
 *     right-to-left *within its own box* and cannot swap places with the digits
 *     beside it.
 *
 * This matters for any RTL symbol, not just the legacy dirham: ر.ع., د.ك and
 * ₪ all behave the same way.
 */
final class Money
{
    /**
     * Last-resort symbol: the Arabic dirham abbreviation WooCommerce rendered
     * on the live site before U+20C3 existed.
     *
     * Kept as a public constant on purpose. It is the documented fallback when
     * the configured currency is not in the Currencies table and no symbol has
     * been set, and removing it would break anything still reading it.
     */
    /*
     * 'AED' rather than the Arabic 'د.إ' this shipped with, and rather than the
     * U+20C3 dirham sign.
     *
     * The Arabic form reordered around digits inside English sentences -- the
     * delivery bar read "199|د.إ" -- and U+20C3 draws as an empty box on every
     * device whose fonts predate Unicode 18.0, which today is almost all of
     * them. Three Latin letters have neither problem.
     */
    public const SYMBOL = 'AED';

    /** Used when no `currency` row is set. */
    public const DEFAULT_CURRENCY = 'AED';

    /**
     * Where the symbol goes relative to the number. Four values, deliberately
     * a small set — this is a store setting, not a formatting language:
     *
     *   before        $12.00   symbol, no space          (the default)
     *   before_space  $ 12.00  symbol, then a space
     *   after         12.00$   number, then the symbol
     *   after_space   12.00 $  number, a space, symbol   (usual for kr, zł)
     */
    public const POSITIONS = ['before', 'before_space', 'after', 'after_space'];

    /**
     * How the symbol reaches the page.
     *
     *   unicode  the bare character (the default, and what the owner asked
     *            for: correct in the DOM for copy-paste, screen readers and
     *            search engines — but U+20C3 has no glyph in most installed
     *            fonts yet, so it shows as tofu on most devices today)
     *   svg      an inline, self-hosted SVG drawing of the glyph, with the
     *            character itself as its accessible name. Always renders;
     *            costs you a copy-pastable character.
     *
     * Only symbols this repo actually ships a drawing for can use `svg`;
     * everything else falls back to `unicode` rather than rendering nothing.
     */
    public const RENDER_MODES = ['unicode', 'svg'];

    /** Same cache entry Setting::map() fills, so Setting::flushMap() clears it. */
    private const CACHE_KEY = 'kbb.settings.map';

    private const CACHE_TTL = 300;

    /**
     * Resolved currency settings, memoised so one page render is one read.
     *
     * format() is called on the order of a hundred times on /shop, and each
     * call would otherwise be a cache lookup — a file read apiece on this host.
     *
     * Deliberately NOT a bare process-level static, though. Setting::map()
     * keeps one of those and CLAUDE.md records what it costs: inside a
     * long-lived process it never sees a later write, so tests and queue
     * workers read stale values forever. Two bounds instead:
     *
     *   - the container it was resolved against. Under PHP-FPM and in tests
     *     that is one per request and one per test, so neither can inherit the
     *     other's currency;
     *   - wall-clock, capped at the same TTL as the cache entry underneath. A
     *     worker or an Octane process that outlives its container still cannot
     *     be staler than the settings cache it is shadowing.
     *
     * forgetConfig() covers the remaining case, a write made mid-request.
     *
     * @var array{code:string,symbol:string,position:string,render:string,decimals:?int}|null
     */
    private static ?array $memo = null;

    private static ?object $memoFor = null;

    private static int $memoAt = 0;

    /** Drop the memo. Called by SettingsService::set(); also usable from tests. */
    public static function forgetConfig(): void
    {
        self::$memo = null;
        self::$memoFor = null;
        self::$memoAt = 0;
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    /** ISO code of the configured currency, upper-cased. */
    public static function currency(): string
    {
        return self::config()['code'];
    }

    /** The display symbol: the configured override, else the currency's default. */
    public static function symbol(): string
    {
        return self::config()['symbol'];
    }

    /** One of self::POSITIONS. */
    public static function position(): string
    {
        return self::config()['position'];
    }

    /** One of self::RENDER_MODES. */
    public static function symbolRender(): string
    {
        return self::config()['render'];
    }

    /**
     * How many minor units make a major one, as a power of ten.
     *
     * 2 for AED (fils), 0 for JPY, 3 for KWD. With no `currency_decimals` row
     * this is 2, because that is what every integer already in the database
     * means.
     */
    public static function minorExponent(): int
    {
        return self::config()['decimals'] ?? 2;
    }

    /**
     * How many decimals the storefront prints.
     *
     * The same setting drives this and minorExponent() — one number per
     * currency, as it should be. The single asymmetry is the unset case: a
     * store with no currency rows keeps the live site's whole-dirham display
     * (0) while still reading its integers as fils (2). That is not a default
     * anybody would choose from scratch; it is the configuration this store is
     * already running, and changing it silently would reprice every page.
     */
    public static function displayDecimals(): int
    {
        return self::config()['decimals'] ?? 0;
    }

    // -----------------------------------------------------------------
    // Conversion
    // -----------------------------------------------------------------

    /** Minor units (fils) -> major units. */
    public static function toMajor(int $minor): float
    {
        $exp = self::minorExponent();

        return round($minor / (10 ** $exp), $exp);
    }

    /** Major units -> minor units (fils). */
    public static function fromMajor(float|int|string $major): int
    {
        return (int) round(((float) $major) * (10 ** self::minorExponent()));
    }

    /**
     * @deprecated Use toMajor(). Kept so the ~35 existing call sites keep
     *             working; renaming them all in one package is exactly the kind
     *             of wide diff that got 2.60.102–.106 withdrawn.
     */
    public static function toAed(int $fils): float
    {
        return self::toMajor($fils);
    }

    /** @deprecated Use fromMajor(). */
    public static function fromAed(float|int|string $aed): int
    {
        return self::fromMajor($aed);
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    /**
     * Bare number, no symbol — for inputs and data attributes.
     *
     * Formatted straight out of the integer. Passing through a float first
     * would reintroduce the drift the fils representation exists to avoid, and
     * at three decimals (KWD, OMR) it is not theoretical.
     *
     * @param  int|null  $decimals  explicit override; null follows the setting.
     */
    public static function amount(int $minor, ?int $decimals = null): string
    {
        $exp = self::minorExponent();
        $dec = max(0, min(6, $decimals ?? self::displayDecimals()));

        $negative = $minor < 0;
        $abs = $negative ? -$minor : $minor;

        $unit = 10 ** $exp;
        $whole = intdiv($abs, $unit);
        $frac = $abs % $unit;

        if ($dec >= $exp) {
            // Widening: 12.3 KWD at 4 decimals is 12.3000, never a rounding.
            $frac *= 10 ** ($dec - $exp);
        } else {
            // Narrowing: round half away from zero, which is what
            // number_format() did here before and what a price should do.
            $scale = 10 ** ($exp - $dec);
            $frac = intdiv($frac + intdiv($scale, 2), $scale);

            if ($frac >= 10 ** $dec) {          // 0.99 -> 1.0 carries
                $frac -= 10 ** $dec;
                $whole++;
            }
        }

        $out = number_format($whole, 0, '.', ',');

        if ($dec > 0) {
            $out .= '.' . str_pad((string) $frac, $dec, '0', STR_PAD_LEFT);
        }

        return ($negative && ($whole !== 0 || $frac !== 0)) ? '-' . $out : $out;
    }

    /**
     * The full price, wrapped the way WooCommerce wraps it so the theme's price
     * styling applies unchanged.
     *
     * Returns HTML, and every caller echoes it with {!! !!}. The symbol is
     * operator-supplied text arriving from a settings row, so it is escaped
     * here — that is the one place it can be, and without it the currency
     * setting is a stored-XSS field on every page of the store.
     */
    public static function format(int $minor, ?int $decimals = null): string
    {
        return '<span class="woocommerce-Price-amount amount" dir="ltr">'
            . self::compose(self::symbolHtml(), self::amount($minor, $decimals))
            . '</span>';
    }

    /**
     * Plain text, for places that must not contain markup (title tags, JSON,
     * data-* attributes read back by JS).
     *
     * No bidi isolate characters are inserted. They would be invisible but
     * real, and this string is compared, parsed and re-injected by the
     * storefront JS (pdp.js reads data-price straight into the DOM). Text that
     * needs the isolation should go through format(), which has markup to say
     * it with.
     */
    public static function plain(int $minor, ?int $decimals = null): string
    {
        return self::compose(self::symbol(), self::amount($minor, $decimals));
    }

    /** The symbol on its own, as HTML — isolated, escaped, and possibly drawn. */
    public static function symbolHtml(): string
    {
        $symbol = self::symbol();
        $svg = self::symbolRender() === 'svg' ? self::glyphSvg($symbol) : null;

        // dir="auto" makes this an independent bidi isolate (HTML gives any
        // element with a dir attribute `unicode-bidi: isolate`) and lets an
        // Arabic symbol lay itself out RTL inside its own box without dragging
        // the digits next to it along.
        return '<span class="woocommerce-Price-currencySymbol" dir="auto">'
            . ($svg ?? e($symbol))
            . '</span>';
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** Join symbol and number according to the configured position. */
    private static function compose(string $symbol, string $number): string
    {
        return match (self::position()) {
            'before_space' => $symbol . ' ' . $number,
            'after' => $number . $symbol,
            'after_space' => $number . ' ' . $symbol,
            default => $symbol . $number,
        };
    }

    /**
     * An inline drawing of the symbol, or null when we do not ship one.
     *
     * Self-hosted and license-clean by construction: it is a path in this file,
     * not a font from a CDN. Only U+20C3 needs it — every other symbol in the
     * table has been in fonts for decades — so anything else returns null and
     * falls back to the character.
     *
     * THE MARK. U+20C3 is a Latin capital D, not an Arabic glyph and not the
     * old د.إ: an upright monoline bowl-and-stem crossed by two horizontal
     * bars at roughly one third and two thirds of the letter height, each bar
     * running the width of the letter and overhanging the stem a little on the
     * left. Drawn on a 1000-unit em square with the baseline at y=750 and the
     * cap height at y=60, which is what lets it sit on the text baseline.
     *
     * HOW IT SIZES. Stroke is `currentColor`, so it inherits whatever colour
     * the price is — ink, sale red, a badge's reversed white. The box is 1em
     * square and `vertical-align:-.25em` drops it by the quarter-em of descent
     * built into the viewBox, so the stem lands on the baseline with the digits
     * instead of floating. Everything is in em, so it scales with the type from
     * small print to the product page rather than being pinned to a pixel size.
     *
     * ACCESSIBILITY AND COPY-PASTE. The SVG carries role="img" and the real
     * character as its accessible name, so a screen reader announces the
     * character rather than "image". Drawn glyphs cannot be selected, so the
     * character is also present as visually-hidden text (aria-hidden, or it
     * would be announced twice) and copy-paste still yields U+20C3.
     */
    private static function glyphSvg(string $symbol): ?string
    {
        if ($symbol !== Currencies::AED_SIGN) {
            return null;
        }

        $label = e($symbol);

        return '<svg class="kbb-currency-glyph" viewBox="0 0 1000 1000" role="img"'
            . ' aria-label="' . $label . '" focusable="false"'
            . ' style="width:1em;height:1em;vertical-align:-.25em;display:inline-block">'
            . '<title>' . $label . '</title>'
            . '<g fill="none" stroke="currentColor" stroke-linejoin="round">'
            // Stem, then the bowl hung off the top and bottom of it.
            . '<path d="M280 60V750" stroke-width="110"/>'
            . '<path d="M280 60h140c210 0 340 140 340 345s-130 345-340 345H280" stroke-width="110"/>'
            // The two bars: full letter width, overhanging the stem to the left.
            . '<path d="M130 290h590M130 520h590" stroke-width="90"/>'
            . '</g></svg>'
            . '<span aria-hidden="true" style="position:absolute;width:1px;height:1px;'
            . 'overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap">'
            . $label . '</span>';
    }

    /**
     * @return array{code:string,symbol:string,position:string,render:string,decimals:?int}
     */
    private static function config(): array
    {
        $container = Container::getInstance();

        if (self::$memo !== null
            && self::$memoFor === $container
            && (time() - self::$memoAt) < self::CACHE_TTL) {
            return self::$memo;
        }

        $map = self::settings();

        $code = strtoupper(trim((string) ($map['currency'] ?? '')));
        if ($code === '') {
            $code = self::DEFAULT_CURRENCY;
        }

        $symbol = trim((string) ($map['currency_symbol'] ?? ''));
        if ($symbol === '') {
            $symbol = Currencies::symbolFor($code, self::SYMBOL);
        }

        /*
         * With no explicit choice, the space depends on the symbol rather than
         * being fixed. A letter-based symbol needs one -- 'AED199' is wrong and
         * 'AED 199' is how the live WooCommerce site reads -- while a glyph does
         * not: '$ 199' would be equally wrong. The test is the last character,
         * so 'AED', 'CHF' and 'kr' get a space and '$', '£', '⃃' do not.
         */
        $position = trim((string) ($map['currency_position'] ?? ''));
        if (! in_array($position, self::POSITIONS, true)) {
            $lastChar = mb_substr($symbol, -1);
            $position = preg_match('/[\p{L}\p{N}]/u', $lastChar) === 1 ? 'before_space' : 'before';
        }

        $render = trim((string) ($map['currency_symbol_render'] ?? ''));
        if (! in_array($render, self::RENDER_MODES, true)) {
            $render = 'unicode';
        }

        // null means "no row" — see displayDecimals() for why that is not the
        // same thing as 0 or 2.
        $rawDecimals = $map['currency_decimals'] ?? null;
        $decimals = ($rawDecimals === null || trim((string) $rawDecimals) === '')
            ? null
            : max(0, min(4, (int) $rawDecimals));

        self::$memoFor = $container;
        self::$memoAt = time();

        return self::$memo = [
            'code' => $code,
            'symbol' => $symbol,
            'position' => $position,
            'render' => $render,
            'decimals' => $decimals,
        ];
    }

    /**
     * The settings map, read from the same cache entry Setting::map() fills so
     * Setting::flushMap() invalidates both. Reading the cache rather than
     * Setting::map() itself sidesteps that method's process-level static.
     */
    private static function settings(): array
    {
        try {
            $raw = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, static function (): array {
                $out = [];

                foreach (Setting::query()->get(['key', 'value']) as $row) {
                    $out[(string) $row->key] = $row->value;
                }

                return $out;
            });
        } catch (\Throwable) {
            // No database yet (an early migration, a console command before
            // install). Defaults are a correct answer here; an exception is not.
            return [];
        }

        return is_array($raw) ? $raw : [];
    }
}
