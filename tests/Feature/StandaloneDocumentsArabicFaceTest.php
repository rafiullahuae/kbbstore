<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Post;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\ArabicFace;
use App\Support\Locale;
use Tests\Support\CssDirection;

/**
 * THE FIVE DOCUMENTS THAT CARRY THEIR OWN <head>, AND THE FACE THEY DID NOT ASK FOR.
 *
 * ArabicFaceTest pins the same contract for layouts/store.blade.php. This file
 * pins it for store/blog, store/post, store/skin-quiz, store/app and
 * store/review-wall, which do not use that layout and got none of it:
 * /ar/skincare-guide/, /ar/<post>/, /ar/skin-quiz/ and /ar/reviews/ linked
 * Poppins only, /ar/app/ linked Fraunces and Hanken Grotesk, and all five named
 * Cairo ZERO times against five font-family rules on the /ar/shop/ control
 * (docs/rtl-standalone-documents.md §2, reproduced before this change).
 *
 * MEASURED IN A BROWSER, not inferred from the markup. The same Arabic string at
 * 40px, set in the page's own inherited stack versus in Cairo served from
 * Google's own bytes, with each character's box read back so the comparison is
 * of painted glyphs:
 *
 *                       page stack            Cairo
 *   before   400        343.67 / 365.91       337.61     matched neither
 *   before   700        411.91 / 343.67       379.48
 *   before   800        411.91 / 343.67       393.86
 *   after    400        337.61                337.61     matches exactly
 *   after    700        379.48                379.48
 *   after    800        393.86                393.86
 *
 * and every text-bearing element on all five moved onto a Cairo-capable stack.
 * docs/FS-ARABIC-TYPOGRAPHY.md carries the whole table and the method.
 *
 * WHAT THIS FILE CAN AND CANNOT SAY. It cannot measure a glyph; it pins the
 * three things that made the measurement come out right and that are each easy
 * to undo by hand:
 *
 *   1. the face is LINKED and NAMED on an Arabic page, in every one of the five;
 *   2. it is APPENDED after the document's own Latin family, never substituted;
 *   3. the English page gets none of it, and the gate is the LANGUAGE rather
 *      than Locale::isRtl().
 *
 * Read with Tests\Support\CssDirection, the repository's own declaration-aware
 * CSS reader, rather than with a regex over the view: these documents discuss
 * their own font stacks in comments, and a regex reads a comment as code.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

/** The five, plus /shop/ as the control that already had the face. */
function fsFaceDocuments(): array
{
    return [
        '/skincare-guide/' => 'store/blog',
        '/fs-face-post/' => 'store/post',
        '/skin-quiz/' => 'store/skin-quiz',
        '/app/' => 'store/app',
        '/reviews/' => 'store/review-wall',
        '/shop/' => 'layouts/store (control)',
    ];
}

function fsFaceState(bool $arabic, bool $mirrored): void
{
    $s = app(SettingsService::class);
    $s->set(Locale::SETTING_ENABLED, $arabic ? '1' : '0');
    $s->set(Locale::SETTING_RTL, $mirrored ? '1' : '0');
    $s->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
}

