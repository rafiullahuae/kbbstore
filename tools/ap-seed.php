<?php
/*
 * Seed the Lane AP preview: one account per legacy role, so the server-rendered
 * sidebar can be photographed and crawled as each of them. Nothing else -- the
 * console's sidebar and load timing do not depend on catalogue rows.
 * Written into the PREVIEW's database only.
 */
foreach (['owner', 'manager', 'support', 'editor'] as $role) {
    \App\Models\AdminUser::updateOrCreate(
        ['email' => $role.'@preview.test'],
        ['name' => 'Preview '.ucfirst($role), 'password' => 'preview-secret-1', 'role' => $role]
    );
}
