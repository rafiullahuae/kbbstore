<?php

declare(strict_types=1);

/**
 * =============================================================================
 * A RECOMMENDED CHECK MUST NOT BE ABLE TO STOP AN INSTALL
 * =============================================================================
 *
 * `public-web-root/install.php` shows the owner what their server can do before
 * it writes anything. Every row in that list started out as a hard gate: `ok`
 * false disables Continue, and the install cannot proceed.
 *
 * That is right for pdo_mysql and wrong for gd. `ImageVariants::available()` is
 * `extension_loaded('gd') && function_exists('imagecreatetruecolor')`, and
 * everything downstream of it already degrades by design — generate() returns
 * `reason: 'no image library'`, srcsetFor() falls back to the original file,
 * and the Media Library screen says so on its face. A shop on a server without
 * gd sells exactly as one with it; it just sends a full-size photograph where a
 * 187px copy would do.
 *
 * So the gd row is advisory: amber, with its advice attached, and Continue
 * stays enabled. The failure this test exists to prevent is the easy one — that
 * somebody later "tidies" the row into the required-extensions loop above it,
 * or flips `ok` to track the extension, and a host with no gd becomes a host
 * where the software refuses to install at all. That turns a page-weight
 * regression into a total blocker, silently, in a file with no test run against
 * it on the machine where it matters.
 *
 * MUTATION: change the gd row's `'ok' => true` to `'ok' => $gd`. Red.
 *
 * That mutation is the reason the last test reads the source instead of calling
 * the function. On a machine that HAS gd — this one, CI, and most — `$gd` and
 * `true` are the same value, so no amount of calling kbb_requirements() can
 * tell them apart. The guard has to hold on the machine running it, not only on
 * the unlucky host it is protecting, so it asserts the shape of the row rather
 * than the value it happens to produce here.
 */

/**
 * The real `kbb_requirements()`, lifted out of install.php and made callable.
 *
 * install.php cannot be included: its top level finds the application folder,
 * mints a setup token and prints a page. So the one function under test is cut
 * from the source and evaluated on its own, which keeps this a test of the
 * shipped code rather than of a copy of it.
 */
