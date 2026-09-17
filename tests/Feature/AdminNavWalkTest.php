<?php

declare(strict_types=1);

/**
 * Every screen in the sidebar renders something.
 *
 * An audit drove all 55 NAV entries in a real browser and found ten where
 * `#content.innerText.length === 0`. A blank screen, to the shop owner who
 * lives in this console, reads as "the software is broken" — and it had read
 * that way for months on nine of them.
 *
 * Two separate causes, which is the point of walking the whole list rather than
 * fixing the ten:
 *
 *   - Nine drew an `<iframe>` pointing at a standalone `.html` file this repo
 *     does not ship. Same defect Lane T found on 'customers' and Lane AM on
 *     'rev-all'; both fixed their own screen and left the rest.
 *   - One, 'meta', called `peCard()`, which is defined nowhere, and threw
 *     before its `innerHTML` assignment ever ran.
 *
 * So this walks NAV itself and asks of each id, structurally, whether anything
 * can end up in `#content`. It is deliberately not a list of the ten: the guard
 * that matters is the one that catches the eleventh.
 *
 * Structural because Pest has no JavaScript engine. The browser measurement
 * lives in the commit — Chromium at 1920 and 390, `pageerror` listener on every
 * screen, `#content` measured rather than `documentElement`, which on this
 * console is structurally blind (the chrome is always there, so the document is
 * never empty however broken the screen is).
 */
function adminApp(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** Slice a balanced `{...}` or `[...]` block starting at the first delimiter at/after $from. */
function jsBlock(string $s, int $from, string $open = '{', string $close = '}'): string
{
    $i = strpos($s, $open, $from);
    if ($i === false) {
        return '';
    }
    $depth = 0;
    for ($j = $i; $j < strlen($s); $j++) {
        if ($s[$j] === $open) {
            $depth++;
        } elseif ($s[$j] === $close) {
            $depth--;
            if ($depth === 0) {
                return substr($s, $i, $j - $i + 1);
            }
        }
    }

    return '';
}

/** The id of every entry in NAV, in sidebar order. */
function navIds(): array
{
    $block = jsBlock(adminApp(), strpos(adminApp(), 'const NAV='), '[', ']');
    preg_match_all("/\[\s*'([a-z0-9-]+)'\s*,\s*'/i", $block, $m);

    return array_values(array_unique($m[1]));
}

/** Keys of a single-line `const NAME={...}` map. */
function jsMapKeys(string $name): array
{
    $block = jsBlock(adminApp(), strpos(adminApp(), 'const '.$name.'='));
    preg_match_all("/'([a-z0-9-]+)'\s*:/i", $block, $m);

    return $m[1];
}

/** Ids the live-wiring `window.go` override re-renders for real. */
function liveWiredIds(): array
{
    $src = adminApp();
    $at = strpos($src, 'var _go = window.go;');
    $block = substr($src, $at, 1600);
    preg_match_all("/id===\s*'([a-z0-9-]+)'/i", $block, $m);

    return array_values(array_unique($m[1]));
}

/** id => renderer name, from the dispatch object inside go(). */
function goDispatch(): array
{
    $src = adminApp();
    $at = strpos($src, '[id]||renderDash)()');
    $block = jsBlock($src, strrpos(substr($src, 0, $at), '({') ?: 0);
    preg_match_all("/'?([a-zA-Z0-9-]+)'?\s*:\s*(render[A-Za-z]+)/", $block, $m, PREG_SET_ORDER);

    $out = [];
    foreach ($m as $hit) {
        $out[$hit[1]] = $hit[2];
    }

    return $out;
}

/** Body of `function NAME(` including braces, or '' when not declared. */
function jsFunctionBody(string $name): string
{
    $at = strpos(adminApp(), 'function '.$name.'(');

    return $at === false ? '' : jsBlock(adminApp(), $at);
}

/** Names the file defines in any form a call could resolve to. */
function definedNames(): array
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $src = adminApp();
    $names = [];
    foreach ([
        '/function\s+([A-Za-z_$][\w$]*)\s*\(/',
        '/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=/',
        '/window\.([A-Za-z_$][\w$]*)\s*=/',
        '/([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?function/',
        '/([A-Za-z_$][\w$]*)\s*[:=]\s*(?:async\s*)?\([^)]*\)\s*=>/',
        '/([A-Za-z_$][\w$]*)\s*[:=]\s*(?:async\s*)?[A-Za-z_$][\w$]*\s*=>/',
    ] as $re) {
        preg_match_all($re, $src, $m);
        foreach ($m[1] as $n) {
            $names[$n] = true;
        }
    }

    return $names = $names;
}

