<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Which controls on Appearance → Set can be previewed WITHOUT asking the server.
 *                                                                    (Lane SA3)
 *
 * The owner, verbatim: *"preview must work in real time upon changing
 * controls."* A slider fires `input` on every pixel of a drag, so "real time"
 * cannot mean a request per pixel — it has to mean the browser re-resolving CSS
 * it already has.
 *
 * Almost all of this screen already is that. SetAppearance::css() is, for the
 * most part, two declaration blocks of CUSTOM PROPERTIES — `.kset.kset{…}` and
 * `.ksl.ksl{…}` plus a phone block for each — and the two storefront partials
 * read every tunable number as `var(--kset-x, <its old literal>)`. So a live
 * preview is one string: re-emit those same declarations from the values in the
 * buffer and let the frame's own cascade do the rest.
 *
 * ── THE MAP IS DERIVED FROM css(), NEVER RESTATED BESIDE IT ─────────────────
 *
 * The obvious way to write this is a table — `'p_photo' => '--ksl-ph'`, 190
 * times. That table is a SECOND COPY of what boxVars() and listVars() already
 * say, it is the copy nobody edits when the first one changes, and this repo
 * has already paid for exactly that shape twice (HomepageLayouts::summaries(),
 * and the preview markup the admin used to keep its own version of).
 *
 * So nothing here is written down. For every field this class:
 *
 *   1. renders the real SetAppearance::css() at the shipped defaults,
 *   2. renders it again with that ONE field moved,
 *   3. diffs the two declaration-by-declaration.
 *
 * What comes back is the property that field really drives, the selector and
 * media query it really sits in, and its position in the sheet. If the two
 * renders differ in more than one declaration, or in a declaration that is not
 * a custom property, the field is simply LEFT OUT of the map and the screen
 * falls back to asking the server. That is the fail-closed direction: a field
 * this class cannot explain is a field it does not claim.
 *
 * ── AND THE NUMBER FORMAT IS FITTED, NOT ASSUMED ───────────────────────────
 *
 * Three of the list's sizes are stored in tenths of a pixel, a dozen ratios in
 * hundredths, the circles' overlap is stored positive and emitted negative, and
 * the heading's letter-spacing carries `em`. Rather than restate any of that,
 * this class probes each field at THREE values and fits the only shape all of
 * them can be: `(neg ? '-' : '') . (value / div) . suffix`. The fit is then
 * VERIFIED against the real output at all three probes, and a field whose
 * output the fit cannot reproduce exactly is dropped. A wrong guess therefore
 * cannot ship as a right one — it can only shrink the fast path.
 *
 * `div` is always 1, 10 or 100, so `value / div` has at most two decimals and
 * JavaScript's `String(v / div)` and this class's format() agree byte for byte
 * on every value a `range` can hold. SetAppearanceLiveMapTest pins that for
 * every field in the map, at every step of every slider.
 *
 * ── WHAT IS DELIBERATELY NOT IN THE MAP ────────────────────────────────────
 *
 * Everything a custom property cannot do, which is the same list css() names:
 *
 *   the on/off switches   they add or remove a `display:none` RULE, and one of
 *                         them (`p_panel_on`) changes the class on `.ksl`
 *   `fan_max`             an `nth-child()` in a SELECTOR
 *   `p_fold_at`           a server decision: the rows past the fold are inside
 *                         a <details> in the MARKUP
 *   the three breakpoints they are the media queries' own text
 *   the five `ci_*`       `.kbb-cartpage .ci.ci-set`'s padding and gap are real
 *                         properties in a compiled stylesheet, not variables
 *
 * Each of those re-renders through the endpoint, debounced. None of them is a
 * drag the owner does forty times a second.
 */
final class SetAppearanceLiveMap
{
    /** Divisors css() can be fitted with. 1, tenths, hundredths — and no more. */
    private const DIVISORS = [1, 10, 100];

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    /** @var array<string, string|null> */
    private static array $mqMemo = [];

