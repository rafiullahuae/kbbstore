<?php

declare(strict_types=1);

/*
 * Lane RD (reviews designs): the preview shop behind docs/lane-rd-shots/.
 * Run by tools/rdr-preview.sh through tinker against the preview's own SQLite
 * file -- never against a real database. (tools/rd-*.{sh,php} belong to an
 * earlier, merged Lane RD -- press feedback -- and are left as they are.)
 *
 *   - the stock DatabaseSeeder (settings, demo catalogue; its demo reviews are
 *     source='demo' and every storefront reader drops them through ->real())
 *   - Arabic on, RTL on, the Arabic interface drafts published, so /ar/ is the
 *     right-to-left shop
 *   - ten K-beauty products with raster pictures and their img-cache copies,
 *     Arabic names through the translation store
 *   - eight shopper photographs (review photos) with img-cache copies
 *   - twelve customers with a default address: the only place this shop
 *     records a city
 *   - 40 approved REAL-provenance reviews in English and Arabic, plus two
 *     pending and one spam row that no design may show
 *
 * PLACEHOLDER pictures, drawn here with GD. Nothing in this file is shipped.
 */

use App\Models\Product;
use App\Models\Review;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use App\Support\ImageVariants;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

(new \Database\Seeders\DatabaseSeeder)->run();

$sv = app(\App\Services\SettingsService::class);
$sv->set(Locale::SETTING_ENABLED, true);
$sv->set(Locale::SETTING_RTL, true);

foreach (\App\Services\Translation\ArabicInterfaceDrafts::all() as $key => $text) {
    TranslationStore::put('ar', Translation::GROUP_UI, 0, $key, $text, Translation::STATUS_PUBLISHED);
}

$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$fontR = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$dir = public_path('uploads/rd');
@mkdir($dir, 0755, true);
mt_srand(7);

$hex = static fn (string $h) => [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))];

/* A product shot: a soft backdrop, a floor shadow, and a bottle, jar or tube
   with its brand on the label. Square, as the catalogue's photographs are.
   $scene draws a shopper's photo instead: a counter, portrait. */
