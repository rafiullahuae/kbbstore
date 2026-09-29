<?php
/*
 * Seed the Lane SPL preview: TWO sets on the same catalogue — one of three
 * members and one of eight — each with four photographs of its own and every
 * member carrying a photograph, plus the shop settings the buy column reads.
 *
 * ── WHY TWO SETS AND WHY EIGHT ─────────────────────────────────────────────
 *
 * partials/set-contents-panel.blade.php folds from SEVEN members upward: five
 * rows stand and the rest go inside a <details>. A fixture of three therefore
 * photographs only half of this block — the unfolded case — and the disclosure,
 * its summary rule and the first folded row's own hairline are exactly the
 * parts a new BOX has to get right, because they are the three places a border
 * can end up running to the panel's edge. So the three treatments are shot on
 * the eight-member set, and the three-member one is kept beside it to prove a
 * short box does not gain a scrollbar, a stray rule or an empty disclosure.
 *
 * ── WHY THE PICTURES ARE MADE HERE ─────────────────────────────────────────
 *
 * THE LAST ATTEMPT AT THIS JOB SHIPPED SIX SCREENSHOTS WITH NO THUMBNAIL STRIP
 * IN ANY OF THEM, and it was not a bug in the design: the fixture carried ONE
 * image, and partials/product-gallery.blade.php draws `.gthumbs` only
 * `@if ($shotCount > 1)`. A gallery strip that is absent from every photograph
 * of a gallery cannot be judged. So this fixture carries a main shot plus three
 * more, by construction, and tools/spl-shots.cjs asserts the strip is on the
 * page before it takes the picture.
 *
 * THEY ARE ALSO NOT FLAT RECTANGLES. The previous fixture used a two-stop
 * linear gradient per product, which renders as a coloured card and makes every
 * layout look like a wireframe of itself — the owner is being asked to judge
 * how a page of PHOTOGRAPHS reads, and a page of swatches does not answer that
 * question. These are drawn as studio shots: a backdrop with a vignette, an
 * object with a specular highlight and a cast shadow, and a label. They are
 * still SVG, so nothing here needs the network, which is blocked on this box.
 *
 * ── AND NOT AS data: URIs ──────────────────────────────────────────────────
 *
 * The earlier fixture inlined them as base64 data URIs and they worked in the
 * <img> and vanished everywhere the shop paints a CSS background — App\Support\
 * CssUrl refuses every scheme but http and https, by design, so each member
 * circle in the fanned stack fell back to its gradient placeholder. Written to
 * disk under the preview's own webroot they are ordinary relative addresses and
 * every surface draws them.
 *
 * MONEY IS INTEGER FILS, as everywhere else in this repository.
 */

$root = rtrim((string) (getenv('KBB_PUBLIC_PATH') ?: public_path()), '/');
$dir = $root.'/uploads/spl';

if (! is_dir($dir)) {
    mkdir($dir, 0775, true);
}

/**
 * One studio-lit object on a backdrop.
 *
 * $shape: 'bottle' | 'jar' | 'tube'. The three read differently at 66px, which
 * is the size the gallery thumbnail strip draws them at and the size at which
 * the last attempt's fixtures were indistinguishable from each other.
 */
