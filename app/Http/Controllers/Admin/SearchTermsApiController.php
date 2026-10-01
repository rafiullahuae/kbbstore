<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SearchTermsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET admin-api/search-terms -- Growth & Marketing -> Search Terms.
 *
 * Read-only, behind its own capability (`search_terms.view`, owner and
 * manager) in AdminCapabilities::RULES, so it fails closed for every other
 * role. Every input is held to its own allowlist or bounds before it reaches
 * the report; the report returns terms, counts and dates and nothing else.
 */
class SearchTermsApiController extends Controller
{
    public function index(Request $request, SearchTermsReport $report): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:'.implode(',', array_keys(SearchTermsReport::PERIODS))],
            'show' => ['nullable', 'string', 'in:'.implode(',', SearchTermsReport::SHOW)],
            'find' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'partials' => ['nullable', 'boolean'],
        ]);

        return response()->json($report->build(
            (string) ($data['period'] ?? '7d'),
            (string) ($data['show'] ?? 'all'),
            (string) ($data['find'] ?? ''),
            (int) ($data['page'] ?? 1),
            (bool) ($data['partials'] ?? false),
        ));
    }
}
