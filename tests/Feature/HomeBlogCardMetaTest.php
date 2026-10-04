<?php

declare(strict_types=1);

/**
 * THE HOMEPAGE BLOG CARDS SHIP WITHOUT "6 MIN READ" AND THE CATEGORY TAG.
 *                                                                  (Lane HS)
 *
 * THE OWNER, 4 October: "turn off the 6 min read, tag on blog section on
 * homepage, also remove the blog category tag on homepage from the blog page."
 *
 * WHAT THE SHOP LOOKED LIKE. Every card in the homepage's "Korean Skincare Tips
 * & Guides" section opened with a pink uppercase row — ROUTINES · 6 MIN READ —
 * above the title, and there was no control for either half.
 *
 * NOW: Appearance → Homepage content → Blog → "Reading time" and "Category
 * tag", both OFF at his request. With both off the meta row is not drawn at
 * all (an empty flex row would still hold its margin above the title). The
 * /blog/ page is a different template and keeps its tag.
 *
 * MUTATIONS, each run and each red here:
 *   · default of home_bl_read back to true            → "ships with neither"
 *   · `@if ($bl['read'])` dropped from hs-blog         → "ships with neither"
 *   · `$bl['tag'] &&` dropped from the hs-pcat @if     → "ships with neither"
 *   · the outer @if around .hs-pmeta dropped           → "draws no empty row"
 */

use App\Models\Post;
use App\Services\HomepageContent;
use App\Services\SettingsService;
use App\Support\HomeSections;

beforeEach(function () {
    app(SettingsService::class)->set('demo_content', false);
    Post::create([
        'slug' => 'hs-meta-'.uniqid(), 'title' => 'How to double cleanse', 'tag' => 'Routines',
        'excerpt' => 'Oil first, then foam.', 'body' => str_repeat('word ', 1200),
        'status' => 'published', 'published_at' => now()->subDay(),
    ]);
});

function hsBlogSection(string $html): string
{
    preg_match('#<section class="sec hs hs-blog.*?</section>#s', $html, $m);

    return $m[0] ?? '';
}

function hsBlogSet(array $values): void
{
    foreach ($values as $k => $v) {
        app(SettingsService::class)->set($k, $v);
    }
    SettingsService::forgetMemo();
}

it('ships with neither the reading time nor the category tag on the homepage cards', function () {
    $blog = hsBlogSection((string) $this->get('/')->assertOk()->getContent());

    expect($blog)->toContain('How to double cleanse')
        ->and($blog)->not->toContain('min read')
        ->and($blog)->not->toContain('hs-pcat')
        ->and($blog)->not->toContain('hs-pmin');
});

it('draws no empty meta row when both are off', function () {
    $blog = hsBlogSection((string) $this->get('/')->assertOk()->getContent());

    expect($blog)->toContain('<span class="hs-pcb"><h3>')
        ->and($blog)->not->toContain('hs-pmeta');
});

it('draws each one alone when it alone is switched on', function () {
    hsBlogSet(['home_bl_read' => true]);
    $blog = hsBlogSection((string) $this->get('/')->getContent());
    expect($blog)->toMatch('#<span class="hs-pmeta"> <span class="hs-pmin">\d+ min read</span></span><h3>#')
        ->and($blog)->not->toContain('hs-pcat');

    hsBlogSet(['home_bl_read' => false, 'home_bl_tag' => true]);
    $blog = hsBlogSection((string) $this->get('/')->getContent());
    expect($blog)->toContain('<span class="hs-pmeta"><span class="hs-pcat">Routines</span> </span><h3>')
        ->and($blog)->not->toContain('min read');
});

it('leaves the /blog/ page its category tag', function () {
    $this->get('/blog/')->assertOk()->assertSee('<span class="ptag">Routines</span>', false);
});

it('offers both switches on the Blog tab, off by default', function () {
    expect(HomepageContent::SCHEMA['home_bl_read']['type'])->toBe('bool')
        ->and(HomepageContent::SCHEMA['home_bl_read']['default'])->toBeFalse()
        ->and(HomepageContent::SCHEMA['home_bl_tag']['default'])->toBeFalse()
        ->and(HomepageContent::SCHEMA['home_bl_read']['label'])->toBe('Reading time')
        ->and(HomepageContent::SCHEMA['home_bl_tag']['label'])->toBe('Category tag')
        ->and(HomeSections::TABS['blog'][2])->toContain('home_bl_read', 'home_bl_tag');

    $bl = HomeSections::blog([]);
    expect($bl['read'])->toBeFalse()->and($bl['tag'])->toBeFalse();
});
