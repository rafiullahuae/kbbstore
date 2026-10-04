<?php

declare(strict_types=1);

use App\Services\WhatsAppButton;
use Tests\Support\WhatsAppButtonHandover;

/**
 * Appearance → WhatsApp button: the wiring, pinned at its FINISHED state, and
 * the screen's own shape.                                             (Lane WA)
 *
 * ▲ EVERY COUNT BELOW IS `=== 1`, AND NONE OF THEM IS AN ABSENCE (CLAUDE.md,
 * "Do not pin that your own work is NOT wired up yet"). They are taken on the
 * console AS IT READS WITH docs/WA-ADMIN-APP-BLOCKS.md IN PLACE: the real file
 * where the integrator has applied a block, the block applied in memory where
 * he has not (Tests\Support\WhatsAppButtonHandover). So they are green in the
 * lane and after the merge, and red on what is really broken: a block applied
 * twice (a row registered twice, window.go wrapped around its own wrapper), or
 * an anchor that has drifted so the handover can no longer be applied.
 */
function waFinished(string $file): string
{
    $r = WhatsAppButtonHandover::finished(base_path());

    expect($r['problems'])->toBe([], implode("\n", $r['problems']));

    return $r['files'][$file] ?? (string) file_get_contents(base_path($file));
}

/* ═══════════════════════════════════════ the integrator's edits ═══ */

it('can apply every block of the handover, each anchor exactly as often as it says', function () {
    /*
     * DEFECT: an anchor another lane has since edited — the integrator finds
     * it missing, hand-places the line, and the console is half-wired.
     * MUTATION: change block 3's anchor by one character -> red, naming it.
     */
    $blocks = WhatsAppButtonHandover::blocks(base_path());

    expect(array_column($blocks, 'n'))->toBe(range(1, 9));

    $r = WhatsAppButtonHandover::finished(base_path());
    expect($r['problems'])->toBe([], implode("\n", $r['problems']));
});

it('mounts the route file on the admin-api group exactly once', function () {
    expect(substr_count(waFinished('routes/web.php'), "require __DIR__.'/whatsapp-button-admin.php';"))
        ->toBe(1, 'routes/whatsapp-button-admin.php is not required exactly once by routes/web.php');

    // Inside the guarded group: directly under page-wash's require, which is.
    expect(waFinished('routes/web.php'))->toContain(
        "require __DIR__.'/page-wash-admin.php';\n        require __DIR__.'/whatsapp-button-admin.php';"
    );
});

it('includes the screen on the console exactly once', function () {
    expect(substr_count(waFinished('resources/views/admin/app.blade.php'), "@include('admin.partials.whatsapp-button-screen')"))
        ->toBe(1, 'the screen is not mounted on the console exactly once');
});

it('gives the screen a breadcrumb and a title, and arms its deep link', function () {
    /*
     * Without the TITLES entry ?go=wabutton opens the DASHBOARD: go() reads
     * TITLES, and this screen's render() refuses to paint unless #ptitle says
     * "WhatsApp button".
     */
    $app = waFinished('resources/views/admin/app.blade.php');

    expect(substr_count($app, "'wabutton':['Appearance','WhatsApp button']"))->toBe(1);

    preg_match('/const LATE_RENDERED\s*=\s*new Set\(\[([^\]]*)\]\);/', $app, $m);
    expect($m)->not->toBeEmpty('LATE_RENDERED could not be found in the console at all');
    expect(substr_count($m[1], "'wabutton'"))->toBe(1, 'wabutton is not armed in LATE_RENDERED exactly once');
});

it('declares its sidebar row where the sidebar is built, as the partial declares it', function () {
    $app = waFinished('resources/views/admin/app.blade.php');
    $src = (string) file_get_contents(resource_path('views/admin/partials/whatsapp-button-screen.blade.php'));

    expect(substr_count($app, "{screen:'wabutton',label:'WhatsApp button',group:'Appearance',"
        ."after:['pagewash','dividers','prodstyles','homepage'],"))->toBe(1);

    // The copy agrees with the partial's own call, anchor for anchor.
    expect($src)->toContain("after: ['pagewash', 'dividers', 'prodstyles', 'homepage']");
});