$shot = function (string $file, array $c, string $shape, string $brand, string $line, int $w = 800, int $h = 800, bool $scene = false) use ($dir, $hex, $font, $fontR): string {
    $im = imagecreatetruecolor($w, $h);
    imageantialias($im, true);
    [$r1, $g1, $b1] = $hex($c[0]);
    [$r2, $g2, $b2] = $hex($c[1]);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t * .5), (int) ($g1 + ($g2 - $g1) * $t * .5), (int) ($b1 + ($b2 - $b1) * $t * .5)));
    }
    if ($scene) {
        imagefilledrectangle($im, 0, (int) ($h * .66), $w, $h, imagecolorallocate($im, 240, 234, 229));
        imagesetthickness($im, 2);
        for ($k = 0; $k < 9; $k++) {
            imageline($im, mt_rand(0, $w), (int) ($h * .66), mt_rand(0, $w), $h, imagecolorallocatealpha($im, 190, 178, 172, 90));
        }
        imagefilledellipse($im, (int) ($w * .86), (int) ($h * .18), (int) ($w * .55), (int) ($w * .55), imagecolorallocatealpha($im, 255, 255, 255, 70));
        imagefilledellipse($im, (int) ($w * .12), (int) ($h * .9), (int) ($w * .5), (int) ($w * .2), imagecolorallocatealpha($im, 255, 255, 255, 60));
    }
    $cx = (int) ($w / 2);
    $floor = (int) ($h * ($scene ? .8 : .8));
    $s = $w / 800;
    imagefilledellipse($im, $cx, $floor, (int) (380 * $s), (int) (34 * $s), imagecolorallocatealpha($im, 40, 30, 35, 100));
    [$kr, $kg, $kb] = $hex($c[2]);
    $cap = imagecolorallocate($im, $kr, $kg, $kb);
    $body = imagecolorallocate($im, 255, 255, 255);
    $label = imagecolorallocate($im, $r2, $g2, $b2);
    $ink = imagecolorallocate($im, 52, 40, 48);
    switch ($shape) {
        case 'jar':
            imagefilledrectangle($im, (int) ($cx - 170 * $s), (int) ($floor - 230 * $s), (int) ($cx + 170 * $s), $floor, $body);
            imagefilledrectangle($im, (int) ($cx - 182 * $s), (int) ($floor - 300 * $s), (int) ($cx + 182 * $s), (int) ($floor - 228 * $s), $cap);
            imagefilledrectangle($im, (int) ($cx - 170 * $s), (int) ($floor - 170 * $s), (int) ($cx + 170 * $s), (int) ($floor - 60 * $s), $label);
            $ly = (int) ($floor - 110 * $s);
            break;
        case 'tube':
            imagefilledpolygon($im, [(int) ($cx - 95 * $s), (int) ($floor - 470 * $s), (int) ($cx + 95 * $s), (int) ($floor - 470 * $s), (int) ($cx + 70 * $s), (int) ($floor - 60 * $s), (int) ($cx - 70 * $s), (int) ($floor - 60 * $s)], $body);
            imagefilledrectangle($im, (int) ($cx - 48 * $s), (int) ($floor - 60 * $s), (int) ($cx + 48 * $s), $floor, $cap);
            imagefilledrectangle($im, (int) ($cx - 86 * $s), (int) ($floor - 360 * $s), (int) ($cx + 86 * $s), (int) ($floor - 220 * $s), $label);
            $ly = (int) ($floor - 290 * $s);
            break;
        default:
            imagefilledrectangle($im, (int) ($cx - 105 * $s), (int) ($floor - 430 * $s), (int) ($cx + 105 * $s), $floor, $body);
            imagefilledrectangle($im, (int) ($cx - 46 * $s), (int) ($floor - 520 * $s), (int) ($cx + 46 * $s), (int) ($floor - 428 * $s), $cap);
            imagefilledrectangle($im, (int) ($cx - 105 * $s), (int) ($floor - 300 * $s), (int) ($cx + 105 * $s), (int) ($floor - 140 * $s), $label);
            $ly = (int) ($floor - 232 * $s);
    }
    $fit = static function (string $f, string $txt, float $size, float $max): array {
        do {
            $bb = imagettfbbox($size, 0, $f, $txt);
            $tw = $bb[2] - $bb[0];
            $size -= 1;
        } while ($tw > $max && $size > 8);

        return [$size + 1, $tw];
    };
    [$fs, $tw] = $fit($font, strtoupper($brand), 26 * $s, 190 * $s);
    imagettftext($im, $fs, 0, (int) ($cx - $tw / 2), $ly, $ink, $font, strtoupper($brand));
    [$fs2, $tw2] = $fit($fontR, $line, 15 * $s, 170 * $s);
    imagettftext($im, $fs2, 0, (int) ($cx - $tw2 / 2), (int) ($ly + 34 * $s), $ink, $fontR, $line);
    imagewebp($im, $dir.'/'.$file, 84);
    imagedestroy($im);

    $url = '/uploads/rd/'.$file;
    ImageVariants::generate($url);

    return $url;
};

$brands = [];
foreach (['Anua', 'Medicube', 'SKIN1004', 'Beauty of Joseon', 'COSRX', 'Round Lab', 'Torriden'] as $b) {
    $brands[$b] = \App\Models\Brand::firstOrCreate(['slug' => Str::slug($b)], ['name' => $b])->id;
}

