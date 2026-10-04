<?php

declare(strict_types=1);

/*
 * Lane PD -- "on few product pages, we added a custom section of 2 videos via
 * html blocks. but it's not showing at all." The Collagen Booster set's
 * Description ended in two .webm addresses glued into one line of text, and
 * the page printed the letters. These pin the player, the allowlist, the
 * escaping, the switch, the query cost, and the fetch that brings the files
 * across before kbeautybliss.com is pointed here.
 */

use App\Models\Block;
use App\Models\Product;
use App\Services\Import\DescriptionVideoFetcher;
use App\Services\Import\ElementorToHtml;
use App\Services\Import\MediaSideloader;
use App\Services\SettingsService;
use App\Support\DescriptionVideos;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const PD_A = 'http://kbeautybliss.com/wp-content/uploads/2024/11/qfeoq0zeznudldm76nvl-1.webm';
const PD_B = 'http://kbeautybliss.com/wp-content/uploads/2024/11/wafopbfejfivqjxml2ky-1.webm';

/** The tail of the real row in storage/catalog/products.json, as WooCommerce stored it. */
function pdGlued(): string
{
    return "<strong>About brand: medicube</strong>\n\nmedicube offers solutions for sensitive skin.\n\n" . PD_A . PD_B;
}

function pdVideos(string $html): array
{
    preg_match_all('#<video class="kbb-dvid__v" src="([^"]*)" controls playsinline preload="metadata"></video>#', $html, $m);

    return $m[1];
}

beforeEach(function () {
    DescriptionVideos::forget();
});

it('draws the two glued addresses as two players, side by side, and no address is left as text', function () {
    // Defect: the page printed "…nvl-1.webmhttp://kbeautybliss.com/…ky-1.webm"
    // as one line of text under "About brand". Mutation: drop the
    // `https?:\/\/` alternative from url()'s look-ahead and the glued pair is
    // one address ending in the second .webm -- one player, wrong file.
    $html = RichText::forStorefront(pdGlued());

    expect(pdVideos($html))->toBe([PD_A, PD_B])
        ->and($html)->toContain('<div class="kbb-dvid kbb-dvid--2">')
        ->and(strip_tags($html))->not->toContain('kbeautybliss.com')
        ->and($html)->toContain('medicube offers solutions')
        ->and($html)->not->toMatch('#<p>\s*</p>#');
});

it('plays a [video] shortcode and an [embed] of a file, and merges neighbouring paragraphs into one grid', function () {
    $html = DescriptionVideos::expand(
        '<p>[video width="640" height="360" mp4="https://kbeautybliss.com/wp-content/uploads/v.mp4"][/video]</p>' . "\n"
        . '<p>[embed]https://www.kbeautybliss.com/wp-content/uploads/w.mov[/embed]</p>',
        true,
    );

    expect(pdVideos($html))->toBe(['https://kbeautybliss.com/wp-content/uploads/v.mp4', 'https://www.kbeautybliss.com/wp-content/uploads/w.mov'])
        ->and(substr_count($html, 'class="kbb-dvid '))->toBe(1)
        ->and($html)->not->toContain('[video')->and($html)->not->toContain('[embed');
});

it('plays an address on its own LINE of a paragraph and splits the paragraph round it', function () {
    $html = DescriptionVideos::expand("<p>Before<br />\nhttps://kbeautybliss.com/wp-content/uploads/a.mp4<br />\nAfter</p>", true);

    expect($html)->toBe('<p>Before</p><div class="kbb-dvid kbb-dvid--1"><video class="kbb-dvid__v" src="https://kbeautybliss.com/wp-content/uploads/a.mp4" controls playsinline preload="metadata"></video></div><p>After</p>');
});

