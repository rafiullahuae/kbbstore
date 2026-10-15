<?php
/* Lane EH preview fixture: Emails -> Sending & delivery -> At checkout ON,
   written the way the screen's save writes it. Never ships (tools/). */
app(\App\Services\SettingsService::class)->set(\App\Support\CheckoutEmailFix::KEY, getenv('EH_OFF') ? '0' : '1');
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
echo 'checkout_email_fix = '.(\App\Support\CheckoutEmailFix::enabled() ? 'on' : 'off')."\n";
