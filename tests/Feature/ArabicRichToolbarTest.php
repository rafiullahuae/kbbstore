<?php

/*
 * The Arabic rich-text box gets the English pane's toolbar -- Lane ART,
 * 19 October 2026.
 *
 * WHAT THE OWNER SAW. Content → Blog Posts → Edit → The article. Under the
 * English editor (B, I, U, H2, H3, P, List, 1., Link, Media and a word count)
 * the box "العربية Arabic · The article" was a bare contenteditable with NO
 * toolbar; he drew red lines where the tools should be: "also i think you
 * didn't make the editors tools etc for arabic version. make it urgently
 * please." The same bare box sat under Content → Pages → Edit → The page and
 * Catalog → Product tabs → (a tab) → What the tab says. (The product editor's
 * Arabic description already drew its own full bar, and is left alone.)
 *
 * THE FIX. KBBArabic.box() takes the screen's own bar markup (`bar`) and a
 * foot (`foot`); each screen builds its bar with ONE function, used for both
 * panes, and binds both with the same handler, which now acts only while the
 * selection is inside its own pane.
 *
 * MUTATION (each run in Chromium): drop `bar: rteBar(), foot: ...` from the
 * post editor's ar({...'rich'}) call -- the shape that shipped -- and the
 * report fails at once: there is no `.kbbar-bar` in the Arabic box. Put back
 * the product-tabs handler's old lookup, t.closest('.kpt-rte'), and the
 * Arabic tab's B does nothing ("arAfterBold" has no <b>). The
 * `area.contains(selection)` guard in bindRte() is belt and braces: Chromium
 * already moves the selection on focus(), so the cross-pane case below pins
 * the behaviour rather than that line.
 */

