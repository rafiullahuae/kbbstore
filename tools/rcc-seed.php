<?php
/*
 * Seed the Lane RC preview (the admin's "Are you sure?" before every reset):
 * Lane RF's catalogue and the preview owner. Written into the PREVIEW's
 * database only.
 */
require __DIR__.'/rf-seed.php';

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);
echo "rcc seed done\n";