it('leaves an address inside a sentence, a link or on a host off the allowlist as the text it was', function () {
    // Mutation: return true from allowed() and the evil.com player appears.
    foreach ([
        '<p>Watch https://kbeautybliss.com/a.mp4 today</p>',
        '<p><a href="https://kbeautybliss.com/a.mp4">https://kbeautybliss.com/a.mp4</a></p>',
        '<p>https://evil.example/wp-content/uploads/a.mp4</p>',
        '<p>https://kbeautybliss.com@evil.example/a.mp4</p>',
        '<p>https://kbeautybliss.com:8080/a.mp4</p>',
        '<p>' . PD_A . ' https://evil.example/b.webm</p>',
        '<h3>https://kbeautybliss.com/a.mp4</h3>',
    ] as $copy) {
        expect(DescriptionVideos::expand($copy, true))->toBe($copy);
    }
});

it('never prints a crafted address unescaped, and never anything but http(s)', function () {
    // An entity-encoded quote is decoded, then refused: the run stays the text it was.
    $html = DescriptionVideos::expand('<p>https://kbeautybliss.com/a.mp4?x=1&amp;y=&quot;onerror=&quot;alert(1)</p>', true);

    expect($html)->not->toContain('<video')->and($html)->not->toContain('onerror="');

    // And what is printed goes through e(). Mutation: drop e() in grid() and
    // the ampersand reaches the attribute raw -- red.
    expect(DescriptionVideos::expand('<p>https://kbeautybliss.com/a.mp4?a=1&amp;b=2</p>', true))
        ->toContain('src="https://kbeautybliss.com/a.mp4?a=1&amp;b=2"');

    foreach (['<p>javascript:alert(1)//kbeautybliss.com/a.mp4</p>', '<p>data:video/mp4,https://kbeautybliss.com/a.mp4"x</p>'] as $copy) {
        expect(pdVideos(DescriptionVideos::expand($copy, true)))->each->toStartWith('https://kbeautybliss.com/');
    }

    $quoted = DescriptionVideos::expand('<p>https://kbeautybliss.com/a.mp4"onmouseover="alert(1)</p>', true);
    expect($quoted)->not->toContain('<video')->and($quoted)->toBe('<p>https://kbeautybliss.com/a.mp4"onmouseover="alert(1)</p>');
});

it('keeps a pasted <video> as its address through clean(), so the page can play it, and drops the tag', function () {
    // Defect: `video` is on DROP_WHOLE and a pasted player vanished whole.
    $clean = RichText::clean('<p>x</p><video controls autoplay onplay="alert(1)"><source src="https://kbeautybliss.com/wp-content/uploads/c.mp4" type="video/mp4"></video>');

    expect($clean)->toBe('<p>x</p><p>https://kbeautybliss.com/wp-content/uploads/c.mp4</p>')
        ->and(pdVideos(DescriptionVideos::expand($clean, true)))->toBe(['https://kbeautybliss.com/wp-content/uploads/c.mp4'])
        ->and(RichText::clean('<video src="javascript:alert(1)"></video><p>y</p>'))->toBe('<p>y</p>');
});

it('returns copy naming no video byte for byte, and the switch OFF prints the copy as it was', function () {
    $plain = '<p>A cleanser.</p><p>Rinse well.</p>';
    expect(DescriptionVideos::expand($plain))->toBe($plain);

    $copy = '<p>' . PD_A . '</p>';
    app(SettingsService::class)->setModule('desc_videos', false);
    expect(DescriptionVideos::expand($copy))->toBe($copy);

    app(SettingsService::class)->setModule('desc_videos', true);
    expect(pdVideos(DescriptionVideos::expand($copy)))->toBe([PD_A]);
});

it('plays this shop\'s own copy once the file has been fetched across', function () {
    $root = storage_path('framework/testing/pd-public-' . Str::random(6));
    @mkdir($root . '/wp-content/uploads/2024/11', 0o755, true);
    file_put_contents($root . '/wp-content/uploads/2024/11/qfeoq0zeznudldm76nvl-1.webm', "\x1A\x45\xDF\xA3");
    $before = public_path();
    app()->usePublicPath($root);

    try {
        // Mutation: return $url from local() unconditionally and A stays on kbeautybliss.com.
        expect(pdVideos(DescriptionVideos::expand('<p>' . PD_A . PD_B . '</p>', true)))
            ->toBe([\App\Support\Url::raw('wp-content/uploads/2024/11/qfeoq0zeznudldm76nvl-1.webm'), PD_B]);
    } finally {
        app()->usePublicPath($before);
        \Illuminate\Support\Facades\File::deleteDirectory($root);
    }
});

