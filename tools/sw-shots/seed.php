<?php
/* Lane SW: Lane TY's seed (several products, Stripe faked, free delivery) and
   the Site App switched on, so every page registers the shop's real worker. */
require __DIR__.'/../ty-shots/seed.php';

\App\Models\Setting::query()->updateOrCreate(['key' => 'site_app'], ['value' => json_encode(['on' => true, 'name' => 'K-Beauty Bliss']), 'autoload' => true]);
\App\Models\Setting::flushMap();
echo "site app on\n";
