<?php

declare(strict_types=1);

/*
 * Catalog → Image SEO, Lane IS2.
 *
 * The owner, with a screenshot of "7 product(s) selected · 27 picture(s)" and a
 * greyed-out Start: "the start renaming button not working. can we do renaming
 * and alt also together too, a third button with joint both to perform. with
 * live progress bar and counts. also the pagination should not be there, or if
 * it's there, the select mean all results should be selected, and the visit
 * between pagination should not be able to un-select in case i un-select
 * anything."
 *
 * The dead button was the preview gate: Start was `disabled` until "Preview
 * changes" had run, with nothing on the screen saying so (image-seo-screen,
 * the start button's `!preview || !preview.rename`). Reproduced in Chromium on
 * tools/is2-preview.sh: 7 products ticked, Start greyed; Preview pressed, Start
 * read "Start renaming 25 file(s)" and worked.
 *
 * Real pictures under a public root of the test's own, as ImageSeoTest does.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ImageSeo\ImageSeoJobs;
use App\Services\ImageSeo\ImageSeoSelection;
use App\Support\ImageRenameRedirect;
use App\Support\MediaRegistrar;
use App\Support\MediaUsageWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ImageSeoAdminRoutes;

function is2Root(): void
{
    $root = kbbTempDir().'/is2-pub-'.bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    app()->usePublicPath($root);
    $GLOBALS['is2RootDir'] = $root;
}

afterEach(function () {
    $dir = $GLOBALS['is2RootDir'] ?? null;

    if ($dir !== null && is_dir($dir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($dir);
    }

    unset($GLOBALS['is2RootDir']);
});

function is2Picture(string $rel, int $tint = 0): void
{
    $img = imagecreatetruecolor(120, 120);
    imagefill($img, 0, 0, imagecolorallocate($img, (40 + $tint * 37) % 256, (120 + $tint * 53) % 256, 160));
    $file = public_path($rel);
    @mkdir(dirname($file), 0777, true);
    imagejpeg($img, $file, 80);
    MediaRegistrar::record($rel);
}

function is2Url(string $rel): string
{
    return rtrim((string) config('app.url'), '/').'/'.$rel;
}

/**
 * Medicube with three pictures and no ALT text; COSRX with two pictures, the
 * first carrying ALT text somebody wrote; two Anua products sharing a picture.
 *
 * @return array{medi: Product, cosrx: Product, anua: Product, oil: Product, brand: Brand}
 */
function is2Shop(): array
{
    $medicube = Brand::create(['name' => 'Medicube', 'slug' => 'is2-medicube']);
    $cosrxB = Brand::create(['name' => 'COSRX', 'slug' => 'is2-cosrx']);
    $anuaB = Brand::create(['name' => 'Anua', 'slug' => 'is2-anua']);
    $cat = Category::create(['name' => 'Eye Care', 'slug' => 'is2-eye-care']);

    foreach (['uploads/products/IMG_0001.jpg', 'uploads/products/IMG_0002.jpg', 'uploads/products/IMG_0003.jpg',
        'uploads/products/DSC_10.jpg', 'uploads/products/DSC_11.jpg', 'uploads/products/a1.jpg', 'uploads/products/shared.jpg', 'uploads/products/o1.jpg'] as $i => $rel) {
        is2Picture($rel, $i);
    }

    $medi = Product::create(['name' => 'Medicube PDRN Eye Patches', 'slug' => 'is2-medi', 'status' => 'publish', 'brand_id' => $medicube->id, 'category_id' => $cat->id, 'price' => 100,
        'image' => is2Url('uploads/products/IMG_0001.jpg'), 'images' => [is2Url('uploads/products/IMG_0002.jpg'), '/uploads/products/IMG_0003.jpg']]);
    $cosrx = Product::create(['name' => 'COSRX Snail Mucin Essence', 'slug' => 'is2-cosrx', 'status' => 'publish', 'brand_id' => $cosrxB->id, 'price' => 100,
        'image' => '/uploads/products/DSC_10.jpg', 'images' => ['/uploads/products/DSC_11.jpg'],
        'image_alts' => ['/uploads/products/DSC_10.jpg' => 'Written by the owner himself']]);
    $anua = Product::create(['name' => 'Anua Heartleaf Toner', 'slug' => 'is2-anua', 'status' => 'publish', 'brand_id' => $anuaB->id, 'price' => 100,
        'image' => '/uploads/products/a1.jpg', 'images' => ['/uploads/products/shared.jpg']]);
    $oil = Product::create(['name' => 'Anua Cleansing Oil', 'slug' => 'is2-oil', 'status' => 'publish', 'brand_id' => $anuaB->id, 'price' => 100,
        'image' => '/uploads/products/o1.jpg', 'images' => ['/uploads/products/shared.jpg']]);

    MediaUsageWriter::rebuild();

    return ['medi' => $medi->fresh(), 'cosrx' => $cosrx->fresh(), 'anua' => $anua->fresh(), 'oil' => $oil->fresh(), 'brand' => $medicube];
}

