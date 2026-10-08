<?php

declare(strict_types=1);

/*
 * Catalog → Image SEO, trained on the catalogue (Lane IN, 8 October).
 *
 * The owner: "train our app well by complex conditions also". Every rule in
 * Tests\Support\ImageNamerRules runs over every title in
 * tests/Fixtures/image-namer-titles.php, for both strategies and 1 to 8
 * pictures. On the code before this lane the corpus broke 1,673 times
 * (storage/in-logs is the lane's record); the tests below pin each root
 * cause on its own, so a regression names itself instead of hiding in a
 * count.
 *
 * Pure string work: no query, no setting, no file.
 */

use App\Services\ImageSeo\AltText;
use App\Services\ImageSeo\ImageNamer;
use App\Services\ImageSeo\ImageScore;
use Tests\Support\ImageNamerRules;

function inCorpus(): array
{
    return require base_path('tests/Fixtures/image-namer-titles.php');
}

it('holds every quality rule over the whole training corpus, both strategies, 1 to 8 pictures', function () {
    $corpus = inCorpus();
    $violations = ImageNamerRules::check($corpus);

    expect(count($corpus))->toBeGreaterThanOrEqual(150)
        ->and(count(array_filter($corpus, static fn (array $row): bool => $row[3])))->toBeGreaterThanOrEqual(30)
        ->and($violations)->toBe([], implode("\n", array_slice($violations, 0, 25)));
    // MUTATION: any one of the root fixes below, reverted, turns this red with
    // the rule and title named (e.g. revert the "by" line in orders() and it
    // reads "repeat · Some By Mi · … "by" ×2").
});

it('names the owner\'s three new titles, set included, exactly', function () {
    /*
     * The owner's titles, 8 October. The set used to read
     * "set-345-relief-cream-mist-spray-by-dr-althea": "set" torn from what it
     * is a set of. A set's type is now its contents plus its set word.
     * MUTATION: in ImageNamer::split() make `$set = false;` and the third name
     * is "set-345-relief-cream-mist-spray-by-dr-althea" again.
     */
    expect(ImageNamer::names('Dr. Althea', 'Dr. Althea 345 Relief Cream + Mist Spray Set', 5))->toBe([
        'dr-althea-345-relief-cream-mist-spray-set',
        '345-relief-dr-althea-cream-mist-spray-set',
        'cream-mist-spray-set-345-relief-by-dr-althea',
        '345-relief-cream-mist-spray-set-dr-althea',
        'dr-althea-cream-mist-spray-set-345-relief',
    ])->and(ImageNamer::groups('Dr. Althea', 'Dr. Althea 345 Relief Cream + Mist Spray Set')['type'])->toBe(['cream', 'mist', 'spray', 'set']);

    expect(ImageNamer::names('Dr.Reju-All', 'Dr.Reju-All - Advanced PDRN Rejuvenating Lip Serum', 5))->toBe([
        'dr-reju-all-advanced-pdrn-rejuvenating-lip-serum',
        'advanced-pdrn-rejuvenating-dr-reju-all-lip-serum',
        'lip-serum-advanced-pdrn-rejuvenating-by-dr-reju-all',
        'advanced-pdrn-rejuvenating-lip-serum-dr-reju-all',
        'dr-reju-all-lip-serum-advanced-pdrn-rejuvenating',
    ])->and(ImageNamer::names('Dr.Melaxin', 'Dr.Melaxin - Calcium Dark Spot Cover Eye Cream', 5))->toBe([
        'dr-melaxin-calcium-dark-spot-cover-eye-cream',
        'calcium-dark-spot-cover-dr-melaxin-eye-cream',
        'eye-cream-calcium-dark-spot-cover-by-dr-melaxin',
        'calcium-dark-spot-cover-eye-cream-dr-melaxin',
        'dr-melaxin-eye-cream-calcium-dark-spot-cover',
    ]);

    expect(AltText::propose('Dr. Althea', 'Dr. Althea 345 Relief Cream + Mist Spray Set', 'Sets', 5))->toBe([
        'Dr. Althea 345 Relief Cream + Mist Spray Set',
        '345 Relief Cream + Mist Spray Set by Dr. Althea',
        'Dr. Althea 345 Relief Cream + Mist Spray Set – Sets',
        '345 Relief Cream + Mist Spray Set by Dr. Althea – view 4',
        '345 Relief Cream + Mist Spray Set by Dr. Althea – view 5',
    ]);
});