$object = function (string $shape, string $light, string $dark, string $cap, float $x, float $scale, string $word): string {
    $h = match ($shape) { 'jar' => 300.0, 'tube' => 560.0, default => 520.0 };
    $w = match ($shape) { 'jar' => 250.0, 'tube' => 170.0, default => 210.0 };
    $id = substr(md5($shape.$light.$word.$x), 0, 8);

    $body = match ($shape) {
        // A squat jar with a wide lid.
        'jar' => '<rect x="'.(-$w / 2).'" y="'.(-$h / 2).'" width="'.$w.'" height="'.$h.'" rx="34" fill="url(#b'.$id.')"/>'
            .'<rect x="'.(-$w / 2 - 6).'" y="'.(-$h / 2 - 54).'" width="'.($w + 12).'" height="62" rx="16" fill="'.$cap.'"/>',
        // A tube with a shoulder and a flat crimped end.
        'tube' => '<path d="M '.(-$w / 2).' '.(-$h / 2 + 90).' Q 0 '.(-$h / 2 - 30).' '.($w / 2).' '.(-$h / 2 + 90).' L '.($w / 2).' '.($h / 2 - 34).' Q '.($w / 2).' '.($h / 2).' '.($w / 2 - 26).' '.($h / 2).' L '.(-$w / 2 + 26).' '.($h / 2).' Q '.(-$w / 2).' '.($h / 2).' '.(-$w / 2).' '.($h / 2 - 34).' Z" fill="url(#b'.$id.')"/>'
            .'<rect x="'.(-$w / 2 - 4).'" y="'.($h / 2 - 6).'" width="'.($w + 8).'" height="26" rx="8" fill="'.$cap.'"/>',
        // A dropper bottle: straight sides, narrow neck, long cap.
        default => '<rect x="'.(-$w / 2).'" y="'.(-$h / 2 + 96).'" width="'.$w.'" height="'.($h - 96).'" rx="30" fill="url(#b'.$id.')"/>'
            .'<rect x="-26" y="'.(-$h / 2 + 46).'" width="52" height="64" fill="url(#b'.$id.')"/>'
            .'<rect x="-40" y="'.(-$h / 2 - 34).'" width="80" height="92" rx="14" fill="'.$cap.'"/>',
    };

    return '<defs>'
        .'<linearGradient id="b'.$id.'" x1="0" y1="0" x2="1" y2="0">'
        .'<stop offset="0" stop-color="'.$dark.'"/><stop offset=".34" stop-color="'.$light.'"/>'
        .'<stop offset=".72" stop-color="'.$light.'"/><stop offset="1" stop-color="'.$dark.'"/>'
        .'</linearGradient></defs>'
        .'<g transform="translate('.$x.' 520) scale('.$scale.')">'
        // The cast shadow, which is most of what makes a drawing read as a
        // photograph at thumbnail size.
        .'<ellipse cx="0" cy="'.($h / 2 + 30).'" rx="'.($w * 0.82).'" ry="26" fill="#2A2228" opacity=".13"/>'
        .$body
        // Specular highlight down the left third.
        .'<rect x="'.(-$w / 2 + 16).'" y="'.(-$h / 2 + 120).'" width="'.($w * 0.16).'" height="'.($h - 190).'" rx="'.($w * 0.08).'" fill="#fff" opacity=".28"/>'
        // The label.
        .'<rect x="'.(-$w * 0.38).'" y="'.($h * 0.02).'" width="'.($w * 0.76).'" height="'.($h * 0.30).'" rx="10" fill="#FFFDFC" opacity=".95"/>'
        .'<text x="0" y="'.($h * 0.17).'" text-anchor="middle" font-family="Helvetica,Arial,sans-serif" font-size="'.($w * 0.17).'" font-weight="700" fill="#2A2228" letter-spacing="2">'.htmlspecialchars($word, ENT_QUOTES).'</text>'
        .'<rect x="'.(-$w * 0.26).'" y="'.($h * 0.21).'" width="'.($w * 0.52).'" height="6" rx="3" fill="#E0567B" opacity=".75"/>'
        .'</g>';
};

$scene = function (string $inner, string $bg1 = '#FCF6F3', string $bg2 = '#F6E7EA'): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="1000" viewBox="0 0 1000 1000">'
        .'<defs>'
        .'<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="'.$bg1.'"/><stop offset="1" stop-color="'.$bg2.'"/></linearGradient>'
        .'<radialGradient id="vig" cx=".5" cy=".42" r=".72"><stop offset=".55" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#2A2228" stop-opacity=".12"/></radialGradient>'
        .'</defs>'
        .'<rect width="1000" height="1000" fill="url(#bg)"/>'
        .'<ellipse cx="500" cy="880" rx="520" ry="150" fill="#fff" opacity=".45"/>'
        .$inner
        .'<rect width="1000" height="1000" fill="url(#vig)"/>'
        .'</svg>';
};

$write = function (string $name, string $svg) use ($dir): string {
    file_put_contents($dir.'/'.$name, $svg);

    return '/uploads/spl/'.$name;
};

