<?php

declare(strict_types=1);

/**
 * The Categories and Attributes screens inside resources/views/admin/app.blade.php.
 *
 * These two tabs were the worst defect shape this codebase has: screens that
 * looked like they worked. catCategories drew eleven invented rows out of
 * CAT_CATEGORIES with slugs made up in JavaScript; catAttributes drew four
 * invented attributes — "Skin Type", "Concern", "Finish" — that are not in this
 * database at all; every button raised a "(preview)" toast. An owner could
 * click around both for a while before noticing nothing was real.
 *
 * So the assertions here are mostly absence assertions: the preview rows are
 * gone, the preview toasts are gone, and what replaced them talks to the
 * guarded admin-api. That file is ~600KB of single-file admin with several
 * lanes editing it at once, and nothing else in the suite would notice a screen
 * silently reverting to a mock.
 */
$blade = fn () => (string) file_get_contents(resource_path('views/admin/app.blade.php'));

function laneNRegion(string $source): string
{
    $start = strpos($source, 'LANE N · Catalog · Categories & Attributes — BEGIN');
    $end = strpos($source, 'LANE N · Catalog · Categories & Attributes — END');

    expect($start)->not->toBeFalse('the Lane N region is missing from the admin view');
    expect($end)->not->toBeFalse('the Lane N region is not closed');

    return substr($source, $start, $end - $start);
}

it('no longer renders either tab from a hard-coded preview array', function () use ($blade) {
    $source = $blade();

    // The exact rows the old screens invented. Both arrays still exist and are
    // deliberately left alone — the Shop Filters screen reads CAT_CATEGORIES
    // and the product editor's Variations panel reads CAT_ATTRS, and those are
    // other lanes' previews to fix — but neither Catalog TAB may draw from
    // them any more. Matched on the row markup, which only the tab had.
    expect($source)->not->toContain('${CAT_CATEGORIES.map(c=>`<tr>')
        ->and($source)->not->toContain('${CAT_ATTRS.map(a=>`<div class="card pad"')
        // And the buttons that made the previews look alive.
        ->and($source)->not->toContain("toast('Add category (preview)')")
        ->and($source)->not->toContain("toast('Edit category (preview)')")
        ->and($source)->not->toContain("toast('Edit terms (preview)')");
});

it('replaces both tab renderers with real ones that call the guarded admin-api', function () use ($blade) {
    $region = laneNRegion($blade());

    // The dispatch table in renderCatalog names catCategories and
    // catAttributes; these window assignments are what those names resolve to
    // once the live-wiring script has run, exactly as window.catBrands replaces
    // the brands preview.
    expect($region)->toContain('window.catCategories = async function()')
        ->and($region)->toContain('window.catAttributes = async function()')
        ->and($region)->toContain("catalogWrite('/categories','GET',null)")
        ->and($region)->toContain("catalogWrite('/attributes','GET',null)")
        ->and($region)->toContain("fixAdminApiUrl('/admin-api'+path)");
});

it('leaves an honest fallback where the previews were, not a convincing one', function () use ($blade) {
    $source = $blade();

    // renderCatalog dispatches through a plain object lookup, so the two names
    // must still exist as declarations or the tab throws. What is left of them
    // says it failed rather than drawing rows.
    expect($source)->toContain('function catCategories(){ $(\'#catBody\').innerHTML=')
        ->and($source)->toContain('function catAttributes(){ $(\'#catBody\').innerHTML=')
        ->and($source)->toContain('Categories could not be loaded')
        ->and($source)->toContain('Attributes could not be loaded');
});

it('escapes every operator-supplied string it writes into the page', function () use ($blade) {
    $region = laneNRegion($blade());

    // Category and attribute names are typed by an operator and land in
    // innerHTML. This region is inside @verbatim so Blade's {{ }} does not
    // apply to it; sesc() is this file's equivalent, and toast() assigns into
    // innerHTML too, which is why every message goes through catToast.
    expect($region)->toContain('function catToast(msg){ toast(sesc(msg)); }')
        ->and($region)->toContain('sesc(c.name)')
        ->and($region)->toContain('sesc(a.name)')
        ->and($region)->toContain('sesc(v.name)')
        // A bare toast(e.message) would put a server message carrying a
        // category name straight into innerHTML unescaped.
        ->and($region)->not->toContain('toast(e.message)');
});

it('confirms before a delete that would detach anything, and says nothing is destroyed', function () use ($blade) {
    $region = laneNRegion($blade());

    // force=1 is only ever sent from inside the confirm modal, after the counts
    // have been shown — the same arrangement the brands screen uses.
    expect($region)->toContain("'?force=1','DELETE',null")
        ->and($region)->toContain('No product is deleted')
        ->and($region)->toContain('No product and no variant is deleted');
});

it('renders the whole admin document with both screens in it', function () {
    // The screens live in one 600KB Blade file; a stray brace or an unclosed
    // string in this region takes the entire admin console down, not just these
    // two tabs.
    $html = view('admin.app')->render();

    expect($html)->toContain('window.catCategories = async function()')
        ->and($html)->toContain('window.catAttributes = async function()')
        ->and($html)->toContain("'/admin-api'+path");
});
