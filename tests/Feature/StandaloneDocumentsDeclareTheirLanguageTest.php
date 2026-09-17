<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Post;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;

/**
 * THE FIVE PAGES THAT CARRY THEIR OWN <html>.
 *
 * store/blog, store/post, store/skin-quiz, store/app and store/review-wall do
 * not extend layouts/store.blade.php. They are whole documents, and each of
 * them opened with a hard-coded `<html lang="en">` and no `dir` at all — so
 * once /ar/ existed, /ar/skincare-guide/ served Arabic chrome, an Arabic
 * canonical and an Arabic hreflang set inside a document that declared itself
 * English. That is wrong for every screen reader, hyphenator, spell checker and
 * translation tool that reads the attribute, on the pages a shopper is most
 * likely to read with one.
 *
 * ── THE TWO SWITCHES, WHICH THIS TEST PINS AND DOES NOT ARGUE WITH ──────────
 *
 * `lang` follows the language. `dir` DOES NOT — it goes through
 * Locale::direction(), which answers 'rtl' only when the mirrored layout has
 * also been switched on. Arabic can be live while the mirrored stylesheet is
 * still being built, and direction() is the single place that knows which of
 * the two states the shop is in. resources/views/invoices/document.blade.php
 * argues this out at length and layouts/store.blade.php already does it; these
 * five now do the same thing, which is why the middle case below is the one
 * that matters most: ARABIC ON, MIRRORED OFF must give lang="ar" dir="ltr".
 *
 * ── WHAT THIS DOES NOT CLAIM ────────────────────────────────────────────────
 *
 * Nothing about the layout. Each of these documents carries its own inline
 * stylesheet and its own webfont link, and turning `dir` on exposes what those
 * do not cover. Measured and written down in docs/rtl-standalone-documents.md
 * rather than papered over here: two physical `left` rules that will not
 * mirror, and — the larger one — no Arabic-capable webfont on any of the five.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function langState(bool $arabic, bool $mirrored): void
{
    $settings = app(SettingsService::class);
    $settings->set(Locale::SETTING_ENABLED, $arabic ? '1' : '0');
    $settings->set(Locale::SETTING_RTL, $mirrored ? '1' : '0');
    $settings->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/** The five documents, plus /shop/ as the control that uses the shared layout. */
function standaloneDocuments(): array
{
    return [
        '/skincare-guide/' => 'store/blog',
        '/lang-guide-post/' => 'store/post',
        '/skin-quiz/' => 'store/skin-quiz',
        '/app/' => 'store/app',
        '/reviews/' => 'store/review-wall',
        '/shop/' => 'layouts/store (control)',
    ];
}