// ── The set's own four shots ────────────────────────────────────────────────
$setFront = $write('set-1-front.svg', $scene(
    $object('bottle', '#F3A9C0', '#D46486', '#8E3A57', 300, 0.74, 'TONER')
    .$object('bottle', '#BFE0CB', '#6FA986', '#3E6B51', 500, 0.86, 'SERUM')
    .$object('jar', '#FFDFA8', '#DCA24A', '#9A6B22', 720, 0.74, 'CREAM')
));

$setTexture = $write('set-2-texture.svg', $scene(
    '<g opacity=".92"><path d="M250 560 Q 330 380 520 420 Q 720 462 700 600 Q 684 720 500 706 Q 300 692 250 560 Z" fill="#F7C9D8"/>'
    .'<path d="M330 540 Q 400 452 520 480 Q 630 506 618 586 Q 608 654 500 648 Q 372 640 330 540 Z" fill="#FFF0F4" opacity=".8"/>'
    .'<circle cx="720" cy="360" r="34" fill="#F3A9C0" opacity=".75"/><circle cx="792" cy="424" r="19" fill="#F3A9C0" opacity=".6"/>'
    .'<circle cx="262" cy="336" r="24" fill="#F3A9C0" opacity=".6"/></g>'
));

$setIngredients = $write('set-3-ingredients.svg', $scene(
    '<g opacity=".9">'
    .'<path d="M500 700 Q 360 640 340 480 Q 470 470 520 580 Z" fill="#79B18C"/>'
    .'<path d="M520 700 Q 660 640 680 470 Q 546 466 500 586 Z" fill="#5E9A72"/>'
    .'<rect x="492" y="560" width="14" height="190" rx="7" fill="#3E6B51"/>'
    .'</g>'
    .$object('bottle', '#BFE0CB', '#6FA986', '#3E6B51', 760, 0.56, 'SERUM'),
    '#F7FBF7',
    '#E3F0E7'
));

$setBox = $write('set-4-box.svg', $scene(
    '<g><rect x="250" y="330" width="500" height="390" rx="22" fill="#F6DCE4"/>'
    .'<rect x="250" y="330" width="500" height="92" rx="22" fill="#EFC6D3"/>'
    .'<rect x="478" y="330" width="44" height="390" fill="#E0567B" opacity=".85"/>'
    .'<rect x="250" y="498" width="500" height="40" fill="#E0567B" opacity=".85"/>'
    .'<ellipse cx="500" cy="748" rx="270" ry="34" fill="#2A2228" opacity=".12"/></g>',
    '#FBF5F7',
    '#F1E2E8'
));

// ── The catalogue rows ──────────────────────────────────────────────────────
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$anua = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$joseon = \App\Models\Brand::updateOrCreate(['slug' => 'beauty-of-joseon'], ['name' => 'Beauty of Joseon']);
$round = \App\Models\Brand::updateOrCreate(['slug' => 'round-lab'], ['name' => 'Round Lab']);
$category = \App\Models\Category::updateOrCreate(['slug' => 'skincare-sets'], ['name' => 'Skincare Sets']);

/* Eight members, and the names are LONG on purpose. "Revive Eye Serum Ginseng
   Retinal 30ml" in a 346px column is the string the panel's own header names as
   how a page comes to scroll sideways, and a box with new padding is exactly
   where that comes back. */
/* A photograph per member, generated rather than reused, because eight rows
   showing three repeated pictures reads as a placeholder and the whole point of
   this fixture is that the box is judged against a real-looking list. Shape,
   two body colours and a cap colour per row; the labels are what make the 34px
   squares tell each other apart at the size the panel draws them. */
