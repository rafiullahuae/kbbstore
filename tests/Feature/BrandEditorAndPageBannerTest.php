<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\PageBanner;
use Tests\Support\BrandAdminRoutes;

/**
 * Catalog → Brands, and the category/brand page banner (Lane BW).
 *
 * Two things ship together here because they are the same complaint. The owner
 * could not add or edit a brand — the Brands tab rendered a read-only
 * drag-to-reorder list with an empty .ct-acts on every row, while
 * BrandsApiController had had store(), update() and destroy() the whole time —
 * and they wanted a banner they could switch on per category and per brand.
 * Both are edited in the same dialog, so both are pinned in the same file.
 *
 * ON ASSERTING AGAINST RENDERED HTML. Searching a page for the string
 * "kbb-banner" proves nothing: the class name also appears in the stylesheet,
 * in the component's own comments, and in any inlined CSS the layout carries.
 * Every assertion below that means "this element is on the page" matches an
 * ELEMENT — /<section[^>]*class="[^"]*kbb-banner/ — not a bare substring. The
 * default-off test in particular would pass against a fully rendered banner if
 * it were written the easy way.
 *
 * Fixtures use "t-" slugs, like BrandUrlTest and CatalogAdminCategoriesTest,
 * because the demo catalogue a migration seeds is already in the table and the
 * first test in a process is not isolated (see tests/Pest.php).
 */

/* ---------------------------------------------------------------- helpers */

function bwAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'T BW Admin',
        'email' => 't-bw-admin@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);
}

/** A banner bag with everything filled in, so a test only states its own variable. */
function bwBanner(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'style' => 'full',
        'image' => '/media/t-banner.jpg',
        'image_alt' => 'A row of serum bottles',
        'heading' => 'T Banner Heading',
        'subheading' => 'T banner subheading text.',
        'tone' => 'light',
        'overlay' => 55,
        'tint' => '#123456',
    ], $overrides);
}

function bwCategory(?array $banner = null): Category
{
    return Category::create([
        'slug' => 't-bw-cat',
        'name' => 'T BW Cat',
        'path' => 't-bw-cat',
        'banner' => $banner,
    ]);
}

function bwBrand(?array $banner = null): Brand
{
    return Brand::create([
        'slug' => 't-bw-brand',
        'name' => 'T BW Brand',
        'banner' => $banner,
    ]);
}

/** The archive for a category, which is where its banner has to appear. */
function bwCategoryHtml(Category $c): string
{
    return test()->get('/product-category/' . $c->path . '/')->assertOk()->getContent();
}

/**
 * A brand's listing.
 *
 * URL contract U-05: a brand has no path of its own. Brand::url() is
 * /shop/?filter_brands={slug}, a query parameter, and the contract says in as
 * many words that this must not be "improved" into a pretty URL. So this is
 * the address the banner has to work on, and asserting it here is also what
 * stops a later change quietly inventing /brand-archive/{slug}/.
 */
function bwBrandListingHtml(Brand $b): string
{
    return test()->get('/shop/?filter_brands=' . $b->slug)->assertOk()->getContent();
}

/** The brand's own landing page — the other surface U-05 leaves room for. */
function bwBrandPageHtml(Brand $b): string
{
    return test()->get('/korean-skincare-brands/' . $b->slug . '/')->assertOk()->getContent();
}

/** True when a <section> element on the page carries the banner class. */
function bwHasBanner(string $html): bool
{
    return preg_match('/<section[^>]*class="[^"]*kbb-banner/', $html) === 1;
}

/* ================================================================= THE API
   Brand create, edit and delete through the endpoints the screen calls. */

