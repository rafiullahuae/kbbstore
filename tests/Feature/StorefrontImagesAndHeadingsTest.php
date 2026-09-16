<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Support\CoverImage;

/**
 * Two things Google could not see, pinned against RENDERED OUTPUT.
 *
 * 1. Photographs drawn as CSS backgrounds. The article hero, the journal index
 *    and "related" covers, the home page's journal rail and the "#KBeautyBliss
 *    spotted" tiles — which are product photographs — were all
 *    `style="background:… url(…)"`. Google Images indexes <img> elements; it
 *    does not index a background-image, and a screen reader gets nothing from
 *    one either. The article page made that worse than invisible: it published
 *    the same column as og:image and as schema.org Article.image, so it told
 *    Google there was a hero photograph on a page where no image element
 *    existed.
 *
 * 2. Pages with no <h1>. The home page, the four collection pages and
 *    /skin-quiz rendered no first-level heading of any kind.
 *
 * WHY THESE ASSERT ON HTML AND NOT ON THE TEMPLATES. Every one of these bugs
 * is invisible in the Blade source — the templates all "had" a cover and a
 * heading. What they did not have was an <img> and an <h1> in the bytes the
 * server sends, which is the only artefact Google and a screen reader ever
 * see. So each test requests the page and counts what came back, and the
 * helpers below strip <style>, <script> and comments first: a CSS comment
 * containing the characters "<h1>" is not a heading, and counting it as one is
 * how a test like this quietly stops meaning anything.
 */

/** The served page with style, script and comment text removed. */
function renderedMarkup(string $html): string
{
    return (string) preg_replace(
        ['#<style\b[^>]*>.*?</style>#is', '#<script\b[^>]*>.*?</script>#is', '#<!--.*?-->#s'],
        '',
        $html
    );
}

/** @return list<string> every <img …> tag in the page's real markup */
function renderedImages(string $html): array
{
    preg_match_all('/<img\b[^>]*>/i', renderedMarkup($html), $m);

    return $m[0];
}

function countH1(string $html): int
{
    return preg_match_all('/<h1[\s>]/i', renderedMarkup($html));
}

/**
 * The markup of one section, from the element carrying $class to the end of
 * the <section> it lives in.
 *
 * Needed because "the page contains this image URL" is not the same claim as
 * "this tile renders that image": the home page puts the same catalogue
 * photographs in four rails, so an assertion against the whole page passes
 * even when the tile under test renders nothing at all.
 */
function sectionHtml(string $html, string $class): string
{
    $start = strpos($html, 'class="' . $class . '"');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, '</section>', $start);

    return substr($html, $start, $end === false ? null : $end - $start);
}

/** Catalogue and journal with real photographs, plus every cover shape. */
function seedIndexableStorefront(): void
{
    test()->seed(\Database\Seeders\DatabaseSeeder::class);

    Product::query()->visible()->get()->each(function (Product $p, int $i) {
        $p->forceFill(['image' => 'https://cdn.example.com/product-' . $i . '.jpg'])->save();
    });

    $i = 0;
    foreach (coverShapes() as $name => $cover) {
        Post::create([
            'slug' => 'cover-' . $name,
            'title' => 'Cover ' . $name,
            'body' => '<p>Body copy.</p>',
            'excerpt' => 'Excerpt.',
            'tag' => 'Routine',
            'cover' => $cover,
            'status' => 'published',
            'published_at' => now()->subMinutes($i++),
        ]);
    }

    \App\Http\Controllers\Store\HomeController::flushCache();
}

/** The four things posts.cover actually holds. */
function coverShapes(): array
{
    return [
        'plain-url' => 'https://cdn.example.com/hero.jpg',
        'css-url' => "#fff url('https://cdn.example.com/shorthand.jpg') center/cover",
        'gradient' => 'linear-gradient(135deg,#FFF0F4,#FCE0E8)',
        'hex' => '#FCE0E8',
    ];
}

/** Every page that had no <h1>, plus the two that already had one. */
function pagesUnderTest(): array
{
    return ['/', '/new-in', '/best-sellers', '/super-sale', '/everything-under-54-aed',
        '/skin-quiz', '/skincare-guide/', '/cover-plain-url/'];
}

it('renders exactly one h1 on every storefront page', function () {
    seedIndexableStorefront();

    foreach (pagesUnderTest() as $url) {
        $response = test()->get($url);

        expect($response->getStatusCode())->toBe(200, $url . ' did not render');
        expect(countH1((string) $response->getContent()))
            ->toBe(1, $url . ' should render exactly one <h1>');
    }
});

it('gives every rendered image a non-empty alt', function () {
    seedIndexableStorefront();

    $seenAny = false;

    foreach (pagesUnderTest() as $url) {
        foreach (renderedImages((string) test()->get($url)->getContent()) as $tag) {
            $seenAny = true;
            $hasAlt = preg_match('/\balt\s*=\s*"([^"]*)"/i', $tag, $m) === 1 && trim($m[1]) !== '';

            expect($hasAlt)->toBeTrue($url . ' has an image with no alt text: ' . $tag);
        }
    }

    expect($seenAny)->toBeTrue('no page rendered any <img> at all — the seed is not exercising this');
});

