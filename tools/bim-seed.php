<?php
/*
 * Seed the Lane BIM preview: the demo catalogue (db:seed) plus an owner and a
 * Journal article whose body carries text links in paragraphs, a list and a
 * quote, a picture wrapped in a link, a gallery with a linked picture and a
 * product grid -- in English and Arabic -- so the underline can be measured on
 * every kind of link a body holds. Preview database only.
 */
use App\Models\Post;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);


$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect width="400" height="300" fill="#f4d9e1"/><circle cx="200" cy="150" r="80" fill="#d77a97"/></svg>';
foreach ([1, 2] as $k) {
    file_put_contents(base_path('storage/framework/testing/bim-preview/webroot/uploads/bim' . $k . '.svg'), $svg);
}

$en = '<p>Double cleansing is the first step of a Korean routine. Start with <a href="/shop/">a gentle oil cleanser</a>, '
    . 'then a low-pH foam. Our <a href="/blog/">skincare journal</a> has more on the order of steps.</p>'
    . '<h3>Why the order matters</h3>'
    . '<p>Oil dissolves oil. Read <a href="https://example.com/sunscreen-study" target="_blank" rel="noopener">the sunscreen study</a> for the details.</p>'
    . '<ul><li>Morning: <a href="/shop/">toner and SPF</a></li><li>Evening: double cleanse</li></ul>'
    . '<blockquote><p>Finish with a hydrating toner while skin is still damp — <a href="/shop/">see toners</a>.</p></blockquote>'
    . '<p><a href="/shop/"><img src="/uploads/bim1.svg" alt="A linked picture" width="400" height="300"></a></p>'
    . '<div class="kbb-gal kbb-gal-2"><a href="/shop/"><img src="/uploads/bim1.svg" alt=""></a><img src="/uploads/bim2.svg" alt=""></div>'
    . '[kbb_products limit="2" columns="2"]'
    . '<p>That is all you need. Questions? <a href="/contact-us/">Ask us</a>.</p>';

$ar = '<p>التنظيف المزدوج هو الخطوة الأولى في الروتين الكوري. ابدئي بـ <a href="/ar/shop/">منظف زيتي لطيف</a>، '
    . 'ثم رغوة منخفضة الحموضة. في <a href="/ar/blog/">مدونة العناية بالبشرة</a> المزيد عن ترتيب الخطوات.</p>'
    . '<h3>لماذا يهم الترتيب</h3>'
    . '<p>الزيت يذيب الزيت. اقرئي <a href="https://example.com/sunscreen-study" target="_blank" rel="noopener">دراسة واقي الشمس</a> للتفاصيل.</p>'
    . '<ul><li>الصباح: <a href="/ar/shop/">تونر وواقي شمس</a></li><li>المساء: تنظيف مزدوج</li></ul>'
    . '<p><a href="/ar/shop/"><img src="/uploads/bim1.svg" alt="صورة" width="400" height="300"></a></p>'
    . '[kbb_products limit="2" columns="2"]'
    . '<p>هذا كل ما تحتاجينه. أسئلة؟ <a href="/ar/contact-us/">اسألينا</a>.</p>';

$tags = ['Routine', 'Ingredients', 'SPF', 'News'];
foreach (['The double-cleanse, explained', 'Centella asiatica: the calm-skin hero', 'How much sunscreen is enough?', 'What is new in K-beauty'] as $i => $title) {
    $post = Post::query()->updateOrCreate(['slug' => \Illuminate\Support\Str::slug($title)], [
        'title' => $title,
        'excerpt' => 'Honest, practical guidance for skin in the UAE climate.',
        'body' => $en,
        'tag' => $tags[$i],
        'author' => 'K-Beauty Bliss',
        'status' => 'published',
        'published_at' => now()->subDays($i + 1),
    ]);
    if ($i === 0) {
        TranslationStore::put('ar', 'posts', $post->id, 'title', 'شرح التنظيف المزدوج', Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL);
        TranslationStore::put('ar', 'posts', $post->id, 'body', $ar, Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL);
    }
}
echo 'bim seed: ' . Post::count() . " posts\n";

// Arabic on, right-to-left, as the live shop has it.
foreach ([\App\Support\Locale::SETTING_ENABLED => '1', \App\Support\Locale::SETTING_RTL => '1'] as $k => $v) {
    \App\Models\Setting::updateOrCreate(['key' => $k], ['value' => $v, 'autoload' => true]);
}
\Illuminate\Support\Facades\Cache::flush();