function fsFaceSeed(): void
{
    Post::updateOrCreate(
        ['slug' => 'fs-face-post'],
        ['title' => 'A Guide', 'body' => '<p>Body.</p>', 'status' => 'published', 'published_at' => now()]
    );

    // /app/ is admin-only; without a session every assertion below it would be
    // made about a 404 page.
    test()->actingAs(AdminUser::create([
        'name' => 'Face Owner',
        'email' => 'fs-face-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]), 'admin');
}

/**
 * Every font-family and font custom property the page's own <style> blocks
 * declare, read as CSS rather than matched as text.
 *
 * @return list<array{selector: string, property: string, value: string, line: int}>
 */
function fsFaceFontDeclarations(string $html): array
{
    $out = [];

    if (preg_match_all('#<style[^>]*>(.*?)</style>#is', $html, $blocks)) {
        foreach ($blocks[1] as $css) {
            foreach (CssDirection::declarations($css) as $d) {
                if ($d['property'] === 'font-family' || str_starts_with($d['property'], '--')) {
                    $out[] = $d;
                }
            }
        }
    }

    return $out;
}

/** The declarations above that actually name Cairo. */
function fsFaceCairoStacks(string $html): array
{
    return array_values(array_filter(
        fsFaceFontDeclarations($html),
        static fn (array $d): bool => stripos($d['value'], 'cairo') !== false
    ));
}

it('serves all six documents in Arabic, so every sweep below is asked of a real page', function () {
    fsFaceSeed();
    fsFaceState(arabic: true, mirrored: true);

    expect(fsFaceDocuments())->toHaveCount(6);

    foreach (fsFaceDocuments() as $url => $view) {
        expect(test()->get('/ar' . $url)->status())->toBe(200, "{$view} did not render at /ar{$url}");
    }
});

it('links the Cairo stylesheet from every one of the five, not only from the shared layout', function () {
    fsFaceSeed();
    fsFaceState(arabic: true, mirrored: true);

    $missing = [];

    expect(fsFaceDocuments())->toHaveCount(6);

    foreach (fsFaceDocuments() as $url => $view) {
        $html = test()->get('/ar' . $url)->getContent();

        if (! str_contains($html, 'family=Cairo')) {
            $missing[] = "{$view} at /ar{$url}";
        }
    }

    expect($missing)->toBe([], "These Arabic documents link no Arabic-capable webfont at all:\n" . implode("\n", $missing));
});

it('names Cairo in a real font stack on every one of the five, which a link alone does not do', function () {
    fsFaceSeed();
    fsFaceState(arabic: true, mirrored: true);

    // The defect this whole lane exists for: layouts/store.blade.php carried a
    // correct <link> for months while no stack in the storefront mentioned the
    // family, so the browser fetched 30 KB and used none of it.
    $silent = [];

    expect(fsFaceDocuments())->toHaveCount(6);

    foreach (fsFaceDocuments() as $url => $view) {
        $html = test()->get('/ar' . $url)->getContent();
        $stacks = fsFaceCairoStacks($html);

        if ($stacks === []) {
            $silent[] = sprintf(
                '%s at /ar%s links Cairo and then never asks for it — %d font declarations on the page, none naming it',
                $view,
                $url,
                count(fsFaceFontDeclarations($html))
            );
        }
    }

    expect($silent)->toBe([], implode("\n", $silent));
});

it('appends Cairo after the document own Latin family instead of substituting for it', function () {
    fsFaceSeed();
    fsFaceState(arabic: true, mirrored: true);

    $wrong = [];
    $checked = 0;

    foreach (fsFaceDocuments() as $url => $view) {
        $html = test()->get('/ar' . $url)->getContent();

        foreach (fsFaceCairoStacks($html) as $d) {
            $checked++;

            $families = array_map(
                static fn (string $f): string => strtolower(trim($f, " \t\"'")),
                explode(',', $d['value'])
            );

            if (($families[0] ?? '') === 'cairo') {
                $wrong[] = sprintf(
                    '%s: `%s: %s` puts Cairo first, which substitutes the brand face rather than backing it up.',
                    $view,
                    $d['property'],
                    $d['value']
                );
            }
        }
    }

    // A loop that does not run asserts nothing, and this file is the wrong place
    // to learn that the hard way.
    expect($checked)->toBeGreaterThanOrEqual(6, 'Fewer Cairo-bearing stacks than there are documents; the sweep above found almost nothing to check.');
    expect($wrong)->toBe([], implode("\n", $wrong));
});

it('restates each document own stack rather than a stack of its own invention', function () {
    fsFaceSeed();
    fsFaceState(arabic: true, mirrored: true);

    /*
     * THE DRIFT THIS CATCHES. The Arabic block cannot be built out of the
     * document's `<style>` in place -- that would put Cairo in the English
     * stylesheet too, which is a change to the default and is the one thing the
     * RTL work is not allowed to make -- so each document RESTATES its own Latin
     * stack beside the include. Two copies of one string is exactly the shape
     * that goes quietly wrong: somebody retunes `:root{--sans}` and the Arabic
     * page keeps rendering the old family.
     *
     * Both copies are in the SAME rendered document, so they can simply be
     * paired: for every declaration the Arabic block emits, find the one the
     * page's own stylesheet makes for the same property, and require that
     * ArabicFace::append() of the second IS the first, character for character.
     */
    $mismatched = [];
    $paired = [];

    foreach (fsFaceDocuments() as $url => $view) {
        $paired[$view] = 0;

        $html = test()->get('/ar' . $url)->getContent();
        $declarations = fsFaceFontDeclarations($html);

        foreach ($declarations as $arabic) {
            if (! str_starts_with($arabic['selector'], 'html[lang="ar"]')) {
                continue;
            }

            // The base selector this one is the Arabic override OF:
            // `html[lang="ar"]` overrides `:root`, `html[lang="ar"] body`
            // overrides `body`, and so on. A base the document does not declare
            // at all -- `button` in the journal views, whose whole point is that
            // the document never gave buttons a font -- has nothing to pair
            // with and is skipped rather than guessed at.
            $wanted = trim(substr($arabic['selector'], strlen('html[lang="ar"]')));
            $wanted = $wanted === '' ? ':root' : $wanted;

            foreach ($declarations as $base) {
                if ($base['property'] !== $arabic['property'] || $base['selector'] !== $wanted) {
                    continue;
                }

                $paired[$view]++;

                if (ArabicFace::append($base['value']) !== $arabic['value']) {
                    $mismatched[] = sprintf(
                        "%s: the page declares `%s: %s` on `%s`, but the Arabic block says `%s`, which is not that stack with the face appended.",
                        $view,
                        $base['property'],
                        $base['value'],
                        $base['selector'],
                        $arabic['value']
                    );
                }
            }
        }
    }

    /*
     * EVERY ONE OF THE FIVE HAS TO PAIR AT LEAST ONCE, and that requirement is
     * the guard rather than a total. A rule can be perfectly spelled and still
     * be aimed at a selector the document does not style -- moving
     * review-wall's override from `body` to `:root` keeps the right stack and
     * the right family order and changes nothing on the page, because the
     * document's own `body{font-family:"Poppins",sans-serif}` goes on winning.
     * Nothing pairs, so a sweep that only counted a TOTAL stayed green through
     * it. Asked per document, it does not.
     *
     * The /shop/ control is exempt: it keeps its Latin stack in BUILT css rather
     * than inline, so there is nothing in its document to pair against.
     */
    $unpaired = [];

    foreach ($paired as $view => $count) {
        if ($count === 0 && $view !== 'layouts/store (control)') {
            $unpaired[] = "{$view} emits an Arabic font rule that overrides nothing the document itself declares";
        }
    }

    expect($unpaired)->toBe([], implode("\n", $unpaired));
    expect($mismatched)->toBe([], implode("\n", $mismatched));
});

it('keeps every English page free of the Arabic face, link and style block alike', function () {
    fsFaceSeed();

    // Arabic is ON. The English URLs still have to come back untouched — the
    // gate is the page's language, not whether the shop speaks Arabic at all.
    fsFaceState(arabic: true, mirrored: true);

    $leaked = [];

    expect(fsFaceDocuments())->toHaveCount(6);

    foreach (fsFaceDocuments() as $url => $view) {
        $html = test()->get($url)->assertOk()->getContent();

        foreach (['family=Cairo' => 'the stylesheet link', 'Cairo' => 'the family name', 'kbb-arabic-face' => 'the style block'] as $needle => $what) {
            if (str_contains($html, $needle)) {
                $leaked[] = "{$view} at {$url} carries {$what} on an English page";
            }
        }
    }

    expect($leaked)->toBe([], implode("\n", $leaked));
});

it('loads the Arabic face on all five with the mirrored layout switched off as well', function () {
    fsFaceSeed();

    // "Arabic on, mirrored off" is a state the owner can choose and the
    // Translation console warns him about rather than preventing. It is Arabic
    // words in a left-to-right layout, and Arabic words still need Arabic
    // glyphs. Gating the face on Locale::isRtl() instead of on the language
    // breaks exactly this and nothing else, which is why it has its own test.
    fsFaceState(arabic: true, mirrored: false);

    $missing = [];

    expect(fsFaceDocuments())->toHaveCount(6);

    foreach (fsFaceDocuments() as $url => $view) {
        $html = test()->get('/ar' . $url)->assertOk()->getContent();

        expect($html)->toContain('dir="ltr"');

        if (! str_contains($html, 'family=Cairo') || fsFaceCairoStacks($html) === []) {
            $missing[] = "{$view} at /ar{$url} drops the Arabic face when the mirrored layout is off";
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('asks each document for the weights its own Latin link asks for', function () {
    fsFaceSeed();
    fsFaceState(arabic: true, mirrored: true);

    // Every weight in the request maps to the SAME variable WOFF2 — measured
    // against fonts.googleapis.com, one arabic file of 30,896 bytes with
    // sha256 748022f50c427456… for 400;500;600;700, for 400;500;600;700;800 and
    // for 300;400;500;600;700;800 alike. So a weight costs stylesheet bytes and
    // no font bytes, and the right list is the one the document actually styles
    // text at rather than a padded union.
    $expected = [
        '/skincare-guide/' => '400;500;600;700',
        '/fs-face-post/' => '400;500;600;700',
        '/skin-quiz/' => '300;400;500;600;700;800',
        '/app/' => '400;500;600;700',
        '/reviews/' => '400;500;600;700;800',
    ];

    expect($expected)->toHaveCount(5);

    foreach ($expected as $url => $weights) {
        $html = test()->get('/ar' . $url)->getContent();

        expect(preg_match('/family=Cairo:wght@([0-9;]+)/', $html, $m))->toBe(1, "No Cairo weight list on /ar{$url}");
        expect($m[1])->toBe($weights, "/ar{$url} asks Cairo for the wrong weights");

        // And the Latin link it is copied from still asks for the same set, so
        // the two cannot drift apart unnoticed.
        expect(preg_match_all('/fonts\.googleapis\.com\/css2\?family=[^"\']*wght@([0-9;.,a-zA-Z@]+)/', $html, $all))->toBeGreaterThan(1);
    }
});

it('inserts the family after the first one and never in front of it', function () {
    // The rule itself, asked of App\Support\ArabicFace, so a document that gets
    // it right by accident cannot stand in for one that gets it right on purpose.
    expect(ArabicFace::append("'Poppins',system-ui,sans-serif"))->toBe("'Poppins',\"Cairo\",system-ui,sans-serif");
    expect(ArabicFace::append('"Fraunces", Georgia, "Times New Roman", serif'))->toBe('"Fraunces","Cairo",Georgia,"Times New Roman",serif');

    // A one-family stack still gets the face after it, not before.
    expect(ArabicFace::append('Poppins'))->toBe('Poppins,"Cairo"');

    // Idempotent: a stack that already names the face is returned untouched, so
    // a document that is later given the shared layout cannot end up with two.
    $already = "'Poppins',\"Cairo\",system-ui";
    expect(ArabicFace::append($already))->toBe($already);
    expect(ArabicFace::append("'Poppins',Cairo,system-ui"))->toBe("'Poppins',Cairo,system-ui");

    expect(fn () => ArabicFace::append(''))->toThrow(InvalidArgumentException::class);
});

it('refuses a weight list it cannot build a real request from', function () {
    expect(ArabicFace::href('400;600;700;800'))
        ->toBe('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap');

    expect(fn () => ArabicFace::href('400,600'))->toThrow(InvalidArgumentException::class);
    expect(fn () => ArabicFace::href('bold'))->toThrow(InvalidArgumentException::class);
    expect(fn () => ArabicFace::href(''))->toThrow(InvalidArgumentException::class);
});
