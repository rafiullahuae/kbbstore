<?php

declare(strict_types=1);

use Tests\Support\EnglishRenderWalk;

/**
 * The acceptance bar for the interface-string conversion (Lane EU, T2).
 *
 * ── WHAT THIS ASSERTS ───────────────────────────────────────────────────────
 *
 * Converting a storefront Blade to __() must change the ENGLISH page by
 * nothing. Not "look the same" — be the same bytes. Every literal that moved
 * into App\Services\Translation\InterfaceStrings is served straight back out by
 * the loader with Arabic switched off, so if a key is misspelt, a placeholder
 * is wrong, or an @if got swallowed in the edit, the page changes and this goes
 * red.
 *
 * ── WHERE THE "BEFORE" COMES FROM, AND WHY IT IS NOT A FILE IN THIS BRANCH ───
 *
 * From git, at the commit this lane branched from. A snapshot captured AFTER
 * the conversion and committed alongside it proves nothing at all — it is the
 * converted output compared with itself. So the comparison materialises
 * resources/views as it stood at BASE_COMMIT into a temporary directory, points
 * the view finder at it, and renders each page twice in the same process: once
 * from the old templates and once from the ones in the working tree. Same
 * request, same database, same frozen clock, same process.
 *
 * WHEN A LATER LANE CHANGES ENGLISH COPY ON PURPOSE, this test goes red, and
 * that is the intended behaviour rather than a maintenance burden to design
 * away: the diff it prints is exactly the copy change, to be read and approved,
 * after which BASE_COMMIT moves forward to the commit that made it.
 *
 * ── THE THREE PASSES ────────────────────────────────────────────────────────
 *
 *   1. BEFORE   old views
 *   2. CONTROL  old views again — must equal pass 1 after masking
 *   3. AFTER    the working tree
 *
 * Pass 2 is what makes the masking in EnglishRenderWalk::mask() honest. A CSRF
 * token differs between two renders of the SAME file, so something has to be
 * masked; the control proves the mask is SUFFICIENT (it passes) and the
 * mutation test at the bottom proves it is not so broad that it would swallow a
 * change to the words.
 */

/**
 * Render every storefront page that comes out of a Blade template.
 *
 * @return array<string, string> path => masked HTML
 */
function englishRenderAll(\Tests\TestCase $test, array $seed, array $expectations): array
{
    $out = [];

    foreach ($expectations as $uri => $spec) {
        if (! ($spec['render'] ?? false)) {
            continue;
        }

        $path = EnglishRenderWalk::pathFor($uri, $spec);

        /*
         * A CLEAN SESSION BEFORE EVERY REQUEST, and it is not tidiness.
         *
         * The account pages below are requested signed in, and Laravel's test
         * client keeps the session between calls inside one test. Left alone,
         * the first signed-in request makes every LATER page render signed in
         * too -- /my-account renders "My account" instead of "Sign in", and the
         * layout adds the account panel's webfont to the <head> of everything
         * after it. Pass one and pass two would then differ from each other
         * over nothing, which is precisely what the control pass caught.
         */
        englishFreshSession($test);

        $request = $test;

        if ($spec['auth'] ?? false) {
            $request = $test->withSession([
                EnglishRenderWalk::customerSessionKey() => $seed['customer']->id,
            ]);
        }

        $out[$uri] = EnglishRenderWalk::mask($request->get($path)->getContent());
    }

    // The 404 page is chrome too, and a shopper reaches it more often than
    // several of the pages above.
    englishFreshSession($test);
    $out['(404)'] = EnglishRenderWalk::mask($test->get('/no-such-page-at-all')->getContent());

    /*
     * And the two pages a shopper only reaches with a basket. Requested with
     * the seeded cart's cookie, exactly as tests/Feature/CartPageLayoutTest
     * does it, because the walk above sees an empty bag and an empty bag skips
     * the entire cart summary and redirects the checkout away.
     */
    foreach (['/cart', '/checkout'] as $path) {
        englishFreshSession($test);
        $out['(with a basket) ' . $path] = EnglishRenderWalk::mask(
            $test->withCredentials()
                ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
                ->withUnencryptedCookie(\App\Services\CartService::COOKIE, $seed['cart']->token)
                ->get($path)
                ->getContent()
        );
    }

    return $out;
}

it('has an entry for every storefront GET route the router registers', function () {
    $seed = EnglishRenderWalk::seed($this);
    $known = array_keys(EnglishRenderWalk::expectations($seed));
    $registered = EnglishRenderWalk::registeredUris();

    $missing = array_values(array_diff($registered, $known));
    expect($missing)->toBe([], sprintf(
        "These storefront GET routes are not listed in EnglishRenderWalk::expectations():\n  %s\n"
        . "Add each one, with render => true if a shopper reads a Blade-rendered page there.",
        implode("\n  ", $missing)
    ));

    $stale = array_values(array_diff($known, $registered));
    expect($stale)->toBe([], 'Listed routes the router does not register: ' . implode(', ', $stale));

    // And the walk must actually be walking pages, not an empty list.
    $rendered = array_filter(EnglishRenderWalk::expectations($seed), fn ($s) => $s['render'] ?? false);
    expect(count($rendered))->toBeGreaterThan(25);
});

