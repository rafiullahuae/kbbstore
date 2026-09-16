<?php

declare(strict_types=1);

use App\Support\RichText;

/**
 * The product description boxes are meant to take pasted markup and keep it.
 *
 * The owner asked for that explicitly — a description copied from a supplier
 * sheet or a previous shop used to arrive as one grey slab, because the editor
 * forced plain text on paste and the sanitiser unwrapped the structural tags
 * that survived.
 *
 * These pin both halves: the tags that must now survive, and the ones that
 * must still not, whatever anybody pastes. The second half is the important
 * one — the widening is only defensible while the line below holds.
 */
it('keeps the structural markup a pasted description is made of', function () {
    $html = '<div class="lede"><p>A gentle toner.</p></div>'
        . '<figure><img src="/uploads/a.png" alt="Bottle" class="shot">'
        . '<figcaption>The 250ml bottle</figcaption></figure>'
        . '<dl><dt>Size</dt><dd>250ml</dd></dl>'
        . '<p><span class="hl">Niacinamide</span> and <abbr title="Hyaluronic acid">HA</abbr>.</p>'
        . '<pre><code>pH 5.5</code></pre>'
        . '<table><caption>Per use</caption><tr><td>2 drops</td></tr></table>';

    $clean = RichText::clean($html);

    /*
     * str_contains, not expect()->toContain($needle, $message).
     * Pest's toContain is VARIADIC: a second argument is read as another
     * NEEDLE, not as a failure message, so the "message" gets searched for too
     * and the assertion fails against output that is perfectly correct. This
     * repo has walked into that once already — ProductMobileLayoutTest's header
     * warns about it, and I did it anyway.
     */
    foreach (['<div', '<figure', '<figcaption', '<dl', '<dt', '<dd', '<span',
              '<abbr', '<pre', '<code', '<caption', 'class="lede"', 'class="hl"',
              'title="Hyaluronic acid"'] as $needle) {
        expect(str_contains($clean, $needle))
            ->toBeTrue("{$needle} did not survive: {$clean}");
    }
});

it('still drops everything that can act, however it is dressed up', function () {
    /*
     * Each of these is a way markup stops being content and starts being
     * behaviour. The widening added inert tags only; nothing here may pass.
     */
    $cases = [
        '<script>alert(1)</script>'                        => 'alert(1)',
        '<iframe src="https://evil.test"></iframe>'         => 'iframe',
        '<style>body{display:none}</style>'                 => 'display:none',
        '<div onclick="alert(1)">x</div>'                   => 'onclick',
        '<div onmouseover=alert(1)>x</div>'                 => 'onmouseover',
        '<a href="javascript:alert(1)">x</a>'               => 'javascript:',
        '<a href="java&Tab;script:alert(1)">x</a>'          => 'script:',
        '<a href="&#106;avascript:alert(1)">x</a>'          => 'javascript:',
        '<img src="javascript:alert(1)">'                   => 'javascript:',
        '<form action="/x"><input name="p"></form>'         => '<form',
        '<object data="x.swf"></object>'                    => '<object',
        '<svg><script>alert(1)</script></svg>'              => 'alert(1)',
        '<div style="position:fixed;inset:0;z-index:9999">x</div>' => 'position:fixed',
        '<div id="header">x</div>'                          => 'id="header"',
    ];

    foreach ($cases as $input => $mustNotAppear) {
        // Same reason as above: toContain is variadic, so the message would
        // become a second needle and `not` would then pass for the wrong one.
        expect(str_contains(RichText::clean($input), $mustNotAppear))
            ->toBeFalse("{$input} left {$mustNotAppear} behind");
    }
});

it('refuses style and id on a tag that is otherwise allowed', function () {
    /*
     * `class` is allowed on any surviving tag and these two are not, which is
     * the distinction the allowlist comment argues for: a class names a rule
     * the storefront's own stylesheet either has or does not, while an inline
     * style is a layer the operator did not necessarily intend and an id
     * collides with the theme's own anchors.
     */
    $clean = RichText::clean('<p class="lede" style="color:red" id="x">Hi</p>');

    expect($clean)->toContain('class="lede"')
        ->and($clean)->not->toContain('style=')
        ->and($clean)->not->toContain('id=');
});
