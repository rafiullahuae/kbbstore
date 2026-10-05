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

/*
 * ═══════════════════════════════════════════════════════════════════════════
 *  THE AUDIT, MOVED OUT OF A DOCUMENT AND INTO THIS FILE          (Lane BG)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * docs/BG-STANDALONE-DOCUMENTS.md §1 carried a table of what
 * layouts/store.blade.php emits into <head> and which of the six documents
 * actually carried each of those things. Three rounds of this lane were spent
 * filling holes that table found, and every one of them had been open for
 * months because NOTHING ASSERTED THE TABLE. Prose in a document cannot go red.
 *
 * ── THE TABLE IS TAKEN AT MOVED SETTINGS, AND THAT IS THE WHOLE TRICK ─────
 *
 * Four of these emitters print NOTHING until the owner touches something —
 * that is the "zero bytes until you move a slider" guarantee every one of them
 * ships with. So an audit taken at the shipped defaults finds the brand colour,
 * the site width and the wash missing from all six documents and reports a
 * clean bill of health, because absent-because-nobody-asked and
 * absent-because-this-document-cannot-see-it look identical on the page.
 *
 * That is exactly the mistake round 2 nearly made. beforeEach below moves the
 * brand colour, the site width, the wash and the analytics id FIRST, so every
 * emitter in the table is speaking, and only then is the page read.
 *
 * ── WHAT IT CATCHES THAT THE CASES ABOVE DO NOT ───────────────────────────
 *
 *   - A SEVENTH standalone document. `sdhOwnHeadViews()` finds every view under
 *     store/ that carries its own <head>, so a new one fails here by name on
 *     the day it is added rather than being found by a later lane doing
 *     something else.
 *   - A NEW THING IN THE LAYOUT'S HEAD. `sdhLayoutHeadIncludes()` reads the
 *     partials the layout includes ABOVE its </head> and requires each to be
 *     either in all six documents or listed as layout-only with a reason.
 *
 * MUTATION NOTES, all four run in this lane's worktree:
 *   - delete @include('partials.outfit-face') from store/review-wall.blade.php
 *       → 'the audit' fails: `review wall / Latin webfont` flips to absent.
 *   - drop the beforeEach's brand-colour line
 *       → 'the audit' fails on SIX rows at once, which is the point of the
 *         moved settings: the emitter is silent, not missing.
 *   - add @include('partials.cart-row-css') to the layout's head
 *       → 'every partial in the layout head is accounted for' names it.
 *   - copy store/blog.blade.php to store/blog2.blade.php
 *       → 'every document that carries its own head is in the audit' names it.
 */

/** Every view under store/ that carries its own <head> instead of extending. */
function sdhOwnHeadViews(): array
{
    $out = [];

    foreach ((array) glob(resource_path('views/store/*.blade.php')) as $path) {
        $src = (string) file_get_contents((string) $path);

        // `@extends` is the discriminator, not the presence of the word head:
        // a view that extends the layout may still push a <style> onto a stack.
        if (! str_contains($src, '<head>') || str_contains($src, '@extends')) {
            continue;
        }

        $out[] = basename((string) $path);
    }

    sort($out);

    return $out;
}