function is2Admin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'IS2 '.$role, 'email' => 'is2-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function is2Ids(array $ids): array
{
    return ['mode' => 'ids', 'ids' => array_map('intval', $ids)];
}

/** The screen's preview loop: POST /preview until done. */
function is2Preview(string $token, string $kind, array $selection, array $extra = []): array
{
    $offset = 0;
    $last = [];

    for ($i = 0; $i < 50; $i++) {
        $last = test()->postJson('/admin-api/image-seo/preview', ['token' => $token, 'kind' => $kind, 'offset' => $offset, 'selection' => $selection] + $extra)->assertOk()->json();

        if ($last['done']) {
            break;
        }

        $offset = $last['at'];
    }

    return $last;
}

/** The screen's run loop: POST /step until done. */
function is2Steps(int $id): array
{
    $job = [];

    for ($i = 0; $i < 50; $i++) {
        $job = test()->postJson('/admin-api/image-seo/step', ['job' => $id])->assertOk()->json('job');

        if ($job['status'] === 'done') {
            break;
        }
    }

    return $job;
}

/* -------------------------------------------- 1. the dead Start button */

it('refuses Start without a finished preview, then runs exactly what the preview froze', function () {
    is2Root();
    ImageSeoAdminRoutes::wire(app());
    $shop = is2Shop();
    test()->actingAs(is2Admin(), 'admin');

    // Fifty-five products with no picture, so the preview takes two requests.
    for ($i = 0; $i < 55; $i++) {
        Product::create(['name' => "Medicube Filler $i", 'slug' => "is2-filler-$i", 'brand_id' => $shop['brand']->id, 'price' => 1]);
    }

    $selection = ['mode' => 'all', 'filter' => ['brand' => $shop['brand']->id]];
    $token = 'tok-'.bin2hex(random_bytes(8));

    // No preview at all.
    test()->postJson('/admin-api/image-seo/start', ['token' => $token])->assertStatus(409)->assertJsonPath('need_preview', true);

    // Half a preview.
    $first = test()->postJson('/admin-api/image-seo/preview', ['token' => $token, 'kind' => 'rename', 'offset' => 0, 'selection' => $selection])->assertOk()->json();
    expect($first['done'])->toBeFalse()->and($first['at'])->toBe(50)->and($first['total'])->toBe(56);
    test()->postJson('/admin-api/image-seo/start', ['token' => $token])->assertStatus(409)->assertJsonPath('need_preview', true);
    // A stepped preview renames nothing either.
    test()->postJson('/admin-api/image-seo/step', ['job' => $first['job_id']])->assertStatus(409);
    expect(is_file(public_path('uploads/products/IMG_0001.jpg')))->toBeTrue();

    $done = test()->postJson('/admin-api/image-seo/preview', ['token' => $token, 'kind' => 'rename', 'offset' => 50, 'selection' => $selection])->assertOk()->json();
    expect($done['done'])->toBeTrue()->and($done['counts']['rename'])->toBe(3)->and($done['counts']['products'])->toBe(56);

    // A product added after the preview is not in the run: the selection was frozen.
    Product::create(['name' => 'Medicube Late Arrival', 'slug' => 'is2-late', 'brand_id' => $shop['brand']->id, 'price' => 1, 'image' => '/uploads/products/o1.jpg']);

    $job = test()->postJson('/admin-api/image-seo/start', ['token' => $token])->assertOk()->json('job');
    expect($job['total'])->toBe(56)->and($job['status'])->toBe('running');

    $job = is2Steps($job['id']);
    expect($job['status'])->toBe('done')->and($job['renamed'])->toBe(3)
        ->and($shop['medi']->fresh()->image)->toBe(is2Url('uploads/products/medicube-pdrn-eye-patches.jpg'));

    // Pressed twice: the same run, not a second one.
    expect(test()->postJson('/admin-api/image-seo/start', ['token' => $token])->assertOk()->json('job.id'))->toBe($job['id']);
    // MUTATION: drop the `at < total` check in ImageSeoJobs::begin() and the half-preview Start is a 200.
    // MUTATION: drop step()'s `status === 'preview'` refusal and the stepped preview renames IMG_0001.jpg.
});

