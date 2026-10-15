<?php

declare(strict_types=1);

/*
 * Lane AR round 3 -- shippable through the updater, and the header's Arabic.
 *
 * 2.60.512 broke live because a migration called a class whose previous
 * version was still in opcache while the updater ran it. And a package is
 * copied file by file, in the order the filesystem lists them, so for a moment
 * a new view can be on disk before the new class it calls. Both are pinned
 * here: the lane's migrations read no class of the shop, and every view call
 * to a class this lane added falls back to the English it always printed.
 */

/** The lane's migrations: rounds 2 and 3 of Lane AR. */
function shipLaneMigrations(): array
{
    return glob(base_path('database/migrations/2027_11_02_1*.php')) ?: [];
}

/** PHP source with comments and doc comments removed. */
function shipCode(string $file): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }

    return $out;
}

function shipConst(string $file, string $name): array
{
    $migration = require $file;

    return (new ReflectionClassConstant($migration, $name))->getValue();
}

/*
 * 1. NO SHOP CLASS IN A MIGRATION. Mutation: put `use App\Models\Translation;`
 *    back in the seed migration and this is red.
 */
it('reads no class of the shop in any of the lane migrations', function () {
    expect(count(shipLaneMigrations()))->toBeGreaterThanOrEqual(4);

    foreach (shipLaneMigrations() as $file) {
        $code = shipCode($file);
        expect(str_contains($code, 'App\\'))->toBeFalse(basename($file).' names a class of the shop')
            ->and(preg_match('/\buse\s+(?!Illuminate\\\\)[A-Z]/', $code))->toBe(0, basename($file));
    }
});

/*
 * 2. THE LITERALS ARE THE CLASSES' DATA. The migrations carry copies; these
 *    keep the copies true. Mutation: change one Arabic draft in ArabicWording
 *    without re-running tools/arr-gen-migrations.php and this is red.
 */
it('carries exactly the drafts and corrections the classes hold', function () {
    $rows = shipConst(base_path('database/migrations/2027_11_02_100000_seed_lane_ar2_wording_arabic_drafts.php'), 'ROWS');
    $want = [];
    foreach (\App\Support\ArabicWording::ENGLISH as $key => $english) {
        $want['store.'.strtolower($key)] = [$english, \App\Support\ArabicWording::ARABIC[$key]];
    }
    expect($rows)->toBe($want);

    $fixes = shipConst(base_path('database/migrations/2027_11_02_100100_correct_lane_ar2_arabic_drafts.php'), 'FIXES');
    $want = [];
    foreach (\App\Services\Translation\ArabicDraftCorrections::ROUND2 as $key => $pair) {
        $want[strtolower($key)] = $pair;
    }
    expect($fixes)->toBe($want);
});

/*
 * 3. A VIEW NEVER CALLS A NEW CLASS UNGUARDED. Mutation: unwrap one
 *    `class_exists(...) ? ... :` in any view and this names the file.
 */
it('guards every view call to a class this lane added', function () {
    $bad = [];
    $seen = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $f) {
        if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.blade.php')) {
            continue;
        }
        $src = (string) file_get_contents($f->getPathname());
        preg_match_all('/\\\\App\\\\Support\\\\(ArabicWording|LocalDate|CountryNames|ArabicSearch|ArabicPreview|HomeArabic)::(?!class)/', $src, $m, PREG_OFFSET_CAPTURE);

        foreach ($m[0] as [$hit, $at]) {
            $seen++;
            $before = substr($src, max(0, $at - 80), min(80, $at));
            if (! preg_match('/class_exists\(\\\\App\\\\Support\\\\'.preg_quote($m[1][0][0] ?? '', '/').'::class\) \?\s*$/', $before)
                && ! preg_match('/class_exists\(\\\\App\\\\Support\\\\\w+::class\) \?\s*$/', $before)) {
                $bad[] = str_replace(resource_path('views').'/', '', $f->getPathname()).': '.$hit;
            }
        }
    }

    // The scan cannot pass by finding nothing.
    expect($seen)->toBeGreaterThan(40)
        ->and($bad)->toBe([]);
});

/*
 * 4. THE LOTUS ON THE RIGHT, THE NAME IN ARABIC, ON /ar ONLY. lockup() and
 *    the placeholder return exactly what they returned in English, and on /ar
 *    only while the wordmark is the shipped one. Mutation: drop the locale
 *    test in lockup() and the English half is red.
 */
it('draws the Arabic wordmark and search placeholder on /ar only', function () {
    $h = app(\App\Services\HeaderSettings::class);

    $en = $h->lockup();
    expect($en['name'])->toBe('K-Beauty')
        ->and($en['class'])->not->toContain('lgx-ar')
        ->and($h->get('search_text'))->toBe('Search skincare, brands…');

    app()->setLocale('ar');
    $ar = $h->lockup();
    expect($ar['name'].$ar['accent'])->toBe('كي بيوتيبليس')
        ->and($ar['tag'])->toBe('العناية الكورية والمكياج')
        ->and($ar['class'])->toContain('lgx-ar')
        ->and($ar['style'])->toBe($en['style'])
        ->and($h->get('search_text'))->toBe((string) __('store.wording.search_placeholder'));

    $h->save(['logo_text' => 'My Shop']);
    expect(app(\App\Services\HeaderSettings::class)->lockup()['name'])->toBe('My Shop')
        ->and(app(\App\Services\HeaderSettings::class)->lockup()['class'])->not->toContain('lgx-ar');
    app()->setLocale('en');
});
