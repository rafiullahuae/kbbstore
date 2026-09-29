<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Support\BrandAccent;

/**
 * The owner's appearance settings reach the documents that carry their own
 * <head>.                                                           (Lane BG)
 *
 * ── WHAT WAS WRONG ─────────────────────────────────────────────────────────
 *
 * Five storefront views do not extend layouts/store.blade.php — store/blog,
 * store/post, store/review-wall, store/skin-quiz and store/app — and
 * App\View\Composers\StoreComposer is registered for `layouts.store` AND
 * NOTHING ELSE:
 *
 *     View::composer('layouts.store', StoreComposer::class);
 *
 * so `$kbbAccent` was not even DEFINED on any of them. All five hard-code
 * `--pink:#E0567B` on their own `:root` and four of them use it heavily — the
 * skin quiz 12 times plus 14 of `--pink-deep`, the review wall 8 and 5. A shop
 * that changed its brand colour changed /shop/, the home page, the cart and the
 * checkout, and the journal, an article, the review wall and the skin quiz kept
 * the old pink. Four live URLs, and nobody had noticed.
 *
 * The site width was in the same shape one step along: store/blog and
 * store/post each carried their own COPY of the layout's block and the other
 * three had nothing.
 *
 * ── MUTATION NOTES, run in this lane's worktree ────────────────────────────
 *
 *   - delete the include from store/skin-quiz.blade.php
 *       → 'skin-quiz does not include the appearance partial exactly once', and
 *         the rendered quiz keeps #E0567B with a green brand colour saved.
 *   - move the include ABOVE that document's own <style>
 *       → the last case fails: both rules are `:root`, they tie on specificity,
 *         and source order is the whole of what makes the owner's colour win.
 *   - make BrandAccent::pair() return a pair for the default colour
 *       → 'a shop on the shipped colour sends nothing' fails, and forty pages
 *         gain a <style> element that renders identically.
 */
it('includes the appearance partial exactly once in every document that needs it', function () {
    /*
     * SIX, not five: the layout is a user of this partial too, and that is the
     * point of it — one writer for the brand colour rather than one in the
     * layout and five stale copies of the default in the documents.
     */
    $documents = [
        'layouts/store.blade.php',
        'store/blog.blade.php',
        'store/post.blade.php',
        'store/review-wall.blade.php',
        'store/skin-quiz.blade.php',
        'store/app.blade.php',
    ];

    $wrong = [];

    foreach ($documents as $view) {
        $src = (string) file_get_contents(resource_path('views/'.$view));
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
        $count = substr_count($src, "@include('partials.shop-appearance-css')");

        if ($count !== 1) {
            $wrong[] = sprintf('  %-32s includes it %d times', $view, $count);
        }
    }

    expect($wrong)->toBe([], "a document does not carry the appearance partial exactly once:\n"
        .implode("\n", $wrong)
        ."\n\n0 is a page the owner's brand colour does not reach, which is what five of"
        .' these were. 2 emits the same stylesheet twice into one head.');
});

it('leaves no second copy of either block behind', function () {
    /*
     * The partial is only one writer if the blocks it replaced are gone. Two
     * documents carried their own copy of the site-layout block and the layout
     * carried both; a copy left behind would emit the same <style> twice.
     */
    foreach (['layouts/store.blade.php', 'store/blog.blade.php', 'store/post.blade.php'] as $view) {
        $src = (string) file_get_contents(resource_path('views/'.$view));
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);

        /*
         * THE WRITER, NOT THE ID. Asserting on `id="kbb-layout"` was the first
         * shape and it failed on store/blog for the wrong reason: that document
         * has a CSS comment -- inside its own <style>, so no Blade strip
         * touches it -- explaining that Appearance → Site layout emits
         * `<style id="kbb-layout">` into its head. The prose is still true and
         * is not a second copy of anything. What would be a second copy is the
         * code that calls the service, so that is what is counted.
         */
        expect(str_contains($src, 'SiteLayout::class)->css()'))
            ->toBeFalse($view.' still calls SiteLayout::css() itself');
        expect(str_contains($src, "\$kbbAccent['base']"))
            ->toBeFalse($view.' still prints the accent itself');
    }
});