    /**
     * The map the screen drives its live preview from.
     *
     * Shape, and it is the shape the JavaScript in
     * admin/partials/set-appearance-screen.blade.php reads:
     *
     *   [
     *     'blocks' => [ ['sel' => '.kset.kset',
     *                    'mq'  => null | 'bp',
     *                    'x'   => false],                 // in SHEET ORDER
     *     'fields' => [ 'p_photo' => ['b' => 1,           // index into blocks
     *                                 'p' => '--ksl-ph',
     *                                 'd' => 1,           // divisor
     *                                 'n' => false,       // negate
     *                                 's' => 'px'],       // suffix
     *                   'p_panel_bg' => ['b' => 1, 'p' => '--ksl-pbg',
     *                                 'c' => true],       // a colour
     *                   … ],
     *   ]
     *
     * ORDER MATTERS AND IS WHY `blocks` IS A LIST RATHER THAN A DICTIONARY.
     * The overlay the screen builds is appended AFTER the sheet css() produced,
     * so for a property declared in both the laptop block and the phone block
     * the LAST declaration the overlay emits is the one that wins inside the
     * media query. Emitting the blocks in the sheet's own order is what makes
     * the overlay's cascade identical to the sheet's; emitting the phone block
     * first would pin every phone value to its laptop one the moment a slider
     * moved, which is the one thing this screen exists to keep apart.
     *
     * `mq` NAMES THE BREAKPOINT FIELD RATHER THAN CARRYING ITS TEXT, and that
     * is not tidiness either. This map is built once, at the shipped defaults,
     * and the owner can move all three breakpoints — so a block that carried
     * the literal `@media (max-width:760px)` would keep writing 760 into the
     * overlay after he had dragged the set box's turnover to 800, and the phone
     * values would apply over the wrong 40 pixels. Which field each media query
     * belongs to is DERIVED the same way everything else here is: the field is
     * moved, and the prelude that moves with it is its own.
     *
     * @return array{blocks: list<array{sel: string, mq: string|null, x: bool}>, fields: array<string, array<string, mixed>>}
     */
    public static function build(): array
    {
        if (self::$memo !== null) {
            /** @var array{blocks: list<array{sel: string, mq: string|null, x: bool}>, fields: array<string, array<string, mixed>>} */
            return self::$memo;
        }

        /*
         * ── IT IS CACHED, AND THE KEY IS THE CODE ITSELF ───────────────────
         *
         * Deriving the map renders SetAppearance::css() about 750 times, which
         * is ~135ms on this machine and several times that on the shop's own
         * box — every time the owner opens Appearance → Set. That is worth
         * caching, and it is worth caching in the one way that cannot go
         * stale: the key is a hash of the two files the answer is derived
         * from, so a package that changes either one lands on a DIFFERENT key
         * and the old entry is simply never read again. Nothing has to
         * remember to flush it, which is the failure mode CLAUDE.md records
         * for Setting::map().
         *
         * A cache that is unavailable, or that hands back something of the
         * wrong shape, costs 135ms and nothing else.
         */
        /* `$cacheKey` AND NOT `$key`: the loop below walks the schema as
           `foreach (… as $key => $spec)`, so a variable called $key here is a
           different string by the time the write at the bottom of this method
           runs. Measured — the map was written under 'ci_name_gap_m' and every
           read missed, so the cache cost a hash and bought nothing. */
        $cacheKey = 'setap.livemap.'.hash(
            'sha256',
            (string) file_get_contents(__FILE__)
            .(string) file_get_contents((string) (new \ReflectionClass(SetAppearance::class))->getFileName())
        );

        try {
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && isset($cached['blocks'], $cached['fields'])) {
                /** @var array{blocks: list<array{sel: string, mq: string|null, x: bool}>, fields: array<string, array<string, mixed>>} */
                return self::$memo = $cached;
            }
        } catch (\Throwable) {
            // No cache today; the answer is the same, it just costs more.
        }

        $defaults = SetAppearance::defaults();
        $base = self::declarations(SetAppearance::css($defaults));

        $shape = self::shape($base);
        $blocks = [];
        $blockIndex = [];
        $fields = [];

        foreach ($shape as $i => $block) {
            $mq = self::breakpointOf($block['media'], $shape, $defaults);

            $blockIndex[$block['media']."\0".$block['sel']] = $i;
            $blocks[] = [
                'sel' => $block['sel'],
                'mq' => $mq,
                /*
                 * A MEDIA QUERY NO FIELD EXPLAINS. The screen rebuilds a
                 * block's prelude from the breakpoint it belongs to, so a
                 * prelude it cannot rebuild is one it must not emit — emitting
                 * the block without its query would apply the phone's values
                 * at every width. `x` marks it, every field inside it is
                 * dropped from the map below, and those fields re-render
                 * through the endpoint like the other structural ones.
                 */
                'x' => $block['media'] !== '' && $mq === null,
            ];
        }