function ircRequirements(string $base): array
{
    static $ready = false;

    if (! $ready) {
        $src = (string) file_get_contents(base_path('public-web-root/install.php'));

        $start = strpos($src, 'function kbb_requirements(');

        expect($start)->not->toBeFalse('install.php no longer defines kbb_requirements()');

        // Brace-match from the signature's opening { to its close.
        $open = strpos($src, '{', $start);
        $depth = 0;
        $end = null;

        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                if (--$depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        expect($end)->not->toBeNull('could not find the end of kbb_requirements()');

        $body = substr($src, $start, $end - $start + 1);

        eval(str_replace('function kbb_requirements(', 'function ircRequirementsReal(', $body));

        $ready = true;
    }

    return ircRequirementsReal($base);
}

/** The rows install.php marks advisory. */
function ircAdvisory(array $checks): array
{
    return array_values(array_filter($checks, fn ($c) => ! empty($c['warn']) || str_contains($c['label'], '(recommended)')));
}

it('marks gd recommended rather than required', function () {
    $checks = ircRequirements(base_path());

    $gd = array_values(array_filter($checks, fn ($c) => str_contains($c['label'], 'gd')));

    expect($gd)->toHaveCount(1);

    /*
     * The assertion that matters, and it holds on a machine WITH gd as well as
     * one without: whatever this server can do, the row reports ok.
     */
    expect($gd[0]['ok'])->toBeTrue(
        'the gd row reports ok=false, which disables Continue; a server without gd serves '
        .'full-size photographs, it does not fail to run the shop'
    );

    // And it tells the truth about this machine in the half that is advisory.
    $available = extension_loaded('gd') && function_exists('imagecreatetruecolor');

    expect($gd[0]['warn'] ?? false)->toBe(
        ! $available,
        'the gd row does not reflect whether this server actually has gd'
    );

    // An advisory row is useless without the advice.
    expect(trim((string) ($gd[0]['fix'] ?? '')))->not->toBe('');
});

it('computes passed from ok alone, so an advisory row can never block', function () {
    /*
     * This is the line install.php runs, applied to a list where every advisory
     * row is failing. Written against a forced input rather than this machine's
     * real one, because a machine that happens to have gd would pass this test
     * no matter how the row was wired.
     */
    $checks = ircRequirements(base_path());

    foreach ($checks as $i => $c) {
        if (! empty($c['warn']) || str_contains($c['label'], '(recommended)')) {
            $checks[$i]['warn'] = true;      // pretend every advisory row is unhappy
        } else {
            $checks[$i]['ok'] = true;        // and every real requirement is met
        }
    }

    $passed = ! in_array(false, array_column($checks, 'ok'), true);

    expect($passed)->toBeTrue(
        'a server that meets every requirement but trips an advisory row is refused an install'
    );

    expect(ircAdvisory($checks))->not->toBe([], 'there are no advisory rows left to protect');
});

it('wires every advisory row to a literal ok, not to the thing it is advising about', function () {
    /*
     * The one assertion that holds everywhere.
     *
     * `'ok' => $gd` and `'ok' => true` are indistinguishable by calling the
     * function on a server that has gd, which is nearly every server a
     * developer or CI runner uses — so the behavioural tests above go quietly
     * green on exactly the change they exist to catch, and the breakage shows up
     * only on a customer's host, during an install, with no shell to debug it.
     *
     * So: read the row. An advisory row's `ok` must be the literal `true`. If it
     * is an expression, it is a gate wearing an amber dot.
     */
    $src = (string) file_get_contents(base_path('public-web-root/install.php'));

    $start = strpos($src, 'function kbb_requirements(');
    $body = substr($src, (int) $start, (int) strpos($src, 'function kbb_db_probe(') - (int) $start);

    // Each `$out[] = [ ... ];` row in the function.
    preg_match_all('/\$out\[\]\s*=\s*\[(.*?)\n    \];/s', $body, $m);

    expect($m[1])->not->toBe([], 'no requirement rows found; has kbb_requirements() been restructured?');

    $advisory = 0;

    foreach ($m[1] as $row) {
        if (! str_contains($row, "'warn'")) {
            continue;
        }

        $advisory++;

        expect(preg_match("/'ok'\s*=>\s*true\s*,/", $row))->toBe(
            1,
            "an advisory row (one carrying 'warn') sets 'ok' to something other than the literal true, "
            .'so it can disable Continue and refuse the install on a server the shop would run on perfectly well'
        );
    }

    expect($advisory)->toBeGreaterThan(0, 'there are no advisory rows left; the gd row has become a hard gate');
});

it('renders an advisory row as its own colour, with its advice showing', function () {
    $src = (string) file_get_contents(base_path('public-web-root/install.php'));

    /*
     * Amber is not decoration here. Without it an advisory row is a green tick
     * beside the words "missing", which reads as a rendering bug and teaches the
     * owner to distrust the whole list.
     *
     * MUTATION: drop `c.warn` from the dot class. Red.
     */
    expect(str_contains($src, "c.ok?(c.warn?'w':'y'):'n'"))->toBeTrue(
        'install.php no longer paints an advisory row differently from a passing one'
    );

    expect(str_contains($src, '.dot.w{background:var(--warn)}'))->toBeTrue(
        'the advisory dot has no colour defined, so it renders as an empty circle'
    );

    /*
     * And the advice has to show. The original renderer printed `fix` only when
     * `ok` was false, which would have hidden it on every advisory row — the one
     * kind of row that exists ONLY to give advice.
     */
    expect(str_contains($src, "(!c.ok||c.warn)?'<div class=\"hint\">'"))->toBeTrue(
        'install.php hides the advice on advisory rows, which is all they carry'
    );
});
