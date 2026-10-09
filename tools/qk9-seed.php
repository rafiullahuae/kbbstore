<?php

declare(strict_types=1);

/*
 * Lane QK9: tools/qk8-seed.php's shop (four gateways, Arabic on) plus seven
 * approved, real (non-demo) reviews so the checkout's "4.x from N reviews"
 * card has something to say -- it draws nothing below StoreRating::MINIMUM,
 * and the "before" shot has to show the card the owner crossed out. Run
 * through tools/qk9-preview.sh; never against a real database.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/qk8-seed.php';

$pid = DB::table('products')->value('id');
foreach ([5, 5, 5, 4, 5, 5, 5] as $i => $stars) {
    DB::table('reviews')->insert(['source' => 'sorina', 'product_id' => $pid, 'author_name' => 'Preview '.$i, 'rating' => $stars,
        'content' => 'Preview review', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
}
\Illuminate\Support\Facades\Cache::flush();
echo "qk9 preview seeded\n";
