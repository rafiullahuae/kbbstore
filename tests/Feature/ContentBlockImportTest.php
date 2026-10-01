<?php

declare(strict_types=1);

/*
 * =============================================================================
 * THE OLD SHOP'S REY GLOBAL SECTIONS, IMPORTED  (Lane PJ-B)
 * =============================================================================
 *
 * ON THE SHOP: product descriptions opened with the letters
 * `[rey_global_section id="18159"]`, because the post that id names -- a block
 * built in Elementor, "Gentle Yet Effective Ingredients" and three ingredients
 * in a row -- was never carried across. "He has a lot of products with these
 * blocks." content_blocks.csv (Lane PJ-A's exporter) carries them; this entity
 * writes them to Content -> HTML Blocks keyed by `blocks.wc_id`.
 *
 * The fixture is built by Tests\Support\ContentBlocksFixture, in the contract's
 * exact columns, and written to a directory of its own so no other import test
 * that walks tests/Fixtures/woo starts importing blocks.
 */

use App\Models\Block;
use App\Services\Import\DocumentMediaRewrite;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;
use App\Services\Import\MediaAudit;
use App\Services\ImportConsole\ImportWorkspace;
use Illuminate\Http\UploadedFile;
use Tests\Support\ContentBlocksFixture;

function pjbImportDir(): string
{
    return storage_path('framework/testing/pjb-blocks-' . getmypid() . '-' . bin2hex(random_bytes(3)));
}

/** @param list<array<string, string>> $rows */
function pjbImport(array $rows, ?string $dir = null, bool $dryRun = false): ImportReport
{
    $dir ??= pjbImportDir();
    ContentBlocksFixture::write($dir, $rows);

    return (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        only: ['content-blocks'],
        runKey: 'pjb-' . bin2hex(random_bytes(4)),
        dryRun: $dryRun,
        restart: true,
    ));
}

afterEach(function () {
    foreach (glob(storage_path('framework/testing/pjb-blocks-' . getmypid() . '-*')) ?: [] as $dir) {
        @unlink($dir . '/content_blocks.csv');
        @rmdir($dir);
    }
});

it('imports a Global Section as a published HTML Block keyed by its WordPress id', function () {
    /*
     * MUTATION, RUN: ContentBlockImporter removed from ImportRunner::entities()
     * -- red, 0 blocks (and AdminImportScreenTest red on the entity order).
     */
    $report = pjbImport([ContentBlocksFixture::row()]);

    $block = Block::query()->where('wc_id', 18159)->sole();

    expect($report->for('content-blocks')->created)->toBe(1)
        ->and($report->for('content-blocks')->rejectedCount())->toBe(0)
        ->and($block->status)->toBe('published')
        ->and($block->source)->toBe('rey_global_section')
        ->and($block->name)->toBe('Anua Heartleaf — Gentle Yet Effective Ingredients')
        ->and($block->slug)->toBe('gentle-yet-effective-ingredients')
        ->and($block->shortcode())->toBe('[rey_global_section id="18159"]')
        ->and($block->content)->toStartWith('<div class="kbb-eblock"><h3 class="kbb-eblock__heading">Gentle Yet Effective Ingredients</h3>')
        ->and(substr_count((string) $block->content, 'kbb-eblock__item'))->toBe(3);
});

it('never writes a second copy: a re-import of the same export is unchanged, an edited one updates in place', function () {
    /*
     * MUTATION, RUN: `$block = new Block` in place of the wc_id lookup -- red,
     * the second run dies on the unique wc_id ("the database refused this row")
     * instead of reporting unchanged.
     */
    pjbImport([ContentBlocksFixture::row()]);
    $id = Block::query()->where('wc_id', 18159)->value('id');

    $again = pjbImport([ContentBlocksFixture::row()]);

    expect($again->for('content-blocks')->unchanged)->toBe(1)
        ->and($again->for('content-blocks')->created)->toBe(0)
        ->and(Block::query()->count())->toBe(1);

    $changed = pjbImport([ContentBlocksFixture::row(['title' => 'Gentle Ingredients (2025)'])]);

    expect($changed->for('content-blocks')->updated)->toBe(1)
        ->and(Block::query()->count())->toBe(1)
        ->and(Block::query()->where('wc_id', 18159)->value('id'))->toBe($id)
        ->and(Block::query()->find($id)->name)->toBe('Gentle Ingredients (2025)');
});

it('keeps a block the owner edited in Content -> HTML Blocks, and says so', function () {
    /*
     * ON THE SHOP this would be: he fixes a typo in the ingredient strip once,
     * re-runs the import a week later for new orders, and the typo is back on
     * every product. The fingerprint of what the import wrote says he edited it.
     *
     * MUTATION, RUN: the source_hash comparison removed from import() -- red,
     * the owner's words replaced by the export's.
     */
    pjbImport([ContentBlocksFixture::row()]);

    $block = Block::query()->where('wc_id', 18159)->sole();
    $block->update(['content' => '<p>My own words</p>']);

    $report = pjbImport([ContentBlocksFixture::row(['title' => 'Renamed in WordPress'])]);

    expect($block->fresh()->content)->toBe('<p>My own words</p>')
        ->and($block->fresh()->name)->toBe('Anua Heartleaf — Gentle Yet Effective Ingredients')
        ->and($report->for('content-blocks')->unchanged)->toBe(1)
        ->and(json_encode($report->for('content-blocks')->discards()))->toContain('your edit is kept');
});