it('lets Start be pressed with no preview: the screen previews first, then asks with the real numbers', function () {
    $blade = file_get_contents(resource_path('views/admin/partials/image-seo-screen.blade.php'));

    // The old gate: disabled until a preview existed, with no reason on screen.
    expect($blade)->not->toContain("(!preview || !preview.rename || jobBusy || busy ? ' disabled' : '')");

    // Start and the combined button both go through go(), which previews when
    // there is no finished preview of that kind, then confirms, then starts.
    preg_match('/async function go\(kind\) \{(.*?)\n  \}/s', $blade, $m);
    expect($m[1] ?? '')->toContain("if (!(pv && pv.kind === kind && pv.done))")
        ->and(strpos($m[1], 'runPreview(kind)'))->toBeLessThan(strpos($m[1], 'window.confirm('))
        ->and(strpos($m[1], 'window.confirm('))->toBeLessThan(strpos($m[1], "'/image-seo/start'"))
        ->and($blade)->toContain("if (a === 'start') { go('rename'); return; }")
        ->and($blade)->toContain("if (a === 'combo') { go('combo'); return; }")
        ->and($blade)->toContain("'Preview, then start renaming'")
        // Greyed only while nothing is selected, something is running, or the
        // finished preview found nothing -- never for "no preview yet".
        ->and($blade)->toContain('var off = none || !!busy || jobBusy;')
        ->and($blade)->toContain("var startOff = off || (ready('rename') && !pv.counts.rename);")
        ->and($blade)->toContain("var comboOff = off || (ready('combo') && !(pv.counts.rename + pv.counts.alt));");

    // Every greyed state has a sentence under the buttons.
    preg_match('/function why\(\) \{(.*?)\n  \}/s', $blade, $w);
    expect($w[1] ?? '')->toContain('selEmpty()')->toContain('jobBusy')->toContain('nothingWhy(pv)');
    // MUTATION: put `!pv ||` back in front of startOff and the button is greyed again before a preview.
});

it('says why nothing would be renamed, and refuses to start a run with nothing in it', function () {
    is2Root();
    ImageSeoAdminRoutes::wire(app());
    $shop = is2Shop();
    test()->actingAs(is2Admin(), 'admin');

    // Only the shared picture of the oil: shared, so skipped unless asked.
    $selection = ['mode' => 'ids', 'ids' => [$shop['oil']->id], 'only' => [['p' => $shop['oil']->id, 'rels' => ['uploads/products/shared.jpg']]]];
    $token = 'tok-'.bin2hex(random_bytes(8));
    $out = is2Preview($token, 'rename', $selection);

    expect($out['counts']['rename'])->toBe(0)
        ->and($out['counts']['products'])->toBe(1)
        ->and($out['counts']['reasons'])->toBe(['shared with other products' => 1]);

    test()->postJson('/admin-api/image-seo/start', ['token' => $token])->assertStatus(422)->assertJsonPath('message', 'The preview found nothing to change.');

    // Ticked, the same picture is one to rename.
    $again = is2Preview('tok-'.bin2hex(random_bytes(8)), 'rename', $selection, ['include_shared' => true]);
    expect($again['counts']['rename'])->toBe(1)->and($again['counts']['reasons'])->toBe([]);

    // And the screen turns those reasons into the sentence under the buttons.
    $blade = file_get_contents(resource_path('views/admin/partials/image-seo-screen.blade.php'));
    expect($blade)->toContain("Tick \"Include pictures shared by several products\" to rename shared ones.");
    // MUTATION: stop adding to $counts['reasons'] in previewChunk() and `reasons` is [] -- the screen has no why to show.
});

/* ------------------------------------ 2. Rename files + ALT text, Undo */

