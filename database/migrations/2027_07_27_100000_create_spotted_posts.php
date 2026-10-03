<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #KBeautyBliss Spotted — the Instagram posts the owner picks by hand. (Lane HB)
 *
 * Master plan row 55, item 4: "with instagram feed make it carousel, but with
 * manual selection … button to a new page /kbeautybliss-spotted with a manually
 * selected IG grid". `instagram_posts` (Lane IG) is a mirror of OUR OWN account
 * fetched through Meta's API; a Spotted post is usually somebody else's — a
 * customer who tagged the shop — and is typed in, so it is its own table rather
 * than a flag on rows a sync rewrites.
 *
 * Every column is operator input and is re-checked on the way out
 * (App\Models\SpottedPost::toCard()), not only on the way in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('spotted_posts')) {
            return;
        }

        Schema::create('spotted_posts', function (Blueprint $t) {
            $t->id();
            // A picture from the media library: a path on this shop or an https URL.
            $t->string('image', 500);
            $t->string('image_alt', 200)->nullable();
            // https://www.instagram.com/… or https://instagram.com/… only.
            $t->string('ig_url', 500)->nullable();
            // Stored without the leading @.
            $t->string('handle', 40);
            $t->string('caption', 160)->nullable();
            $t->unsignedBigInteger('product_id')->nullable()->index();
            // 'instagram' or 'product': where the whole card goes when tapped.
            $t->string('link_to', 16)->default('instagram');
            // NULL = the owner entered none, and then NOTHING is drawn. Never 0.
            $t->unsignedInteger('likes')->nullable();
            $t->integer('sort')->default(0);
            $t->boolean('on_home')->default(true);
            $t->boolean('on_page')->default(true);
            $t->timestamps();

            $t->index(['sort', 'id'], 'spotted_posts_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spotted_posts');
    }
};