function artChrome(): ?string
{
    $chrome = (string) env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    $pw = (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules');

    return is_file($chrome) && $node !== '' && is_dir($pw . '/playwright') ? $chrome : null;
}

function artRun(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }

    $chrome = artChrome();
    if ($chrome === null) {
        test()->markTestSkipped('node, playwright or Chromium is not installed here; tests/browser/art-arabic-toolbar.mjs needs them.');
    }

    $html = '';
    foreach (['arabic-boxes', 'rte-media', 'post-editor-screen', 'page-editor-screen', 'product-tabs-screen'] as $partial) {
        $html .= view('admin.partials.' . $partial)->render();
    }

    $file = storage_path('framework/testing/art-toolbar-' . getmypid() . '.html');
    @mkdir(dirname($file), 0o777, true);
    file_put_contents($file, $html);

    $env = 'KBB_ART_PAGE=' . escapeshellarg($file)
        . ' KBB_BROWSER_CHROME=' . escapeshellarg($chrome)
        . ' NODE_PATH=' . escapeshellarg((string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'));
    $raw = (string) shell_exec($env . ' node ' . escapeshellarg(base_path('tests/browser/art-arabic-toolbar.mjs')) . ' 2>&1');
    @unlink($file);

    $r = json_decode($raw, true);
    expect($r)->toBeArray('no report: ' . substr($raw, 0, 600))
        ->and($r['ok'] ?? false)->toBeTrue($r['error'] ?? '');

    return $memo = $r;
}

const ART_POST_BAR = ['B', 'I', 'U', 'H2', 'H3', 'P', 'List', '1.', 'Link', '🖼 Media'];

it('builds each Arabic rich box with the English pane\'s own bar builder', function () {
    $dir = resource_path('views/admin/partials/');
    $boxes = (string) file_get_contents($dir . 'arabic-boxes.blade.php');
    $post = (string) file_get_contents($dir . 'post-editor-screen.blade.php');
    $page = (string) file_get_contents($dir . 'page-editor-screen.blade.php');
    $tabs = (string) file_get_contents($dir . 'product-tabs-screen.blade.php');

    // The shared box draws a screen's bar inside its own pane wrapper, in RTL.
    expect($boxes)->toContain("(opts.bar ? '<div class=\"kbbar-bar\" dir=\"rtl\">' + opts.bar + '</div>' : '')");

    // One builder per screen, used for the English pane AND passed to the Arabic.
    foreach (['post' => $post, 'page' => $page, 'tabs' => $tabs] as $name => $src) {
        expect(substr_count($src, 'rteBar()'))->toBeGreaterThanOrEqual(2, "{$name}: rteBar() is used once");
        expect($src)->toMatch('/bar:\s*(type === \'rich\' \? )?rteBar\(\)/', "{$name}: the Arabic box gets no bar");
    }
    // Both the English label set and the order are written once.
    expect(substr_count($post, 'data-cmd="bold"'))->toBe(1)
        ->and(substr_count($page, 'data-cmd="bold"'))->toBe(1)
        ->and(substr_count($tabs, 'data-kpt-cmd="bold"'))->toBe(1);
});

it('gives the Arabic article the same toolbar as the English one, in the box\'s direction -- in Chromium', function () {
    $post = artRun()['post'];

    expect($post['enLabels'])->toBe(ART_POST_BAR)
        ->and($post['arLabels'])->toBe($post['enLabels'])
        ->and($post['arDir'])->toBe('rtl')
        ->and($post['arFirstIsRightmost'])->toBeTrue()
        ->and($post['arWordsInitial'])->toBe('4 words')
        ->and($post['errors'])->toBe([]);
});

it('bolds, links, heads and inserts pictures in the Arabic box only, and saves them -- in Chromium', function () {
    $post = artRun()['post'];

    expect($post['arAfterBold'])->toContain('<b>أولى</b>')
        ->and($post['arAfterLink'])->toContain('<a href="https://example.com/ar-link">ثانية</a>')
        ->and($post['arAfterH2'])->toContain('<h2>فقرة <a href="https://example.com/ar-link">ثانية</a></h2>')
        // The picture lands at the caret: after the first Arabic paragraph.
        ->and($post['arMedia']['cls'])->toBe('kbb-gal kbb-gal-1')
        ->and($post['arMedia']['src'])->toBe('/pic-ar.png')
        ->and($post['arMedia']['before'])->toBe('فقرة أولى عربية')
        ->and($post['arMedia']['after'])->toContain('ثانية')
        ->and($post['arWordsAfter'])->toBe('4 words');

    // A press on the Arabic bar never edits the English -- not even with the
    // selection left in the English pane.
    expect($post['enUntouched'])->toBeTrue()
        ->and($post['enGalleries'])->toBe(0)
        ->and($post['enAfterCrossBold'])->not->toContain('<b>');

    // And the reverse.
    expect($post['enAfterEnBold'])->toContain('<b>first</b>')
        ->and($post['arUntouchedByEn'])->toBeTrue();

    // The same save as before: English in `body`, Arabic in translations.
    expect($post['savedBody'])->toContain('<b>first</b>')->not->toContain('أولى')
        ->and($post['savedArBody'])->toContain('<b>أولى</b>')
        ->toContain('href="https://example.com/ar-link"')
        ->toContain('kbb-gal')
        ->not->toContain('first');
});

it('gives the Arabic page and the Arabic product tab the same bar, acting on their own box -- in Chromium', function () {
    $r = artRun();

    expect($r['page']['arLabels'])->toBe($r['page']['enLabels'])
        ->and($r['page']['enLabels'])->toBe(ART_POST_BAR)
        ->and($r['page']['arAfterBold'])->toContain('<b>أولى</b>')
        ->and($r['page']['enUntouched'])->toBeTrue()
        ->and($r['page']['arWords'])->toBe('4 words')
        ->and($r['page']['errors'])->toBe([]);

    expect($r['tabs']['arLabels'])->toBe($r['tabs']['enLabels'])
        ->and($r['tabs']['enLabels'])->toBe(['B', 'I', 'U', 'H3', 'P', 'List', '1.', 'Link', '🖼 Media'])
        ->and($r['tabs']['arAfterBold'])->toContain('<b>أولى</b>')
        ->and($r['tabs']['enAfter'])->toBe('<p>English tab text</p>')
        ->and($r['tabs']['enAfterEnBold'])->toContain('<b>tab</b>')
        ->and($r['tabs']['arAfterEnBold'])->toBe($r['tabs']['arAfterBold'])
        ->and($r['tabs']['errors'])->toBe([]);
});