$members = [
    ['Heartleaf 77% Soothing Toner 250ml', 'spl-heartleaf-toner', 9000, $anua->id, 'bottle', '#F3A9C0', '#D46486', '#8E3A57', 'TONER'],
    ['Relief Sun Rice + Probiotic Serum 50ml', 'spl-relief-serum', 7550, $joseon->id, 'bottle', '#BFE0CB', '#6FA986', '#3E6B51', 'SERUM'],
    ['Birch Juice Moisturizing Cream 80ml', 'spl-birch-cream', 6900, $round->id, 'jar', '#FFDFA8', '#DCA24A', '#9A6B22', 'CREAM'],
    ['Revive Eye Serum Ginseng + Retinal 30ml', 'spl-revive-eye', 11900, $joseon->id, 'bottle', '#D9D2F2', '#8C7BE8', '#4E3FA0', 'EYE'],
    ['Peach 77% Niacinamide Sleeping Mask 100ml', 'spl-peach-mask', 8400, $anua->id, 'jar', '#FFD3C0', '#E8825C', '#A44C2C', 'MASK'],
    ['Dokdo Cleansing Oil 200ml', 'spl-dokdo-oil', 7200, $round->id, 'bottle', '#CFE7F5', '#5FA3CE', '#2F6389', 'OIL'],
    ['Green Plum Refreshing Cleanser 150ml', 'spl-green-plum', 5600, $anua->id, 'tube', '#D5EFC4', '#7DB755', '#456F2A', 'WASH'],
    ['Apricot Gentle Exfoliating Gel 120ml', 'spl-apricot-gel', 6100, $round->id, 'tube', '#FFE7B0', '#E0A83A', '#96681A', 'GEL'],
];

$ids = [];


foreach ($members as [$name, $slug, $fils, $brandId, $shape, $light, $dark, $cap, $word]) {
    $image = $write(
        $slug.'.svg',
        $scene($object($shape, $light, $dark, $cap, 500, 'jar' === $shape ? 1.0 : 0.92, $word))
    );

    $p = \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'brand_id' => $brandId, 'image' => $image,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
    ]);
    $ids[] = $p->id;
}

/* THE BIG SET — eight members, so the panel folds. */
$set = \App\Models\Product::updateOrCreate(['slug' => 'spl-glow-ritual-set'], [
    'name' => 'The Glow Ritual Set',
    'type' => 'set',
    'price' => 26900,
    'brand_id' => $anua->id,
    'category_id' => $category->id,
    'image' => $setFront,
    'images' => [$setTexture, $setIngredients, $setBox],
    'short_description' => 'Three steps, one box — cleanse the day off, calm the redness, seal it in.',
    'description' => '<p>The toner that took a year to reformulate, the serum everybody asks us to restock, '
        .'and the cream that makes both of them last until morning. Packed in a gift box, so it goes '
        .'straight under a tree or into a suitcase without a second wrapping.</p>',
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
]);

$set->categories()->syncWithoutDetaching([$category->id]);

\App\Models\ProductSetItem::where('set_product_id', $set->id)->delete();

foreach ($ids as $i => $memberId) {
    \App\Models\ProductSetItem::create([
        'set_product_id' => $set->id,
        'member_product_id' => $memberId,
        'quantity' => 0 === $i ? 2 : 1,
        'position' => $i,
    ]);
}

/* THE SHORT SET — three members, so the same box is photographed with no
   disclosure under it. A panel that only ever gets shot folded is a panel
   nobody has checked for a stray rule at its own bottom edge. */
$short = \App\Models\Product::updateOrCreate(['slug' => 'spl-starter-duo-set'], [
    'name' => 'The Starter Trio',
    'type' => 'set',
    'price' => 19900,
    'brand_id' => $anua->id,
    'category_id' => $category->id,
    'image' => $setFront,
    'images' => [$setTexture, $setIngredients, $setBox],
    'short_description' => 'The three everybody starts with.',
    'description' => '<p>Toner, serum, cream. Nothing else to decide.</p>',
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
]);

$short->categories()->syncWithoutDetaching([$category->id]);

\App\Models\ProductSetItem::where('set_product_id', $short->id)->delete();

foreach (array_slice($ids, 0, 3) as $i => $memberId) {
    \App\Models\ProductSetItem::create([
        'set_product_id' => $short->id,
        'member_product_id' => $memberId,
        'quantity' => 0 === $i ? 2 : 1,
        'position' => $i,
    ]);
}

/* The shop's own words, so the trust chips and the delivery line draw rather
   than render as three empty divs. Every one of these is a value an operator
   types on a screen that already exists; nothing here is a new setting. */
$settings = app(\App\Services\SettingsService::class);
$settings->set('store_name', 'K Beauty Bliss');
$settings->set('store_country', 'AE');
$settings->set('trust_returns_text', 'Easy returns within 14 days');
\App\Models\Setting::flushMap();

echo 'seeded /product/', $set->id, ' big=', $set->slug, ' (', count($ids), ' members) short=', $short->slug, "\n";
