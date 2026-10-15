<?php
/* Lane CAP preview fixture: the Abandoned Cart module on and worded, first
   reminder 15 minutes after. Never ships (tools/). */
$s = app(\App\Services\SettingsService::class);
$s->setModule('abandoned_cart', true);
$s->set(\App\Services\CartRecovery::KEY_OPTIN_LABEL, 'Email me a reminder about this basket.');
$s->set(\App\Services\CartRecovery::KEY_SUBJECT, 'You left something in your bag');
$s->set(\App\Services\CartRecovery::KEY_BODY, 'Your basket is waiting for you.');
$s->set(\App\Services\CartRecovery::KEY_SCHEDULE, '0.25');
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
$s->flush();
echo "abandoned cart armed\n";
