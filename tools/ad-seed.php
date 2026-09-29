<?php
/* Seed the Lane AD preview: an owner to sign in as. The catalogue comes from
   DemoCatalogueSeeder, which the preview script runs first, so the product
   grids the Product styles screen previews have real cards in them. */

\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);
