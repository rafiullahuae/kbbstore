<?php
/*
 * Seed the Lane IE2 preview: Store -> Import / Export.                (Lane IE2)
 *
 * The screen being photographed is an ADMIN screen and nothing else, so this
 * is deliberately the smallest seed in tools/: one owner to sign in as. The
 * import screen draws itself from the workspace and the checkpoints, both of
 * which start empty, and an empty shop is the honest state a migration begins
 * from anyway.
 *
 * A CATALOGUE WOULD BE WORSE THAN USELESS HERE. The photographs have to show
 * the uploaded file's ROW COUNT, and rows already in the shop would make
 * "12,000 rows accepted" ambiguous between the upload and the seed.
 *
 * Adapted from tools/bg-seed.php; the shape is deliberately the same so the
 * two harnesses read alike.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

echo "seeded: 1 owner\n";
