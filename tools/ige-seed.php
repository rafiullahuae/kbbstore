<?php
/*
 * Seed the Lane IGE preview: the demo catalogue (rf-seed), an owner account
 * for the admin screenshots, six pasted Instagram addresses (four posts, two
 * reels; made-up codes in Instagram's alphabet) on the homepage row, and a
 * content page carrying [kbb_instagram_embeds]. Written into the PREVIEW's
 * database only; nothing here reaches a package.
 */
require __DIR__.'/rf-seed.php';

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$paste = implode("\n", [
    'https://www.instagram.com/p/DAbcPost001/?igsh=abc',
    'https://www.instagram.com/reel/C9ReelOne01/',
    'https://www.instagram.com/kbeauty.bliss/p/DAbcPost002/',
    'https://www.instagram.com/p/DAbcPost003/',
    'instagram.com/reels/C9ReelTwo02/',
    'https://www.instagram.com/tv/DAbcPost004/',
]);
$parsed = \App\Services\InstagramEmbeds::parseMany($paste);
$items = $parsed['items'];
$items[0]['l'] = 'Glass-skin routine';
$items[1]['l'] = 'Unboxing: sunscreen haul';
app(\App\Services\InstagramEmbeds::class)->save(['style' => getenv('IGE_STYLE') ?: 'clean'], $items);

if (getenv('IGE_EMPTY')) {
    app(\App\Services\InstagramEmbeds::class)->save([], []);
}

// A content page carrying the shortcode: the Delivery policy page (seeded by a
// migration), with the section appended, in the preview only.
$page = \App\Models\Page::query()->where('slug', 'delivery')->first();
if ($page) {
    $page->content = (string) $page->content.'[kbb_instagram_embeds layout="slider" max="4" title="Seen on Instagram"]';
    $page->save();
}

echo 'ige seed: '.count($items).' embeds, refused '.count($parsed['refused'])."\n";
