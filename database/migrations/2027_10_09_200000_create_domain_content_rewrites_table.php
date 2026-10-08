<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lane DS: the undo record of Platform -> Domain switch -> "Old links in the
 * shop's text". One row per changed cell, with the whole value before and
 * after, so the change can be walked back cell by cell -- and only where the
 * cell still says exactly what the rewrite wrote (App\Services\DomainMove\
 * ContentRewrite::undo()). Listed in DomainReadiness::HISTORY: the "before"
 * column names the old domain on purpose and is never a finding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('domain_content_rewrites')) {
            return;
        }

        Schema::create('domain_content_rewrites', function (Blueprint $t) {
            $t->id();
            $t->string('batch', 40)->index();
            $t->string('table_name', 64);
            $t->string('key_column', 64);
            $t->string('row_key', 191);
            $t->string('column_name', 64);
            $t->longText('before')->nullable();
            $t->longText('after')->nullable();
            $t->unsignedInteger('links')->default(0);
            $t->timestamp('undone_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_content_rewrites');
    }
};
