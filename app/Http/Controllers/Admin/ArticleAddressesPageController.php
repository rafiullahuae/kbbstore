<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\ReservedArticleReport;
use Illuminate\Http\Response;

/**
 * The articles the import will refuse, on a screen — Lane U3, Phase 13.
 *
 * =============================================================================
 * THE LIST ALREADY EXISTED. NOTHING COULD REACH IT
 * =============================================================================
 *
 * `ReservedArticleReport` was built in full and mounted at two endpoints:
 *
 *     GET /admin-api/import/article-addresses       JSON
 *     GET /admin-api/import/article-addresses.csv   the spreadsheet
 *
 * Both work. Neither is linked from anywhere, and neither is a screen. The
 * owner's side of this defect is to open Store → Import, see which of his live
 * articles this shop cannot serve, and go and rename them in WordPress — and
 * what he had was a JSON body he would have to know the URL of, and a download
 * whose first row is a header nobody has read yet. The plan's wording is
 * "**Run a preview** — it writes nothing — and it produces the list Lane GA
 * asked the owner for", and a list is a thing you look at.
 *
 * So this is the third representation of the same read, and the only one that
 * is a page: the same `ReservedArticleReport::run()`, rendered server-side.
 *
 * =============================================================================
 * SERVER-RENDERED, AND WHY THAT IS NOT LAZINESS
 * =============================================================================
 *
 * `MediaSideloadApiController::page()` is the precedent and states the first
 * two reasons: no build step exists in this project (`package.json` defines no
 * `build` script, CI does not build assets), and a page that has to be
 * trustworthy when something has gone wrong should not depend on the 21,000
 * line console bundle, which is the thing most likely to be what is wrong.
 *
 * The third is this page's own. That page polls because a fetch run MOVES.
 * This one does not: `run()` reads one file once and answers, so there is
 * nothing to poll, and a page whose content is fixed at render time needs no
 * JavaScript to display it at all. There is none here beyond a filter box, and
 * nothing on the page measures layout — CLAUDE.md rule 4.
 *
 * =============================================================================
 * IT WRITES NOTHING, WHICH IS WHY IT IS A GET
 * =============================================================================
 *
 * No transaction is opened, no checkpoint touched, no ledger row appended. The
 * owner can open this halfway through a live import and the run does not
 * notice. `ReservedArticleReport`'s own header has the argument in full, and
 * `ImportArticleAddressesTest > it writes nothing at all` asserts it.
 *
 * =============================================================================
 * THE CAPABILITY IS THE EXISTING ONE, BECAUSE THE PREFIX IS
 * =============================================================================
 *
 * `routes/import-articles-page.php` mounts this under `/import/`, which
 * `AdminCapabilities::RULES` already covers with
 * `['*', 'admin-api/import/**', 'data.import']`. A prefix of this lane's own
 * would fall through to the closed owner-only default — which
 * `AdminCapabilityMapTest` fails on by name, and which would be a 403 on a
 * screen whose whole job is to be read before a cutover.
 *
 * `no-store`, because the page quotes article titles out of the owner's
 * WordPress database and is behind `auth:admin`; a cached copy in a shared
 * browser is the one way that content outlives the session.
 */
final class ArticleAddressesPageController extends Controller
{
    public function __invoke(ReservedArticleReport $report): Response
    {
        return response()
            ->view('admin.article-addresses', ['report' => $report->run()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
