<?php

declare(strict_types=1);

/*
 * Catalog → Image SEO (Lane IR).
 *
 * The owner: rename product pictures to the product name, one word-order
 * variation per picture, "no any image breakage after rename", alt text in
 * bulk, a green tick in the Media Library, and (added) a score out of 10 in
 * front of every image URL on the search.
 *
 * Every fixture is a REAL picture made with GD under a public root of the
 * test's own (usePublicPath), with REAL img-cache copies made by
 * ImageVariants::generate(), so "the srcset still lists every width" is a
 * claim about files on a disk and not about a mock.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ImageSeo\AltText;
use App\Services\ImageSeo\ImageFiles;
use App\Services\ImageSeo\ImageNamer;
use App\Services\ImageSeo\ImageScore;
use App\Services\ImageSeo\ImageSeo;
use App\Services\ImageSeo\ImageSeoJobs;
use App\Services\ImageSeo\ImageSeoPlanner;
use App\Support\ImageRenameRedirect;
use App\Support\ImageVariants;
use App\Support\MediaRegistrar;
use App\Support\MediaUsageWriter;
use Illuminate\Support\Facades\DB;
use Tests\Support\ImageSeoAdminRoutes;

/* ------------------------------------------------------------ fixtures */

function irRoot(): string
{
    $root = kbbTempDir().'/ir-pub-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    app()->usePublicPath($root);
    $GLOBALS['irRootDir'] = $root;

    return $root;
}

afterEach(function () {
    if (isset($GLOBALS['irRootDir'])) {
        $dir = $GLOBALS['irRootDir'];

        if (is_dir($dir)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }

            @rmdir($dir);
        }

        unset($GLOBALS['irRootDir']);
    }
});

/** A real JPEG (or WebP), 900x900, tinted so each picture differs. */
function irPicture(string $rel, int $tint = 0, string $type = 'jpg'): string
{
    $img = imagecreatetruecolor(900, 900);
    imagefill($img, 0, 0, imagecolorallocate($img, (40 + $tint * 37) % 256, (120 + $tint * 53) % 256, 160));
    imagefilledrectangle($img, 200, 200, 700, 700, imagecolorallocate($img, 250, 250, 250));
    $file = public_path($rel);
    @mkdir(dirname($file), 0777, true);
    $type === 'webp' ? imagewebp($img, $file, 80) : imagejpeg($img, $file, 85);

    return $file;
}

function irUrl(string $rel): string
{
    return rtrim((string) config('app.url'), '/').'/'.$rel;
}

/**
 * The shop the browser test and these share: Medicube PDRN Eye Patches with
 * five pictures (main, three gallery, one only on a variant), one of them also
 * inside its description and a Journal post; and two Anua products sharing a
 * picture.
 *
 * @return array{medi: Product, anua: Product, oil: Product, rels: array<string, string>}
 */
function irShop(): array
{
    $brand = Brand::create(['name' => 'Medicube', 'slug' => 'ir-medicube']);
    $anua = Brand::create(['name' => 'Anua', 'slug' => 'ir-anua']);
    $cat = Category::create(['name' => 'Eye Care', 'slug' => 'ir-eye-care']);

    $rels = [
        'm1' => 'uploads/products/20261005-101010-a1b2c3.jpg',
        'm2' => 'uploads/products/20261005-101011-d4e5f6.jpg',
        'm3' => 'uploads/products/IMG_1234.jpg',
        'm4' => 'wp-content/uploads/2023/05/DSC00042.jpg',
        'mv' => 'uploads/products/20261005-101015-zz9900.jpg',
        'a1' => 'uploads/products/20261006-090000-aa11bb.jpg',
        'sh' => 'uploads/products/20261006-090001-shared.jpg',
        'o1' => 'uploads/products/20261006-090002-oil111.jpg',
    ];

    $i = 0;

    foreach ($rels as $rel) {
        irPicture($rel, $i++);
        MediaRegistrar::record($rel);
        ImageVariants::generate('/'.$rel);
    }

    $medi = Product::create([
        'name' => 'Medicube PDRN Eye Patches', 'slug' => 'ir-medicube-pdrn-eye-patches', 'sku' => 'MDC-PDRN-60', 'status' => 'publish',
        'brand_id' => $brand->id, 'category_id' => $cat->id, 'price' => 9900, 'type' => 'variable',
        'image' => irUrl($rels['m1']),
        'images' => [irUrl($rels['m2']), '/'.$rels['m3'], '/'.$rels['m4']],
        'description' => '<p>How to use</p><p><img src="/'.$rels['m3'].'" alt="patch on skin"></p>',
    ]);
    ProductVariant::create(['product_id' => $medi->id, 'sku' => 'MDC-PDRN-60-B', 'price' => 9900, 'image' => irUrl($rels['mv'])]);

    $anuaP = Product::create([
        'name' => 'Anua Heartleaf 77% Soothing Toner', 'slug' => 'ir-anua-heartleaf-toner', 'status' => 'publish', 'brand_id' => $anua->id, 'price' => 7600,
        'image' => irUrl($rels['a1']), 'images' => [irUrl($rels['sh'])],
    ]);
    $oil = Product::create([
        'name' => 'Anua Heartleaf Pore Control Cleansing Oil', 'slug' => 'ir-anua-cleansing-oil', 'status' => 'publish', 'brand_id' => $anua->id, 'price' => 8400,
        'image' => irUrl($rels['o1']), 'images' => [irUrl($rels['sh'])],
    ]);

    Post::create(['title' => 'Eye patch routine', 'slug' => 'ir-eye-patch-routine', 'status' => 'publish', 'body' => '<img src="'.irUrl($rels['m3']).'">']);

    MediaUsageWriter::rebuild();

    return ['medi' => $medi->fresh(), 'anua' => $anuaP->fresh(), 'oil' => $oil->fresh(), 'rels' => $rels];
}

function irAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'IR '.$role, 'email' => 'ir-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

/** Run a whole job the way the screen does: step until done. */
function irRun(object $job): array
{
    $results = [];

    for ($i = 0; $i < 50; $i++) {
        $out = ImageSeoJobs::step((int) $job->id, null);
        $results = array_merge($results, $out['results']);

        if (($out['job']['status'] ?? '') === 'done') {
            break;
        }
    }

    return $results;
}

function irRename(array $productIds, array $options = [], ?string $token = null): array
{
    $job = ImageSeoJobs::start('rename', $token ?? 'r-'.bin2hex(random_bytes(10)), array_map(fn ($id) => ['p' => $id], $productIds), $options + ['strategy' => 'variations'], null);

    return ['job' => $job, 'results' => irRun($job)];
}

/* ------------------------------------------------------- the name rules */

it('names the owner\'s Medicube example exactly as he wrote it', function () {
    $names = ImageNamer::names('Medicube', 'Medicube PDRN eye patches', 5);

    // His three, verbatim, then the scheme continues without repeating.
    expect(array_slice($names, 0, 3))->toBe(['medicube-pdrn-eye-patches', 'pdrn-medicube-eye-patches', 'eye-patches-pdrn-by-medicube'])
        ->and($names)->toHaveCount(5)
        ->and(array_unique($names))->toHaveCount(5)
        ->and($names[3])->toBe('pdrn-eye-patches-medicube')
        ->and($names[4])->toBe('medicube-eye-patches-pdrn');

    // The second strategy: name + view number.
    expect(ImageNamer::names('Medicube', 'Medicube PDRN eye patches', 3, ImageNamer::STRATEGY_NUMBERED))
        ->toBe(['medicube-pdrn-eye-patches', 'medicube-pdrn-eye-patches-2', 'medicube-pdrn-eye-patches-3']);
    // MUTATION: swap the second and third entries of ImageNamer::orders() and the first expectation is red.
});

it('puts the brand in once, whether the title leads with it, ends with it or lacks it', function () {
    expect(ImageNamer::names('Medicube', 'PDRN eye patches by Medicube', 1))->toBe(['medicube-pdrn-eye-patches'])
        ->and(ImageNamer::names('Medicube', 'PDRN Eye Patches', 1))->toBe(['medicube-pdrn-eye-patches'])
        ->and(ImageNamer::names('Beauty of Joseon', 'Beauty of Joseon Relief Sun: Rice + Probiotics SPF50+', 1))
        ->toBe(['beauty-of-joseon-relief-sun-rice-probiotics-spf50']);
});

it('transliterates, drops apostrophes, refuses Arabic-only titles and never repeats a name', function () {
    expect(ImageNamer::names("d'Alba", "d'Alba White Truffle First Spray Serum", 1))->toBe(['dalba-white-truffle-first-spray-serum'])
        ->and(ImageNamer::names('COSRX', 'COSRX Crème Été Essence', 1))->toBe(['cosrx-creme-ete-essence'])
        ->and(ImageNamer::names(null, 'كريم مرطب للوجه', 2))->toBe([null, null]);

    // Few words: the orders run out and the position takes over, no repeats.
    $few = ImageNamer::names('Anua', 'Anua Toner', 5);
    expect($few)->toBe(['anua-toner', 'toner-anua', 'toner-by-anua', 'anua-toner-4', 'anua-toner-5'])
        ->and(array_unique($few))->toHaveCount(5);
});

it('caps names at 8 words and 70 characters, lower-case hyphenated ASCII only', function () {
    $long = ImageNamer::names('COSRX', 'COSRX Advanced Snail 96 Mucin Power Repair Intensive Hydrating Essence for Very Dry Skin', 3);

    foreach ($long as $stem) {
        expect(strlen($stem))->toBeLessThanOrEqual(ImageNamer::MAX_LENGTH)
            ->and(count(explode('-', $stem)))->toBeLessThanOrEqual(ImageNamer::MAX_WORDS + 1)
            ->and(preg_match(ImageNamer::SLUG_PATTERN, $stem))->toBe(1)
            ->and($stem)->toContain('cosrx');
    }
});

it('drops the pack size before a word of the name when a title is too long', function () {
    /*
     * The owner's own title, 8 October. Nine words over the cap of eight, and
     * fit() used to pop the key first: every picture lost "jelly" -- the word
     * that says what the product is -- and kept "6 pairs", which nobody
     * searches a picture by. Mutation: delete the pack-size loop in
     * ImageNamer::fit() and the first name reads
     * medicube-pdrn-pink-collagen-eye-mask-6-pairs again.
     */
    $names = ImageNamer::names('medicube', 'medicube - PDRN Pink Collagen Jelly Eye Mask 6 pairs', 5);

    expect($names[0])->toBe('medicube-pdrn-pink-collagen-jelly-eye-mask')
        ->and($names)->toHaveCount(5)
        ->and(array_unique($names))->toHaveCount(5);

    foreach ($names as $name) {
        expect($name)->toContain('jelly')->not->toContain('pairs')->not->toMatch('/-6(-|$)/');
    }

    // A title that already fits keeps its size: nothing is dropped that need not be.
    expect(ImageNamer::names('Anua', 'Anua Heartleaf 77% Soothing Toner 250ml', 1))
        ->toBe(['anua-heartleaf-77-soothing-toner-250ml']);
});