it('renders the article hero as a real img rather than a background', function () {
    seedIndexableStorefront();

    $html = (string) test()->get('/cover-plain-url/')->getContent();
    $images = renderedImages($html);

    $hero = array_values(array_filter(
        $images,
        fn (string $tag) => str_contains($tag, 'https://cdn.example.com/hero.jpg')
    ));

    expect($hero)->toHaveCount(1, 'the article hero photograph is not an <img>');
    expect($hero[0])->toContain('alt="Cover plain-url"')
        ->toContain('width="1200"')
        ->toContain('height="600"');

    // Above the fold: the hero must not be lazy-loaded.
    expect($hero[0])->not->toContain('loading="lazy"');

    // And the photograph must no longer be painted as a CSS background.
    expect($html)->not->toContain("url('https://cdn.example.com/hero.jpg')");
});

it('renders journal and product photographs as img elements', function () {
    seedIndexableStorefront();

    // Journal index covers.
    $blog = renderedImages((string) test()->get('/skincare-guide/')->getContent());
    expect($blog)->not->toBeEmpty('the journal index renders no <img>');

    $home = (string) test()->get('/')->getContent();
    expect(renderedImages($home))->not->toBeEmpty('the home page renders no <img>');

    /*
     * Scoped to each section, not to the page. The same catalogue photographs
     * appear in the best-sellers and flash rails, which already emitted real
     * <img> tags through partials/home/grid.blade.php, so "the page contains
     * product-0.jpg" was true before this change and stayed true when the
     * spotted tile's <img> was deleted. Asking the section itself is the only
     * form of the question that can fail.
     */
    $spotted = sectionHtml($home, 'ugc');
    expect($spotted)->not->toBe('', 'the #KBeautyBliss spotted section did not render');
    expect(renderedImages($spotted))->not->toBeEmpty('the spotted product tiles render no <img>');
    /*
     * str_contains inside toBeFalse, not ->not->toContain($needle, $message):
     * toContain takes a LIST of needles, so a message passed as its second
     * argument becomes a second needle and quietly changes what is asserted.
     * That is what made the Blade-comment test below pass over a page that was
     * serving a Blade comment.
     */
    expect(str_contains($spotted, 'url('))
        ->toBeFalse('the spotted tiles still paint the photograph as a CSS background');

    $rail = sectionHtml($home, 'blog');
    expect($rail)->not->toBe('', 'the home journal rail did not render');
    expect(renderedImages($rail))->not->toBeEmpty('the home journal rail renders no <img>');
});

it('keeps the placeholder for covers that are not photographs', function () {
    seedIndexableStorefront();

    // A gradient cover is decoration: no <img> for it, and the emoji stays.
    $html = (string) test()->get('/cover-gradient/')->getContent();

    expect($html)->toContain('background:linear-gradient(135deg,#FFF0F4,#FCE0E8)')
        ->toContain('✍️');

    foreach (renderedImages($html) as $tag) {
        expect($tag)->not->toContain('linear-gradient');
    }

    /*
     * The journal index as well as the article page. Two of the seeded posts
     * have decorative covers, so the index has to draw the gradient AND the
     * tag emoji for them — removing that placeholder leaves a blank rectangle
     * where a card used to have something in it. Testing only the article page
     * missed this entirely: they are different templates.
     */
    $index = (string) test()->get('/skincare-guide/')->getContent();

    expect($index)->toContain('background:linear-gradient(135deg,#FFF0F4,#FCE0E8)')
        ->toContain('✍️');

    foreach (renderedImages($index) as $tag) {
        expect($tag)->not->toContain('linear-gradient');
    }
});

it('publishes og:image only when the cover is a photograph', function () {
    seedIndexableStorefront();

    $ogImage = function (string $slug): ?string {
        $html = (string) test()->get('/cover-' . $slug . '/')->getContent();

        return preg_match('#<meta property="og:image" content="([^"]*)"#i', $html, $m) === 1
            ? $m[1]
            : null;
    };

    expect($ogImage('plain-url'))->toBe('https://cdn.example.com/hero.jpg');
    expect($ogImage('css-url'))->toBe('https://cdn.example.com/shorthand.jpg');

    /*
     * The regression this pins: a gradient cover went out as
     * og:image="https://localhost/linear-gradient(135deg,#FFF0F4,#FCE0E8)",
     * because Seo::absolute() saw no scheme and prefixed the site base. No
     * image is the honest answer for a post whose cover is decoration.
     */
    expect($ogImage('gradient'))->toBeNull();
    expect($ogImage('hex'))->toBeNull();
});