it('renames and writes ALT text in one run, keeps ALT text somebody wrote, and Undo reverses both', function () {
    is2Root();
    ImageSeoAdminRoutes::wire(app());
    $shop = is2Shop();
    test()->actingAs(is2Admin(), 'admin');

    $token = 'tok-'.bin2hex(random_bytes(8));
    $preview = is2Preview($token, 'combo', is2Ids([$shop['medi']->id, $shop['cosrx']->id]), ['template' => 'variations', 'keep' => true]);

    // Five files to rename; four ALT texts (COSRX's first is kept as written).
    expect($preview['counts']['rename'])->toBe(5)
        ->and($preview['counts']['alt'])->toBe(4)
        ->and($preview['counts']['kept'])->toBe(1)
        ->and($preview['items'][0]['images'][0]['alt'])->toBe('Medicube PDRN Eye Patches');

    $job = test()->postJson('/admin-api/image-seo/start', ['token' => $token])->assertOk()->json('job');
    $job = is2Steps($job['id']);

    $medi = $shop['medi']->fresh();
    $cosrx = $shop['cosrx']->fresh();

    expect($job['kind'])->toBe('combo')->and($job['renamed'])->toBe(5)->and($job['alt_written'])->toBe(4)->and($job['failed'])->toBe(0)
        // Renamed, and the ALT text is keyed by the NEW addresses.
        ->and($medi->image)->toBe(is2Url('uploads/products/medicube-pdrn-eye-patches.jpg'))
        ->and($medi->image_alts[$medi->image])->toBe('Medicube PDRN Eye Patches')
        ->and($medi->image_alts[$medi->images[0]])->toBe('PDRN Eye Patches by Medicube')
        ->and(count($medi->image_alts))->toBe(3)
        // The owner's own ALT text kept; the second picture written.
        ->and($cosrx->image_alts[$cosrx->image])->toBe('Written by the owner himself')
        ->and($cosrx->image_alts[$cosrx->images[0]])->toBe('Snail Mucin Essence by COSRX');

    // Somebody edits one ALT text after the run: Undo leaves that one alone.
    $edited = $medi->image_alts;
    $edited[$medi->images[1]] = 'Changed by hand afterwards';
    $medi->image_alts = $edited;
    $medi->save();

    $undo = test()->postJson('/admin-api/image-seo/undo', ['job' => $job['id'], 'confirm' => 'UNDO'])->assertOk()->json('job');
    $undo = is2Steps($undo['id']);

    $medi = $shop['medi']->fresh();
    $cosrx = $shop['cosrx']->fresh();

    expect($undo['renamed'])->toBe(5)->and($undo['alt_written'])->toBe(3)
        ->and($medi->image)->toBe(is2Url('uploads/products/IMG_0001.jpg'))
        ->and($medi->images)->toBe([is2Url('uploads/products/IMG_0002.jpg'), '/uploads/products/IMG_0003.jpg'])
        ->and(is_file(public_path('uploads/products/IMG_0001.jpg')))->toBeTrue()
        // Only the hand-edited ALT text survives, under the old address.
        ->and($medi->image_alts)->toBe(['/uploads/products/IMG_0003.jpg' => 'Changed by hand afterwards'])
        ->and($cosrx->image_alts)->toBe(['/uploads/products/DSC_10.jpg' => 'Written by the owner himself'])
        ->and(ImageRenameRedirect::resolvePath('uploads/products/medicube-pdrn-eye-patches.jpg'))->toBe('uploads/products/IMG_0001.jpg');

    // Undo twice is one undo; an undo cannot itself be undone.
    test()->postJson('/admin-api/image-seo/undo', ['job' => $job['id'], 'confirm' => 'UNDO'])->assertOk()->assertJsonPath('job.id', $undo['id']);
    test()->postJson('/admin-api/image-seo/undo', ['job' => $undo['id'], 'confirm' => 'UNDO'])->assertStatus(422);
    // MUTATION: drop the `alt` entries ImageSeoJobs::undo() adds for a combo run and the ALT text stays on the restored pictures.
});

