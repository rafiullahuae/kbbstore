<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Redirect;

/**
 * Creates redirects automatically when a published product or post's slug
 * changes — the one thing most likely to quietly break real, indexed links
 * during a routine content edit, not just during the WordPress migration.
 *
 * Collapses chains: if some other redirect already points at the old path
 * being redirected away from (A → oldPath), it's repointed straight to the
 * new path (A → newPath) instead of left chaining through it. A→B→C works
 * in a browser, but is worse for SEO than a single A→C hop, and left
 * unchecked, a slug that changes several times over a product's life
 * quietly grows a chain one link at a time.
 */
class RedirectManager
{
    public static function autoCreate(string $fromPath, string $toPath): void
    {
        if ($fromPath === $toPath) {
            return;
        }

        try {
            Redirect::query()->updateOrCreate(
                ['source' => $fromPath],
                ['target' => $toPath, 'code' => 301, 'enabled' => true, 'auto_created' => true]
            );

            // Repoint anything that was chaining through the old path.
            Redirect::query()
                ->where('target', $fromPath)
                ->where('source', '!=', $toPath)
                ->update(['target' => $toPath]);
        } catch (\Throwable $e) {
            // A redirect-bookkeeping failure must never block the actual
            // product/post save that triggered it.
        }
    }
}
