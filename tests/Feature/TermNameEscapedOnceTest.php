<?php

declare(strict_types=1);

/**
 * "HYDRATION &amp; GLOW" — A CATEGORY NAME ESCAPED TWICE. Lane FP.
 *
 * In the owner's own screenshot of the Filters drawer
 * (docs/fp-owner/filters-mobile.png) the second row reads "Hydration &amp;
 * Glow". The page source said `Hydration &amp;amp; Glow`.
 *
 * ROOT CAUSE: WordPress keeps a term name HTML-encoded in wp_terms.name, and
 * CategoryImporter / BrandImporter copied it as it came, so the column held
 * "Hydration &amp; Glow". The template escapes what it prints -- once, which is
 * right -- and the stored entity became a visible one. The brand list in the
 * same drawer has the same shape ("Rom&amp;nd"), and so did the brand name in
 * the listing's JSON-LD.
 *
 * FIXED AT THE SOURCE: the importer stores the plain text (App\Support\
 * TermName), and 2027_08_19_100000_decode_imported_term_names brings the rows
 * already imported into line. No template changed; every one still escapes
 * exactly once, and a decoded `<` is printed as `&lt;`, never raw.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Support\TermName;

function fptMigrate(): void
{
    (require base_path('database/migrations/2027_08_19_100000_decode_imported_term_names.php'))->up();
}

/** A category and a brand stored the way the importer stored them, each with products. */
function fptImportedShape(string $cat = 'Hydration &amp; Glow', string $brand = 'Rom&amp;nd'): array
{
    $c = Category::create(['name' => $cat, 'slug' => 'fpt-hydration-glow', 'depth' => 0, 'position' => 0, 'path' => 'fpt-hydration-glow']);
    $b = Brand::create(['name' => $brand, 'slug' => 'fpt-romand']);

    $ids = Product::query()->visible()->orderBy('id')->limit(3)->pluck('id')->all();
    expect($ids)->not->toBeEmpty('the demo catalogue has no visible product to file');
    $c->products()->syncWithoutDetaching($ids);
    Product::query()->whereIn('id', $ids)->update(['brand_id' => $b->id]);
    \App\Http\Controllers\Store\ShopController::flushSidebarCache();

    return [$c, $b];
}

/** The filter drawer's markup only. */
function fptDrawer(string $html): string
{
    $at = strpos($html, '<aside class="filtercol" id="fcol">');
    expect($at)->not->toBeFalse();

    return substr($html, $at, strpos($html, '</aside>', $at) - $at);
}

it('draws "Hydration & Glow" in the Filters drawer, escaped exactly once', function () {
    /*
     * The defect, pinned first: the imported row prints the entity.
     * MUTATION, RUN: make TermName::plain() return $name unchanged -> red: the
     * migration fixes nothing and the drawer reads "&amp;amp;" again.
     */
    fptImportedShape();

    $beforeHtml = (string) $this->get('/shop/')->assertOk()->getContent();
    expect(fptDrawer($beforeHtml))->toContain('Hydration &amp;amp; Glow')
        ->and($beforeHtml)->toContain('Rom'.chr(92).'u0026amp;nd');

    fptMigrate();

    $html = (string) $this->get('/shop/')->assertOk()->getContent();
    $drawer = fptDrawer($html);

    expect($drawer)->toContain(' Hydration &amp; Glow')
        ->and($drawer)->toContain(' Rom&amp;nd')
        ->and($drawer)->not->toContain('&amp;amp;')
        ->and($html)->not->toContain('&amp;amp;')
        // The listing's JSON-LD carried it too, as JSON (\u0026 is "&").
        ->and($html)->not->toContain('Rom'.chr(92).'u0026amp;nd');

    expect(Category::where('slug', 'fpt-hydration-glow')->value('name'))->toBe('Hydration & Glow')
        ->and(Brand::where('slug', 'fpt-romand')->value('name'))->toBe('Rom&nd');
});

it('prints a decoded < as &lt;, never raw', function () {
    /*
     * Decoding puts a real `<` in the column, which is only safe because no
     * template prints a term name unescaped. This is that promise, kept.
     *
     * MUTATION, RUN: print the category name with {!! !!} in shop.blade.php ->
     * red.
     */
    fptImportedShape('&lt;b&gt;Bold&lt;/b&gt; Picks', 'A&amp;B');
    fptMigrate();

    expect(Category::where('slug', 'fpt-hydration-glow')->value('name'))->toBe('<b>Bold</b> Picks');

    $html = (string) $this->get('/shop/')->assertOk()->getContent();

    expect(fptDrawer($html))->toContain('&lt;b&gt;Bold&lt;/b&gt; Picks')
        ->and($html)->not->toContain('<b>Bold</b>');
});

it('leaves a name typed in the admin byte for byte, and moves no slug', function () {
    /*
     * "Bath & Body" typed in Catalog → Categories is stored plain already and
     * has no entity in it. The migration only writes a row that decodes to
     * something different.
     */
    $typed = Category::create(['name' => 'Bath & Body', 'slug' => 'bath-body', 'depth' => 0, 'position' => 0, 'path' => 'bath-body']);
    [$c] = fptImportedShape();
    $stamp = $typed->fresh()->updated_at;

    fptMigrate();

    expect($typed->fresh()->name)->toBe('Bath & Body')
        ->and($typed->fresh()->updated_at?->toIso8601String())->toBe($stamp?->toIso8601String())
        ->and($c->fresh()->slug)->toBe('fpt-hydration-glow');

    expect(TermName::plain('Bath & Body'))->toBe('Bath & Body')
        ->and(TermName::plain('Hydration &amp; Glow'))->toBe('Hydration & Glow')
        ->and(TermName::plain('Dr.Jart&#043; &#8211; Cicapair'))->toBe('Dr.Jart+ – Cicapair');
});

it('imports a WordPress term name as plain text, and keeps the slug it came with', function () {
    /*
     * The other half of the source: a re-import must not put the entity back.
     * MUTATION, RUN: drop TermName::plain() from CategoryImporter::identity()
     * -> red on the category; from BrandImporter -> red on the brand.
     */
    $dir = storage_path('framework/testing/fpt-import-'.getmypid().'-'.bin2hex(random_bytes(3)));
    @mkdir($dir, 0775, true);

    $csv = function (string $file, array $header, array $row) use ($dir): void {
        $h = fopen($dir.'/'.$file, 'wb');
        fputcsv($h, $header, ',', '"', '');
        fputcsv($h, $row, ',', '"', '');
        fclose($h);
    };
    $csv('categories.csv', ['term_id', 'name', 'slug', 'parent', 'description', 'image', 'position'],
        ['9101', 'Hydration &amp; Glow', 'hydration-glow', '0', '', '', '0']);
    $csv('brands.csv', ['term_id', 'name', 'slug', 'description', 'logo', 'position'],
        ['9201', 'Rom&amp;nd', 'romand', '', '', '0']);

    try {
        (new ImportRunner)->run(new ImportOptions(
            directory: $dir, only: ['categories', 'brands'], runKey: 'fpt-'.bin2hex(random_bytes(4)), dryRun: false, restart: true,
        ));
    } finally {
        @unlink($dir.'/categories.csv');
        @unlink($dir.'/brands.csv');
        @rmdir($dir);
    }

    expect(Category::where('source_term_id', 9101)->first()?->only(['name', 'slug']))->toBe(['name' => 'Hydration & Glow', 'slug' => 'hydration-glow'])
        ->and(Brand::where('source_term_id', 9201)->first()?->only(['name', 'slug']))->toBe(['name' => 'Rom&nd', 'slug' => 'romand']);
});
