<?php

declare(strict_types=1);

/**
 * #KBeautyBliss Spotted — the homepage carousel and its polaroid card. (Lane HB)
 *
 * THE OWNER (master plan row 55, item 4): "with instagram feed make it
 * carousel, but with manual selection … button to a new page
 * /kbeautybliss-spotted … super responsive. without any bugs, hanging, or
 * broken stuff." Card design B (docs/home-preview/cards.html): white frame,
 * 4:5 photo, the handle on a dark scrim, a caption line, the heart count ONLY
 * when he entered one. Controls: Appearance → #KBeautyBliss Spotted.
 *
 * The defects these pin, each as it would look on the shop:
 *   · a heading "#KBeautyBliss — Seen on Instagram" over an EMPTY row, on the
 *     day the package is applied and before he has picked a post;
 *   · a post he un-ticked "Homepage" still riding the carousel;
 *   · a card linking to https://instagram.com.evil.test/… or javascript:… —
 *     a prefix check passes the first, {{ }} passes the second byte for byte;
 *   · "♥ 0" (or any invented number) under a post he gave no count.
 *
 * MUTATION NOTES, RUN:
 *   · delete `@if ($sptCards !== [])` from partials/home/spotted → RED (case 1).
 *   · drop `->where($where === 'home' ? 'on_home' : 'on_page', true)` from
 *     SpottedSettings::build() → RED (case 2).
 *   · replace the host check in SpottedPost::instagramUrl() with
 *     str_starts_with($url, 'https://instagram.com') → RED (case 3).
 *   · print the likes span unconditionally in partials/spotted-card → RED (case 4).
 */

use App\Models\Product;
use App\Models\SpottedPost;
use App\Services\SettingsService;
use App\Services\SpottedSettings;

function sptPost(array $over = []): SpottedPost
{
    static $n = 0;
    $n++;

    return SpottedPost::create($over + [
        'image' => '/uploads/spotted/p'.$n.'.jpg',
        'ig_url' => 'https://www.instagram.com/p/Abc'.$n.'/',
        'handle' => 'sara.glows',
        'caption' => 'Torriden Serum '.$n,
        'sort' => $n,
        'on_home' => true,
        'on_page' => true,
    ]);
}

function sptHome(): string
{
    SpottedSettings::flush();

    return view('partials.home.spotted')->render();
}

function sptSave(array $values): void
{
    app(SpottedSettings::class)->save($values);
    SettingsService::forgetMemo();
}

it('renders nothing at all until a post is ticked for the homepage', function () {
    // The day the package is applied: an empty table.
    expect(trim(sptHome()))->toBe('');

    // A post on the Spotted page only is still nothing on the homepage.
    sptPost(['on_home' => false]);
    expect(trim(sptHome()))->toBe('');

    // And the owner's off switch wins over a ticked post.
    sptPost();
    sptSave(['home_on' => false]);
    expect(trim(sptHome()))->toBe('');

    sptSave(['home_on' => true]);
    // ▲ Lane PF: no phone arrows and a peeking 2.3 by default (owner, 4 Oct).
    expect(sptHome())->toContain('<section class="sec spt spt-lilac spt-tilt spt-noarr-m spt-peek-m"');
});

it('draws only the ticked posts, in the owner\'s order, up to the number he chose', function () {
    $a = sptPost(['caption' => 'First pick', 'sort' => 3]);
    $b = sptPost(['caption' => 'Second pick', 'sort' => 1]);
    sptPost(['caption' => 'Page only', 'on_home' => false, 'sort' => 2]);

    $html = sptHome();

    expect(substr_count($html, '<li class="spt-cell">'))->toBe(2)
        ->and($html)->not->toContain('Page only')
        ->and(strpos($html, 'Second pick'))->toBeLessThan(strpos($html, 'First pick'));

    foreach (range(1, 6) as $i) {
        sptPost(['sort' => 10 + $i]);
    }
    sptSave(['home_max' => '4']);

    expect(substr_count(sptHome(), '<li class="spt-cell">'))->toBe(4);
});