it('unticking "keep" replaces ALT text somebody wrote, and only for the pictures selected', function () {
    is2Root();
    ImageSeoAdminRoutes::wire(app());
    $shop = is2Shop();
    test()->actingAs(is2Admin(), 'admin');

    $selection = ['mode' => 'ids', 'ids' => [$shop['cosrx']->id], 'only' => [['p' => $shop['cosrx']->id, 'rels' => ['uploads/products/DSC_10.jpg']]]];
    $token = 'tok-'.bin2hex(random_bytes(8));
    $preview = is2Preview($token, 'combo', $selection, ['keep' => false]);

    expect($preview['counts']['rename'])->toBe(1)->and($preview['counts']['alt'])->toBe(1)->and($preview['counts']['kept'])->toBe(0);

    is2Steps(test()->postJson('/admin-api/image-seo/start', ['token' => $token])->assertOk()->json('job.id'));
    $cosrx = $shop['cosrx']->fresh();

    expect($cosrx->image)->toBe('/uploads/products/cosrx-snail-mucin-essence.jpg')
        ->and($cosrx->image_alts)->toBe(['/uploads/products/cosrx-snail-mucin-essence.jpg' => 'COSRX Snail Mucin Essence'])
        ->and($cosrx->images)->toBe(['/uploads/products/DSC_11.jpg']);
    // MUTATION: ignore `$only` in ImageSeoJobs::altPlan() and DSC_11.jpg gets ALT text too (alt = 2).
});

/* ------------------------------------------------- 3. live progress */

it('answers each step with true counts, elapsed time and no log, and a stopped run stays stopped', function () {
    is2Root();
    ImageSeoAdminRoutes::wire(app());
    $shop = is2Shop();
    test()->actingAs(is2Admin(), 'admin');

    $token = 'tok-'.bin2hex(random_bytes(8));
    is2Preview($token, 'combo', is2Ids([$shop['medi']->id, $shop['cosrx']->id, $shop['anua']->id, $shop['oil']->id]));
    $id = test()->postJson('/admin-api/image-seo/start', ['token' => $token])->assertOk()->json('job.id');

    // Stop before the first step: a step without Resume does nothing.
    test()->postJson('/admin-api/image-seo/stop', ['job' => $id])->assertOk()->assertJsonPath('job.status', 'stopped');
    $idle = test()->postJson('/admin-api/image-seo/step', ['job' => $id])->assertOk()->json();
    expect($idle['job']['status'])->toBe('stopped')->and($idle['job']['position'])->toBe(0)->and($idle['results'])->toBe([]);

    $res = test()->postJson('/admin-api/image-seo/step', ['job' => $id, 'resume' => true])->assertOk();
    expect($res->json('job'))->not->toHaveKey('log')
        ->and($res->json('job.status'))->toBeIn(['running', 'done'])
        ->and(strlen((string) $res->getContent()))->toBeLessThan(8000);

    $job = is2Steps($id);

    expect($job['status'])->toBe('done')
        ->and($job['position'])->toBe(4)->and($job['total'])->toBe(4)
        // medi 3 + cosrx 2 + anua 1 + oil 1 renamed; the shared picture skipped twice.
        ->and($job['renamed'])->toBe(7)
        ->and($job['skipped'])->toBe(2)
        ->and($job['failed'])->toBe(0)
        // medi 3, cosrx 1 (one kept), anua 2, oil 2.
        ->and($job['alt_written'])->toBe(8)
        ->and($job['elapsed_ms'])->toBeGreaterThan(0);

    $full = test()->getJson('/admin-api/image-seo/job?id='.$id)->assertOk()->json('job');
    expect($full['failures'])->toBe([])
        ->and(collect($full['log'])->where('status', 'skipped')->pluck('reason')->unique()->values()->all())
        ->toBe(['shared with 1 other product(s) — tick "Include shared pictures" to rename it']);
    // MUTATION: drop the 'alt' branch of ImageSeoJobs::count() and alt_written is 0 (the eight land under skipped).
    // MUTATION: let step() resume a stopped job without `resume` and the idle step moves the cursor.
});

/* ------------------------------------- 4. select all, across pages */

