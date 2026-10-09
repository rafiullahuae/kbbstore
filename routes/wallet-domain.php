<?php

declare(strict_types=1);

use App\Http\Controllers\Store\AppleDomainController;
use App\Http\Controllers\Store\SeoFilesController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Apple Pay — the domain association file (Lane WAL)
|------------------------------------------------------------------------------
|
| INTEGRATOR: this file needs one line in routes/web.php, at the TOP LEVEL of
| the file, ANYWHERE ABOVE `require __DIR__ . '/kbb-brands-blog.php';` (that
| file ends in a catch-all single-root-segment route):
|
|     require __DIR__.'/wallet-domain.php';
|
| The natural home is immediately after the three crawl files at the top —
| /sitemap.xml, /robots.txt and /llms.txt — because this is the fourth document
| of the same kind: machine-read, no session, no per-visitor content.
|
| WHY IT IS NEEDED AT ALL. Apple Pay on the web will not draw a sheet on a
| domain Apple has not verified, and verification is one GET of
|
|     https://<your domain>/.well-known/apple-developer-merchantid-domain-association
|
| where <your domain> is the shop's own address (Platform → Site address, else
| APP_URL) — App\Services\Payments\AppleDomainFile::url() builds it, and the
| Stripe status block on Store → Payments → Credit or debit card prints it.
|
| WHAT IT SERVES IS APPLE'S FILE OR NOTHING (Lane WL). The live shop once
| answered here with `pmd_…`, the ID Stripe shows for a payment-method domain,
| pasted into the box meant for the file; Apple cannot verify a domain from
| that, and Apple Pay never appeared. AppleDomainFile::problem() now refuses
| anything that is not one long line of hex, on save and here (a 404).
|
| Everything else about Apple Pay is built and testable from here. This file is
| the only part that is not, because only the owner can add the domain in the
| Stripe dashboard and paste back what it hands him.
| docs/WALLETS-APPLE-GOOGLE-PAY.md is the numbered list.
|
| WHY IT IS NOT A FILE IN public/. On this host the web root is a DIFFERENT
| DIRECTORY from the application root — bootstrap/app.php ends with a
| usePublicPath() pointing at a sibling of it — so a file committed under
| public/ arrives somewhere no HTTP request can reach. Code travels as a zip
| applied through Store → Core Updates, which writes into the application root.
| A route is the only delivery that needs nobody to touch the server.
|
| An owner who would rather place the real file wins anyway and needs no change
| here: a web server serves an existing file before Laravel's front controller
| ever runs, so `public_html/.well-known/apple-developer-merchantid-domain-association`
| simply takes precedence and this route never fires.
|
| ONE CAVEAT ABOUT THE BASE PATH, because it is the difference between this
| working and this being invisible. Apple fetches the file from the DOMAIN ROOT
| and will not follow a prefix:
|
|     https://<your domain>/.well-known/apple-developer-merchantid-domain-association
|
| On the live shop `KBB_BASE_PATH` is EMPTY (docs/CUTOVER-EXTRABEAUTY.md is the
| authority, and CLAUDE.md records that believing otherwise has already cost
| this project real time), so the route below sits exactly there. A deployment
| that DOES serve the shop under a prefix has to place the real file in the web
| root instead — this route would answer at `<prefix>/.well-known/...`, which is
| not an address Apple ever asks for. Step 5 of
| docs/WALLETS-APPLE-GOOGLE-PAY.md is a curl of the exact URL, which catches
| that in one command rather than as a verification that silently never passes.
|
| A route added here does nothing until the compiled route cache is cleared, so
| the package carrying it also ships
| database/migrations/2027_05_06_000000_clear_caches_wallet_domain.php.
|
*/

/*
 * STATELESS, the same five middleware exemptions the crawl files take and for
 * the same reason: this document has no per-visitor content, and a session
 * would put two Set-Cookie headers on a file Apple fetches from a datacentre.
 *
 * NOT withoutMiddleware(['web']) — that would take NoIndexStaging with it, and
 * the controller's own X-Robots-Tag is about this document rather than about
 * the environment.
 *
 * NO THROTTLE. Apple retries verification on its own schedule and a rate limit
 * that refused one of those attempts would fail the registration for a reason
 * nothing on the shop would explain. The response is a constant-shaped string
 * read out of one already-cached row; it is cheaper than the 404 it replaces.
 */
Route::withoutMiddleware(SeoFilesController::STATELESS)->group(function () {
    /*
     * TWO PATHS, ONE ANSWER.
     *
     * The extensionless one is what Apple documents and what Stripe's dashboard
     * tells the merchant to serve. The `.txt` one is here because it is what a
     * download lands as on a machine that adds an extension, and because some
     * hosting panels will not serve an extensionless file at all — answering
     * both costs a line and removes a failure whose only symptom is Stripe
     * saying "we could not verify this domain".
     */
    Route::get('/.well-known/apple-developer-merchantid-domain-association', AppleDomainController::class)
        ->name('wallets.apple-domain');

    Route::get('/.well-known/apple-developer-merchantid-domain-association.txt', AppleDomainController::class)
        ->name('wallets.apple-domain-txt');
});
