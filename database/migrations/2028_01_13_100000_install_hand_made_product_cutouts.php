<?php

declare(strict_types=1);

use App\Services\Cutouts\ProductCutouts;
use App\Services\SettingsService;
use App\Support\OccasionBanner;
use Illuminate\Database\Migrations\Migration;

/**
 * Hand-made product cut-outs for the Occasion banner and the Free gifts strip
 * (Lane CUT; the owner, 6 November 2026, on 2.60.649's automatic ones:
 * "completely shit results").
 *
 * A package cannot write into the web root outside public/build/ (UpdateGuard),
 * and img-cache is never shipped, so the cut-outs travel as source files in
 * resources/cutouts/{240,480}/<sha1 of the photo path>.webp, listed in
 * resources/cutouts/index.json. This copies each one over the automatic
 * cut-out at public_path('img-cache/cut/...') -- the path the shop already
 * serves -- unless the owner has uploaded his own for that photo or chosen
 * "Use automatic". From then on "Prepare cut-outs" / "Re-cut" / "Remake" put
 * the hand-made one back instead of cutting over it (ProductCutouts::make()).
 *
 * THE BANNER'S SNAPSHOT is refreshed for the photos installed: it stores each
 * cut-out's widths and its file time, and a hand-made cut-out has a different
 * width (and a new time, so a visitor's year-long cached copy of the
 * automatic one is not reused). The gift strip reads the disk; nothing to do.
 *
 * Skipped under a test run (it would write into the web root on every
 * migrate). Safe to run again: it copies the same files and rewrites the same snapshot.
 * NO SCHEMA CHANGE.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A test run migrates a fresh database every time; it must not write
        // into the web root. ProductCutoutsHandMadeTest drives installShipped().
        if (app()->runningUnitTests()) {
            return;
        }

        $done = ProductCutouts::installShipped();

        $settings = app(SettingsService::class);
        $c = OccasionBanner::config([OccasionBanner::KEY => $settings->get(OccasionBanner::KEY)]);
        $changed = false;
        foreach ($c['pool'] as $i => $item) {
            $rel = ProductCutouts::relative((string) $item['image']);
            if ($rel !== null && in_array($rel, $done, true)) {
                $c['pool'][$i]['cut'] = OccasionBanner::cutSnapshot((string) $item['image']);
                $changed = true;
            }
        }
        if ($changed) {
            $settings->set(OccasionBanner::KEY, $c);
        }

        if (app()->runningInConsole()) {
            echo 'Installed '.count($done)." hand-made product cut-outs.\n";
        }
    }

    public function down(): void {}
};
