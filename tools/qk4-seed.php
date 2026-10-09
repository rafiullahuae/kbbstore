<?php
// Lane QK4 preview seed: the shared storefront seed (Lane RF/FT), the admin the
// screenshots sign in as, and one published article so /blog/{slug}/ has a
// page to measure. Written into the PREVIEW's database only.
require __DIR__.'/ft-seed.php';
\Illuminate\Support\Facades\DB::table('posts')->updateOrInsert(['slug' => 'double-cleansing-guide'], [
    'title' => 'The double-cleansing guide', 'status' => 'published', 'published_at' => now()->subDay(),
    'excerpt' => 'Oil first, then foam.', 'body' => '<p>Oil first, then foam. '.str_repeat('Massage gently and rinse. ', 40).'</p>',
    'created_at' => now(), 'updated_at' => now(),
]);
echo "qk4 seed done\n";