/** Strip `/* *&#47;` and `//` comments. */
function jsNoComments(string $s): string
{
    $s = preg_replace('#/\*.*?\*/#s', ' ', $s);

    return (string) preg_replace('#//[^\n]*#', ' ', (string) $s);
}

/**
 * Reduce a body to the parts that are actually code.
 *
 * Quoted strings go, because their contents are not calls. Template literals
 * keep their `${...}` expressions and drop the HTML around them — this screen
 * builds almost everything inside a template, and `peCard()` was called from
 * inside one, so dropping templates wholesale would blind the check to the very
 * bug it exists for.
 */
function jsCodeOnly(string $s): string
{
    $s = jsNoComments($s);
    $out = '';
    $n = strlen($s);
    $i = 0;

    while ($i < $n) {
        $c = $s[$i];

        if ($c === "'" || $c === '"') {
            $q = $c;
            $i++;
            while ($i < $n && $s[$i] !== $q) {
                $i += ($s[$i] === '\\') ? 2 : 1;
            }
            $i++;
            $out .= ' ';

            continue;
        }

        if ($c === '`') {
            $i++;
            while ($i < $n && $s[$i] !== '`') {
                if ($s[$i] === '\\') {
                    $i += 2;

                    continue;
                }
                if ($s[$i] === '$' && $i + 1 < $n && $s[$i + 1] === '{') {
                    $i += 2;
                    $start = $i;
                    $depth = 1;
                    while ($i < $n && $depth > 0) {
                        if ($s[$i] === '{') {
                            $depth++;
                        } elseif ($s[$i] === '}') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                        }
                        $i++;
                    }
                    $out .= ' '.jsCodeOnly(substr($s, $start, $i - $start)).' ';
                    $i++;

                    continue;
                }
                $i++;
            }
            $i++;
            $out .= ' ';

            continue;
        }

        $out .= $c;
        $i++;
    }

    return $out;
}

/** Function-position identifiers inside a body that nothing defines. */
function undefinedCalls(string $body): array
{
    $builtins = array_flip([
        'if', 'for', 'while', 'switch', 'catch', 'return', 'typeof', 'function', 'new', 'do',
        'else', 'delete', 'void', 'in', 'of', 'await', 'yield', 'throw', 'case', 'instanceof',
        'async',   // `async (e)=>{}` is an arrow head, not a call
        'String', 'Number', 'Boolean', 'Array', 'Object', 'Math', 'JSON', 'Date', 'Promise',
        'Set', 'Map', 'RegExp', 'Error', 'parseInt', 'parseFloat', 'isNaN', 'fetch', 'alert',
        'confirm', 'setTimeout', 'setInterval', 'clearTimeout', 'encodeURIComponent', 'decodeURIComponent',
        'requestAnimationFrame', 'structuredClone', 'FormData', 'URL', 'URLSearchParams', 'Intl',
    ]);

    preg_match_all('/(?<![.\w$])([A-Za-z_$][\w$]*)\s*\(/', jsCodeOnly($body), $m);

    $defined = definedNames();
    $bad = [];
    foreach ($m[1] as $n) {
        if (! isset($builtins[$n]) && ! isset($defined[$n])) {
            $bad[$n] = true;
        }
    }

    return array_keys($bad);
}

it('has a NAV to walk', function () {
    expect(navIds())->not->toBeEmpty()
        ->and(count(navIds()))->toBeGreaterThan(40);
});

/*
 * The walk. One failure listing every screen that cannot put anything on the
 * page, because a run that stops at the first is a run you have to repeat ten
 * times.
 */