/** The partials the layout includes ABOVE its </head>. */
function sdhLayoutHeadIncludes(): array
{
    $src = (string) file_get_contents(resource_path('views/layouts/store.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);

    $head = substr($src, 0, (int) strpos($src, '</head>'));

    preg_match_all("/@include\('partials\.([a-z0-9\-]+)'/i", $head, $m);

    $out = array_values(array_unique($m[1]));
    sort($out);

    return $out;
}

/**
 * The `<style id="kbb-…">` blocks the layout writes into its own head.
 *
 * The Latin webfont and the Arabic face are NOT includes in the layout — they
 * are inline style tags — so a guard that only reads @include misses exactly
 * the two emitters this lane spent two rounds pushing out to the other five
 * documents. Both shapes are read.
 */
function sdhLayoutHeadStyleIds(): array
{
    $src = (string) file_get_contents(resource_path('views/layouts/store.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);

    $head = substr($src, 0, (int) strpos($src, '</head>'));

    preg_match_all('/<style id="(kbb-[a-z0-9\-]+)"/i', $head, $m);

    $out = array_values(array_unique($m[1]));
    sort($out);

    return $out;
}

/**
 * The six documents and the URL each is served at.
 *
 * /app is admin-only — PageController::app() aborts 404 without an admin
 * session — so it is fetched as one. That is not a detail: it is the reason
 * /app is the one document this lane left on fonts.googleapis.com.
 */
function sdhDocuments(): array
{
    return [
        'home (the layout)' => ['/', false],
        'journal' => ['/blog/', false],
        'article' => ['/blog/sdh-audit-article/', false],
        'review wall' => ['/reviews/', false],
        'skin quiz' => ['/skin-quiz/', false],
        'app' => ['/app', true],
    ];
}

/** Each thing the layout puts in <head>, and the marker it leaves behind. */
function sdhEmitters(): array
{
    return [
        'SEO · canonical' => 'rel="canonical"',
        'analytics loader' => 'googletagmanager.com/gtag/js',
        'page wash' => 'id="kbb-page-wash"',
        'brand colour' => 'id="kbb-brand-accent"',
        'site width' => 'id="kbb-layout"',
        'Latin webfont' => 'id="kbb-outfit"',
    ];
}

/**
 * The Arabic face is measured on the MIRROR, not on the English page.
 *
 * It is gated on `Locale::current() !== Locale::DEFAULT` and emits nothing on
 * an English URL, which is correct and is the reason it cannot be a row in the
 * table above: on /shop/ its absence is the design, and on /ar/shop/ its
 * absence is the defect Lane FS fixed. Same emitter, opposite verdict, decided
 * entirely by which URL you read.
 */
function sdhArabicEmitter(): array
{
    return ['Arabic face' => 'id="kbb-arabic-face"'];
}

/**
 * Move everything the owner can move, and create the article the audit reads.
 *
 * NOT a beforeEach: three cases earlier in this file assert what a shop on its
 * SHIPPED settings emits, and a global hook would move the ground under them.
 */
function sdhMoveEverything(): void
{
    $settings = app(SettingsService::class);

    // The brand colour, off its shipped #E0567B.
    $settings->set('brand_accent', '#2E7D6B');

    // The site width, off its shipped 1680.
    app(\App\Services\SiteLayout::class)->save(['max' => 1440]);

    // The page wash, off its shipped `false`.
    app(\App\Services\PageWash::class)->save(['on' => true]);

    // Analytics: the module on, and a measurement id in it.
    \App\Models\ModuleToggle::query()->updateOrCreate(['module' => 'marketing_pixels'], ['enabled' => true]);
    $settings->set('ga', 'G-SDHAUDIT01');

    // Arabic ON, because the Arabic face is gated on the LANGUAGE -- it emits
    // nothing on an English URL, by design -- so the audit reads it on the
    // mirror. ArabicShop::on() sets the language and not the mirrored layout,
    // which is the shop's own default and is what its note explains.
    \Tests\Support\ArabicShop::on();

    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    \Illuminate\Support\Facades\Cache::flush();

    \App\Models\Post::query()->updateOrCreate(['slug' => 'sdh-audit-article'], [
        'title' => 'What the audit reads',
        'excerpt' => 'An article exists so the audit can read one.',
        'body' => '<p>'.str_repeat('An article body, long enough to render. ', 30).'</p>',
        'tag' => 'Ingredients',
        'status' => 'published',
        'published_at' => now()->subDays(3),
    ]);
}

it('emits every head block the layout emits, on all six documents, at moved settings', function () {
    sdhMoveEverything();

    /*
     * An owner to sign in as, because /app is admin-only. `role` is 'owner' so
     * no per-capability gate can quietly turn the last row of the audit into a
     * 404 that reads as a missing emitter.
     */
    $admin = \App\Models\AdminUser::create([
        'name' => 'Owner',
        'email' => 'sdh-audit-'.uniqid().'@example.com',
        'password' => bcrypt('secret-secret'),
        'role' => 'owner',
    ]);

    /*
     * THE EXPECTED TABLE. `true` means the document must carry it; a STRING
     * means it must NOT, and the string is the reason — which is the half of an
     * audit that rots first if it is not written down next to the assertion.
     */
    $expected = [
        'home (the layout)' => [],
        'journal' => [],
        'article' => [],
        'review wall' => [],
        'skin quiz' => [],
        'app' => [
            'Latin webfont' => 'admin-only (PageController::app() 404s a guest), noindex and linked'
                .' from nowhere, and built on Fraunces + Hanken Grotesk, which this shop does not'
                .' self-host. Converting it means adding two families for a page no shopper reaches;'
                .' docs/BG-STANDALONE-DOCUMENTS.md §3 carries the measurement and the recommendation.',
        ],
    ];

    $rows = [];
    $wrong = [];

    foreach (sdhDocuments() as $label => [$url, $needsAdmin]) {
        $request = $needsAdmin ? $this->actingAs($admin, 'admin') : $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
        $response = $request->followingRedirects()->get($url);

        expect($response->getStatusCode())->toBe(200, $label.' ('.$url.') did not render, so the audit read nothing');

        $html = (string) $response->getContent();
        $head = substr($html, 0, (int) strpos($html, '</head>') ?: strlen($html));

        $cells = [];

        foreach (sdhEmitters() as $what => $marker) {
            $present = str_contains($head, $marker);
            $mustBeAbsent = $expected[$label][$what] ?? null;
            $cells[] = ($present ? '✅' : '❌').' '.$what;

            if ($mustBeAbsent === null && ! $present) {
                $wrong[] = sprintf('  %-18s MISSING  %-18s (marker %s)', $label, $what, $marker);
            }

            if ($mustBeAbsent !== null && $present) {
                $wrong[] = sprintf('  %-18s carries   %-18s which this table says it does not:'
                    ."\n      %s\n      Advance the table, do not delete the row.", $label, $what, $mustBeAbsent);
            }
        }

        /*
         * AND THE SAME DOCUMENT ON THE ARABIC MIRROR, for the one emitter whose
         * verdict depends on the URL. /ar/app is included: it is admin-only on
         * both sides.
         */
        $arabic = $needsAdmin ? $this->actingAs($admin, 'admin') : $this;
        $arResponse = $arabic->followingRedirects()->get('/ar'.$url);

        if ($arResponse->getStatusCode() === 200) {
            $arHead = (string) $arResponse->getContent();
            $arHead = substr($arHead, 0, (int) strpos($arHead, '</head>') ?: strlen($arHead));

            foreach (sdhArabicEmitter() as $what => $marker) {
                $present = str_contains($arHead, $marker);
                $cells[] = ($present ? '✅' : '❌').' '.$what.' (/ar)';

                if (! $present) {
                    $wrong[] = sprintf('  %-18s MISSING  %-18s on /ar%s (marker %s)',
                        $label, $what, $url, $marker);
                }
            }
        } else {
            $cells[] = '— '.$arResponse->getStatusCode().' on /ar';
            $wrong[] = sprintf('  %-18s /ar%s answered %d, so the Arabic face could not be audited'
                .' there. Arabic is switched ON by sdhMoveEverything(); a 404 here means the mirror'
                .' does not serve this document at all.', $label, $url, $arResponse->getStatusCode());
        }

        $rows[] = sprintf('  %-18s %s', $label, implode('  ', $cells));
    }

    expect($wrong)->toBe([], "the head audit does not match the table:\n".implode("\n", $wrong)
        ."\n\nmeasured:\n".implode("\n", $rows)
        ."\n\nEvery emitter above was MOVED off its shipped value first, so ❌ means"
        ." the document cannot see it — not that nobody asked for it.");
});

it('gives the journal and an article the designed page background, and leaves the designed ones alone', function () {
    /*
     * The page background does not have ONE marker across the six, which is
     * why it is its own case rather than a row in the table above: the layout
     * gets it from kbb.css, the two documents that could not get it from
     * kbb.css now carry partials/page-background-css, and two more are
     * compositions in their own named colours that must NOT be flattened.
     *
     * Measured with getComputedStyle before this lane touched them:
     *   home, /shop/, product, cart   rgb(253,239,243) + 5 layers
     *   the journal, an article       rgb(255,255,255) + none      ← the defect
     *   the review wall               rgb(255,248,245) + its cream fade
     *   the skin quiz                 transparent + 11 layers
     */
    sdhMoveEverything();

    $carries = [
        'journal' => ['/blog/', 'id="kbb-page-background"'],
        'article' => ['/blog/sdh-audit-article/', 'id="kbb-page-background"'],
        'review wall' => ['/reviews/', 'linear-gradient(180deg,#fff,var(--cream) 520px)'],
        'skin quiz' => ['/skin-quiz/', 'radial-gradient(55% 45% at 88% -6%,#FFD3E4,transparent 70%)'],
    ];

    foreach ($carries as $label => [$url, $marker]) {
        $html = (string) $this->followingRedirects()->get($url)->getContent();

        expect(str_contains($html, $marker))->toBeTrue($label.' ('.$url.') no longer carries its page'
            .' background: '.$marker);
    }

    /*
     * AND THE OWNER'S WASH STILL BEATS IT, which is the half of this that a
     * later lane would break by tidying the includes into a nicer order.
     *
     * The designed background is the SHIPPED design; the wash is a choice the
     * owner made on Appearance → Page background. Both write `body`, so they
     * tie on specificity and source order is the whole mechanism — and this one
     * has to come FIRST, the opposite of what the brand accent asks for.
     * layouts/store.blade.php has the same order: kbb.css, then the wash.
     *
     * Measured in Chromium with the wash on: the journal and an article compute
     * rgb(252,242,241) with 0 layers, which is exactly what the home page
     * computes — so the two documents now agree with the shop in BOTH states,
     * where before they were white in one of them.
     *
     * MUTATION: move @include('partials.page-background-css') below
     * @include('partials.page-wash-css') in store/blog.blade.php → red here,
     * and the journal renders the shipped pink while the owner's wash is on.
     */
    foreach (['/blog/', '/blog/sdh-audit-article/'] as $url) {
        $html = (string) $this->followingRedirects()->get($url)->getContent();

        $designed = strpos($html, 'id="kbb-page-background"');
        $wash = strpos($html, 'id="kbb-page-wash"');

        expect($designed)->not->toBeFalse($url.' does not carry the designed background');
        expect($wash)->not->toBeFalse($url.' does not carry the wash, so this guard asserts nothing');
        expect($wash)->toBeGreaterThan($designed, $url.' emits the wash BEFORE the designed'
            .' background, so the shipped design outranks the owner\'s own choice');
    }

    /*
     * AND EXACTLY ONCE. Zero is the "built, never wired up" shape; two emits
     * the same 9 KB of artwork twice into one head.
     */
    foreach (['store/blog.blade.php', 'store/post.blade.php'] as $view) {
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '',
            (string) file_get_contents(resource_path('views/'.$view)));

        expect(substr_count($src, "@include('partials.page-background-css')"))
            ->toBe(1, $view.' does not include the page background exactly once');
    }
});

it('includes the webfont partial exactly once in each document that needs it', function () {
    /*
     * The finished state, pinned as a COUNT rather than as an absence.
     *
     * CLAUDE.md is explicit about this and this repository has paid for it
     * three times: a lane that cannot wire itself up is tempted to assert that
     * it is NOT wired up, and that assertion goes red the moment the integrator
     * does the one thing the lane asked for. So the pin is 1, which is green
     * here and green after any merge.
     *
     * ZERO is the "built, never wired up" shape. TWO emits the four preloads
     * and all fifteen @font-face rules twice into one head -- and
     * StorefrontEnglishUnchangedTest's insertion rule would report it as a page
     * carrying an approved element twice, which is the same defect seen from
     * the other end.
     *
     * /app is deliberately absent and asserted as 0: it is admin-only, noindex
     * and built on two families this shop does not self-host. See the audit's
     * expected table and docs/BG-STANDALONE-DOCUMENTS.md §3.5.
     */
    $expected = [
        'store/blog.blade.php' => 1,
        'store/post.blade.php' => 1,
        'store/review-wall.blade.php' => 1,
        'store/skin-quiz.blade.php' => 1,
        'store/app.blade.php' => 0,
    ];

    $wrong = [];

    foreach ($expected as $view => $want) {
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '',
            (string) file_get_contents(resource_path('views/'.$view)));

        $got = substr_count($src, "@include('partials.outfit-face')");

        if ($got !== $want) {
            $wrong[] = sprintf('  %-32s includes it %d times, expected %d', $view, $got, $want);
        }
    }

    expect($wrong)->toBe([], "a document does not carry the webfont partial the expected number of"
        ." times:\n".implode("\n", $wrong)
        ."\n\n0 where 1 is expected is a page back on fonts.googleapis.com; 2 emits four preloads"
        .' and fifteen @font-face rules twice into one head.');

    // and the layout keeps serving it its own way, inline rather than by include
    $layout = (string) file_get_contents(resource_path('views/layouts/store.blade.php'));
    expect(substr_count($layout, '<style id="kbb-outfit">'))
        ->toBe(1, 'layouts/store.blade.php no longer emits the Poppins faces exactly once');
});

it('keeps the copied background declaration identical to the one in kbb.css', function () {
    /*
     * THE ANTI-DIVERGENCE PIN, and the whole reason a copy of the page
     * background is tolerable in partials/page-background-css.blade.php.
     * kbb.css keeps its own copy because moving it into a separate stylesheet
     * would add a render-blocking request to the forty pages that already fetch
     * kbb.css -- docs/BG-STANDALONE-DOCUMENTS.md §5 costs that out -- so the two
     * have to be held identical by something that fails.
     *
     * ▲ WHAT IS PINNED CHANGED WHEN THE DRAWING CAME OFF.
     *
     * This used to require the partial to carry kbb.css's 9,130-byte
     * `--bg-botanical` declaration byte for byte. The owner asked for that
     * drawing to come off the page background, so neither file carries it any
     * more and the pin would have been asserting the absence of nothing. What
     * the two files still share -- and what a lane can still edit in one and
     * not the other -- is the gradient declaration and the body rule that uses
     * it, so that is what this holds together now.
     *
     * MUTATION: change one byte of the gradient in either file → red, naming
     * both. Run, both directions.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $partial = (string) file_get_contents(resource_path('views/partials/page-background-css.blade.php'));

    $start = strpos($css, '--kbb-page-gradient:');

    expect($start)->not->toBeFalse('kbb.css no longer declares --kbb-page-gradient, so this guard'
        .' asserts nothing. If the page background moved, move this with it.');

    $declaration = substr($css, (int) $start, (int) strpos($css, "\n", (int) $start) - (int) $start);
    $declaration = rtrim($declaration);

    expect(strlen($declaration))->toBeGreaterThan(40,
        'the extracted gradient declaration is too short to be the page background');

    expect(str_contains($partial, $declaration))->toBeTrue(
        'partials/page-background-css.blade.php and kbb.css no longer carry the SAME'
        .' --kbb-page-gradient declaration. One of them was edited on its own; the journal and an'
        ." article would now render a different background from every other page.\n"
        .'  kbb.css: '.$declaration);

    /*
     * AND NEITHER OF THEM PUTS THE DRAWING BACK. The owner asked for it off the
     * page background; a lane that reinstates it in either file fails here
     * rather than on somebody noticing it on the shop.
     */
    $bodyRuleStart = strpos($css, 'body{'."\n".'  background-color:#FDEFF3;');

    expect($bodyRuleStart)->not->toBeFalse('kbb.css no longer has the page-background body rule');

    $bodyRule = substr($css, (int) $bodyRuleStart, (int) strpos($css, '}', (int) $bodyRuleStart) - (int) $bodyRuleStart + 1);

    /*
     * THE PARTIAL'S EMITTED CSS, NOT THE WHOLE FILE — and this caught itself.
     *                                                          (Lane BG)
     * Written as `$partial`, the check below went red on a correct file: the
     * partial's own header comment explains at length that it USED to carry
     * `--bg-botanical` and no longer does, and that prose satisfied the needle.
     * The same shape this lane spent round 5 removing from three other files,
     * in the assertion written to guard against it. The emitted stylesheet
     * starts at the style tag; everything before it is commentary.
     */
    $emitted = substr($partial, (int) strpos($partial, '<style id="kbb-page-background">'));

    foreach ([['kbb.css', $bodyRule], ['the partial', $emitted]] as [$label, $haystack]) {
        expect(str_contains($haystack, '--bg-botanical'))->toBeFalse(
            $label.' puts the botanical drawing back on the page background. The owner asked for'
            .' it off: "you put some image on background of the whole site, which i don\'t want".'
            .' Appearance → Page background → "Botanical drawing" is how it comes back, and it'
            .' ships off.');

        expect(str_contains($haystack, 'repeat-y'))->toBeFalse(
            $label.' tiles the page background down the page again -- the "not continue type" the'
            .' owner asked to be rid of.');
    }
});

it('finds every document that carries its own head, and everything in the layout head', function () {
    /*
     * The two ways this audit goes stale, both made loud.
     */
    expect(sdhOwnHeadViews())->toBe([
        'app.blade.php',
        'blog.blade.php',
        'post.blade.php',
        'review-wall.blade.php',
        'skin-quiz.blade.php',
    ], 'a view under store/ carries its own <head> and is not in this audit. Add it to'
        .' sdhDocuments(), give it the partials the layout emits, and say so in'
        .' docs/BG-STANDALONE-DOCUMENTS.md — that is the whole job this lane has been doing'
        .' five documents at a time.');

    /*
     * Everything in the layout's head is either shared with all six documents
     * or listed as layout-only WITH THE REASON. Both shapes are checked, because
     * the two emitters that turned out to be missing from five documents were
     * an inline <style> and an @include respectively.
     */
    $shared = [
        'shop-appearance-css' => 'the brand colour and the site width',
        'page-wash-css' => 'Appearance → Page background',
        'kbb-outfit' => 'the self-hosted Latin webfont',
        'kbb-arabic-face' => 'the Arabic face',
        'kbb-cairo' => 'the Arabic face files',
        // Lane PW: the Home Screen app's tags. The journal, the article, the
        // review wall and the quiz include the partial themselves, so the app
        // can be installed from any shopper page; the admin-only App Preview
        // (store/app.blade.php) is not one. StorefrontEnglishUnchangedTest
        // counts the block on all 37 pages.
        'site-app-head' => 'the Home Screen app: manifest, apple-touch-icon and the worker registration',
    ];

    $layoutOnly = [
        'set-appearance-css' => 'styles .kset-* only, and no set is rendered on any of the five',
        /*
         * Lane PI-B. Appearance → Header → Breadcrumbs: it styles the
         * breadcrumb trail and nothing else, and of the five documents with a
         * head of their own only the ARTICLE draws a trail — so post.blade.php
         * includes the partial itself, and BreadcrumbControlsTest's "hides the
         * trail on every storefront page that draws one, the journal article
         * included" is red if it ever stops. The journal index, the review
         * wall and the quiz have no trail to hide (their "Home" is a nav link),
         * and app.blade.php is the admin-only App Preview.
         */
        'breadcrumb-css' => 'styles the breadcrumb trail only; the article, the one standalone document with a trail, includes it itself',
    ];

    foreach ([...sdhLayoutHeadIncludes(), ...sdhLayoutHeadStyleIds()] as $thing) {
        $classified = array_key_exists($thing, $shared) || array_key_exists($thing, $layoutOnly);

        expect($classified)->toBeTrue("layouts/store.blade.php puts `{$thing}` in its <head> and this"
            ." audit does not know about it.\n\nEither the five documents that carry their own <head>"
            ." need it too — which is what the brand colour, the site width, the Arabic face, the wash,"
            ." the Latin webfont and the page background each turned out to be — or it is layout-only"
            ." and belongs in \$layoutOnly with the reason why.");
    }

    /*
     * And the shared ones are still THERE. Without this half, deleting the
     * webfont from the layout would make this case pass by having less to check.
     */
    $present = [...sdhLayoutHeadIncludes(), ...sdhLayoutHeadStyleIds()];

    foreach (array_keys($shared) as $thing) {
        expect(in_array($thing, $present, true))->toBeTrue('the layout head no longer carries `'
            .$thing.'` ('.$shared[$thing].'), so this guard asserts less than it says');
    }
});
