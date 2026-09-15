<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalogue Audit — scans visible products for the handful of SEO gaps
 * that genuinely matter (missing meta description, missing image, a
 * short-description too thin to build a real fallback description from)
 * and reports counts plus the worst offenders, rather than every product
 * individually. Reads the same `seo` column ProductController now
 * actually renders from — an audit checking a field nothing reads would
 * be checking the wrong thing.
 *
 * Deliberately narrow for a first version: this reports, it does not
 * bulk-fix anything yet. A bulk "auto-fill missing descriptions from the
 * short description" action is the natural next step once this is live
 * and the counts it reports are trusted, not before.
 */
class CatalogueAuditApiController extends Controller
{
    /** Below this many words, a short_description is too thin to build a real meta description from. */
    private const SHORT_DESC_MIN_WORDS = 15;

    public function scan(): JsonResponse
    {
        $products = Product::query()
            ->visible()
            ->select('id', 'name', 'slug', 'short_description', 'image', 'seo')
            ->get();

        $issues = [
            'no_description' => [],
            'no_image' => [],
            'thin_short_description' => [],
        ];

        foreach ($products as $p) {
            $override = is_array($p->seo) ? $p->seo : [];
            $hasRealDescription = !empty($override['desc'])
                || (!empty($p->short_description) && str_word_count(strip_tags($p->short_description)) >= self::SHORT_DESC_MIN_WORDS);

            if (!$hasRealDescription) {
                $issues['no_description'][] = ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug];
            }

            if (empty($p->image)) {
                $issues['no_image'][] = ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug];
            }

            $wordCount = str_word_count(strip_tags((string) $p->short_description));
            if (!empty($p->short_description) && $wordCount > 0 && $wordCount < self::SHORT_DESC_MIN_WORDS) {
                $issues['thin_short_description'][] = ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'words' => $wordCount];
            }
        }

        return response()->json([
            'ok' => true,
            'total' => $products->count(),
            'counts' => [
                'no_description' => count($issues['no_description']),
                'no_image' => count($issues['no_image']),
                'thin_short_description' => count($issues['thin_short_description']),
            ],
            // Capped per list — this reports what's worth knowing exists,
            // not every row; a catalogue with 400 products missing images
            // doesn't need 400 rows to make the point.
            'issues' => [
                'no_description' => array_slice($issues['no_description'], 0, 50),
                'no_image' => array_slice($issues['no_image'], 0, 50),
                'thin_short_description' => array_slice($issues['thin_short_description'], 0, 50),
            ],
        ]);
    }
}