it('accepts only https Instagram addresses on the two real hosts, and checks again on the way out', function () {
    foreach ([
        'https://www.instagram.com/p/C1x2y3/',
        'https://instagram.com/reel/C1x2y3/',
        'https://www.instagram.com/p/C1x2y3/?igsh=abc',
    ] as $good) {
        expect(SpottedPost::instagramUrl($good))->toBe($good);
    }

    foreach ([
        'http://www.instagram.com/p/C1x2y3/',          // not https
        'https://instagram.com.evil.test/p/x/',         // a prefix match passes this
        'https://instagram.com@evil.test/p/x/',         // and this
        'https://evil.test/https://instagram.com/p/x/',
        'javascript:alert(1)//instagram.com/p/x',
        '//www.instagram.com/p/x/',
        'https://www.instagram.com/',                   // the bare root is not a post
        'https://www.instagram.com:444/p/x/',
        'https://www.instagram.com/p/x/"onmouseover="alert(1)',
    ] as $bad) {
        expect(SpottedPost::instagramUrl($bad))->toBeNull();
    }

    foreach (['javascript:alert(1)', '//evil.test/x.jpg', 'data:image/png;base64,AAAA', 'vbscript:x'] as $bad) {
        expect(SpottedPost::imageUrl($bad))->toBeNull();
    }
    expect(SpottedPost::imageUrl('/uploads/a.jpg'))->toBe('/uploads/a.jpg')
        ->and(SpottedPost::imageUrl('https://cdn.example.com/a.jpg'))->toBe('https://cdn.example.com/a.jpg');

    // A row that reached the table some other way (a restore, phpMyAdmin) is
    // still not printed: no usable link and no product means no card at all,
    // and a hostile picture means no card either.
    sptPost(['ig_url' => 'https://instagram.com.evil.test/p/x/', 'caption' => 'Hostile link']);
    sptPost(['image' => 'javascript:alert(1)', 'caption' => 'Hostile picture']);
    sptPost(['handle' => '"><script>', 'caption' => 'Hostile handle']);
    sptPost(['caption' => 'Good one']);

    $html = sptHome();
    expect($html)->toContain('Good one')
        ->not->toContain('Hostile')
        ->not->toContain('evil.test')
        ->not->toContain('javascript:');
});

it('draws the heart count only when the owner entered one, and never invents it', function () {
    sptPost(['caption' => 'No count', 'likes' => null]);
    $html = sptHome();
    expect($html)->toContain('No count')->not->toContain('spt-likes');

    SpottedPost::query()->delete();
    sptPost(['caption' => 'With count', 'likes' => 1240]);
    expect(sptHome())->toContain('<b class="spt-likes">')->toContain('1.2k<span class="spt-sr"> likes</span>');

    expect(SpottedPost::compactCount(860))->toBe('860')
        ->and(SpottedPost::compactCount(1000))->toBe('1k')
        ->and(SpottedPost::compactCount(2400000))->toBe('2.4m')
        ->and(SpottedPost::compactCount(null))->toBeNull();
});

it('is a real link on every card: Instagram in a new tab, or the linked product', function () {
    $p = Product::create([
        'slug' => 'spt-toner', 'name' => 'Spotted Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 100, 'stock_status' => 'instock',
    ]);

    sptPost(['caption' => 'To Instagram']);
    sptPost(['caption' => 'To product', 'product_id' => $p->id, 'link_to' => 'product']);
    // He chose the product but there is none: Instagram is used instead.
    sptPost(['caption' => 'Fallback', 'link_to' => 'product', 'ig_url' => 'https://instagram.com/p/Fall/']);

    $html = sptHome();

    expect($html)->toMatch('#<a class="spt-card" href="https://www\.instagram\.com/p/Abc\d+/" target="_blank" rel="noopener">#')
        ->and($html)->toContain('<a class="spt-card" href="'.$p->url().'">')
        ->and($html)->toContain('<a class="spt-card" href="https://instagram.com/p/Fall/" target="_blank" rel="noopener">')
        // width/height on every picture (no layout shift), lazy below the fold.
        ->and(substr_count($html, 'width="400" height="500" loading="lazy" decoding="async"'))->toBe(3);
});

it('is a carousel on the shop\'s shared, measure-nothing script with a button to the Spotted page', function () {
    sptPost();
    $html = sptHome();

    expect($html)->toContain('data-ymal data-ymal-auto="0" aria-labelledby="spt-h"')
        ->toContain('data-ymal-prev aria-controls="spt-track"')
        ->toContain('data-ymal-next aria-controls="spt-track"')
        ->toContain('id="spt-track" data-ymal-track')
        ->toContain('<h2 id="spt-h">#KBeautyBliss — Seen on Instagram</h2>')
        ->toContain('class="bndl-all spt-all" href="/kbeautybliss-spotted/"')
        // ▲ Lane PF: 2.3 on a phone, as the owner asked on 4 October.
        ->toContain('style="--spt-per-d:5;--spt-per-m:2.3;');

    sptSave(['btn_on' => false]);
    expect(sptHome())->not->toContain('spt-all');

    // The lilac from docs/home-preview/home.html.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    expect($css)->toContain('.kbb-home .sec.spt-lilac{background:linear-gradient(160deg,#FCEFF5 0%,#F3ECFF 100%)}')
        ->and($css)->toContain('grid-auto-columns:calc((100% - (var(--spt-per) - 1) * var(--spt-gap)) / var(--spt-per))');
});

it('stores only a select\'s own options, so nothing typed reaches the style attribute', function () {
    sptSave([
        'per_d' => '4;background:url(//evil.test)', 'pad_top_d' => '999', 'auto' => '5',
        'home_title' => '<b>Our</b> community', 'bg' => 'plain',
    ]);
    sptPost();

    $s = app(SpottedSettings::class)->section();
    expect($s['style'])->toContain('--spt-per-d:5;')->toContain('--spt-pt-d:8px')->not->toContain('evil')
        ->and($s['auto'])->toBe(5)
        ->and($s['title'])->toBe('Our community')
        ->and($s['classes'])->toBe('spt spt-tilt spt-noarr-m spt-peek-m'); // ▲ Lane PF: the phone defaults
});
