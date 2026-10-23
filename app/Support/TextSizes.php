<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * Text sizes on the cart panel, the cart page and the checkout -- AT ZERO
 * COST TO A SHOP THAT HAS NOT MOVED ONE (Lane CK2, 23 October 2026).
 *
 * Lane CK built every control (Overall · Text % / Spacing %, a size per role,
 * Arabic values, small-phone steps, live previews) by appending a "twin" of
 * every sized rule to kbb.css, kbb-cart.css and kbb-checkout.css and setting
 * factors in the style attributes: +4.9 KB gzip of stylesheet on every page,
 * and size sums on every request, whether or not anything was ever moved. The
 * owner: "I don't want to compromise on speed at all."
 *
 * So the twins live in App\Support\TextSizeTwins (generated once, at build
 * time, by tools/ck2-twins.php) and this class turns the owner's values into
 * ONE small inline block holding only what his values need:
 *
 *   - Overall · Text % / Spacing %: the factor, per device, and every twin of
 *     that kind (the twins compute to the stylesheet's own sizes times it);
 *   - a role moved: its factor on its container, and only the twins whose
 *     elements sit in that container;
 *   - an Arabic value: only on /ar, only where it differs from English;
 *   - small phones: the same values taken down, inside @media blocks, only for
 *     the mobile sizes set ABOVE today and never below today's size.
 *
 * Every selector and property is a constant of the calling service or of the
 * generated table; every value is an integer sum printed as a decimal. No
 * setting string ever reaches the CSS. Plain calc(), var() and media queries:
 * nothing newer than var() sits next to one (CLAUDE.md's landmine).
 *
 * The block is built when the owner SAVES (refresh()) and stored in one
 * settings row read from the request-memoised settings map; a request only
 * picks the English or the Arabic string out of it. With every size at
 * today's value nothing is stored and nothing is printed: the page is byte
 * for byte what it was before Lane CK.
 *
 * The admin's live preview (partials/admin/shop-type-live) builds the same
 * string with the same integer sums from the same table; LaneCk2TextSizesTest
 * runs both and holds them equal.
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
     * A stored value in THOUSANDTHS of what it prints, by kind -- integers, so
     * the server and the admin preview cannot round a tie differently:
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
        $out = self::decimal($milli, 3);

        return in_array($kind, ['px', 'px10'], true) ? $out.'px' : $out;
    }

    /** The value a stored setting prints: emit('ov', 15) is "1.15". */
    public static function emit(string $kind, int $value): string
    {
        return self::fromMilli($kind, self::milli($kind, $value));
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

    /** An integer of 10^-$places as a trimmed decimal: decimal(1265000, 6) is "1.265". */
    private static function decimal(int $n, int $places): string
    {
        $sign = $n < 0 ? '-' : '';
        $abs = abs($n);
        $unit = 10 ** $places;
        $frac = rtrim(str_pad((string) ($abs % $unit), $places, '0', STR_PAD_LEFT), '0');

        return $sign.intdiv($abs, $unit).($frac === '' ? '' : '.'.$frac);
    }

    /*
     * ── THE BLOCK ───────────────────────────────────────────────────────────
     *
     * $spec, a constant of the calling service. The only setting values that
     * reach the CSS are integers: the sizes (through integer sums), the rows'
     * phone width and kbbint(key) in a twin's media query, each cast to int.
     *   surface   key of TextSizeTwins::TABLE
     *   scope     the element carrying the surface's style attribute
     *   bp        phone sizes at this width and below, desktop above it
     *   t, s      the text and spacing factor properties the twins read
     *   dev       ['d' => [text key, spacing key, lock key], 'm' => [...]]
     *   roles     name => [desktop key, mobile key, container selector | null, property | null]
     *   old       key => [property, kind]: sizes the style attribute already
     *             prints (Arabic and small-phone values only)
     *   phoneOld  the `old` keys of the phone (small-phone steps reach only these)
     *   today     key => default, for `phoneOld` and `rules`
     *   rules     key => [selector, kind, 'd'|'m']: sizes printed as rules (the
     *             cart page's product rows), re-printed times the text factor
     *   rulesBp   the key of the setting holding those rules' phone width
     *   rowKeys   key => default: while all sit at default the page prints no
     *             row rules (CartPage::rowCss()), so neither does this
     *   fit       [step-1 key, step-2 key]
     *   extra     [key, value, constant CSS printed while key holds value]
     */

    /**
     * The block for one page language. $en: every key of the surface as
     * all() returns it. $ar: the moved Arabic values ([] on an English page).
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $en
     * @param  array<string, int>  $ar
     */
    public static function css(array $spec, array $en, array $ar = []): string
    {
        $v = $en;
        foreach ($ar as $key => $value) {
            if (array_key_exists($key, $v)) {
                $v[$key] = $value;
            }
        }

        $state = [];
        $textAll = false;
        $spaceAll = false;
        $moved = [];

        foreach (['d', 'm'] as $dev) {
            $f = self::factors($spec, $v, $dev, 0);
            $state[$dev] = $f;
            $textAll = $textAll || $f['t'] !== 1000;
            $spaceAll = $spaceAll || $f['s'] !== 1000;
            foreach ($f['roles'] as $role => $pct) {
                if ($pct !== 1000) {
                    $moved[$role] = true;
                }
            }
        }

        $bp = (int) $spec['bp'];
        $out = '';

        // A. The factors, desktop and phone.
        foreach (['d' => '@media(min-width:'.($bp + 1).'px){', 'm' => '@media(max-width:'.$bp.'px){'] as $dev => $open) {
            $rules = self::stateCss($spec, $state[$dev]);
            if ($rules !== '') {
                $out .= $open.$rules.'}';
            }
        }

        // B. The twins those factors need, in stylesheet order.
        $ctx = null;
        $body = '';
        foreach (TextSizeTwins::TABLE[$spec['surface']] as [$at, $sel, $t, $s, $roles]) {
            $decls = [];
            if ($t !== '' && ($textAll || array_intersect_key(array_flip($roles), $moved) !== [])) {
                $decls[] = $t;
            }
            if ($s !== '' && $spaceAll) {
                $decls[] = $s;
            }
            if ($decls === []) {
                continue;
            }
            if ($at !== $ctx) {
                $out .= self::wrap($ctx, $body, $en);
                [$ctx, $body] = [$at, ''];
            }
            $body .= $sel.'{'.implode(';', $decls).'}';
        }
        $out .= self::wrap($ctx, $body, $en);

        // C. Arabic values of the sizes the style attribute prints.
        $arOld = [];
        foreach ($spec['old'] ?? [] as $key => [$prop, $kind]) {
            if ((int) $v[$key] !== (int) $en[$key]) {
                $arOld[] = $prop.':'.self::emit($kind, (int) $v[$key]).'!important';
            }
        }
        if ($arOld !== []) {
            $out .= $spec['scope'].'{'.implode(';', $arOld).'}';
        }

        // D. Sizes printed as rules: again, times the text factor, whenever
        //    Overall moves or Arabic differs.
        if (($spec['rules'] ?? []) !== []) {
            $differs = false;
            foreach ($spec['rules'] as $key => $rule) {
                $differs = $differs || (int) $v[$key] !== (int) $en[$key];
            }
            // The page prints its row rules only once a row size moved
            // (CartPage::rowCss()); until then the stylesheet draws them and
            // Overall reaches them through the twins.
            $printed = false;
            foreach ($spec['rowKeys'] as $key => $today) {
                $printed = $printed || (int) $en[$key] !== (int) $today;
            }
            if ($differs || ($textAll && $printed)) {
                $desk = '';
                $phone = '';
                foreach ($spec['rules'] as $key => [$sel, $kind, $dev]) {
                    $rule = $sel.'{font-size:calc('.self::emit($kind, (int) $v[$key]).'*var('.$spec['t'].',1))}';
                    $dev === 'm' ? $phone .= $rule : $desk .= $rule;
                }
                $out .= $desk.($phone === '' ? '' : '@media (max-width:'.(int) $en[$spec['rulesBp']].'px){'.$phone.'}');
            }
        }

        // E. Small phones: every phone size above today, taken down.
        foreach ([[self::SMALL_1, (int) $v[$spec['fit'][0]]], [self::SMALL_2, (int) $v[$spec['fit'][1]]]] as [$width, $cut]) {
            if ($cut <= 0) {
                continue;
            }
            $inner = '';
            $cutState = self::factors($spec, $v, 'm', $cut);
            if ($cutState !== $state['m']) {
                $inner .= self::stateCss($spec, $cutState, $state['m']);
            }
            $old = [];
            foreach ($spec['old'] ?? [] as $key => [$prop, $kind]) {
                if (! in_array($key, $spec['phoneOld'], true)) {
                    continue;
                }
                $today = (int) $spec['today'][$key];
                if ((int) $v[$key] > $today) {
                    $old[] = $prop.':'.self::fromMilli($kind, self::reducedMilli($kind, (int) $v[$key], $today, $cut)).'!important';
                }
            }
            if ($old !== []) {
                $inner .= $spec['scope'].'{'.implode(';', $old).'}';
            }
            foreach ($spec['rules'] ?? [] as $key => [$sel, $kind, $dev]) {
                $today = (int) $spec['today'][$key];
                if ($dev === 'm' && (int) $v[$key] > $today) {
                    $inner .= $sel.'{font-size:calc('.self::fromMilli($kind, self::reducedMilli($kind, (int) $v[$key], $today, $cut)).'*var('.$spec['t'].',1))!important}';
                }
            }
            if ($inner !== '') {
                $out .= '@media (max-width:'.$width.'px){'.$inner.'}';
            }
        }

        // F. A constant look switched by a select (the checkout's pink label).
        foreach ($spec['extra'] ?? [] as [$key, $value, $css]) {
            if (($v[$key] ?? null) === $value) {
                $out .= $css;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $en */
    private static function wrap(?string $ctx, string $body, array $en): string
    {
        if ($body === '') {
            return '';
        }
        if ($ctx === null || $ctx === '') {
            return $body;
        }

        // An integer setting the stylesheet's own media query reads (the
        // squeezed cart's desktop width): its saved value, as an integer.
        $ctx = (string) preg_replace_callback('/kbbint\((\w+)\)/', static fn (array $k): string => (string) (int) ($en[$k[1]] ?? 0), $ctx);

        // A nested context ("@media …@supports …") opens one block per level.
        preg_match_all('/@[^@]+/', $ctx, $m);

        return implode('{', array_map('trim', $m[0])).'{'.$body.str_repeat('}', count($m[0]));
    }

    /**
     * One device's factors, in thousandths: Overall text / spacing and each
     * role. $cut > 0 takes every value above today down by that percentage.
     *
     * @return array{t: int, s: int, roles: array<string, int>}
     */
    private static function factors(array $spec, array $v, string $dev, int $cut): array
    {
        [$textKey, $spaceKey, $lockKey] = $spec['dev'][$dev];
        $text = (int) $v[$textKey];
        $space = $v[$lockKey] ? $text : (int) $v[$spaceKey];
        $ov = static fn (int $x): int => $cut > 0 ? self::reducedMilli('ov', $x, 0, $cut) : self::milli('ov', $x);
        $roles = [];

        foreach ($spec['roles'] as $role => $r) {
            $x = (int) $v[$dev === 'd' ? $r[0] : $r[1]];
            $roles[$role] = $cut > 0 ? self::reducedMilli('pct', $x, 100, $cut) : self::milli('pct', $x);
        }

        return ['t' => $ov($text), 's' => $ov($space), 'roles' => $roles];
    }

    /**
     * The rules that set one device's factors: the scope, then the role
     * containers. With $base (a small-phone step), every factor $base printed
     * is printed again even where the step brought it back to 1, or the
     * phone's own value would still apply inside the step.
     */
    private static function stateCss(array $spec, array $f, ?array $base = null): string
    {
        $moved = static fn (int $now, ?int $was): bool => $now !== 1000 || ($was !== null && $was !== 1000);
        $root = [];
        if ($moved($f['t'], $base['t'] ?? null)) {
            $root[] = $spec['t'].':'.self::decimal($f['t'], 3);
        }
        if ($moved($f['s'], $base['s'] ?? null)) {
            $root[] = $spec['s'].':'.self::decimal($f['s'], 3);
        }

        $containers = '';
        $anyRole = false;
        foreach ($spec['roles'] as $role => $r) {
            if ($r[3] !== null) {
                // A role that is its own property (the lifted label): set on the scope.
                if ($moved($f['roles'][$role], $base['roles'][$role] ?? null)) {
                    $root[] = $r[3].':'.self::decimal($f['roles'][$role], 3);
                }
            } elseif ($moved($f['roles'][$role], $base['roles'][$role] ?? null)) {
                $anyRole = true;
            }
        }

        // Every container once any role moved, because containers nest (a note
        // row is a row): each must set its own factor or it would inherit its
        // parent's.
        if ($anyRole) {
            foreach ($spec['roles'] as $role => $r) {
                if ($r[2] !== null) {
                    $containers .= $r[2].'{'.$spec['t'].':'.self::decimal($f['t'] * $f['roles'][$role], 6).'}';
                }
            }
        }

        return ($root === [] ? '' : $spec['scope'].'{'.implode(';', $root).'}').$containers;
    }

    /*
     * ── THE STORED BLOCK ─────────────────────────────────────────────────────
     */

    /**
     * Build and store the block of one surface: both languages, under the
     * table's version. Nothing stored (null) while both are empty, which is
     * how it ships.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $en
     * @param  array<string, int>  $ar
     */
    public static function refresh(SettingsService $settings, string $key, array $spec, array $en, array $ar): void
    {
        $css = self::css($spec, $en);
        $arCss = $ar === [] ? $css : self::css($spec, $en, $ar);

        $value = $css === '' && $arCss === '' ? null : ['v' => TextSizeTwins::VERSION, 'en' => $css, 'ar' => $arCss];

        // Unchanged (most saves: a shop at the defaults stays with no row at
        // all) writes nothing.
        if ($settings->get($key) !== $value) {
            $settings->set($key, $value);
        }
    }

    /**
     * This request's block: one lookup in the memoised settings map, and
     * nothing else -- '' at the defaults. A block stored under an older table
     * (a package changed a stylesheet) is rebuilt for the request by $rebuild
     * until the next Save stores it again.
     *
     * @param  \Closure(): array{en: string, ar: string}  $rebuild
     */
    public static function stored(SettingsService $settings, string $key, \Closure $rebuild): string
    {
        $c = $settings->get($key);

        if ($c === null || $c === '') {
            return '';
        }

        $lang = self::arabic() ? 'ar' : 'en';

        if (is_array($c) && ($c['v'] ?? null) === TextSizeTwins::VERSION) {
            return is_string($c[$lang] ?? null) ? $c[$lang] : '';
        }

        return $rebuild()[$lang];
    }
}
