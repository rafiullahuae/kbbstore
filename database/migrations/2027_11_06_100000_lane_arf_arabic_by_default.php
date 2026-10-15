<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane ARF: the Arabic shop is Arabic BY DEFAULT.
 *
 * The owner, 15 October, on /ar: "why every heading etc is inside the arrow
 * < > bracket? i need the full translation by default. and if i found
 * anything wrong, i will do it from the backend." And of the cart and the
 * checkout: "i don't need anything in english in arabic version".
 *
 * Every ⟪…⟫ he circled was an interface string whose Arabic was shipped as a
 * DRAFT (2.60.519-522) and never approved, so /ar fell back to English and the
 * review switch drew it in brackets. This does, without him pressing anything,
 * what Translation -> Progress -> "Approve all" does, in three steps:
 *
 *   1. CORRECTS the shipped drafts that still carried a Latin word a shopper
 *      reads (ArabicDraftCorrections::ROUND3, copied here as literals) -- only
 *      a row that is still a draft and still holds the shipped words.
 *   2. INSERTS, as published, the Arabic that never had a row: the live store
 *      name "K-Beauty Bliss UAE" (wording.site_name_uae) and the eight concern
 *      pages' intro paragraphs -- only where no Arabic row exists at all.
 *   3. PUBLISHES every remaining Arabic draft (status draft -> published,
 *      reviewed_at now), the Approve-all query exactly. A published row is
 *      never touched, so nothing he approved or typed changes.
 *
 * A builder update fires no model hooks, so what Translation::saved() would
 * have done is done here by hand: a product name that becomes published gets
 * its normalised copy in search_texts_ar (the normaliser of 2027_11_02_100300,
 * copied), and the translation, search and spelling caches are forgotten by
 * their literal keys. Literals and framework calls only (the 2.60.512 rule);
 * ArabicByDefaultTest pins it.
 *
 * It does NOT touch language_ar_enabled: whether /ar is on is his call.
 */
