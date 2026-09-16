<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Review;
use App\Services\SettingsService;
use App\Support\ProductRating;

/**
 * The shop's rating capsule and the admin's Badge Themes preview are one
 * component drawn twice.
 *
 * The owner picks a badge on Store → Badge Themes, sees a preview, and expects
 * the shop to show that. For months it did not: the preview drew `.rbt-cap`
 * (a 999px pill, 5px/12px padding, 13px text, a plain heart glyph) while the
 * product page drew `.sr-capbar` (a gradient with a drop shadow, a 30px
 * radius, 8px/16px padding, a 26px circled heart, a 15px score). Two separate
 * stylesheets in two separate files with nothing holding them together.
 *
 * This test is that something. It reads both declarations out of their real
 * source files and compares the properties that make the two look alike. If
 * anyone restyles one capsule and forgets the other, the suite says so with
 * the property named.
 *
 * Deliberately NOT compared:
 *   - colour tokens. The admin lives on --border/--ink-soft, the shop on
 *     --line/--ink-2. Same intent, different design systems; forcing one set
 *     into the other's stylesheet is how you get an admin token leaking onto
 *     the shop.
 *   - text-decoration, cursor and the hover lift. The shop's capsule is an
 *     anchor down to the reviews; the preview is inert and has nothing to
 *     lift towards.
 */
function cssRule(string $file, string $selector): array
{
    $source = file_get_contents(base_path($file));

    expect($source)->not->toBeFalse("could not read {$file}");

    // The selector, then its block, up to the first closing brace. Guards
    // against matching `.rbt-cap` inside `.rbt-caption` by requiring the
    // next character to open the block or separate the selector.
    $pattern = '/(?<![\w-])' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';

    expect(preg_match($pattern, $source, $m))
        ->toBe(1, "{$selector} is not in {$file} any more — the parity check has nothing to compare");

    $out = [];

    foreach (explode(';', $m[1]) as $declaration) {
        $declaration = trim(preg_replace('/\s+/', ' ', $declaration));

        if ($declaration === '' || ! str_contains($declaration, ':')) {
            continue;
        }

        [$property, $value] = explode(':', $declaration, 2);
        $out[trim($property)] = trim($value);
    }

    return $out;
}

const ADMIN_CSS = 'resources/views/admin/partials/review-badges-screen.blade.php';

/**
 * Every file that defines .sr-capbar, and there are two.
 *
 * This list is the point of the test. The first attempt at this fix changed
 * only kbb-product.css and nothing moved on the shop, because
 * ProductController::reviewsCss() reads sorina-reviews.css off disk and inlines
 * it into a <style> block that lands after the built stylesheet and wins the
 * cascade. A screenshot caught it; nothing in the suite would have.
 *
 * sorina-reviews.css is the copy that renders on the product page;
 * kbb-product.css is the built stylesheet under it. If a third copy ever
 * appears, add it here — the shape check below is only worth what this list
 * covers.
 *
 * THERE WERE THREE (Lane DM). store/review-wall.blade.php carried its own copy,
 * and the capsule it styled was a hard-coded "4.9 · 128 reviews" sitting over
 * the name of one product that page never looked up, above twelve invented
 * customers in a JavaScript array. The page is now built from the reviews
 * table, the capsule went with the figure it existed to print, and the rule
 * went with the capsule. The two copies left are the two that render over real
 * per-product figures, and their parity with the admin preview is unchanged.
 */
function capsuleSources(): array
{
    return [
        'resources/css/kbb/sorina-reviews.css',
        'resources/css/kbb/kbb-product.css',
    ];
}

