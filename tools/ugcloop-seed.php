<?php
/* Seed a preview with ONE shoppable-video clip that carries a real mp4 and a
   real poster, so the "2.5 second loop" column has something to actually play.
   Used by tools/ugcloop-check.cjs. */
\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

app(\App\Services\SettingsService::class)->setModule('shoppable_video', true);

$v = \App\Models\UgcVideo::updateOrCreate(['slug' => 'loopcheck'], [
    'title' => 'Loop check clip',
    'status' => 'publish',
    'rights_status' => 'granted',
    'file_path' => '/uploads/ugc/clip-loopcheck.mp4',
    'poster_path' => '/uploads/ugc/poster-loopcheck.jpg',
    'width' => 360,
    'height' => 640,
    'creator_handle' => '@loopcheck',
    'published_at' => now()->subDay(),
]);

$s = \App\Models\UgcSection::updateOrCreate(['handle' => 'loopcheck'], [
    'title' => 'Loop check', 'status' => 'publish',
]);
$s->videos()->syncWithoutDetaching([$v->id => ['position' => 0]]);

echo "seeded ugc video id {$v->id}\n";
