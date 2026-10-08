<?php

declare(strict_types=1);

use App\Support\LandingCss;
use Illuminate\Foundation\Vite;

/**
 * Lane CC — the homepage's grid stylesheet arrives inside the page that opens
 * the shop, and as the cached <link> on every page after it.
 *
 * THE DEFECT ON THE SHOP: the homepage made a phone wait for TWO stylesheets
 * before its first paint — kbb.css and the 5 KB kbb-grid-skins.css, asked for
 * in parallel with app.js, the Outfit face and the site-app script, so the
 * smaller one often finished last (Lane LH). Lighthouse mobile, median of
 * five, Lane LH's homepage seed: FCP and Speed Index ~100 ms later than with
 * the same rules printed in the page. kbb.css itself stays a <link>: every
 * other page needs it, so it must be in the cache when the shopper clicks.
 *
 * What is pinned here, each with what goes red without it:
 *   - the opening request prints the rules inline at the link's own place;
 *   - a navigation from inside the shop prints today's <link>, byte for byte;
 *   - the inline copy is the built file, and the built file cannot read
 *     differently from inside the document (no url(), no @import);
 *   - nothing but the allowlisted grid sheet is ever inlined.
 */
function ccGridTags(): string
{
    return (string) app(Vite::class)('resources/css/kbb/kbb-grid-skins.css');
}

function ccGridCss(): string
{
    return rtrim((string) app(Vite::class)->content('resources/css/kbb/kbb-grid-skins.css'), "\n");
}

/** The page with its per-request token masked, so two renders can be compared. */
function ccMask(string $html): string
{
    return (string) preg_replace(['/"csrf":"[^"]*"/', '/(name="csrf-token" content=")[^"]*/', '/(name="_token" value=")[^"]*/'], ['"csrf":"X"', '$1X', '$1X'], $html);
}

beforeEach(fn () => LandingCss::forget());

it('prints the grid rules inline, at the link\'s own place, on the request that opens the shop', function () {
    /*
     * MUTATION: put `@vite('resources/css/kbb/kbb-grid-skins.css')` back in
     * store/home.blade.php and the opening request carries the <link> again.
     * Red on the first expectation. Change LandingCss::tags() to return the
     * tags unconditionally: red on the same line.
     */
    $landing = $this->withHeaders(['Sec-Fetch-Site' => 'none'])->get('/')->assertOk()->getContent();
    $inside = $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->get('/')->assertOk()->getContent();

    expect($landing)->not->toContain(ccGridTags())
        ->and(substr_count($landing, '<style id="kbb-css-inline">'.ccGridCss().'</style>'))->toBe(1)
        ->and($landing)->not->toContain('kbb-grid-skins');

    /*
     * THE WHOLE PROOF THAT NOTHING ELSE MOVED: the opening page is the inside
     * page with the two <link> tags swapped for the <style>, and nothing else
     * different — same position in <head>, same neighbours, same document.
     */
    expect(ccMask($landing))->toBe(ccMask(str_replace(ccGridTags(), '<style id="kbb-css-inline">'.ccGridCss().'</style>', $inside)));
});

it('keeps the cached link, byte for byte, on a navigation from inside the shop', function () {
    /*
     * THE REGRESSION THIS PREVENTS: inlined on every request, a shopper who
     * comes back to the homepage downloads 5 KB inside the page that the
     * browser already holds as a file — page switching slower, which the owner
     * ruled out. MUTATION: make LandingCss::isLanding() return true and this is
     * red. (Chrome's instant-navigation prefetch does NOT send this header —
     * see the next test.)
     */
    $inside = $this->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->get('/')->assertOk()->getContent();

    expect(substr_count($inside, ccGridTags()))->toBe(1)
        ->and($inside)->not->toContain('kbb-css-inline');
});