it('defines the capsule in exactly the files this test knows about', function () {
    /*
     * Guards the list itself. A new copy somewhere else is a new way for the
     * shop to disagree with the admin, and the parity checks below cannot see
     * a file they were never told about.
     */
    $found = [];

    $roots = [base_path('resources'), base_path('app')];

    foreach ($roots as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! in_array($file->getExtension(), ['css', 'php'], true)) {
                continue;
            }

            $body = (string) file_get_contents($file->getPathname());

            // A definition, not a mention. The admin partials talk ABOUT
            // .sr-capbar in their comments without styling it.
            if (preg_match('/(?<![\w-])\.sr-capbar\s*\{/', $body)) {
                $found[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }
    }

    sort($found);
    $expected = capsuleSources();
    sort($expected);

    expect($found)->toBe(
        $expected,
        'the set of files defining .sr-capbar changed — add or remove it in capsuleSources()'
    );
});

it('draws the shop capsule in the same shape as the admin preview', function (string $shopFile) {
    $shop = cssRule($shopFile, '.sr-capbar');
    $admin = cssRule(ADMIN_CSS, '.rbt-cap');

    // Every property below decides how the capsule reads at rest. Each one of
    // them differed before this was fixed.
    foreach (['display', 'align-items', 'gap', 'padding', 'border-radius', 'font-size', 'white-space', 'line-height'] as $property) {
        /*
         * array_key_exists, not toHaveKey. Pest reads toHaveKey's second
         * argument as the EXPECTED VALUE, not a failure message, so
         * ->toHaveKey('display', 'the admin preview no longer sets display')
         * quietly asserts that display equals that sentence. Same trap as
         * toContain's variadic needles, which bit RichTextAllowlistTest.
         */
        expect(array_key_exists($property, $admin))
            ->toBeTrue("the admin preview no longer sets {$property}");
        expect(array_key_exists($property, $shop))
            ->toBeTrue("the shop capsule no longer sets {$property}");

        expect($shop[$property])->toBe(
            $admin[$property],
            "{$shopFile} and the admin preview disagree on {$property}"
        );
    }

    // The border colour is each side's own token, but the weight and style are
    // what you see. A 2px border on one and 1px on the other is a visible
    // difference the colour exemption must not wave through.
    foreach ([$shopFile => $shop, ADMIN_CSS => $admin] as $side => $rule) {
        expect(array_key_exists('border', $rule))
            ->toBeTrue("the capsule in {$side} dropped its border");
        expect(str_starts_with($rule['border'], '1px solid '))
            ->toBeTrue("the capsule in {$side} is no longer a 1px solid border: {$rule['border']}");
    }
})->with(capsuleSources());

it('gives the capsule children the same treatment on both sides', function (string $shopFile) {
    // heart colour, star tracking and the weight of the score. The heart is a
    // literal on both sides on purpose — it is the brand pink, not a themeable
    // token, and the owner's colour picker drives the STARS, not the heart.
    $pairs = [
        ['.sr-cap-heart', '.rbt-heart', 'color'],
        ['.sr-cap-stars', '.rbt-stars', 'letter-spacing'],
        ['.sr-cap-avg', '.rbt-avg', 'font-weight'],
    ];

    foreach ($pairs as [$shopSelector, $adminSelector, $property]) {
        $shop = cssRule($shopFile, $shopSelector);
        $admin = cssRule(ADMIN_CSS, $adminSelector);

        expect(array_key_exists($property, $shop))
            ->toBeTrue("{$shopSelector} in {$shopFile} no longer sets {$property}");
        expect(array_key_exists($property, $admin))
            ->toBeTrue("{$adminSelector} no longer sets {$property}");

        expect($shop[$property])->toBe(
            $admin[$property],
            "{$shopSelector} in {$shopFile} and {$adminSelector} disagree on {$property}"
        );
    }
})->with(capsuleSources());

