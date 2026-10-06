<?php
/*
 * Seed the Lane BH preview: the shop's demo catalogue (so the shared header has
 * its menus, the mega menus have categories and brands to show) plus six
 * Journal articles, so /blog/ has a grid and an article has related posts.
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\Post;


$body = '<p>Double cleansing is the first step of a Korean routine: an oil cleanser lifts sunscreen and make-up, '
    . 'a gentle foam cleanser takes the rest. Done well it leaves skin clean but never tight.</p>'
    . '<h3>Why the order matters</h3><p>Oil dissolves oil. Starting with water leaves the sunscreen film on, '
    . 'and the second cleanser then has to work twice as hard.</p><p>Finish with a hydrating toner while skin is still damp.</p>';

$tags = ['Routine', 'Ingredients', 'SPF', 'News', 'Routine', 'SPF'];
foreach (['The double-cleanse, explained', 'Centella asiatica: the calm-skin hero', 'How much sunscreen is enough?',
          'What is new in K-beauty this autumn', 'A five-minute morning routine', 'Reapplying SPF over make-up'] as $i => $title) {
    Post::query()->updateOrCreate(['slug' => \Illuminate\Support\Str::slug($title)], [
        'title' => $title,
        'excerpt' => 'Honest, practical guidance for skin in the UAE climate - ' . strtolower($title) . '.',
        'body' => $body,
        'tag' => $tags[$i],
        'author' => 'K-Beauty Bliss',
        'status' => 'published',
        'published_at' => now()->subDays($i + 1),
    ]);
}
echo "bh seed: " . Post::count() . " posts\n";