it('adds a short suffix only when another file already holds the name', function () {
    $taken = fn (string $stem): bool => $stem === 'pdrn-medicube-eye-patches';

    expect(ImageNamer::names('Medicube', 'Medicube PDRN eye patches', 3, ImageNamer::STRATEGY_VARIATIONS, $taken))
        ->toBe(['medicube-pdrn-eye-patches', 'pdrn-medicube-eye-patches-2', 'eye-patches-pdrn-by-medicube']);
    // MUTATION: drop the `$taken($stem, $i)` test in names() and the middle name collides.
});

/* ------------------------------------------------------------ the score */

it('scores the owner\'s example exactly: 4/10 before, 10/10 after', function () {
    $before = ImageScore::score('20261005-101010-a1b2c3.webp', 'Medicube', 'Medicube PDRN eye patches', 'Medicube PDRN Eye Patches', false);

    expect($before['score'])->toBe(35)
        ->and($before['ten'])->toBe(4)
        ->and($before['tick'])->toBeFalse()
        ->and(ImageScore::reasons($before['lost']))->toBe(
            '−0.5 camera or random file name · −1.5 random name is not descriptive · −1 file name has no descriptive words'
            .' · −1.5 no brand in file name · −1.5 product name not in file name · −0.5 alt is the automatic one'
        );

    $after = ImageScore::score('pdrn-medicube-eye-patches.webp', 'Medicube', 'Medicube PDRN eye patches', 'PDRN Eye Patches by Medicube', true, ['Medicube PDRN Eye Patches']);

    expect($after['score'])->toBe(100)->and($after['ten'])->toBe(10)->and($after['tick'])->toBeTrue()->and($after['lost'])->toBe([]);
    // MUTATION: change the brand rule's 15 to 10 and the before score is 40 (4/10 still) but the reasons line moves; change TICK to 101 and `tick` is false.
});

it('takes the exact points the rubric names off each fault', function () {
    $camera = ImageScore::score('IMG_1234.JPG', 'Medicube', 'Medicube PDRN eye patches', '', false);
    $capitals = ImageScore::score('Medicube_PDRN.jpg', 'Medicube', 'Medicube PDRN eye patches', 'image of medicube', true);
    $noBrand = ImageScore::score('pdrn-eye-patches.jpg', 'Medicube', 'Medicube PDRN eye patches', 'Medicube PDRN Eye Patches', true);
    $dupAlt = ImageScore::score('medicube-pdrn-eye-patches.jpg', 'Medicube', 'Medicube PDRN eye patches', 'Medicube PDRN Eye Patches', true, ['medicube pdrn eye patches']);

    expect($camera['score'])->toBe(0)->and($camera['ten'])->toBe(0)
        // 15 format + 10 too short + 8 one product word + 8 alt brand-only + 5 "image of"
        ->and($capitals['score'])->toBe(54)->and($capitals['ten'])->toBe(5)
        ->and($noBrand['score'])->toBe(85)->and($noBrand['ten'])->toBe(9)
        ->and($dupAlt['score'])->toBe(95)
        ->and(ImageScore::reasons($dupAlt['lost']))->toBe('−0.5 same alt as another picture');

    expect(ImageScore::ten(35))->toBe(4)->and(ImageScore::ten(34))->toBe(3)->and(ImageScore::ten(75))->toBe(8);
});

it('gives each image its rating, reasons and the product its lowest and average on the Find list', function () {
    irRoot();
    $shop = irShop();

    $found = ImageSeo::find(['q' => 'MDC-PDRN-60']);
    $p = $found['items'][0];

    expect($found['total'])->toBe(1)
        ->and($p['images'])->toHaveCount(5)
        ->and($p['images'][0]['url'])->toBe(irUrl($shop['rels']['m1']))
        ->and($p['images'][0]['ten'])->toBe(4)
        ->and($p['images'][0]['reasons'])->toContain('no brand in file name')
        ->and($p['images'][0]['proposed'])->toBe('medicube-pdrn-eye-patches.jpg')
        ->and($p['images'][1]['proposed'])->toBe('pdrn-medicube-eye-patches.jpg')
        ->and($p['images'][2]['proposed'])->toBe('eye-patches-pdrn-by-medicube.jpg')
        ->and($p['images'][4]['role'])->toBe('variant')
        ->and($p['lowest'])->toBe(min(array_column($p['images'], 'score')))
        ->and($p['average'])->toBeInt();
});

/* ------------------------------------------------- rename, end to end */