it('resolves "all matching F except these" on the server, the same at any page, with closed filter keys', function () {
    is2Root();
    ImageSeoAdminRoutes::wire(app());
    $brand = Brand::create(['name' => 'Bulk Beauty', 'slug' => 'is2-bulk']);
    $other = Brand::create(['name' => 'Elsewhere', 'slug' => 'is2-else']);
    is2Picture('uploads/products/one.jpg');
    is2Picture('uploads/products/two.jpg', 1);
    $ids = [];

    for ($i = 1; $i <= 45; $i++) {
        $ids[] = Product::create(['name' => sprintf('Bulk Beauty Toner %02d', $i), 'slug' => "is2-bulk-$i", 'brand_id' => $brand->id, 'price' => 1,
            'image' => '/uploads/products/one.jpg', 'images' => ['/uploads/products/two.jpg', 'https://elsewhere.example/x.jpg']])->id;
    }

    Product::create(['name' => 'Not Bulk', 'slug' => 'is2-not', 'brand_id' => $other->id, 'price' => 1, 'image' => '/uploads/products/one.jpg']);
    test()->actingAs(is2Admin(), 'admin');

    // The Find tab pages it 20 at a time: 45 is three pages.
    expect(test()->getJson('/admin-api/image-seo/find?brand='.$brand->id.'&page=3')->assertOk()->json('pages'))->toBe(3);

    // One unticked on page 1, one on page 3 -- and pictures of a third cut to one.
    $selection = ['mode' => 'all', 'filter' => ['q' => '', 'brand' => $brand->id, 'category' => 0, 'filter' => ''],
        'except' => [$ids[0], $ids[44]], 'only' => [['p' => $ids[10], 'rels' => ['uploads/products/one.jpg']]]];

    test()->postJson('/admin-api/image-seo/selection', ['selection' => $selection])->assertOk()
        ->assertJsonPath('products', 43)
        ->assertJsonPath('matching', 45)
        // Two local pictures each, the remote one not counted, one product cut to one.
        ->assertJsonPath('pictures', 85);

    // The run's list is the same rule, resolved again on the server.
    $resolved = ImageSeoSelection::from($selection)->ids();
    expect($resolved)->toHaveCount(43)->not->toContain($ids[0])->not->toContain($ids[44])->toContain($ids[10]);

    // Closed keys, clamped values: a page, a sort, an unknown filter, a negative brand, a long term.
    foreach ([
        ['mode' => 'all', 'filter' => ['brand' => $brand->id, 'page' => 2]],
        ['mode' => 'all', 'filter' => ['brand' => $brand->id, 'sort' => 'name']],
        ['mode' => 'all', 'filter' => ['filter' => 'drop table']],
        ['mode' => 'all', 'filter' => ['brand' => -1]],
        ['mode' => 'all', 'filter' => ['q' => str_repeat('a', 121)]],
        ['mode' => 'everything'],
        ['mode' => 'ids', 'ids' => ['1; drop']],
        ['mode' => 'all', 'extra' => 1],
    ] as $bad) {
        test()->postJson('/admin-api/image-seo/selection', ['selection' => $bad])->assertStatus(422);
    }

    // The screen keeps the selection out of the page: paging re-fetches the
    // page and never writes `sel`.
    $blade = file_get_contents(resource_path('views/admin/partials/image-seo-screen.blade.php'));
    preg_match('/async function find\(page\) \{(.*?)\n  \}/s', $blade, $m);
    expect($m[1] ?? '')->not->toBe('')->not->toContain('sel.')->not->toContain('sel =')
        ->and($blade)->toContain("if (sel.mode === 'all') { if (on) delete sel.except[pid]; else sel.except[pid] = true; }");
    // MUTATION: drop the `except` check in ImageSeoSelection::ids() and products is 45.
    // MUTATION: loosen 'array:q,brand,category,filter' to 'array' and the page/sort filters are 200s.
});

it('costs the same number of queries to count a selection of 3 products as of 40', function () {
    is2Root();
    $brand = Brand::create(['name' => 'Bulk', 'slug' => 'is2-bulk-q']);
    is2Picture('uploads/products/one.jpg');
    $make = function (int $from, int $n) use ($brand): void {
        for ($i = $from; $i < $from + $n; $i++) {
            Product::create(['name' => "Bulk $i", 'slug' => "is2-q-$i", 'brand_id' => $brand->id, 'price' => 1, 'image' => '/uploads/products/one.jpg', 'images' => ["/uploads/products/$i.jpg"]]);
        }
    };

    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    $measure = function () use (&$n, $brand): int {
        $n = 0;
        $sel = ImageSeoSelection::from(['mode' => 'all', 'filter' => ['brand' => $brand->id]]);
        $sel->pictures($sel->ids());

        return $n;
    };

    $make(0, 3);
    $small = $measure();
    $make(3, 37);

    expect($measure())->toBe($small);
    // MUTATION: query each product's variants inside pictures()' loop and 40 products cost 37 more queries.
});

/* ------------------------------------------------ 5. locks, guards */

