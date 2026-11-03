<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ARABIC FOR THE PRODUCTS ADDED SINCE 31 OCTOBER (the owner, 3 November 2026:
 * "please translate the recent added products into arabic fully including
 * everything. and give me patch"). Lane LT4, the 2.60.631 method.
 *
 * Found from the live shop (kbeautybliss.com) on 3 November: all 847 products
 * in the sitemap were fetched on /ar and their name, short description and
 * description tab read. Four still showed English in all three -- three
 * Dr.Althea products and Medicube's Coconut & Rose Deodorant -- and their
 * Arabic travels here as data, written by hand in the shop's style
 * ("<brand> - <product>", feminine address, every HTML tag of the English
 * kept where it was -- checked tag for tag, short descriptions included,
 * whether the shop prints them as plain text or as HTML -- the INCI tab left in
 * English as on every other product, "Made in Korea" as "صُنع في كوريا", the
 * brand paragraphs worded as on the brands' already-Arabic products). Every
 * other product added since 31 October already reads Arabic on /ar. Every
 * brand on /ar/brands/ already has its Arabic name, so no brand is written.
 *
 * Flagged and left alone: celimax Retinal Booster & Cream Set names its two
 * items by their Latin line names inside Arabic sentences (deliberate, as on
 * other sets); medicube PDRN Pink Vita Coating Mask still has no description
 * at all (the page shows the shop's own "No description available." fallback),
 * so there is nothing to translate until one is written.
 *
 * SEO: an Arabic product page's <title>, meta description, share card and
 * Product structured data are all drawn from these fields -- the Arabic name,
 * the brand's Arabic name and the short description, else the description's
 * opening (App\Support\ProductSeo) -- so these are the whole of its Arabic SEO.
 *
 * SAFE TO APPLY ON A SHOP THAT HAS MOVED ON: products by SLUG, never by id; a
 * slug this shop lacks is skipped; a field that already has PUBLISHED Arabic is
 * never overwritten -- what the owner typed wins; written as published with the
 * English source's sha1 (read from the row at apply time, as the translation
 * screens do), so Progress counts them done. Running it twice changes nothing.
 * LITERALS ONLY (the 2.60.512 rule), apart from the translation cache flush,
 * which is guarded.
 */
return new class extends Migration
{
    /** slug => [name, short_description, description]; null = leave that field alone. */
    private const PRODUCTS = [
        'dr-althea-aqua-marine-deep-serum' => ['الدكتورة ألثيا - سيروم أكوا مارين ديب', 'هل تمرّين بأحد تلك الأيام التي تكون فيها بشرتكِ في أمسّ الحاجة إلى الترطيب؟ اختاري سيروم أكوا مارين ديب من الدكتورة ألثيا الذي يروي عطش بشرتكِ بفضل 70% من ماء الخيزران. يستمدّ هذا السيروم لونه الأزرق الطبيعي من الغوايازولين، وهو مكوّن مشتق من البابونج يهدّئ البشرة ويحميها من المهيّجات الخارجية. كما أنه غنيّ بمستخلص الكافيار الأخضر والبانثينول لتقوية حاجز البشرة، إلى جانب PDRN النباتي ومستخلص الأغاف لتعزيز مرونتها.', '<div><b>الفوائد الرئيسية:</b></div><div><ul><li>يساعد ماء الخيزران بنسبة 70% على تجديد الرطوبة وتخفيف جفاف البشرة.</li><li>يساعد مركّب حمض الهيالورونيك ثلاثي الأبعاد على جذب الرطوبة والاحتفاظ بها لمظهر ممتلئ ونضر.</li><li>يساعد النياسيناميد على تفتيح البشرة الباهتة وتحسين عدم تجانس لونها.</li><li>يساعد البانثينول والسنتيلا الآسيوية على تهدئة البشرة والعناية بها.</li><li>يساعد PDRN النباتي والمستخلصات البحرية على دعم مرونة البشرة ومنحها مظهرًا صحيًا.</li><li>يساعد الغوايازولين على تهدئة البشرة ويمنحها عناية مهدّئة إضافية.</li><li>تركيبة خفيفة وسريعة الامتصاص تترك لمسة نهائية منتعشة ونديّة من دون أثر دهني.</li></ul></div><div>صُنع في كوريا </div><div class="kbb-bl"></div><div><b>المكوّنات الأساسية: </b></div><div><ul><li>ماء الخيزران (70%) ومركّب حمض الهيالورونيك ثلاثي الأبعاد – يساعدان على تجديد الرطوبة وتحسين ليونة البشرة والحفاظ على ترطيب يدوم طويلًا.</li><li>النياسيناميد والبانثينول وPDRN النباتي – تساعد على تفتيح البشرة والعناية بها ودعم بشرة أكثر نعومة بمظهر أكثر صحة.</li></ul><div class="kbb-bl"></div></div><div><b>طريقة الاستخدام: </b></div><div><ul><li>بعد التنظيف والتونر، ضعي 2–3 قطرات على وجهكِ.</li><li>وزّعيه برفق وربّتي عليه حتى يمتصّه الجلد بالكامل.</li><li>أتبعيه بالمرطّب المفضّل لديكِ.</li><li>استخدميه صباحًا ومساءً ضمن روتين العناية اليومي ببشرتكِ.</li></ul><div class="kbb-bl"></div></div><div>الأنسب لجميع أنواع البشرة، وخاصة البشرة الجافة والمجفّفة والباهتة والحساسة التي تحتاج إلى ترطيب خفيف ولمسة نهائية نديّة.</div><div class="kbb-bl"></div><div><b>الحجم: </b><span>30 مل</span></div><div class="kbb-bl"></div><div><b>عن العلامة التجارية: </b>تركّز الدكتورة ألثيا على عناية لطيفة وفعّالة في آن، فتبتكر منتجات بتركيبات مدروسة لدعم بشرة بمظهر أكثر صحة. تضمّ مجموعتها الكورية كريمات الحاجز والسيرومات المهدّئة وبلسم التنظيف والأقنعة وواقيات الشمس لمشكلات البشرة اليومية. ومن خلال الجمع بين مكوّنات مختارة بعناية وتركيبات حديثة، تقدّم الدكتورة ألثيا عناية بسيطة تركّز على الترطيب والتهدئة والعناية بحاجز البشرة.</div>'],
        'dr-althea-aqua-marine-jelly-mist' => ['الدكتورة ألثيا - رذاذ أكوا مارين جيلي', 'بشرة نضرة ومفعمة بالحيوية على بُعد رشّة واحدة! فالترطيب والعناية بالمرونة هما سرّ هذه النتيجة، ورذاذ أكوا مارين جيلي من الدكتورة ألثيا يجمع بينهما. يملأ هذا الرذاذ الخفيف البشرة ويجدّد رطوبتها بفضل PDRN النباتي ومياه أعماق البحر ومركّب ثلاثي من حمض الهيالورونيك. كما تمنحكِ تركيبته التي تتحوّل من جل إلى ماء إشراقة لافتة!', '<div><b>الفوائد الرئيسية:</b></div><div><ul><li>يمنح دفعة فورية من الرطوبة لإنعاش البشرة الجافة والمجفّفة.</li><li>تساعد مياه أعماق البحر من أوليونغدو بنسبة 10% على تجديد الترطيب وإحياء البشرة الباهتة.</li><li>يساعد PDRN النباتي (الحمض النووي للصوديوم) على العناية بالبشرة وتحسين مظهر تماسكها.</li><li>يساعد المركّب الثلاثي من حمض الهيالورونيك على جذب الرطوبة والاحتفاظ بها لبشرة أكثر امتلاءً ونعومة.</li><li>يساعد البيتا جلوكان والجلسرين على الحفاظ على الترطيب ودعم راحة البشرة.</li><li>رذاذ ناعم يتحوّل من جل إلى ماء ويترك لمسة نهائية منتعشة ونديّة من دون إحساس بالثقل أو اللزوجة.</li><li>يمكن استخدامه فوق المكياج لإنعاش البشرة وتعزيز إشراقتها الطبيعية.</li></ul></div><div>صُنع في كوريا </div><div class="kbb-bl"></div><div><b>المكوّنات الأساسية: </b></div><div><ul><li>PDRN النباتي ومياه أعماق البحر من أوليونغدو (10%) – يساعدان على العناية بالبشرة وتجديد رطوبتها ومنحها مظهرًا منتعشًا ومشرقًا.</li><li>المركّب الثلاثي من حمض الهيالورونيك والبيتا جلوكان – يساعدان على جذب الرطوبة والحفاظ على الترطيب وتحسين ليونة البشرة.</li></ul><div class="kbb-bl"></div></div><div><b>طريقة الاستخدام: </b></div><div><ul><li>رجّي العبوة جيدًا قبل الاستخدام لمزج التركيبة الهلامية.</li><li>أغمضي عينيكِ ورشّي الرذاذ بالتساوي على الوجه.</li><li>ربّتي برفق لامتصاص أفضل.</li><li>استخدميه بعد التنظيف كخطوة للترطيب، أو على مدار اليوم فوق المكياج لإشراقة منعشة.</li></ul><div class="kbb-bl"></div></div><div>الأنسب لجميع أنواع البشرة، وخاصة البشرة الجافة والمجفّفة والباهتة التي تحتاج إلى ترطيب خفيف ولمسة نهائية نديّة.</div><div class="kbb-bl"></div><div><b>الحجم: </b><span>100 مل</span></div><div class="kbb-bl"></div><div><b>عن العلامة التجارية: </b>تركّز الدكتورة ألثيا على عناية لطيفة وفعّالة في آن، فتبتكر منتجات بتركيبات مدروسة لدعم بشرة بمظهر أكثر صحة. تضمّ مجموعتها الكورية كريمات الحاجز والسيرومات المهدّئة وبلسم التنظيف والأقنعة وواقيات الشمس لمشكلات البشرة اليومية. ومن خلال الجمع بين مكوّنات مختارة بعناية وتركيبات حديثة، تقدّم الدكتورة ألثيا عناية بسيطة تركّز على الترطيب والتهدئة والعناية بحاجز البشرة.</div>'],
        'dr-althea-pore-refresh-grinding-cleansing-balm' => ['الدكتورة ألثيا - بلسم التنظيف بور ريفريش غرايندينغ', 'يذيب بلسم التنظيف هذا بفعالية المكياج العنيد والأوساخ وشوائب البشرة من دون أن يترك أي إحساس دهني. وهو غنيّ بمستخلص الشاي الأخضر وزيت فول الصويا وسلفونات الغوايازولين الصوديومية لتقليل الدهون الزائدة، مع منح البشرة ترطيبًا وتهدئة. تركيبته نباتية وغير مختبرة على الحيوانات، وخالية من العطور والألوان الاصطناعية. مناسب لجميع أنواع البشرة بما فيها البشرة الحساسة.', '<div><b>الفوائد الرئيسية:</b></div><div><ul><li>يذيب بفعالية المكياج والمنتجات المقاومة للماء وواقي الشمس والشوائب اليومية.</li><li>يساعد على إزالة الزهم الزائد وتنظيف المسام لبشرة تبدو منتعشة.</li><li>يوفّر مستخلص الشاي الأخضر عناية مضادة للأكسدة ويساعد على العناية بالبشرة الدهنية.</li><li>تساعد زيوت الجوجوبا وفول الصويا ونخالة الأرز على تغذية البشرة والحفاظ على رطوبتها.</li><li>تساعد سلفونات الغوايازولين الصوديومية على تهدئة البشرة وتقليل مظهر الاحمرار.</li><li>قوام يتحوّل من السوربيه إلى زيت، يستحلب بسهولة ويُشطف من دون أن يترك أثرًا ثقيلًا.</li><li>موزّع الطحن الصحي يتيح استخدامًا مريحًا وكمية مضبوطة من المنتج.</li></ul></div><div>صُنع في كوريا </div><div class="kbb-bl"></div><div><b>المكوّنات الأساسية: </b></div><div><ul><li>مستخلص الشاي الأخضر وسلفونات الغوايازولين الصوديومية – يساعدان على العناية بالبشرة ومنحها حماية مضادة للأكسدة وتهدئة الاحمرار الظاهر.</li><li>زيت بذور الجوجوبا وزيت فول الصويا وزيت نخالة الأرز – تساعد على إذابة المكياج وتغذية البشرة والحفاظ على رطوبتها.</li></ul><div class="kbb-bl"></div></div><div><b>طريقة الاستخدام: </b></div><div><ul><li>أديري قاعدة العبوة باتجاه عقارب الساعة لإخراج كمية مناسبة.</li><li>ضعيه على بشرة جافة ودلّكي برفق بحركات دائرية لإذابة المكياج والشوائب.</li><li>أضيفي قليلًا من الماء ليتحوّل البلسم إلى قوام حليبي.</li><li>اشطفي جيدًا بالماء الفاتر، ثم أتبعيه بمنظّف مائي إذا رغبتِ.</li></ul><div class="kbb-bl"></div></div><div>الأنسب لجميع أنواع البشرة، بما فيها الدهنية والمختلطة والحساسة، وخاصة لمن تعاني من الزهم الزائد والمسام المسدودة وبقايا المكياج.</div><div class="kbb-bl"></div><div><b>الحجم: </b><span>50 مل</span></div><div class="kbb-bl"></div><div><b>عن العلامة التجارية: </b>تركّز الدكتورة ألثيا على عناية لطيفة وفعّالة في آن، فتبتكر منتجات بتركيبات مدروسة لدعم بشرة بمظهر أكثر صحة. تضمّ مجموعتها الكورية كريمات الحاجز والسيرومات المهدّئة وبلسم التنظيف والأقنعة وواقيات الشمس لمشكلات البشرة اليومية. ومن خلال الجمع بين مكوّنات مختارة بعناية وتركيبات حديثة، تقدّم الدكتورة ألثيا عناية بسيطة تركّز على الترطيب والتهدئة والعناية بحاجز البشرة.</div>'],
        'medicube-coconut-rose-deodrant-83g' => ['ميديكوب - مزيل العرق بجوز الهند والورد', '<p>يجمع مزيل العرق بجوز الهند والورد من ميديكوب بين التحكّم اليومي في الروائح وعناية بمنطقة الإبط مستوحاة من العناية بالبشرة. وبفضل غناه بـPDRN والنياسيناميد والجلوتاثيون، يساعد على تحسين مظهر عدم تجانس لون الإبط، فيما يحافظ البانثينول والسيراميد على ترطيب البشرة. ويترك ملمسه الناعم الجاف كالبودرة رائحة زهرية منعشة بلمسات رقيقة من جوز الهند والورد.</p>', '<div><b>الفوائد الرئيسية:</b></div><div><ul><li>يساعد على التحكّم في روائح الإبط غير المرغوبة مع انتعاش يدوم حتى 24 ساعة.</li><li>يساعد PDRN المستخلص من السلمون والنياسيناميد والجلوتاثيون على تحسين مظهر عدم تجانس لون الإبط وملمسه.</li><li>يساعد البانثينول والسنتيلا الآسيوية والسيراميد NP على ترطيب بشرة الإبط الرقيقة والعناية بها.</li><li>يساعد هيدروكسيد المغنيسيوم على التحكّم في الروائح من دون الاعتماد على مكوّنات مضادة للتعرّق قائمة على الألومنيوم.</li><li>تركيبة ناعمة الانزلاق تُمتصّ براحة وتترك ملمسًا جافًا كالبودرة من دون أثر دهني أو بقع بيضاء.</li><li>يتميّز برائحة منعشة من جوز الهند والورد مع لمسات رقيقة من المسك ونفحات نظيفة تشبه الصابون.</li></ul></div><div>صُنع في كوريا </div><div class="kbb-bl"></div><div><b>المكوّنات الأساسية: </b></div><div><ul><li>PDRN السلمون والنياسيناميد والجلوتاثيون – تساعد على العناية ببشرة الإبط وتحسين مظهر عدم تجانس اللون والملمس الخشن.</li><li>البانثينول والسنتيلا الآسيوية والسيراميد NP – تساعد على تهدئة البشرة وترطيبها والحفاظ على راحتها.</li></ul><div class="kbb-bl"></div></div><div><b>طريقة الاستخدام: </b></div><div><ul><li>نظّفي منطقة الإبط وجفّفيها جيدًا قبل الاستخدام.</li><li>أديري الستيك إلى الأعلى ومرّريه مرة أو مرتين بسلاسة على كل إبط.</li><li>اتركي التركيبة حتى تُمتصّ وتجفّ قبل ارتداء ملابسكِ.</li><li>استخدميه يوميًا وأعيدي وضعه عند الحاجة، خاصة بعد ممارسة الرياضة.</li></ul><div class="kbb-bl"></div></div><div>مثالي لمن تبحث عن تحكّم يومي في الروائح إلى جانب ترطيب الإبط وعناية مستوحاة من العناية بالبشرة، وخاصة لمن تعاني من عدم تجانس اللون الظاهر والملمس الخشن.</div><div class="kbb-bl"></div><div><b>الحجم: </b><span>83 غ</span></div><div><b><br>عن العلامة التجارية: </b>انطلاقًا من تقنيات الجمال الكورية، تجمع ميديكوب بين تركيبات العناية بالبشرة وحلول متقدّمة مصمّمة حول مشكلات البشرة اليومية. وتضم مجموعتها الكورية منتجات العناية بالمسام والترطيب والإشراقة والمرونة وحاجز البشرة، إلى جانب أجهزة التجميل المبتكرة AGE-R. وبتركيبات قائمة على الأبحاث وتقنيات تجميل منزلية، تقدّم ميديكوب نهجًا أكثر استهدافًا للعناية العصرية بالبشرة.</div>'],
    ];

    private const BRANDS = [];

    private const FIELDS = ['name', 'short_description', 'description'];

    public function up(): void
    {
        if (! Schema::hasTable('translations') || ! Schema::hasTable('products')) {
            return;
        }

        $written = 0;
        $skipped = 0;
        $products = DB::table('products')->whereIn('slug', array_keys(self::PRODUCTS))->get(['id', 'slug', 'name', 'short_description', 'description']);

        foreach ($products as $p) {
            foreach (self::PRODUCTS[$p->slug] as $i => $value) {
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }

                $field = self::FIELDS[$i];
                $this->put('products', (int) $p->id, $field, $value, (string) ($p->{$field} ?? '')) ? $written++ : $skipped++;
            }
        }

        if (Schema::hasTable('brands')) {
            foreach (DB::table('brands')->whereIn('name', array_keys(self::BRANDS))->get(['id', 'name']) as $b) {
                $this->put('brands', (int) $b->id, 'name', self::BRANDS[$b->name], (string) $b->name) ? $written++ : $skipped++;
            }
        }

        try {
            if (class_exists(\App\Services\Translation\TranslationStore::class)) {
                \App\Services\Translation\TranslationStore::flush();
            }
        } catch (\Throwable) {
            // The caches expire on their own.
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Arabic product text: {$written} field(s) written, {$skipped} already had Arabic and were left as they are.\n";
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations') || ! Schema::hasTable('products')) {
            return;
        }

        // Only rows still holding exactly this package's text: anything the
        // owner has edited since is his and stays.
        $ids = DB::table('products')->whereIn('slug', array_keys(self::PRODUCTS))->pluck('id', 'slug');

        foreach (self::PRODUCTS as $slug => $values) {
            foreach ($values as $i => $value) {
                if ($value !== null && isset($ids[$slug])) {
                    DB::table('translations')->where(['locale' => 'ar', 'group' => 'products', 'item_id' => $ids[$slug], 'field' => self::FIELDS[$i], 'value' => $value])->delete();
                }
            }
        }

        foreach (self::BRANDS as $english => $arabic) {
            if (Schema::hasTable('brands') && ($id = DB::table('brands')->where('name', $english)->value('id')) !== null) {
                DB::table('translations')->where(['locale' => 'ar', 'group' => 'brands', 'item_id' => $id, 'field' => 'name', 'value' => $arabic])->delete();
            }
        }
    }

    private function put(string $group, int $id, string $field, string $value, string $english): bool
    {
        $key = ['locale' => 'ar', 'group' => $group, 'item_id' => $id, 'field' => $field];
        $row = DB::table('translations')->where($key)->first(['id', 'status', 'value']);

        if ($row !== null && $row->status === 'published' && trim((string) $row->value) !== '') {
            return false;
        }

        $data = ['value' => $value, 'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
            'reviewed_at' => now(), 'updated_at' => now()];

        $row === null
            ? DB::table('translations')->insert($key + $data + ['created_at' => now()])
            : DB::table('translations')->where('id', $row->id)->update($data);

        return true;
    }
};
