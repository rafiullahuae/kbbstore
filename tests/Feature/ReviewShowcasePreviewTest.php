<?php

declare(strict_types=1);

/*
 * Lane RD: the customer-reviews section DESIGN PREVIEWS (A-H) in
 * resources/views/partials/review-showcase/, fed by App\Support\ReviewShowcase.
 * They are previews the owner chooses between; these cases hold what any letter
 * he picks must already be true of, so the choice cannot wire in a defect.
 */

use App\Models\Product;
use App\Models\Review;
use App\Support\ReviewShowcase;
use Illuminate\Support\Facades\DB;

function rsProduct(): Product
{
    return Product::create([
        'slug' => 'rs-'.uniqid(), 'name' => 'Showcase Toner '.uniqid(), 'status' => 'publish',
        'is_visible' => true, 'price' => 5900, 'stock_status' => 'instock',
    ]);
}

function rsReviews(int $n, array $attrs = []): void
{
    $p = rsProduct();
    for ($i = 0; $i < $n; $i++) {
        $cid = DB::table('customers')->insertGetId(['name' => 'C '.$i, 'email' => uniqid('c').'@x.test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('addresses')->insert(['customer_id' => $cid, 'is_default' => true, 'city' => 'Sharjah', 'created_at' => now(), 'updated_at' => now()]);
        Review::create(array_merge([
            'product_id' => $p->id, 'customer_id' => $cid, 'author_name' => 'Aisha Khan', 'author_email' => 'secret-'.$i.'@private.test',
            'rating' => 5, 'title' => 'Calm skin', 'content' => 'Lovely toner number '.$i, 'status' => 'approved',
            'verified' => true, 'ip' => '203.0.113.9', 'images' => ['/uploads/reviews/photo-'.$i.'.webp'],
        ], $attrs));
    }
}

function rsRenderAll(): string
{
    $rs = ReviewShowcase::data();
    $html = view('partials.review-showcase._base')->render().view('partials.review-showcase._js')->render();
    foreach (array_keys(ReviewShowcase::DESIGNS) as $l) {
        $html .= view('partials.review-showcase.design-'.$l, ['rs' => $rs])->render();
    }

    return $html;
}

it('renders all eight designs from approved reviews, with name, city, product and the reviews link', function () {
    rsReviews(6);

    $html = rsRenderAll();

    expect(substr_count($html, 'class="sec rsx rs-'))->toBe(8)
        ->and($html)->toContain('Aisha K.')          // first name + initial, never the surname
        ->and($html)->not->toContain('Aisha Khan')
        ->and($html)->toContain('Sharjah')
        ->and($html)->toContain('Showcase Toner')
        ->and($html)->toContain('href="/reviews/"');
});

it('never prints a pending, spam or demo review, an email address or an IP', function () {
    // The defect this guards: a showcase reading `reviews` without ->approved()
    // ->real() would put a spam row, or a demo seed, on the homepage; a bare
    // ->get() would hand the template author_email and ip.
    rsReviews(5);
    rsReviews(1, ['status' => 'pending', 'content' => 'PENDING-ROW']);
    rsReviews(1, ['status' => 'spam', 'content' => 'SPAM-ROW']);
    rsReviews(1, ['source' => 'demo', 'content' => 'DEMO-ROW']);

    $html = rsRenderAll();

    expect($html)->not->toContain('PENDING-ROW')
        ->and($html)->not->toContain('SPAM-ROW')
        ->and($html)->not->toContain('DEMO-ROW')
        ->and($html)->not->toContain('@private.test')
        ->and($html)->not->toContain('203.0.113.9');
});

it('costs the same number of queries with 5 approved reviews as with 45', function () {
    rsReviews(5);
    ReviewShowcase::data(); // warm once-per-process checks
    DB::enableQueryLog();
    ReviewShowcase::data();
    $few = count(DB::getQueryLog());
    DB::flushQueryLog();

    rsReviews(40);
    ReviewShowcase::data();
    DB::flushQueryLog();
    ReviewShowcase::data();
    $many = count(DB::getQueryLog());

    expect($many)->toBe($few)->and($few)->toBeLessThanOrEqual(4);
});

it('carries no layout-measuring call and no timer in its script', function () {
    $js = file_get_contents(resource_path('views/partials/review-showcase/_js.blade.php'));

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'scrollWidth', 'setInterval', 'setTimeout', 'requestAnimationFrame'] as $api) {
        expect($js)->not->toContain($api);
    }
});

it('shortens names to a first name and an initial, and leaves Arabic names whole-word', function () {
    expect(ReviewShowcase::displayName('Mariam Al Mansoori'))->toBe('Mariam M.')
        ->and(ReviewShowcase::displayName('Joanna'))->toBe('Joanna')
        ->and(ReviewShowcase::displayName('مريم المنصوري'))->toBe('مريم')
        ->and(ReviewShowcase::displayName('  '))->toBe('');
});