it('renames every picture, its copies and every reference, with a 301 from each old address', function () {
    irRoot();
    $shop = irShop();
    $r = $shop['rels'];

    $run = irRename([$shop['medi']->id]);
    $medi = $shop['medi']->fresh();
    $renamed = array_values(array_filter($run['results'], fn ($x) => $x['status'] === 'renamed'));

    expect($renamed)->toHaveCount(5);

    $new = [
        'm1' => 'uploads/products/medicube-pdrn-eye-patches.jpg',
        'm2' => 'uploads/products/pdrn-medicube-eye-patches.jpg',
        'm3' => 'uploads/products/eye-patches-pdrn-by-medicube.jpg',
        'm4' => 'wp-content/uploads/2023/05/pdrn-eye-patches-medicube.jpg',
        'mv' => 'uploads/products/medicube-eye-patches-pdrn.jpg',
    ];

    foreach ($new as $key => $rel) {
        // The file, and every img-cache width, moved; nothing left under the old name.
        expect(is_file(public_path($rel)))->toBeTrue()
            ->and(is_file(public_path($r[$key])))->toBeFalse();

        foreach (ImageVariants::WIDTHS as $w) {
            expect(is_file(public_path("img-cache/$w/$rel")))->toBeTrue("img-cache/$w/$rel")
                ->and(is_file(public_path("img-cache/$w/{$r[$key]}")))->toBeFalse();
        }

        // The library row followed, with its tick date.
        expect(Media::query()->where('path', $rel)->whereNotNull('seo_renamed_at')->exists())->toBeTrue();
        expect(ImageRenameRedirect::resolvePath($r[$key]))->toBe($rel);
    }

    // Every reference: main, gallery, the description, the variant, the post.
    expect($medi->image)->toBe(irUrl($new['m1']))
        ->and($medi->images)->toBe([irUrl($new['m2']), '/'.$new['m3'], '/'.$new['m4']])
        ->and($medi->description)->toContain('/'.$new['m3'])->not->toContain($r['m3'])
        ->and(ProductVariant::query()->where('product_id', $medi->id)->value('image'))->toBe(irUrl($new['mv']))
        ->and(Post::query()->where('slug', 'ir-eye-patch-routine')->value('body'))->toContain($new['m3']);

    // The old address and an old phone-size copy each answer 301, one hop, to a file that is there.
    $old = test()->get('/'.$r['m2']);
    $old->assertStatus(301);
    expect(parse_url((string) $old->headers->get('Location'), PHP_URL_PATH))->toBe('/'.$new['m2']);

    $copy = test()->get('/img-cache/400/'.$r['m4']);
    $copy->assertStatus(301);
    expect(parse_url((string) $copy->headers->get('Location'), PHP_URL_PATH))->toBe('/img-cache/400/'.$new['m4'])
        ->and(is_file(public_path('img-cache/400/'.$new['m4'])))->toBeTrue();

    // Something that was never renamed is still an ordinary 404.
    test()->get('/uploads/products/never-was.jpg')->assertNotFound();
    // MUTATION: drop the ImageRenameRedirect::ledgerTarget() line from LegacyImageRedirect::movedTo() and the first 301 is a 404.
});

it('keeps the product page complete after a rename: the same srcset widths, every one a file on disk', function () {
    irRoot();
    $shop = irShop();
    test()->withoutVite();

    $pictures = function (): array {
        $html = test()->get('/product/ir-medicube-pdrn-eye-patches')->assertOk()->getContent();
        preg_match_all('#(?:src|srcset)="([^"]+)"#', $html, $m);
        $paths = [];

        foreach ($m[1] as $attr) {
            foreach (explode(',', $attr) as $candidate) {
                $url = trim(explode(' ', trim($candidate))[0]);

                if (str_contains($url, '/uploads/') || str_contains($url, '/img-cache/')) {
                    $paths[] = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
                }
            }
        }

        return [$html, $paths];
    };

    [, $before] = $pictures();
    irRename([$shop['medi']->id]);
    [$html, $after] = $pictures();

    $widths = fn (array $paths) => count(array_filter($paths, fn ($p) => str_starts_with($p, 'img-cache/')));

    // Same number of srcset candidates: no width was lost with the old name.
    expect($after)->toHaveCount(count($before))
        ->and($widths($after))->toBe($widths($before))->toBeGreaterThan(0)
        ->and(implode(' ', $after))->toContain('img-cache/800/uploads/products/eye-patches-pdrn-by-medicube.jpg')
        ->and($html)->not->toContain('20261005-101010-a1b2c3')->not->toContain('IMG_1234');

    foreach (array_unique($after) as $path) {
        expect(is_file(public_path(rawurldecode($path))))->toBeTrue($path);
    }
    // MUTATION: skip the ImageFiles::derived() pairs in ImageRenamer and the img-cache candidates drop out of the srcset.
});

