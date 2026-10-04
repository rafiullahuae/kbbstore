<?php

declare(strict_types=1);

/**
 * THE HOMEPAGE #KBEAUTYBLISS SPOTTED SECTION AS A STATIC GRID OF SIX.
 *                                                                  (Lane HS)
 *
 * THE OWNER, 4 October, with docs/hs-owner-spotted.png: "#KBEAUTYBLISS
 * Spotted ... for now homepage, there will be 6 static images, and upon click
 * on any image, it will take the user to the actual page ... for now just put
 * demo cards on homepage, i will upload each card image manually."
 *
 * WHAT THE SHOP LOOKED LIKE. The section was the Instagram carousel, which
 * draws NOTHING until a post is ticked "Homepage" — and none is, so the
 * homepage had no Spotted section at all and there was no way to put six
 * pictures there without building six Instagram posts first.
 *
 * NOW: Appearance → #KBeautyBliss Spotted → Homepage section → "Homepage
 * layout" ships at "Static grid" (his request), and the Homepage grid tab
 * holds six cards — picture from the Media Library, link, description,
 * ↑ ↓. Until he picks a picture a card is a pink placeholder with a camera.
 *
 * MUTATIONS, each run and each red here:
 *   · home_layout default back to 'carousel'          → "ships as the grid"
 *   · HomeSections::url() swapped for the raw setting  → "scheme-checks"
 *   · width/height dropped from the grid <img>          → "reserves the box"
 *   · the hidden('spotted') guard dropped               → "honours the row"
 *   · grid() made to read SpottedPost                   → "costs no query"
 */

use App\Models\AdminUser;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Services\SpottedSettings;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SpottedRoutes;

function sgHome(): string
{
    SpottedSettings::flush();

    return view('partials.home.spotted')->render();
}

function sgSave(array $values): array
{
    $r = app(SpottedSettings::class)->save($values);
    SettingsService::forgetMemo();

    return $r;
}

it('ships as the grid: his heading, six placeholder cards, each a link to the Spotted page', function () {
    $html = sgHome();

    expect($html)->toContain('<h2 id="spt-h">#KBEAUTYBLISS Spotted</h2>')
        ->and(substr_count($html, '<a class="spt-sgc" href="/kbeautybliss-spotted/"'))->toBe(6)
        ->and(substr_count($html, '<span class="spt-sgph"><svg'))->toBe(6)
        ->and($html)->toContain('aria-label="#KBeautyBliss Spotted photo 1"')
        ->and($html)->toContain('aria-label="#KBeautyBliss Spotted photo 6"')
        // No carousel furniture and no request for a picture that is not there.
        ->and($html)->not->toContain('data-ymal')
        ->and($html)->not->toContain('<img');

    // On the real homepage, exactly once.
    expect(substr_count((string) $this->get('/')->assertOk()->getContent(), 'class="spt-sgl"'))->toBe(1);
});

it('reserves the box of a chosen picture and loads it lazily, so nothing shifts', function () {
    sgSave(['grid_1_img' => '/uploads/spotted/a.jpg', 'grid_1_alt' => 'Lina with her Anua toner']);
    $html = sgHome();

    expect($html)->toContain('<img src="/uploads/spotted/a.jpg" alt="Lina with her Anua toner" width="500" height="600" loading="lazy" decoding="async">')
        ->and(substr_count($html, 'spt-sgph'))->toBe(5);
});

