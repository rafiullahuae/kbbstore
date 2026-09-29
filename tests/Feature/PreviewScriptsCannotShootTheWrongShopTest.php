<?php

declare(strict_types=1);

/**
 * A preview script may not hand back a URL it has not proved is its own.
 *                                                                    (Lane BG)
 *
 * ── WHAT THIS EXISTS FOR ───────────────────────────────────────────────────
 *
 * There are thirty-nine `tools/*-preview.sh` in this repository and every one
 * of them boots `php -S` on a port it chose by typing a number into the script.
 * Counted before this guard: SIX defaulted to 8991, five to 8989, four to 8977,
 * three to 8993, and eight more shared a port with one other script. Two lanes
 * colliding was not bad luck, it was the arrangement.
 *
 * And it fails in the way that costs the most. `php -S` writes "Address already
 * in use" into its OWN log and exits; the script's next line printed a URL
 * regardless; and the shot run that followed photographed WHOEVER WAS ALREADY
 * ON THAT PORT. Lane BG lost twenty minutes to exactly that — a shop with the
 * wrong catalogue in it and a 404 on a product that was certainly in the
 * database, which reads as a bug in your own work and is not one. A leaked
 * server also outlives the run that started it, so once it happens the
 * collision is permanent for every future run on that number.
 *
 * ── THE TWO THINGS EVERY SCRIPT MUST DO ────────────────────────────────────
 *
 * 1. ASK THE OPERATING SYSTEM FOR A PORT rather than trusting a literal. Walk
 *    up from the requested number and take the first that will actually bind.
 *    `tests/Support/PreviewPort.php` is the same fix for the suite and carries
 *    the same story.
 *
 * 2. PROVE THE SERVER ANSWERING IS ITS OWN before printing a URL. A 200 does
 *    not prove it — another lane's preview answers 200 to everything. Each
 *    script writes a nonce into its own webroot and reads it back over HTTP;
 *    another lane's server is rooted in another lane's directory and cannot
 *    have the file. Lane PG2 got there first with a request for a slug only its
 *    own seed creates, which is the same idea with a stronger claim (it proves
 *    the SEED as well as the server), and the scripts that have one keep it.
 *
 * ── MUTATION NOTES, both run ───────────────────────────────────────────────
 *
 *   - put `PORT=${1:-8991}` back at the top of tools/sa-preview.sh
 *       → "sa-preview.sh picks its port by typing a number"
 *   - delete the nonce block from tools/cp-preview.sh
 *       → "cp-preview.sh prints a URL without proving the server is its own"
 */
it('makes every preview script ask for a port rather than type one', function () {
    $scripts = glob(base_path('tools/*-preview.sh')) ?: [];

    // The check is blind if the directory ever stops being walked.
    expect(count($scripts))->toBeGreaterThan(30,
        'tools/*-preview.sh returned almost nothing, so this guard saw nothing');

    $wrong = [];

    foreach ($scripts as $file) {
        $src = (string) file_get_contents($file);
        $name = basename($file);

        if (! str_contains($src, 'KBBPORTPY')) {
            $wrong[] = '  '.$name.' picks its port by typing a number, so it will one day'
                ."\n     boot onto another lane's preview and photograph their shop";
        }
    }

    expect($wrong)->toBe([], "a preview script guesses its port:\n".implode("\n", $wrong));
});

it('makes every preview script prove the server answering is its own', function () {
    $scripts = glob(base_path('tools/*-preview.sh')) ?: [];
    $wrong = [];

    foreach ($scripts as $file) {
        $src = (string) file_get_contents($file);
        $name = basename($file);

        /*
         * The nonce is written AND read back. Either half alone is useless: a
         * script that writes the file and never fetches it has proved nothing,
         * and one that fetches a file it did not write is asking another lane's
         * server for something it might coincidentally have.
         */
        $writes = str_contains($src, '> "$ROOT/kbb-preview-id.txt"');
        $reads = str_contains($src, 'kbb-preview-id.txt" || true)');

        if (! ($writes && $reads)) {
            $wrong[] = sprintf(
                '  %-26s prints a URL without proving the server is its own (writes:%s reads:%s)',
                $name, $writes ? 'y' : 'n', $reads ? 'y' : 'n'
            );
        }
    }

    expect($wrong)->toBe([], "a preview script hands back a URL it has not checked:\n"
        .implode("\n", $wrong)
        ."\n\nA 200 does not prove the server is yours — another lane's answers 200 too.");
});

it('refuses rather than warns, and takes its own server down with it', function () {
    /*
     * The failure path matters as much as the check. A script that printed a
     * warning and carried on would leave the operator with a URL and a caveat
     * they will not remember four minutes later, and a leaked `php -S` holding
     * the port for every future run on this machine.
     */
    foreach (glob(base_path('tools/*-preview.sh')) ?: [] as $file) {
        $src = (string) file_get_contents($file);

        if (! str_contains($src, 'kbb-preview-id.txt')) {
            continue;
        }

        $block = substr($src, (int) strpos($src, 'kbbnonce='));
        $block = substr($block, 0, (int) strpos($block, "fi\n") + 3);

        expect(str_contains($block, 'exit 4'))
            ->toBeTrue(basename($file).' does not exit on a failed identity probe');
        expect(str_contains($block, 'kill "$(cat "$DIR/server.pid")"'))
            ->toBeTrue(basename($file).' leaves its own server running after refusing');
    }
});

it('keeps the fixture checks the scripts that have one already had', function () {
    /*
     * The general nonce does not replace a per-lane fixture check, it sits
     * beside it: the nonce proves the SERVER, the fixture proves the SEED. A
     * webroot that came up before `artisan tinker` finished passes the first and
     * fails the second, and photographs an empty shop.
     *
     * Asserted in the positive so that a later sweep over these scripts cannot
     * quietly drop the stronger check while keeping the weaker one.
     */
    $pg2 = (string) file_get_contents(base_path('tools/pg2-preview.sh'));
    expect($pg2)->toContain('Relief Sun Rice + Probiotics SPF50');

    $bg = (string) file_get_contents(base_path('tools/bg-preview.sh'));
    expect($bg)->toContain('Heartleaf 77% Soothing Toner 250ml')
        ->and($bg)->toContain('/product/lanebg-1/');
});