it('keeps the shop capsule free of the decoration the preview never had', function (string $shopFile) {
    $shop = cssRule($shopFile, '.sr-capbar');

    /*
     * The three things that made the two badges look like different
     * components. A resting box-shadow or a gradient puts the shop back where
     * it was; both belong on :hover at most, which is a separate rule this
     * one cannot see.
     */
    expect(array_key_exists('box-shadow', $shop))
        ->toBeFalse("the capsule in {$shopFile} has a resting shadow the preview has not");

    expect(str_contains($shop['background'] ?? '', 'gradient'))
        ->toBeFalse("the capsule in {$shopFile} is painted with a gradient again");

    // The 28px circled heart, specifically. It is what made the shop's badge
    // twice the height of the admin's, and it hides inside .sr-cap-heart
    // rather than in the capsule rule.
    $heart = cssRule($shopFile, '.sr-cap-heart');

    expect(array_key_exists('width', $heart))
        ->toBeFalse("the heart in {$shopFile} is a sized circle again, not a glyph");
    expect(str_contains($heart['background'] ?? '', 'gradient'))
        ->toBeFalse("the heart in {$shopFile} is a gradient disc again");

    expect($shop['border-radius'] ?? '')->toBe('999px', "the capsule in {$shopFile} is not a full pill");
})->with(capsuleSources());

it('renders the capsule parts in the preview order, honouring the same switches', function () {
    /*
     * CSS parity is half of it. The preview emits heart, stars, average, count
     * in that order, each behind its own setting; if the page emits them in a
     * different order or ignores a switch, the owner still sees one thing and
     * the shopper another.
     */
    $product = Product::create([
        'slug' => 'parity-' . uniqid(),
        'name' => 'Parity Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12900,
        'stock_status' => 'instock',
    ]);

    Review::create([
        'product_id' => $product->id,
        'author_name' => 'Shopper',
        'rating' => 5,
        'content' => 'Real review, so the capsule has something to say.',
        'status' => 'approved',
    ]);

    ProductRating::refresh([$product->id]);

    /*
     * Through SettingsService::set(), which is what the admin screen calls.
     * Writing the rows directly does not work: the service holds its own
     * forever-cache AND a per-process memo, and the page would keep rendering
     * the settings from before the write.
     */
    $settings = app(SettingsService::class);

    foreach ([
        'review_capsule_style' => 'capsule',
        'review_badge_heart' => '1',
        'review_badge_avg' => '1',
        'review_badge_count' => '1',
        'review_badge_label' => '{n} reviews',
        'review_badge_colour' => '#E8A33D',
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $html = $this->get('/product/' . $product->slug . '/')->assertOk()->getContent();

    expect(preg_match('/<a[^>]*class="[^"]*sr-capbar[^"]*"[^>]*>(.*?)<\/a>/s', $html, $m))
        ->toBe(1, 'the capsule did not render for a product with a real review');

    $inner = $m[1];

    $heart = strpos($inner, 'sr-cap-heart');
    $stars = strpos($inner, 'sr-cap-stars');
    $avg = strpos($inner, 'sr-cap-avg');
    $count = strpos($inner, 'sr-cap-count');

    expect($heart)->not->toBeFalse('the heart is missing with review_badge_heart on');
    expect($stars)->not->toBeFalse('the stars are missing');
    expect($avg)->not->toBeFalse('the average is missing with review_badge_avg on');
    expect($count)->not->toBeFalse('the count is missing with review_badge_count on');

    expect($heart < $stars && $stars < $avg && $avg < $count)
        ->toBeTrue('the capsule parts are not in the heart → stars → average → count order the preview draws');

    // And the switches actually switch. The heart off must remove the heart
    // and leave the rest standing.
    $settings->set('review_badge_heart', '0');

    $html = $this->get('/product/' . $product->slug . '/')->assertOk()->getContent();

    expect(preg_match('/<a[^>]*class="[^"]*sr-capbar[^"]*"[^>]*>(.*?)<\/a>/s', $html, $m))->toBe(1);

    expect(str_contains($m[1], 'sr-cap-heart'))->toBeFalse('the heart survived being switched off');
    expect(str_contains($m[1], 'sr-cap-stars'))->toBeTrue('switching the heart off took the stars with it');
});
