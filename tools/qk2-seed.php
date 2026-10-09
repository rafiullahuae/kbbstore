<?php
/*
 * Seed the Lane QK2 preview: an owner and an editor, and the live shop's state
 * -- every social profile saved empty, so no icon shows anywhere.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);
\App\Models\AdminUser::updateOrCreate(['email' => 'editor@preview.test'], [
    'name' => 'Preview Editor', 'password' => 'preview-secret-1', 'role' => 'editor',
]);

foreach (array_keys(\App\Support\SocialProfiles::FIELDS) as $key) {
    app(\App\Services\SettingsService::class)->set($key, '');
}
echo "qk2 seeded\n";
