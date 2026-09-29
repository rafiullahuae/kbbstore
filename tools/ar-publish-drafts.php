<?php
/*
 * Approve the shipped Arabic drafts in a preview, the way the console's
 * "Approve all" button does — one call, whole locale.
 *
 * SEPARATE FROM tools/ar-seed.php on purpose. The default state of the preview
 * has to be the default state of the shop: drafts written, nothing published,
 * /ar still English. This script is the OWNER'S PRESS, and the screenshots are
 * taken either side of it.
 */
$n = \App\Models\Translation::query()
    ->where('locale', 'ar')
    ->where('status', \App\Models\Translation::STATUS_DRAFT)
    ->count();

foreach (\App\Models\Translation::query()
    ->where('locale', 'ar')
    ->where('status', \App\Models\Translation::STATUS_DRAFT)
    ->cursor() as $row) {
    $row->status = \App\Models\Translation::STATUS_PUBLISHED;
    $row->reviewed_at = now();
    $row->save();
}

\App\Services\Translation\TranslationStore::flush();

echo "approved {$n} drafts\n";
