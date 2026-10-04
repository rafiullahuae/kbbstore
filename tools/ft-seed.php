<?php
// Lane FT preview seed: the shared storefront seed, plus the admin the
// screenshots sign in as. Paths are repo-relative so this travels with the branch.
require __DIR__.'/rf-seed.php';
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);
echo "ft seed done\n";
