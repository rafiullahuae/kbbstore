<?php
/*
 * Turn the wishlist module OFF in the preview, which is how the shop ships.
 *
 * ── WHY THERE ARE SHOTS OF BOTH ────────────────────────────────────────────
 *
 * The owner's card has a heart beside the Add to cart button, so the contact
 * sheets are taken with Catalogue → Wishlist ON — a sheet without it would be a
 * picture of his design with a piece missing, and he is choosing between four
 * designs.
 *
 * But the shop ships with that module OFF, so what applying the package
 * actually puts on the page is the button at the card's full width and no heart
 * at all. Handing him only the first picture would be handing him a screenshot
 * of a shop he does not have. Both, named, is the honest answer — and the card
 * is built so that either is right rather than one being a compromise: the
 * `:has(.heart)` rule is what gives the heart its room, so the button takes the
 * whole column when there is nothing to make room for.
 */
app(\App\Services\SettingsService::class)->setModule('wishlist', false);

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();

echo "wishlist off\n";
