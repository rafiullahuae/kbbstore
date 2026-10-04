<?php
// Lane HS preview seed: the shared catalogue, three tagged journal articles
// (so the homepage Blog cards have something to drop), and the preview owner.
require __DIR__.'/rf-seed.php';
foreach ([['Routines', 'How to double cleanse the Korean way'], ['Ingredients', 'Centella, explained'], ['SPF', 'Choosing a daily sunscreen']] as $i => [$tag, $title]) {
    \App\Models\Post::updateOrCreate(['slug' => 'hs-preview-'.$i], [
        'title' => $title, 'tag' => $tag, 'excerpt' => 'A short guide from the K-Beauty Bliss journal to help you choose and use Korean skincare.',
        'body' => str_repeat('word ', 900 + $i * 400), 'status' => 'published', 'published_at' => now()->subDays($i + 1),
    ]);
}
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);
echo "hs seed done\n";