it('renames a WebP together with the original WebP kept beside it', function () {
    irRoot();
    $brand = Brand::create(['name' => 'COSRX', 'slug' => 'ir-cosrx']);
    irPicture('uploads/products/20261001-000000-snail1.jpg', 3);
    irPicture('uploads/products/20261001-000000-snail1.webp', 3, 'webp');
    MediaRegistrar::record('uploads/products/20261001-000000-snail1.webp');
    ImageVariants::generate('/uploads/products/20261001-000000-snail1.webp');
    DB::table('webp_conversions')->insert(['from_path' => 'uploads/products/20261001-000000-snail1.jpg', 'to_path' => 'uploads/products/20261001-000000-snail1.webp',
        'origin' => 'bulk', 'status' => 'converted', 'refs_done' => true, 'created_at' => now(), 'updated_at' => now()]);
    $p = Product::create(['name' => 'COSRX Snail 96 Mucin Essence', 'slug' => 'ir-cosrx-snail', 'brand_id' => $brand->id, 'price' => 6900, 'image' => '/uploads/products/20261001-000000-snail1.webp']);

    irRename([$p->id]);

    expect($p->fresh()->image)->toBe('/uploads/products/cosrx-snail-96-mucin-essence.webp')
        ->and(is_file(public_path('uploads/products/cosrx-snail-96-mucin-essence.jpg')))->toBeTrue()
        ->and(is_file(public_path('uploads/products/20261001-000000-snail1.jpg')))->toBeFalse()
        ->and(DB::table('webp_conversions')->value('from_path'))->toBe('uploads/products/cosrx-snail-96-mucin-essence.jpg')
        ->and(DB::table('webp_conversions')->value('to_path'))->toBe('uploads/products/cosrx-snail-96-mucin-essence.webp')
        ->and(ImageRenameRedirect::resolvePath('uploads/products/20261001-000000-snail1.jpg'))->toBe('uploads/products/cosrx-snail-96-mucin-essence.jpg');
});

it('leaves a picture two products share alone unless asked, and then both products follow it', function () {
    irRoot();
    $shop = irShop();
    $shared = $shop['rels']['sh'];

    $first = irRename([$shop['anua']->id]);
    $skip = collect($first['results'])->firstWhere('rel', $shared);

    expect($skip['status'])->toBe('skipped')
        ->and($skip['reason'])->toContain('shared with 1 other product')
        ->and(is_file(public_path($shared)))->toBeTrue();

    irRename([$shop['anua']->id], ['include_shared' => true]);
    $moved = (string) DB::table('image_renames')->where('old_path', $shared)->value('new_path');

    expect($moved)->toStartWith('uploads/products/')->not->toBe($shared)
        ->and($shop['anua']->fresh()->images)->toBe([irUrl($moved)])
        ->and($shop['oil']->fresh()->images)->toBe([irUrl($moved)]);
});

it('collapses chains so an address renamed twice still redirects in one hop', function () {
    irRoot();
    $shop = irShop();
    $first = $shop['rels']['m1'];

    irRename([$shop['medi']->id]);
    $shop['medi']->update(['name' => 'Medicube PDRN Pink Eye Patches']);
    irRename([$shop['medi']->id]);

    $now = (string) DB::table('image_renames')->where('status', 'done')->where('old_path', $first)->value('new_path');

    expect($now)->toBe('uploads/products/medicube-pdrn-pink-eye-patches.jpg')
        ->and(is_file(public_path($now)))->toBeTrue()
        ->and(ImageRenameRedirect::resolvePath($first))->toBe($now)
        ->and(ImageRenameRedirect::resolvePath('uploads/products/medicube-pdrn-eye-patches.jpg'))->toBe($now);
    // MUTATION: delete the first update in ImageRenamer::ledger() (new_path = $from -> $to) and the first address points at a file that is gone.
});

/* ------------------------------------------- atomicity and roll back */

it('rolls a picture back when the database refuses a write, and renames the product\'s others', function () {
    irRoot();
    $shop = irShop();
    $r = $shop['rels'];

    // The Journal post holding m3 refuses every update: the write throws.
    DB::statement("CREATE TRIGGER ir_refuse BEFORE UPDATE ON posts BEGIN SELECT RAISE(ABORT, 'refused'); END");

    $run = irRename([$shop['medi']->id]);
    DB::statement('DROP TRIGGER ir_refuse');

    $back = collect($run['results'])->firstWhere('rel', $r['m3']);
    $medi = $shop['medi']->fresh();

    expect($back['status'])->toBe('rolled_back')
        // Nothing about m3 moved: file, copies, references, ledger.
        ->and(is_file(public_path($r['m3'])))->toBeTrue()
        ->and(is_file(public_path('img-cache/400/'.$r['m3'])))->toBeTrue()
        ->and(is_file(public_path('uploads/products/eye-patches-pdrn-by-medicube.jpg')))->toBeFalse()
        ->and($medi->images[1])->toBe('/'.$r['m3'])
        ->and(DB::table('image_renames')->where('old_path', $r['m3'])->exists())->toBeFalse()
        // The other four went through.
        ->and(collect($run['results'])->where('status', 'renamed'))->toHaveCount(4)
        ->and($medi->image)->toBe(irUrl('uploads/products/medicube-pdrn-eye-patches.jpg'));
});

it('rolls back when the check finds the old name still written somewhere after the update', function () {
    irRoot();
    $shop = irShop();
    $r = $shop['rels'];

    // Silently ignored updates: the write "succeeds", the verification scan
    // still finds the old address, and the picture must not be renamed.
    DB::statement('CREATE TRIGGER ir_ignore BEFORE UPDATE ON posts BEGIN SELECT RAISE(IGNORE); END');
    $run = irRename([$shop['medi']->id]);
    DB::statement('DROP TRIGGER ir_ignore');

    $back = collect($run['results'])->firstWhere('rel', $r['m3']);

    expect($back['status'])->toBe('rolled_back')
        ->and($back['reason'])->toContain('still pointed at the old name')
        ->and(is_file(public_path($r['m3'])))->toBeTrue()
        ->and(Post::query()->where('slug', 'ir-eye-patch-routine')->value('body'))->toContain($r['m3']);
    // MUTATION: delete the `$left` check in ImageRenamer::attempt() and m3 reports "renamed" while the post still shows the old file.
});

