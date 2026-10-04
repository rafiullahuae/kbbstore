<?php

declare(strict_types=1);

use App\Services\Seo\BrandRename;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Lane BR — the owner, 4 October: "please replace the word Extra Beauty >
 * K-Beauty Bliss everywhere ... on backend, frontend, in emails, footers".
 *
 * The visible "Extra Beauty" on the live shop is STORED text (the store name,
 * the SEO site name, mail From name, footer and email copy), not code. This
 * replaces it in brand-bearing columns only — App\Services\Seo\BrandRename::
 * TARGETS is the allowlist and its class comment says what is never read
 * (orders, customers, payments, reviews, product descriptions, import history).
 *
 * Idempotent: the replacement never matches the pattern, so a second run
 * changes nothing. Never fails the update: BrandRename catches per table, and
 * this catches anything that escapes it. The count per table/key goes to the
 * log and, when run from the console, to the output.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $r = BrandRename::apply();
        } catch (\Throwable $e) {
            Log::warning('Brand rename migration skipped: '.$e->getMessage());

            return;
        }

        if (app()->runningInConsole()) {
            $per = [];
            foreach ($r['rows'] as $row) {
                $k = $row['table'] === 'settings' ? 'settings.'.$row['label'] : $row['table'].'.'.$row['column'];
                $per[$k] = ($per[$k] ?? 0) + $row['count'];
            }
            echo 'Extra Beauty -> K-Beauty Bliss: '.$r['total'].' replaced'
                .($per === [] ? '' : ' ('.implode(', ', array_map(fn ($k, $n) => "$k: $n", array_keys($per), $per)).')')
                .', '.count($r['left']).' left on purpose'
                .($r['errors'] === [] ? '' : ', '.count($r['errors']).' tables skipped').".\n";
        }
    }

    public function down(): void
    {
        // Not reversible by design: the old name is what the owner asked to be rid of.
    }
};
