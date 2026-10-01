<?php

declare(strict_types=1);

/*
 * =============================================================================
 * "REMOVE ALL FILES" ON STORE -> STORE IMPORT / EXPORT  (Lane PJ-B)
 * =============================================================================
 *
 * ON THE SHOP: section "1 · Your exports" has one small Remove button per file
 * card, and to clear the card the owner pressed nine of them, one at a time,
 * waiting for the card to repaint after each.
 *
 * One button at the top of that card now does all of it, behind a confirm().
 * It reuses POST /admin-api/import/forget (entity=all) -- the same route, the
 * same capability, the same workspace methods each Remove button calls -- and
 * removes uploaded FILES only, never a row that was imported from them.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\ImportAdminRoutes;

beforeEach(function () {
    ImportAdminRoutes::wire($this->app);
    pjbPurge(storage_path('app/import'));
});

afterEach(function () {
    pjbPurge(storage_path('app/import'));
});

function pjbPurge(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $path = $dir . '/' . $entry;
            is_dir($path) ? pjbPurge($path) : @unlink($path);
        }
    }

    @rmdir($dir);
}

function pjbOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Import Owner',
        'email' => 'pjb-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function pjbUpload(string $name): void
{
    // permalinks.csv and media.csv are the exporter's companions and live
    // beside its own output; every entity file is in the woo fixture.
    $source = base_path('tests/Fixtures/woo/' . $name);
    $source = is_file($source) ? $source : base_path('tests/Fixtures/kbb-export/' . $name);
    $temp = sys_get_temp_dir() . '/kbb-pjb-' . bin2hex(random_bytes(6)) . '-' . $name;
    copy($source, $temp);

    test()->postJson('/admin-api/import/upload', ['file' => new UploadedFile($temp, $name, 'text/csv', null, true)])
        ->assertOk();
}

it('removes every uploaded file in one press, and nothing that was imported', function () {
    /*
     * MUTATION, RUN: the `entity === 'all'` branch removed from
     * ImportApiController::forget() -- red, 422 "Unknown entity." and every
     * file still on the card.
     */
    $this->actingAs(pjbOwner(), 'admin');

    foreach (['brands.csv', 'categories.csv', 'products.csv', 'orders.csv', 'content_blocks.csv', 'permalinks.csv', 'media.csv'] as $name) {
        pjbUpload($name);
    }

    file_put_contents((new ImportWorkspace)->manifestPath(), json_encode(['format' => 'kbb-export/1', 'files' => []]));

    // An imported row the files came from, which must survive.
    $brand = Brand::query()->create(['name' => 'Anua', 'slug' => 'anua-pjb', 'source_term_id' => 9001]);

    $before = $this->getJson('/admin-api/import/status')->assertOk()->json();
    expect(collect($before['files'])->where('present', true)->count())->toBeGreaterThanOrEqual(6);

    $res = $this->postJson('/admin-api/import/forget', ['entity' => 'all'])->assertOk()->json();

    $workspace = new ImportWorkspace;

    expect($res['ok'])->toBeTrue()
        ->and($res['removed'])->toBe(8)
        ->and(collect($res['status']['files'])->where('present', true)->count())->toBe(0)
        ->and(collect($res['status']['companions'])->where('present', true)->count())->toBe(0)
        ->and($workspace->hasManifest())->toBeFalse()
        ->and(glob($workspace->directory() . '/*') ?: [])->toBe([])
        // Files only: the data imported from them is untouched.
        ->and(Brand::query()->whereKey($brand->id)->exists())->toBeTrue();
});

it('refuses while an import is part-way through, and removes nothing', function () {
    /*
     * One Remove mid-run takes one file a step may still want; all of them
     * takes every file the run was started over.
     *
     * MUTATION, RUN: the running-run check removed -- red, 200 and the file gone.
     */
    $this->actingAs(pjbOwner(), 'admin');
    pjbUpload('brands.csv');

    DB::table(ImportDriver::TABLE)->insert([
        'run_key' => ImportDriver::RUN_KEY,
        'status' => 'running',
        'mode' => 'live',
    ]);

    $this->postJson('/admin-api/import/forget', ['entity' => 'all'])
        ->assertStatus(409)
        ->assertJsonPath('ok', false);

    expect((new ImportWorkspace)->has('brands'))->toBeTrue();
});

it('is refused to a visitor who is not signed in to the admin, exactly as Remove is', function () {
    $temp = sys_get_temp_dir() . '/kbb-pjb-' . bin2hex(random_bytes(6)) . '-brands.csv';
    copy(base_path('tests/Fixtures/woo/brands.csv'), $temp);
    (new ImportWorkspace)->accept(new UploadedFile($temp, 'brands.csv', 'text/csv', null, true));

    $this->postJson('/admin-api/import/forget', ['entity' => 'all'])->assertStatus(401);

    expect((new ImportWorkspace)->has('brands'))->toBeTrue();
});

it('puts one "Remove all files" button at the top of the exports card, behind a confirm()', function () {
    /*
     * The button is drawn by resources/views/admin/app.blade.php's
     * impFilesCard(); pinned on the source because the console is one page of
     * JavaScript and the shot in docs/ is the picture of it.
     *
     * MUTATION, RUN: the confirm() line removed from the handler -- red.
     */
    $src = file_get_contents(resource_path('views/admin/app.blade.php'));

    $card = substr($src, strpos($src, 'function impFilesCard(s)'), 4000);
    $wire = substr($src, strpos($src, "const forgetAll=\$('#impForgetAll');"), 900);

    expect(substr_count($src, 'id="impForgetAll"'))->toBe(1)
        ->and($card)->toContain('Remove all files')
        ->and($card)->toContain("s.run.status==='running'?' disabled")
        ->and($wire)->toContain('if(!confirm(')
        ->and($wire)->toContain("JSON.stringify({entity:'all'})")
        ->and($wire)->toContain("'/import/forget'");
});
