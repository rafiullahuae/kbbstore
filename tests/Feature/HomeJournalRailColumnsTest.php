<?php

declare(strict_types=1);

/**
 * THE JOURNAL RAIL READS COLUMNS THE `posts` TABLE ACTUALLY HAS.
 *
 * THE DEFECT THIS PINS — the same one, three more times.
 *
 * `posts` has, in full: slug, title, excerpt, body, cover, tag, author,
 * status, seo, published_at. The home page's journal rail asked it for
 * `category`, `content` and `read_minutes`, none of which exist. Eloquent
 * answers null for a missing attribute rather than failing, so nothing broke
 * and nothing worked:
 *
 *   - `@if ($post->category)` was never true, so the category chip has never
 *     been drawn on a real article — on a rail whose whole design has one.
 *   - `$post->excerpt ?: $post->content` fell through to null for an article
 *     with no excerpt, printing an empty paragraph instead of the opening of
 *     the piece.
 *   - `$post->read_minutes ?? 5` was always null, so EVERY article on the home
 *     page claimed "5 min read" — the same number under a 400-word note and a
 *     3,000-word guide.
 *
 * That last one is the reason this is worth a test rather than a shrug. It is
 * not a missing feature, it is a printed claim about the article that is
 * independent of the article. The rail has been quietly telling every visitor
 * the same wrong thing.
 *
 * It is also EXACTLY the `$post->image` defect already fixed in this same
 * block, whose comment reads: "`cover`, not `image`. There is no posts.image
 * column … and Eloquent returns null for a missing attribute instead of
 * failing, so this rail has been drawing the gradient placeholder for every
 * article no matter what photograph the post carried." Same block, same table,
 * same class of mistake, three columns that were not checked when `image` was.
 *
 * THE DEMO OBJECTS MOVE WITH IT. DemoContent::posts() invented its own field
 * names — `category`, `content`, `image` — and DemoContent::fill() concatenates
 * those objects onto a collection of REAL Post models, so one Blade expression
 * has to serve both. They now use the real column names, which is what makes
 * the rail's markup a single expression rather than a pair of fallbacks.
 */

use App\Models\Post;
use App\Services\SettingsService;
use App\Support\ReadingTime;

beforeEach(function () {
    // Demo content substitutes for empty sections; these cases are about real
    // rows, so it is switched off except where a test says otherwise.
    app(SettingsService::class)->set('demo_content', false);
});

function journalPost(array $overrides = []): Post
{
    return Post::create(array_merge([
        'slug' => 'journal-' . uniqid(),
        'title' => 'A beginner’s guide to Korean skincare',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ], $overrides));
}

it('draws the category chip from the column that holds it', function () {
    journalPost(['tag' => 'Routines', 'excerpt' => 'Where to start.']);

    $html = $this->get('/')->assertOk()->getContent();

    // An ELEMENT, not the bare word: the page inlines its own CSS, and a
    // class-name search of the document matches the stylesheet too.
    expect((bool) preg_match('/<span[^>]*class="[^"]*\bchip\b[^"]*"[^>]*>\s*Routines\s*<\/span>/', $html))
        ->toBeTrue('the journal rail did not draw the category chip for a post carrying a tag');
});

it('draws no chip for an article that carries no tag', function () {
    journalPost(['excerpt' => 'No tag on this one.']);

    $html = $this->get('/')->assertOk()->getContent();

    expect((bool) preg_match('/<span[^>]*class="[^"]*\bchip\b[^"]*"[^>]*>\s*\S/', $html))
        ->toBeFalse('an empty chip was drawn for an article with no tag');
});

it('falls back to the body when an article has no excerpt', function () {
    journalPost([
        'excerpt' => null,
        'body' => '<p>Korean skincare is renowned for its innovative formulations.</p>',
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    expect(str_contains($html, 'Korean skincare is renowned'))
        ->toBeTrue('the rail printed nothing for an article whose text lives in `body`');
});

it('does not tell every article it takes five minutes to read', function () {
    /*
     * The defect at its plainest: a short note and a long guide, on the same
     * rail, on the same page. They cannot both be "5 min read".
     */
    journalPost([
        'slug' => 'short-note',
        'title' => 'A short note',
        'excerpt' => 'Brief.',
        'body' => '<p>' . str_repeat('word ', 120) . '</p>',
    ]);
    journalPost([
        'slug' => 'long-guide',
        'title' => 'The long guide',
        'excerpt' => 'Thorough.',
        'body' => '<p>' . str_repeat('word ', 3000) . '</p>',
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('/([0-9]+) min read/', $html, $m);

    expect($m[1])->toHaveCount(2);
    expect(array_unique($m[1]))->toHaveCount(
        2,
        'a 120-word note and a 3,000-word guide both reported ' . ($m[1][0] ?? '?') . ' min read'
    );

    // And the numbers are the honest ones, not merely different from each
    // other: 120 words is 1 minute at 200wpm, 3,000 words is 15.
    sort($m[1]);
    expect($m[1])->toBe(['1', '15']);
});

it('computes reading time from the words in the body', function () {
    // 200 words a minute, rounded up, never zero — the helper, directly.
    expect(ReadingTime::minutes(null))->toBe(1);
    expect(ReadingTime::minutes(''))->toBe(1);
    expect(ReadingTime::minutes('<p>' . str_repeat('word ', 200) . '</p>'))->toBe(1);
    expect(ReadingTime::minutes('<p>' . str_repeat('word ', 201) . '</p>'))->toBe(2);
    expect(ReadingTime::minutes('<p>' . str_repeat('word ', 3000) . '</p>'))->toBe(15);

    // Markup is not words. This is the whole reason it is not str_word_count().
    expect(ReadingTime::minutes('<div class="x"><p>' . str_repeat('word ', 200) . '</p></div>'))->toBe(1);
});

it('reports a post its own reading time', function () {
    $post = journalPost(['body' => '<p>' . str_repeat('word ', 600) . '</p>']);

    expect($post->readMinutes())->toBe(3);
});

it('still renders the rail when demo articles stand in for real ones', function () {
    /*
     * DemoContent::fill() concatenates its objects onto real Post models, so
     * the rail's markup has to serve both. With no real posts at all the whole
     * rail is demo — and it must not warn on a missing property or print the
     * same "5 min read" three times over.
     */
    app(SettingsService::class)->set('demo_content', true);

    expect(Post::count())->toBe(0);

    $html = $this->get('/')->assertOk()->getContent();

    expect((bool) preg_match('/<span[^>]*class="[^"]*\bchip\b[^"]*"[^>]*>\s*Routines\s*<\/span>/', $html))
        ->toBeTrue('the demo journal rail lost its category chip');

    preg_match_all('/([0-9]+) min read/', $html, $m);

    expect(count($m[1]))->toBeGreaterThan(1);
    expect(array_unique($m[1]))->not->toHaveCount(1, 'every demo article claimed the same reading time');
});
