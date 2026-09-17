<?php

declare(strict_types=1);

/**
 * =============================================================================
 * EVERY WRITE THE ADMIN CONSOLE MAKES HAS TO CARRY A TOKEN LARAVEL ACCEPTS
 * =============================================================================
 *
 * This file exists because six of them did not, and every one of the six failed
 * SILENTLY and IDENTICALLY: Laravel answers a missing CSRF token with 419, the
 * console's own error handling turns any non-ok response into its generic
 * sentence, and the owner reads "Stripe refused the connection" over a request
 * Stripe was never sent.
 *
 * What was actually broken, all of it shipped and none of it noticed:
 *
 *   - POST /admin-api/payments/stripe/connect              (paste a secret key)
 *   - POST /admin-api/payments/stripe/connect/application  (the Connect app)
 *   - POST /admin-api/payments/stripe/disconnect
 *   - POST /admin-api/outbound/sweep                       (send queued mail now)
 *   - POST /admin-api/mail/status-emails
 *
 * The middle one is why the owner could not find the one-click Stripe button:
 * the button is drawn from `oauth_ready`, `oauth_ready` needs a client id and a
 * platform secret saved, and the only screen that can save them could not write.
 * The feature was built, shipped and unreachable — not missing.
 *
 * TWO DIFFERENT SPELLINGS ARE BOTH CORRECT, which is why this checks for either
 * and not for one:
 *
 *   X-XSRF-TOKEN   the value of the XSRF-TOKEN cookie, decrypted by Laravel
 *   X-CSRF-TOKEN   the raw session token, normally taken from a meta tag
 *
 * The second is the trap. Two calls read
 * `document.querySelector('meta[name=csrf-token]')` and THIS DOCUMENT HAS NO
 * SUCH TAG, so `(...||{}).content||''` degraded to an empty string instead of
 * throwing — a header that is present, well-formed and empty. Adding the tag is
 * not a fix here either: `resources/views/admin/app.blade.php` opens with
 * `@verbatim`, so a `{{ csrf_token() }}` in its head renders as those eighteen
 * literal characters. Measured, not assumed: the tag was added, the page served
 * it verbatim, and the request still took a 419.
 *
 * WHY THE SOURCE AND NOT THE RENDERED PAGE. Every one of these is an argument to
 * `fetch()` inside a script, so there is no element to assert on — the rendered
 * document would give back the same text this reads, with the same comments in
 * it. Comments ARE the hazard (risk ▒31: a regex guard reads prose as code), so
 * they are stripped with a real string-aware scanner before anything is matched,
 * and the scanner is itself pinned by a case below.
 */

use function Pest\Laravel\get;

/** Strip JS comments without mangling the strings that contain slashes. */
function jsWithoutComments(string $js): string
{
    $out = '';
    $n = strlen($js);

    for ($i = 0; $i < $n; $i++) {
        $c = $js[$i];

        if ($c === '/' && $i + 1 < $n && $js[$i + 1] === '*') {
            $j = strpos($js, '*/', $i + 2);
            $i = $j === false ? $n : $j + 1;
            $out .= ' ';

            continue;
        }

        if ($c === '/' && $i + 1 < $n && $js[$i + 1] === '/') {
            $j = strpos($js, "\n", $i);
            $i = $j === false ? $n : $j - 1;
            $out .= ' ';

            continue;
        }

        if ($c === '"' || $c === "'") {
            $quote = $c;
            $out .= $c;
            $i++;
            while ($i < $n) {
                if ($js[$i] === '\\') {
                    $out .= substr($js, $i, 2);
                    $i += 2;

                    continue;
                }
                $out .= $js[$i];
                if ($js[$i] === $quote) {
                    break;
                }
                $i++;
            }

            continue;
        }

        $out .= $c;
    }

    return $out;
}