/* -------------------------------------------- undo and idempotency */

it('undoes a run: names, copies, references back, and the new name redirects to the old', function () {
    irRoot();
    $shop = irShop();
    $r = $shop['rels'];

    $run = irRename([$shop['medi']->id]);
    $undo = ImageSeoJobs::undo((int) $run['job']->id, null);
    $results = irRun($undo);
    $medi = $shop['medi']->fresh();

    expect(collect($results)->where('status', 'restored'))->toHaveCount(5)
        ->and($medi->image)->toBe(irUrl($r['m1']))
        ->and($medi->images)->toBe([irUrl($r['m2']), '/'.$r['m3'], '/'.$r['m4']])
        ->and(is_file(public_path($r['m1'])))->toBeTrue()
        ->and(is_file(public_path('img-cache/800/'.$r['m1'])))->toBeTrue()
        ->and(is_file(public_path('uploads/products/medicube-pdrn-eye-patches.jpg')))->toBeFalse()
        ->and(ImageRenameRedirect::resolvePath($r['m1']))->toBeNull()
        ->and(ImageRenameRedirect::resolvePath('uploads/products/medicube-pdrn-eye-patches.jpg'))->toBe($r['m1'])
        ->and(Media::query()->where('path', $r['m1'])->value('seo_renamed_at'))->toBeNull();

    // Pressing Undo twice is one undo.
    expect(ImageSeoJobs::undo((int) $run['job']->id, null)->id)->toBe($undo->id);
});

it('cannot rename twice: the same token is the same job, and a renamed product plans as already named', function () {
    irRoot();
    $shop = irShop();

    $a = irRename([$shop['medi']->id], [], 'r-same-token-0123456789');
    $again = ImageSeoJobs::start('rename', 'r-same-token-0123456789', [['p' => $shop['medi']->id]], [], null);
    $step = ImageSeoJobs::step((int) $again->id, null);

    expect($again->id)->toBe($a['job']->id)
        ->and($step['results'])->toBe([])
        ->and(DB::table('image_renames')->where('status', 'done')->whereIn('role', ['main', 'gallery', 'variant'])->count())->toBe(5);

    $second = irRename([$shop['medi']->id]);

    expect(collect($second['results'])->pluck('status')->unique()->values()->all())->toBe(['ok'])
        ->and(DB::table('image_renames')->where('status', 'done')->whereIn('role', ['main', 'gallery', 'variant'])->count())->toBe(5);
});

/* ----------------------------------------------------------- ALT text */

it('proposes natural, distinct alt text and applies and undoes it', function () {
    expect(AltText::propose('Medicube', 'Medicube PDRN Eye Patches', 'Eye Care', 4))->toBe([
        'Medicube PDRN Eye Patches',
        'PDRN Eye Patches by Medicube',
        'Medicube PDRN Eye Patches – Eye Care',
        'PDRN Eye Patches by Medicube – view 4',
    ])->and(AltText::clean('Image of <b>Medicube</b> patches'))->toBe('Medicube patches')
        ->and(mb_strlen(AltText::clean(str_repeat('word ', 60))))->toBeLessThanOrEqual(125);

    irRoot();
    $shop = irShop();
    $url = $shop['medi']->image;

    $job = ImageSeoJobs::start('alt', 'a-'.bin2hex(random_bytes(10)), [['p' => $shop['medi']->id, 'alts' => [$url => 'Medicube PDRN Eye Patches', 'https://evil.example/x.jpg' => 'Not this product']]], [], null);
    irRun($job);

    $alts = $shop['medi']->fresh()->image_alts;
    expect($alts)->toBe([$url => 'Medicube PDRN Eye Patches']);

    irRun(ImageSeoJobs::undo((int) $job->id, null));
    expect($shop['medi']->fresh()->image_alts)->toBe([]);
});

/* -------------------------------------- the Media Library tick and score */

it('stores the score on the library row, ticks a renamed high scorer, and keeps it fresh on a product save', function () {
    irRoot();
    $shop = irShop();
    $r = $shop['rels'];

    $backfill = ImageSeo::backfill();
    expect($backfill['done'])->toBeTrue()
        ->and(ImageSeo::unscored())->toBe(0)
        ->and((int) Media::query()->where('path', $r['m1'])->value('seo_score'))->toBe(35);

    irRename([$shop['medi']->id]);
    $medi = $shop['medi']->fresh();
    $medi->image_alts = [$medi->image => 'Medicube PDRN Eye Patches'];
    $medi->save();   // the product editor's path: the listener re-scores

    $row = Media::query()->where('path', 'uploads/products/medicube-pdrn-eye-patches.jpg')->first();

    expect((int) $row->seo_score)->toBe(100)->and($row->seo_renamed_at)->not->toBeNull();

    test()->actingAs(irAdmin(), 'admin');
    $tiles = collect(test()->getJson('/admin-api/media')->assertOk()->json('items'))->keyBy('path');

    expect($tiles['uploads/products/medicube-pdrn-eye-patches.jpg']['seo_tick'])->toBeTrue()
        ->and($tiles['uploads/products/medicube-pdrn-eye-patches.jpg']['seo_ten'])->toBe(10)
        ->and($tiles[$r['a1']]['seo_tick'])->toBeFalse()
        ->and($tiles[$r['a1']]['seo_ten'])->toBeInt();
    // MUTATION: drop ImageSeo::listen() from AppServiceProvider and the row stays at its post-rename score (alt still automatic): 95, not 100.
});

