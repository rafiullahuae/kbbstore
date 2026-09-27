<?php

declare(strict_types=1);

/**
 * A failure on the Shoppable video screens has to name its own cause.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT, AND WHAT IT COST ────────────────────────────────────────────
 *
 * The owner reported "All clips tab giving error" with a screenshot of one
 * sentence: **"The video library could not be read."** That sentence is all
 * either of us had. It names no cause, carries no status code, and suggests
 * nothing to do — and it is what BOTH screens printed for every failure that
 * was not a 403, 404, 413, 422 or 429.
 *
 * The status was on the error object the whole time. api() sets `err.status`
 * from the response and explain() threw it away one line later, so a crashed
 * server, an expired session and a dropped connection were one indistinguishable
 * message. Diagnosing the report meant guessing, and guessing is what this
 * project's own rules forbid.
 *
 * Three holes, each a status this shop really produces:
 *
 *   401  The console's shell is server-rendered once at sign-in and then lives
 *        in the tab. Every screen after that is XHR — so a lapsed admin session
 *        does not bounce anyone to a login page, it lets the console keep
 *        painting and refuses the DATA. Reloading is the whole remedy, and
 *        nothing is wrong with the shop.
 *   419  The same family: Laravel's answer to a CSRF token that aged out.
 *   5xx  The server crashed. Laravel answers with {"message": ...} and NOT
 *        {"error": ...}, which is the only key explain() ever read — so a
 *        server crash's own words could never reach the screen even when they
 *        were in the payload.
 *
 * ── WHY THIS TEST READS THE SOURCE ──────────────────────────────────────────
 *
 * explain() is browser JavaScript inside a Blade partial; there is no PHP entry
 * point to call and no bundler to import it from. AdminNavAndIdsTest and
 * UgcInstantStepsTest read these same files for the same reason. What is pinned
 * is therefore the BRANCH EXISTING for each status and the sentence being
 * actionable — which is exactly the thing that regressed by never being written.
 *
 * MUTATION NOTE. Delete any one of the three branches from either screen and
 * the matching case below is red. RUN: red, six times over (three branches,
 * two screens).
 */
$ugcScreens = [
    'All clips' => 'ugc-library-screen',
    'Sections' => 'ugc-sections-screen',
];

function ugcScreenSource(string $name): string
{
    $path = resource_path('views/admin/partials/'.$name.'.blade.php');

    expect(is_file($path))->toBeTrue("missing partial: {$name}");

    return (string) file_get_contents($path);
}

it('tells the owner a lapsed session is a session, on both screens', function () use ($ugcScreens) {
    foreach ($ugcScreens as $label => $file) {
        $src = ugcScreenSource($file);

        expect($src)->toContain('e.status === 401')
            ->and($src)->toContain('e.status === 419')
            // The remedy, in the sentence itself. "Unauthenticated" is the
            // server's word and means nothing to the person reading it.
            ->and($src)->toContain('Your admin session has expired');
    }
});

it('says a server crash is a server crash, and where the reason is', function () use ($ugcScreens) {
    foreach ($ugcScreens as $label => $file) {
        $src = ugcScreenSource($file);

        expect($src)->toContain('e.status >= 500')
            // The status, because "could not answer" without a number is the
            // message this whole test exists to replace.
            ->and($src)->toContain("'The server could not answer (HTTP '")
            // Laravel's own key for a 500. Reading only `error` is what kept
            // the server's words off the screen.
            ->and($src)->toContain('e.body.message')
            // Something to actually do. The owner has SSH; this is the command.
            ->and($src)->toContain('storage/logs/laravel.log');
    }
});

it('never leaves a failure anonymous, whatever the status', function () use ($ugcScreens) {
    foreach ($ugcScreens as $label => $file) {
        $src = ugcScreenSource($file);

        /*
         * The catch-all. Any status without a branch of its own still prints
         * its number, so the next unfamiliar failure arrives already diagnosed
         * instead of costing a round trip. A fetch that never completed reports
         * status 0, which is meaningless as a number and is named instead.
         */
        expect($src)->toContain("fallback + ' (HTTP ' + e.status + ')'")
            ->and($src)->toContain('e.status === 0')
            ->and($src)->toContain('never reached the server');
    }
});

/*
 * AND THE SENTENCE THAT STARTED IT ALL IS STILL THERE — as the FALLBACK, which
 * is correct. It is the subject of the message; the branches above supply the
 * predicate. A change that deleted it would be a change that made the banner
 * say only "(HTTP 500)".
 */
it('keeps the original sentence as the subject of the message', function () {
    expect(ugcScreenSource('ugc-library-screen'))
        ->toContain('The video library could not be read.');
});
