<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\SecurityModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the report-only policy on a storefront page, and does nothing else.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT IS A MIDDLEWARE AND IT STILL CANNOT REFUSE ANYTHING
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every other hook this module has is a model event, an auth event or a
 * listener on RequestHandled, because a listener holds a finished response and
 * has nothing to return. A header has to be SET on the response, so this one
 * is a middleware — and that is the one place in the module where the
 * structural argument has to be made differently rather than not at all.
 *
 * It is made three ways:
 *
 *   1. handle() has exactly one `return` and it returns `$next($request)`'s own
 *      response. There is no branch that answers without calling $next, no
 *      abort(), no response(), no setStatusCode, no redirect. SecurityCspTest
 *      reads this file as text for each of those and would go red on any of
 *      them.
 *   2. What it adds is Content-Security-Policy-REPORT-ONLY, which by
 *      definition changes nothing a browser does. The enforcing header name is
 *      not in this application's source at all — see ContentSecurityPolicy.
 *   3. The absolute-status walk. SecurityCspTest asks the storefront for six
 *      addresses with the switch on and with it off and compares the codes to
 *      NAMED absolutes, not to each other: comparing the module against itself
 *      is how a gate that refuses in both passes reports all clear, which this
 *      lane established by mutation in round one and is not going to repeat.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHERE IT IS REGISTERED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * AppServiceProvider::boot() appends it to the `web` group, beside CacheHeaders
 * and for the same two reasons that one is registered there:
 *
 *   - bootstrap/app.php cannot ship. It is on BuildPackage::NEVER_SHIP and on
 *     UpdateGuard's forbidden list, so a registration written there reaches
 *     this server only if somebody edits the file by hand. That is how
 *     CacheHeaders sat complete, tested and called by nothing for a whole lane,
 *     and how /ar 404'd for a release. app/ ships.
 *   - It sets a header on a finished response, so it wants to run LAST, after
 *     every other middleware has stopped touching it. The `web` group is also
 *     what keeps it off `/api/*` entirely, which loads through the api group.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT IT SKIPS, AND WHY EACH ONE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The switch. `sec_csp_on` SHIPS OFF, which is the rule rather than an
 * exception to it: this shop sends no CSP header today, so a new setting ships
 * at the value the page already has and applying the package moves nothing. It
 * is also the honest default for a second reason, printed on the screen beside
 * the switch — a report-only policy this shop cannot satisfy yet turns one page
 * view into one page view plus a handful of violation POSTs, and that is a real
 * cost on a shared plan. The owner turns it on when he wants the measurement,
 * with the number in front of him.
 *
 * The admin console. `resources/views/admin/app.blade.php` is a single 1.1 MB
 * document built almost entirely of inline script and inline style; a policy
 * written for the storefront would report thousands of violations from the one
 * screen the owner is reading them on, and drown the storefront's. The console
 * wants its own policy and its own round, and a screen that reported neither
 * honestly would be worse than one that says which it covers. It is skipped by
 * ROUTE NAME rather than by path, because the console's path is the secret
 * `admin_path` setting — matching on that would be a settings read on every
 * request, and would silently stop matching the day the owner changes it.
 *
 * Anything that is not HTML. A policy on a JSON response, an image, a PDF
 * invoice or a CSV download is a header no browser will ever act on, and on
 * this shop the invoices and the export downloads are the bulk of them.
 */
final class CspHeaders
{
    public function __construct(
        private SecurityModule $security,
        private ContentSecurityPolicy $policy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($this->applies($request, $response)) {
                $response->headers->set($this->policy->headerName(), $this->policy->header());
            }
        } catch (\Throwable) {
            /*
             * A policy that cannot be built is a page with no policy, never a
             * page with no page. The whole module is written this way —
             * SecurityModule::record() swallows for the same reason — and it
             * matters more here than anywhere else in it, because this is the
             * one piece of the module that runs on the storefront's own
             * response path.
             */
        }

        return $response;
    }

    /**
     * Whether this response should carry the policy.
     *
     * Ordered cheapest-first, and the order is load-bearing on a shared plan:
     * the two free tests (the route name, the content type) run before the one
     * that reads a setting, so the admin console and every JSON response in the
     * console leave this method having touched no cache and no database at all.
     */
    private function applies(Request $request, Response $response): bool
    {
        // The console and everything named under it — see the class docblock.
        if ($request->routeIs('admin') || $request->routeIs('admin.*')) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        if (! str_contains(strtolower($type), 'text/html')) {
            return false;
        }

        /*
         * ONE SETTING READ, and only for a storefront HTML response.
         *
         * SecurityModule::get() reads that one key rather than building the
         * whole schema, so this is a single hit on the settings map that every
         * storefront page has already loaded — no query, and nothing this
         * project's query budget can see. Measured by
         * SecurityCspTest rather than asserted here.
         */
        return (bool) $this->security->get('csp_on');
    }
}