it('keeps a number that is the name with its word, and drops a pack size, a conversion and a PA rating whole', function () {
    /*
     * "30 Days" lost its 30 and kept "days" (toner-aha-bha-pha-days-…); a
     * long sun cream kept "pa" and "fl oz" and lost "sun" and "rice"
     * (beauty-of-joseon-relief-spf50-pa-fl-oz). A number that is the name is
     * glued to its word; a size, a count and a multi-pack are one unit each.
     * MUTATION: drop the glueNumbers() call in ImageNamer::tokens() and the
     * AHA toner reads "…-pha-30-toner", a 30 with no "days"; delete the fl oz
     * pattern and the Anua oil loses its "200ml" to the conversion.
     */
    $days = ImageNamer::groups('Some By Mi', 'Some By Mi Bye Bye Blackhead 30 Days Miracle Green Tea Tox Bubble Cleanser 120g');
    expect(implode('-', $days['ordered']))->toBe('bye-blackhead-30-days-cleanser')
        ->and(ImageNamer::names('Some By Mi', 'Some By Mi AHA BHA PHA 30 Days Miracle Toner 150ml', 1))->toBe(['some-by-mi-aha-bha-pha-toner'])
        ->and(ImageNamer::names('Anua', 'Anua Heartleaf Pore Control Cleansing Oil 200ml/6.76 fl.oz', 1))->toBe(['anua-heartleaf-pore-control-cleansing-oil-200ml']);

    expect(ImageNamer::names('Beauty of Joseon', 'Beauty of Joseon Relief Sun: Rice + Probiotics SPF50+ PA++++ 50ml/1.69 fl.oz', 1))
        ->toBe(['beauty-of-joseon-relief-sun-rice-probiotics-spf50'])
        ->and(ImageNamer::names('Round Lab', 'Birch Juice Moisturizing Sun Cream SPF45 PA++++ 50ml', 3)[2])
        ->toBe('sun-cream-spf45-birch-juice-moisturizing-by-round-lab');

    // 1+1, x2 and "2 x 100ml" are a 2-pack; "2X" in a name is a name; "60 pads" after "Pad" goes, never a lone 60.
    expect(ImageNamer::names('Klairs', 'Klairs Supple Preparation Facial Toner 180ml 1+1', 1))->toBe(['klairs-supple-preparation-facial-toner-180ml-2-pack'])
        ->and(ImageNamer::names('Mixsoon', 'Mixsoon Bean Essence 2 x 100ml', 1))->toBe(['mixsoon-bean-essence-100ml-2-pack'])
        ->and(ImageNamer::names('Innisfree', 'Innisfree Super Volcanic Pore Clay Mask 2X 100ml', 1))->toBe(['innisfree-super-volcanic-pore-clay-mask-2x-100ml'])
        ->and(ImageNamer::names('Etude', 'NEW Etude SoonJung pH 5.5 Relief Toner 180ml', 1))->toBe(['etude-soonjung-ph-5-5-relief-toner-180ml']);
});

it('reads the type around the head, says "by" once, and finds the brand however the title spells it', function () {
    /*
     * "Eye Patches with Retinol" made "patches-retinol" the type; "Cream Mist
     * Spray" made "spray" the type and "cream mist" key words; "By Wishtrend"
     * and "Some By Mi" got "by-by-wishtrend" and a 9-word name; "Manyo",
     * "Dr. Jart+" and "Skin 1004" were not recognised as the brand.
     * MUTATION: make ImageNamer::qualifies() return true and "retinol" is in
     * the type again; restore `$by = array_merge(['by'], $b)` and the third
     * name ends "-by-by-wishtrend".
     */
    expect(ImageNamer::groups('Etude', 'Etude Collagen Eye Patches with Retinol')['type'])->toBe(['eye', 'patches'])
        ->and(ImageNamer::groups('Dr.Jart+', 'Dr.Jart+ Ceramidin Cream Mist Spray 120ml')['type'])->toBe(['cream', 'mist', 'spray', '120ml'])
        ->and(ImageNamer::names('By Wishtrend', 'By Wishtrend Polyphenol in Propolis 15% Ampoule 30ml', 3)[2])->toBe('ampoule-30ml-polyphenol-propolis-15-by-wishtrend')
        ->and(ImageNamer::names('Some By Mi', 'Some By Mi V10 Hyal Air Fit Toner', 3)[2])->toBe('toner-v10-hyal-air-fit-some-by-mi')
        ->and(ImageNamer::names('ma:nyo', 'Manyo Galactomy Niacin Essence 50ml', 1))->toBe(['ma-nyo-galactomy-niacin-essence-50ml'])
        ->and(ImageNamer::names('Dr.Jart+', 'Dr. Jart+ Every Sun Day Mild Sun SPF43 PA+++', 1))->toBe(['dr-jart-every-sun-day-mild-spf43'])
        ->and(ImageNamer::names('Abib', 'Abib Mild Acidic pH Sheet Mask Heartleaf Fit Set of 3', 3)[2])->toBe('sheet-mask-set-of-3-mild-acidic-by-abib');
});