/** Every `fetch( ... )` argument list, balanced, as source text. */
function fetchCalls(string $code): array
{
    $calls = [];
    $offset = 0;

    while (preg_match('/\bfetch\s*\(/', $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $open = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $j = $open;

        for (; $j < strlen($code); $j++) {
            if ($code[$j] === '(') {
                $depth++;
            } elseif ($code[$j] === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        $calls[] = [
            'line' => substr_count(substr($code, 0, $m[0][1]), "\n") + 1,
            'src' => substr($code, $open, $j - $open + 1),
        ];
        $offset = $j + 1;
    }

    return $calls;
}

const CONSOLE = 'resources/views/admin/app.blade.php';

it('strips comments without reading prose as code', function () {
    // The guard's own instrument, pinned first — risk ▒36: an extractor that
    // silently matches nothing reports a clean file.
    $sample = <<<'JS'
    /* method:'POST' written in a comment, with no token */
    // method:'POST' again, in a line comment
    var keep = "a string with method:'POST' inside it";
    fetch(url, {method:'POST', headers:{'X-XSRF-TOKEN':t}});
    JS;

    $clean = jsWithoutComments($sample);

    expect(substr_count($clean, "method:'POST'"))->toBe(2)   // the string and the real call
        ->and(str_contains($clean, 'written in a comment'))->toBeFalse()
        ->and(str_contains($clean, 'again, in a line comment'))->toBeFalse()
        ->and(str_contains($clean, 'a string with method'))->toBeTrue();

    $calls = fetchCalls($clean);
    expect($calls)->toHaveCount(1);
});

it('sends a token Laravel accepts with every direct POST the console makes', function () {
    $code = jsWithoutComments(file_get_contents(base_path(CONSOLE)));
    $calls = fetchCalls($code);

    // The extractor must find something before its silence means anything.
    expect(count($calls))->toBeGreaterThan(40);

    $posts = array_values(array_filter(
        $calls,
        fn (array $c) => (bool) preg_match("/method\s*:\s*'POST'/", $c['src']),
    ));

    expect(count($posts))->toBeGreaterThan(30);

    $untokened = [];

    foreach ($posts as $call) {
        $hasHeader = str_contains($call['src'], 'X-XSRF-TOKEN')
            || str_contains($call['src'], 'X-CSRF-TOKEN');

        if (! $hasHeader) {
            $untokened[] = 'line ' . $call['line'];
        }
    }

    expect($untokened)->toBe(
        [],
        // Pest's toContain() is variadic and its messages are needles; toBe()'s
        // second argument is a real message. Risk ▒39.
    );
})->group('console');

it('reads no csrf-token meta tag unless the document actually renders one', function () {
    $raw = file_get_contents(base_path(CONSOLE));

    $readsTag = str_contains($raw, 'meta[name=csrf-token]')
        || str_contains($raw, "meta[name='csrf-token']")
        || str_contains($raw, 'meta[name="csrf-token"]');

    if (! $readsTag) {
        expect(true)->toBeTrue();

        return;
    }

    // If a later edit adds a reader back, the tag has to exist AND has to be
    // outside the @verbatim region that opens this file, or it renders as the
    // eighteen characters of its own source.
    $head = substr($raw, 0, (int) strpos($raw, '</head>'));
    $verbatimEnds = strpos($head, '@endverbatim');

    expect(str_contains($head, 'name="csrf-token"'))->toBeTrue()
        ->and($verbatimEnds)->not->toBeFalse();
});

it('offers the Stripe setup wizard and wires its button', function () {
    $code = jsWithoutComments(file_get_contents(base_path(CONSOLE)));

    expect(str_contains($code, 'data-paywizard'))->toBeTrue()
        ->and(str_contains($code, 'payStripeWizardOpen'))->toBeTrue()
        // the escape hatch and the two ways out of it
        ->and(str_contains($code, 'data-wizoauth'))->toBeTrue()
        ->and(str_contains($code, 'data-wizfinish'))->toBeTrue()
        // it posts to the endpoint that already existed, not a new one
        ->and(str_contains($code, "'/admin-api/payments/stripe/connect'"))->toBeTrue();
});

it('prints only Stripe links that were verified, and only on dashboard.stripe.com', function () {
    $raw = file_get_contents(base_path(CONSOLE));

    preg_match_all('~https?://[a-z0-9.\-]*stripe\.com[^\s"\'<>)]*~i', $raw, $m);
    $found = array_values(array_unique($m[0]));
    sort($found);

    // Two, both the Dashboard's own API-keys page, one per mode. A third would
    // be a link nobody checked, in a screen that asks for a secret key — which
    // is the shape of a phishing page. StripeConnectConsole's written guide
    // still carries menu paths and no URLs at all; that is asserted separately
    // in its own test and is not relaxed by these two.
    expect($found)->toBe([
        'https://dashboard.stripe.com/apikeys',
        'https://dashboard.stripe.com/test/apikeys',
    ]);
});
