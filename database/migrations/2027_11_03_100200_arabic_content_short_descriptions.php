<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane ARC, phase 2: each live product's SHORT DESCRIPTION in Arabic, as data.
 * Same rules as 2027_11_03_100000: keyed by slug, published, only where no
 * Arabic row exists, live catalogue only, literals and framework calls only.
 * The HTML is the English field's own simple markup (p, ul/li, strong, br);
 * INCI names, SPF/PA figures and sizes are kept readable.
 */
return new class extends Migration
{
    private const DATA = <<<'KBBARC'
{
"groups": {
"products": {
"short_description": {
"abib-airy-sunstick-smoothing-bar": "<p>ستيك حماية من الشمس واسع الطيف بعامل حماية 50+ وحماية ++++، غني بمكوّنات طبيعية مثل الألانتوين وزيت بذور دوار الشمس، يصدّ الأشعة فوق البنفسجية بسهولة بينما يهدّئ البشرة ويقوّيها. تركيبته آمنة على الشعاب المرجانية ومقاومة للماء، وتترك لمسة شبه مطفية دون أثر أبيض. تصميمه المنحني بعرض 5.7 سم يجعل التطبيق سلسًا، ومثالي لتجديد الحماية أثناء التنقّل.</p>",
"abib-collagen-eye-patch-jericho-rose-jelly": "أنعشي عينيكِ المتعبتين وخفّفي الانتفاخ مع لصقات العين هذه. كل لصقة هيدروجيل غنية بالكولاجين النباتي وزبدة الشيا والببتيد، وتترك منطقة العين مشرقة ومشدودة. معزّزة بحمض الهيالورونيك والكافيين والسيراميد لتجديد البشرة وتنشيطها وترطيبها.",
"abib-glutathiosome-dark-spot-pad-vita-touch": "قطن تونر غني بفيتامين سي والنياسيناميد والجلوتاثيون يحدّ من إنتاج الميلانين للتخفيف من التصبّغات والبقع الداكنة. القطن مشبع بالإيسنس لترطيب عميق، ومناسب للبشرة الحساسة.",
"abib-glutathiosome-dark-spot-serum-vita-drop": "سيروم معزّز للإشراقة بمكوّنات مفتّحة مثل الجلوتاثيون والنياسيناميد وحمض الأسكوربيك. يعتمد على تقنية جلوتاثيوسوم الخاصة بأبيب، بحبيبات دقيقة من الجلوتاثيون تتفتّت وتمتصّها البشرة عند التطبيق. مناسب لجميع أنواع البشرة بما فيها الحساسة.",
"abib-heartleaf-essence-calming-pump": "إيسنس مهدّئ غير لزج بخلاصة عشبة القلب، يقشّر البشرة بلطف ويعتني بالدهون ليتركها مشرقة وصافية. ويهدّئ أيضًا البشرة المتهيّجة ويخفّف الإجهاد الناتج عن العوامل الخارجية.",
"abib-hydration-gel-water-tube": "جل خفيف وعالي الترطيب يمنح البشرة الترطيب الذي تحتاجه دون أي ملمس لزج، ويحبس الرطوبة لبشرة نديّة تدوم طويلًا.",
"abib-pdrn-collagen-lip-mask-glazed-jelly": "دلّلي شفتيكِ بلمعة عالية مع قناع الشفاه من أبيب الذي يرطّب الشفاه المتشقّقة وينعّمها ويليّنها. تركيبته المختبرة جلديًا غنية بـ بي دي آر إن المستخلص من السنتيلا والجنكة، إلى جانب الكولاجين وخلاصة الأرز وحمض الهيالورونيك، لتعزيز المرونة وترميم حاجز البشرة. قوامه الجيلي المرن يترك لمسة لامعة غير لزجة، ورائحته الخفيفة الشبيهة بالحلوى تضيف لمسة من الدلال.",
"abib-pdrn-retinal-eye-patch-glow-jelly": "أطلقي إشراقتكِ مع لصقات العين بي دي آر إن والريتينال من أبيب! تمنح لصقات الهيدروجيل هذه العيون الباهتة والمتعبة حيوية وإشراقة بالريتينال و بي دي آر إن النباتي المستخلص من السيكا والجنكة والخيزران. وهي غنية أيضًا بمكوّنات منعشة ومضادة لعلامات التقدّم في السن مثل النياسيناميد والكافيين والكولاجين منخفض الوزن الجزيئي، فتعمل اللصقات الوردية المهدّئة بفعالية على تخفيف الانتفاخ والهالات والبقع الداكنة. استخدميها أيضًا على مناطق أخرى معرّضة للتجاعيد مثل خطوط الابتسامة والجبهة والرقبة لعناية شاملة.",
"abib-quick-sunstick-protection-bar": "احمي بشرتكِ من الشمس الحارقة مع ستيك واقي الشمس الكيميائي! يوفّر حماية بعامل 50+ وحماية ++++، وتنزلق تركيبته غير اللزجة على البشرة بسلاسة وتترك طبقة شفافة بلمسة طبيعية دون أثر أبيض. يمكن إعادة وضعه فوق المكياج لحماية مستمرة. يحتوي على ببتيد الأكاسيا والسيراميد لتقوية حاجز البشرة، وخلاصات طبيعية مهدّئة مثل الألوة والخيار.",
"abib-rice-probiotics-overnight-mask-barrier-jelly": "قناع ليلي بقوام جيلي يحتوي على البروبيوتيك لتغذية البشرة، واللاكتوباسيلس لتقوية حاجزها وحمايتها من العوامل البيئية، وخلاصة نخالة الأرز ليتركها ناعمة ومشدودة.",
"anua-7-rice-ceramide-hydrating-barrier-serum": "<p>سيروم غني بماء نخالة الأرز لتفتيح البشرة وتنقيتها، وخلاصة العسل لتقوية حاجز البشرة، وألفا أربوتين للتخفيف من البقع الداكنة والتصبّغات. سيروم قوي يجدّد البشرة الباهتة والمتعبة، خالٍ من العطور ومختبر جلديًا ليناسب جميع أنواع البشرة.</p>",
"anua-all-in-1-glow-essentials-5-step-skincare-set": "استمتعي بتجربة عناية متكاملة مع مجموعة أنوا لروتين العناية بخمس خطوات، روتين متناغم مصمّم لتهدئة بشرتكِ وتجديدها فورًا. تضم المجموعة المختارة بعناية أفضل منتجات أنوا التي تعتمد على خلاصة عشبة القلب لتمنح بشرتكِ واحة من الهدوء. سواء كنتِ جديدة على العناية بالبشرة أو خبيرة فيها، ستبسّط هذه المجموعة روتينكِ اليومي وترتقي به.",
"anua-azelaic-10-hyaluron-redness-soothing-pad": "يقدّم قطن أزيليك 10 هيالورون المهدّئ للاحمرار من أنوا عناية مركّزة بالاحمرار والدهون الزائدة والبشرة المعرّضة للحبوب. غني بحمض الأزيليك وعدّة أنواع من حمض الهيالورونيك ومركّب سيكا من سبعة مكوّنات، ويساعد قطن التونر الكوري هذا على تهدئة الاحمرار المرتبط بالجفاف وتجديد الترطيب وتحسين مظهر الملمس غير المتجانس. ويمكن وضع قماشه المرن على المناطق المزعجة كقناع صغير، ما يجعله إضافة عملية لروتين البشرة الحساسة أو المجففة أو المحتقنة.",
"anua-azelaic-acid-10-hyaluron-redness-soothing-serum": "سيروم خفيف يعالج مشاكل البشرة مثل الحبوب والوردية، بفضل تركيبته الغنية بمكوّنات مهدّئة منها حمض الأزيليك والبانثينول وعصير الألوة وخلاصة السنتيلا. ويساعد النياسيناميد على تخفيف التصبّغات، بينما يمنح حمض الهيالورونيك ترطيبًا يدوم طويلًا.",
"anua-bha-2-gentle-exfoliating-toner-150ml": "مقشّر لطيف للوجه يساعد على فتح المسام المسدودة وإزالة الخلايا الميتة وتوحيد لون البشرة.",
"anua-birch-70-moisture-boosting-cream-50ml": "يستمدّ كريم البتولا المعزّز للترطيب من أنوا قوته من ماء البتولا الأبيض الياباني بنسبة 70% ومركّب إيه بي سي (الألانتوين والبيتايين وخلاصة الخيار)، فيحافظ على توازن حموضة البشرة ويحبس الرطوبة. المنتج نباتي وخالٍ من القسوة، ويحتوي أيضًا على البانثينول وعشرة أنواع من حمض الهيالورونيك لمزيد من الترطيب.",
"anua-collagen-retinol-refining-gua-sha-cream": "استغني عن جلسات العيادة واستمتعي بتجربة سبا في البيت مع كريم الغوا شا هذا! يجمع الثلاثي المضاد لعلامات التقدّم في السن: الريتينول والكولاجين والببتيدات، في تركيبة لطيفة داخل عبوة مبتكرة برأس معدني على شكل حرف يو يوزّع المنتج ويطبّقه بسهولة. وأداة الغوا شا المدمجة مصمّمة لتدليك خط الفك والرقبة.",
"anua-combination-skin-set": "مجموعة عناية تهدف إلى التوازن المثالي بين الترطيب والتحكّم بالدهون للبشرة المختلطة، لتبقى صافية وصحية ومتوازنة.",
"anua-heartleaf-70-daily-lotion-200ml": "<p>لوشن مغذٍّ يمنح ترطيبًا عميقًا بينما يهدّئ حاجز البشرة الطبيعي ويرمّمه، غني بخلاصة عشبة القلب بنسبة 70% وخلاصة أوراق الريحان وثلاثة أنواع من حمض الهيالورونيك. جميع مكوّناته معتمدة من مجموعة العمل البيئي، فهو آمن حتى لأكثر أنواع البشرة حساسية. مختبر جلديًا ومثبت أنه لا يسبّب الحساسية، وهو خياركِ الأمثل لبشرة مثالية.</p>",
"anua-heartleaf-70-intense-calming-cream-50ml": "هدّئي البشرة الحساسة والمتهيّجة بهذا الكريم الغني بخلاصة عشبة القلب بنسبة 70% لفوائدها المضادة للالتهاب. يعمل السيراميد إن بي مع البانثينول على تقوية حاجز الرطوبة في البشرة، بينما يقدّم الأدينوسين عناية مضادة لعلامات التقدّم في السن.",
"anua-heartleaf-70-soothing-cream-100ml": "<p>كريم عشبة القلب 70% المهدّئ من أنوا غني بـ 700,000 جزء في المليون من خلاصة عشبة القلب لتهدئة بشرتكِ وإنعاشها. قوامه الجل الكريمي خفيف لكنه مرطّب، ويترك بشرتكِ منتعشة ومتجدّدة. وبقوة العناية الثلاثية بالسيكا، حمض الماديكاسيك وخلاصة السيكا وحمض الآسياتيك، يعمل الكريم بفعالية على البشرة المتهيّجة ويعزّز حاجزها لترطيب يدوم طويلًا.</p>",
"anua-heartleaf-77-clear-pad": "قطن تونر مشبع بتونر عشبة القلب 77% المهدّئ من أنوا، يمنح ترطيبًا وفيرًا ويهدّئ تهيّج البشرة. يحتوي على مزيج من الخلاصات النباتية للتحكّم بإفراز الدهون وشدّ المسام الواسعة. وله جهة بارزة لإزالة الخلايا الميتة والشوائب لبشرة ناعمة وملساء.",
"anua-heartleaf-77-soothing-toner-250ml": "تونر عالي الترطيب غني بخلاصة عشبة القلب بنسبة 77% إلى جانب أحد عشر مكوّنًا آخر مصنّفًا بالأخضر من مجموعة العمل البيئي، لتهدئة البشرة الحساسة وحمايتها. ودرجة حموضته المعتدلة تساعد على تنظيم توازن الدهون والرطوبة في البشرة.",
"anua-heartleaf-80-soothing-ampoule-30ml": "<p>أمبولة بخلاصة عشبة القلب بنسبة 80%، خياركِ الأمثل لتهدئة التهيّج وتقليل الاحمرار وتعزيز الترطيب. مصمّمة لجميع أنواع البشرة، وتُمتصّ بسرعة لتترك بشرتكِ منتعشة وممتلئة دون أي لزوجة.</p>",
"anua-heartleaf-intense-soothing-set": "مجموعة عشبة القلب المهدّئة المكثّفة من أنوا روتين عناية متكامل لتهدئة البشرة الحساسة وتغذيتها. بقوة خلاصة عشبة القلب المستخلصة من نبتة هوتوينيا كورداتا المعروفة بخصائصها المهدّئة، تقدّم المجموعة حلولًا لطيفة وفعّالة لمن تبحث عن راحة من التهيّج والحبوب والاحمرار.",
"anua-heartleaf-lha-moisture-peeling-gel": "جل تقشير لطيف بحمض إل إتش إيه وخلاصة عشبة القلب، يزيل الخلايا الميتة والشوائب ويهدّئ البشرة لنتيجة ناعمة ومشرقة. ضعي كمية مناسبة على الوجه بعد تنظيفه مع تجنّب منطقة العينين، ودلّكيه بلطف بحركات دائرية، ثم اشطفيه بالماء الفاتر.",
"anua-heartleaf-pore-control-cleansing-oil-200ml": "<p>زيت تنظيف لا يسدّ المسام بمزيج قوي من الزيوت النباتية، منها زيت الجوجوبا وثمار الزيتون وبذور العنب، يذيب المكياج والدهون الزائدة والشوائب التي تسدّ المسام بلطف وفعالية. غني بخلاصة عشبة القلب، وتركيبته المهدّئة مثالية للبشرة المعرّضة للحبوب والحساسة، إذ تساعد على تخفيف الاحمرار والتهيّج وتمنح بشرة صحية ومشرقة. مصنوع في كوريا، ويترك بشرتكِ مرطّبة دون أي بقايا دهنية، ما يجعله إضافة مهمة لروتين عنايتكِ.</p>",
"anua-heartleaf-pore-control-cleansing-oil-mild": "<p>النسخة اللطيفة من زيت التنظيف بعشبة القلب للتحكم بالمسام من أنوا، مصمّمة خصيصًا للبشرة الحساسة. زيت تنظيف لطيف لا يسدّ المسام، يخلّص البشرة من الشوائب بفعالية ويتركها منتعشة ومرطّبة. تركيبته الخالية من العطور مكوّنة من عدد قليل من المكوّنات الآمنة للبشرة الحساسة، منها خلاصة عشبة القلب للوقاية من التهيّج، وزيتا أوراق إكليل الجبل وبذور دوار الشمس لترطيب البشرة والتحكّم بالدهون الزائدة.</p>",
"anua-heartleaf-pore-control-cleansing-oil-mini": "زيت التنظيف بعشبة القلب للتحكم بالمسام من أنوا متوفّر الآن بحجم صغير! تركيبته لا تسدّ المسام، ويخلّص البشرة من الشوائب وبقايا المكياج بفعالية ويتركها منتعشة ومرطّبة. وتساعد خلاصة عشبة القلب وزيوت نباتية من الجوجوبا وثمار الزيتون وبذور العنب على الوقاية من التهيّج. مناسب للبشرة المعرّضة للحبوب.",
"anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml": "نظّفي بشرتكِ مع رغوة التنظيف الغنية بمسحوق عشبة القلب لإزالة الخلايا الميتة وتنقية المسام. تركيبتها الحبيبية غنية بالغليسرين لترطيب البشرة، وحمض الساليسيليك للعناية بالمسام ومكافحة الحبوب. يُنصح بها للبشرة المعرّضة للحبوب.",
"anua-heartleaf-silky-moisture-sun-cream": "<p>كريم شمس بفلاتر كيميائية يوفّر حماية واسعة الطيف بعامل حماية 50+ وحماية ++++. يمنح البشرة فوائد مهدّئة ومرطّبة بمكوّنات مثل خلاصة عشبة القلب والبانثينول. وبفضل تأثيره في التحكّم بالدهون، يثبت جيدًا تحت كريم الأساس ويطيل ثبات المكياج.</p>",
"anua-heartleaf-soothing-trial-kit": "تضم مجموعة تجربة عشبة القلب المهدّئة من أنوا تونر ولوشن وأمبولة وقطن تنظيف، جميعها بخلاصة عشبة القلب لخصائصها المضادة للالتهاب. أضيفيها إلى روتينكِ عندما تحتاجين إلى حماية البشرة من العوامل الخارجية وتهدئة الاحمرار والتهيّج. جميع المنتجات خالية من القسوة ومختبرة سريريًا.",
"anua-niacinamide-10-txa-4-dark-spot-correcting-serum-30ml": "عالجي التصبّغات مع هذا السيروم بالنياسيناميد 10% وحمض الترانيكساميك 4% للتخفيف من البقع الداكنة وآثار الحبوب. يحتوي على ألفا أربوتين لتفتيح البشرة وتوحيد لونها، وحمض الهيالورونيك وزيوت نباتية لترطيبها وتهدئتها. يخفّف بفعالية البقع الداكنة العنيدة والتصبّغات وآثار الحبوب وبقع الشمس وغيرها من أشكال تغيّر اللون، ويساعد على منع ظهور بقع جديدة. بثلاثي قوي من المكوّنات الفعّالة: نياسيناميد 10% وحمض الترانيكساميك 4% وأربوتين 2%، لاستهداف البهتان وعدم توحّد اللون والمسام الواسعة.",
"apieu-honey-milk-lip-oil": "زيت شفاه بالعسل وخلاصة بروتين الحليب يكوّن طبقة مرطّبة على الشفاه تُبقيها ناعمة وممتلئة وجذّابة. استخدميه وحده لشفاه ناعمة بلمعة طبيعية، أو فوق صبغة الشفاه للمزيد من اللمعان.",
"apieu-honey-milk-lip-oil-light": "حافظي على شفاه مغذّاة ومرطّبة مع زيت الشفاه بالعسل وخلاصة بروتين الحليب. مزيج زيوت بذور الجوجوبا والزيتون وبذور الميدوفوم يكوّن طبقة مرطّبة تحبس الرطوبة دون أي ملمس لزج. ضعيه فوق مكياج الشفاه لمزيد من اللمعان والامتلاء. متوفّر برائحة النعناع أو الخوخ.",
"apieu-honey-milk-lip-scrub": "مقشّر شفاه بخلاصات بروتين الحليب وفيتامين إي يساعد على شدّ بشرة الشفاه وترطيبها وحمايتها، بينما يزيل الخلايا الميتة بلطف ويترك شفتيكِ ناعمتين ومخمليتين.",
"apieu-mint-scalp-hair-vinegar": "بلسم مرطّب مضاد للقشرة بالخلّ ومركّب النعناع الثلاثي والفركتان، ينظّف فروة الرأس ويهدّئها مع الحفاظ على توازن الزيوت والرطوبة، ويترك الشعر منتعشًا ومفعمًا بالحيوية.",
"apieu-raspberry-hair-vinegar": "غسول شعر مرطّب غني بخلاصة التوت والكيراتين النباتي والخلّ وخلاصات عشبية إضافية، يمنح فروة الرأس تغذية مكثّفة ويوازن درجة حموضتها، ويساعد على تهدئة الفروة والشعر وتنقيتهما.",
"apieu-raspberry-vinegar-hair-mist": "رذاذ للشعر يحافظ على توازن الزيوت والماء في فروة الرأس بـ 10,000 جزء في المليون من الخلّ، ويغذّي الشعر بـ 20,000 جزء في المليون من خلاصة التوت. يحتوي أيضًا على الكيراتين النباتي لخصلات لامعة وخلاصة زهرة الكركديه للعناية بالشعر التالف.",
"apieu-raspberry-vinegar-hair-shampoo": "دلّلي شعركِ بهذا الشامبو المتوازن الحموضة بـ 1,000 جزء في المليون من الخلّ و10,000 جزء في المليون من خلاصة التوت، يخلّص الشعر وفروة الرأس من الدهون الزائدة مع الحفاظ على الترطيب. وتساعد خلاصة الرمان وخلاصات عشبية متنوّعة على حبس الرطوبة وتهدئة فروة الرأس المتهيّجة."
}
}
},
"guard": [
"1025-dokdo-toner",
"abib-airy-sunstick-smoothing-bar",
"abib-collagen-eye-patch-jericho-rose-jelly",
"abib-glutathiosome-dark-spot-pad-vita-touch",
"abib-glutathiosome-dark-spot-serum-vita-drop",
"abib-heartleaf-essence-calming-pump",
"abib-hydration-gel-water-tube",
"abib-pdrn-collagen-lip-mask-glazed-jelly",
"abib-pdrn-retinal-eye-patch-glow-jelly",
"abib-quick-sunstick-protection-bar",
"abib-rice-probiotics-overnight-mask-barrier-jelly",
"anua-7-rice-ceramide-hydrating-barrier-serum",
"anua-all-in-1-glow-essentials-5-step-skincare-set",
"anua-azelaic-10-hyaluron-redness-soothing-pad",
"anua-azelaic-acid-10-hyaluron-redness-soothing-serum",
"anua-bha-2-gentle-exfoliating-toner-150ml",
"anua-birch-70-moisture-boosting-cream-50ml",
"anua-collagen-retinol-refining-gua-sha-cream",
"anua-combination-skin-set",
"anua-daily-glow-skincare-set",
"anua-heartleaf-70-daily-lotion-200ml",
"anua-heartleaf-70-intense-calming-cream-50ml",
"anua-heartleaf-70-soothing-cream-100ml",
"anua-heartleaf-77-clear-pad",
"anua-heartleaf-77-soothing-toner-250ml",
"anua-heartleaf-80-soothing-ampoule-30ml",
"anua-heartleaf-intense-soothing-set",
"anua-heartleaf-lha-moisture-peeling-gel",
"anua-heartleaf-pore-control-cleansing-oil-200ml",
"anua-heartleaf-pore-control-cleansing-oil-mild",
"anua-heartleaf-pore-control-cleansing-oil-mini",
"anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml",
"anua-heartleaf-silky-moisture-sun-cream",
"anua-heartleaf-soothing-trial-kit",
"anua-niacinamide-10-txa-4-dark-spot-correcting-serum-30ml",
"anua-niacinamide-5-txa-brightening-pad",
"anua-pdrn-100-hyaluronic-acid-glow-pad",
"anua-pdrn-collagen-melting-patch-for-glass-skin",
"anua-pdrn-glass-skin-set",
"anua-pdrn-hyaluron-ultimate-glass-skin-set",
"anua-pdrn-hyaluronic-acid-100-moisturizing-cream",
"anua-pdrn-hyaluronic-acid-capsule-100-serum",
"anua-pdrn-hyaluronic-acid-hydrating-capsule-mist",
"anua-pdrn-hyaluronic-acid-hydrating-capsule-mist-mini",
"anua-pdrn-lip-serum",
"anua-peach-70-niacin-serum",
"anua-peach-77-niacin-enriched-cream-50ml",
"anua-peach-77-niacin-essence-toner",
"anua-retinol-0-1-caffeine-revitalizing-eye-cream",
"anua-rice-70-glow-milky-toner",
"anua-rice-70-intensive-moisturizing-milk",
"anua-rice-enzyme-brightening-cleansing-powder",
"anua-ultimate-oil-control-skincare-set",
"anua-zero-cast-moisturizing-finish-sunscreen",
"apieu-honey-milk-lip-oil",
"apieu-honey-milk-lip-oil-light",
"apieu-honey-milk-lip-scrub",
"apieu-mint-scalp-hair-vinegar",
"apieu-raspberry-hair-vinegar",
"apieu-raspberry-vinegar-hair-mist",
"apieu-raspberry-vinegar-hair-shampoo",
"aplb-glutathione-niacinamide-ampoule-serum",
"aplb-glutathione-niacinamide-body-lotion",
"aplb-glutathione-niacinamide-body-wash",
"aplb-glutathione-niacinamide-facial-cream",
"aplb-retinol-vitamin-c-vitamin-e-facial-cream",
"aprilskin-carrotene-clarifying-serum",
"aprilskin-carrotene-ipmp-clearing-solution",
"aprilskin-carrotene-ipmp-exfoliating-body-wash",
"aprilskin-carrotene-ipmp-hydramelt-cleansing-balm",
"aprilskin-carrotene-pore-clay-mask",
"aprilskin-pink-aloe-mucin-serum",
"aprilskin-pink-aloe-pack-cleanser",
"aprilskin-pink-aloe-vita-toner",
"aprilskin-real-calendula-peel-off-pack",
"aprilskin-real-carrotene-acne-foam-cleanser",
"aprilskin-txa-niacinamide-99-deep-cleanser",
"aprilskin-txa-niacinamide-99-milky-toner",
"aprilskin-txa-niacinamide-99-night-wrapping-mask",
"aprilskin-txa-niacinamide-collagen-99-capsule-gel-mask-set",
"aprilskin-txa-niacinamide-toning-shot-99-serum",
"aquaphor-healing-balm-stick-0-65-oz",
"aquaphor-healing-ointment-14oz",
"aquaphor-lip-protectant-spf-30",
"aquaphor-lip-repair-35oz",
"aquaphor-lip-repair-stick-dual-pack",
"arencia-deep-water-surge-serum",
"arencia-eraser-shot-glycolic-acid-booster",
"arencia-fresh-blue-hyssop-rice-mochi-cleanser",
"arencia-fresh-green-cleanser",
"arencia-fresh-red-smoothie-serum-30",
"arencia-fresh-red-smoothie-serum-8",
"arencia-holy-hyssop-serum-30",
"arencia-nad-booster-shot",
"arencia-pdrn-booster-shot",
"arencia-pdrn-hyaluronic-deep-hydration-pad",
"arencia-pore-melt-mochi-cleansing-oil",
"arencia-red-smoothie-lotion-5",
"arencia-retinal-booster-shot",
"arencia-retinal-boosting-eye-mask",
"arencia-txa-booster-shot",
"arencia-vitamin-c-booster-shot",
"arencia-vitamin-c-booster-shot-mask",
"arencia-vitamin-c-booster-shot-mask-set",
"arencia-vitamin-c-booster-trio",
"arencia-vitamin-c-glutathione-skin-booster-mist",
"aromatica-rosemary-dry-shampoo-powder",
"aromatica-rosemary-hair-thickening-conditioner-mini",
"aromatica-rosemary-root-enhancer-100ml",
"aromatica-rosemary-scalp-scaling-shampoo",
"aromatica-rosemary-scalp-scrub",
"aromatica-tea-tree-purifying-shampoo",
"axis-y-artichoke-intensive-skin-barrier-ampoule-30ml",
"axis-y-biome-comforting-infused-toner",
"axis-y-biome-ultimate-indulging-cream",
"axis-y-cera-heart-my-type-duo-cream",
"axis-y-complete-no-stress-physical-sunscreen",
"axis-y-daily-purifying-treatment-toner",
"axis-y-dark-spot-correcting-glow-cream",
"axis-y-dark-spot-correcting-glow-serum",
"axis-y-mugwort-pore-clarifying-wash-off-pack",
"axis-y-new-skin-resolution-gel-mask",
"axis-y-pha-resurfacing-glow-peel",
"axis-y-quinoa-one-step-balanced-gel-cleanser",
"axis-y-spot-the-difference-blemish-treatment",
"axis-y-the-mini-glow-set",
"axis-y-vegan-collagen-eye-serum",
"b_lab-cica-barrier-5-5-cleansing-oil-balm-100ml",
"b_lab-matcha-hydrating-foam-cleanser-120ml",
"b_lab-matcha-hydrating-real-sun-stick-21g",
"banila-co-clean-it-zero-cleansing-balm-original-100ml",
"banila-co-clean-it-zero-cleansing-balm-original-180ml",
"banila-co-clean-it-zero-cleansing-balm-purifying-100ml",
"banila-co-clean-it-zero-cleansing-balm-revitalizing-100ml",
"banila-co-clean-it-zero-foam-cleanser-150ml",
"beauty-of-joseon-apricot-blossom-peeling-gel",
"beauty-of-joseon-calming-serum",
"beauty-of-joseon-centella-asiatica-calming-mask-10-sheets",
"beauty-of-joseon-centella-asiatica-calming-mask-25ml",
"beauty-of-joseon-dynasty-cream",
"beauty-of-joseon-ginseng-cleansing-oil",
"beauty-of-joseon-ginseng-essence-water-150ml",
"beauty-of-joseon-glass-skin-set",
"beauty-of-joseon-glow-charging-rice-duo",
"beauty-of-joseon-glow-deep-serum-rice-alpha-arbutin-30ml",
"beauty-of-joseon-glow-replenishing-rice-milk",
"beauty-of-joseon-glow-serum-propolis-niacinamide",
"beauty-of-joseon-green-plum-refreshing-cleanser-100ml",
"beauty-of-joseon-green-plum-refreshing-toner-aha-bha",
"beauty-of-joseon-ground-rice-and-honey-glow-mask",
"beauty-of-joseon-hanbang-serum-discovery-kit",
"beauty-of-joseon-jello-skin-massage-cream",
"beauty-of-joseon-light-on-serum-centella-vita-c-30ml",
"beauty-of-joseon-matte-sun-stick-mugwort-camelia-spf-50-pa",
"beauty-of-joseon-radiance-cleansing-balm",
"beauty-of-joseon-red-bean-refreshing-pore-mask-140ml",
"beauty-of-joseon-red-bean-water-gel-100ml",
"beauty-of-joseon-relief-sun-aqua-fresh-rice-b5",
"beauty-of-joseon-relief-sun-rice-probiotics-spf50-pa-50ml",
"beauty-of-joseon-revive-eye-serum",
"beauty-of-joseon-revive-serum",
"beauty-works-10-in-1-miracle-heat-protect-spray-50ml",
"beauty-works-10-in-one-miracle-heat-protect-spray-250ml",
"beauty-works-aeris-multi-styler",
"beauty-works-waver",
"beauty-works-xxl-volume-hot-brush",
"benton-fermentation-eye-cream",
"beplain-mung-bean-cleansing-oil-jumbo",
"beplain-mung-bean-cooling-moisture-sunscreen",
"beplain-mung-bean-ph-balanced-cleansing-foam-jumbo",
"beplain-mung-bean-pore-clay-mask",
"beplain-mung-bean-pore-tight-up-serum",
"biodance-bio-collagen-real-deep-mask-1-box-4-pcs",
"biodance-collagen-gel-toner-pads",
"biodance-collagen-peptide-jelly-serum-mist",
"biodance-hydro-cera-nol-real-deep-mask-set",
"biodance-radiant-vita-niacinamide-real-deep-facial-sheet-mask",
"biodance-refreshing-sea-kelp-real-deep-mask-set",
"bioderma-atoderm-ultra-nourishing-anti-irritation-shower-oil",
"bioheal-boh-probioderm-collagen-remodeling-serum-gel-mask",
"birch-moisturizing-cleanser",
"celimax-jiwoogae-cica-bha-blemish-toner-pad",
"celimax-noni-ampoule-calming-radiance",
"celimax-oil-control-light-sunscreen",
"celimax-oil-control-mattifying-sun-stick",
"celimax-pore-dark-spot-brightening-care-sunscreen",
"celimax-pore-dark-spot-brightening-pad",
"celimax-pore-dark-spot-brightening-serum",
"celimax-poredark-spot-brightening-cream",
"celimax-poredark-spot-complete-brightening-skincare-set",
"celimax-the-real-noni-acne-bubble-cleanser",
"celimax-the-vita-a-retinal-shot-tightening-booster",
"celimax-the-vita-a-retinal-shot-tightening-dual-cream",
"celimax-the-vita-a-retinol-shot-tightening-serum",
"celimax-txa-body-clarifying-pad",
"centellian24-360o-shot-pdrn-glowing-eye-patch",
"centellian24-360o-shot-pdrn-lifting-eye-cream",
"centellian24-madeca-cream-time-reverse",
"cerave-foaming-cleanser",
"cerave-moisturising-cream-tube-with-ceramides-for-dry-to-very-dry-skin",
"color-bloom-liquid-blush-matte-finish-love-cake",
"color-bloom-liquid-blush-matte-finish-risky-business",
"corsx-bha-blackhead-power-liquid-100ml",
"cosrx-ac-collection-blemish-spot-clearing-serum",
"cosrx-ac-collection-lightweight-soothing-moisturizer",
"cosrx-ac-collection-ultimate-spot-cream",
"cosrx-acne-pimple-patch",
"cosrx-advanced-snail-92-all-in-one-cream-100ml",
"cosrx-advanced-snail-96-mucin-power-essence-100ml",
"cosrx-advanced-snail-hydrogel-eye-patch",
"cosrx-advanced-snail-peptide-eye-cream-25ml",
"cosrx-advanced-snail-radiance-dual-essence-80ml",
"cosrx-aha-7-whitehead-power-liquid-100ml",
"cosrx-aha-bha-clarifying-treatment-toner-150ml",
"cosrx-all-about-snail-kit",
"cosrx-aloe-soothing-spf-50-sun-cream-50ml",
"cosrx-balancium-ceramide-lip-butter-sleeping-mask-20g",
"cosrx-centella-blemish-cream",
"cosrx-centella-water-alcohol-free-toner-150ml",
"cosrx-favorites-skincare-set",
"cosrx-full-fit-honey-sugar-lip-scrub",
"cosrx-full-fit-propolis-honey-overnight-mask",
"cosrx-full-fit-propolis-light-ampoule",
"cosrx-full-fit-propolis-light-cream-65ml",
"cosrx-full-fit-propolis-synergy-toner-150ml",
"cosrx-galactomyces-95-tone-balancing-essence",
"cosrx-honey-glow-trial-kit",
"cosrx-hyaluronic-acid-hydra-power-essence",
"cosrx-hyaluronic-hydra-intensive-cream-100ml",
"cosrx-hydrium-triple-hyaluronic-moisturizing-cleanser-150ml",
"cosrx-low-ph-good-morning-gel-cleanser-for-sensitive-skin-150ml",
"cosrx-oil-free-ultra-moisturizing-lotion-with-birch-sap-100ml",
"cosrx-one-step-original-clear-pad",
"cosrx-refresh-aha-bha-vitamin-c-daily-toner-150ml",
"cosrx-salicylic-acid-daily-gentle-cleanser-150ml",
"cosrx-the-6-peptide-skin-booster",
"cosrx-the-niacinamide-15-serum-20ml",
"cosrx-the-peptide-collagen-hydrogel-eye-patch",
"cosrx-the-retinol-0-1-cream",
"cosrx-the-retinol-0-5-oil",
"cosrx-the-vitamin-c-23-serum-20ml",
"cosrx-two-in-one-poreless-power-liquid-toner-100ml",
"cosrx-ultimate-nourishing-rice-overnight-spa-mask",
"cosrx-vitamin-e-vitalizing-sunscreen",
"d-alba-double-serum-all-in-one-multi-balm",
"d-alba-professional-repairing-hair-perfume-serum",
"d-alba-white-truffle-vital-spray-serum",
"dalba-signature-allthera-double-shot",
"dalba-white-truffle-first-spray-serum-mini",
"dear-klairs-all-day-airy-sunscreen",
"dear-klairs-egf-blue-youth-activating-drop",
"dear-klairs-freshly-juiced-vitamin-drop",
"dear-klairs-fundamental-eye-awakening-gel",
"dear-klairs-midnight-blue-calming-cream",
"dear-klairs-rich-moist-soothing-cream",
"dear-klairs-rich-moist-soothing-serum",
"dear-klairs-supple-preparation-unscented-facial-toner-180-ml",
"dr-althea-0-1-gentle-retinol-serum",
"dr-althea-345-relief-cream",
"dr-althea-345-relief-cream-mask-set",
"dr-althea-345-relief-cream-mist",
"dr-althea-345-relief-cream-mist-mini",
"dr-althea-345-relief-cream-mist-spray-set",
"dr-althea-amino-acid-gentle-bubble-cleanser-140ml",
"dr-althea-aqua-marine-watery-cream",
"dr-althea-azulene-147ha-intensive-soothing-cream",
"dr-althea-cica-skin-calming-pad-65-pads",
"dr-althea-gentle-vitamin-c-serum",
"dr-althea-lash-boost-serum",
"dr-althea-melaclear-cream",
"dr-althea-pdrn-reju-5000-cream",
"dr-althea-pure-grinding-cleansing-balm",
"dr-althea-rapid-hypochlorous-acid-rescue-mist-60ml",
"dr-althea-restoration-glow-trio",
"dr-althea-retinol-flat-iron-eye-roller",
"dr-althea-skin-relief-essence-250ml",
"dr-althea-soft-melting-lip-balm",
"dr-althea-vitamin-c-boosting-serum",
"dr-ceuracle-cica-regen-vegan-sun",
"dr-ceuracle-hyal-reyouth-moist-sun",
"dr-ceuracle-pro-balance-night-enzyme-wash",
"dr-ceuracle-pro-balance-pure-cleansing-oil",
"dr-ceuracle-vegan-kombucha-tea-essence",
"dr-ceuracle-vegan-kombucha-tea-gel-cream",
"dr-ceuracle-vegan-kombucha-tea-special-set",
"dr-g-r-e-d-blemish-clear-soothing-cream-70ml",
"dr-melaxin-calcium-dark-spot-cover-eye-cream",
"dr-melaxin-calcium-volume-multi-balm",
"dr-melaxin-cemenrete-calcium-volume-eye-patch",
"dr-melaxin-cemenrete-cyano-pink-spicule-serum",
"dr-melaxin-eyephalt-eyebag-cream",
"dr-melaxin-melting-cleanser",
"dr-melaxin-peel-shot-exfoliating-black-rice-ampoule",
"dr-melaxin-peel-shot-exfoliating-kojic-acid-turmeric-spray",
"dr-melaxin-peel-shot-exfoliating-white-rice-ampoule",
"dr-melaxin-peel-shot-glow-white-rice-toner",
"dr-melaxin-tx-cream",
"dr-reju-all-advanced-lc-ceramide-barrier-cream",
"dr-reju-all-advanced-pdrn-rejuvenating-cream-20g",
"dr-reju-all-advanced-pdrn-rejuvenating-cream-20ml",
"dr-reju-all-advanced-pdrn-rejuvenating-lip-serum",
"elizavecca-milky-piggy-cer-100-collagen-ceramide-coating-protein-treatment",
"epii-double-up-cream-ectoin-panthenol",
"epii-double-up-cream-nad-nmn-txa",
"epii-hpr-pro-shot",
"eqqualberry-aloe-pdrn-calming-serum",
"eqqualberry-aloe-pdrn-calming-serum-mini",
"eqqualberry-bakuchiol-plumping-capsule-cream",
"eqqualberry-bakuchiol-plumping-serum",
"eqqualberry-glow-duo-serum-set",
"eqqualberry-hyaltoin-flooding-serum",
"eqqualberry-nad-peptide-boosting-serum",
"eqqualberry-purple-pdrn-pore-minimizing-serum",
"eqqualberry-swimming-pool-toner-155ml",
"eqqualberry-swimming-pool-toner-pads",
"eqqualberry-vitamin-illuminating-cream",
"eqqualberry-vitamin-illuminating-serum",
"esse-beauty-gentle-exfoliating-face-scrub",
"essence-what-a-tint-lip-cheek-tint",
"etude-dear-darling-water-tint",
"etude-soon-jung-10-free-moist-emulsion",
"etude-soon-jung-10-panthensoside-cica-balm",
"etude-soon-jung-2x-barrier-intensive-cream-60ml",
"etude-soon-jung-ph-5-5-relief-toner-200ml",
"eucerin-hydro-protect-ultra-light-fluid-spf-50",
"eucerin-sun-even-pigment-control-spf50",
"foodology-coleology-bowel-movement-jelly",
"foodology-coleology-cutting-jelly",
"foodology-coleology-cutting-powder",
"foodology-collagen-centella-jelly",
"fully-green-tomato-clay-mask-cleanser",
"fully-green-tomato-cream",
"fwee-lipcheek-blurry-pudding-pot-cherry",
"fwee-lipcheek-glowy-jelly-pot-compote",
"goodal-green-tangerine-vita-c-cleansing-foam",
"goodal-green-tangerine-vita-c-dark-spot-care-cream",
"goodal-green-tangerine-vita-c-dark-spot-care-serum",
"goodal-green-tangerine-vitamin-c-moisturizing-eye-patch",
"goodal-heartleaf-calming-moisture-sun-cream",
"haruharu-wonder-black-bamboo-daily-smoothing-body-oil",
"haruharu-wonder-black-bamboo-daily-soothing-sun-shield",
"haruharu-wonder-black-bamboo-nourishing-calming-hand-nail-cream",
"haruharu-wonder-black-rice-10-hyaluronic-cream",
"haruharu-wonder-black-rice-10-hyaluronic-cream-unscented",
"haruharu-wonder-black-rice-5-ceramide-barrier-moisturizing-cream",
"haruharu-wonder-black-rice-bakuchiol-eye-cream",
"haruharu-wonder-black-rice-facial-oil-30ml",
"haruharu-wonder-black-rice-hyaluronic-toner",
"haruharu-wonder-black-rice-moisture-5-5-soft-cleansing-gel",
"haruharu-wonder-black-rice-moisture-airyfit-sunscreen",
"haruharu-wonder-black-rice-moisture-deep-cleansing-oil",
"haruharu-wonder-black-rice-night-knight-retinol-serum",
"haruharu-wonder-black-rice-probiotics-barrier-essence",
"haruharu-wonder-black-rice-probiotics-barrier-essence-2",
"haruharu-wonder-centella-4-txa-dark-spot-go-away-serum",
"healing-ointment-7oz",
"heimish-all-clean-balm-120ml",
"heimish-all-clean-green-foam-cleanser-150-g",
"heimish-artless-glow-base-spf-50-pa-40ml-1-35fl-oz",
"heimish-artless-perfect-cushion-set-2-colors",
"heimish-dailism-smudge-stop-mascara-9-g-curling",
"heimish-marine-care-eye-cream-30ml",
"heimish-matcha-biome-amino-acne-cleansing-foam-150ml",
"heimish-rx-hyaluronic-acid-2-hydrating-serum-35ml",
"heimish-rx-multi-vitamin-glow-serum",
"house-of-hur-moist-ampoule-blusher-6-colors",
"house-of-hur-purifying-cleansing-balm",
"illiyoon-ceramide-ato-concentrate-cream",
"ilso-deep-clean-master",
"ilso-super-melting-sebum-softener",
"im-from-ginseng-serum",
"im-from-mugwort-cream",
"im-from-mugwort-essence",
"im-from-rice-cream-50g",
"im-from-rice-serum",
"im-from-rice-toner-150-ml",
"im-from-rice-toner-mini",
"im-from-rice-toner-pad",
"innisfree-cherry-blossom-glow-jelly-cream",
"innisfree-green-tea-amino-acid-cleansing-water",
"innisfree-green-tea-amino-hydrating-cleansing-foam-150g",
"innisfree-no-sebum-mineral-powder",
"innisfree-super-volcanic-pore-clay-mask",
"innisfree-volcanic-bha-pore-cleansing-foam-150-g",
"ishizawa-lab-keana-rice-mask",
"ishizawa-lab-nadeshiko-keana-rice-pack",
"isntree-c-niacin-toning-cream-50ml",
"isntree-green-tea-fresh-toner-200ml",
"isntree-hyaluronic-acid-airy-sun-stick-22g",
"isntree-hyaluronic-acid-aqua-gel-cream",
"isntree-hyaluronic-acid-moist-cream",
"isntree-hyaluronic-acid-toner-plus",
"isntree-hyaluronic-acid-watery-sun-gel",
"isntree-mugwort-calming-clay-mask",
"isntree-onion-newpair-sunscreen",
"isntree-ultra-low-molecular-hyaluronic-acid-serum-50ml",
"isntree-ultra-low-molecular-hyaluronic-acid-toner",
"iunik-beta-glucan-daily-moisture-cream-60ml",
"iunik-calendula-complete-cleansing-oil-200ml",
"iunik-centella-bubble-cleansing-foam-150ml",
"iunik-centella-calming-gel-cream-60ml",
"iunik-tea-tree-relief-serum-50ml",
"iunik-tea-tree-relief-toner-200ml",
"jumiso-all-day-vitamin-brightening-balancing-facial-serum",
"jumiso-all-day-vitamin-clean-mild-facial-cleanser",
"jumiso-all-day-vitamin-nourishing-recharging-wash-off-mask",
"jumiso-all-day-vitamin-pure-c-5-5-glow-serum-30ml",
"jumiso-all-day-vitamin-vc-ip-1-0-firming-serum-30ml",
"jumiso-awe-sun-airy-fit-sunscreen",
"jumiso-snail-mucin-95-peptide-facial-essence",
"jumiso-waterfull-hyaluronic-cream-50ml",
"jumiso-waterfull-hyaluronic-toner",
"k-beauty-dark-spots-care-bundle",
"kaine-green-fit-pro-sun-spf-50-sunscreen-55ml",
"kaine-kombu-balancing-ampoule-toner-150ml",
"kaine-rosemary-relief-gel-cleanser-150ml",
"kaine-vita-drop-serum-30ml",
"kao-biore-uv-aqua-rich-watery-essence-sunscreen-spf-50-pa",
"kbeautybliss-anti-aging-glow-skincare-routine",
"kose-softymo-cleansing-oil",
"la-roche-posay-anthelios-invisible-fluid-spf-50",
"la-roche-posay-hyalu-b5-mini-serum",
"laneige-cream-skin-cerapeptide-refiner-mini",
"laneige-lip-glowy-balm",
"laneige-lip-glowy-balm-gummy-bear",
"laneige-lip-sleeping-mask-20g",
"laneige-lip-sleeping-mask-ex-mini",
"laneige-lip-sleeping-mask-grapefruit-20g",
"laneige-water-bank-blue-hyaluronic-cleansing-foam",
"lepique-retinol-night-cream",
"lilyeve-grow-turn-exosome-3d-volume-brush-shampoo",
"lilyeve-grow-turn-exosome-brush-ampoule",
"lilyeve-grow-turn-exosome-dual-lash-serum-2pcs-bundle-set",
"lilyeve-scalp-turn-cica-pdrn-brush-ampoule",
"lirene-ir-complex-spf-50-high-protection",
"manyo-bifida-biome-ampoule-pad-70-pads",
"manyo-factory-bifida-biome-aqua-barrier-cream-80ml",
"manyo-factory-deep-pore-cleansing-soda-foam",
"manyo-factory-galac-niacin-2-0-essence",
"manyo-pure-cleansing-oil",
"marymay-6-peptide-complex-serum-30ml",
"marymay-cica-houttuynia-tea-tree-calming-mask",
"marymay-cica-soothing-sun-cream",
"marymay-houttuynia-cordata-tea-tree-serum",
"marymay-idebenone-blackberry-intense-cream",
"marymay-niacinamide-chaenomeles-sinensis-serum",
"marymay-rose-hyaluronic-hydra-wash-off-mask-pack-125g",
"marymay-tranexamic-acid-glutathione-eye-cream",
"marymay-tranexamic-acid-glutathione-eye-cream-mini",
"marymay-vitamin-b-c-e-cleansing-balm",
"medicube-age-r-booster-deep-vita-c-set",
"medicube-age-r-booster-facial-cleanser-head-pink",
"medicube-age-r-booster-pro",
"medicube-age-r-booster-pro-mini-pink",
"medicube-age-r-booster-pro-mini-plus-pink",
"medicube-age-r-booster-pro-mini-white",
"medicube-age-r-booster-pro-pink-edition",
"medicube-age-r-booster-pro-x2-pink",
"medicube-age-r-booster-pro-x2-pink-set",
"medicube-age-r-booster-pro-yellow-edition",
"medicube-age-r-glutathione-glow-capsule-facial-cream",
"medicube-age-r-glutathione-glow-toner",
"medicube-age-r-pdrn-booster-gel",
"medicube-age-r-v-roller-head-pink",
"medicube-age-r-vita-c-pro-ampoule",
"medicube-cleansing-device-set-pink-version",
"medicube-collagen-booster-set",
"medicube-collagen-booster-set-pink-edition",
"medicube-collagen-glow-booster-serum",
"medicube-collagen-glow-sunscreen",
"medicube-collagen-jelly-cream",
"medicube-collagen-jelly-cream-mini",
"medicube-collagen-lifting-mask",
"medicube-collagen-night-wrapping-mask-75ml",
"medicube-deep-lifting-peptide-eye-cream-for-face",
"medicube-deep-vita-c-capsule-cream",
"medicube-deep-vita-c-daily-quick-mask",
"medicube-deep-vita-c-glutathione-brightening-mask",
"medicube-deep-vita-c-pad-70-pads",
"medicube-egf-nad-firming-gel-mask",
"medicube-egf-nad-firming-serum",
"medicube-face-roller-set-pink-version",
"medicube-fresh-glow-travel-body-kit",
"medicube-full-facial-device-set-pink-version",
"medicube-glutathione-glow-serum",
"medicube-hyaluronic-moisturizing-capsule-cream",
"medicube-hyaluronic-multi-peptide-serum",
"medicube-hypochlorous-acid-daily-facial-spray",
"medicube-jelly-gel-mask-with-firming-nad-egf",
"medicube-kojic-acid-glow-full-routine-set",
"medicube-kojic-acid-turmeric-body-peel-shot",
"medicube-kojic-acid-turmeric-booster-pro-set",
"medicube-kojic-acid-turmeric-brightening-gel-mask",
"medicube-kojic-acid-turmeric-niacinamide-serum",
"medicube-kojic-acid-turmeric-night-wrapping-mask",
"medicube-kojic-acid-turmeric-pad",
"medicube-kojic-acid-turmeric-resurfacing-complete-set",
"medicube-kojic-acid-turmeric-resurfacing-toner",
"medicube-kojic-acid-turmeric-toning-cleanser",
"medicube-kojic-acid-turmeric-vita-brightening-body-wash",
"medicube-kojic-acid-turmeric-vita-capsule-cream",
"medicube-kojic-acid-turmeric-vita-eye-gel-serum",
"medicube-kojic-acid-turmeric-vita-jelly-mist-serum",
"medicube-one-day-exosome-shot-pore-ampoule-2000",
"medicube-one-day-exosome-shot-pore-ampoule-7500",
"medicube-pdrn-collagen-gua-sha-neck-wrinkle-cream",
"medicube-pdrn-glow-booster-set",
"medicube-pdrn-glow-booster-set-black-edition",
"medicube-pdrn-glow-booster-set-mini",
"medicube-pdrn-hydrating-gel-cleanser",
"medicube-pdrn-hydrating-uv-serum",
"medicube-pdrn-lip-sleeping-mask",
"medicube-pdrn-pink-caffeine-collagen-eye-patch",
"medicube-pdrn-pink-caffeine-night-wrapping-mask",
"medicube-pdrn-pink-cica-soothing-toner",
"medicube-pdrn-pink-collagen-bubble-serum",
"medicube-pdrn-pink-collagen-capsule-cream",
"medicube-pdrn-pink-collagen-exosome-shot-serum-2000",
"medicube-pdrn-pink-collagen-exosome-shot-serum-7500",
"medicube-pdrn-pink-collagen-gel-mask",
"medicube-pdrn-pink-collagen-glow-jelly-mist-serum",
"medicube-pdrn-pink-collagen-jelly-eye-mask-6-pairs",
"medicube-pdrn-pink-collagen-toning-gel-toner-pad",
"medicube-pdrn-pink-collagen-volume-multi-balm",
"medicube-pdrn-pink-glutathione-serum-mist",
"medicube-pdrn-pink-hyaluronic-moisturizing-cream",
"medicube-pdrn-pink-niacinamide-milky-toner",
"medicube-pdrn-pink-niacinamide-whip-cleanser",
"medicube-pdrn-pink-one-day-serum-set",
"medicube-pdrn-pink-peptide-eye-cream",
"medicube-pdrn-pink-peptide-serum",
"medicube-pdrn-pink-tension-up-mask-set",
"medicube-pdrn-pink-tone-up-sun-cream",
"medicube-pore-brush",
"medicube-red-acne-body-peeling-shot",
"medicube-red-acne-body-wash",
"medicube-red-acne-succinic-acid-peel",
"medicube-red-succinic-acid-peeling-pad",
"medicube-rosemary-pdrn-cooling-thickening-shampoo",
"medicube-rosemary-pdrn-hair-recovery-set",
"medicube-rosemary-pdrn-hair-scalp-conditioner",
"medicube-rosemary-pdrn-scalp-serum",
"medicube-soymint-scaling-shampoo",
"medicube-triple-collagen-cream-4-0",
"medicube-triple-collagen-serum-55ml",
"medicube-triple-collagen-toner",
"medicube-txa-niacinamide-15-serum",
"medicube-txa-niacinamide-capsule-cream",
"medicube-ultra-light-pdrn-pink-peptide-serum",
"medicube-vanilla-deodrant-and-body-mist-duo",
"medicube-vanilla-hair-body-fragrance-mist",
"medicube-vanilla-pistachio-deodorant",
"medicube-viral-pdrn-lift-and-firm-set",
"medicube-zero-foam-cleanser",
"medicube-zero-pore-blackhead-cleansing-oil",
"medicube-zero-pore-blackhead-mud-mask",
"medicube-zero-pore-pad-2-0",
"medicube-zero-pore-pad-mild",
"mediheal-teatree-trouble-pad-100-pcs",
"missha-all-around-safe-block-essence-sun-spf45-pa-50-ml",
"missha-glow-skin-balm-50ml",
"missha-m-perfect-cover-bb-cream-spf-42-pa50ml",
"mixsoon-bean-cream",
"mixsoon-bean-essence-50ml",
"mixsoon-bean-essence-mini",
"mixsoon-centella-asiatica-toner-300ml",
"mixsoon-centella-cleansing-foam",
"mixsoon-glacier-water-hyaluronic-acid-serum-300ml",
"mixsoon-soybean-milk-pad-set",
"moev-annurcatin-shampoo",
"moev-annurcatin-shampoo-jumbo",
"moev-annurcatin-treatment",
"moev-annurcatin-water-treatment",
"nida-revive-eye-cream-for-face-and-neck",
"nida-salmon-pdrn-peptide-serum",
"nida-youthful-formula-ultimate-moisturizing-cream",
"nineless-b-boost-1-kojic-acid-serum",
"nuface-mini-starter-kit",
"numbuzin-no-1-clear-filter-sun-essence-50ml",
"numbuzin-no-1-glossy-essence-serum-50ml",
"numbuzin-no-1-pantothenic-b5-active-soothing-cream",
"numbuzin-no-3-porcelain-base-skip-tone-up-beige",
"numbuzin-no-3-radiance-glowing-jumbo-essence-pad",
"numbuzin-no-3-skin-softening-serum",
"numbuzin-no-3-super-glowing-essence-toner",
"numbuzin-no-4-pore-zero-peeled-egg-toner-pad-190ml",
"numbuzin-no-5-goodbye-blemish-serum",
"numbuzin-no-5-vitamin-concentrated-serum",
"numbuzin-no-5-vitamin-niacinamide-concentrated-toner-pad",
"numbuzin-no-6-deep-sleep-mask-serum",
"numbuzin-no-9-nad-bio-lifting-sil-essence",
"numbuzin-no-9-nad-bio-lifting-sil-full-face-mask-set",
"numbuzin-no-9-nad-bio-lifting-sil-timeless-set",
"numbuzin-no-9-nad-collagen-under-eye-patches",
"numbuzin-no-9-nad-pdrn-glow-boosting-toner",
"numbuzin-no-9-nad-retinol-volumetox-eye-cream",
"numbuzin-no-9-secret-firming-serum-50ml",
"ointment-body-spray-6-2oz",
"olaplex-no-10-bond-shaper-curl-defining-gel-20ml-mini",
"ongredients-skin-barrier-calming-lotion",
"orien-mugwort-zero-film-cleansing-oil",
"plix-pineapple-de-pigmentation-moisturizer-with-2-alpha-arbutin",
"purito-seoul-centella-green-level-eye-cream-30ml",
"purito-seoul-dermide-balancing-barrier-balm-60-ml",
"purito-seoul-from-green-deep-foaming-cleanser",
"purito-seoul-galacto-niacin-97-power-essence-60ml",
"purito-seoul-green-level-buffect-serum-60ml",
"purito-seoul-hydro-wave-deep-sea-cream",
"purito-seoul-mighty-bamboo-panthenol-cream",
"purito-seoul-oat-in-intense-cream",
"purito-seoul-wonder-releaf-centella-serum-unscented",
"pyunkang-yul-acne-spot-cream-15ml",
"pyunkang-yul-ato-cream-blue-label-tube",
"pyunkang-yul-calming-deep-moisture-toner-150ml",
"pyunkang-yul-calming-moisture-barrier-cream",
"pyunkang-yul-deep-clear-cleansing-balm-100ml",
"pyunkang-yul-essence-toner-200ml",
"pyunkang-yul-kids-baby-sun-cream-75ml",
"pyunkang-yul-low-ph-pore-deep-cleansing-foam",
"pyunkang-yul-moisture-serum-100ml",
"rohto-mentholatum-melano-cc-vitamin-c-essence",
"rohto-mentholatum-melano-cc-vitamin-c-premium-essence",
"round-lab-1025-dokdo-cleanser",
"round-lab-1025-dokdo-cleansing-oil",
"round-lab-birch-juice-moisturizing-sunscreen-50ml",
"scalp-oil-applicator-comb",
"seapuri-scalpy-hair-serum",
"sebum-sweeper-pore-cleansing-silicone-brush",
"shark-cryoglow-under-eye-cooling-led-anti-aging-red-light-skin-clearing-mask",
"shiseido-fino-premium-touch-conditioner",
"shiseido-fino-premium-touch-hair-mask",
"shiseido-fino-premium-touch-hair-oil",
"shiseido-fino-premium-touch-shampoo",
"shiseido-ma-cherie-hair-oil-ex",
"shiseido-senka-perfect-whip-beauty-face-foam",
"shiseido-tsubaki-premium-ex-repair-hair-mask",
"shiseido-tsubaki-premium-moist-repair-conditioner",
"shiseido-tsubaki-premium-moist-repair-shampoo",
"shiseido-tsubaki-premium-repair-mask-pink-camellia",
"shiseido-tsubaki-premium-volume-repair-shampoo",
"skin-1004-cleansing-duo",
"skin-1004-madagascar-centella-ampoule-foam",
"skin-1004-madagascar-centella-ampoule-mini-30ml",
"skin-1004-madagascar-centella-asiatica-100-ampoule-100ml",
"skin-1004-madagascar-centella-cream-75ml",
"skin-1004-madagascar-centella-hyalu-cica-water-fit-sun-serum-50ml",
"skin-1004-madagascar-centella-light-cleansing-oil-200ml",
"skin-1004-madagascar-centella-light-cleansing-oil-mini",
"skin-1004-madagascar-centella-pore-minimizing-light-gel-cream",
"skin-1004-madagascar-centella-poremizing-clear-toner-210ml",
"skin-1004-madagascar-centella-poremizing-quick-clay-stick-mask",
"skin-1004-madagascar-centella-probio-cica-bakuchiol-eye-cream",
"skin-1004-madagascar-centella-probio-cica-essence-toner",
"skin-1004-madagascar-centella-probio-cica-intensive-ampoule",
"skin-1004-madagascar-centella-quick-calming-toner-pad-70-pads",
"skin-1004-madagascar-centella-soothing-cream-75ml",
"skin-1004-madagascar-centella-tea-trica-bha-foam",
"skin-1004-madagascar-centella-tone-brightening-boosting-toner",
"skin-1004-madagascar-centella-tone-brightening-capsule-ampoule",
"skin-1004-madagascar-centella-tone-brightening-capsule-ampoule-jumbo",
"skin-1004-madagascar-centella-tone-brightening-capsule-cream",
"skin-1004-madagascar-centella-toning-toner-200ml",
"skin-1004-skin-relief-duo",
"skin-1004-zombie-pack-set",
"skin1004-hyalu-cica-first-ampoule-100ml",
"skin1004-madagascar-centella-hyalu-cica-silky-fit-sun-stick-20g",
"skin1004-madagascar-centella-hyalu-cica-water-fit-sun-serum-50ml-x-2-twin-pack",
"skin1004-madagascar-centella-poremizing-deep-cleansing-foam-125ml",
"skin1004-madagascar-centella-poremizing-fresh-ampoule-50ml",
"skin1004-madagascar-centella-probio-cica-enrich-cream-50ml",
"skin1004-madagascar-centella-tea-trica-relaxing-mask",
"skin1004-madagascar-centella-tone-brightening-tone-up-sunscreen",
"skin1004-madagascar-centella-travel-kit",
"skin1004-tea-trica-relief-ampoule-100ml",
"skinfood-black-sugar-mask-wash-off",
"skinfood-carrot-carotene-calming-water-pad",
"skinfood-carrot-carotene-daily-mask",
"skinfood-shine-muscat-bha-2-toner-mini",
"skinlab-barrierderm-intensive-cream-50ml",
"skinlab-vitamin-c-brightening-serum",
"some-by-mi-30-days-miracle-clear-spot-patch",
"some-by-mi-aha-bha-pha-30-days-miracle-ac-sos-kit",
"some-by-mi-aha-bha-pha-30-days-miracle-acne-clear-body-cleanser",
"some-by-mi-aha-bha-pha-30-days-miracle-cream",
"some-by-mi-aha-bha-pha-30-days-miracle-starter-kit-green-4-count",
"some-by-mi-aha-bha-pha-30-days-miracle-toner-150ml",
"some-by-mi-aha-bha-pha-30-days-miracle-truecica-clear-pad-70-pieces",
"some-by-mi-aha-bha-pha-miracle-calming-body-lotion",
"some-by-mi-aha-pha-bha-30-days-miracle-serum-50ml",
"some-by-mi-bye-bye-blackhead-30-days-miracle-green-tea-tox-bubble-cleanser",
"some-by-mi-cica-peptide-anti-hair-loss-derma-scalp-shmpoo-285ml",
"some-by-mi-cica-peptide-anti-hair-loss-derma-scalp-tonic-150ml",
"some-by-mi-cica-peptide-anti-hair-loss-derma-scalp-treatment",
"some-by-mi-galactomyces-pure-vitamin-c-glow-serum-30-ml",
"some-by-mi-retinol-intense-advanced-triple-action-eye-cream",
"some-by-mi-retinol-intense-reactivating-serum",
"some-by-mi-retinol-intense-trial-kit",
"some-by-mi-super-matcha-pore-clean-clay-mask-100g",
"some-by-mi-yuja-niacin-30-days-miracle-brightening-toner",
"some-by-mi-yuja-niacin-anti-blemish-starter-kit",
"some-by-mi-yuja-niacin-brightening-peeling-gel",
"stylpro-fabulous-firmer-neck-face-smoother",
"stylpro-facial-steamer",
"stylpro-smooth-finish",
"stylpro-wavelength-led-face-mask",
"tiam-b3-niacin-sunscreen",
"tirtir-ceramic-cream",
"tirtir-mask-fit-red-cushion-foundation-9-shades",
"tirtir-milk-skin-toner-150ml",
"tocobo-bifida-biome-essence-50ml",
"tocobo-bio-watery-sun-cream-spf50-pa",
"tocobo-cica-calming-sun-serum",
"tocobo-coconut-clay-cleansing-foam-150ml",
"tocobo-collagen-brightening-eye-gel-cream-30ml",
"tocobo-cotton-soft-sun-stick",
"tocobo-lemon-sugar-scrub-lip-mask",
"tocobo-lip-mask-2-types",
"tocobo-multi-ceramide-cream-50ml",
"tocobo-vita-berry-pore-toner-150ml",
"tocobo-vita-glazed-lip-mask-20ml",
"tocobo-vita-tone-up-sun-cream-spf50-pa",
"torriden-cellmazing-pore-perfecting-ampoule",
"torriden-dive-in-low-molecular-hyaluronic-acid-soothing-cream-100ml",
"torriden-dive-in-low-molecule-hyaluronic-acid-serum-2",
"torriden-dive-in-low-molecule-hyaluronic-acid-skin-booster-200ml",
"torriden-dive-in-low-molecule-hyaluronic-acid-toner",
"torriden-solid-in-ceramide-lip-essence",
"tosowoong-arbutin-7-glutathione-cream",
"tosowoong-arbutin-7-tranexamic-acid-4-cream",
"tosowoong-copper-peptide-12-cream",
"tosowoong-pink-peptide-12-pdrn-serum",
"tosowoong-real-collagen-fit",
"tosowoong-real-kojic-acid-turmeric-fit",
"tosowoong-real-retinol-0-1-night-cream",
"umberto-giannini-curl-jelly-scrunching-jelly-mini",
"vicrea-honey-deep-moist-shampoo-1-0",
"vicrea-honey-deep-moist-treatment-2-0",
"vicrea-honey-hair-oil-3-0",
"vt-cica-daily-soothing-mask",
"vt-cica-reti-a-all-in-one-3-step-mask",
"vt-collagen-reedle-shot-100",
"vt-hydrop-reedle-shot-100hl-2-step-mask",
"vt-mild-reedle-shot-50",
"vt-pdrn-capsule-cream-100",
"vt-pdrn-daily-mask",
"vt-pdrn-essence-100",
"vt-pdrn-essence-stick-balm",
"vt-pdrn-glow-ampoule",
"vt-reedle-shot-100",
"vt-reedle-shot-100-2-step-mask",
"vt-reedle-shot-100-mini",
"vt-reedle-shot-300",
"vt-reedle-shot-300-2-step-mask",
"vt-reedle-shot-700",
"vt-reedle-shot-700-2-step-mask",
"vt-reedle-shot-lifting-serum",
"vt-reedle-shot-lip-plumper-beginner",
"vt-reti-a-reedle-shot-100-2step-hydrogel-mask",
"vt-vita-light-all-in-one-3step-mask",
"vt-vita-light-reedle-shot-100-2step-hydrogel-mask",
"wella-wellaflex-invisible-hold-hairspray-extra-strong-hold-75-ml-4-5-hold"
]
}
KBBARC;

    /*
     * Which table each group's rows live in and the column a row is found by.
     * Literal: a migration names no class of this shop (the 2.60.512 rule).
     */
    private const TABLES = [
        'products' => ['products', 'slug'],
        'brands' => ['brands', 'slug'],
        'categories' => ['categories', 'slug'],
        'pages' => ['pages', 'slug'],
        'posts' => ['posts', 'slug'],
        'menu_items' => ['menu_items', 'label'],
    ];

    public function up(): void
    {
        $data = json_decode(self::DATA, true);

        if (! is_array($data) || ! $this->isLiveCatalogue($data)) {
            return;
        }

        $now = now();
        $written = 0;
        $names = [];

        foreach ($data['groups'] ?? [] as $group => $fields) {
            [$table, $column] = self::TABLES[$group] ?? [null, null];

            if ($table === null || ! Schema::hasTable($table) || ! is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $byKey) {
                if (! is_array($byKey) || $byKey === [] || ! Schema::hasColumn($table, $field)) {
                    continue;
                }

                foreach (array_chunk(array_keys($byKey), 300) as $keys) {
                    $rows = DB::table($table)->whereIn($column, $keys)->get(['id', $column, $field]);

                    if ($rows->isEmpty()) {
                        continue;
                    }

                    // NEVER OVER A ROW THAT EXISTS: published, draft, the owner's
                    // or a machine's. Only a field with no Arabic row at all gets one.
                    $taken = array_flip(DB::table('translations')
                        ->where('locale', 'ar')
                        ->where('group', $group)
                        ->where('field', $field)
                        ->whereIn('item_id', $rows->pluck('id')->all())
                        ->pluck('item_id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all());

                    $insert = [];

                    foreach ($rows as $row) {
                        $id = (int) $row->id;
                        $value = trim((string) ($byKey[(string) $row->{$column}] ?? ''));

                        if ($value === '' || isset($taken[$id])) {
                            continue;
                        }

                        $taken[$id] = true;
                        $insert[] = [
                            'locale' => 'ar',
                            'group' => $group,
                            'item_id' => $id,
                            'field' => $field,
                            'value' => $value,
                            'status' => 'published',
                            'source' => 'manual',
                            'source_hash' => sha1((string) $row->{$field}),
                            'reviewed_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if ($group === 'products' && $field === 'name') {
                            $names[$id] = $value;
                        }
                    }

                    foreach (array_chunk($insert, 200) as $chunk) {
                        DB::table('translations')->insert($chunk);
                        $written += count($chunk);
                    }
                }
            }
        }

        $this->indexSearch($names);
        $this->forgetCaches();

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Arabic content: {$written} translations written.\n";
        }
    }

    public function down(): void
    {
        $data = json_decode(self::DATA, true);

        if (! is_array($data) || ! Schema::hasTable('translations')) {
            return;
        }

        // Only rows still holding exactly what this migration wrote: anything
        // the owner has since edited is his, and stays.
        foreach ($data['groups'] ?? [] as $group => $fields) {
            [$table, $column] = self::TABLES[$group] ?? [null, null];

            if ($table === null || ! Schema::hasTable($table) || ! is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $byKey) {
                foreach (array_chunk(array_keys((array) $byKey), 300) as $keys) {
                    foreach (DB::table($table)->whereIn($column, $keys)->get(['id', $column]) as $row) {
                        DB::table('translations')
                            ->where('locale', 'ar')
                            ->where('group', $group)
                            ->where('item_id', (int) $row->id)
                            ->where('field', $field)
                            ->where('source', 'manual')
                            ->where('value', trim((string) ($byKey[(string) $row->{$column}] ?? '')))
                            ->delete();
                    }
                }
            }
        }

        $this->forgetCaches();
    }

    /**
     * THE LIVE-CATALOGUE GUARD. Acts only when at least half of the listed
     * live product slugs are present, so a fresh install, a preview fixture
     * and the test database are left exactly as they are.
     *
     * @param  array<string, mixed>  $data
     */
    private function isLiveCatalogue(array $data): bool
    {
        if (! Schema::hasTable('translations') || ! Schema::hasTable('products')) {
            return false;
        }

        $slugs = array_values(array_filter((array) ($data['guard'] ?? []), 'is_string'));

        if ($slugs === []) {
            return false;
        }

        $present = 0;

        foreach (array_chunk($slugs, 300) as $chunk) {
            $present += DB::table('products')->whereIn('slug', $chunk)->count();
        }

        return $present * 2 >= count($slugs);
    }

    /**
     * The Arabic search's own copy of each new product name (search_texts_ar),
     * normalised exactly as 2027_11_02_100300 does. A raw insert fires no
     * model hook, so without this an Arabic name would show and not be found.
     *
     * @param  array<int, string>  $names
     */
    private function indexSearch(array $names): void
    {
        if ($names === [] || ! Schema::hasTable('search_texts_ar')) {
            return;
        }

        $norm = static function (string $s): string {
            $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
            $s = strtr($s, [
                'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            ]);
            $s = mb_strtolower($s);
            $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

            return trim((string) preg_replace('/\s+/u', ' ', $s));
        };

        foreach ($names as $id => $name) {
            $text = $norm($name);

            if ($text !== '') {
                DB::table('search_texts_ar')->updateOrInsert(['product_id' => $id], ['text' => $text]);
            }
        }
    }

    /** TranslationStore's maps and the Arabic search, by their literal keys. */
    private function forgetCaches(): void
    {
        try {
            foreach (['kbb.translations.ar', 'kbb.translations.en', 'kbb.search.ar.v1', 'kbb.search.ar.ready'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own in five minutes.
        }
    }
};