describe('the brands API, signed in as an admin', function () {
    beforeEach(function () {
        BrandAdminRoutes::wire($this->app);
        $this->actingAs(bwAdmin(), 'admin');
    });

    it('creates a brand, deriving the slug from the name', function () {
        $this->postJson('/admin-api/brands', [
            'name' => 'T Beauty Of Joseon',
            'description' => 'Hanbang skincare.',
        ])->assertStatus(201)->assertJsonPath('brand.slug', 't-beauty-of-joseon');

        expect(Brand::query()->where('slug', 't-beauty-of-joseon')->exists())->toBeTrue();
    });

    it('edits a brand through the update endpoint', function () {
        $brand = bwBrand();

        $this->putJson('/admin-api/brands/' . $brand->id, [
            'name' => 'T BW Renamed',
            'slug' => 't-bw-renamed',
            'description' => 'Now with a description.',
            'position' => 7,
            'seo' => ['title' => 'T SEO title', 'description' => 'T SEO description'],
        ])->assertOk()->assertJsonPath('brand.slug', 't-bw-renamed');

        $fresh = $brand->fresh();

        expect($fresh->name)->toBe('T BW Renamed')
            ->and($fresh->position)->toBe(7)
            // The cast, not a raw JSON string. Brand declared no casts at all
            // before this lane, so seo came back as text that is truthy, has
            // no ->title, and renders as literal JSON wherever it is echoed.
            ->and($fresh->seo)->toBe(['title' => 'T SEO title', 'description' => 'T SEO description']);
    });

    it('deletes a brand that has no products', function () {
        $brand = bwBrand();

        $this->deleteJson('/admin-api/brands/' . $brand->id)->assertOk();

        expect(Brand::query()->whereKey($brand->id)->exists())->toBeFalse();
    });

    /*
     * The delete the UI has to tell the truth about.
     *
     * products.brand_id is nullable()->constrained()->nullOnDelete(), so the
     * database would take this happily and blank the brand on every product
     * that referenced it. destroy() refuses instead, with the count, unless
     * force=1 is passed — and the dialog this lane ships says exactly that,
     * in the same words, before the operator clicks anything.
     */
    it('refuses to delete a brand that still has products, and says how many', function () {
        $brand = bwBrand();

        Product::create([
            'slug' => 't-bw-prod', 'name' => 'T BW Prod', 'brand_id' => $brand->id,
            'price' => 1000, 'status' => 'publish',
        ]);

        $this->deleteJson('/admin-api/brands/' . $brand->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'brand_in_use')
            ->assertJsonPath('products_count', 1);

        expect(Brand::query()->whereKey($brand->id)->exists())->toBeTrue();
    });

    it('deletes a brand with products when forced, unbranding them rather than deleting them', function () {
        $brand = bwBrand();

        $product = Product::create([
            'slug' => 't-bw-prod2', 'name' => 'T BW Prod 2', 'brand_id' => $brand->id,
            'price' => 1000, 'status' => 'publish',
        ]);

        $this->deleteJson('/admin-api/brands/' . $brand->id . '?force=1')
            ->assertOk()
            ->assertJsonPath('unbranded', 1);

        expect(Brand::query()->whereKey($brand->id)->exists())->toBeFalse()
            ->and(Product::query()->whereKey($product->id)->exists())->toBeTrue()
            ->and(Product::query()->whereKey($product->id)->value('brand_id'))->toBeNull();
    });

    it('stores a banner through the brand endpoint and hands it back through the list', function () {
        $brand = bwBrand();

        $this->putJson('/admin-api/brands/' . $brand->id, [
            'name' => $brand->name,
            'slug' => $brand->slug,
            'banner' => bwBanner(['style' => 'split']),
        ])->assertOk();

        $stored = $brand->fresh()->banner;

        expect($stored['enabled'])->toBeTrue()
            ->and($stored['style'])->toBe('split')
            ->and($stored['heading'])->toBe('T Banner Heading');

        $listed = collect($this->getJson('/admin-api/brands')->assertOk()->json('brands'))
            ->firstWhere('id', $brand->id);

        expect(is_array($listed['banner']))->toBeTrue('the list endpoint must return the banner so the editor can populate its fields')
            ->and($listed['banner']['style'])->toBe('split');
    });

    /*
     * Turning the switch off must clear the bag, not leave an image and a
     * heading behind that nothing renders. A stored {"enabled":false} and a
     * stored NULL have to mean the same thing, or "off by default" has two
     * code paths and only one of them is tested.
     */
    it('clears the stored banner when the switch is turned off', function () {
        $brand = bwBrand(bwBanner());

        $this->putJson('/admin-api/brands/' . $brand->id, [
            'name' => $brand->name,
            'slug' => $brand->slug,
            'banner' => bwBanner(['enabled' => false]),
        ])->assertOk();

        expect($brand->fresh()->banner)->toBeNull();
    });
});