it('still renders one h1 when the hero section is switched off', function () {
    seedIndexableStorefront();

    /*
     * The home page's <h1> is the first hero slide's headline, and the hero is
     * a section the owner can switch off. Without the fallback the page loses
     * its only <h1> to an admin toggle; with a careless fallback it gets two.
     */
    Setting::updateOrCreate(
        ['key' => 'homepage_sections'],
        ['value' => json_encode(['hero' => ['desktop' => false, 'mobile' => false]])]
    );
    Setting::flushMap();
    \App\Http\Controllers\Store\HomeController::flushCache();

    $html = (string) test()->get('/')->getContent();

    expect(countH1($html))->toBe(1, 'the home page lost or duplicated its <h1> with the hero hidden');
});

it('serves no literal Blade comment', function () {
    seedIndexableStorefront();

    /*
     * store/skin-quiz.blade.php and store/post.blade.php are wrapped in
     * @verbatim from the doctype down. A Blade comment inside a verbatim region
     * is not a comment — it is text, and it is served to the visitor.
     */
    foreach (pagesUnderTest() as $url) {
        $response = test()->get($url);

        // The status matters here: an error page contains no Blade comment
        // either, so without this a 500 would read as a pass.
        expect($response->getStatusCode())->toBe(200, $url . ' did not render');
        expect(str_contains((string) $response->getContent(), '{{--'))
            ->toBeFalse($url . ' serves a Blade comment to the visitor as page text');
    }
});

it('sizes h1 and h2 identically wherever the tag was retagged', function () {
    /*
     * The only reason the missing <h1>s were left for a deliberate package: the
     * heading sizes come from tag-name selectors, so promoting a heading to
     * <h1> without updating the CSS drops it to the browser's 2em default. Each
     * selector below has to match both tags or the retag is a visual change.
     *
     * Asserted against the stylesheet rather than a screenshot because it is
     * the thing that is easy to revert by accident — a later edit that
     * "tidies" :is(h1,h2) back to h2 silently resizes the home hero and all
     * four collection headings, and nothing else in the suite would notice.
     */
    $css = file_get_contents(resource_path('css/kbb/kbb.css'));

    expect($css)->not->toMatch('/\.(sh|sl)\s+h2\b/', 'a bare .sh/.sl h2 selector is back; the retagged <h1> will not match it');

    foreach (['.kbb-home .sh :is(h1,h2)', '.kbb-home .sl :is(h1,h2)'] as $selector) {
        expect($css)->toContain($selector);
    }
});

describe('CoverImage', function () {
    it('reads a photograph out of every shape the column holds', function () {
        expect(CoverImage::src('https://cdn.example.com/a.jpg'))->toBe('https://cdn.example.com/a.jpg');
        expect(CoverImage::src('/storage/posts/a.jpg'))->toBe('/storage/posts/a.jpg');
        expect(CoverImage::src("#fff url('https://cdn.example.com/b.jpg') center/cover"))
            ->toBe('https://cdn.example.com/b.jpg');
        expect(CoverImage::src('url(https://cdn.example.com/c.jpg)'))->toBe('https://cdn.example.com/c.jpg');
    });

    it('treats decoration as decoration', function () {
        expect(CoverImage::src('linear-gradient(135deg,#FFF0F4,#FCE0E8)'))->toBeNull();
        expect(CoverImage::src('radial-gradient(circle,#fff,#000)'))->toBeNull();
        expect(CoverImage::src('#FCE0E8'))->toBeNull();
        expect(CoverImage::src(''))->toBeNull();
        expect(CoverImage::src(null))->toBeNull();
        // A CSS value this helper does not recognise must never reach an src.
        expect(CoverImage::src('center / cover no-repeat'))->toBeNull();
        /*
         * A bare CSS colour keyword is a single unbroken token, exactly like a
         * relative path is, and it is not a photograph. Answering with it
         * renders <img src="rebeccapurple"> — a broken image in place of the
         * placeholder the card used to draw.
         */
        expect(CoverImage::src('rebeccapurple'))->toBeNull();
        expect(CoverImage::src('transparent'))->toBeNull();
        // A relative path still resolves, because it looks like a file.
        expect(CoverImage::src('posts/hero.jpg'))->toBe('posts/hero.jpg');
    });

    it('keeps the background every card had before', function () {
        $default = 'linear-gradient(135deg,#FFF0F4,#FCE0E8)';

        expect(CoverImage::background(null))->toBe($default);
        expect(CoverImage::background(''))->toBe($default);
        // Unrecognised values kept their default gradient before, and still do.
        expect(CoverImage::background('rebeccapurple'))->toBe($default);
        // Gradients and colours are still painted as themselves.
        expect(CoverImage::background($default))->toBe($default);
        expect(CoverImage::background('#FCE0E8'))->toBe('#FCE0E8');
        // A photograph gets a plain surface; the <img> covers it.
        expect(CoverImage::background('https://cdn.example.com/a.jpg'))->toBe('#fff');
    });
});