        foreach (SetAppearance::SCHEMA as $key => $spec) {
            $type = (string) $spec[0];

            if ($type === 'colour') {
                $entry = self::fitColour($key, $defaults, $base);
            } elseif ($type === 'range') {
                $entry = self::fitRange($key, $spec, $defaults, $base);
            } else {
                $entry = null;
            }

            if ($entry === null) {
                continue;
            }

            $id = $entry['media']."\0".$entry['sel'];

            // A property that only ever appears once the field is moved (an
            // empty colour) can in principle land in a block the defaults do
            // not open. Give it one rather than dropping the field.
            if (! array_key_exists($id, $blockIndex)) {
                $mq = self::breakpointOf($entry['media'], $shape, $defaults);

                $blockIndex[$id] = count($blocks);
                $blocks[] = [
                    'sel' => $entry['sel'],
                    'mq' => $mq,
                    'x' => $entry['media'] !== '' && $mq === null,
                ];
            }

            if ($blocks[$blockIndex[$id]]['x']) {
                continue;
            }

            unset($entry['media'], $entry['sel']);
            $entry['b'] = $blockIndex[$id];
            $fields[$key] = $entry;
        }

        $out = ['blocks' => $blocks, 'fields' => $fields];

        try {
            Cache::forever($cacheKey, $out);
        } catch (\Throwable) {
            // Same again: a cache that will not take it changes nothing.
        }