it('scheme-checks every link and picture: a path on this shop or http(s), else the Spotted page', function () {
    sgSave([
        'grid_1_url' => 'javascript:alert(1)',
        'grid_2_url' => '//evil.example/x',
        'grid_3_url' => '/collections/sunscreens/',
        'grid_4_url' => 'https://www.instagram.com/p/Abc/',
        'grid_5_url' => 'data:text/html,<b>x</b>',
        'grid_1_img' => 'javascript:alert(1)',
        'grid_2_img' => '//evil.example/a.jpg',
    ]);
    $cards = app(SpottedSettings::class)->grid();

    expect($cards[0]['href'])->toBe('/kbeautybliss-spotted/')
        ->and($cards[1]['href'])->toBe('/kbeautybliss-spotted/')
        ->and($cards[2]['href'])->toBe('/collections/sunscreens/')
        ->and($cards[2]['external'])->toBeFalse()
        ->and($cards[3]['href'])->toBe('https://www.instagram.com/p/Abc/')
        ->and($cards[3]['external'])->toBeTrue()
        ->and($cards[4]['href'])->toBe('/kbeautybliss-spotted/')
        ->and($cards[0]['src'])->toBe('')
        ->and($cards[1]['src'])->toBe('');

    $html = sgHome();
    expect($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('evil.example')
        ->and($html)->toContain('href="https://www.instagram.com/p/Abc/" aria-label="#KBeautyBliss Spotted photo 4" target="_blank" rel="noopener">');
});

it('draws the cards in the order saved, which is how ↑ ↓ reorders them', function () {
    sgSave(['grid_1_alt' => 'First look', 'grid_2_alt' => 'Second look']);
    expect(array_column(app(SpottedSettings::class)->grid(), 'alt')[0])->toBe('First look');

    // What the screen's ↑ on card 2 posts: the two cards' values swapped.
    sgSave(['grid_1_alt' => 'Second look', 'grid_2_alt' => 'First look']);
    $html = sgHome();
    expect(strpos($html, 'Second look'))->toBeLessThan(strpos($html, 'First look'));
});

it('costs no query: the grid is drawn from the settings the page already has', function () {
    sgSave(['grid_1_img' => '/uploads/spotted/a.jpg']);
    // Warm what every storefront page has loaded before this section: the
    // settings memo and the interface-string map.
    app(SpottedSettings::class)->all();
    __('store.spotted.grid_heading');
    SpottedSettings::flush();

    DB::flushQueryLog();
    DB::enableQueryLog();
    view('partials.home.spotted')->render();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('keeps the carousel one choice away, unchanged', function () {
    sgSave(['home_layout' => 'carousel']);
    expect(trim(sgHome()))->toBe('');   // no post ticked: the carousel's own rule

    \App\Models\SpottedPost::create([
        'image' => '/uploads/spotted/p.jpg', 'ig_url' => 'https://www.instagram.com/p/Xyz/',
        'handle' => 'sara.glows', 'caption' => 'Torriden', 'sort' => 1, 'on_home' => true, 'on_page' => true,
    ]);
    $html = sgHome();
    expect($html)->toContain('data-ymal')->and($html)->not->toContain('spt-sgl');
});

it('honours the section switches: off, and the spotted row hidden on Appearance → Homepage', function () {
    sgSave(['home_on' => false]);
    expect(trim(sgHome()))->toBe('');

    sgSave(['home_on' => true]);
    $sections = Mockery::mock(HomepageSections::class);
    $sections->shouldReceive('hidden')->with('spotted')->andReturn(true);
    expect(trim(view('partials.home.spotted', ['sections' => $sections])->render()))->toBe('');
});

it('stores only its own options for the two selects, so nothing typed reaches the class', function () {
    $r = sgSave(['home_layout' => 'grid" onload="x', 'grid_cols_d' => '4;color:red']);
    expect(app(SpottedSettings::class)->all()['home_layout'])->toBe('grid')
        ->and(app(SpottedSettings::class)->all()['grid_cols_d'])->toBe('3')
        ->and($r['written'])->toContain('home_layout');

    sgSave(['grid_cols_d' => '6']);
    expect(sgHome())->toContain(' spt-sg spt-g6');
});

it('puts the six cards on their own Homepage grid tab, each picture a Media Library field', function () {
    $tab = SpottedSettings::TABS['grid'];
    expect($tab[0])->toBe('Homepage grid')
        ->and($tab[2])->toHaveCount(1 + 3 * SpottedSettings::GRID)
        ->and(SpottedSettings::TABS['home'][2])->toContain('home_layout');

    for ($n = 1; $n <= SpottedSettings::GRID; $n++) {
        expect(SpottedSettings::SCHEMA["grid_{$n}_img"]['options'])->toBe(['picker' => 'media']);
    }

    $screen = (string) file_get_contents(resource_path('views/admin/partials/spotted-screen.blade.php'));
    expect($screen)->toContain("current.key === 'grid' ? gridHTML(current)")
        ->and($screen)->toContain('data-spa-gpick')
        ->and($screen)->toContain('window.kbbPickMedia({')
        ->and($screen)->toContain('data-spa-gmove');
});

it('serves the Homepage grid tab from the existing endpoint, behind its capability', function () {
    SpottedRoutes::wire($this->app);
    $this->getJson('/admin-api/spotted')->assertStatus(401);

    $admin = AdminUser::create(['name' => 'Editor', 'email' => 'hs-editor@example.test', 'password' => Hash::make('secret-hs-1'), 'role' => 'editor']);
    $body = $this->actingAs($admin, 'admin')->getJson('/admin-api/spotted')->assertOk()->json();

    $grid = collect($body['tabs'])->firstWhere('key', 'grid');
    expect($grid['label'])->toBe('Homepage grid')
        ->and(collect($grid['fields'])->pluck('key')->all())->toContain('grid_1_img', 'grid_6_alt');

    $this->postJson('/admin-api/spotted/settings', ['settings' => ['grid_3_url' => '/shop/']])->assertOk();
    SettingsService::forgetMemo();
    expect(app(SpottedSettings::class)->all()['grid_3_url'])->toBe('/shop/');
});

it('has Arabic drafts for its two new fixed strings', function () {
    $drafts = ArabicInterfaceDrafts::all();
    foreach (['spotted.grid_heading', 'spotted.grid_photo'] as $k) {
        expect(InterfaceStrings::english('store.'.$k))->not->toBeNull()
            ->and($drafts['store.'.$k] ?? null)->not->toBeNull();
    }
    expect($drafts['store.spotted.grid_photo'])->toContain(':n');
});