it('writes the brand once as the shop spells it, in English, and never loses the suffix or the set word to a long title', function () {
    /*
     * "[COSRX] Low pH … by COSRX" named the brand twice; "Dr. Jart+ … by
     * Dr.Jart+" twice and spelled two ways; Arabic stayed inside the English
     * alt; and a title over 125 characters was cut AFTER "– view 4" was added,
     * so pictures 3 to 8 of a long product all read the same and a set lost
     * "Set". MUTATION: in AltText::fit() return cut($text.$suffix, MAX) and
     * the long Torriden alts repeat; drop withoutBrand()'s sign rule and the
     * Dr.Jart+ alt starts "+ Ceramidin".
     */
    expect(AltText::propose('COSRX', '[COSRX] Low pH Good Morning Gel Cleanser — 150ml', 'Cleansers', 2))->toBe([
        'COSRX Low pH Good Morning Gel Cleanser — 150ml',
        'Low pH Good Morning Gel Cleanser — 150ml by COSRX',
    ])->and(AltText::propose('Dr.Jart+', 'Dr. Jart+ Every Sun Day Mild Sun SPF43 PA+++', null, 2))->toBe([
        'Dr.Jart+ Every Sun Day Mild Sun SPF43 PA+++',
        'Every Sun Day Mild Sun SPF43 PA+++ by Dr.Jart+',
    ])->and(AltText::propose('Dr.Jart+', 'Dr.Jart+ Ceramidin Cream Mist Spray 120ml', null, 2)[1])->toBe('Ceramidin Cream Mist Spray 120ml by Dr.Jart+')
        ->and(AltText::propose('Anua', 'Anua Heartleaf 77% Soothing Toner 250ml | تونر أنوا للبشرة', null, 1))->toBe(['Anua Heartleaf 77% Soothing Toner 250ml'])
        ->and(AltText::propose('By Wishtrend', 'By Wishtrend Pure Vitamin C 21.5 Advanced Serum Kit', null, 2)[1])->toBe('Pure Vitamin C 21.5 Advanced Serum Kit from By Wishtrend');

    $long = AltText::propose('Torriden', 'Torriden DIVE-IN Low Molecular Hyaluronic Acid Serum + Toner + Cream + Cleansing Foam Full Routine Skin Care Gift Set with Pouch', 'Sets', 8);

    expect(array_unique($long))->toHaveCount(8);

    foreach ($long as $i => $alt) {
        expect(mb_strlen($alt))->toBeLessThanOrEqual(AltText::MAX)
            ->and($alt)->toContain('Set')->toContain('Torriden');

        if ($i >= 3) {
            expect($alt)->toEndWith('– view '.($i + 1));
        }
    }
});

it('scores keyword stuffing the way Google describes it: a keyword list in the alt, a word twice in the file name', function () {
    /*
     * Google: "Avoid filling alt attributes with keywords (keyword
     * stuffing)"; its spam policy describes keywords that "appear in a list
     * or group". The score only caught one word written three times, so a
     * comma list of twelve different keywords scored a clean 10/10, and so
     * did "eye-patches-eye-mask-patches". MUTATION: drop isKeywordList() from
     * the $stuffed line and the first score is 100; drop the repeats() branch
     * and the second is 100.
     */
    $list = ImageScore::score('medicube-pdrn-eye-patches.jpg', 'Medicube', 'Medicube PDRN eye patches', 'Medicube PDRN eye patches, collagen, korean skincare, dark circles, puffy eyes', true);
    $twice = ImageScore::score('medicube-pdrn-eye-patches-eye-mask.jpg', 'Medicube', 'Medicube PDRN eye patches', 'Medicube PDRN Eye Patches', true);
    $natural = ImageScore::score('medicube-pdrn-eye-patches.jpg', 'Medicube', 'Medicube PDRN eye patches', 'Medicube PDRN Eye Patches, 60 patches in a jar', true);

    expect($list['score'])->toBe(95)->and(ImageScore::reasons($list['lost']))->toBe('−0.5 keyword stuffing in alt')
        ->and($twice['score'])->toBe(90)->and(ImageScore::reasons($twice['lost']))->toBe('−1 a word repeated in file name')
        ->and($natural['score'])->toBe(100);
});