it('sends nothing at all while the shop is on its shipped colour', function () {
    expect(BrandAccent::pair())->toBeNull()
        ->and(BrandAccent::css())->toBe('');

    // Case, because ModuleSchema's colour cast upper-cases and the picker sends
    // lower case, and both spellings have been in this column.
    foreach (['#E0567B', '#e0567b', '#E0567b'] as $same) {
        app(SettingsService::class)->set('brand_accent', $same);
        expect(BrandAccent::css())->toBe('', $same.' is the shipped colour and should emit nothing');
    }
});

it('refuses a stored value that is not a colour, rather than printing it', function () {
    app(SettingsService::class)->set('brand_accent', 'red;}body{display:none');

    expect(BrandAccent::css())->toBe('');

    app(SettingsService::class)->set('brand_accent', '#2E7D6B');

    expect(BrandAccent::css())
        ->toContain('--pink:#2E7D6B')
        ->and(BrandAccent::css())->toStartWith(':root,.kbb-checkout,.kbb-cart{');
});

it('reaches all six documents once the owner moves his brand colour', function () {
    /*
     * The finished state, asserted over HTTP on the rendered page rather than
     * at the model — which is the whole lesson of this lane: the layout looked
     * correct in the file and four URLs did not carry it.
     */
    app(SettingsService::class)->set('brand_accent', '#2E7D6B');

    /*
     * FOLLOWING REDIRECTS, because /skincare-guide/ is a 301 in this fixture --
     * the imported redirect table points it at /blog/ -- and a guard that
     * asserted about the redirect body would pass on an empty page. The address
     * the shopper lands on is the one that has to carry the colour.
     */
    $pages = ['/', '/shop/', '/skincare-guide/', '/reviews/', '/skin-quiz/'];

    foreach ($pages as $path) {
        $html = $this->followingRedirects()->get($path)->getContent();

        expect(str_contains($html, 'kbb-brand-accent'))
            ->toBeTrue($path.' does not carry the brand colour');
        expect(str_contains($html, '--pink:#2E7D6B'))
            ->toBeTrue($path.' carries the block without the colour in it');
    }
});

it('puts the accent after the document\'s own stylesheet, or it loses the tie', function () {
    /*
     * Specificity is equal — `:root` against `:root` — so source order is the
     * whole mechanism. Asserted on the rendered page: the document's own
     * `--pink:#E0567B` must appear BEFORE the accent block, not after it.
     */
    app(SettingsService::class)->set('brand_accent', '#2E7D6B');

    foreach (['/reviews/', '/skin-quiz/', '/skincare-guide/'] as $path) {
        $html = $this->followingRedirects()->get($path)->getContent();

        $own = strpos($html, '--pink:#E0567B');
        $accent = strpos($html, 'kbb-brand-accent');

        expect($own)->not->toBeFalse($path.' no longer declares its own --pink, so this guard asserts nothing');
        expect($accent)->toBeGreaterThan($own, $path.' emits the accent BEFORE its own stylesheet, so the'
            .' document wins the tie and the owner\'s colour never applies');
    }
});

it('keeps the composer and the partial answering the same question', function () {
    /*
     * The anti-drift guard. `$kbbAccent` is what the layout prints and
     * BrandAccent::pair() is what the five documents read; they were one
     * expression and a copy of the default before this lane, which is exactly
     * the shape that drifts.
     */
    app(SettingsService::class)->set('brand_accent', '#123456');

    $composed = null;
    view()->composer('layouts.store', function ($view) use (&$composed) {
        $composed = $view->getData()['kbbAccent'] ?? null;
    });

    $this->get('/');

    expect($composed)->toBe(BrandAccent::pair());
});
