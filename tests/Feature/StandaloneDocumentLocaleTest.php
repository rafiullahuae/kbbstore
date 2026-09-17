<?php

declare(strict_types=1);

/**
 * The five storefront documents that are not layouts/store.blade.php.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * layouts/store.blade.php writes <html lang="{{ $kbbLocale }}" dir="{{ $kbbDir }}">
 * and every page that extends it has been correct since the bilingual work
 * landed. Five pages do not extend it -- the Journal index, an article, the
 * skin quiz, the review wall and the app preview -- and each carries its own
 * <html> element with the language written in as the literal "en".
 *
 * So /ar/skincare-guide/, /ar/skin-quiz/, /ar/reviews/ and an Arabic article
 * all answered 200 and all four declared themselves English. Lane FJ reported
 * the Journal; fetching the other four against a running preview with Arabic
 * switched on found the same literal in all of them.
 *
 * ── WHY IT IS WORTH A TEST AND NOT JUST A FIX ──────────────────────────────
 *
 * Because the class of defect is "a second copy of a decision the shared layout
 * already makes", and a sixth standalone document can be added tomorrow. The
 * list below is walked rather than asserted one page at a time, and the two
 * halves -- Arabic says Arabic, English still says English -- are both here, so
 * a fix that hard-codes "ar" instead of "en" is caught by the second half.
 *
 * ── THE RTL SWITCH IS SEPARATE, AND STAYS SEPARATE ─────────────────────────
 *
 * `dir` comes from Locale::direction() and never from the language: this shop
 * can have Arabic live while the mirrored stylesheet is still being built, and
 * BilingualFoundationTest pins that pair for the shared layout. The same pair
 * is pinned here for the five documents that were missing it entirely.
 *
 * ── MUTATIONS THIS CATCHES ────────────────────────────────────────────────
 *
 *   - reverting any one of the five templates to lang="en"      (the Arabic half)
 *   - hard-coding lang="ar" instead                             (the English half)
 *   - taking dir from the language rather than from direction()  (the rtl-off case)
 *   - putting the <html> element back inside the verbatim block, where Blade
 *     does not interpolate: the attribute then renders as the literal
 *     {{ ... }} source, which the regex below refuses.
 */

use App\Models\AdminUser;
use App\Models\Post;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;

/** Arabic on, the way the Translation settings screen turns it on. */
function sdlArabicOn(bool $rtl = true): void
{
    Setting::query()->updateOrCreate([  'key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => $rtl ? '1' : '0', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();
}

function sdlPost(): Post
{
    return Post::firstOrCreate(
        ['slug' => 'sdl-heartleaf'],
        [
            'title' => 'Heartleaf, explained',
            'excerpt' => 'A short one.',
            'body' => '<p>Houttuynia cordata calms redness.</p>',
            'tag' => 'Ingredients',
            'author' => 'KBB',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]
    );
}

/**
 * Every standalone document, as uri => a short name for the failure message.
 *
 * /app is behind the admin guard (PageController::app 404s a logged-out
 * visitor), so its case signs in first. It is included rather than skipped
 * because it carries the same literal and the same owner.
 *
 * @return array<string, string>
 */
function sdlDocuments(): array
{
    return [
        '/skincare-guide/' => 'the Journal index',
        '/' . sdlPost()->slug . '/' => 'an article',
        '/skin-quiz/' => 'the skin quiz',
        '/reviews/' => 'the review wall',
        '/app/' => 'the app preview',
    ];
}

/**
 * The document element of a rendered page.
 *
 * Matched on the OPENING TAG only and returned whole, so an assertion can look
 * at the attributes that are there rather than at a substring that might have
 * come from anywhere on the page -- a class-name search of rendered HTML also
 * matches the page's own inlined CSS, which is how a guard in this repository
 * once asserted against a <style> block.
 */
function sdlHtmlTag(string $uri, bool $admin = false): string
{
    $request = $admin
        ? test()->actingAs(AdminUser::create([
            'name' => 'SDL Owner',
            'email' => 'sdl-owner-' . uniqid() . '@example.test',
            'password' => 'secret-secret',
            'role' => 'owner',
        ]), 'admin')
        : test();

    $html = $request->get($uri)->assertOk()->getContent();

    expect(preg_match('#<html\b[^>]*>#', $html, $m))->toBe(1, "No <html> element on {$uri}.");

    return $m[0];
}

it('declares Arabic and right-to-left on every standalone document', function () {
    sdlArabicOn();

    foreach (sdlDocuments() as $uri => $name) {
        $tag = sdlHtmlTag('/ar' . rtrim($uri, '/') . '/', $uri === '/app/');

        expect($tag)->toBe(
            '<html lang="ar" dir="rtl">',
            "{$name} served under /ar/ and did not declare itself Arabic: {$tag}"
        );
    }
});

it('leaves the English document English', function () {
    sdlArabicOn();

    foreach (sdlDocuments() as $uri => $name) {
        $tag = sdlHtmlTag($uri, $uri === '/app/');

        expect($tag)->toBe(
            '<html lang="en" dir="ltr">',
            "{$name} stopped declaring itself English: {$tag}"
        );
    }
});

it('keeps the language and the mirrored layout as two separate switches', function () {
    // Arabic live, RTL still off: the mid-rollout state the owner asked to be
    // able to reach. The language moves; the direction does not.
    sdlArabicOn(rtl: false);

    foreach (sdlDocuments() as $uri => $name) {
        $tag = sdlHtmlTag('/ar' . rtrim($uri, '/') . '/', $uri === '/app/');

        expect($tag)->toBe(
            '<html lang="ar" dir="ltr">',
            "{$name} took its direction from the language rather than from Locale::direction(): {$tag}"
        );
    }
});

it('interpolates the attributes rather than printing the Blade source', function () {
    // The failure mode of putting the element back inside the verbatim block.
    sdlArabicOn();

    foreach (sdlDocuments() as $uri => $name) {
        $tag = sdlHtmlTag('/ar' . rtrim($uri, '/') . '/', $uri === '/app/');

        // Not ->not->toContain(): toContain() is variadic in Pest, so a message
        // passed beside the needle silently becomes a SECOND needle and the
        // assertion passes whatever the value is.
        expect(str_contains($tag, '{{'))
            ->toBeFalse("{$name} printed the Blade expression instead of evaluating it: {$tag}");
    }
});

it('serves the English document with no locale segment at all', function () {
    // Arabic OFF, which is how this ships. htmlLang() is 'en' and direction()
    // is 'ltr', so the five documents are what they always were plus the dir
    // attribute the shared layout has always emitted.
    foreach (sdlDocuments() as $uri => $name) {
        $tag = sdlHtmlTag($uri, $uri === '/app/');

        expect($tag)->toBe('<html lang="en" dir="ltr">', "{$name}: {$tag}");
    }

    expect(Locale::enabledCodes())->toBe(['en']);
});