/* ============================================================== DEFAULT OFF
   The guarantee the whole feature rests on. */

it('renders no banner on a category page by default', function () {
    $html = bwCategoryHtml(bwCategory());

    expect(bwHasBanner($html))->toBeFalse('a category with no banner configured must render no banner element at all');

    // And the ordinary heading is still there, because nothing has replaced it.
    expect(str_contains($html, 'class="ptitle"'))->toBeTrue('the plain page heading must still render when there is no banner');
});

it('renders no banner on a brand page by default', function () {
    $brand = bwBrand();

    expect(bwHasBanner(bwBrandListingHtml($brand)))->toBeFalse('a brand with no banner configured must render no banner on its listing')
        ->and(bwHasBanner(bwBrandPageHtml($brand)))->toBeFalse('a brand with no banner configured must render no banner on its landing page');
});

it('treats a stored banner with the switch off as no banner', function () {
    // Written straight to the column rather than through the API, because the
    // API refuses to store this shape — the point is that a row that acquired
    // it some other way (an import, a hand-edited record) still renders
    // nothing.
    $category = bwCategory(bwBanner(['enabled' => false]));

    expect(bwHasBanner(bwCategoryHtml($category)))->toBeFalse('enabled:false must render exactly what a missing banner renders');
});

/* ================================================================== ON, AND
   what each style actually draws. */

it('renders the banner on a category page once it is switched on', function () {
    $html = bwCategoryHtml(bwCategory(bwBanner()));

    expect(bwHasBanner($html))->toBeTrue('a category with a banner switched on must render the banner element')
        ->and(str_contains($html, 'T Banner Heading'))->toBeTrue('the banner must carry the heading the owner typed')
        ->and(str_contains($html, 'T banner subheading text.'))->toBeTrue('the banner must carry the subheading the owner typed');

    // The banner carries the page's <h1>, so the plain one must stand down —
    // two elements both claiming to be the page heading is one too many, and
    // the one a crawler picks would be the one the owner did not design.
    expect(preg_match_all('/<h1[^>]*>/', $html))->toBe(1);
});

it('renders the banner on a brand page, on the listing and on the landing page', function () {
    $brand = bwBrand(bwBanner());

    expect(bwHasBanner(bwBrandListingHtml($brand)))->toBeTrue('the brand listing at /shop/?filter_brands= must render the banner')
        ->and(bwHasBanner(bwBrandPageHtml($brand)))->toBeTrue('the brand landing page must render the banner');
});

/*
 * What counts as a brand page, given that a brand has no path.
 *
 * /shop/?filter_brands=a,b is a comparison of two brands, not a brand page;
 * showing one of the two banners across the top of it would be picking a
 * winner at random. Pinned because the temptation is to take the first slug.
 */
it('does not render a brand banner when more than one brand is filtered', function () {
    $brand = bwBrand(bwBanner());
    Brand::create(['slug' => 't-bw-other', 'name' => 'T BW Other']);

    $html = $this->get('/shop/?filter_brands=' . $brand->slug . ',t-bw-other')->assertOk()->getContent();

    expect(bwHasBanner($html))->toBeFalse('a two-brand comparison is not one brand’s page');
});

