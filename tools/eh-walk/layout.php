<?php
/* Lane EH preview fixture: the shapes of the email field the hint must fit.
   EH_LAYOUT=grey-off  -> Appearance -> Checkout page -> "Grey line under Email" off
   EH_LAYOUT=old       -> the pre-13-October two-step layout (no grey line)
   EH_LAYOUT=default   -> the shipped 13 October layout, grey line on
   Never ships (tools/). */
$shape = getenv('EH_LAYOUT') ?: 'default';
if ($shape === 'old') {
    app(\App\Services\CheckoutLayout::class)->restore();
} elseif ($shape === 'grey-off') {
    app(\App\Services\CheckoutLayout::class)->apply();
    app(\App\Services\CheckoutPage::class)->save(['lo_email_hint_on' => false]);
} else {
    app(\App\Services\CheckoutLayout::class)->apply();
    app(\App\Services\CheckoutPage::class)->save(['lo_email_hint_on' => true]);
}
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
echo "layout: $shape\n";
