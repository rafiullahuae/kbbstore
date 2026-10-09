<?php

declare(strict_types=1);

use App\Models\Page;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;

/**
 * The owner's About us story (Lane AB): "also input this text nicely in about
 * us page:".
 *
 * What the defect looked like on the shop: /about/ still showed the seeded
 * placeholder — "This is placeholder wording. Edit this page in Store → Pages
 * to publish your own story." — over three invented subheadings, weeks after
 * the owner had sent the real wording.
 *
 * Mutation notes, each checked by hand:
 *   · delete database/migrations/2027_10_15_110000_owner_about_us_story.php
 *     → RED (case 1: the placeholder is served, no story, no facts row).
 *   · change one word or one ’ to ' in the migration's STORY → RED (case 1).
 *   · put an <h1> in STORY → RED (case 1, two h1s on the page).
 *   · make down() a no-op → RED (case 3).
 *   · drop the `.kbb-about-facts` rule from kbb.css → RED (case 4).
 */
function abMigration(): object
{
    return require database_path('migrations/2027_10_15_110000_owner_about_us_story.php');
}

/** The seeded about content, exactly as 2026_11_06_000000 wrote it. */
function abSeededContent(): string
{
    $seed = require database_path('migrations/2026_11_06_000000_seed_footer_content_pages.php');

    foreach ((new ReflectionMethod($seed, 'pages'))->invoke($seed) as $page) {
        if ($page['slug'] === 'about') {
            return $page['content'];
        }
    }

    throw new RuntimeException('The seed no longer carries an about page.');
}

/** The owner's text, typed here independently of the migration. */
const AB_OWNER_PARAGRAPHS = [
    'At K-Beauty Bliss UAE, we are passionate about bringing the best of Korean beauty to skincare enthusiasts across the UAE. Our mission is to provide you with authentic, high-quality K-beauty products that deliver real results. Whether you’re looking to enhance your skincare routine, discover the latest beauty trends, or find effective solutions for your skin concerns, we’ve got you covered.',
    'We carefully curate our product range, ensuring that every item fulfills the highest standards of quality and effectiveness. From skincare and hair care to makeup and beauty sets, we offer a wide variety of products created to cater to all your beauty needs.',
    'Customer satisfaction is at the heart of everything we do. With fast delivery options, including 1-3 day delivery across the UAE and free shipping on orders over 199 AED, we make it easy and convenient for you to get the products you love.',
    'Join the K-Beauty Bliss community and experience the beauty revolution that’s taking the world by storm. Your journey to flawless, radiant skin starts here!',
];

it('serves the owner\'s story on /about/, verbatim, under exactly one h1 and with no script', function () {
    $html = $this->get('/about')->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<p class="kbb-about-eyebrow">Our story</p>')
        ->and($html)->toContain('<h2>About K-Beauty Bliss UAE</h2>')
        ->and($html)->toContain('<a href="/shop/">Shop now</a>')
        ->and($html)->toContain('<li><strong>1-3 day delivery</strong> across the UAE</li>')
        ->and($html)->toContain('<li><strong>Free shipping</strong> on orders over 199 AED</li>')
        ->and($html)->not->toContain('This is placeholder wording');

    foreach (AB_OWNER_PARAGRAPHS as $p) {
        expect($html)->toContain('>' . $p . '</p>');
    }

    preg_match('#<div class="policy-body">(.*?)</div>\s*\n\s*<p class="policy-date">#s', $html, $m);
    expect($m[1] ?? '')->toContain('kbb-about')
        ->and(stripos($m[1] ?? '', '<script'))->toBeFalse()
        ->and(stripos($m[1] ?? '', 'style='))->toBeFalse();
});

it('survives the page editor\'s sanitiser, so the owner can re-save it without losing the layout', function () {
    $story = abMigration()::STORY;
    $norm = fn (string $h): string => preg_replace('/>\s+</', '><', trim($h));

    expect($norm(RichText::clean($story)))->toBe($norm($story));
});

it('keeps the previous wording as a draft and down() puts it back', function () {
    $m = abMigration();
    $about = Page::query()->where('slug', 'about')->firstOrFail();
    $backup = Page::query()->where('slug', 'about-previous')->firstOrFail();

    expect($about->content)->toBe($m::storyHtml())
        ->and($about->title)->toBe('About Us')
        ->and($backup->status)->toBe('draft')
        ->and($backup->content)->toBe(abSeededContent());

    // A draft is never served.
    $this->get('/about-previous/')->assertNotFound();

    // Nothing but the two about rows is touched, by either direction.
    $others = fn () => DB::table('pages')->whereNotIn('slug', ['about', 'about-previous'])->orderBy('id')->get()->toArray();
    $before = $others();

    $m->down();
    expect(Page::query()->where('slug', 'about')->value('content'))->toBe(abSeededContent())
        ->and(Page::query()->where('slug', 'about-previous')->exists())->toBeFalse()
        ->and($others())->toEqual($before);
    $this->get('/about')->assertOk()->assertSee('This is placeholder wording', false)->assertDontSee('kbb-about', false);

    $m->up();
    $m->up(); // a re-run changes nothing and keeps the first copy
    expect(Page::query()->where('slug', 'about')->value('content'))->toBe($m::storyHtml())
        ->and(Page::query()->where('slug', 'about-previous')->count())->toBe(1)
        ->and(Page::query()->where('slug', 'about-previous')->value('content'))->toBe(abSeededContent())
        ->and($others())->toEqual($before);

    // A page the owner edited after the update is his: a rollback leaves it.
    Page::query()->where('slug', 'about')->update(['content' => '<p>Owner edit.</p>']);
    $m->down();
    expect(Page::query()->where('slug', 'about')->value('content'))->toBe('<p>Owner edit.</p>');
});

it('backs every class the story uses with a scoped rule in kbb.css, in logical properties only', function () {
    $css = file_get_contents(resource_path('css/kbb/kbb.css'));
    preg_match_all('/class="([^"]+)"/', abMigration()::STORY, $m);

    foreach (array_unique($m[1]) as $class) {
        expect($css)->toContain('.kbb-home .policy-body .' . $class . '{');
    }

    preg_match_all('/^\.kbb-home \.policy-body \.kbb-about[^{]*\{[^}]*\}$/m', $css, $rules);
    $block = implode("\n", $rules[0]);
    expect(count($rules[0]))->toBeGreaterThan(5)
        ->and($block)->not->toMatch('/(?:margin|padding|border)-(?:left|right)\b|\b(?:left|right)\s*:/');
});
