<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * THE SHORT DESCRIPTIONS OF THE 31 OCTOBER PRODUCTS (the owner, on 2.60.631:
 * "short description is not translated. plz fix"). Lane LT3, second pass.
 *
 * 2.60.631 read each product's short description off its live page, but only
 * where the page wraps it in a <div> -- a short description saved as plain
 * text is printed in a <p class="bb-desc">, and those 23 were read as empty
 * and left English beside the price. They are fetched again (31 October) and
 * their Arabic travels here, in the same style: feminine address, the
 * English's own <span> kept where it had one, SPF/PA values kept
 * left-to-right with Unicode isolates. On /ar the short description is also
 * the page's meta description and share text (App\Support\ProductSeo), so
 * this finishes their Arabic SEO too.
 *
 * Same rules as 2.60.631: products by SLUG, a missing slug skipped, a field
 * that already has PUBLISHED Arabic never overwritten, written published with
 * the English source's sha1, running it twice changes nothing.
 */
return new class extends Migration
{
    /** slug => [name, short_description, description]; only the short description is set here. */
    private const PRODUCTS = [
        'anua-birch-70-moisture-boosting-serum' => [null, 'يجمع سيروم البتولا المعزّز للترطيب من أنوا قوة 70% من ماء البتولا البيضاء اليابانية المقوّي للبشرة مع مركّب ABC (الألانتوين والبيتين ومستخلص الخيار) للحفاظ على توازن حموضة البشرة وحبس الرطوبة فيها. كما يحتوي هذا المنتج النباتي غير المختبر على الحيوانات على البانثينول وعشرة أنواع من حمض الهيالورونيك لترطيب إضافي.', null],
        'anua-heartleaf-70-soothing-cream-mini' => [null, '<span>غنيّ بـ700,000 جزء في المليون من مستخلص عشبة القلب، يهدّئ هذا الكريم البشرة الحساسة بفعالية. ويمنح قوامه الجل-الكريمي عناية سيكا ثلاثية تجمع حمض الماديكاسيك ومستخلص السيكا وحمض الآسياتيك، ليترك البشرة هادئة ومنتعشة.</span>', null],
        'anua-retinol-0-3-niacin-renewing-serum' => [null, 'هل تُتعبكِ مشكلات البشرة مثل التجاعيد والبهتان والترهّل؟ جدّدي بشرتكِ بهذا السيروم المضاد لعلامات التقدّم في السن بقوة الريتينول والنياسيناميد. وهو غنيّ بثمانية أنواع من الببتيدات لتنعيم البشرة ومنحها مظهرًا ممتلئًا، إلى جانب السيراميد لتغذية حاجز الرطوبة. وتمنح المستخلصات النباتية المهدّئة مثل مستخلص حبوب الشوفان ومستخلص الكاميليا وزيت أوراق شجرة الشاي البشرةَ المتهيّجة استراحة تحتاجها.', null],
        'axis-y-mini-dark-spot-correcting-glow-serum-5ml' => [null, 'لا تدعي البقع الداكنة العنيدة تقف في طريق إشراقتكِ. يعمل سيروم الإشراقة المصحّح للبقع الداكنة الأكثر مبيعًا من أكسيس-واي على تخفيف فرط التصبّغ وتفتيح آثار الحبوب بمكوّنات مثل مستخلص نبق البحر والنياسيناميد ومستخلص نخالة الأرز. ويمنح مستخلص الآذريون تأثيرًا مهدّئًا. تركيبته النباتية خفيفة على البشرة ومغذّية في الوقت نفسه.', null],
        'biodance-cera-nol-gel-toner-pads' => [null, 'امنحي حاجز بشرتكِ العناية التي يستحقها مع أقراص التونر فائقة الترطيب هذه بقوامها الهلامي الفريد. فهي مشبّعة بالمياه الجليدية والدي-بانثينول والسيراميد وعشرة أنواع من حمض الهيالورونيك لترطيب البشرة بعمق وتهدئتها. للاستخدام، امسحي البشرة بقرص التونر. ولعناية أكثر تركيزًا، استخدميه كقناع تونر: ضعيه على المناطق المستهدفة لمدة 3-5 دقائق ثم انزعيه.', null],
        'biodance-first-synergy-toner' => [null, 'تونر فيرست سينرجي من بيودانس هو تونر مركّز ومرطّب للخطوة الأولى، بتركيبة من البانثينول ومركّب من 10 أنواع من حمض الهيالورونيك ومزيج نباتي مهدّئ. يساعد على تجديد الرطوبة بعد التنظيف ويدعم حاجز البشرة ويهيّئها للخطوات التالية في روتينكِ. يُمتصّ قوامه الخفيف الشبيه بالسيروم بسهولة، ليترك البشرة ناعمة ومتوازنة ومنتعشة.', null],
        'centellian24-360-shot-pdrn-active-serum' => [null, 'واجهي علامات التقدّم في السن مع هذا السيروم الغني بـPDRN والمعزّز بالكولاجين والإيلاستين ومستخلص جذور الجينسنغ لتقليل الخطوط الدقيقة وتنقية ملمس البشرة. ويمنح السيراميد NP وخميرة اللاكتوباسيلس البشرةَ خصائص مقوّية، بينما يحسّن النياسيناميد ومستخلص جذور الكركم إشراقتها. تركيبته المضادة للحساسية مناسبة لجميع أنواع البشرة بما فيها البشرة الحساسة.', null],
        'centellian24-madeca-cream-time-reverse-zero-80ml' => [null, 'يحمل الإصدار زيرو فوائد كريم ماديكا تايم ريفيرس الأصلي نفسها في مكافحة علامات التقدّم في السن، لكن بقوام أخف مثالي للاستخدام في أيام الصيف الحارة! غنيّ بمركّب TECA والريتينول والأحماض الأمينية، يهدّئ الكريم الالتهاب ويعزّز تجدّد البشرة لبشرة ناعمة ومتجدّدة ومشرقة. ويعمل السيراميد NP والفيتوستيرولات وحمض الهيالورونيك في تركيبته على تقوية حاجز البشرة والحفاظ على مستوى عالٍ من الرطوبة.', null],
        'frankly-pdrn-bounce-ball-serum' => [null, 'يعالج هذا السيروم المعزّز للإشراقة والغني بـPDRN السلمون علامات التقدّم في السن الظاهرة مثل التجاعيد والترهّل والبهتان. وتتوازن تركيبته بمزيج غني من المكوّنات المرطّبة والمهدّئة التي تمنح الخفة والراحة لأصحاب البشرة الجافة والحساسة.', null],
        'frankly-retinol-0-1-cream' => [null, 'هذا الكريم النباتي غير المختبر على الحيوانات غنيّ بالريتينول والأدينوزين والسيراميد NP لتعزيز تجدّد خلايا البشرة وتقوية حاجزها وتقليل مظهر الخطوط الدقيقة والتجاعيد. ومن مكوّناته البارزة الأخرى مستخلص السنتيلا الآسيوية والنياسيناميد لتأثير مهدّئ ومفتّح. وهو خالٍ من الإيثانول والعطور.', null],
        'haruharu-wonder-black-rice-hyaluronic-toner-fragrance' => [null, '<span>يأتي تونر الأرز الأسود بحمض الهيالورونيك المحبوب من هاروهارو وندر الآن بإصدار خالٍ من العطور، مثالي لكل من تمتلك بشرة حساسة. بتركيبة من 2,000 جزء في المليون من مستخلص الأرز الأسود الكوري لمقاومة الجذور الحرة، وحمض الهيالورونيك والبيتا جلوكان لترطيب مكثّف، يترك التونر البشرة أكثر إشراقًا وامتلاءً.</span>', null],
        'haruharu-wonder-rose-pdrn-peptide-glowy-balm-petal-pink' => [null, 'امنحي شفتيكِ إشراقة حقيقية مع العناية بـPDRN! يستمدّ غلوي بالم بالورد وPDRN والببتيد من هاروهارو وندر قوة PDRN الورد والببتيدات المجدّدة لملء الشفاه وتنعيمها. ويحمي الشفاه من التشقّق بمكوّنات مرطّبة ومغذّية مثل زيت بذور الجوجوبا وفيتامين E والسيراميد NP. كما يقشّر حمض اللاكتيك في تركيبته خلايا الجلد الميتة برفق لنتيجة ناعمة تمامًا. ويمنح إصدار بيتال بينك الشفاه لمسة وردية رومانسية تليق باسمه.', null],
        'heimish-bulgarian-rose-water-hydrogel-eye-patch' => [null, 'يمكن إذابة رقع الهيدروجيل هذه في الماء واستخدامها كإيسنس. يساعد ماء الورد البلغاري على ترطيب البشرة وتفتيحها وتحسين مظهر التجاعيد على الجبهة وحول العينين والأنف والفم.', null],
        'isntree-mugwort-calming-clay-mask-mini' => [null, 'امنحي بشرتكِ تنقية عميقة مع قناع الطين هذا الذي يجمع 50,000 جزء في المليون من مستخلص الشيح مع مسحوق أوراق الشيح ومسحوق الفاصولياء الحمراء لإزالة الأوساخ والدهون الزائدة من دون إزعاج البشرة. ويحافظ ماء أوراق الشاي الأخضر والبانثينول على مستوى عالٍ من الرطوبة. هذا المنتج بحجم ميني.', null],
        'kormesic-facial-sheet-mask-1-pc' => [null, 'يقدّم قناع الوجه الورقي من كورميسيك علاجًا غنيًا بالإيسنس لإنعاش البشرة المتعبة ومنحها دفعة من الترطيب والنعومة والإشراقة. صُمّم للعناية المنزلية السهلة، ويساعد الإيسنس المغذّي للبشرة على تجديد الرطوبة ليترك البشرة أنعم وأكثر انتعاشًا ومرونة. وبحسب التركيبة، يركّز القناع على تهدئة البشرة المجهدة أو تعزيز إشراقتها أو استعادة ترطيبها لتوهّج صحي.', null],
        'mary-may-vegan-peptide-bakuchiol-sun-stick' => [null, 'مثالي لأسلوب الحياة سريع الإيقاع، يمنح ستيك واقي الشمس العملي هذا حماية من الشمس بعامل ⁦SPF 50+ PA++++⁩. ويحتوي على مجموعة من أبرز المكوّنات المضادة لعلامات التقدّم في السن، منها الباكوتشيول لمقاومة الالتهاب والببتيدات لتعزيز إنتاج الكولاجين والتوكوفيرول لتنعيم الخطوط الدقيقة. بقوامه الناعم الحريري المدعّم بمسحوق ماص للدهون، يحافظ الستيك على البشرة منتعشة وجافة مع كل إعادة تطبيق. جميع مكوّناته صديقة للشعاب المرجانية.', null],
        'medicube-rosemary-pdrn-damage-care-hair-mask' => [null, 'وداعًا لأيام الشعر المتعب! يعيد قناع الشعر بإكليل الجبل وPDRN للعناية بالشعر التالف الحيوية إلى الخصلات الجافة والمتضرّرة بفضل مستخلص إكليل الجبل وPDRN. وتمنح الأحماض الأمينية والبروتينات الشعر مظهرًا ناعمًا وأملس وحريريًا. هذا المنتج المختبر سريريًا خالٍ من الألوان الاصطناعية ولطيف بما يكفي لفروة الرأس الحساسة.', null],
        'purito-seoul-luminous-moisture-shea-butter-body-lotion-cotton-dreamlight' => [null, 'غنيّ بزبدة الشيا ومستخلص زهرة النجمة من جيجو وزيت بذور الكاميليا، يرطّب لوشن الجسم هذا البشرة بعمق ويترك رائحة منعشة. يُمتصّ قوامه الحريري الناعم بسرعة لملمس نهائي غير دهني. تركيبته النباتية مناسبة للبشرة الحساسة.', null],
        'round-lab-birch-juice-moisturizing-pad' => [null, 'بسّطي روتين العناية ببشرتكِ مع أقراص التونر هذه المشبّعة بعصارة البتولا الغنية بالأحماض الأمينية وأنواع متعددة من حمض الهيالورونيك لترطيب مكثّف للبشرة. ويعمل مزيج البانثينول والألانتوين على تهدئة التهيّج وحماية البشرة من العوامل الخارجية.', null],
        'round-lab-birch-juice-moisturizing-tone-up-sunscreen' => [null, 'بعامل حماية ⁦SPF 50+ PA++++⁩، يحمي واقي الشمس الكيميائي هذا البشرة من الأشعة فوق البنفسجية مع الحفاظ على ترطيبها وتهدئتها بمكوّنات مثل عصارة البتولا ومستخلص البقدونس والبانثينول وغيرها. تلتصق تركيبته الخفيفة بقوام اللوشن جيدًا بالبشرة لتطبيق مريح. ويمنح لونه البيج الوردي تأثير تون أب خفيفًا لإطلالة مشرقة.', null],
        'round-lab-pine-calming-cica-pad' => [null, 'منقذ البشرة المضطربة، تعمل أقراص التونر ذات الوجهين هذه على تهدئة البشرة المتهيّجة وتبريدها بفعالية بفضل PINE CICA ACTIVER™ الذي يحتوي على مستخلص أوراق الصنوبر وخمسة مكوّنات مشتقة من السنتيلا الآسيوية. ومعزّزة بثلاثة أنواع من حمض الهيالورونيك، تجذب الأقراص الرطوبة إلى البشرة وتحافظ على ترطيبها. مرّري الوجه البارز على البشرة لتقشير لطيف، أو اتركي الوجه الأملس على البشرة النظيفة لمدة عشر دقائق لجرعة من الترطيب.', null],
        'sungboon-editor-deep-collagen-melting-patch-set' => [null, 'واجهي علامات التقدّم في السن مع مجموعة لصقات ديب كولاجين ميلتنغ من سونغبون إديتور التي تستهدف التجاعيد على الرقبة وحول العينين والفم والجبهة بمكوّنات قوية مضادة للتقدّم في السن مثل الكولاجين والببتيدات. ومن المكوّنات الفعّالة الأخرى الجلوتاثيون الذي يفتّح البشرة. وبأشكال وأحجام مختلفة تناسب مناطق الوجه المختلفة، تمنح اللصقات المشدّة للوجه ترطيبًا مكثّفًا وتأثيرًا مضادًا للتجاعيد.', null],
        'torriden-cellmazing-vita-c-brightening-ampoule' => [null, 'تستهدف أمبولة سيلمايزنغ للتفتيح بفيتامين C من توريدن فرط التصبّغ والبقع الداكنة لبشرة أكثر إشراقًا. بتركيبة من خمسة أنواع من فيتامين C النقي لتخفيف آثار الحبوب. كما تحتوي الأمبولة على الألانتوين والبانثينول لتهدئة التهيّج. وهي مختبرة من أطباء الجلد لتناسب البشرة الحساسة.', null],
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
