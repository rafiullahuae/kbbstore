<?php
/*
 * Lane PH (brand-design page header) preview seed: tools/cb-seed.php's catalogue, brands and categories
 * (so the brand and category pages can be measured unchanged), plus one
 * published article so the Journal index has a card. The content pages are
 * the ones the migrations seed (About, Contact, Delivery, Returns, Terms, ...).
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
require __DIR__.'/cb-seed.php';

\App\Models\Post::query()->updateOrCreate(['slug' => 'ph-walk-article'], [
    'title' => 'How to build a simple K-beauty routine', 'excerpt' => 'Four steps, morning and night.',
    'body' => '<p>Cleanse, tone, treat, protect.</p>', 'tag' => 'Routine', 'status' => 'published', 'published_at' => now()->subDay(),
]);

\App\Models\Setting::flushMap();
echo "ph seed done\n";