it('refuses a second Start while a run is going, and while another Start holds the lock', function () {
    is2Root();
    ImageSeoAdminRoutes::wire(app());
    $shop = is2Shop();
    test()->actingAs(is2Admin(), 'admin');

    $a = 'tok-'.bin2hex(random_bytes(8));
    $b = 'tok-'.bin2hex(random_bytes(8));
    is2Preview($a, 'rename', is2Ids([$shop['medi']->id]));
    is2Preview($b, 'combo', is2Ids([$shop['cosrx']->id]));

    // Another Start is being decided right now.
    $held = Cache::lock('kbb.image-seo.start', 15);
    expect($held->get())->toBeTrue();
    test()->postJson('/admin-api/image-seo/start', ['token' => $a])->assertStatus(409);
    test()->postJson('/admin-api/image-seo/alt-start', ['token' => $a, 'items' => [['p' => $shop['medi']->id, 'alts' => [$shop['medi']->image => 'Medicube PDRN Eye Patches']]]])->assertStatus(409);
    $held->release();

    $first = test()->postJson('/admin-api/image-seo/start', ['token' => $a])->assertOk()->json('job');
    test()->postJson('/admin-api/image-seo/start', ['token' => $b])->assertStatus(409)->assertJsonPath('running.id', $first['id']);

    // The same Start again is the same run, not a refusal and not a second run.
    test()->postJson('/admin-api/image-seo/start', ['token' => $a])->assertOk()->assertJsonPath('job.id', $first['id']);
    expect(DB::table('image_seo_jobs')->where('status', 'running')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('event', 'image_seo')->where('summary', 'like', '%rename started%')->count())->toBe(1);

    // A preview token cannot name an undo, nor an undo be started through /start.
    test()->postJson('/admin-api/image-seo/start', ['token' => 'undo-'.$first['id'].'-0000000000'])->assertStatus(409);
    // MUTATION: drop the Cache::lock in start() and the first Start goes through while the lock is held.
    // MUTATION: drop the running() check in start() and run $b starts beside run $a.
});

it('keeps every new endpoint behind media.image_seo, failing closed', function () {
    ImageSeoAdminRoutes::wire(app());

    $uris = array_map(fn ($r) => $r->uri(), ImageSeoAdminRoutes::registered());
    expect($uris)->toContain('admin-api/image-seo/selection')->not->toContain('admin-api/image-seo/ids');

    foreach (ImageSeoAdminRoutes::registered() as $route) {
        expect(\App\Support\AdminCapabilities::forPath($route->methods()[0], $route->uri()))->toBe('media.image_seo', $route->uri());
    }

    test()->postJson('/admin-api/image-seo/selection', ['selection' => ['mode' => 'ids', 'ids' => [1]]])->assertUnauthorized();

    test()->actingAs(is2Admin('editor'), 'admin');
    test()->postJson('/admin-api/image-seo/selection', ['selection' => ['mode' => 'ids', 'ids' => [1]]])->assertForbidden();
    test()->postJson('/admin-api/image-seo/preview', ['token' => 'tok-0123456789abcdef', 'kind' => 'combo', 'offset' => 0, 'selection' => ['mode' => 'ids', 'ids' => [1]]])->assertForbidden();
    test()->postJson('/admin-api/image-seo/start', ['token' => 'tok-0123456789abcdef'])->assertForbidden();

    test()->actingAs(is2Admin('manager'), 'admin');
    test()->postJson('/admin-api/image-seo/selection', ['selection' => ['mode' => 'ids', 'ids' => [1]]])->assertOk();
    // MUTATION: map 'admin-api/image-seo/**' to a capability the editor holds and the editor's 403s are 200s.
});

it('keeps previews out of History and out of Resume', function () {
    is2Root();
    ImageSeoAdminRoutes::wire(app());
    $shop = is2Shop();
    test()->actingAs(is2Admin(), 'admin');

    is2Preview('tok-'.bin2hex(random_bytes(8)), 'rename', is2Ids([$shop['medi']->id]));

    $boot = test()->getJson('/admin-api/image-seo')->assertOk()->json();
    expect($boot['jobs'])->toBe([])->and($boot['running'])->toBeNull()->and($boot['resumable'])->toBeNull()
        ->and(ImageSeoJobs::recent())->toBe([]);
    // MUTATION: drop `where('status', '!=', 'preview')` from recent() and every preview shows as a run in History.
});
