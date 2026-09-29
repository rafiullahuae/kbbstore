<?php
/*
 * Turn the wishlist module ON or OFF in the preview: KBB_WISHLIST=1 or 0.
 *
 * It ships OFF, and that is the state the card actually arrives in.
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
/* ── AND IT TAKES A VALUE, WHICH IS NOT OVER-ENGINEERING ────────────────────
   This was `setModule('wishlist', false)` with no way back, and the shoot that
   ran after it took the Arabic pair with no heart in it — the one part of the
   card the mirrored shot exists to show on the other side of the button.
   Measured: `heart=-` on all four Arabic rows. A switch that only goes one way
   leaves whatever runs next in a state nobody chose. */
$on = (string) (getenv('KBB_WISHLIST') ?: '0') === '1';

app(\App\Services\SettingsService::class)->setModule('wishlist', $on);

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();

echo 'wishlist '.($on ? 'on' : 'off')."\n";
