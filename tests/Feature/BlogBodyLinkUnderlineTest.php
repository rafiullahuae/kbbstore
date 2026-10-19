<?php

use App\Models\Post;

/*
 * Links in a blog post's body are underlined -- Lane BIM, 19 October 2026.
 *
 * WHAT THE OWNER SAW. "the links in the blogs should show as underlined links
 * on the front-end." On /blog/<post>/ (and /ar/) a link in the article's words
 * looked exactly like the words around it: `.kbb-journal a{color:inherit}` and
 * nothing gave it back its underline, so a reader could not tell it was a link.
 * Measured in Chromium before the fix: every body text link computed
 * text-decoration-line `none`; after: `underline`, 1px, offset 3.2px, the same
 * ink colour, pink-deep and 2px on hover -- while the header, menu,
 * breadcrumb, product card, picture link, gallery link, related card, back link
 * and footer all stayed `none` (tools/bim-shots.cjs).
 *
 * MUTATION: delete the `.kbb-journal .abody a:not([class]){text-decoration:
 * underline...}` line from store/post.blade.php and the first test is red.
 */

function bimPostCss(string $html): string
{
    preg_match('#<style id="kbb-journal-css">(.*?)</style>#s', $html, $m);

    return (string) ($m[1] ?? '');
}

it('underlines the article body\'s own text links, and only those', function () {
    $post = Post::query()->create([
        'slug' => 'bim-underline', 'title' => 'Underline', 'excerpt' => 'x', 'tag' => 'Routine',
        'author' => 'K-Beauty Bliss', 'status' => 'published', 'published_at' => now()->subDay(),
        'body' => '<p>Read <a href="/shop/">our toners</a>.</p><p><a href="/shop/"><img src="/x.png" alt="" width="4" height="3"></a></p>',
    ]);

    $html = (string) $this->get('/blog/' . $post->slug . '/')->assertOk()->getContent();
    $css = bimPostCss($html);

    expect($html)->toContain('<a href="/shop/">our toners</a>')
        // The rule exists, is scoped to the article body, and leaves classed
        // links (product cards, buttons, embeds) alone.
        ->and($css)->toContain('.kbb-journal .abody a:not([class]){text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:.2em}')
        ->and($css)->toContain('.kbb-journal .abody a:not([class]):hover{color:var(--pink-deep);text-decoration-thickness:2px}')
        // A link that wraps a picture, or sits in a gallery, stays bare.
        ->and($css)->toContain('.kbb-journal .abody a:not([class]):has(img){text-decoration:none}')
        ->and($css)->toContain('.kbb-journal .abody .kbb-gal a:not([class]){text-decoration:none}')
        // The page's other links keep what they had.
        ->and($css)->toContain('.kbb-journal a{color:inherit}')
        ->and($css)->toContain('.kbb-journal .crumb a{text-decoration:none}')
        ->and($css)->toContain('.kbb-journal .backrow a{font-size:13px;font-weight:600;color:var(--pink-deep);text-decoration:none}');

    // Every rule in the sheet that underlines anything is scoped to .abody.
    preg_match_all('#([^{}]+)\{[^}]*text-decoration:underline[^}]*\}#', (string) preg_replace('#/\*.*?\*/#s', '', $css), $rules);
    expect($rules[1])->not->toBeEmpty();
    foreach ($rules[1] as $selector) {
        foreach (explode(',', $selector) as $one) {
            expect(trim($one))->toStartWith('.kbb-journal .abody ');
        }
    }
});

it('rests on every component link in a body carrying a class of its own', function () {
    // The underline is for links WITHOUT a class. A product card, a product
    // grid's "view all", an Instagram embed or a review rail printed into a
    // body by a shortcode must never be one, or it would be underlined too.
    foreach (['components/product-card', 'components/product-grid', 'igembed/section', 'ugc/rail'] as $view) {
        $src = (string) file_get_contents(resource_path('views/' . $view . '.blade.php'));
        preg_match_all('#<a\s[^>]*>#', $src, $m);
        foreach ($m[0] as $open) {
            expect($open)->toMatch('#\sclass="#', "{$view}: {$open}");
        }
    }
});

it('adds no request and no new stylesheet to the post page', function () {
    $src = (string) file_get_contents(resource_path('views/store/post.blade.php'));

    expect(substr_count($src, '<style'))->toBe(1)
        ->and($src)->not->toContain('<link rel="stylesheet"')
        ->and($src)->not->toContain('<script');
});
