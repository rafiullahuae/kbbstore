<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content -> HTML Blocks: the table the Block model has always expected.
 * (Lane BC)
 *
 * app/Models/Block.php has been in this repo since the Phase 0 baseline and
 * nothing has ever referenced it, because there was no `blocks` table for it
 * to read. Establishing that, rather than assuming it: the model's own file is
 * four lines with no $table, so Eloquent resolves it to `blocks`; no
 * Schema::create('blocks', ...) exists anywhere under database/migrations; and
 * the string "blocks" does not appear in that directory at all. The model was
 * a promise of a screen that was never built.
 *
 * COLUMN CHOICES, each one read by something.
 *
 *  - `slug` is the handle a shortcode names, so it is unique and indexed by
 *    that uniqueness. It is what App\Support\Shortcodes::render() looks up for
 *    [kbb_block slug="..."]; nothing else identifies a block in content.
 *  - `name` is the label the admin list sorts and searches on. It never
 *    reaches the storefront.
 *  - `content` is the HTML, longText to match pages.content — a block holds
 *    the same kind of markup a page holds and must not be the one that
 *    truncates.
 *  - `status` is 'published' or 'draft', indexed because the shortcode filters
 *    on it on every storefront render. A draft block renders nothing at all,
 *    which is the only way to take a block off the site without deleting it
 *    or editing every page that names it.
 *
 * Timestamps are real timestamps, per the note at the top of the Phase 0
 * schema about the original string columns.
 *
 * No AFTER clause anywhere — see the standing note in this directory about the
 * nine migrations that were silent no-ops on MySQL because of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('blocks')) {
            return;
        }

        Schema::create('blocks', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->longText('content')->nullable();
            $t->string('status')->default('draft')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
    }
};