it('renders byte-identical English on every storefront page after the __() conversion', function () {
    // A frozen clock, so "30 days ago" and © 2026 cannot differ between the
    // three passes for a reason that has nothing to do with this lane.
    $this->travelTo(\Carbon\Carbon::parse('2026-06-15 09:30:00'));

    $seed = EnglishRenderWalk::seed($this);
    $expectations = EnglishRenderWalk::expectations($seed);

    $current = config('view.paths');
    $base = EnglishRenderWalk::baseViews();

    try {
        EnglishRenderWalk::useViewPath($base);
        $before = englishRenderAll($this, $seed, $expectations);
        $control = englishRenderAll($this, $seed, $expectations);

        EnglishRenderWalk::useViewPath($current[0]);
        $after = englishRenderAll($this, $seed, $expectations);
    } finally {
        EnglishRenderWalk::useViewPath($current[0]);
    }

    expect(count($before))->toBeGreaterThan(25);

    /*
     * A page that 500s or redirects renders a short body or none, and comparing
     * two empty strings passes. So every snapshot has to be a real page, and the
     * two basket pages have to be the basket pages rather than a bounce back to
     * an empty cart.
     */
    // /quick-view/{id} is a fragment the modal fetches, not a page; a couple of
    // kilobytes is all of it. It is checked by its own marker below instead.
    $fragments = ['quick-view/{id}'];
    $thin = array_keys(array_filter(
        $before,
        fn (string $html, string $uri): bool => ! in_array($uri, $fragments, true) && strlen($html) < 3000,
        ARRAY_FILTER_USE_BOTH
    ));
    expect($thin)->toBe([], 'These pages rendered almost nothing, so comparing them proves nothing: ' . implode(', ', $thin));
    expect($before['(with a basket) /cart'])->toContain('cartInner')
        ->and($before['(with a basket) /cart'])->not->toContain('Your bag is empty');
    expect($before['(with a basket) /checkout'])->toContain('kbbCheckoutForm');
    expect($before['quick-view/{id}'])->toContain('qv-acts');

    // 1. The control. If this fails, the mask is incomplete and the comparison
    //    below would be measuring noise rather than words.
    $unstable = [];
    foreach ($before as $uri => $html) {
        if (($control[$uri] ?? null) !== $html) {
            $unstable[] = $uri . ' — ' . EnglishRenderWalk::firstDifference($html, $control[$uri] ?? '');
        }
    }
    expect($unstable)->toBe([], "These pages differ between two renders of the SAME template, so something\n"
        . "unmasked is non-deterministic and the comparison below cannot be trusted:\n  "
        . implode("\n  ", $unstable));

    /*
     * The approved reflows, applied to the BEFORE side and counted. See
     * EnglishRenderWalk::approvedReflows() for what each one is and why it
     * could not be kept byte-identical.
     */
    $reflowed = [];
    foreach (EnglishRenderWalk::approvedReflows() as $name => $rule) {
        $hits = 0;
        foreach ($before as $uri => $html) {
            $before[$uri] = EnglishRenderWalk::collapseInner($html, $rule['pattern'], $hits);
        }
        $reflowed[$name] = ['expected' => $rule['hits'], 'actual' => $hits];
    }
    expect(array_keys(array_filter($reflowed, fn (array $r): bool => $r['expected'] !== $r['actual'])))
        ->toBe([], 'Each approved reflow must match the elements it was written for. Got: '
            . json_encode($reflowed));

    // 2. The bar itself.
    $changed = [];
    foreach ($before as $uri => $html) {
        if (($after[$uri] ?? null) !== $html) {
            $changed[] = $uri . "\n      " . EnglishRenderWalk::firstDifference($html, $after[$uri] ?? '');
        }
    }

    expect($changed)->toBe([], "The English output of these pages changed:\n  " . implode("\n  ", $changed));
});

/**
 * Forget the session, every resolved guard and the resolved cart, so the next
 * page is a new visitor.
 *
 * The cart is the one that is easy to miss. App\Services\CartService is a
 * singleton and memoises BOTH the cart it found and the fact that it found
 * none; the second memo is per-REQUEST in production, where a request is a
 * process, and per-TEST here, where one application serves fifty of them. So
 * the first cookie-less page in a pass latched "this visitor has no cart", and
 * /checkout — which asks with create:false — went on bouncing to /cart even
 * when the request carried a basket cookie. The page still answered 200, with
 * a redirect's empty body, which is exactly the kind of "snapshot" that
 * compares equal to another empty body and proves nothing.
 */
function englishFreshSession(\Tests\TestCase $test): void
{
    $test->flushSession();
    app('auth')->forgetGuards();
    app(\App\Services\CartService::class)->forget();

    /*
     * And the browser's cookie jar. withUnencryptedCookie() stores the basket
     * cookie on the test case, where it survives every later request — so the
     * basket renders at the end of one pass put a cart badge into the header of
     * every page at the START of the next one, and pass one and pass two
     * differed over a basket neither page was asked about. Bound closure rather
     * than a setter because MakesHttpRequests keeps these protected.
     */
    (function (): void {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withCredentials = false;
    })->call($test);
}

/**
 * The comparison above is only worth running if it can fail. This proves it
 * can: one byte of one page is changed and the same comparison must report it.
 */
it('detects a single changed byte in a rendered page', function () {
    $a = "<p>Add to bag</p>";
    $b = "<p>Add to bags</p>";

    expect(EnglishRenderWalk::mask($a))->not->toBe(EnglishRenderWalk::mask($b));
    expect(EnglishRenderWalk::firstDifference($a, $b))->toContain('at byte');

    // And the mask must not be broad enough to erase a word. A CSRF token is
    // masked; the words around it are not.
    $withToken = '<input name="_token" value="' . str_repeat('a', 40) . '"><p>Add to bag</p>';
    $otherToken = '<input name="_token" value="' . str_repeat('b', 40) . '"><p>Add to bag</p>';
    $otherWords = '<input name="_token" value="' . str_repeat('a', 40) . '"><p>Add to basket</p>';

    expect(EnglishRenderWalk::mask($withToken))->toBe(EnglishRenderWalk::mask($otherToken));
    expect(EnglishRenderWalk::mask($withToken))->not->toBe(EnglishRenderWalk::mask($otherWords));
});
