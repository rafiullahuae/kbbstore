<?php
// Lane ASEO: the live shop's shape -- typed ENGLISH search-appearance settings,
// which on /ar used to print as they were. PREVIEW DATABASE ONLY.
foreach ([
    'seo_home_title' => 'K-Beauty Bliss | Authentic Korean Skincare in UAE',
    'seo_home_description' => 'Shop 100% authentic Korean skincare and K-beauty in the UAE. Fast delivery across Dubai, Abu Dhabi and all Emirates.',
    'seo_default_description' => 'Authentic Korean skincare, delivered across the UAE by K-Beauty Bliss.',
    'seo_site_name' => 'KBeauty Bliss',
] as $k => $v) {
    App\Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
}
Illuminate\Support\Facades\Cache::flush();
echo "aseo seed ok\n";
