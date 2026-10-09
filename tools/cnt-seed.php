<?php
/*
 * Lane CT (contact page) preview seed: tools/cb-seed.php's catalogue, brands
 * and categories, so the product, category and brand pages can be measured
 * unchanged beside /contact-us/. The contact page itself is the row the
 * migrations seed. Written into the PREVIEW's database only.
 */
require __DIR__.'/cb-seed.php';

\App\Models\Setting::flushMap();
echo "ct seed done\n";
// The values the live shop has seeded (SettingsSeeder) and a valid set of
// hours, so the shots show every card the page can draw.
$cntSettings = app(\App\Services\SettingsService::class);
$cntSettings->set('support_email', 'info@kbeautybliss.com');
$cntSettings->set('store_hours', "Mon-Sat 10:00-22:00\nSun 12:00-20:00");
\App\Models\Setting::flushMap();
echo "cnt contact values done\n";

// An owner to sign in with, and three inquiries for the inbox shot (one read).
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);
if (\App\Models\ContactInquiry::query()->count() === 0) {
    foreach ([
        ['Mariam Al Suwaidi', 'mariam@example.com', '+971 50 222 3344', 'Wholesale', "Hello,\nWe run a small salon in Sharjah and would like to stock Anua and COSRX. Do you offer wholesale prices?", now()->subDays(2), now()->subDay()],
        ['Sara K.', 'sara@example.com', null, 'Product advice', 'Which sunscreen would you recommend for very sensitive skin? <b>Thanks!</b>', now()->subHours(5), null],
        ['Omar', 'omar@example.com', '+971 55 000 1122', 'Order question', 'My order KBB10234 shows packed since yesterday — when will it ship?', now()->subMinutes(20), null],
    ] as [$n, $e, $p, $t, $m, $at, $read]) {
        $q = \App\Models\ContactInquiry::create(['name' => $n, 'email' => $e, 'phone' => $p, 'topic' => $t, 'message' => $m, 'locale' => 'en', 'read_at' => $read]);
        $q->forceFill(['created_at' => $at])->save();
    }
}
echo "cnt admin + inquiries done\n";
