<?php
/* Seed the Lane PX (pixel guide) preview: an owner to sign in as. The pixels module is left
   at its shipped state (off), which is the screen the owner photographed. */
\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);
