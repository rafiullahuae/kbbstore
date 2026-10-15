<?php
/* Lane EH preview fixture: an owner to sign in as (tools/ce-contact-seed.php's). Never ships. */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Rafi (owner)', 'password' => 'preview-secret-1', 'role' => 'owner',
]);
echo "owner ready\n";
