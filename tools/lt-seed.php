<?php

declare(strict_types=1);

/*
 * Lane LT: tools/mac-seed.php's shop, with six of its products made the
 * newest, given real-shaped HTML copy, and run through Catalog -> Translate &
 * categorise against a FAKED Claude API (Http::fake) -- the real runner, the
 * real checks, the real store; only the answer is canned. Run through
 * tools/lt-preview.sh; never against a real database.
 *
 *   admin owner@example.com / preview-password
 *
 * After it: Anua's drafts are approved (its /ar/ page is Arabic), the others
 * wait as drafts, d'Alba's description was answered with a dropped tag and is
 * reported as rejected, and storage/framework/testing/lt-preview/suggest.json
 * holds the server's own answer to "Suggest" computed with a faked Claude.
 */

use App\Models\Setting;
use App\Services\ConcernSync\ConcernSync;
use App\Services\ConcernSync\ConcernSyncCredentials;
use App\Services\RecentLocalise\RecentConcerns;
use App\Services\RecentLocalise\RecentLocalise;
use App\Support\Locale;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/mac-seed.php';

Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
Setting::flushMap();
ConcernSyncCredentials::saveKey('sk-ant-preview-not-a-real-key');

foreach (ConcernSync::CONCERNS as $slug => $name) {
    if (! DB::table('categories')->where('slug', $slug)->exists()) {
        DB::table('categories')->insert(['slug' => $slug, 'name' => $name, 'path' => $slug, 'created_at' => now(), 'updated_at' => now()]);
    }
}

// English copy => the Arabic the fake Claude answers with.
$copy = [
    'anua' => [
        'name' => ['Anua Heartleaf 77% Soothing Toner', 'تونر Anua Heartleaf المهدّئ 77%'],
        'short_description' => ['<p>A <strong>77% heartleaf</strong> toner that calms redness and balances oily skin.</p>', '<p>تونر بنسبة <strong>77% من عشبة Heartleaf</strong> يهدّئ الاحمرار ويوازن البشرة الدهنية.</p>'],
        'description' => ['<p>Made with 77% Houttuynia Cordata Extract to soothe sensitive skin after cleansing.</p><ul><li>Calms redness and irritation</li><li>Light, watery texture</li><li>250ml</li></ul>', '<p>مصنوع بنسبة 77% من Houttuynia Cordata Extract لتهدئة البشرة الحسّاسة بعد التنظيف.</p><ul><li>يهدّئ الاحمرار والتهيّج</li><li>قوام خفيف مائي</li><li>250ml</li></ul>'],
        'ingredients' => ['<p>Houttuynia Cordata Extract (77%), Water, 1,2-Hexanediol, Glycerin, Panthenol</p>', '<p>Houttuynia Cordata Extract (77%)، Water، 1,2-Hexanediol، Glycerin، Panthenol</p>'],
        'how_to_use' => ['<p>After cleansing, pat onto the face with your hands or a cotton pad.</p>', '<p>بعد التنظيف، ربّتي به على وجهكِ بيديكِ أو بقطنة.</p>'],
    ],
    'cosrx' => [
        'name' => ['COSRX Advanced Snail 96 Mucin Power Essence', 'خلاصة COSRX Advanced Snail 96 Mucin Power'],
        'short_description' => ['<p>A 96% snail mucin essence for deep hydration and a healthy glow.</p>', '<p>خلاصة بنسبة 96% من مخاط الحلزون لترطيب عميق وإشراقة صحية.</p>'],
        'description' => ['<p>Snail Secretion Filtrate repairs and plumps dehydrated skin.</p><ul><li>Hydrates for 24 hours</li><li>100ml</li></ul>', '<p>يعمل Snail Secretion Filtrate على إصلاح البشرة الجافة وامتلائها.</p><ul><li>يرطّب لمدة 24 ساعة</li><li>100ml</li></ul>'],
        'ingredients' => ['<p>Snail Secretion Filtrate (96%), Betaine, Sodium Hyaluronate, Panthenol</p>', '<p>Snail Secretion Filtrate (96%)، Betaine، Sodium Hyaluronate، Panthenol</p>'],
    ],
    'boj' => [
        'name' => ['Beauty of Joseon Relief Sun: Rice + Probiotics SPF50+', 'واقي الشمس Beauty of Joseon Relief Sun: Rice + Probiotics SPF50+'],
        'short_description' => ['<p>A light, dewy sunscreen with rice and probiotics. SPF50+ PA++++.</p>', '<p>واقي شمس خفيف بلمسة ندية مع الأرز والبروبيوتيك. SPF50+ PA++++.</p>'],
        'description' => ['<p>Organic sunscreen that feels like a cream. No white cast.</p><ul><li>50ml</li></ul>', '<p>واقي شمس عضوي بملمس الكريم. لا يترك أثرًا أبيض.</p><ul><li>50ml</li></ul>'],
    ],
    'medi' => [
        'name' => ['Medicube Zero Pore Pad 2.0', 'أقراص Medicube Zero Pore Pad 2.0'],
        'short_description' => ['<p>Double-sided pads with 4.5% AHA and 0.45% BHA for pores and excess sebum.</p>', '<p>أقراص بوجهين تحتوي على 4.5% AHA و0.45% BHA للمسام والزيوت الزائدة.</p>'],
        'description' => ['<p>Exfoliates inside the pore and controls oil.</p><ul><li>70 pads</li></ul>', '<p>تقشّر داخل المسام وتتحكم في الزيوت.</p><ul><li>70 قرصًا</li></ul>'],
        'ingredients' => ['<p>Lactic Acid, Salicylic Acid, Zinc PCA</p>', '<p>Lactic Acid، Salicylic Acid، Zinc PCA</p>'],
    ],
    'dalba' => [
        'name' => ["d'Alba White Truffle First Spray Serum", "سيروم الرذاذ d'Alba White Truffle First"],
        'short_description' => ['<p>A mist serum with white truffle for glow and moisture.</p>', '<p>سيروم رذاذ بالكمأة البيضاء للإشراقة والترطيب.</p>'],
        // Answered with the <ul> dropped: rejected by the check, never stored.
        'description' => ['<p>Spray over make-up for a dewy finish.</p><ul><li>100ml</li></ul>', '<p>رشّيه فوق المكياج للمسة ندية. 100ml</p>'],
    ],
    'aren' => [
        'name' => ['Arencia Fresh Green Rice Mochi Cleanser', 'منظّف Arencia Fresh Green Rice Mochi'],
        'short_description' => ['<p>A mochi-textured cleanser with green rice and centella for sensitive skin.</p>', '<p>منظّف بقوام الموتشي مع الأرز الأخضر والسنتيلا للبشرة الحسّاسة.</p>'],
    ],
];

