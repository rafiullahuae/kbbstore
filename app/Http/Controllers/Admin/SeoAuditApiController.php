<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SeoAudit;
use Illuminate\Http\JsonResponse;

/**
 * Store → SEO & Meta → SEO Audit.
 *
 * Reports the defects on the shop's indexable surface that suppress a result
 * rather than merely weaken it — duplicate titles, unsafe canonical
 * overrides, missing descriptions and images, products with no identifier.
 * See App\Support\SeoAudit for what each finding means and why it is here.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS AN ADMIN ENDPOINT AND NOT AN /api/* ONE
 * ---------------------------------------------------------------------------
 *
 * /api/* in this application is unauthenticated by design, and this response
 * is a map of the shop's weak points: which products have no description,
 * which pages Google is ignoring, what the catalogue is called internally. In
 * a competitor's hands that is a content plan. It is also a full catalogue
 * scan per call, so unauthenticated it would be free amplification.
 *
 * It therefore lives in the EXISTING admin-api group — `web`, `auth:admin`,
 * NoStoreAdminApi — under its own capability, and it is throttled on top of
 * that. See routes/seo-audit-admin.php.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS IN THE RESPONSE, AND WHAT IS DELIBERATELY NOT
 * ---------------------------------------------------------------------------
 *
 * Each sample row is FOUR keys built here by hand — kind, name, url, and an
 * optional human-readable `detail` — and never a model, a row object or a
 * `->toArray()`. That is the same rule CLAUDE.md records for /api/*, applied
 * here on purpose even though this route is authenticated: `products` carries
 * `wc_id`, `sku` and `total_sales`, and an audit screen has no business
 * handing any of them to the browser. An allowlist that is built rather than
 * filtered cannot leak a column added next year.
 *
 * No id is returned either. The screen links by URL, which is what the operator
 * needs to go and look at the page; an id would only be useful for a bulk
 * action, and this screen deliberately has none yet — the same first-version
 * restraint CatalogueAuditApiController documents.
 */
class SeoAuditApiController extends Controller
{
    public function scan(): JsonResponse
    {
        $report = SeoAudit::run();

        return response()->json([
            'ok' => true,
            'total' => $report['total'],
            'scanned' => $report['scanned'],
            'verdict' => $report['verdict'],
            'findings' => $report['findings'],
        ]);
    }
}