it('puts the button on every storefront document exactly once', function () {
    /*
     * The store layout and the four storefront pages that do NOT extend it —
     * the journal, an article, the review wall and the skin quiz carry their
     * own <html> and would otherwise be the pages with no button.
     *
     * INSIDE A RAW BLOCK AN @include IS TEXT. blog, post and skin-quiz each end
     * in a raw block that holds `</body>`, and the first draft of this lane put
     * the include just before `</body>` there — where Blade prints it as the
     * literal words "@include(...)" and the page has no button. Measured on the
     * preview: /skin-quiz rendered 0 buttons. So the count is taken with every
     * raw block cut out first.
     * MUTATION: move the include in store/skin-quiz.blade.php back inside its
     * raw block (just above `</body>`) -> red, "store/skin-quiz.blade.php
     * includes the button 0 times".
     */
    $documents = [
        'layouts/store.blade.php',
        'store/blog.blade.php',
        'store/post.blade.php',
        'store/review-wall.blade.php',
        'store/skin-quiz.blade.php',
    ];

    $wrong = [];

    foreach ($documents as $view) {
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/'.$view)));
        $src = (string) preg_replace('/@verbatim\b.*?@endverbatim/s', '', $src);
        $count = substr_count($src, "@include('partials.whatsapp-button')");

        if ($count !== 1) {
            $wrong[] = "{$view} includes the button {$count} times";
        }
    }

    expect($wrong)->toBe([], implode("\n", $wrong));
});

/* ═════════════════════════════════════════════════ the screen itself ═══ */

it('registers one sidebar row, under Appearance, and wraps window.go once, painting first', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/whatsapp-button-screen.blade.php'));

    expect(substr_count($src, 'kbbAddNavEntry('))->toBe(1)
        ->and($src)->toContain("var SCREEN = 'wabutton';")
        ->and($src)->toContain("label: 'WhatsApp button'")
        ->and($src)->toContain("group: 'Appearance'")
        ->and(substr_count($src, 'window.go = function'))->toBe(1)
        ->and(substr_count($src, 'var previousGo = window.go;'))->toBe(1);

    $body = substr($src, strpos($src, 'window.go = function'));
    $body = substr($body, 0, strpos($body, 'async function load()'));

    expect(strpos($body, 'render();'))->toBeLessThan((int) strpos($body, 'load();'));

    // One endpoint, and no other path invented by the screen.
    preg_match_all("/api\\('([^']+)'/", $src, $calls);
    expect(array_values(array_unique($calls[1])))->toBe(['/whatsapp-button']);
});

it('draws every field type the schema declares', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/whatsapp-button-screen.blade.php'));

    $types = [];
    foreach (WhatsAppButton::normalised() as $field) {
        $types[$field['type']] = true;
    }

    expect(array_keys($types))->toEqualCanonicalizing(['bool', 'select', 'range', 'text', 'textarea']);

    foreach (['bool', 'select', 'range', 'textarea'] as $type) {
        expect(str_contains($src, "f.type === '".$type."'"))->toBeTrue("the screen cannot draw a {$type} field");
    }

    // The eight offset boxes are drawn as two rows of four, and every one of
    // them is a key in the schema.
    foreach (['m', 'd'] as $dev) {
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            expect(WhatsAppButton::SCHEMA)->toHaveKey("{$dev}_{$side}");
        }
    }
    expect($src)->toContain('/^[md]_(top|right|bottom|left)$/');
});

it('builds the preview with the same markup the shop prints', function () {
    /*
     * DEFECT: a preview that drifts from the shop — the owner tunes a design
     * on the screen and the shop shows something else. The preview uses the
     * shop's own CSS (cssAll), so every class the partial prints must be one
     * the screen's build() prints too.
     * MUTATION: rename `kbw-c` to `kbw-cap` in the partial -> red.
     */
    $partial = (string) file_get_contents(resource_path('views/partials/whatsapp-button.blade.php'));
    $screen = (string) file_get_contents(resource_path('views/admin/partials/whatsapp-button-screen.blade.php'));

    preg_match_all('/\bkbw-[a-z]{1,2}\d?\b/', $partial, $shop);
    $classes = array_values(array_unique($shop[0]));

    expect(count($classes))->toBeGreaterThanOrEqual(12);

    foreach ($classes as $class) {
        if (in_array($class, ['kbw-mo', 'kbw-do'], true)) {
            continue; // the per-device hide; the preview shows a note instead
        }
        expect(str_contains($screen, $class))->toBeTrue("build() never prints {$class}");
    }

    // And the CSS the preview injects is the shop's own, for all seven.
    foreach (array_keys(WhatsAppButton::DESIGNS) as $d) {
        expect(WhatsAppButton::cssAll())->toContain(WhatsAppButton::CSS_DESIGNS[$d]);
    }
    expect($screen)->toContain('st.textContent = preview.css;');
});

it('never sends a request while the owner drags, types or switches design', function () {
    /*
     * "Super light": no request per keystroke. The only fetch is api(), and
     * api() is called from load() and save() and nowhere else.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/whatsapp-button-screen.blade.php'));

    expect(substr_count($src, 'fetch('))->toBe(1)
        ->and(substr_count($src, "await api('/whatsapp-button'"))->toBe(2)
        ->and($src)->not->toContain('setInterval');

    // The size bar moves one custom property and returns without a redraw.
    expect($src)->toContain("btn.style.setProperty('--k'");
});
