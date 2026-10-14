<?php

/**
 * Every reset-to-default control in the admin asks "Are you sure?" (Yes / No)
 * in the admin's own box before it puts anything back.
 *
 * THE OWNER, 10 October 2026: "any reset to default buttons in our backend,
 * must ask Are you sure (yes / no) in a popup to confirm."
 *
 * WHAT IT LOOKED LIKE BEFORE (Lane RC): reset-guard caught a button only when
 * its label STARTED with Reset / Restore / Revert / Back to defaults, so these
 * put a default back in one click with no question at all:
 *   - Store -> Modules, each group's "Defaults";
 *   - Product page -> Bought together, "Use the default" (drops the picks);
 *   - Catalog -> Product tabs, "Use the shop's one" (DELETES the override on
 *     the server straight away);
 *   - Appearance -> Set, the per-setting "shipped";
 *   - Pages -> Edit page, a contact card's "Shop's own";
 *   - Appearance -> Coming Soon page, "Shop pink".
 * And the ones it did catch said only "You pressed “Reset to defaults”", while
 * six others asked twice -- this box, then the browser's own window.confirm --
 * and Modules -> "Back to defaults" asked in a different box with "Cancel".
 *
 * MUTATIONS, RUN:
 *   - delete the ['[data-mdgdef]', ...] row from KNOWN in reset-guard: the
 *     "every reset control routes through the dialog" case is red, naming
 *     Defaults in app.blade.php;
 *   - put `if (!window.confirm('Put this email back ...')) return;` back in
 *     emails-templates-screens: the "no native confirm" case is red;
 *   - rename id="hdReset" in app.blade.php: the "every listed selector still
 *     exists" case is red;
 *   - define window.kbbConfirm a second time in any admin view: the "defined
 *     once" case is red.
 */
function rcGuard(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/reset-guard.blade.php'));
}

/** @return array<string, string> file name => source, every admin view except the guard */
function rcViews(): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/admin'))) as $file) {
        $path = $file->getPathname();
        if (str_ends_with($path, '.blade.php') && ! str_contains($path, 'reset-guard')) {
            $out[substr($path, strlen(resource_path('views/admin')) + 1)] = (string) file_get_contents($path);
        }
    }

    return $out;
}

/** @return list<string> the selectors in reset-guard's KNOWN list, in order */
function rcKnown(): array
{
    $guard = rcGuard();
    $start = strpos($guard, 'var KNOWN = [');
    $end = strpos($guard, "\n  ];", (int) $start);
    expect($start)->not->toBeFalse('reset-guard no longer declares KNOWN');
    preg_match_all("/^    \\['([^']+)',/m", substr($guard, (int) $start, (int) $end - (int) $start), $m);

    return $m[1];
}

/** The text a selector leaves in the markup, e.g. #hdReset -> id="hdReset". */
function rcToken(string $selector): string
{
    if (preg_match('/^#([\w-]+)$/', $selector, $m)) {
        return 'id="'.$m[1].'"';
    }
    if (preg_match('/^\[([\w-]+)\^="([^"]+)"\]$/', $selector, $m)) {
        return $m[1].'="'.$m[2];
    }
    if (preg_match('/^\[([\w-]+)(?:="([^"]+)")?\]$/', $selector, $m)) {
        return isset($m[2]) ? $m[1].'="'.$m[2].'"' : $m[1];
    }

    throw new RuntimeException("unrecognised selector {$selector}");
}

/**
 * Every button in the admin whose label puts a default (or an earlier state)
 * back, with its attributes. Labels are read from markup and from the JS
 * strings that build it, including `(isCustom ? 'Use the default' : ...)`.
 *
 * @return list<array{file: string, attrs: string, label: string}>
 */
function rcResetControls(): array
{
    $label = '/^(reset|restore|revert|back to defaults?|defaults\b|use the default|use the shop|shop(’|&rsquo;|\')s own|shipped|shop pink|put this group back|forget progress)/i';
    $found = [];
    foreach (rcViews() as $file => $src) {
        preg_match_all('#<button\b([^>]*)>([^<]{0,80})#i', $src, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $text = trim((string) preg_replace('/^[\s\'"+(]*(?:\$\{)?(?:[\w.]+\s*\?\s*)?[\'"`]?/', '', $hit[2]));
            if (preg_match($label, $text)) {
                $found[] = ['file' => $file, 'attrs' => $hit[1], 'label' => substr($text, 0, 40)];
            }
        }
    }

    return $found;
}

