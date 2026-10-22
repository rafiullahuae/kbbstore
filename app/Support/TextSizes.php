<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Text sizes on the cart panel, the cart page and the checkout (Lane CK,
 * 22 October 2026): the three things the three screens share, in one place so
 * they cannot drift apart.
 *
 *   1. ARABIC VALUES. "Same as English" is the absence of a value: the Arabic
 *      map holds only the keys the owner has moved on the Arabic tab, so a shop
 *      that never opens it stores nothing and prints nothing new. On an /ar
 *      page the map is laid over the English values before anything is
 *      emitted; on every other page it is never read.
 *
 *   2. FACTORS. A size role is stored as a percentage of what the page draws
 *      today (100 = today); Overall · Text % and Spacing % are stored as the
 *      signed change (0 = today, -10..+25). Both become the unitless factor
 *      the stylesheet multiplies by: calc(12px*var(--x,1)).
 *
 *   3. SMALL PHONES. "If we set any ratio, on low-res screens users get
 *      too-big fonts." Pure CSS: for every MOBILE size the owner has set ABOVE
 *      today, the same property again inside @media (max-width:374px) and
 *      (max-width:340px), reduced by the owner's percentage but never below
 *      today's size. Computed here, once, from numbers already in memory; the
 *      browser only switches a media query. Nothing at all is printed while
 *      no mobile size is above today, so the default page is byte-identical.
 *      `!important` because the values it overrides sit in an inline style
 *      attribute, and an important author rule is the one thing that beats a
 *      normal inline declaration.
 */
final class TextSizes
{
    /** The two small-phone widths, each "and below". */
    public const SMALL_1 = 374;

    public const SMALL_2 = 340;

    /** Is this request an Arabic page? Reads the framework locale; no query. */
    public static function arabic(): bool
    {
        return Locale::current() === 'ar';
    }

    /**
     * The stored Arabic map, cleaned: only keys in $allowed, each forced
     * through the screen's own cast (so a crafted row is clamped exactly like
     * a slider), anything else dropped.
     *
     * @param  list<string>  $allowed
     * @param  callable(string, mixed): mixed  $cast
     * @return array<string, int>
     */
    public static function arMap(mixed $raw, array $allowed, callable $cast): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $raw) && is_numeric($raw[$key])) {
                $out[$key] = (int) $cast($key, $raw[$key]);
            }
        }

        return $out;
    }

    /**
     * $c with the Arabic values laid over it — on an Arabic page only.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, int>  $ar
     * @return array<string, mixed>
     */
    public static function overlay(array $c, array $ar): array
    {
        if ($ar === [] || ! self::arabic()) {
            return $c;
        }

        foreach ($ar as $key => $value) {
            if (array_key_exists($key, $c)) {
                $c[$key] = $value;
            }
        }

        return $c;
    }

    /**
     * A stored value in THOUSANDTHS of what it prints, by kind — integers, so
     * the server and the admin preview (which does the same sums in
     * JavaScript) cannot round a tie differently:
     *
     *   pct  115 -> 1150   (prints 1.15, a share of today's size)
     *   ov    15 -> 1150   (prints 1.15, Overall: the signed change)
     *   px    13 -> 13000  (prints 13px)
     *   px10 125 -> 12500  (prints 12.5px, stored in tenths of a pixel)
     */
    public static function milli(string $kind, int $value): int
    {
        return match ($kind) {
            'pct' => $value * 10,
            'ov' => (100 + $value) * 10,
            'px' => $value * 1000,
            'px10' => $value * 100,
            default => throw new \InvalidArgumentException('unknown kind '.$kind),
        };
    }

    /** Thousandths back to CSS: 1093 -> "1.093", 14250 -> "14.25px". */
    public static function fromMilli(string $kind, int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $abs = abs($milli);
        $frac = rtrim(str_pad((string) ($abs % 1000), 3, '0', STR_PAD_LEFT), '0');
        $out = $sign.intdiv($abs, 1000).($frac === '' ? '' : '.'.$frac);

        return in_array($kind, ['px', 'px10'], true) ? $out.'px' : $out;
    }

    /** The value a stored setting prints: emit('ov', 15) is "1.15". */
    public static function emit(string $kind, int $value): string
    {
        return self::fromMilli($kind, self::milli($kind, $value));
    }

    /**
     * The small-phone block, or '' when nothing on a phone is above today.
     *
     * A row with a selector (its 5th entry) is a size printed as a RULE rather
     * than a custom property — the cart page's own product-row sizes — and is
     * written as that rule again, times the page's text factor.
     *
     * The admin preview (shop-type-live) builds this same string in the same
     * order; LaneCkTextSizesTest holds the two to each other.
     *
     * @param  string  $scope  the selector of the element that carries the inline style
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4?: string}>  $rows  [property, kind, value, today, selector]
     */
    public static function fitCss(string $scope, array $rows, int $step1, int $step2, string $textVar = ''): string
    {
        $out = '';

        foreach ([[self::SMALL_1, $step1], [self::SMALL_2, $step2]] as [$width, $cut]) {
            if ($cut <= 0) {
                continue;
            }

            $decls = [];
            $rules = [];

            foreach ($rows as $row) {
                [$prop, $kind, $value, $today] = $row;

                if ((int) $value <= (int) $today) {
                    continue; // at or below today: never reduced
                }

                $size = self::fromMilli($kind, self::reducedMilli($kind, (int) $value, (int) $today, $cut));

                if (isset($row[4])) {
                    $rules[] = $row[4].'{font-size:calc('.$size.'*var('.$textVar.',1))!important}';
                } else {
                    $decls[] = $prop.':'.$size.'!important';
                }
            }

            if ($decls !== []) {
                $out .= '@media (max-width:'.$width.'px){'.$scope.'{'.implode(';', $decls).'}}';
            }

            if ($rules !== []) {
                $out .= '@media (max-width:'.$width.'px){'.implode('', $rules).'}';
            }
        }

        return $out;
    }

    /**
     * One stored value reduced by $cut percent, in thousandths, never below
     * today's. Rounded half up on integers: (m * (100 - cut) + 50) div 100.
     * For `ov` the cut comes off the FACTOR (1.15 -> 1.093), so "5% smaller"
     * means 5% smaller text, not 5% of the change.
     */
    public static function reducedMilli(string $kind, int $value, int $today, int $cut): int
    {
        $m = self::milli($kind, $value);

        return max(self::milli($kind, $today), intdiv($m * (100 - $cut) + 50, 100));
    }
}