it('leaves no sidebar screen blank', function () {
    $frameSrc = jsMapKeys('FRAME_SRC');
    $revSrc = jsMapKeys('REV_SRC');
    $live = liveWiredIds();
    $dispatch = goDispatch();

    // A frame screen is safe only if something is guaranteed to fill #content:
    // either the live wiring replaces it, or the frame renderers route through a
    // mount helper that falls back to a message when the file is not there.
    $mount = jsFunctionBody('mountFrame');
    $mountIsSafe = $mount !== ''
        && str_contains($mount, 'frameNotBuiltHTML')
        && jsFunctionBody('frameNotBuiltHTML') !== '';

    $renderFrame = jsFunctionBody('renderFrame');
    $renderRevFrame = jsFunctionBody('renderReviewFrame');
    $framesRouted = str_contains($renderFrame, 'mountFrame(')
        && str_contains($renderRevFrame, 'mountFrame(')
        && ! preg_match('/innerHTML\s*=\s*`<iframe/', $renderFrame)
        && ! preg_match('/innerHTML\s*=\s*`<iframe/', $renderRevFrame);

    $framesSafe = $mountIsSafe && $framesRouted;

    /*
     * LANE EC — the floor that stops this walk grading itself.
     *
     * goDispatch() reads go()'s dispatch object out of the shell with
     * strpos('[id]||renderDash)()') and jsBlock(), both of which hand back
     * NOTHING rather than failing when the anchor moves. The loop below then
     * takes `$dispatch[$id] ?? null` as "falls through to renderDash, which
     * renders" and `continue`s — so an empty dispatch map excuses every single
     * screen and the walk reports a clean bill of health having checked none of
     * them. Same shape as the mobile drawer selector in
     * ChromeLinksResolveTest: a parse that fails by returning nothing.
     *
     * Thirty is the measured size rounded well down. It moves only when screens
     * are added or removed, and if it ever trips it is telling you the anchor
     * string above has drifted, not that the panel has.
     */
    expect(count($dispatch))->toBeGreaterThan(
        30,
        'goDispatch() parsed almost no renderers out of go(), so every screen below is waved '
        . 'through as "falls back to renderDash" and this walk checks nothing. The anchor it '
        . 'searches for has moved.',
    );

    expect(count($frameSrc))->toBeGreaterThan(
        0,
        'jsMapKeys(\'FRAME_SRC\') parsed nothing; the frame screens are no longer being recognised as frames.',
    );

    $blank = [];

    foreach (navIds() as $id) {
        $isFrame = in_array($id, $frameSrc, true) || in_array($id, $revSrc, true);

        if ($isFrame) {
            if (in_array($id, $live, true) || $framesSafe) {
                continue;
            }
            $blank[$id] = 'iframe to a .html file this repo does not ship, and nothing replaces it';

            continue;
        }

        if (str_starts_with($id, 'p-')) {
            continue;   // renderPlaceholder — an honest "isn't installed yet" card
        }

        if (in_array($id, $live, true)) {
            continue;   // rendered for real by the live-wiring override
        }

        // Everything else is drawn by a renderer in go()'s dispatch object. It
        // must exist, and everything it calls must exist, or it throws before it
        // assigns anything — which is exactly how 'meta' went blank.
        $fn = $dispatch[$id] ?? null;
        if ($fn === null) {
            continue;   // falls through to renderDash, which renders
        }

        $body = jsFunctionBody($fn);
        if ($body === '') {
            $blank[$id] = $fn.'() is named in go() but not defined';

            continue;
        }

        $missing = undefinedCalls($body);
        if ($missing !== []) {
            $blank[$id] = $fn.'() calls undefined '.implode(', ', array_map(fn ($n) => $n.'()', $missing));
        }
    }

    $report = implode("\n", array_map(
        fn ($id, $why) => sprintf('  %-16s %s', $id, $why),
        array_keys($blank),
        $blank
    ));

    expect($blank)->toBe([], count($blank)." sidebar screen(s) render nothing:\n".$report."\n");
});

it('never requests a standalone file for a screen the live wiring replaces', function () {
    // The pointless 404. These ids draw a real screen a moment later, so drawing
    // the frame first only ever fired a request for a file that is not there.
    $liveFrames = array_values(array_intersect(liveWiredIds(), jsMapKeys('FRAME_SRC')));

    expect($liveFrames)->not->toBeEmpty();

    $set = jsBlock(adminApp(), strpos(adminApp(), 'const LIVE_RENDERED='), '[', ']');

    foreach ($liveFrames as $id) {
        expect($set)->toContain("'".$id."'");
    }

    // and mountFrame must return before touching the network for those ids
    $mount = jsFunctionBody('mountFrame');
    expect($mount)->toMatch('/LIVE_RENDERED\.has\(id\)[^;]*;return;/');
});

it('does not show the shop owner invented numbers on the Meta screen', function () {
    // Comments stripped: this function's own comment names the invented figures
    // in order to explain why they are gone.
    $meta = jsNoComments(jsFunctionBody('renderMeta'));

    expect($meta)->not->toBeEmpty();

    // The figures the broken screen would have rendered had peCard been defined.
    foreach (['642', 'meta-catalog.xml', 'Sync catalog now', 'Conversions API · server-side'] as $invented) {
        expect($meta)->not->toContain($invented);
    }

    // and it must actually say something
    expect($meta)->toContain("isn't installed yet");
});