it('renders the full-bleed style with its own scrim and a real img element', function () {
    $html = bwCategoryHtml(bwCategory(bwBanner(['style' => 'full'])));

    expect(preg_match('/<section[^>]*class="[^"]*kbb-banner--full/', $html))->toBe(1)
        ->and(preg_match('/<div[^>]*class="[^"]*kbb-banner__scrim/', $html))->toBe(1);

    // A real <img>, not a CSS background: these pages are indexed, and
    // width/height on the element is half of what reserves the space before
    // the bytes arrive. The other half is aspect-ratio in the stylesheet.
    expect(preg_match('/<img[^>]*class="[^"]*kbb-banner__img[^"]*"[^>]*>/', $html, $img))->toBe(1);

    expect(str_contains($img[0], 'src="/media/t-banner.jpg'))->toBeTrue('the banner image must be a real img src')
        ->and(str_contains($img[0], 'width="' . PageBanner::IMG_WIDTH . '"'))->toBeTrue('the img needs an intrinsic width so the browser can reserve the box')
        ->and(str_contains($img[0], 'height="' . PageBanner::IMG_HEIGHT . '"'))->toBeTrue('the img needs an intrinsic height so the browser can reserve the box')
        ->and(str_contains($img[0], 'alt="A row of serum bottles"'))->toBeTrue('the img needs the alt text the owner typed');

    // The other two styles' unique elements must NOT be here.
    expect(preg_match('/kbb-banner__panel/', $html))->toBe(0)
        ->and(preg_match('/<div[^>]*class="[^"]*kbb-banner__wash/', $html))->toBe(0);
});

it('renders the split style with its own two-track markup and no scrim', function () {
    $html = bwCategoryHtml(bwCategory(bwBanner(['style' => 'split'])));

    expect(preg_match('/<section[^>]*class="[^"]*kbb-banner--split/', $html))->toBe(1)
        ->and(preg_match('/<div[^>]*class="[^"]*kbb-banner__split/', $html))->toBe(1)
        ->and(preg_match('/<div[^>]*class="[^"]*kbb-banner__panel/', $html))->toBe(1)
        ->and(preg_match('/<img[^>]*class="[^"]*kbb-banner__img/', $html))->toBe(1);

    // No scrim: the copy is never over the photograph in this style, so there
    // is nothing to darken. A scrim here would be the full-bleed layout
    // wearing a different class name.
    expect(preg_match('/<div[^>]*class="[^"]*kbb-banner__scrim/', $html))->toBe(0)
        ->and(preg_match('/<div[^>]*class="[^"]*kbb-banner__wash/', $html))->toBe(0);
});

it('renders the soft tint style with a wash and no image element at all', function () {
    $html = bwCategoryHtml(bwCategory(bwBanner(['style' => 'tint'])));

    expect(preg_match('/<section[^>]*class="[^"]*kbb-banner--tint/', $html))->toBe(1)
        ->and(preg_match('/<div[^>]*class="[^"]*kbb-banner__wash/', $html))->toBe(1);

    // This style needs no photograph, and does not draw one even when the row
    // still carries an image from a previous style choice — the fixture above
    // has one. A stray <img> here would be a hidden download on every visit.
    expect(preg_match('/<img[^>]*class="[^"]*kbb-banner__img/', $html))->toBe(0)
        ->and(preg_match('/<div[^>]*class="[^"]*kbb-banner__scrim/', $html))->toBe(0);
});

/* ============================================================ THE NO-IMAGE
   case: enabled, a style that wants a photograph, and no photograph. */

it('degrades a photo style to the soft tint rather than rendering a broken image', function () {
    foreach (['full', 'split'] as $style) {
        $category = Category::create([
            'slug' => 't-bw-noimg-' . $style,
            'name' => 'T BW No Image ' . $style,
            'path' => 't-bw-noimg-' . $style,
            'banner' => bwBanner(['style' => $style, 'image' => '']),
        ]);

        $html = bwCategoryHtml($category);

        expect(bwHasBanner($html))->toBeTrue("the {$style} banner is still switched on, so something must render");

        // An <img> with an empty src is a request for the current page, and it
        // draws as a broken-image icon across the top of the archive. There
        // must not be one.
        expect(preg_match('/<img[^>]*class="[^"]*kbb-banner__img/', $html))->toBe(0)
            ->and(preg_match('/<img[^>]*src=""/', $html))->toBe(0);

        // It falls back to the style that needs no photograph, and says so in
        // the markup so the admin can explain it rather than the page just
        // looking different from what was picked.
        expect(preg_match('/<section[^>]*class="[^"]*kbb-banner--tint/', $html))->toBe(1)
            ->and(preg_match('/<section[^>]*data-kbb-banner-fallback="no-image"/', $html))->toBe(1);
    }
});

