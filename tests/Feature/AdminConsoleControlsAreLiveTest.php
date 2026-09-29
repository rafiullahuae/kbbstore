<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Lane QA — a control on an admin screen that does nothing, and does it quietly.
 *
 * ── THE TWO SHAPES, BOTH ALREADY PAID FOR ───────────────────────────────────
 *
 * 1. A BUTTON THAT CALLS A PATH NO ROUTE ANSWERS.
 *    routes/import-history-admin.php was never required in any version of this
 *    app. Its three endpoints were dead, and resources/views/admin/app.blade.php
 *    rendered a LINK to one of them: the owner could click it and get a 404 on
 *    a screen that looked finished. Nothing logged, nothing 500'd, no test
 *    failed — because no test had ever crossed what the console CALLS against
 *    what the router ANSWERS.
 *
 * 2. A BUTTON WITH NO HANDLER AT ALL.
 *    Drawn, styled, disabled-state and all, and nothing binds its id. Pressing
 *    it is indistinguishable from pressing a piece of the page: no request, no
 *    error in the console, no toast. The only way to find one is to press every
 *    button on every screen, or to look for it.
 *
 * Both are invisible by construction, which is why they are enumerated here
 * rather than left to whoever happens to click.
 *
 * ── WHAT EACH CASE COVERS, SAID EXACTLY ─────────────────────────────────────
 *
 * The first walks resources/views/admin/** and the second only
 * resources/views/admin/partials/**. That is deliberate and it is not a
 * softening: every NEW admin screen in this project is a partial — app.blade.php
 * is the integrator's file and CLAUDE.md forbids a lane editing it, which is
 * exactly why the checkout, cart-panel, security and set-appearance screens were
 * each extracted into one. So the second case covers the ground new work lands
 * on.
 *
 * ▲ IT IS NOT GREEN ON app.blade.php TODAY, AND THAT IS A REPORTED FINDING, NOT
 *   AN OVERSIGHT. Two buttons in that file are drawn and bound to nothing:
 *
 *     app.blade.php:8181  id="impStop"       Store → Import, the "part-way
 *                                            through" banner. Its sibling
 *                                            #impContinue is bound at line 8791
 *                                            and #impStop is bound nowhere, so
 *                                            an import cannot be stopped from
 *                                            the screen that offers to stop it.
 *                                            POST /admin-api/import/stop is
 *                                            live, capability-mapped and called
 *                                            by tests only.
 *     app.blade.php:12603 id="olBulkDelete"  Orders → the bulk bar's "Move to
 *                                            trash…". olConfirmDelete() and
 *                                            olRunDelete() both exist and both
 *                                            are unreachable; the three
 *                                            controls beside it (#olBulkStatus,
 *                                            #olBulkPrint, #olBulkRestore) are
 *                                            bound at lines 12894–12911.
 *
 *   Widening this case to app.blade.php is a one-line change to the glob, and
 *   it is the right change the moment those two lines are wired.
 */

/* ───────────────────────────── 1 · live endpoints ───────────────────────── */