$P = [
    'anua-toner' => ['Anua Heartleaf 77% Soothing Toner 250ml', 'Anua', 'Heartleaf 77%', 'bottle', ['#EEF6E6', '#BFD9A8', '#6E9A5A'], 7900, 'أنوا تونر هارتليف 77% المهدّئ 250 مل'],
    'medicube-pad' => ['Medicube Zero Pore Pad 2.0 (70 pads)', 'Medicube', 'Zero Pore Pad', 'jar', ['#FFEAF0', '#FFB6C9', '#D9476F'], 8900, 'ميديكيوب زيرو بور باد 2.0'],
    'skin1004-ampoule' => ['SKIN1004 Madagascar Centella Ampoule 100ml', 'SKIN1004', 'Centella Ampoule', 'bottle', ['#F3F0E2', '#D9CFA4', '#8B7B43'], 6900, 'سكين1004 أمبولة سنتيلا مدغشقر'],
    'boj-sun' => ['Beauty of Joseon Relief Sun: Rice + Probiotics SPF50+', 'Beauty of Joseon', 'Relief Sun', 'tube', ['#FAF3E1', '#E7D6A8', '#5F7E44'], 6500, 'بيوتي أوف جوسون واقي شمس الأرز SPF50+'],
    'cosrx-snail' => ['COSRX Advanced Snail 96 Mucin Power Essence 100ml', 'COSRX', 'Snail 96 Mucin', 'bottle', ['#FCEFDD', '#EFC995', '#B98545'], 6900, 'كوزركس إيسنس ميوسين الحلزون 96'],
    'roundlab-toner' => ['Round Lab 1025 Dokdo Toner 200ml', 'Round Lab', '1025 Dokdo', 'bottle', ['#E7F1FA', '#A9CBE8', '#2F6EA8'], 7400, 'راوند لاب تونر دوكدو 1025'],
    'torriden-serum' => ['Torriden DIVE-IN Low Molecular Hyaluronic Acid Serum 50ml', 'Torriden', 'DIVE-IN Serum', 'bottle', ['#E6F4F7', '#A6D8E3', '#2E8BA3'], 7200, 'توريدن سيروم حمض الهيالورونيك'],
    'medicube-mask' => ['Medicube Collagen Night Wrapping Mask 75ml', 'Medicube', 'Collagen Mask', 'jar', ['#FBEFF4', '#F1BFD3', '#A8335F'], 9800, 'ميديكيوب قناع الكولاجين الليلي'],
    'boj-serum' => ['Beauty of Joseon Glow Serum: Propolis + Niacinamide 30ml', 'Beauty of Joseon', 'Glow Serum', 'bottle', ['#FFF4DF', '#F5CF86', '#B07A1E'], 5900, 'بيوتي أوف جوسون سيروم البروبوليس'],
    'anua-oil' => ['Anua Heartleaf Pore Control Cleansing Oil 200ml', 'Anua', 'Cleansing Oil', 'bottle', ['#F1F8E7', '#CFE4AD', '#86A657'], 8400, 'أنوا زيت التنظيف هارتليف'],
];

$pid = [];
$i = 0;
foreach ($P as $slug => [$name, $brand, $line, $shape, $c, $fils, $ar]) {
    $img = $shot('p-'.$slug.'.webp', $c, $shape, $brand, $line);
    $p = Product::create([
        'slug' => 'rd-'.$slug, 'name' => $name, 'brand_id' => $brands[$brand], 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'price' => $fils,
        'image' => $img, 'total_sales' => 500 - $i * 31,
    ]);
    TranslationStore::put('ar', $p->translationGroup(), $p->id, 'name', $ar, Translation::STATUS_PUBLISHED);
    $pid[$slug] = $p->id;
    $i++;
}

$photos = [];
foreach (['anua-toner', 'medicube-pad', 'skin1004-ampoule', 'boj-sun', 'cosrx-snail', 'torriden-serum', 'medicube-mask', 'boj-serum'] as $slug) {
    [, $brand, $line, $shape, $c] = $P[$slug];
    $photos[$slug] = $shot('ugc-'.$slug.'.webp', [$c[0], '#F4E9E3', $c[2]], $shape, $brand, $line, 1080, 1350, true);
}

