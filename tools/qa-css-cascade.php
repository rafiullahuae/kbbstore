<?php

declare(strict_types=1);

/**
 * Lane QA — a control whose property is emitted and then overridden.
 *
 *     php tools/qa-css-cascade.php
 *
 * ── THE DEFECT IT FINDS ─────────────────────────────────────────────────────
 *
 * Thirteen Appearance → Set sliders saved correctly, emitted the right custom
 * property, and moved nothing, because a LATER rule at the same selector and the
 * same specificity re-declared the property they fed. Every check this project
 * had was blind to it: the setting had a writer, the writer had a reader, the
 * reader emitted a `var()`, and the cascade threw the answer away.
 *
 * A reflection sweep over the SCHEMA classes cannot see this, and neither can a
 * grep -- the only thing that can is reading the stylesheet IN ORDER.
 *
 * ── WHAT IT REPORTS ─────────────────────────────────────────────────────────
 *
 * Every declaration that reads a custom property and is later overridden, in
 * the same file, at the same media context, at the same normalised selector, by
 * a declaration that does NOT read it and whose specificity is greater or equal.
 *
 * ── WHY THIS IS A TOOL AND NOT A TEST ───────────────────────────────────────
 *
 * The shape is legitimate about as often as it is a bug: `.mega{box-shadow:
 * var(--sh-l)}` in a base block and `.mega{box-shadow:0 22px 48px …}` in a
 * refinement block below it is a deliberate restyle, not a dead control. Four of
 * the five hits when this was written were exactly that. So the output is a
 * reading list and the judgement is a human's.
 *
 * The one that was not, and is reported rather than changed (it moves no pixel
 * today, and `resources/css/kbb/**` belongs to the RTL lane):
 *
 *   kbb.css:181  body{font-family:var(--sans);…;background:var(--bg);line-height:1.5}
 *   kbb.css:874  body{margin:0;background:#fff;color:var(--ink);font:400 14px/1.6 Poppins,…}
 *
 * Line 874 sits inside the block headed "HOMEPAGE — ported from the approved
 * preview, scoped under .kbb-home" and is the one rule in it that is NOT scoped.
 * Same selector, same specificity, later: so `--bg` and `--sans` are both dead
 * at body level on EVERY page, not just the homepage. `--bg` is `#fff` and the
 * `font:` shorthand names Poppins, so nothing renders differently today -- but
 * `--sans` has exactly one reader in the whole storefront and that reader is
 * this dead declaration, which means the Arabic font stack
 * (`html[lang="ar"]{--sans:"Poppins","Cairo",…}` in layouts/store.blade.php)
 * reaches the body only through the separate explicit rule on the next line.
 * Delete that line believing the token covers it and Arabic loses Cairo.
 */

/** (ids, classes+attributes+pseudo-classes, elements) — enough to compare two rules. */
function qaSpecificity(string $selector): array
{
    $s = (string) preg_replace('/\[[^\]]*\]/', ' ATTR ', $selector);

    return [
        preg_match_all('/#[\w-]+/', $s),
        preg_match_all('/\.[\w-]+/', $s) + preg_match_all('/ATTR/', $s)
            + preg_match_all('/:(?!:)(?!hover|focus|active|visited|not|is|where)[\w-]+/', $s),
        preg_match_all('/(?:^|[\s>+~])([a-zA-Z][\w-]*)/', $s),
    ];
}

function qaSpecificityAtLeast(array $a, array $b): bool
{
    for ($i = 0; $i < 3; $i++) {
        if ($a[$i] > $b[$i]) return true;
        if ($a[$i] < $b[$i]) return false;
    }

    return true;
}

/** Every declaration in a stylesheet, in cascade order, with its media context. */
function qaDeclarations(string $css, string $file): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    $out = [];
    $stack = [];
    $buffer = '';
    $order = 0;
    $length = strlen($css);

    for ($i = 0; $i < $length; $i++) {
        $char = $css[$i];

        if ($char === '{') { $stack[] = trim($buffer); $buffer = ''; continue; }
        if ($char === '}') { array_pop($stack); $buffer = ''; continue; }

        if ($char !== ';') { $buffer .= $char; continue; }

        $declaration = trim($buffer);
        $buffer = '';

        if ($declaration === '' || $stack === []) continue;

        $selector = end($stack);
        if (str_starts_with($selector, '@')) continue;
        if (! str_contains($declaration, ':')) continue;

        $media = '';
        foreach ($stack as $frame) {
            if (str_starts_with($frame, '@')) $media .= $frame . ' ';
        }

        [$property, $value] = explode(':', $declaration, 2);
        $property = strtolower(trim($property));
        $value = trim($value);

        if ($property === '' || str_starts_with($property, '--')) continue;

        foreach (explode(',', $selector) as $one) {
            $one = trim((string) preg_replace('/\s+/', ' ', $one));
            if ($one === '') continue;

            $out[] = [
                'file' => $file,
                'media' => trim($media),
                'sel' => $one,
                'prop' => $property,
                'val' => $value,
                'spec' => qaSpecificity($one),
                'order' => $order++,
                'line' => substr_count(substr($css, 0, $i), "\n") + 1,
            ];
        }
    }

    return $out;
}

$root = dirname(__DIR__);
$reports = [];

foreach (glob("$root/resources/css/kbb/*.css") ?: [] as $file) {
    $declarations = qaDeclarations((string) file_get_contents($file), basename($file));

    foreach ($declarations as $earlier) {
        if (! preg_match('/var\(\s*(--[\w-]+)/', $earlier['val'], $token)) continue;
        if (str_contains($earlier['val'], '!important')) continue;

        foreach ($declarations as $later) {
            if ($later['order'] <= $earlier['order']) continue;
            if ($later['prop'] !== $earlier['prop']) continue;
            if ($later['media'] !== $earlier['media']) continue;
            if ($later['sel'] !== $earlier['sel']) continue;
            if (str_contains($later['val'], 'var(')) continue;
            if (! qaSpecificityAtLeast($later['spec'], $earlier['spec'])) continue;

            $reports[] = sprintf(
                "%-22s %-34s %-16s reads %-14s — overridden near line %d by: %s",
                $earlier['file'],
                substr($earlier['sel'], 0, 34),
                $earlier['prop'],
                $token[1],
                $later['line'],
                substr($later['val'], 0, 34)
            );

            break;
        }
    }
}

$reports = array_values(array_unique($reports));

echo implode("\n", $reports), "\n";
echo '-- ', count($reports), " declarations that read a custom property and are later overridden\n";
echo "   at the same selector, the same media context and the same-or-greater specificity.\n";
echo "   Line numbers are counted with comments stripped, so they are close, not exact.\n";
