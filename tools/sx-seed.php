<?php

/*
 * Lane SX preview seed. Run through `artisan tinker --execute` by
 * tools/sx-preview.sh, on top of DemoCatalogueSeeder.
 *
 * Two products, and the second is the whole point of the pictures: a variable
 * product whose option images carry a single quote and a parenthesis, which is
 * the address the WordPress import is about to start writing and the one that
 * used to close the url( and start a declaration of somebody else's choosing.
 *
 * It also puts a quote-bearing image on a simple product and drops it into the
 * cart, so the basket, drawer and checkout thumbnails are photographed with the
 * same kind of address behind them.
 */

use App\Models\Product;
use App\Models\ProductVariant;

$image = fn (string $name) => '/uploads/sx/'.$name;

/* The honest one: an ordinary address, so the shots prove the pictures still
   draw exactly as they did. */
$plain = Product::updateOrCreate(['slug' => 'sx-plain-serum'], [
    'name' => 'SX Heartleaf Serum',
    'status' => 'publish',
    'is_visible' => true,
    'price' => 9900,
    'stock_status' => 'instock',
    'type' => 'simple',
    'image' => $image('serum.png'),
    'short_description' => 'An ordinary product with an ordinary image address.',
]);

/* The hostile one. Read it out loud: the quote closes the url(), the semicolon
   ends the declaration, and what follows is a second declaration the shop did
   not write. The trailing `x:url('` is there to swallow the template's own
   closing `')` into a harmless unknown property, so the injected `background:`
   is VALID CSS and paints — without it the stray quote invalidates the last
   declaration, the request still fires, and the picture understates the
   finding. */
$hostile = "/uploads/sx/toner.png');background:url(/uploads/sx/injected.png) center/cover;x:url('";

$variable = Product::updateOrCreate(['slug' => 'sx-quote-toner'], [
    'name' => 'SX Quote Toner',
    'status' => 'publish',
    'is_visible' => true,
    'price' => null,
    'stock_status' => 'instock',
    'type' => 'variable',
    'image' => $hostile,
    'short_description' => 'Its image address carries a quote and a parenthesis.',
]);

ProductVariant::where('product_id', $variable->id)->delete();

ProductVariant::create([
    'product_id' => $variable->id,
    'price' => 12000,
    'stock_status' => 'instock',
    'image' => $hostile,
]);

ProductVariant::create([
    'product_id' => $variable->id,
    'price' => 19000,
    'stock_status' => 'instock',
    'image' => '/uploads/sx/toner-large.png',
]);

echo "sx seed: {$plain->slug} {$variable->slug}\n";