it('points every admin-console API path at a route that answers', function () {
    /*
     * Read from the real router rather than from routes/web.php as text: a path
     * is only answerable if the file that declares it was actually required,
     * and the whole point of this check is the file that never was.
     */
    $uris = [];
    $patterns = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/' . ltrim($route->uri(), '/');
        $uris[] = $uri;

        $regex = preg_quote($uri, '#');
        $regex = (string) preg_replace('#\\\\\{[A-Za-z0-9_]+\\\\\?\\\\\}#', '[^/]*', $regex);
        $regex = (string) preg_replace('#\\\\\{[A-Za-z0-9_]+\\\\\}#', '[^/]+', $regex);
        $patterns[] = '#^' . $regex . '$#';
    }

    $uris = array_values(array_unique($uris));

    /**
     * A literal is answerable if the router matches it whole, OR if some route
     * sits underneath it — the console builds most of its calls as
     * `base() + '/suffix'`, so `'/admin-api/outbound'` is a BASE and not a path,
     * and demanding an exact match would report every one of them.
     */
    $answerable = function (string $path) use ($patterns, $uris): bool {
        foreach ($patterns as $regex) {
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        $path = rtrim($path, '/');

        foreach ($uris as $uri) {
            if (str_starts_with($uri, $path . '/')) {
                return true;
            }
        }

        return false;
    };

    $dead = [];
    $seen = 0;

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/admin')));

    foreach ($walk as $entry) {
        if (! $entry->isFile() || ! str_ends_with($entry->getFilename(), '.blade.php')) {
            continue;
        }

        /*
         * Prose first. This console explains its own history in comments and
         * QUOTES the paths it no longer calls — a raw scan reads the
         * explanation as a call and reports a screen that is correct.
         */
        $src = (string) file_get_contents($entry->getPathname());
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
        $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

        foreach (explode("\n", $src) as $i => $line) {
            if (! preg_match_all('#[\'"](/(?:admin-api|api)/[A-Za-z0-9_\-/.]*)[\'"]#', $line, $m)) {
                continue;
            }

            foreach ($m[1] as $path) {
                // A file the browser fetches, not an endpoint.
                if (preg_match('#\.(css|js|png|jpg|jpeg|svg|ico|woff2?)$#i', $path)) {
                    continue;
                }

                // '/admin-api/...' appears literally, as an ellipsis, in help text.
                if (str_contains($path, '...')) {
                    continue;
                }

                $seen++;

                if (! $answerable($path)) {
                    $dead[] = sprintf(
                        '  %s:%d  %s',
                        str_replace(base_path() . '/', '', $entry->getPathname()),
                        $i + 1,
                        $path
                    );
                }
            }
        }
    }

    expect($seen)->toBeGreaterThan(60, 'almost no API paths were found in the console, so this guard saw nothing');

    expect(array_values(array_unique($dead)))->toBe(
        [],
        "the admin console calls a path no route answers:\n" . implode("\n", array_unique($dead))
        . "\n\nThis is the routes/import-history-admin.php shape: a screen that looks finished,"
        . ' a link the owner can click, and a 404 with nothing in any log.'
    );
});

/* ─────────────────────────── 2 · buttons with handlers ──────────────────── */

it('binds every button an admin screen partial draws', function () {
    /*
     * A button is bound if ANY other occurrence of its id exists in the same
     * partial — `byId('x')`, `$('#x')`, `document.getElementById('x')`,
     * `querySelector('#x')` are all in use in this console and a check that
     * knew only one of them would be its own dead guard. Counting every
     * occurrence of the id and subtracting the `id="…"` declarations answers
     * all four at once and needs nothing added when a fifth appears.
     *
     * Two cases are skipped and both are real bindings, not exemptions:
     * an inline `on…=` attribute IS the handler, and `type="submit"` inside a
     * form is handled by the form.
     */
    $unbound = [];
    $seen = 0;

    foreach (glob(resource_path('views/admin/partials/*.blade.php')) ?: [] as $file) {
        $body = (string) file_get_contents($file);

        foreach (explode("\n", $body) as $i => $line) {
            if (! preg_match_all('#<button\b([^>]*)>#', $line, $m, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($m as $match) {
                $attrs = $match[1];

                if (! preg_match('#\bid=[\'"]([A-Za-z][\w-]*)[\'"]#', $attrs, $idMatch)) {
                    continue;
                }

                if (preg_match('#\bon[a-z]+\s*=#i', $attrs)) {
                    continue;   // the handler is on the tag
                }

                if (preg_match('#\btype=[\'"]submit[\'"]#i', $attrs)) {
                    continue;   // the form handles it
                }

                $id = $idMatch[1];
                $seen++;

                $all = preg_match_all('#(?<![\w-])' . preg_quote($id, '#') . '(?![\w-])#', $body);
                $declarations = preg_match_all('#\bid=[\'"]' . preg_quote($id, '#') . '[\'"]#', $body);

                if ($all - $declarations <= 0) {
                    $unbound[] = sprintf('  %s:%d  #%s', basename($file), $i + 1, $id);
                }
            }
        }
    }

    expect($seen)->toBeGreaterThan(80, 'almost no id-bearing buttons were found, so this guard saw nothing');

    expect(array_values(array_unique($unbound)))->toBe(
        [],
        "an admin screen draws a button nothing listens to:\n" . implode("\n", array_unique($unbound))
        . "\n\nPressing it does nothing at all — no request, no error, no toast — which the owner"
        . ' reports as "the feature did not ship".'
    );
});