it('falls back to the page name when the banner is switched on with no heading', function () {
    $html = bwCategoryHtml(bwCategory(bwBanner(['heading' => '', 'subheading' => ''])));

    expect(preg_match('/<h1[^>]*class="[^"]*kbb-banner__heading[^"]*"[^>]*>\s*T BW Cat\s*</', $html))->toBe(1);
});

/* ============================================== THE BAG, WITHOUT THE BROWSER
   PageBanner is the only reader and writer, so its clamps are pinned here
   rather than inferred from a rendered page. */

it('clamps everything a banner can carry', function () {
    // An unknown style is the default, not a class name echoed into the page.
    expect(PageBanner::resolve(['enabled' => true, 'style' => '"><script>', 'image' => '/a.jpg'])['style'])
        ->toBe(PageBanner::STYLE_DEFAULT);

    // The tint lands in a style attribute as a custom property. It is matched
    // against a hex pattern whole rather than escaped on the way out, so there
    // is no version of the string that escaping could get wrong.
    expect(PageBanner::resolve(['enabled' => true, 'style' => 'tint', 'tint' => 'red;background:url(x)'])['tint'])
        ->toBe(PageBanner::TINT_DEFAULT)
        ->and(PageBanner::resolve(['enabled' => true, 'style' => 'tint', 'tint' => '#ABC'])['tint'])
        ->toBe('#aabbcc');

    // data: in an <img src> is the stored-XSS shape MediaUploadController
    // already refuses for uploaded SVG, because image/svg+xml renders as a
    // document and can carry script.
    expect(PageBanner::resolve(['enabled' => true, 'image' => 'data:image/svg+xml;base64,AAAA'])['image'])
        ->toBeNull()
        ->and(PageBanner::resolve(['enabled' => true, 'image' => '/../../etc/passwd'])['image'])
        ->toBeNull()
        ->and(PageBanner::resolve(['enabled' => true, 'image' => 'https://cdn.example.test/a.jpg'])['image'])
        ->toBe('https://cdn.example.test/a.jpg');

    // The scrim never reaches fully opaque: that would hide the image the
    // operator just uploaded, and every report of it would read as "the banner
    // is broken".
    expect(PageBanner::resolve(['enabled' => true, 'style' => 'tint', 'overlay' => 400])['overlay'])->toBe(90)
        ->and(PageBanner::resolve(['enabled' => true, 'style' => 'tint', 'overlay' => -9])['overlay'])->toBe(0);

    expect(PageBanner::resolve(null))->toBeNull()
        ->and(PageBanner::resolve([]))->toBeNull()
        ->and(PageBanner::sanitize(['enabled' => false, 'heading' => 'x']))->toBeNull();
});

/* ================================================================ THE SCREEN
   The admin affordances the owner said were missing. */

it('ships the brands editor as its own partial and includes it once', function () {
    $path = resource_path('views/admin/partials/brands-editor-screen.blade.php');

    expect(is_file($path))->toBeTrue();

    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.brands-editor-screen')"))->toBe(1);

    /*
     * Order matters and is not cosmetic. This file enhances the Brands tab
     * that category-tree-screen renders, and it wraps window.go the way that
     * file does; included before it, its wrapper would be the inner one and
     * the observer would be installed before the screen it watches exists.
     */
    expect(strpos($app, "@include('admin.partials.category-tree-screen')"))
        ->toBeLessThan(strpos($app, "@include('admin.partials.brands-editor-screen')"));
});

