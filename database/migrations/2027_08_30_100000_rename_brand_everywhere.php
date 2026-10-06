<?php

declare(strict_types=1);

use App\Services\Seo\BrandRename;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Lane EB — the owner, 6 October: "remove the Extra Beauty word from whole
 * site from everywhere. please apply this auto in the next patch ... replace
 * the Extra Beauty with K-Beauty Bliss everywhere."
 *
 * 2027_08_09_100000 (Lane BR) ran the rename over the brand-bearing settings
 * and content. The name the owner still sees lives in what that one left out:
 * product names and descriptions, image alt text, category and brand copy,
 * reviews, the checkout's shipping and payment titles and the rest that
 * BrandRename::TARGETS now lists. This runs the widened rename once.
 *
 * The rows the first run already did are not touched again (the replacement
 * never matches the pattern), so this is idempotent and a second apply
 * reports 0. Hostnames, emails, handles and legal entity names are left by
 * BrandName::OLD; records (orders, customers, mail logs, import history) are
 * not on the list. Never fails the update: BrandRename catches per table, and
 * this catches anything that escapes it. Clears the content caches itself
 * (BrandRename::flush) because `php artisan migrate --force` from a shell does
 * not run cache:clear the way Core Updates does.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $r = BrandRename::apply();
        } catch (\Throwable $e) {
            Log::warning('Brand rename (everywhere) skipped: '.$e->getMessage());

            return;
        }

        if (app()->runningInConsole()) {
            $per = [];
            foreach ($r['areas'] ?? [] as $a) {
                if ($a['found'] > 0) {
                    $per[] = $a['found'].' in '.$a['table'];
                }
            }
            echo 'Extra Beauty -> K-Beauty Bliss everywhere: '.$r['total'].' replaced'
                .($per === [] ? '' : ' ('.implode(', ', $per).')')
                .', '.count($r['left']).' left on purpose'
                .($r['errors'] === [] ? '' : ', '.count($r['errors']).' tables skipped').".\n";
        }
    }

    public function down(): void
    {
        // Not reversible by design: the old name is what the owner asked to be rid of.
    }
};