/* ------------------------------------------------- security and budget */

it('never treats another host, a traversal, a customer folder or a non-picture as a local file', function () {
    irRoot();

    expect(ImageFiles::local('https://kbeautybliss-old.example/uploads/products/a.jpg'))->toBeNull()
        ->and(ImageFiles::local('/uploads/../.env'))->toBeNull()
        ->and(ImageFiles::local('/uploads/reviews/a.jpg'))->toBeNull()
        ->and(ImageFiles::local('/uploads/products/a.php'))->toBeNull()
        ->and(ImageFiles::local('/uploads/products/a.jpg?v=2'))->toBeNull()
        ->and(ImageFiles::local('/etc/passwd'))->toBeNull()
        ->and(ImageFiles::local('/uploads/products/a%20b.jpg'))->toBe('uploads/products/a b.jpg')
        ->and(ImageFiles::local(irUrl('wp-content/uploads/2023/05/a.jpg')))->toBe('wp-content/uploads/2023/05/a.jpg')
        ->and(ImageRenameRedirect::resolvePath('../../etc/passwd.jpg'))->toBeNull()
        ->and(\App\Support\LegacyImageRedirect::targetFor('img-cache/400/../../.env.jpg'))->toBeNull()
        ->and(ImageRenameRedirect::resolvePath('wp-login.php'))->toBeNull();
});

it('guards every endpoint with media.image_seo: owner and manager in, editor and support out, a guest nowhere', function () {
    ImageSeoAdminRoutes::wire(app());

    expect(ImageSeoAdminRoutes::registered())->toHaveCount(12);

    foreach (ImageSeoAdminRoutes::registered() as $route) {
        $method = $route->methods()[0];
        expect(\App\Support\AdminCapabilities::forPath($method, $route->uri()))->toBe('media.image_seo', $route->uri());
    }

    test()->getJson('/admin-api/image-seo')->assertUnauthorized();
    test()->postJson('/admin-api/image-seo/start', [])->assertUnauthorized();

    test()->actingAs(irAdmin('editor'), 'admin');
    test()->getJson('/admin-api/image-seo')->assertForbidden();
    test()->postJson('/admin-api/image-seo/step', ['job' => 1])->assertForbidden();

    test()->actingAs(irAdmin('support'), 'admin');
    test()->getJson('/admin-api/image-seo/find')->assertForbidden();

    test()->actingAs(irAdmin('manager'), 'admin');
    test()->getJson('/admin-api/image-seo')->assertOk()->assertJsonPath('ok', true);
    // MUTATION: change 'media.image_seo' => ['owner', 'manager'] to include 'editor' and the editor's 403 is a 200.
});

it('starts, steps and undoes through the endpoints, with the selection refused when malformed', function () {
    irRoot();
    ImageSeoAdminRoutes::wire(app());
    $shop = irShop();
    test()->actingAs(irAdmin(), 'admin');

    test()->postJson('/admin-api/image-seo/start', ['token' => '../../etc'])->assertStatus(422);
    test()->postJson('/admin-api/image-seo/preview', ['token' => 'tok-0123456789abcdef', 'kind' => 'rename', 'offset' => 0, 'selection' => ['mode' => 'ids', 'ids' => ['x']]])->assertStatus(422);

    // Lane IS2: the preview is the run's frozen list, and Start needs it.
    $preview = test()->postJson('/admin-api/image-seo/preview', ['token' => 'tok-0123456789abcdef', 'kind' => 'rename', 'offset' => 0, 'selection' => ['mode' => 'ids', 'ids' => [$shop['medi']->id]]])->assertOk();
    expect($preview->json('items.0.images.0.proposed_rel'))->toBe('uploads/products/medicube-pdrn-eye-patches.jpg')
        ->and($preview->json('counts.rename'))->toBe(5)
        ->and(is_file(public_path($shop['rels']['m1'])))->toBeTrue();   // a preview writes nothing

    $job = test()->postJson('/admin-api/image-seo/start', ['token' => 'tok-0123456789abcdef'])->assertOk()->json('job');

    for ($i = 0; $i < 5 && $job['status'] !== 'done'; $i++) {
        $job = test()->postJson('/admin-api/image-seo/step', ['job' => $job['id']])->assertOk()->json('job');
    }

    expect($job['status'])->toBe('done')->and($job['renamed'])->toBe(5);

    test()->postJson('/admin-api/image-seo/undo', ['job' => $job['id']])->assertStatus(422);   // confirm=UNDO required
    $undo = test()->postJson('/admin-api/image-seo/undo', ['job' => $job['id'], 'confirm' => 'UNDO'])->assertOk()->json('job');
    test()->postJson('/admin-api/image-seo/step', ['job' => $undo['id']])->assertOk();

    expect($shop['medi']->fresh()->image)->toBe(irUrl($shop['rels']['m1']))
        ->and(DB::table('audit_events')->where('event', 'image_seo')->count())->toBeGreaterThanOrEqual(2);
});