it('draws the players on the product page with no extra query beyond the module snapshot', function () {
    $product = Product::create([
        'name' => 'Medicube - Collagen Booster Set - Pink Edition', 'slug' => 'pd-medicube-' . Str::lower(Str::random(5)),
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 85000,
        'stock_status' => 'instock', 'description' => pdGlued(),
    ]);
    $twin = Product::create([
        'name' => 'Medicube twin', 'slug' => 'pd-twin-' . Str::lower(Str::random(5)),
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 85000,
        'stock_status' => 'instock', 'description' => "<strong>About brand: medicube</strong>\n\nmedicube offers solutions for sensitive skin.\n\nNo clips.",
    ]);

    app(SettingsService::class)->moduleEnabled('warm', true);   // the snapshot every page already holds
    $this->get('/product/' . $twin->slug . '/')->assertOk();

    $count = static function (string $url): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = test()->get($url)->assertOk()->getContent();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$n, $html];
    };

    [$plain] = $count('/product/' . $twin->slug . '/');
    [$withVideos, $html] = $count('/product/' . $product->slug . '/');

    expect(pdVideos($html))->toBe([PD_A, PD_B, PD_A, PD_B])   // desktop panel and phone accordion
        ->and($withVideos)->toBe($plain);
});

it('turns Elementor\'s hosted Video widget into an address the page plays, and still reports a YouTube one', function () {
    $json = json_encode([[
        'elType' => 'section', 'settings' => [], 'elements' => [[
            'elType' => 'column', 'settings' => [], 'elements' => [
                ['elType' => 'widget', 'widgetType' => 'video', 'settings' => ['video_type' => 'hosted', 'hosted_url' => ['url' => PD_A]], 'elements' => []],
                ['elType' => 'widget', 'widgetType' => 'video', 'settings' => ['video_type' => 'hosted', 'hosted_url' => ['url' => PD_B]], 'elements' => []],
            ],
        ]],
    ]]);

    $out = (new ElementorToHtml)->convert($json, '');
    expect(pdVideos(DescriptionVideos::expand($out['html'], true)))->toBe([PD_A, PD_B]);

    $yt = (new ElementorToHtml)->convert(json_encode([['elType' => 'widget', 'widgetType' => 'video', 'settings' => ['video_type' => 'youtube', 'youtube_url' => 'https://youtu.be/x'], 'elements' => []]]), '<p>fallback</p>');
    expect($yt['html'])->not->toContain('youtu');
});

/* ─────────────────────────── the fetch, faked ─────────────────────────── */

function pdFetcher(?int $free = null): DescriptionVideoFetcher
{
    return new DescriptionVideoFetcher(
        new MediaSideloader(resolve: static fn (string $host): array => ['93.184.216.34']),
        $free === null ? null : static fn (): int => $free,
    );
}

function pdPublic(): array
{
    $root = storage_path('framework/testing/pd-fetch-' . Str::random(6));
    @mkdir($root, 0o755, true);
    $before = public_path();
    app()->usePublicPath($root);

    return [$root, $before];
}

