<?php

declare(strict_types=1);

use App\Support\UrlScheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repoint the stored redirects the address scheme would otherwise turn into a
 * chain — and delete the five that would have become an infinite loop.
 *
 * =============================================================================
 * THE LOOP, WHICH IS WHY THIS FILE IS NOT OPTIONAL TIDYING
 * =============================================================================
 *
 * `2026_09_14_160000_seed_phase9_post_url_redirects` seeded ten rows for the
 * five articles the owner confirmed:
 *
 *     /blog/{slug}/   ->  /{slug}/
 *     /blog/{slug}    ->  /{slug}/
 *
 * `/blog/{slug}/` is now the article's CANONICAL address, and `CheckRedirects`
 * runs in the global pipeline BEFORE the router. Left alone, those rows send the
 * canonical address to the site root, and `PageController::rootArticle()` sends
 * the site root straight back to `/blog/{slug}/`:
 *
 *     /blog/x/  --301 (row)-->  /x/  --301 (route)-->  /blog/x/  -- ...
 *
 * A browser gives up with ERR_TOO_MANY_REDIRECTS and a crawler drops the page.
 * `CheckRedirects::loops()` does not catch it: it walks the redirects TABLE, and
 * the second hop is a route, which the walk cannot see. Five of this shop's
 * articles would have been unreachable at their own address the moment the
 * package applied.
 *
 * =============================================================================
 * AND THE CHAINS, WHICH ARE THE SAME DEFECT ONE DEGREE MILDER
 * =============================================================================
 *
 * Every other stored row pointing at an address the scheme moved would still
 * resolve — through a second hop. `RedirectManager` collapses chains at write
 * time precisely because two hops leak ranking and burn crawl budget, and a
 * migration that moves the destinations without collapsing what points at them
 * writes the chain it exists to prevent. Four destinations moved:
 *
 *     /{slug}/                  ->  /blog/{slug}/     for a published article
 *     /product-category/{path}/ ->  /collections/{path}/
 *     /korean-skincare-brands/… ->  /brands/…
 *     /skincare-guide/          ->  /blog/
 *
 * =============================================================================
 * WHAT IT WILL NOT TOUCH
 * =============================================================================
 *
 * A row whose SOURCE the scheme now serves, other than the seeded /blog/ ones,
 * is left exactly as it is. This migration owns destinations; a source is a
 * decision somebody made on Store -> SEO & Meta -> Redirects & 404s, and
 * overruling one from a migration would be a change to the shop nobody asked
 * for and nobody could see.
 *
 * A row pointing at `/{slug}/` where no PUBLISHED article carries that slug is
 * also left alone. It is a WordPress page, or an address the owner pointed
 * somewhere by hand, and rewriting it to /blog/ would send a visitor to a 404.
 * The check is a join against `posts`, not a guess from the shape of the path.
 *
 * =============================================================================
 * IDEMPOTENT, AND SELF-CHECKING
 * =============================================================================
 *
 * Every write is keyed on the value it is replacing, so a second run finds
 * nothing to do. The last pass deletes any row left pointing at its own source —
 * which a repoint can create, when a row already read `/x/ -> /blog/x/` and
 * another read `/blog/x/ -> /x/` — because a self-redirect is an address
 * CheckRedirects refuses to follow and therefore a row that silently does
 * nothing.
 *
 * down() is deliberately empty. The reverse of this is the loop, and a
 * migration that can restore an outage on a rollback is not a safety net.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redirects')) {
            return;
        }

        $loops = $this->deleteSeededArticleLoops();
        $moved = $this->repointArticleTargets()
            + $this->repointPrefix(UrlScheme::LEGACY_COLLECTION_BASE, UrlScheme::COLLECTION_BASE)
            + $this->repointPrefix(UrlScheme::LEGACY_BRAND_INDEX, UrlScheme::BRAND_BASE)
            + $this->repointExact(UrlScheme::LEGACY_BLOG_INDEX, UrlScheme::BLOG_BASE);
        $selfPointing = $this->deleteSelfPointing();

        if (app()->runningInConsole()) {
            echo "Address scheme: removed {$loops} rows that would have looped, ";
            echo "repointed {$moved} onto their final address, ";
            echo "and removed {$selfPointing} left pointing at themselves.\n";
        }
    }

    public function down(): void {}

    /**
     * The seeded `/blog/{slug}/ -> /{slug}/` rows.
     *
     * ── MATCHED ON SHAPE, NOT ON THE `posts` TABLE, AND THAT IS THE FIX ────
     *
     * The first version of this method looked the slugs up in `posts` and
     * deleted a row only for a published article. It is correct on the live
     * shop, where the articles exist when the package applies, and WRONG on a
     * fresh install: `migrate` runs 2026_09_14_160000 (which writes the ten
     * rows) and then this file, with the `posts` table still empty — so nothing
     * matched, nothing was deleted, and the first article imported afterwards
     * was unreachable at its own canonical address for ever. Found by the
     * suite, which migrates from nothing on every run.
     *
     * The shape is unambiguous on its own and needs no table: a row whose
     * source is `/blog/{x}/` and whose target is `/{x}/` points the article's
     * CANONICAL address at an address that redirects straight back to it. There
     * is no reading of that row in which it is wanted.
     *
     * A row under /blog/ with any OTHER target is left alone — `/blog/old/ ->
     * /blog/new/` is a perfectly good redirect somebody may write later, and
     * this must not be the thing that deletes it.
     *
     * Deleted rather than repointed: repointing gives `/blog/x/ -> /blog/x/`,
     * which deleteSelfPointing() would remove anyway, and saying it once is
     * clearer than saying it twice.
     */
    private function deleteSeededArticleLoops(): int
    {
        $removed = 0;

        $rows = DB::table('redirects')
            ->where('source', 'like', UrlScheme::BLOG_BASE.'%')
            ->get(['id', 'source', 'target']);

        foreach ($rows as $row) {
            $slug = trim(substr((string) $row->source, strlen(UrlScheme::BLOG_BASE)), '/');

            if ($slug === '' || str_contains($slug, '/')) {
                continue;
            }

            if (! in_array(trim((string) $row->target, '/'), [$slug], true)) {
                continue;
            }

            $removed += DB::table('redirects')->where('id', $row->id)->delete();
        }

        return $removed;
    }

    /** `/{slug}/` targets that are now `/blog/{slug}/`. */
    private function repointArticleTargets(): int
    {
        $moved = 0;

        foreach ($this->articleSlugs() as $slug) {
            $moved += DB::table('redirects')
                ->whereIn('target', ['/'.$slug.'/', '/'.$slug])
                ->update(['target' => UrlScheme::article($slug), 'updated_at' => now()]);
        }

        return $moved;
    }

    /**
     * Every target under one retired base, moved onto the new one.
     *
     * A per-row rewrite rather than a SQL string function: `SUBSTRING` and
     * `REPLACE` are spelled differently on SQLite and MySQL and this shop runs
     * both, and the row count here is in the hundreds at most.
     */
    private function repointPrefix(string $from, string $to): int
    {
        $moved = 0;

        $rows = DB::table('redirects')
            ->where('target', 'like', $from.'%')
            ->get(['id', 'target']);

        foreach ($rows as $row) {
            $target = $to.substr((string) $row->target, strlen($from));

            $moved += DB::table('redirects')
                ->where('id', $row->id)
                ->update(['target' => $target, 'updated_at' => now()]);
        }

        return $moved;
    }

    /** One exact destination, in both of its slash spellings. */
    private function repointExact(string $from, string $to): int
    {
        return DB::table('redirects')
            ->whereIn('target', [$from, rtrim($from, '/')])
            ->update(['target' => $to, 'updated_at' => now()]);
    }

    /**
     * A row that points at its own source does nothing — CheckRedirects refuses
     * to follow one — and a repoint is exactly the operation that can create it.
     */
    private function deleteSelfPointing(): int
    {
        $removed = 0;

        foreach (DB::table('redirects')->get(['id', 'source', 'target']) as $row) {
            if (rtrim((string) $row->source, '/') === rtrim((string) $row->target, '/')) {
                $removed += DB::table('redirects')->where('id', $row->id)->delete();
            }
        }

        return $removed;
    }

    /**
     * The slugs of every PUBLISHED article, which is what makes `/{slug}/` an
     * address the scheme moved rather than a WordPress page or a hand-written
     * row.
     *
     * @return list<string>
     */
    private function articleSlugs(): array
    {
        if (! Schema::hasTable('posts')) {
            return [];
        }

        $query = DB::table('posts')->whereNotNull('slug');

        if (Schema::hasColumn('posts', 'status')) {
            $query->where('status', 'published');
        }

        return array_values(array_filter(array_map(
            static fn ($slug): string => trim((string) $slug, '/'),
            $query->pluck('slug')->all(),
        ), static fn (string $slug): bool => $slug !== ''));
    }
};
