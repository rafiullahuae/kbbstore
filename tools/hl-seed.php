<?php
/* Seed the Lane HL preview: an owner to sign in as, and nothing else.
   Everything the Live preview tab draws -- the seventeen sections, their two
   switches and their grid skins -- is the shipped default, which is the state
   a real install is in the moment the package is applied. */
\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

echo "seeded owner\n";
