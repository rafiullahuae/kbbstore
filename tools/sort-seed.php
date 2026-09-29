<?php
/* Seed the Lane SORT preview: a small shop whose grid holds plain products AND
   all three set pricing modes, with the printed prices deliberately interleaved
   so a sort that ignores the rule CANNOT come out looking right.

   Printed prices, cheapest first:

     AED  80.00  Rice Water Cleanser        plain
     AED 140.00  Barrier Rescue Box         set, hand-typed 160.00, anchored 200.00
     AED 145.00  Heartleaf Soothing Toner   plain
     AED 160.00  Morning Glow Duo           set, 20.00 off a 180.00 box
     AED 162.00  Night Repair Set           set, 10% off a 180.00 box
     AED 200.00  Ceramide Night Cream       plain
     AED 260.00  Retinal Renewal Serum      plain

   The three sets all carry products.price = 18000 -- the snapshot the editor
   writes -- so before this lane they sorted as though they cost AED 180, which
   is the screenshot in docs/lane-sort-shots/*-before-*.png.

   And one set whose members have been marked down SINCE it was last saved, so
   the -N% badge and the "On sale" facet have something to agree about. */

$mk = function (string $name, int $fils, array $extra = []) {
    return \App\Models\Product::create(array_merge([
        'slug' => \Illuminate\Support\Str::slug($name),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
        'description' => 'Preview product for the Lane SORT screenshots.',
    ], $extra));
};

// Everything the demo seeder left behind is drafted: /shop pages at 24 and a
// screenshot of page one of two is not a screenshot of an order.
\App\Models\Product::query()->update(['status' => 'draft']);

$toner = $mk('Heartleaf Soothing Toner', 14500);
$cream = $mk('Ceramide Night Cream', 20000);
$serum = $mk('Retinal Renewal Serum', 26000);
$wash = $mk('Rice Water Cleanser', 8000);

// The two members every set below is built from: AED 100 + AED 80 = AED 180.
$member1 = $mk('Snail Mucin Essence', 10000);
$member2 = $mk('Propolis Ampoule', 8000);

$set = function (string $name, array $overrides) use ($member1, $member2) {
    $row = \App\Models\Product::create(array_merge([
        'slug' => \Illuminate\Support\Str::slug($name),
        'name' => $name,
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 18000,
        'stock_status' => 'instock',
        'description' => 'Preview set for the Lane SORT screenshots.',
    ], $overrides));

    foreach ([[$member1, 0], [$member2, 1]] as [$member, $position]) {
        \App\Models\ProductSetItem::create([
            'set_product_id' => $row->id,
            'member_product_id' => $member->id,
            'quantity' => 1,
            'position' => $position,
        ]);
    }

    return $row;
};

$set('Night Repair Set', ['set_price_mode' => 'discount_percent', 'set_discount' => 1000]);
$set('Morning Glow Duo', ['set_price_mode' => 'discount_amount', 'set_discount' => 2000]);
$set('Barrier Rescue Box', ['price' => 16000, 'set_price_mode' => 'fixed', 'set_price_basis' => 20000]);

/* THE ON-SALE CASE, and it is a SECOND pair of members so the three sets above
   keep the prices the caption states. This set was saved when its box was worth
   AED 180 -- products.price is the 16200 the editor wrote -- and its members
   have been marked down by AED 40 since. It therefore charges AED 144, its tile
   draws -11%, and "On sale" has to contain it. */
$saleA = $mk('Mugwort Calming Pad', 6000);
$saleB = $mk('Azelaic Acid Serum', 10000);

$onSale = \App\Models\Product::create([
    'slug' => 'glow-starter-set',
    'name' => 'Glow Starter Set',
    'type' => 'set',
    'status' => 'publish',
    'is_visible' => true,
    'price' => 16200,
    'stock_status' => 'instock',
    'set_price_mode' => 'discount_percent',
    'set_discount' => 1000,
    'description' => 'Preview set for the Lane SORT screenshots.',
]);

foreach ([[$saleA, 0], [$saleB, 1]] as [$member, $position]) {
    \App\Models\ProductSetItem::create([
        'set_product_id' => $onSale->id,
        'member_product_id' => $member->id,
        'quantity' => 1,
        'position' => $position,
    ]);
}

echo "seeded: ".\App\Models\Product::where('status', 'publish')->count()." published products, "
    .\App\Models\Product::where('type', 'set')->where('status', 'publish')->count()." of them sets\n";
