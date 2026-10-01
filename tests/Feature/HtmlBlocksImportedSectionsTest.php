<?php

declare(strict_types=1);

/*
 * =============================================================================
 * IMPORTED GLOBAL SECTIONS ON CONTENT -> HTML BLOCKS  (Lane PJ-B)
 * =============================================================================
 *
 * The owner edits a section ONCE and every product using it changes -- which
 * only works if he can find it. An imported block is listed under its name,
 * with the `[rey_global_section id="N"]` his product copy already carries (a
 * [kbb_block] handle would name text none of his products contain), with the
 * products that place it counted in "Used in", and Delete warns while products
 * still name it.
 */

use App\Models\AdminUser;
use App\Models\Block;
use App\Models\Product;
use Illuminate\Support\Str;
use Tests\Support\HtmlBlocksAdminRoutes;

beforeEach(function () {
    HtmlBlocksAdminRoutes::wire(app());

    $this->actingAs(AdminUser::create([
        'name' => 'Owner',
        'email' => 'pjb-blocks-' . uniqid() . '@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
});

function pjbImportedSection(array $overrides = []): Block
{
    return Block::query()->forceCreate(array_merge([
        'slug' => 'gentle-yet-effective-ingredients',
        'name' => 'Gentle Yet Effective Ingredients',
        'content' => '<div class="kbb-eblock"><h3 class="kbb-eblock__heading">Gentle Yet Effective Ingredients</h3></div>',
        'status' => 'published',
        'wc_id' => 18159,
        'source' => 'rey_global_section',
    ], $overrides));
}

function pjbNaming(string $copy, string $field = 'description'): Product
{
    return Product::create([
        'name' => 'Anua ' . Str::random(4),
        'slug' => 'pjb-' . Str::lower(Str::random(8)),
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 8000,
        'stock_status' => 'instock',
        $field => $copy,
    ]);
}

it('lists an imported section by name, with the shortcode its products carry and how many use it', function () {
    /*
     * MUTATION, RUN: index() selecting without wc_id/source -- red, the row's
     * shortcode reads [kbb_block slug="..."]; usageCounts() without the
     * product scan -- red, used_in 0.
     */
    pjbImportedSection();
    Block::query()->create(['slug' => 'free-shipping', 'name' => 'Free shipping', 'content' => '<p>x</p>', 'status' => 'published']);

    pjbNaming('[rey_global_section id="18159"] Foam copy.');
    pjbNaming("Serum copy.\n[rey_global_section id='18159']");
    pjbNaming('[rey_global_section id=18159]', 'short_description');
    pjbNaming('[rey_global_section id="99999"] Names another section.');

    $rows = collect($this->getJson('/admin-api/blocks')->assertOk()->json('blocks'))->keyBy('slug');

    expect($rows['gentle-yet-effective-ingredients']['name'])->toBe('Gentle Yet Effective Ingredients')
        ->and($rows['gentle-yet-effective-ingredients']['shortcode'])->toBe('[rey_global_section id="18159"]')
        ->and($rows['gentle-yet-effective-ingredients']['imported'])->toBeTrue()
        ->and($rows['gentle-yet-effective-ingredients']['used_in'])->toBe(3)
        // A block the owner wrote is untouched by any of this.
        ->and($rows['free-shipping']['shortcode'])->toBe('[kbb_block slug="free-shipping"]')
        ->and($rows['free-shipping']['imported'])->toBeFalse()
        ->and($rows['free-shipping']['used_in'])->toBe(0);
});

it('names the products in the editor, and refuses Delete while they still place it', function () {
    $block = pjbImportedSection();
    $p = pjbNaming('[rey_global_section id="18159"]');

    $show = $this->getJson('/admin-api/blocks/' . $block->id)->assertOk()->json();

    expect($show['block']['shortcode'])->toBe('[rey_global_section id="18159"]')
        ->and($show['used_in'][0])->toMatchArray(['type' => 'product', 'id' => $p->id, 'title' => $p->name]);

    $this->deleteJson('/admin-api/blocks/' . $block->id)->assertStatus(422)->assertJsonPath('error', 'block_in_use');

    expect(Block::query()->whereKey($block->id)->exists())->toBeTrue();
});

it('saves an edit the storefront then draws on every product naming the section', function () {
    $block = pjbImportedSection();
    $a = pjbNaming("[rey_global_section id=\"18159\"]\nFirst product.");
    $b = pjbNaming("[rey_global_section id=\"18159\"]\nSecond product.");

    $this->putJson('/admin-api/blocks/' . $block->id, [
        'name' => $block->name,
        'slug' => $block->slug,
        'status' => 'published',
        'content' => '<div class="kbb-eblock"><h3 class="kbb-eblock__heading">Edited once, everywhere</h3></div>',
    ])->assertOk();

    foreach ([$a, $b] as $p) {
        expect($this->get('/product/' . $p->slug . '/')->assertOk()->getContent())->toContain('Edited once, everywhere');
    }
});

it('shows the imported shortcode in the editor and keeps it when the handle changes', function () {
    /*
     * Pinned on the screen's source: syncShortcode() used to rewrite the box
     * from the handle on every keystroke, which for an imported section
     * replaced the text his products carry with one they do not.
     */
    $src = file_get_contents(resource_path('views/admin/partials/html-blocks-screen.blade.php'));

    expect($src)->toContain("esc(b.imported && b.shortcode ? b.shortcode : shortcodeFor(slug))")
        ->and($src)->toContain("if (sc && !(editing && editing.imported)) sc.textContent")
        ->and($src)->toContain('From WordPress');
});
