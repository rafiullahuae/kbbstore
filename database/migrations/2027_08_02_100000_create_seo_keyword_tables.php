<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SEO → Keywords (Lane KW). Four small tables, each guarded so a re-applied
 * package is a no-op, and none of them touching a table another lane owns.
 *
 *   seo_keywords        the keyword BANK: phrases real sources returned —
 *                       Search Console, Google Autocomplete, this shop's own
 *                       search box, the curated lexicon. One row per
 *                       term+locale.
 *   seo_page_keywords   what each page publishes: its four layers, the
 *                       flattened list (≤10), its PRIMARY keyword, the
 *                       title/description SUGGESTIONS and the row it replaced
 *                       (`previous`) so the last sync can be undone.
 *                       (locale, primary_kw) is UNIQUE: one page owns a
 *                       primary keyword, which is the no-cannibalisation rule
 *                       enforced by the database rather than by hope. Both
 *                       engines allow many NULLs under a unique index.
 *   seo_keyword_runs    one row per sync / dry run: cursor, counts, report —
 *                       what makes a sync resumable in small requests.
 *   seo_keyword_config  source switches and the Search Console key. The key is
 *                       encrypted with Crypt and lives HERE, not in `settings`,
 *                       because GET /admin-api/settings returns that table
 *                       wholesale.
 *
 * String lengths: utf8mb4 × (160 + 5) = 660 bytes for the widest unique key,
 * well inside MySQL's 3072.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_keywords')) {
            Schema::create('seo_keywords', function (Blueprint $t) {
                $t->id();
                $t->string('term', 160);
                $t->string('locale', 5)->default('en');
                $t->string('source', 16);           // gsc | autocomplete | site | lexicon
                $t->unsignedInteger('score')->default(0);
                $t->text('metrics')->nullable();    // JSON
                $t->timestamp('fetched_at')->nullable();
                $t->unique(['term', 'locale']);
                $t->index(['locale', 'score']);
            });
        }

        if (! Schema::hasTable('seo_page_keywords')) {
            Schema::create('seo_page_keywords', function (Blueprint $t) {
                $t->id();
                $t->string('entity_type', 16);     // product | category | brand | collection | page | post
                $t->string('entity_id', 64);
                $t->string('locale', 5)->default('en');
                $t->text('layers')->nullable();     // JSON {own,product,industry,skincare}
                $t->text('keywords')->nullable();   // JSON list, ≤10
                $t->string('primary_kw', 160)->nullable();
                $t->boolean('locked')->default(false);
                $t->text('suggest')->nullable();    // JSON {title, desc}
                $t->text('links')->nullable();      // JSON [[term, path]] — Popular searches
                $t->string('clash', 200)->nullable();
                $t->unsignedBigInteger('run_id')->nullable();
                $t->longText('previous')->nullable();
                $t->timestamp('generated_at')->nullable();
                $t->unique(['entity_type', 'entity_id', 'locale']);
                $t->unique(['locale', 'primary_kw']);
                $t->index('run_id');
            });
        }

        if (! Schema::hasTable('seo_keyword_runs')) {
            Schema::create('seo_keyword_runs', function (Blueprint $t) {
                $t->id();
                $t->boolean('dry')->default(false);
                $t->string('status', 12)->default('running'); // running | done | failed | undone
                $t->string('phase', 12)->default('bank');      // bank | compose | done
                $t->text('scope')->nullable();
                $t->text('cursor')->nullable();
                $t->text('counts')->nullable();
                $t->mediumText('report')->nullable();
                $t->unsignedBigInteger('started_by')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
                $t->index(['dry', 'status']);
            });
        }

        if (! Schema::hasTable('seo_keyword_config')) {
            Schema::create('seo_keyword_config', function (Blueprint $t) {
                $t->string('name', 32)->primary();
                $t->text('value')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keyword_config');
        Schema::dropIfExists('seo_keyword_runs');
        Schema::dropIfExists('seo_page_keywords');
        Schema::dropIfExists('seo_keywords');
    }
};
