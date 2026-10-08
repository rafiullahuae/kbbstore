<?php
/* Lane PO: a brand and a category for the speed-freeze measurements
   (product, category and brand pages). Preview fixture; never ships. */
$brand = \App\Models\Brand::updateOrCreate(['slug' => 'po-anua'], ['name' => 'Anua']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'po-serums'], ['name' => 'Serums']);
foreach (\App\Models\Product::whereIn('slug', ['co-glow-serum', 'co-fwee-jelly-pot'])->get() as $p) {
    $p->brand_id = $brand->id;
    $p->save();
    $p->categories()->syncWithoutDetaching([$category->id]);
}
echo "brand {$brand->slug} category {$category->slug}\n";
