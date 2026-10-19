<?php

/*
 * The Gallery bar's Columns after a picture is clicked -- 19 October 2026.
 *
 * WHAT THE OWNER SAW (Content → Blog posts → a post → The article): "after
 * inserting image, when click on image, then the column options is not giving
 * to change, it's freezed there." Clicking a picture opens its link / alt-text
 * dialog AND the Gallery bar; the dialog's full-screen backdrop (z-index
 * 100000) covered the bar (99999), so Columns 1-4 could not be clicked, and
 * closing the dialog with Cancel or Save bubbled to the document click
 * handler, which hid the bar. Worse, a Columns click itself hid the bar:
 * paint() rewrites the bar's buttons, so the document handler no longer found
 * the clicked button inside the bar. A one-picture gallery is all picture, so there
 * was nowhere left to click that opened the bar without the dialog.
 *
 * MUTATION: set .rgm-bar back to z-index:99999 -> topAtButton2 is the backdrop
 * and classWhileDialog stays kbb-gal-1, red; drop the "click inside the dialog"
 * early return -> barAfterCancel is false, red; go back to bar.contains(e.target)
 * alone -> barAfterColumn is false, red.
 */

function gbcRun(): array
{
    $chrome = (string) env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    $pw = (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules');
    if (! is_file($chrome) || $node === '' || ! is_dir($pw . '/playwright')) {
        test()->markTestSkipped('node, playwright or Chromium is not installed here.');
    }

    $file = storage_path('framework/testing/gbc-rte-media-' . getmypid() . '.html');
    @mkdir(dirname($file), 0o777, true);
    file_put_contents($file, view('admin.partials.rte-media')->render());

    $env = 'KBB_GBC_PARTIAL=' . escapeshellarg($file)
        . ' KBB_BROWSER_CHROME=' . escapeshellarg($chrome)
        . ' NODE_PATH=' . escapeshellarg($pw);
    $raw = (string) shell_exec($env . ' node ' . escapeshellarg(base_path('tests/browser/gallery-bar-columns.mjs')) . ' 2>&1');
    @unlink($file);

    $out = json_decode($raw, true);
    expect($out)->toBeArray("the browser script failed:\n" . $raw);

    return $out;
}

it('lets Columns be changed after a picture is clicked, with its dialog open and after closing it', function () {
    $r = gbcRun();

    expect($r['dialogOpen'])->toBeTrue()
        ->and($r['barVisible'])->toBeTrue()
        ->and($r['topAtButton2'])->toBe('bar')
        ->and($r['classWhileDialog'])->toContain('kbb-gal-2')
        ->and($r['barAfterColumn'])->toBeTrue()
        ->and($r['barAfterCancel'])->toBeTrue()
        ->and($r['classAfterCancel'])->toContain('kbb-gal-3')
        ->and($r['barAfterSave'])->toBeTrue()
        ->and($r['classAfterSave'])->toContain('kbb-gal-1')
        ->and($r['barAfterDone'])->toBeFalse()
        ->and($r['errors'])->toBe([]);
});
