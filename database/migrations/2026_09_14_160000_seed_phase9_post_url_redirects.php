<?php

declare(strict_types=1);

use App\Models\Redirect;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the redirect map for the Phase 9 article-URL decision.
 *
 * The owner confirmed that blog posts live at the site root, one slug per
 * post, with no prefix:
 *
 *   https://kbeautybliss.com/heartleaf-extract-transforming-k-beauty-skincare/
 *
 * This app had invented /skincare-guide/{slug}/ instead, and /blog/{slug}/
 * before that. The /skincare-guide/ form is handled by a route
 * (PageController::legacyPost) because it has to cover every post, including
 * ones written after this migration ran. /blog/{slug}/ has no route and never
 * will — it is a dead prefix — so the five confirmed live articles are seeded
 * here, and the existing 404 handler (AppServiceProvider → CheckRedirects)
 * serves them.
 *
 * Both slash forms are seeded for each article. CheckRedirects::findMatch
 * compares `source` against getPathInfo() with a plain equality check, so a
 * row is matched only by the exact spelling it was stored under. U-01 says
 * this site's URLs keep their trailing slash and that is the form that was
 * indexed, but a link shared without one is not a hypothetical — and one extra
 * row per article is a much smaller price than a 404 on a URL somebody
 * actually posted.
 *
 * Idempotent: keyed on `source`, which is unique, so re-running writes the
 * same values. `hits` and `last_hit_at` are left alone — they belong to the
 * runtime, not to the seed.
 */
return new class extends Migration
{
    /** The five articles the owner confirmed live at the root. */
    private const POST_SLUGS = [
        'k-beauty-bliss-a-beginners-guide-to-korean-skincare',
        'k-beauty-face-masks-the-ultimate-guide-to-relaxing-and-rejuvenating-at-home',
        'k-beauty-bliss-how-to-repair-a-damaged-skin-barrier-with-k-beauty',
        'k-beauty-bliss-10-best-korean-moisturizers-for-sensitive-skin',
        'heartleaf-extract-transforming-k-beauty-skincare',
    ];

    public function up(): void
    {
        foreach ($this->map() as $source => $target) {
            Redirect::query()->updateOrCreate(
                ['source' => $source],
                [
                    'target' => $target,
                    'code' => 301,
                    'enabled' => true,
                    // Not auto_created: a human decision about a URL move, not
                    // bookkeeping from a slug edit. The admin's Redirects
                    // screen uses this to tell the two apart.
                    'auto_created' => false,
                ]
            );
        }
    }

    public function down(): void
    {
        Redirect::query()->whereIn('source', array_keys($this->map()))->delete();
    }

    /** @return array<string, string> source path => target path */
    private function map(): array
    {
        $map = [];

        foreach (self::POST_SLUGS as $slug) {
            $map['/blog/' . $slug . '/'] = '/' . $slug . '/';
            $map['/blog/' . $slug] = '/' . $slug . '/';
        }

        return $map;
    }
};
