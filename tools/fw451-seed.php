<?php
/*
 * Lane FW preview seed: an owner account for the admin shots and a post for
 * the blog page. The catalogue is DemoCatalogueSeeder's. Preview database only.
 */
use App\Models\AdminUser;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

try {
    \App\Models\Post::updateOrCreate(['slug' => 'fw-routine'], ['title' => 'A five-step evening routine', 'status' => 'publish',
        'body' => '<p>Cleanse, tone, treat, moisturise, protect.</p>', 'published_at' => now()->subDay()]);
} catch (\Throwable $e) {
    echo 'post: '.$e->getMessage()."\n";
}

\App\Services\Security\IpBlockList::rebuild();
echo "seeded\n";