return new class extends Migration
{
    /** key => [the shipped draft, its correction]. Same as ArabicDraftCorrections::ROUND3. */
    private const CORRECTIONS = [
        'store.shop.sort_name' => ['الاسم A–Z', 'الاسم أبجديًا'],
        'store.cart.page_title' => ['السلة · K-Beauty Bliss', 'السلة · كي بيوتي بليس'],
        'store.wishlist.page_title' => ['المفضلة · K-Beauty Bliss', 'المفضلة · كي بيوتي بليس'],
        'store.home.quiz_option_combo' => ['منطقة T دهنية والخدود جافة', 'الجبهة والأنف دهنيان والخدود جافة'],
        'store.spotted.seo_desc' => ['روتينات حقيقية للعناية الكورية بالبشرة وفتح علب ونتائج يشاركها عملاء K-Beauty Bliss في الإمارات. شاهدي منشوراتهم وتسوقي المنتجات التي يحبونها.', 'روتينات حقيقية للعناية الكورية بالبشرة وفتح علب ونتائج يشاركها عملاء كي بيوتي بليس في الإمارات. شاهدي منشوراتهم وتسوقي المنتجات التي يحبونها.'],
        'store.home.about_heading' => ['عن K-Beauty Bliss', 'عن كي بيوتي بليس'],
        'store.reviews.reply_from' => ['رد من K-Beauty Bliss', 'رد من كي بيوتي بليس'],
        'store.checkout.page_title' => ['إتمام الطلب · K-Beauty Bliss', 'إتمام الطلب · كي بيوتي بليس'],
        'store.checkout.field_phone_placeholder' => ['+971 5x xxx xxxx', '+971 50 123 4567'],
        'store.checkout.whatsapp_optin' => ['أرسلوا لي تحديثات الطلب والعروض الجديدة — عبر WhatsApp والبريد الإلكتروني.', 'أرسلوا لي تحديثات الطلب والعروض الجديدة — عبر واتساب والبريد الإلكتروني.'],
        'store.checkout.ssl_secure' => ['محمي بـ SSL', 'اتصال آمن ومشفّر'],
        'store.order_received.page_title' => ['تم استلام الطلب · K-Beauty Bliss', 'تم استلام الطلب · كي بيوتي بليس'],
        'store.journal.footer_line' => ['© K-Beauty Bliss · جمال كوري أصلي في الإمارات', '© كي بيوتي بليس · جمال كوري أصلي في الإمارات'],
        'store.orders.page_title' => ['الطلبات · K-Beauty Bliss', 'الطلبات · كي بيوتي بليس'],
        'store.orders.detail_title' => ['الطلب رقم :number · K-Beauty Bliss', 'الطلب رقم :number · كي بيوتي بليس'],
        'store.account.reset_legacy_note' => ['ملاحظة: ستتوقف كلمة مرورك الأصلية في K Beauty Bliss عن العمل بمجرد حفظ هذه.', 'ملاحظة: ستتوقف كلمة مروركِ الأصلية في كي بيوتي بليس عن العمل بمجرد حفظ هذه.'],
        'store.track.page_title' => ['تتبع طلبي · K-Beauty Bliss', 'تتبع طلبي · كي بيوتي بليس'],
        'store.quiz.js_expert_body' => ['أرسل إجاباتك إلى خبيرة بشرة في K-Beauty Bliss — سنراجع روتينك ونراسلك عبر WhatsApp.', 'أرسلي إجاباتكِ إلى خبيرة بشرة في كي بيوتي بليس — سنراجع روتينكِ ونراسلكِ عبر واتساب.'],
        'store.quiz.js_label_phone' => ['الهاتف (WhatsApp)', 'الهاتف (واتساب)'],
        'email.common.sign_off' => ['— K Beauty Bliss', '— كي بيوتي بليس'],
        'email.reset.lead' => ['طلب أحدهم إعادة تعيين كلمة المرور على حسابك في K Beauty Bliss. إن كنت أنت فافتح الرابط أدناه واختر كلمة مرور جديدة.', 'طلب أحدهم إعادة تعيين كلمة المرور على حسابكِ في كي بيوتي بليس. إن كنتِ أنتِ فافتحي الرابط أدناه واختاري كلمة مرور جديدة.'],
        'store.push.order_processing_body' => ['نقوم بتجهيز طلبك من K-Beauty Bliss.', 'نقوم بتجهيز طلبكِ من كي بيوتي بليس.'],
    ];

    /**
     * key => [the shipped English, its Arabic]: interface strings that had no
     * Arabic row of any kind, written PUBLISHED.
     *
     * The eight concern intros were left English on purpose in 2.60.519
     * (ArabicInterfaceDrafts' header: SEO landing-page copy). The owner has
     * since asked for every word in Arabic and to correct what he wants from
     * the backend, so they are written here, in the shop's voice: feminine
     * address, ingredient names in Arabic, SPF as «عامل حماية».
     */
    private const NEW_ROWS = [
        'store.wording.site_name_uae' => ['K-Beauty Bliss UAE', 'كي بيوتي بليس الإمارات'],
        'store.concern.intro_acne' => [
            'Gentle, barrier-first routines for skin that breaks out. Salicylic acid and BHA to keep pores clear, centella and madecassoside to calm what is already angry, and lightweight hydration that will not sit heavily on congested skin. Every product here is one we stock and have tagged for blemish-prone skin — start with one new step at a time.',
            'روتينات لطيفة تضع حاجز البشرة أولًا، للبشرة المعرّضة للحبوب: حمض الساليسيليك وأحماض بيتا هيدروكسي لإبقاء المسام نظيفة، والسنتيلا والماديكاسوسايد لتهدئة ما التهب منها، وترطيب خفيف لا يُثقل البشرة المحتقنة. كل منتج هنا متوفر لدينا وصنّفناه للبشرة المعرّضة للحبوب، فابدئي بخطوة جديدة واحدة في كل مرة.',
        ],
        'store.concern.intro_hydration' => [
            'Dehydrated is not the same as dry, and in this climate it is the more common of the two: skin that is oily by lunchtime and tight by evening is usually short of water rather than short of oil. Korean routines answer that with layers rather than with one heavy cream — hyaluronic acid and glycerin to draw water in, panthenol and beta-glucan to hold it there, and a light occlusive on top so the day’s air conditioning does not take it straight back out. Everything here is a product we stock and have tagged for hydration. If your skin drinks a toner and still feels tight an hour later, the missing step is usually the last one, not the first.',
            'البشرة المجففة ليست كالبشرة الجافة، وفي مناخنا هي الأكثر شيوعًا بين الاثنتين: البشرة التي تلمع عند الظهيرة وتشدّ في المساء ينقصها الماء غالبًا لا الزيوت. والروتين الكوري يعالج ذلك بالطبقات لا بكريم ثقيل واحد: حمض الهيالورونيك والجلسرين لجذب الماء، والبانثينول والبيتا جلوكان للاحتفاظ به، وطبقة خفيفة عازلة في الأعلى كي لا يسحبه مكيّف الهواء طوال اليوم. كل منتج هنا متوفر لدينا وصنّفناه للترطيب. وإن كانت بشرتكِ تشرب التونر ثم تشعرين بالشدّ بعد ساعة، فالخطوة الناقصة غالبًا هي الأخيرة لا الأولى.',
        ],
        'store.concern.intro_dark_spots' => [
            'Uneven tone is the slowest thing in skincare to shift and the easiest to undo. A mark left by a spot, a patch of sun damage, a shadow that was not there last year — all of it moves on a timescale of months. Niacinamide is the everyday ingredient here, gentle enough for morning and night; vitamin C is the one most brightening routines are built around; alpha arbutin, tranexamic acid and kojic acid are the more targeted options, aimed at particular marks rather than at overall dullness. Everything here is a product we stock and have tagged for tone. The part nobody enjoys hearing: sunscreen is the treatment. Without daily SPF every serum on this page is a holding action, and six weeks is not long enough to judge any of them.',
            'تفاوت لون البشرة أبطأ ما يتغيّر في العناية بالبشرة وأسهل ما يعود. أثر حبّة، أو بقعة من أضرار الشمس، أو ظلّ لم يكن موجودًا العام الماضي، كلها تتحسن على مدى أشهر. النياسيناميد هو المكوّن اليومي هنا، لطيف بما يكفي للصباح والمساء؛ وفيتامين سي هو أساس معظم روتينات التفتيح؛ أما ألفا أربوتين وحمض الترانيكساميك وحمض الكوجيك فخيارات أكثر استهدافًا، موجّهة إلى بقع بعينها لا إلى البهتان العام. كل منتج هنا متوفر لدينا وصنّفناه لتوحيد اللون. والحقيقة التي لا يحب أحد سماعها: واقي الشمس هو العلاج. من دون واقٍ يومي يبقى كل سيروم في هذه الصفحة مجرد محاولة للصمود، وستة أسابيع لا تكفي للحكم على أيٍّ منها.',
        ],
        'store.concern.intro_ageing' => [
            'Fine lines show up first where skin is thinnest and moves most, and they look deeper on a dehydrated face than on a well-hydrated one — which is why so many Korean routines start here with moisture rather than with an active. Retinal and retinol are the long-game ingredients; peptides and niacinamide sit alongside them and are far easier to tolerate; ceramides and squalane are the support act that keeps a retinoid usable at all. Everything here is a product we stock and have tagged for fine lines. Two honest notes. Start a retinoid twice a week, not nightly, and expect the first month to be worse before it is better. And nothing on this page works without sunscreen — UV is the single largest cause of what it is trying to address.',
            'تظهر الخطوط الدقيقة أولًا حيث البشرة أرقّ وأكثر حركة، وتبدو أعمق على وجه مجفف منها على وجه مرطّب جيدًا، ولهذا تبدأ روتينات كورية كثيرة هنا بالترطيب لا بمكوّن فعّال. الريتينال والريتينول مكوّنا النفس الطويل؛ والببتيدات والنياسيناميد ترافقهما وتتحملها البشرة بسهولة أكبر؛ أما السيراميدات والسكوالان فهي الدعم الذي يجعل الريتينويد قابلًا للاستخدام أصلًا. كل منتج هنا متوفر لدينا وصنّفناه للخطوط الدقيقة. ملاحظتان صادقتان: ابدئي الريتينويد مرتين في الأسبوع لا كل ليلة، وتوقّعي أن يكون الشهر الأول أصعب قبل أن يتحسن. ولا شيء في هذه الصفحة يعمل من دون واقي الشمس، فالأشعة فوق البنفسجية هي السبب الأكبر لما يحاول معالجته.',
        ],
        'store.concern.intro_sensitivity' => [
            'Reactive skin is not a skin type you grow out of. It is usually a barrier that has been asked to do too much — too many actives at once, too hot a cleanse, or simply a year of moving between 45°C outside and dry air conditioning inside. Korean skincare is unusually good at this, because calming ingredients are the tradition rather than the specialist corner: centella asiatica, or cica, and its refined form madecassoside; heartleaf, which does similar work with a lighter feel; panthenol and ceramides on the repair side. Everything here is a product we stock and have tagged for sensitivity. One honest note. If your skin is reacting right now, the useful move is usually to take products away rather than add one — cleanse, moisturise, sunscreen, nothing else, until the stinging has stopped.',
            'البشرة المتحسسة ليست نوع بشرة تتجاوزينه مع الوقت. غالبًا هي حاجز طُلب منه أكثر من طاقته: مكوّنات فعّالة كثيرة دفعة واحدة، أو تنظيف بماء ساخن جدًا، أو ببساطة عام كامل من التنقل بين 45 درجة في الخارج وهواء المكيّف الجاف في الداخل. والعناية الكورية بارعة في هذا على نحو خاص، لأن المكوّنات المهدّئة فيها تقليد راسخ لا ركن متخصص: السنتيلا آسياتيكا، أو السيكا، وشكلها المنقّى الماديكاسوسايد؛ وعشبة الهارتليف التي تؤدي عملًا مشابهًا بملمس أخف؛ والبانثينول والسيراميدات في جانب الإصلاح. كل منتج هنا متوفر لدينا وصنّفناه للبشرة الحساسة. ملاحظة صادقة: إن كانت بشرتكِ متهيّجة الآن، فالخطوة المفيدة غالبًا أن تُنقصي المنتجات لا أن تُضيفي: تنظيف، ترطيب، واقي شمس، ولا شيء غير ذلك حتى يتوقف اللسع.',
        ],
        'store.concern.intro_pores' => [
            'Pore size is largely inherited and no product closes a pore, so the honest aim of this shelf is a pore that is clear and skin that is not shining by noon. Salicylic acid — BHA on most labels — is oil-soluble, which is why it works inside a pore rather than on top of it; niacinamide is the everyday ingredient for oil balance; clay and gentle enzyme exfoliants handle the texture; a low-pH cleanser keeps the whole thing from tipping over into stripped. Everything here is a product we stock and have tagged for pores and oil. The counter-intuitive part is real: skin that has been stripped makes more oil, not less, so the fix for a shiny face is rarely a stronger cleanser.',
            'حجم المسام وراثي إلى حد كبير، ولا يوجد منتج يُغلق المسام، لذا فالهدف الصادق لهذا الرف مسام نظيفة وبشرة لا تلمع عند الظهيرة. حمض الساليسيليك، المكتوب على معظم العبوات باسم بيتا هيدروكسي أسيد، يذوب في الزيوت، ولهذا يعمل داخل المسام لا فوقها؛ والنياسيناميد هو المكوّن اليومي لتوازن الزيوت؛ والطين والمقشّرات الإنزيمية اللطيفة تعتني بالملمس؛ والمنظف منخفض الحموضة يحمي البشرة من الجفاف المفرط. كل منتج هنا متوفر لدينا وصنّفناه للمسام والبشرة الدهنية. والجزء غير المتوقع حقيقي: البشرة التي جُرّدت من زيوتها تُفرز زيوتًا أكثر لا أقل، لذا نادرًا ما يكون حلّ الوجه اللامع منظفًا أقوى.',
        ],
        'store.concern.intro_dullness' => [
            'Dullness is usually two things at once — a layer of dead surface cells that scatters light, and skin that is short of water underneath it. Both are fixable and neither needs anything aggressive. Gentle acids, PHA and lactic acid ahead of anything stronger, deal with the surface; vitamin C and niacinamide work on tone; a hydrating essence or a sleeping mask is what actually produces the look people mean by glow, because light reflects off a well-hydrated surface and not off a dry one. Everything here is a product we stock and have tagged for dullness. If you take one thing from this page: over-exfoliating is the most common way to make dull skin duller, and twice a week is plenty.',
            'البهتان غالبًا أمران معًا: طبقة من الخلايا الميتة على السطح تشتّت الضوء، وبشرة ينقصها الماء تحتها. وكلاهما قابل للإصلاح دون أي شيء قاسٍ. الأحماض اللطيفة، أحماض البولي هيدروكسي وحمض اللاكتيك قبل أي شيء أقوى، تعتني بالسطح؛ وفيتامين سي والنياسيناميد يعملان على توحيد اللون؛ أما الإسنس المرطّب أو قناع النوم فهو ما يمنح فعلًا الإشراقة التي نقصدها، لأن الضوء ينعكس عن سطح مرطّب جيدًا لا عن سطح جاف. كل منتج هنا متوفر لدينا وصنّفناه للبشرة الباهتة. وإن أخذتِ شيئًا واحدًا من هذه الصفحة: الإفراط في التقشير أكثر الطرق شيوعًا لجعل البشرة الباهتة أبهت، ومرتان في الأسبوع تكفيان تمامًا.',
        ],
        'store.concern.intro_sun' => [
            'The UAE sun is the whole game. Sunscreen is the one step that does more for tone, texture and fine lines than everything else on this site put together, and it is the step most routines skip because most sunscreens are unpleasant to wear. Korean formulations are where that changed — light chemical filters that sink in without a white cast, hybrid and mineral options for skin that objects to the chemical ones, and finishes from dewy to genuinely matte. Everything here is a product we stock and have tagged for sun protection. What matters more than which one you buy: SPF 50 or higher, wear it every morning including indoors near a window, and reapply if you have been outside. A sunscreen you will actually put on beats a better one you will not.',
            'شمس الإمارات هي كل الحكاية. واقي الشمس هو الخطوة التي تفعل للون البشرة وملمسها وخطوطها الدقيقة أكثر مما تفعله كل المنتجات الأخرى في هذا الموقع مجتمعة، وهي الخطوة التي تتخطاها معظم الروتينات لأن أغلب واقيات الشمس مزعجة عند الاستخدام. والتركيبات الكورية هي حيث تغيّر ذلك: فلاتر كيميائية خفيفة تتغلغل دون أثر أبيض، وخيارات هجينة ومعدنية للبشرة التي لا تتقبّل الكيميائية، ولمسات نهائية من الندية إلى المطفية تمامًا. كل منتج هنا متوفر لدينا وصنّفناه للحماية من الشمس. والأهم من أي واقٍ تشترين: عامل حماية 50 أو أعلى، ضعيه كل صباح حتى في الداخل قرب النافذة، وجدّديه إن كنتِ في الخارج. الواقي الذي ستضعينه فعلًا أفضل من واقٍ أفضل منه لن تضعيه.',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $now = now();

        // 1. Corrections, on a shipped draft only.
        foreach (self::CORRECTIONS as $field => [$old, $new]) {
            DB::table('translations')
                ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', $field)->where('status', 'draft')->where('value', $old)
                ->update(['value' => $new, 'updated_at' => $now]);
        }

        // 2. The Arabic that never had a row, published.
        $existing = array_flip(DB::table('translations')
            ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->whereIn('field', array_keys(self::NEW_ROWS))
            ->pluck('field')->all());
        $insert = [];

        foreach (self::NEW_ROWS as $field => [$english, $arabic]) {
            if (isset($existing[$field])) {
                continue;
            }

            $insert[] = [
                'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('translations')->insert($insert);
        }

        // 3. "Approve all": every Arabic draft, every group. Product names first
        //    noted, so the Arabic search can find what is about to be served.
        $names = DB::table('translations')
            ->where('locale', 'ar')->where('status', 'draft')
            ->where('group', 'products')->where('field', 'name')
            ->pluck('value', 'item_id')->all();

        $published = DB::table('translations')
            ->where('locale', 'ar')->where('status', 'draft')
            ->update(['status' => 'published', 'reviewed_at' => $now, 'updated_at' => $now]);

        $this->indexSearch($names);
        $this->forgetCaches();

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo 'Arabic by default: '.count($insert)." rows added, {$published} drafts published.\n";
        }
    }

    /**
     * Only the rows this migration added, while they still hold its words.
     * The publishing is not undone: once served, a row cannot be told apart
     * from one he approved himself, and un-publishing his would be worse.
     */
    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        foreach (self::NEW_ROWS as $field => [, $arabic]) {
            DB::table('translations')
                ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', $field)->where('value', $arabic)
                ->delete();
        }

        $this->forgetCaches();
    }

    /**
     * search_texts_ar for each newly served product name, normalised exactly
     * as 2027_11_02_100300 and 2027_11_03_100000 do.
     *
     * @param  array<int|string, string>  $names
     */
    private function indexSearch(array $names): void
    {
        if ($names === [] || ! Schema::hasTable('search_texts_ar')) {
            return;
        }

        foreach ($names as $id => $name) {
            $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', (string) $name);
            $s = strtr($s, [
                'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            ]);
            $s = mb_strtolower($s);
            $s = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s)));

            if ($s !== '') {
                DB::table('search_texts_ar')->updateOrInsert(['product_id' => (int) $id], ['text' => $s]);
            }
        }
    }

    /** TranslationStore's maps, the Arabic search and the spelling dictionary, by their literal keys. */
    private function forgetCaches(): void
    {
        try {
            foreach (['kbb.translations.ar', 'kbb.translations.en', 'kbb.search.ar.v1', 'kbb.search.ar.ready', 'kbb.search.spelling.v1'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own in five minutes.
        }
    }
};