it('does not mistake the picture pass for an edit', function () {
    /*
     * The picture pass re-points the image-box pictures at this shop's copy.
     * A fingerprint over the raw bytes read that as the owner editing the
     * block, and froze every block whose pictures had moved at its first
     * import for ever.
     *
     * MUTATION, RUN: hash() without the uploads-path folding -- red, the
     * re-import keeps the stale name instead of updating it.
     */
    pjbImport([ContentBlocksFixture::row()]);

    $block = Block::query()->where('wc_id', 18159)->sole();
    $block->forceFill(['content' => str_replace('https://kbeautybliss.com/wp-content/uploads/', '/wp-content/uploads/', (string) $block->content)])->save();

    pjbImport([ContentBlocksFixture::row(['title' => 'Renamed in WordPress'])]);

    expect($block->fresh()->name)->toBe('Renamed in WordPress');
});

it('falls back to plain HTML for a widget it cannot draw, and names the widget with a count in the report', function () {
    /*
     * The task's own words: "the import report names the unknown widget types
     * with counts (so we can add them)".
     *
     * MUTATION, RUN: finalise() without the note -- red on the note; the
     * per-row adjustment alone does not total the types.
     */
    $carousel = json_encode([[
        'elType' => 'section', 'settings' => [], 'elements' => [[
            'elType' => 'column', 'settings' => [], 'elements' => [
                ['elType' => 'widget', 'widgetType' => 'rey-carousel', 'settings' => [], 'elements' => []],
                ['elType' => 'widget', 'widgetType' => 'rey-carousel', 'settings' => [], 'elements' => []],
            ],
        ]],
    ]]);

    $report = pjbImport([
        ContentBlocksFixture::row(),
        ContentBlocksFixture::row(['id' => '20001', 'slug' => 'slider', 'title' => 'Slider', 'elementor_data' => $carousel, 'plain_html' => '<p>Slide words</p>']),
    ]);

    $notes = implode("\n", array_keys($report->for('content-blocks')->notes()));

    expect(Block::query()->where('wc_id', 20001)->value('content'))->toBe('<div class="kbb-eblock kbb-eblock--plain"><p>Slide words</p></div>')
        ->and($notes)->toContain('Elementor widgets this shop cannot draw yet, so 1 block used the section\'s plain text instead: rey-carousel x2')
        ->and(json_encode($report->for('content-blocks')->adjustments()))->toContain('unknown Elementor widget(s): rey-carousel x2');
});

it('imports a draft section as a draft, which the storefront draws as nothing', function () {
    pjbImport([ContentBlocksFixture::row(['status' => 'draft'])]);

    expect(Block::query()->where('wc_id', 18159)->value('status'))->toBe('draft');
});

it('never takes a handle a block the owner wrote already holds', function () {
    Block::query()->create(['slug' => 'gentle-yet-effective-ingredients', 'name' => 'Mine', 'content' => '<p>x</p>', 'status' => 'published']);

    pjbImport([ContentBlocksFixture::row()]);

    expect(Block::query()->where('wc_id', 18159)->value('slug'))->toBe('gentle-yet-effective-ingredients-18159')
        ->and(Block::query()->where('slug', 'gentle-yet-effective-ingredients')->value('content'))->toBe('<p>x</p>');
});

it('writes nothing on a preview', function () {
    $report = pjbImport([ContentBlocksFixture::row()], dryRun: true);

    expect($report->for('content-blocks')->created)->toBe(1)
        ->and(Block::query()->count())->toBe(0);
});

it('refuses a row with no WordPress id, by name', function () {
    $report = pjbImport([ContentBlocksFixture::row(['id' => ''])]);

    expect($report->for('content-blocks')->rejectedCount())->toBe(1)
        ->and(Block::query()->count())->toBe(0);
});

it('puts the block\'s pictures in front of the picture pass with everything else', function () {
    /*
     * The image-box pictures are full URLs on the old site. Without
     * blocks.content in DocumentMediaRewrite::DOCUMENTS and in MediaAudit, the
     * audit's "remote" count reached zero with every ingredient picture still
     * served by the site the owner is about to switch off.
     *
     * MUTATION, RUN: the Block entry removed from DOCUMENTS -- red, no
     * proposals for blocks.content; removed from MediaAudit -- red, the
     * audit's sample names no block.
     */
    pjbImport([ContentBlocksFixture::row()]);

    $rows = array_values(array_filter(
        (new DocumentMediaRewrite)->propose(['kbeautybliss.com']),
        static fn (array $p): bool => $p['owner_type'] === Block::class,
    ));

    $audited = array_values(array_filter(
        (new MediaAudit)->audit(),
        static fn (array $r): bool => $r['field'] === 'blocks.content',
    ));

    expect(count($rows))->toBe(3)
        ->and(array_column($rows, 'path'))->toContain('wp-content/uploads/2024/03/anua-bha-300x300.jpg')
        ->and(count($audited))->toBe(3)
        ->and($audited[0]['verdict'])->toBe(MediaAudit::REMOTE);
});

it('is accepted on the import screen as content_blocks.csv, and listed as Content blocks', function () {
    $dir = pjbImportDir();
    $path = ContentBlocksFixture::write($dir, [ContentBlocksFixture::row()]);

    $workspace = new ImportWorkspace;
    $result = $workspace->acceptUpload(new UploadedFile($path, 'content_blocks.csv', 'text/csv', null, true));

    expect($result['refused'])->toBe([])
        ->and($result['accepted'][0]['entity'])->toBe('content-blocks')
        ->and(ImportWorkspace::meta('content-blocks')['label'])->toBe('Content blocks')
        ->and($workspace->has('content-blocks'))->toBeTrue();

    $workspace->forget('content-blocks');
});
