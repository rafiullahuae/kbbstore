<?php
/* Seed the Lane CP preview: an owner, four products with names long enough to
   reach the cart panel's line clamp, and a free-delivery threshold so the panel
   draws its progress bar. */
\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

$rows = [
    ['Age-R Booster Pro Device', 'age-r-booster-pro-device', 80],
    ['Hyaluronic Acid Watery Sun Gel that runs to a second line', 'hyaluronic-acid-watery-sun-gel', 133],
    ['Cellmazing Fit Serum', 'cellmazing-fit-serum', 299],
    ['Ceramide Daily Moisturiser', 'ceramide-daily-moisturiser', 329],
];

foreach ($rows as [$name, $slug, $price]) {
    \App\Models\Product::updateOrCreate(["slug" => $slug], [
        "name" => $name, "price" => $price,
        'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

app(\App\Services\SettingsService::class)->set('free_shipping_threshold', 199);

echo "seeded ", \App\Models\Product::count(), " products\n";
