<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Services\HomepageLayouts;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * A built grid instance is a FIRST-CLASS ROW on Appearance → Homepage. (Lane GS)
 *
 * The design decision this whole lane rests on is that an instance is a row in
 * the EXISTING section registry rather than a mechanism beside it — so it
 * inherits the ordering, the Desktop/Mobile switches and the dividers the
 * seventeen shipped sections already have. That is only true if the three
 * readers of `HomepageSections::REGISTRY` outside that class read the registry
 * and not the const, and each of the two that matter fails SILENTLY and
 * differently:
 *
 *   HomepageApiController::payloadFor()  422s the whole save with "Unknown
 *                                        section", so the screen shows the row,
 *                                        lets the owner reorder it, and stores
 *                                        none of it.
 *   HomepageLayouts::payloadFor()        omits the instance from the payload a
 *                                        preset writes, so save() DROPS its
 *                                        stored row and all() re-merges it with
 *                                        its switches back on and its order
 *                                        gone — a section that moved and
 *                                        switched itself on because the owner
 *                                        pressed an unrelated button.
 *
 * Neither is visible in a diff and neither throws. Both are pinned here.
 */
function gshoReset(): void
{
    GridSection::query()->delete();
    GridSections::flush();
    Cache::forget('kbb.home.rails');
    SettingsService::forgetMemo();
}

function gshoSection(string $name): GridSection
{
    $n = GridSection::query()->count() + 1;

    return GridSection::create([
        'name' => $name, 'slug' => 'gsho-'.$n.'-'.uniqid(), 'status' => 'publish', 'position' => $n,
        'show_heading' => true, 'heading' => $name, 'subheading' => '',
        'source' => 'bestsellers', 'include_children' => false,
        'count' => 4, 'mobile_count' => 4,
        'desktop_layout' => 'grid', 'desktop_cols' => 4,
        'mobile_layout' => 'carousel', 'mobile_cols' => 2,
        'skin' => '', 'card_label' => '', 'show_rank' => false,
        'show_view_all' => false, 'view_all_label' => '', 'view_all_url' => '',
    ]);
}

function gshoAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'GSHO', 'email' => 'gsho-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

beforeEach(function () {
    gshoReset();

    Product::firstOrCreate(['slug' => 'gsho-p1'], [
        'name' => 'GSHO One', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
        'rating' => 0.0, 'review_count' => 0, 'total_sales' => 500000,
    ]);
});

it('saves a reordered payload that contains a built grid instance', function () {
    /*
     * MUTATION: put `HomepageSections::REGISTRY` back on line 87 of
     * HomepageApiController and this goes red with
     * `{"ok":false,"error":"Unknown section: grid_1."}` and a 422. Run, red,
     * put back.
     *
     * This drives the CONTROLLER rather than the service, because the token
     * that was wrong is in the controller and a service-level test would have
     * stayed green through the whole defect.
     */
    $section = gshoSection('Autumn picks');
    GridSections::flush();

    $rows = [];

    foreach (array_keys(HomepageSections::registry()) as $key) {
        $rows[] = ['key' => $key, 'desktop' => true, 'mobile' => true, 'skin' => null];
    }

    // Move the instance to the very front, which is the thing the owner would
    // actually do with it — "under the above sections" is a position.
    array_unshift($rows, array_pop($rows));

    test()->actingAs(gshoAdmin(), 'admin')
        ->postJson('/admin-api/homepage', ['sections' => $rows])
        ->assertOk();

    GridSections::flush();
    SettingsService::forgetMemo();

    $all = app(HomepageSections::class)->all();

    expect($all)->toHaveKey($section->sectionKey())
        ->and(array_key_first($all))->toBe($section->sectionKey())
        ->and($all[$section->sectionKey()]['order'])->toBe(0);
});

it('switches a built grid instance off from the homepage screen and keeps it off', function () {
    $section = gshoSection('Switchable');
    GridSections::flush();

    $rows = [];

    foreach (array_keys(HomepageSections::registry()) as $key) {
        $rows[] = [
            'key' => $key,
            'desktop' => $key !== $section->sectionKey(),
            'mobile' => $key !== $section->sectionKey(),
            'skin' => null,
        ];
    }

    test()->actingAs(gshoAdmin(), 'admin')
        ->postJson('/admin-api/homepage', ['sections' => $rows])
        ->assertOk();

    GridSections::flush();
    SettingsService::forgetMemo();

    expect(app(HomepageSections::class)->hidden($section->sectionKey()))->toBeTrue();

    // And it really is off the page, wrapper and all.
    GridSections::flush();
    SettingsService::forgetMemo();

    expect(test()->get('/')->getContent())->not->toContain('Switchable');
});

it('keeps a built grid instance when a layout preset is applied', function () {
    /*
     * ── THE ONE THAT FAILS QUIETEST ─────────────────────────────────────────
     *
     * A preset writes a whole payload and save() stores only the keys it
     * carries. Read against the const, `payloadFor()` mentions the instance in
     * NEITHER of its two loops, so applying any preset silently deletes the
     * instance's stored row: it comes back from the registry with its switches
     * ON and its order gone. Nothing errors and nothing is logged; the owner
     * presses "Editorial" and a section he had switched off is back on his
     * front page.
     *
     * MUTATION: put `HomepageSections::REGISTRY` back in both loops of
     * HomepageLayouts::payloadFor() and this goes red — the payload has no
     * grid_* key at all. Run, red, put back.
     */
    $section = gshoSection('Survives a preset');
    GridSections::flush();

    $payload = app(HomepageLayouts::class)->payloadFor('editorial');

    expect($payload)->toHaveKey($section->sectionKey());

    app(HomepageLayouts::class)->apply('editorial', app(HomepageSections::class));

    GridSections::flush();
    SettingsService::forgetMemo();

    $saved = app(SettingsService::class)->get('homepage_sections');

    expect($saved)->toBeArray()
        ->and($saved)->toHaveKey($section->sectionKey())
        // A section a preset does not name is kept, defaulted and placed last —
        // which is what this loop already promises for `cards_banner` and the
        // rest, and is now true of an instance as well.
        ->and($saved[$section->sectionKey()]['desktop'])->toBeTrue();
});

it('gives an instance no card-template picker on the homepage screen', function () {
    /*
     * The one value that deliberately does NOT travel through the section
     * registry. The registry row says `has_grid = false` even though an
     * instance is nothing but a grid, because the skin is edited on the
     * instance's own screen and a value with two homes is a value that will
     * disagree with itself. castRow() then stores `skin => null` for the key,
     * so a picker here would be a box whose value is discarded on the way in —
     * the shape AdminConsoleWriteTokenTest was written after.
     *
     * MUTATION: change the third element of the row GridSections::registryRows()
     * builds from `false` to `true` and this goes red: sectionTabs() starts
     * emitting a `skin` field for a section whose cast throws it away.
     */
    $section = gshoSection('No picker please');
    GridSections::flush();

    $row = app(HomepageSections::class)->all()[$section->sectionKey()];

    expect($row['has_grid'])->toBeFalse()
        ->and($row['skin'])->toBeNull();

    $keys = [];

    foreach (HomepageSections::sectionTabs($section->sectionKey(), $row) as $tab) {
        foreach ($tab['fields'] as $field) {
            $keys[] = $field['key'];
        }
    }

    expect($keys)->toBe(['desktop', 'mobile']);
});