it('keeps the link on Chrome\'s instant-navigation prefetch, which says Sec-Fetch-Site: none', function () {
    /*
     * MEASURED, not assumed: Chromium 141's speculation-rules prefetch of the
     * homepage from a product page arrives as `Sec-Fetch-Site: none`,
     * `Sec-Purpose: prefetch`, Referer = the product page. Keyed on the first
     * header alone (this lane's first draft), every prefetched homepage —
     * which is every homepage opened from inside the shop — carried the
     * inline copy, 5 KB the browser already held. MUTATION: drop the Referer
     * half of LandingCss::isLanding() — red.
     */
    $host = parse_url(url('/'), PHP_URL_HOST) ?: 'localhost';

    $prefetch = $this->withHeaders([
        'Sec-Fetch-Site' => 'none', 'Sec-Purpose' => 'prefetch', 'Sec-Fetch-Mode' => 'navigate',
        'Referer' => 'http://'.$host.'/product/x/',
    ])->get('/')->assertOk()->getContent();

    expect(substr_count($prefetch, ccGridTags()))->toBe(1)
        ->and($prefetch)->not->toContain('kbb-css-inline');
});

it('prints today\'s page to anything that does not say it is opening the shop', function () {
    /*
     * No Sec-Fetch-Site at all — a crawler, curl, a browser older than Safari
     * 16.4, this test client — is the page as it was, byte for byte, whatever
     * the Referer says. So no other test in the suite, and no bot, sees a
     * different homepage. MUTATION: treat a missing header as an opening in
     * LandingCss::isLanding() and this is red (and so are three older tests
     * that count strings in the homepage's HTML: GridCardOwnerAsks,
     * HomepageSectionOrder, WhatsAppButton, which is how this was found).
     */
    $plain = $this->get('/')->assertOk()->getContent();
    $google = $this->withHeaders(['Referer' => 'https://www.google.com/'])->get('/')->getContent();

    expect(substr_count($plain, ccGridTags()))->toBe(1)->and($plain)->not->toContain('kbb-css-inline')
        ->and(substr_count($google, ccGridTags()))->toBe(1)->and($google)->not->toContain('kbb-css-inline');

    // An opening from a search result or another site, and a typed address.
    foreach (['cross-site', 'same-site', 'none'] as $site) {
        $r = request()->duplicate();
        $r->headers->set('Sec-Fetch-Site', $site);
        $r->headers->set('Referer', 'https://www.google.com/');
        expect(LandingCss::isLanding($r))->toBeTrue();
    }
});

it('inlines a file that reads the same from inside the document as from its own address', function () {
    /*
     * A <style> resolves url() against the PAGE, a <link> against the FILE, and
     * an @import inside a <style> is a new request on the critical path. The
     * built grid sheet carries neither, so the inline copy is the same
     * stylesheet. MUTATION: add `background:url(x.svg)` to any rule in
     * resources/css/kbb/kbb-grid-skins.css and rebuild — red, and the rule to
     * write instead is the one this file states.
     */
    $css = ccGridCss();

    expect($css)->not->toBe('')
        ->and($css)->not->toContain('url(')
        ->and($css)->not->toContain('@import')
        ->and(stripos($css, '</'))->toBeFalse();
});

it('never inlines anything but the allowlisted grid sheet', function () {
    /*
     * kbb.css is 44 KB on the wire and every page needs it; printed inline it
     * would leave the NEXT page to download it. MUTATION: add kbb.css to
     * LandingCss::INLINE — red.
     */
    expect(LandingCss::INLINE)->toBe(['resources/css/kbb/kbb-grid-skins.css']);

    $request = request()->duplicate();
    $request->headers->set('Sec-Fetch-Site', 'none');

    expect((string) LandingCss::tags('resources/css/kbb/kbb.css', $request))
        ->toBe((string) app(Vite::class)('resources/css/kbb/kbb.css'));

    // And the pages that do not load the grid sheet print nothing new.
    $shop = $this->withHeaders(['Sec-Fetch-Site' => 'none'])->get('/shop/')->assertOk()->getContent();
    expect($shop)->not->toContain('kbb-css-inline');
});

it('is called exactly once, from the homepage', function () {
    // Zero is "built, never wired"; two would print the rules twice.
    $views = collect(\Illuminate\Support\Facades\File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_contains($f->getContents(), 'LandingCss::tags('))
        ->map(fn ($f) => str_replace(resource_path('views').'/', '', $f->getPathname()))
        ->values()->all();

    expect($views)->toBe(['store/home.blade.php'])
        ->and(substr_count((string) file_get_contents(resource_path('views/store/home.blade.php')), 'LandingCss::tags('))->toBe(1)
        ->and((string) file_get_contents(resource_path('views/store/home.blade.php')))->not->toContain("@vite('resources/css/kbb/kbb-grid-skins.css')");
});