$pid = [];
$day = 0;
foreach ($copy as $key => $fields) {
    $name = $fields['name'][0];
    $id = (int) DB::table('products')->where('name', $name)->value('id');
    $pid[$key] = $id;
    $update = ['created_at' => now()->addMinutes(60 - $day++), 'updated_at' => now()];
    foreach ($fields as $f => [$en]) {
        if ($f !== 'name') {
            $update[$f] = $en;
        }
    }
    DB::table('products')->where('id', $id)->update($update);
}

$answers = [];
foreach ($copy as $fields) {
    foreach ($fields as [$en, $ar]) {
        $answers[$en] = $ar;
    }
}

Http::fake(['api.anthropic.com/*' => function (HttpRequest $req) use ($answers, $pid) {
    $content = (string) $req->data()['messages'][0]['content'];

    if (str_contains($content, '"items"')) {
        $items = json_decode(substr($content, strpos($content, '{')), true)['items'];
        $out = [];
        foreach ($items as $it) {
            $out[] = ['i' => $it['i'], 'ar' => $answers[$it['text']] ?? 'ترجمة'];
        }

        return Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['translations' => $out], JSON_UNESCAPED_UNICODE)]]]);
    }

    // Skin concerns, as Claude would answer them.
    $why = [
        $pid['cosrx'] => [['slug' => 'hydration-glow', 'reason' => '96% snail secretion filtrate and sodium hyaluronate hydrate and plump.'], ['slug' => 'redness-sensitive-skin', 'reason' => 'Panthenol soothes and supports the barrier.']],
        $pid['boj'] => [],
        $pid['medi'] => [['slug' => 'pore-care', 'reason' => 'Salicylic acid (BHA) exfoliates inside the pore.'], ['slug' => 'oily-skin', 'reason' => 'Zinc PCA and BHA control excess sebum.'], ['slug' => 'acne-blemish-care', 'reason' => 'BHA pads clear blemish-prone skin.']],
        $pid['dalba'] => [['slug' => 'hydration-glow', 'reason' => 'A glow mist; claims moisture and a dewy finish.']],
        $pid['aren'] => [['slug' => 'redness-sensitive-skin', 'reason' => 'Centella calms sensitive skin; the product says it is for sensitive skin.']],
        $pid['anua'] => [['slug' => 'oily-skin', 'reason' => 'The product says it balances oily skin.']],
    ];
    $products = [];
    foreach (json_decode(substr($content, strpos($content, "Products:\n") + 10), true) as $p) {
        $products[] = ['id' => $p['id'], 'concerns' => $why[$p['id']] ?? []];
    }

    return Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['products' => $products])]]]);
}]);

// Anua is already filed by hand in Redness & Sensitive Skin.
$redness = (int) DB::table('categories')->where('slug', 'redness-sensitive-skin')->value('id');
DB::table('category_product')->insertOrIgnore(['category_id' => $redness, 'product_id' => $pid['anua'], 'category_position' => 1]);

$ids = RecentLocalise::scope(6);
$creds = ConcernSyncCredentials::read();
do {
    $step = RecentLocalise::step($ids, $creds['key'], $creds['model']);
    echo 'step: '.$step['translated'].' drafted, '.count($step['rejected']).' rejected, '.$step['remaining']." remaining\n";
} while ($step['remaining'] > 0 && $step['translated'] > 0);

echo 'approved anua: '.json_encode(RecentLocalise::approve([$pid['anua']]))."\n";

$suggest = RecentConcerns::suggest($ids, ConcernSync::concerns(), $creds['key'], $creds['model']);
$suggest['concerns'] = array_map(fn ($s, $c) => ['slug' => $s, 'name' => $c['name']], array_keys(ConcernSync::concerns()), ConcernSync::concerns());
@mkdir(storage_path('framework/testing/lt-preview'), 0777, true);
file_put_contents(storage_path('framework/testing/lt-preview/suggest.json'), json_encode($suggest, JSON_UNESCAPED_UNICODE));
echo 'anua slug: '.DB::table('products')->where('id', $pid['anua'])->value('slug')."\n";
echo 'scope: '.json_encode($ids)."\n";