        return self::$memo = $out;
    }

    /** Only for tests, which build the map against several sets of values. */
    public static function forget(): void
    {
        self::$memo = null;
        self::$mqMemo = [];
    }

    /**
     * `(neg ? '-' : '') . (value / div) . suffix`, in PHP, byte for byte as
     * JavaScript's `String(value / div)` writes it.
     *
     * `div` is 1, 10 or 100 and `value` is an integer out of a clamped range,
     * so the quotient has at most two decimal places and both languages print
     * the shortest exact decimal. number_format() is used rather than a plain
     * cast because PHP would otherwise print `1.0E-5`-shaped output for small
     * quotients and would honour the locale's decimal separator.
     */
    public static function format(int $value, int $div, bool $neg, string $suffix): string
    {
        if ($div === 1) {
            $out = (string) $value;
        } else {
            $places = $div === 10 ? 1 : 2;
            $out = rtrim(rtrim(number_format($value / $div, $places, '.', ''), '0'), '.');

            if ($out === '' || $out === '-') {
                $out = '0';
            }
        }

        return ($neg ? '-' : '').$out.$suffix;
    }

    /* ══════════════════════════════════════════════ where the blocks are ══ */

    /**
     * The sheet's blocks, in source order, each named once.
     *
     * @param  list<array{media: string, sel: string, prop: string, value: string}>  $declarations
     * @return list<array{media: string, sel: string}>
     */
    private static function shape(array $declarations): array
    {
        $out = [];
        $seen = [];

        foreach ($declarations as $decl) {
            $id = $decl['media']."\0".$decl['sel'];

            if (! array_key_exists($id, $seen)) {
                $seen[$id] = true;
                $out[] = ['media' => $decl['media'], 'sel' => $decl['sel']];
            }
        }

        return $out;
    }

    /**
     * Which field's slider writes this media query's width, or null for the
     * blocks that are not inside one.
     *
     * Derived, not matched on the number: each candidate is moved on its own
     * and the prelude that moves with it — and reads back as exactly
     * `@media (max-width:<that value>px)`, which is the one template the screen
     * knows how to rebuild — is that field's. A prelude no single field
     * explains keeps its literal text, and the screen then leaves that block
     * out of the overlay rather than guessing at it.
     *
     * EVERY prelude is resolved in ONE pass over the fields, not one pass per
     * prelude. There are three media queries and about 160 sliders, and the
     * probe is a whole css() render: asking per prelude scanned the schema
     * three times over and cost 480 renders where 160 answer all three. On the
     * measurement that mattered it took this class from 146ms to a third of
     * that, on a screen the owner opens from a menu.
     *
     * @param  list<array{media: string, sel: string}>  $shape
     * @param  array<string, mixed>  $defaults
     */
    private static function breakpointOf(string $media, array $shape, array $defaults): ?string
    {
        if ($media === '') {
            return null;
        }

        if (self::$mqMemo !== []) {
            return self::$mqMemo[$media] ?? null;
        }

        foreach ($shape as $block) {
            if ($block['media'] !== '') {
                self::$mqMemo[$block['media']] = null;
            }
        }

        foreach (SetAppearance::SCHEMA as $key => $spec) {
            if (($spec[0] ?? '') !== 'range') {
                continue;
            }

            $options = is_array($spec[4] ?? null) ? $spec[4] : [];
            $probe = (int) ($defaults[$key]) + max(1, (int) ($options['step'] ?? 1));

            if ($probe > (int) ($options['max'] ?? 0)) {
                continue;
            }

            $moved = self::shape(self::declarations(SetAppearance::css([$key => $probe] + $defaults)));

            if (count($moved) !== count($shape)) {
                continue;
            }

            $hits = [];
            $consistent = true;

            foreach ($shape as $i => $block) {
                if ($moved[$i]['sel'] !== $block['sel']) {
                    $consistent = false;
                    break;
                }

                if ($moved[$i]['media'] === $block['media']) {
                    continue;
                }

                // Every prelude this field moves must move to the one template
                // the screen can rebuild, or the field does not own it.
                if ($moved[$i]['media'] !== sprintf('@media (max-width:%dpx)', $probe)) {
                    $consistent = false;
                    break;
                }

                $hits[] = $block['media'];
            }

            if ($consistent && $hits !== []) {
                foreach ($hits as $prelude) {
                    if ((self::$mqMemo[$prelude] ?? null) === null) {
                        self::$mqMemo[$prelude] = $key;
                    }
                }
            }
        }

        return self::$mqMemo[$media] ?? null;
    }

    /* ═════════════════════════════════════════════════════ the two fitters ══ */

    /**
     * A colour field: it either prints `#RRGGBB` or prints no declaration at
     * all, and both halves are what the screen has to be able to reproduce.
     *
     * The probe is a hex no default uses, so the "it did not move" case cannot
     * be mistaken for a fit. `#ABCDEF` never reaches a stylesheet from here —
     * it is rendered, read back and thrown away.
     *
     * @param  array<string, mixed>  $defaults
     * @param  list<array{media: string, sel: string, prop: string, value: string}>  $base
     * @return array{media: string, sel: string, p: string, c: true}|null
     */
    private static function fitColour(string $key, array $defaults, array $base): ?array
    {
        $changed = self::changedBy($key, '#ABCDEF', $defaults, $base);

        if ($changed === null || $changed['value'] !== '#ABCDEF') {
            return null;
        }

        return [
            'media' => $changed['media'],
            'sel' => $changed['sel'],
            'p' => $changed['prop'],
            'c' => true,
        ];
    }

    /**
     * A numeric field, fitted at three probes and verified at all three.
     *
     * THREE AND NOT TWO. Two points fit a line through anything, and several of
     * these sliders are short enough that two probes can coincide with the
     * shape of a different divisor — `p_ring` runs 0…60 in tenths, and at two
     * points a tenths fit and a whole-pixel fit are not always distinguishable.
     * The third probe is what makes the fit falsifiable rather than merely
     * consistent.
     *
     * @param  array<int|string, mixed>  $spec
     * @param  array<string, mixed>  $defaults
     * @param  list<array{media: string, sel: string, prop: string, value: string}>  $base
     * @return array{media: string, sel: string, p: string, d: int, n: bool, s: string}|null
     */
    private static function fitRange(string $key, array $spec, array $defaults, array $base): ?array
    {
        $options = is_array($spec[4] ?? null) ? $spec[4] : [];
        $min = (int) ($options['min'] ?? 0);
        $max = (int) ($options['max'] ?? 0);
        $step = max(1, (int) ($options['step'] ?? 1));

        if ($max <= $min) {
            return null;
        }

        $probes = self::probes($min, $max, $step, (int) $defaults[$key]);

        if (count($probes) < 3) {
            return null;
        }

        $seen = [];

        foreach ($probes as $probe) {
            $changed = self::changedBy($key, $probe, $defaults, $base);

            if ($changed === null) {
                return null;
            }

            $seen[] = $changed;
        }

        // Every probe must move the SAME declaration, or this field is not one
        // declaration and the screen must not pretend it is.
        foreach ($seen as $one) {
            if ($one['media'] !== $seen[0]['media']
                || $one['sel'] !== $seen[0]['sel']
                || $one['prop'] !== $seen[0]['prop']) {
                return null;
            }
        }

        $suffix = self::suffix($seen[0]['value']);

        foreach (self::DIVISORS as $div) {
            foreach ([false, true] as $neg) {
                $fits = true;

                foreach ($probes as $i => $probe) {
                    if (self::format($probe, $div, $neg, $suffix) !== $seen[$i]['value']) {
                        $fits = false;
                        break;
                    }
                }

                if ($fits) {
                    return [
                        'media' => $seen[0]['media'],
                        'sel' => $seen[0]['sel'],
                        'p' => $seen[0]['prop'],
                        'd' => $div,
                        'n' => $neg,
                        's' => $suffix,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Three values on this slider that are not its default and not each other.
     *
     * They are deliberately spread — near the floor, near the middle, near the
     * ceiling — because a fit that only holds in the middle of the range is a
     * fit that breaks the first time the owner drags something to an end stop.
     *
     * @return list<int>
     */
    private static function probes(int $min, int $max, int $step, int $default): array
    {
        $out = [];

        foreach ([0.17, 0.5, 0.83] as $at) {
            $raw = $min + (int) round(($max - $min) * $at / $step) * $step;

            foreach ([$raw, $raw + $step, $raw - $step, $min, $max] as $candidate) {
                if ($candidate >= $min && $candidate <= $max
                    && $candidate !== $default && ! in_array($candidate, $out, true)) {
                    $out[] = $candidate;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The ONE custom-property declaration that moving `$key` to `$value`
     * changes, or null when it is not exactly one and not a custom property.
     *
     * Keyed on media + selector + property rather than on position, because a
     * colour that ships EMPTY emits no declaration at all until it is set — so
     * a probe can ADD a declaration, and every position after it would appear
     * to have moved.
     *
     * @param  array<string, mixed>  $defaults
     * @param  list<array{media: string, sel: string, prop: string, value: string}>  $base
     * @return array{media: string, sel: string, prop: string, value: string}|null
     */
    private static function changedBy(string $key, mixed $value, array $defaults, array $base): ?array
    {
        $moved = self::declarations(SetAppearance::css([$key => $value] + $defaults));

        $before = [];

        foreach ($base as $decl) {
            $before[$decl['media']."\0".$decl['sel']."\0".$decl['prop']] = $decl['value'];
        }

        $diff = [];

        foreach ($moved as $decl) {
            $id = $decl['media']."\0".$decl['sel']."\0".$decl['prop'];

            if (! array_key_exists($id, $before) || $before[$id] !== $decl['value']) {
                $diff[] = $decl;
            }

            unset($before[$id]);
        }

        // A declaration that DISAPPEARED is a structural change, not a value
        // one, and this class does not claim it.
        if ($before !== [] || count($diff) !== 1 || ! str_starts_with($diff[0]['prop'], '--')) {
            return null;
        }

        return $diff[0];
    }

    /** The trailing unit on a printed value: `px`, `em`, or none. */
    private static function suffix(string $value): string
    {
        foreach (['px', 'em'] as $unit) {
            if (str_ends_with($value, $unit)) {
                return $unit;
            }
        }

        return '';
    }

    /* ═══════════════════════════════════════════════════════ the tiny parser ═ */

    /**
     * Every declaration in a stylesheet this class built, in source order.
     *
     * It parses ONLY what SetAppearance::css() emits — flat rules and one level
     * of `@media` — and it is deliberately not a CSS parser. It is handed a
     * string this application composed out of integers, literals and hexes one
     * method away; there is no author input anywhere in it. Anything it cannot
     * read it drops, which costs a field its fast path and nothing else.
     *
     * @return list<array{media: string, sel: string, prop: string, value: string}>
     */
    private static function declarations(string $css): array
    {
        $out = [];
        $media = '';
        $length = strlen($css);
        $at = 0;
        $prelude = '';

        while ($at < $length) {
            $char = $css[$at];

            if ($char === '{') {
                $selector = trim($prelude);
                $prelude = '';
                $at++;

                if (str_starts_with($selector, '@media')) {
                    $media = $selector;
                    continue;
                }

                $body = '';

                while ($at < $length && $css[$at] !== '}') {
                    $body .= $css[$at];
                    $at++;
                }

                $at++;

                foreach (explode(';', $body) as $declaration) {
                    $colon = strpos($declaration, ':');

                    if ($colon === false) {
                        continue;
                    }

                    $out[] = [
                        'media' => $media,
                        'sel' => $selector,
                        'prop' => trim(substr($declaration, 0, $colon)),
                        'value' => trim(substr($declaration, $colon + 1)),
                    ];
                }

                continue;
            }

            if ($char === '}') {
                $media = '';
                $prelude = '';
                $at++;

                continue;
            }

            $prelude .= $char;
            $at++;
        }

        return $out;
    }
}