it('fetches every clip the copy names into the same path under this web root, and skips one already here', function () {
    [$root, $before] = pdPublic();

    try {
        Product::create(['name' => 'M', 'slug' => 'pd-f-' . Str::random(5), 'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 1, 'stock_status' => 'instock', 'description' => pdGlued()]);
        Block::query()->forceCreate(['slug' => 'pd-b', 'name' => 'Clips', 'status' => 'published', 'content' => '<p>[video mp4="https://kbeautybliss.com/wp-content/uploads/2024/11/c.mp4"][/video]</p>']);

        Http::fake([
            // A closure, so each request gets a body of its own (one shared stream is read once).
            'kbeautybliss.com/wp-content/uploads/2024/11/*.webm' => fn () => Http::response("\x1A\x45\xDF\xA3" . str_repeat('w', 2000), 200, ['Content-Type' => 'video/webm']),
            'kbeautybliss.com/wp-content/uploads/2024/11/c.mp4' => Http::response("\x00\x00\x00\x18ftypmp42" . str_repeat('m', 500), 200, ['Content-Type' => 'video/mp4']),
        ]);

        app()->instance(DescriptionVideoFetcher::class, pdFetcher());
        $this->artisan('kbb:fetch-description-videos')->assertExitCode(0);

        expect(file_get_contents($root . '/wp-content/uploads/2024/11/qfeoq0zeznudldm76nvl-1.webm'))->toStartWith("\x1A\x45\xDF\xA3")
            ->and(is_file($root . '/wp-content/uploads/2024/11/wafopbfejfivqjxml2ky-1.webm'))->toBeTrue()
            ->and(is_file($root . '/wp-content/uploads/2024/11/c.mp4'))->toBeTrue()
            ->and(glob($root . '/wp-content/uploads/2024/11/.kbb-video-*'))->toBe([]);

        Http::assertSentCount(3);

        // Re-run: everything is here, nothing is requested again.
        $this->artisan('kbb:fetch-description-videos')->assertExitCode(0);
        Http::assertSentCount(3);
    } finally {
        app()->usePublicPath($before);
        \Illuminate\Support\Facades\File::deleteDirectory($root);
    }
});

it('refuses an HTML page called video, a wrong type, a redirect off the host, a private address and a name that could execute', function () {
    [$root, $before] = pdPublic();

    try {
        Http::fake([
            'kbeautybliss.com/u/wp-content/uploads/page.webm' => Http::response('<html>Not found</html>', 200, ['Content-Type' => 'video/webm']),
            'kbeautybliss.com/u/wp-content/uploads/type.webm' => Http::response("\x1A\x45\xDF\xA3", 200, ['Content-Type' => 'text/html']),
            'kbeautybliss.com/u/wp-content/uploads/hop.webm' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest.webm']),
            'kbeautybliss.com/u/wp-content/uploads/big.webm' => Http::response("\x1A\x45\xDF\xA3", 200, ['Content-Type' => 'video/webm', 'Content-Length' => (string) (DescriptionVideoFetcher::MAX_BYTES + 1)]),
        ]);

        $f = pdFetcher();

        expect($f->fetch('https://kbeautybliss.com/u/wp-content/uploads/page.webm', 'wp-content/uploads/page.webm'))->toMatchArray(['ok' => false])
            ->and($f->fetch('https://kbeautybliss.com/u/wp-content/uploads/type.webm', 'wp-content/uploads/type.webm')['reason'])->toContain('text/html')
            ->and($f->fetch('https://kbeautybliss.com/u/wp-content/uploads/hop.webm', 'wp-content/uploads/hop.webm')['reason'])->toContain('redirect')
            ->and($f->fetch('https://kbeautybliss.com/u/wp-content/uploads/big.webm', 'wp-content/uploads/big.webm')['reason'])->toContain('limit')
            ->and((new DescriptionVideoFetcher(new MediaSideloader(resolve: static fn (): array => ['10.0.0.5'])))->fetch('https://kbeautybliss.com/x/wp-content/uploads/a.webm', 'wp-content/uploads/a.webm')['reason'])->toContain('private')
            ->and($f->nameRefusal('wp-content/uploads/shell.php.webm'))->toContain('.php')
            ->and($f->nameRefusal('wp-content/uploads/../../index.webm'))->not->toBeNull()
            ->and(pdFetcher(1024)->fetch('https://kbeautybliss.com/u/wp-content/uploads/page.webm', 'wp-content/uploads/space.webm')['reason'])->toContain('free space');

        expect(glob($root . '/wp-content/uploads/*'))->toBe([])
            ->and(glob($root . '/wp-content/uploads/.kbb-video-*'))->toBe([]);
    } finally {
        app()->usePublicPath($before);
        \Illuminate\Support\Facades\File::deleteDirectory($root);
    }
});