it('defines window.kbbConfirm exactly once, in reset-guard, and the guard asks through it', function () {
    $guard = rcGuard();
    $defs = substr_count($guard, 'window.kbbConfirm = function');
    foreach (rcViews() as $src) {
        $defs += preg_match_all('/window\.kbbConfirm\s*=/', $src);
    }

    expect($defs)->toBe(1)
        // The click guard itself asks through the same box the screens call.
        ->and($guard)->toContain('window.kbbConfirm(asks')
        // Focus starts on No; Escape and the backdrop answer No; Tab stays inside.
        ->and($guard)->toContain('no.focus();')
        ->and($guard)->toContain("if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); settle(false); return; }")
        ->and($guard)->toContain('if (e.target === bg) settle(false);')
        ->and($guard)->toContain("if (e.key === 'Tab')")
        ->and($guard)->toContain('role="alertdialog" aria-modal="true"');
});

it('routes every reset-to-default control in the admin through the dialog', function () {
    $tokens = array_map('rcToken', rcKnown());
    $controls = rcResetControls();
    $unasked = [];

    foreach ($controls as $c) {
        $ok = str_contains($c['attrs'], 'data-kbb-asks') || str_contains($c['attrs'], 'data-kbb-sure');
        foreach ($tokens as $t) {
            $ok = $ok || str_contains($c['attrs'], $t);
        }
        if (! $ok) {
            $unasked[] = "\"{$c['label']}\" in {$c['file']}";
        }
    }

    expect($unasked)->toBe([], 'reset controls that do not ask first: '.implode('; ', $unasked));

    // Pinned, so a new reset is a decision and not an accident: add it to
    // KNOWN in reset-guard (or mark it data-kbb-asks and call kbbConfirm), then
    // move this number.
    // ▲ Lane CX: +1, "Restore the seven concerns" in the Skin concerns chip
    // editor, which asks for itself (data-kbb-asks + window.kbbConfirm).
    expect(count($controls))->toBe(55);
});

it('names a control that still exists for every row of the list', function () {
    $known = rcKnown();
    $all = implode("\n", rcViews());

    expect(count($known))->toBe(47);
    foreach ($known as $selector) {
        expect(str_contains($all, rcToken($selector)))->toBeTrue("{$selector} is in reset-guard's list but no admin view renders it");
    }

    // The resets the owner was meeting with no question at all, by name.
    foreach (['[data-mdgdef]', '[data-btp-mode]', '[data-kpt-revert]', '[data-sap-reset]', '[data-cc-reset]', '[data-csx-bgreset]', '[data-mddef]', '#hdReset', '[data-chp-defaults]'] as $must) {
        expect($known)->toContain($must);
    }
});

it('asks once, in the admin box, where a screen asks for itself', function () {
    $views = rcViews();
    $self = [
        'partials/admin-roles-screen.blade.php' => 'data-rl="restore-role" data-kbb-asks',
        'partials/emails-templates-screens.blade.php' => 'data-ek-reset data-kbb-asks',
        'partials/domain-switch-screen.blade.php' => 'data-dwi-reset data-kbb-asks',
        'partials/marketing-pixels-connect.blade.php' => 'data-kbb-asks data-cc-restore',
        'partials/set-appearance-screen.blade.php' => 'data-kbb-asks data-sap-groupreset',
        'app.blade.php' => 'id="impReset" data-kbb-asks',
        'partials/concern-chips-editor.blade.php' => 'data-kcc-reset data-kbb-asks',
    ];

    $marked = 0;
    foreach ($views as $src) {
        $marked += preg_match_all('/data-kbb-asks\b/', $src);
    }
    expect($marked)->toBe(count($self));

    foreach ($self as $file => $button) {
        expect($views[$file])->toContain($button)
            ->and($views[$file])->toContain('window.kbbConfirm(');
    }

    // No reset left on the browser's own confirm(), and Modules no longer has
    // a second, different box for Back to defaults.
    foreach ($views as $file => $src) {
        preg_match_all('/\bconfirm\((?:[^()]|\([^()]*\))*\)/', $src, $m);
        foreach ($m[0] as $call) {
            expect((bool) preg_match('/default|reset|shipped|built-in|version back/i', $call))
                ->toBeFalse("{$file} still resets behind the browser's confirm(): {$call}");
        }
    }
    expect($views['app.blade.php'])->not->toContain("askThen('Back to defaults'");
});

it('renders the dialog once on the admin page, hidden until asked', function () {
    $html = view('admin.partials.reset-guard')->render();

    expect(substr_count($html, 'id="kbbSureBg"'))->toBe(1)
        ->and($html)->toContain('window.kbbConfirm = function')
        ->and($html)->toContain('>No</button>')
        ->and($html)->toContain('>Yes</button>')
        ->and($html)->not->toContain('@verbatim');
});
