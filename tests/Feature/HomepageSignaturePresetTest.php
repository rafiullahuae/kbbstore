<?php

declare(strict_types=1);

/**
 * THE SIGNATURE PRESET IS THE OWNER'S HOMEPAGE, NOT "EVERY SECTION ON".
 *                                                                  (Lane PF)
 *
 * Row 55 (3 October 2026) replaced the homepage with the picture banner and
 * nine sections and switched every other section off. Appearance → Homepage →
 * Layouts still offered "Signature — The full store. Every section on", the
 * default and the first card, with "Re-apply" on it. One press put the routine
 * builder, the quiz, the old rails, ticker, flash sale, reviews, trust and
 * newsletter back on the shop and undid the page he had just approved.
 *
 * Every case says what the defect looked like and how to turn it red.
 */

use App\Services\HomepageLayouts;
use App\Services\HomepageSections;
use App\Services\SettingsService;

function pfVisible(array $rows): array
{
    return array_keys(array_filter($rows, fn ($r) => $r['desktop'] || $r['mobile']));
}

it('puts back the shipped row-55 page when Signature is re-applied, not every section on', function () {
    /*
     * THE DEFECT: after "Re-apply" on Signature, Build your routine, the skin
     * quiz, Recommended, the old Best sellers, Flash sale, Reviews, Trust and
     * Newsletter were all back on the homepage.
     *
     * MUTATION (RUN): set Signature's `off` back to [] → red.
     */
    $shipped = pfVisible(app(HomepageSections::class)->all());

    // Somebody switched things about first …
    $rows = app(HomepageSections::class)->all();
    $rows['routine']['desktop'] = $rows['routine']['mobile'] = true;
    $rows['about']['desktop'] = $rows['about']['mobile'] = false;
    app(HomepageSections::class)->save($rows);
    SettingsService::forgetMemo();

    // … and Signature puts back exactly the page that shipped.
    app(HomepageLayouts::class)->apply('signature', app(HomepageSections::class));
    SettingsService::forgetMemo();
    $after = app(HomepageSections::class);

    expect(pfVisible($after->all()))->toBe($shipped)
        ->and($after->orderIsDefault())->toBeTrue();

    foreach (HomepageSections::OFF_BY_DEFAULT as $key) {
        expect($after->hidden($key))->toBeTrue($key.' came back on with Signature');
    }

    // ▲ Lane HC: the two phones-only strips are visible sections too.
    // ▲ Lane IGE: `igembeds` ships on, after Spotted; it renders nothing until a post is pasted.
    expect($shipped)->toBe(['topstrip', 'cards_banner', 'countries', 'hero', 'bundles', 'bestselling', 'brands', 'spotted',
        'igembeds', 'trending', 'blog', 'under54', 'feature', 'about']);
});

it('says on its card what it does', function () {
    // THE DEFECT: a card reading "Every section on" over a preset that does
    // something else. MUTATION: restore the old blurb → red.
    $sig = HomepageLayouts::LAYOUTS['signature'];

    expect($sig['blurb'])->not->toContain('Every section on')
        ->and($sig['blurb'])->toContain('About us')
        ->and($sig['off'])->toBe(HomepageSections::OFF_BY_DEFAULT);
});

it('keeps the other three presets but lets none of them draw an unfinished section or two best-seller rails', function () {
    /*
     * THE DEFECT: Conversion, Editorial and Boutique each switched on the
     * routine builder ("not finished yet", the owner, 3 October) and drew the
     * old Best sellers rail AND the new one — two best-seller sections on one
     * page — with the row-55 sections dumped after the newsletter.
     *
     * MUTATION (RUN): remove 'routine' from Conversion's `off` → red.
     */
    foreach (['conversion', 'editorial', 'boutique'] as $key) {
        $payload = app(HomepageLayouts::class)->payloadFor($key);
        $on = pfVisible($payload);

        expect($on)->not->toContain('routine')
            ->and($on)->not->toContain('quiz')
            ->and($on)->not->toContain('bestsellers')
            ->and($on)->toContain('bestselling')
            ->and(HomepageLayouts::LAYOUTS[$key]['sections'])->toContain('bestselling', 'trending', 'under54', 'feature');

        // About us after the feature, as on his page.
        $order = HomepageLayouts::LAYOUTS[$key]['sections'];
        expect(array_search('feature', $order, true))->toBeLessThan(array_search('about', $order, true));
    }

    // Not deleted: all four are still offered.
    expect(array_keys(HomepageLayouts::LAYOUTS))->toBe(['signature', 'conversion', 'editorial', 'boutique']);
});