$people = [
    ['Aisha', 'Khan', 'Dubai'], ['Mariam', 'Al Mansoori', 'Abu Dhabi'], ['Priya', 'Nair', 'Sharjah'],
    ['Sara', 'Haddad', 'Dubai'], ['Fatima', 'Al Zaabi', 'Al Ain'], ['Hannah', 'Lee', 'Dubai'],
    ['Noura', 'Al Hashimi', 'Ajman'], ['Lina', 'Saleh', 'Ras Al Khaimah'], ['Reem', 'Obaid', 'Abu Dhabi'],
    ['Zainab', 'Hussain', 'Sharjah'], ['Elena', 'Petrova', 'Dubai'], ['Hessa', 'Al Ketbi', 'Fujairah'],
];
$cust = [];
foreach ($people as $n => [$f, $l, $city]) {
    $id = DB::table('customers')->insertGetId([
        'name' => $f.' '.$l, 'first_name' => $f, 'last_name' => $l, 'email' => 'rd'.$n.'@preview.test',
        'orders_count' => 2 + $n % 5, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('addresses')->insert([
        'customer_id' => $id, 'type' => 'shipping', 'is_default' => true, 'first_name' => $f, 'last_name' => $l,
        'line1' => 'Preview street '.($n + 1), 'city' => $city, 'state' => $city, 'country' => 'AE',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $cust[] = $id;
}

/* [product slug|null, customer index|null, name, rating, title, body, days ago, verified, photo?]
   A null product is a business review (product_id null): a review of the shop. */
$R = [
    ['anua-toner', 0, 'Aisha Khan', 5, 'My skin finally calmed down', 'I have tried every toner for redness and this is the one. Two weeks in, the red patches around my nose are almost gone. Arrived next day in Dubai, perfectly sealed.', 2, true, true],
    ['medicube-pad', 1, 'Mariam Al Mansoori', 5, 'Pores look smaller', 'One pad every night after cleansing. The texture on my cheeks is so much smoother and it does not sting at all. Already ordered my second jar.', 4, true, true],
    [null, 2, 'Priya Nair', 5, 'Genuine products, fast delivery', 'Ordered at 11pm and it was at my door the next afternoon. Everything had the authenticity stickers and the packaging was beautiful. My go-to K-beauty shop now.', 5, true, false],
    ['skin1004-ampoule', 3, 'Sara Haddad', 5, 'Holy grail for sensitive skin', 'Light, watery and absorbs in seconds. My skin drinks it after a day in the sun and the breakouts on my chin have settled.', 6, true, true],
    ['boj-sun', 4, 'Fatima Al Zaabi', 5, 'No white cast at all', 'Finally a sunscreen that works under makeup in UAE heat. No white cast, no pilling, and it does not make me oily by noon.', 8, true, true],
    ['cosrx-snail', 5, 'Hannah Lee', 4, 'Great hydration', 'Very hydrating and my skin looks plump in the morning. A little sticky at first, but it settles in a minute. Would buy again.', 9, true, true],
    ['roundlab-toner', 6, 'Noura Al Hashimi', 5, '', 'Gentle exfoliating toner that does not dry me out. I use it morning and night, and my makeup sits much better on top.', 11, true, false],
    ['torriden-serum', 7, 'Lina Saleh', 5, 'Dewy without being greasy', 'Pure water for the skin. I layer it under moisturiser and my face stays dewy through a full day in the office AC.', 12, true, true],
    [null, 8, 'Reem Obaid', 5, 'Customer service is amazing', 'I asked on WhatsApp which serum suits my dry skin and they replied in minutes with a whole routine. Thoughtful samples in the box too.', 14, true, false],
    ['medicube-mask', 9, 'Zainab Hussain', 4, 'Glass skin in the morning', 'My skin looks glowing the next day. It takes a while to set, so I put it on an hour before bed.', 15, true, true],
    ['boj-serum', 10, 'Elena Petrova', 5, 'The glow is real', 'Faded my dark spots in a month. It smells lovely and a tiny amount covers my whole face. Best value serum I own.', 17, true, true],
    ['anua-oil', 11, 'Hessa Al Ketbi', 5, '', 'Melts sunscreen and makeup in seconds and rinses clean. The blackheads on my nose are visibly fewer after three weeks.', 19, true, false],
    ['anua-toner', 2, 'Priya Nair', 5, 'Second bottle', 'Repurchased. Nothing else keeps my skin this calm through the summer.', 21, true, false],
    ['skin1004-ampoule', null, 'Joanna', 4, 'Nice and light', 'Lovely, light ampoule. No dramatic change yet, but my skin feels calmer and less tight.', 23, false, false],
    [null, 3, 'Sara Haddad', 5, '', 'Third order this year. Always well packed and always authentic. Free delivery over the threshold is a nice bonus.', 25, true, false],
    ['boj-sun', 0, 'Aisha Khan', 5, 'Repurchase forever', 'My fourth tube. My husband uses it too now.', 27, true, false],
    ['medicube-pad', 5, 'Hannah Lee', 3, 'Good, not life-changing', 'Gentle and nice to use, though my pores look about the same. The jar is huge and lasts long.', 30, true, false],
    ['torriden-serum', null, 'Daniel', 5, '', 'Bought it for my wife and ended up stealing it. Very hydrating for dry skin.', 33, false, false],
    ['anua-toner', 1, 'مريم المنصوري', 5, 'هدّأ بشرتي تماماً', 'أفضل تونر جربته للبشرة الحساسة. خفّ الاحمرار خلال أسبوعين ووصل الطلب في اليوم التالي مغلفاً بعناية.', 3, true, false],
    ['medicube-pad', 4, 'فاطمة الزعابي', 5, 'مسام أنعم بشكل واضح', 'أستخدم قطنة كل ليلة بعد التنظيف. ملمس بشرتي صار أنعم بكثير ولا يسبب أي حرقة.', 7, true, true],
    [null, 6, 'نورة الهاشمي', 5, 'منتجات أصلية وتوصيل سريع', 'طلبت في المساء ووصلني الطلب ظهر اليوم التالي. كل المنتجات أصلية والتغليف جميل جداً. متجري المفضل للعناية الكورية.', 10, true, false],
    ['boj-sun', 8, 'ريم عبيد', 5, 'بدون أي أثر أبيض', 'واقي شمس خفيف جداً ومناسب لحرارة الإمارات. لا يترك أثراً أبيض ولا يجعل بشرتي دهنية.', 13, true, true],
    ['skin1004-ampoule', 11, 'حصة الكتبي', 4, 'ممتاز للبشرة المتعبة', 'قوامه خفيف ويمتص بسرعة. بشرتي أهدأ بعد الشمس، فقط أتمنى لو كانت العبوة أكبر.', 16, true, false],
    ['boj-serum', 9, 'زينب حسين', 5, 'إشراقة حقيقية', 'خفّف البقع الداكنة خلال شهر. رائحته لطيفة وكمية صغيرة تكفي للوجه كاملاً.', 18, true, false],
    ['cosrx-snail', 7, 'لينا صالح', 5, '', 'ترطيب رائع، وبشرتي تبدو ممتلئة ونضرة في الصباح. سأشتريه مرة أخرى بالتأكيد.', 20, true, false],
    [null, 1, 'مريم المنصوري', 5, 'خدمة عملاء رائعة', 'سألت عبر واتساب عن روتين لبشرتي الجافة وجاءني الرد خلال دقائق مع عينات مجانية في الطلب.', 24, true, false],
];

foreach ($R as $n => [$slug, $ci, $name, $rating, $title, $body, $days, $verified, $photo]) {
    $at = now()->subDays($days)->subHours($n % 7);
    Review::create([
        'source' => 'sorina', 'product_id' => $slug ? $pid[$slug] : null,
        'customer_id' => $ci === null ? null : $cust[$ci], 'author_name' => $name,
        'author_email' => 'rd-review-'.$n.'@preview.test', 'rating' => $rating, 'title' => $title,
        'content' => $body, 'images' => ($photo && $slug && isset($photos[$slug])) ? [$photos[$slug]] : null,
        'status' => 'approved', 'verified' => $verified,
        'helpful' => ($n * 7) % 23, 'ip' => '127.0.0.1', 'created_at' => $at, 'updated_at' => $at,
    ]);
}

// Older approved reviews, so the count and the bars are a shop's, not a page's.
$fill = ['Lovely product, works as described.', 'Fast delivery and authentic.', 'Good for my combination skin.', 'Will buy again.', 'Nice texture, absorbs well.', 'Gentle on my sensitive skin.'];
$slugs = array_keys($pid);
for ($n = 0; $n < 14; $n++) {
    $at = now()->subDays(40 + $n * 6);
    Review::create([
        'source' => 'sorina', 'product_id' => $pid[$slugs[$n % count($slugs)]],
        'author_name' => ['Amal', 'Huda', 'Ritu', 'Mona', 'Kate', 'Yasmin', 'Leila'][$n % 7],
        'author_email' => 'rd-fill-'.$n.'@preview.test', 'rating' => [5, 5, 4, 5, 5, 4, 3, 5, 2, 5, 4, 5, 5, 1][$n],
        'title' => '', 'content' => $fill[$n % count($fill)], 'status' => 'approved', 'verified' => $n % 3 !== 0,
        'helpful' => $n % 4, 'ip' => '127.0.0.1', 'created_at' => $at, 'updated_at' => $at,
    ]);
}

foreach ([['pending', 'PENDING-SHOULD-NOT-SHOW'], ['pending', 'PENDING-SHOULD-NOT-SHOW-2'], ['spam', 'SPAM-SHOULD-NOT-SHOW']] as $n => [$st, $txt]) {
    Review::create(['source' => 'sorina', 'product_id' => $pid['anua-toner'], 'author_name' => 'Spammy', 'author_email' => 'x'.$n.'@spam.test', 'rating' => 5, 'content' => $txt, 'status' => $st, 'ip' => '1.2.3.4']);
}

\App\Support\ProductRating::refresh(array_values($pid));
TranslationStore::flush();
\Illuminate\Support\Facades\Cache::flush();

echo 'rd seed done: '.Review::query()->approved()->real()->count().' approved real reviews, '.count($pid)." products\n";