function langSeed(): void
{
    Post::updateOrCreate(
        ['slug' => 'lang-guide-post'],
        ['title' => 'A Guide', 'body' => '<p>Body.</p>', 'status' => 'published', 'published_at' => now()]
    );

    // /app/ is admin-only; without a session it 404s and every assertion below
    // it would be made about an error page.
    test()->actingAs(AdminUser::create([
        'name' => 'Lang Owner',
        'email' => 'lang-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]), 'admin');
}

/** The document's own opening tag, or a description of why there is none. */
function htmlTag(string $url): string
{
    $response = test()->get($url);

    if ($response->status() !== 200) {
        return '(HTTP ' . $response->status() . ')';
    }

    return preg_match('/<html[^>]*>/', $response->getContent(), $m) === 1 ? $m[0] : '(no <html> element)';
}

it('serves all five documents and the control, so the sweeps below are asked of real pages', function () {
    langSeed();
    langState(arabic: true, mirrored: false);

    foreach (standaloneDocuments() as $url => $view) {
        expect(test()->get($url)->status())->toBe(200, "{$view} did not render at {$url}");
        expect(test()->get('/ar' . $url)->status())->toBe(200, "{$view} did not render at /ar{$url}");
    }
});

it('declares English on an English URL, whatever the switches say', function () {
    langSeed();

    foreach ([[false, false], [true, false], [true, true]] as [$arabic, $mirrored]) {
        langState($arabic, $mirrored);

        foreach (standaloneDocuments() as $url => $view) {
            expect(htmlTag($url))->toBe(
                '<html lang="en" dir="ltr">',
                "{$view} at {$url} with arabic=" . var_export($arabic, true) . ' mirrored=' . var_export($mirrored, true)
            );
        }
    }
});

it('declares Arabic on an Arabic URL', function () {
    langSeed();
    langState(arabic: true, mirrored: false);

    foreach (standaloneDocuments() as $url => $view) {
        expect(htmlTag('/ar' . $url))->toContain('lang="ar"');
    }
});

it('leaves dir alone until the mirrored layout is switched on, which is the shop two switches', function () {
    langSeed();

    // Arabic on, mirrored OFF. This is the shipped state the moment Arabic is
    // enabled, and the one that would be wrong if `dir` followed the language.
    langState(arabic: true, mirrored: false);

    foreach (standaloneDocuments() as $url => $view) {
        expect(htmlTag('/ar' . $url))->toBe(
            '<html lang="ar" dir="ltr">',
            "{$view} mirrored itself on an Arabic URL while the mirrored layout is still switched off"
        );
    }

    // Both on.
    langState(arabic: true, mirrored: true);

    foreach (standaloneDocuments() as $url => $view) {
        expect(htmlTag('/ar' . $url))->toBe(
            '<html lang="ar" dir="rtl">',
            "{$view} did not mirror although the mirrored layout is switched on"
        );
    }
});

it('reads dir out of Locale::direction() and not out of the language', function () {
    // The contract the five documents were changed to honour, asked of the
    // helper itself so that a page that agrees with it by accident does not
    // stand in for one that agrees with it on purpose.
    langState(arabic: true, mirrored: false);

    expect(Locale::direction('ar'))->toBe('ltr');
    expect(Locale::htmlLang('ar'))->toBe('ar');

    langState(arabic: true, mirrored: true);

    expect(Locale::direction('ar'))->toBe('rtl');
    expect(Locale::direction('en'))->toBe('ltr');
});

it('leaves no hard-coded lang or dir in any storefront document', function () {
    // The five were found by reading every view that opens its own <html>;
    // this is what stops a sixth arriving.
    $offenders = [];
    $checked = 0;

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($it as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $relative = str_replace(resource_path('views') . '/', '', $file->getPathname());

        // The admin console and the Laravel welcome page are not storefront
        // documents: neither is ever served under /ar, and admin/app.blade.php
        // is a file this lane may not edit.
        if (str_starts_with($relative, 'admin/') || $relative === 'welcome.blade.php') {
            continue;
        }

        /*
         * COMMENTS STRIPPED FIRST, in all three forms this repository writes
         * them, and not for tidiness. Several of these files -- the ones this
         * lane just changed, layouts/store.blade.php in a PHP block comment,
         * and the email layout, which deliberately has no <html> at all --
         * discuss the <html> element in prose. A sweep that reads those reports
         * the commentary rather than the markup, which is a guard that fails on
         * the wrong thing and teaches the next reader to ignore it.
         */
        $source = preg_replace(
            ['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s', '#/\*.*?\*/#s'],
            '',
            file_get_contents($file->getPathname())
        );

        if (! preg_match_all('/<html[^>]*>/i', (string) $source, $m)) {
            continue;
        }

        $checked++;

        foreach ($m[0] as $tag) {
            if (preg_match('/lang\s*=\s*["\'](?!\{)/i', $tag) === 1) {
                $offenders[] = $relative . '  ' . $tag . '  — lang is hard-coded; use Locale::htmlLang()';
            }

            if (preg_match('/dir\s*=\s*["\'](?!\{)/i', $tag) === 1) {
                $offenders[] = $relative . '  ' . $tag . '  — dir is hard-coded; use Locale::direction()';
            }

            if (preg_match('/\bdir\s*=/i', $tag) !== 1) {
                $offenders[] = $relative . '  ' . $tag . '  — the document states no direction at all';
            }
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(6);
    expect($offenders)->toBe([], "\n" . implode("\n", $offenders) . "\n");
});