it('costs the same number of queries for a Find page of 3 products as for 40', function () {
    irRoot();
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'ir-anua']);
    irPicture('uploads/products/one.jpg');
    MediaRegistrar::record('uploads/products/one.jpg');

    $make = function (int $from, int $n) use ($brand): void {
        for ($i = $from; $i < $from + $n; $i++) {
            Product::create(['name' => "Anua Toner $i", 'slug' => "ir-anua-toner-$i", 'brand_id' => $brand->id, 'price' => 100,
                'image' => '/uploads/products/one.jpg', 'images' => ['/uploads/products/'.$i.'.jpg', 'https://elsewhere.example/'.$i.'.jpg']]);
        }
    };

    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    $measure = function () use (&$n, $brand): int {
        $n = 0;
        $page = ImageSeo::find(['brand' => $brand->id, 'sort' => 'attention']);
        expect($page['items'])->not->toBeEmpty();

        return $n;
    };

    $make(0, 3);
    $small = $measure();
    $make(3, 37);
    $large = $measure();

    expect(ImageSeo::PER_PAGE)->toBe(20)
        ->and($large)->toBe($small);
    // MUTATION: load the variants per product inside ImageSeoPlanner::plan()'s loop and the 40-product page costs 20 more queries.
});

it('answers an old address with one indexed query, and a non-picture 404 with none', function () {
    irRoot();
    $shop = irShop();
    irRename([$shop['medi']->id]);

    $n = 0;
    DB::listen(function () use (&$n) { $n++; });

    expect(ImageRenameRedirect::resolvePath('img-cache/400/'.$shop['rels']['m1']))->toBe('img-cache/400/uploads/products/medicube-pdrn-eye-patches.jpg')
        ->and($n)->toBe(1);

    $n = 0;
    expect(ImageRenameRedirect::resolvePath('wp-login.php'))->toBeNull()
        ->and(ImageRenameRedirect::resolvePath('product/some-page/'))->toBeNull()
        ->and($n)->toBe(0);
    // MUTATION: drop the picture-path regex in resolvePath() and the two non-pictures each cost a query.
});

it('renames a file written two ways on one product once, and both spellings follow it', function () {
    irRoot();
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'ir-anua-2']);
    irPicture('uploads/products/IMG_9.jpg');
    $p = Product::create(['name' => 'Anua Heartleaf Toner', 'slug' => 'ir-anua-twice', 'brand_id' => $brand->id, 'price' => 100,
        'image' => irUrl('uploads/products/IMG_9.jpg'), 'images' => ['/uploads/products/IMG_9.jpg']]);

    $run = irRename([$p->id]);
    $p = $p->fresh();

    expect(collect($run['results'])->where('status', 'renamed'))->toHaveCount(1)
        ->and($p->image)->toBe(irUrl('uploads/products/anua-heartleaf-toner.jpg'))
        ->and($p->images)->toBe(['/uploads/products/anua-heartleaf-toner.jpg'])
        ->and(is_file(public_path('uploads/products/anua-heartleaf-toner.jpg')))->toBeTrue();
    // MUTATION: drop the `same_as` branch in ImageSeoPlanner and the second spelling is planned as its own rename of a file the first already moved: rolled back.
});


it('sends an old WordPress sized copy, a pre-WebP name and a pre-rename name to the final file in one 301', function () {
    irRoot();
    $brand = Brand::create(['name' => 'COSRX', 'slug' => 'ir-cosrx-3']);
    // A JPEG turned into WebP with its original REMOVED, then renamed here.
    irPicture('uploads/products/snail.webp', 2, 'webp');
    ImageVariants::generate('/uploads/products/snail.webp');
    DB::table('webp_conversions')->insert(['from_path' => 'uploads/products/snail.jpg', 'to_path' => 'uploads/products/snail.webp',
        'origin' => 'bulk', 'status' => 'removed', 'refs_done' => true, 'created_at' => now(), 'updated_at' => now()]);
    $p = Product::create(['name' => 'COSRX Snail Mucin Essence', 'slug' => 'ir-cosrx-snail-3', 'brand_id' => $brand->id, 'price' => 100, 'image' => '/uploads/products/snail.webp']);

    irRename([$p->id]);
    $final = 'uploads/products/cosrx-snail-mucin-essence.webp';

    expect(is_file(public_path($final)))->toBeTrue()
        ->and(\App\Support\LegacyImageRedirect::targetFor('uploads/products/snail.webp'))->toBe($final)
        ->and(\App\Support\LegacyImageRedirect::targetFor('uploads/products/snail.jpg'))->toBe($final)
        ->and(\App\Support\LegacyImageRedirect::targetFor('uploads/products/snail-600x600.jpg'))->toBe($final)
        ->and(\App\Support\LegacyImageRedirect::targetFor('img-cache/400/uploads/products/snail.webp'))->toBe('img-cache/400/'.$final)
        // A width that was never made: the new original, still a picture.
        ->and(\App\Support\LegacyImageRedirect::targetFor('img-cache/1600/uploads/products/snail.webp'))->toBe($final);

    $res = test()->get('/uploads/products/snail.jpg');
    $res->assertStatus(301);
    expect(parse_url((string) $res->headers->get('Location'), PHP_URL_PATH))->toBe('/'.$final);
});
