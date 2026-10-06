<?php
/*
 * Lane SP (speed) -- a LIVE-SCALE fixture for the inner-page before/after.
 *
 * Runs on the OLDER tree (5d3c7724) and is then dumped and migrated forward
 * for HEAD and the fix, so all three previews read the same rows. Only uses
 * models and columns that exist at 5d3c7724.
 *
 * Scale is the owner's live shop: ~1,200 products (120 sold out, 73 low
 * stock), 60 categories with parents and children, 80 brands, a Super Sale
 * category of 150 products, reviews, posts, a header menu, the homepage
 * sections (pf-seed / ha-seed), and a photograph per product WITH its
 * img-cache copies (the steady state after the after-response sizing).
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/pf-seed.php';

mt_srand(4242);
$now = now();

// ── brands to 80 ────────────────────────────────────────────────────────────
$have = Brand::query()->count();
for ($i = $have; $i < 80; $i++) {
    Brand::query()->create(['slug' => 'spd-brand-'.$i, 'name' => 'Brand '.chr(65 + $i % 26).' '.$i, 'position' => 0]);
}
$brandIds = Brand::query()->orderBy('id')->pluck('id')->all();

// ── categories to 60: 12 parents x 4 children, plus what the demo made ─────
$parents = [];
for ($p = 0; $p < 12; $p++) {
    $slug = 'spd-dept-'.$p;
    $parents[$p] = Category::query()->create(['slug' => $slug, 'name' => 'Department '.$p, 'path' => $slug, 'depth' => 0, 'position' => $p]);
    for ($c = 0; $c < 4; $c++) {
        $cs = 'spd-shelf-'.$p.'-'.$c;
        Category::query()->create(['slug' => $cs, 'name' => 'Shelf '.$p.'.'.$c, 'parent_id' => $parents[$p]->id, 'path' => $slug.'/'.$cs, 'depth' => 1, 'position' => $c]);
    }
}
$super = Category::query()->firstOrCreate(['slug' => 'super-sale'], ['name' => 'Super Sale', 'path' => 'super-sale', 'depth' => 0, 'position' => 99]);
$children = Category::query()->where('slug', 'like', 'spd-shelf-%')->get()->keyBy('id');

// ── photographs: 24 originals, each product hardlinks one ──────────────────
$base = public_path('wp-content/uploads/spd-base');
@mkdir($base, 0775, true);
$pal = [[0xF8, 0xBB, 0xD0], [0xBB, 0xDE, 0xFB], [0xC8, 0xE6, 0xC9], [0xFF, 0xE0, 0xB2], [0xE1, 0xBE, 0xE7], [0xB2, 0xEB, 0xF2]];
$originals = [];
for ($k = 0; $k < 24; $k++) {
    $w = $k % 6 === 5 ? 700 : 1000;   // one in six narrower than 800, as some imports are
    $im = imagecreatetruecolor($w, $w);
    [$r, $g, $b] = $pal[$k % 6];
    for ($y = 0; $y < $w; $y += 4) {
        $t = $y / $w;
        imagefilledrectangle($im, 0, $y, $w, $y + 4, imagecolorallocate($im, (int) ($r * (1 - $t) + 248 * $t), (int) ($g * (1 - $t) + 240 * $t), (int) ($b * (1 - $t) + 244 * $t)));
    }
    imagefilledrectangle($im, (int) ($w * .36), (int) ($w * .22), (int) ($w * .64), (int) ($w * .86), imagecolorallocate($im, 255, 255, 255));
    for ($n = 0; $n < ($w * $w) / 40; $n++) {
        imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $w - 1), imagecolorallocate($im, mt_rand(120, 255), mt_rand(120, 255), mt_rand(120, 255)));
    }
    imagejpeg($im, $base.'/o'.$k.'.jpg', 85);
    imagedestroy($im);
    \App\Support\ImageVariants::generate('/wp-content/uploads/spd-base/o'.$k.'.jpg');
    $originals[$k] = 'wp-content/uploads/spd-base/o'.$k.'.jpg';
}

$link = static function (string $fromRel, string $toRel): void {
    $roots = [''];
    foreach (\App\Support\ImageVariants::WIDTHS as $wd) {
        $roots[] = \App\Support\ImageVariants::DIR.'/'.$wd.'/';
    }
    foreach ($roots as $r) {
        $from = public_path($r.$fromRel);
        if (! is_file($from)) {
            continue;
        }
        $to = public_path($r.$toRel);
        @mkdir(dirname($to), 0775, true);
        @link($from, $to) || @copy($from, $to);
    }
};

// ── products to 1,200 ───────────────────────────────────────────────────────
$existing = Product::query()->count();
$rows = [];
for ($i = $existing; $i < 1200; $i++) {
    $rows[] = [
        'slug' => 'spd-product-'.$i, 'name' => 'Glow Product '.str_pad((string) $i, 4, '0', STR_PAD_LEFT).' Essence',
        'brand_id' => $brandIds[$i % count($brandIds)], 'type' => 'simple', 'status' => 'publish', 'is_visible' => 1,
        'price' => 2900 + ($i % 40) * 250, 'sale_price' => $i % 5 === 0 ? 1900 + ($i % 40) * 200 : null,
        'stock_status' => 'instock', 'manage_stock' => 0, 'stock' => null,
        'short_description' => 'A gentle daily essence for every skin type.',
        'description' => str_repeat('<p>Hydrating, calming and lightweight. Apply after toner, morning and night.</p>', 6),
        'rating' => 0, 'review_count' => 0, 'total_sales' => mt_rand(0, 500), 'featured' => $i % 37 === 0 ? 1 : 0,
        'position' => 0, 'created_at' => $now->copy()->subMinutes($i), 'updated_at' => $now,
    ];
}
foreach (array_chunk($rows, 200) as $chunk) {
    DB::table('products')->insert($chunk);
}

$all = Product::query()->orderBy('id')->get(['id', 'slug']);
$pivot = [];
$childIds = $children->keys()->all();
foreach ($all as $i => $p) {
    $rel = 'wp-content/uploads/2025/'.str_pad((string) (1 + $i % 12), 2, '0', STR_PAD_LEFT).'/spd-'.$p->id.'.jpg';
    $link($originals[$i % 24], $rel);
    $gallery = [];
    for ($g = 1; $g <= 3; $g++) {
        $grel = 'wp-content/uploads/2025/gal/spd-'.$p->id.'-'.$g.'.jpg';
        $link($originals[($i + $g) % 24], $grel);
        $gallery[] = '/'.$grel;
    }
    DB::table('products')->where('id', $p->id)->update(['image' => '/'.$rel, 'images' => json_encode($gallery)]);

    $child = $children[$childIds[$i % count($childIds)]];
    $pivot[$child->id.'-'.$p->id] = ['category_id' => $child->id, 'product_id' => $p->id];
    $pivot[$child->parent_id.'-'.$p->id] = ['category_id' => $child->parent_id, 'product_id' => $p->id];
    if ($i % 8 === 0) {   // 150 in Super Sale
        $pivot[$super->id.'-'.$p->id] = ['category_id' => $super->id, 'product_id' => $p->id];
    }
}
foreach (array_chunk(array_values($pivot), 500) as $chunk) {
    DB::table('category_product')->insertOrIgnore($chunk);
}
DB::table('products')->whereIn('id', $all->pluck('id')->filter(fn ($id) => $id % 10 === 3)->all())->update(['stock_status' => 'outofstock']);
DB::table('products')->whereIn('id', $all->pluck('id')->filter(fn ($id) => $id % 16 === 7)->take(73)->all())->update(['manage_stock' => 1, 'stock' => 2]);
// A curated order on Super Sale and one department, as the owner has.
DB::table('products')->whereIn('id', $all->pluck('id')->filter(fn ($id) => $id % 8 === 0)->all())->update(['position' => DB::raw('id % 97')]);

// ── reviews: ~4 per product on two thirds of the catalogue ─────────────────
$rev = [];
foreach ($all as $i => $p) {
    if ($i % 3 === 2) {
        continue;
    }
    for ($r = 0; $r < 4; $r++) {
        $rev[] = ['product_id' => $p->id, 'author_name' => 'Shopper '.$r, 'author_email' => "s{$r}@preview.test", 'rating' => 3 + ($i + $r) % 3,
            'title' => 'Lovely', 'content' => 'Soft on the skin and absorbs quickly.', 'status' => 'approved', 'verified' => 1, 'source' => 'sorina',
            'created_at' => $now->copy()->subDays($r + 1), 'updated_at' => $now];
    }
}
foreach (array_chunk($rev, 500) as $chunk) {
    DB::table('reviews')->insert($chunk);
}
DB::statement('UPDATE products SET review_count = (SELECT COUNT(*) FROM reviews WHERE reviews.product_id = products.id AND reviews.status = \'approved\'), rating = COALESCE((SELECT AVG(rating) FROM reviews WHERE reviews.product_id = products.id AND reviews.status = \'approved\'), 0)');

// ── posts to 30 ─────────────────────────────────────────────────────────────
for ($i = Post::query()->count(); $i < 30; $i++) {
    Post::query()->create(['slug' => 'spd-post-'.$i, 'title' => 'Skincare journal entry '.$i, 'tag' => 'Routines', 'excerpt' => 'Notes on a routine.',
        'body' => str_repeat('<p>Why each step exists and which ones you can skip on a busy morning.</p>', 12), 'status' => 'published',
        'published_at' => $now->copy()->subDays($i + 3), 'cover' => '/'.$originals[$i % 24]]);
}

// ── a header menu: the departments and their shelves ───────────────────────
if (\Illuminate\Support\Facades\Schema::hasTable('menus')) {
    $cols = \Illuminate\Support\Facades\Schema::getColumnListing('menus');
    $menu = \App\Models\Menu::query()->create(array_intersect_key(['slug' => 'spd-main', 'name' => 'Main', 'location' => 'header', 'show_desktop' => 1, 'show_mobile' => 1], array_flip($cols)));
    foreach ($parents as $p => $cat) {
        $top = \App\Models\MenuItem::query()->create(['menu_id' => $menu->id, 'label' => $cat->name, 'target_type' => 'category', 'target_id' => $cat->id, 'url' => '/product-category/'.$cat->path.'/', 'position' => $p]);
        foreach (Category::query()->where('parent_id', $cat->id)->get() as $j => $ch) {
            \App\Models\MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $top->id, 'label' => $ch->name, 'target_type' => 'category', 'target_id' => $ch->id, 'url' => '/product-category/'.$ch->path.'/', 'position' => $j]);
        }
    }
}

\App\Services\SettingsService::forgetMemo();
\Illuminate\Support\Facades\Cache::flush();
echo 'spd seed done: '.Product::query()->count().' products, '.Category::query()->count().' categories, '.Brand::query()->count().' brands, '
    .DB::table('reviews')->count()." reviews\n";