it('gives the brands screen the add, edit, banner and delete affordances the categories tab has', function () {
    $src = (string) file_get_contents(
        resource_path('views/admin/partials/brands-editor-screen.blade.php')
    );

    expect(str_contains($src, '+ Add brand'))->toBeTrue('the screen needs an Add button — its absence is what the owner reported')
        ->and(str_contains($src, 'data-bedit='))->toBeTrue('every brand row needs an Edit button')
        ->and(str_contains($src, 'data-bdel='))->toBeTrue('every brand row needs a Delete button')
        ->and(str_contains($src, 'data-bbanner='))->toBeTrue('every brand row needs a way into the banner editor');

    // The dialog has to cover the real columns of `brands`.
    foreach (['bz-name', 'bz-slug', 'bz-desc', 'bz-pos', 'bz-seotitle', 'bz-seodesc'] as $field) {
        expect(str_contains($src, 'id="' . $field . '"'))->toBeTrue("the brand dialog is missing the {$field} field");
    }

    // And the banner fieldset, which is shared with the category dialog.
    foreach (['bz-bn-on', 'bz-bn-style', 'bz-bn-heading', 'bz-bn-sub', 'bz-bn-tone', 'bz-bn-overlay', 'bz-bn-tint'] as $field) {
        expect(str_contains($src, 'id="' . $field . '"'))->toBeTrue("the banner fieldset is missing the {$field} control");
    }

    /*
     * The two image fields are built by libraryField()/wireLibrary(), so their
     * ids are arguments rather than literal id="…" attributes. Asserted by the
     * wiring call, which is the thing that would actually be missing.
     */
    expect(str_contains($src, "wireLibrary('bz-logo-lib', 'bz-logo', 'bz-logo-thumb'"))->toBeTrue('the logo field must be wired to the Media Library')
        ->and(str_contains($src, "wireLibrary('bz-bn-lib', 'bz-bn-image', 'bz-bn-thumb'"))->toBeTrue('the banner image field must be wired to the Media Library');

    /*
     * And NOT to a raw browser file picker. The owner's standing instruction is
     * that the media library shows on every upload in the backend, and
     * AdminMediaPickerEverywhereTest fails by name if one reappears — this is
     * the same guarantee stated where the screen is being reviewed.
     */
    expect(str_contains($src, 'window.kbbPickMedia'))->toBeTrue('image fields must open the shared Media Library');

    // The delete dialog must tell the truth about what destroy() does: it
    // refuses a brand with products unless force=1, and then nulls brand_id
    // rather than deleting anything.
    expect(str_contains($src, 'force=1'))->toBeTrue('the delete must pass force=1, which is what the operator just confirmed')
        ->and(str_contains($src, 'with <b>no brand</b>'))->toBeTrue('the delete dialog must say the products are left unbranded')
        ->and(str_contains($src, '<b>not</b> deleted'))->toBeTrue('the delete dialog must say the products are not deleted');

    /*
     * The slug warning must be the one that is TRUE for a brand. The category
     * dialog warns that the old address keeps working as a redirect; there is
     * no brand redirect table and a brand slug is a query-parameter value, so
     * copying that sentence across would be a screen that lies.
     */
    expect(str_contains($src, 'will not redirect'))->toBeTrue('the brand slug warning must not promise a redirect that does not exist')
        ->and(str_contains($src, 'filter_brands='))->toBeTrue('the warning must name the real address a brand slug appears in');
});

it('lets every container on the brands screen shrink below its content width', function () {
    $css = (string) file_get_contents(
        resource_path('views/admin/partials/brands-editor-screen.blade.php')
    );

    // The grid itself and — the half that actually bites — its children. A
    // grid item's default min-width is auto, "at least as wide as my content",
    // which is how an over-wide screen shipped on Coupons.
    expect($css)->toMatch('/\.bz-wrap\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-wrap\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-row\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-row\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-main\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-main\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-stats\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-stats\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-head\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.bz-grid2\s*>\s*\*\{[^}]*min-width:0/');
});

/* ============================================================= THE STYLESHEET
   The two promises that cannot be seen in rendered HTML. */

it('reserves the banner’s space and respects a reduced-motion preference', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-banner.css'));

    // Half of "no layout jump". The other half — width/height on the <img> —
    // is asserted against the rendered page above.
    expect($css)->toMatch('/aspect-ratio:var\(--kbb-banner-ratio/');

    // The tint style has no image to give it a height, so it declares its own.
    expect($css)->toMatch('/\.kbb-banner--tint \.kbb-banner__inner\{[^}]*aspect-ratio/');

    // Not "a shorter animation" — none.
    expect($css)->toMatch('/@media \(prefers-reduced-motion:reduce\)\{[^}]*animation:none/');

    // And the layout rule, on the file that draws at 360px.
    expect($css)->toMatch('/\.kbb-banner \*\{min-width:0\}/')
        ->and($css)->toMatch('/\.kbb-banner__split\s*>\s*\*\{min-width:0\}/');
});
