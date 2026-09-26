<?php

/**
 * The preview reaches all four editors, and changes nothing any of them saves —
 * Lane S7.
 *
 * ── WHY EACH OF THESE ASSERTIONS EXISTS ─────────────────────────────────────
 *
 * The preview is an insertion into four screens that already work, three of
 * which belong to nobody this round and all four of which save values that are
 * published to Google. Rule 1 of the project notes is what can go wrong: a
 * back-office reorganisation that silently alters a published title would not be
 * discovered for weeks, because the shop keeps rendering happily and the only
 * symptom is in Google's index.
 *
 * So for each editor this pins two things:
 *
 *   1. THE PREVIEW IS MOUNTED EXACTLY ONCE. Zero is the "built, never wired up"
 *      shape this repository keeps finding. Two binds two `input` listeners to
 *      every box and fires two requests per keystroke — and the second
 *      kbbSeoPreview() call replaces the mount's contents, so the first
 *      instance's snippet is orphaned and its handler goes on redrawing an
 *      element nobody can see.
 *
 *   2. THE SAVE PAYLOAD IS EXACTLY WHAT IT WAS. Each editor's SEO save is a
 *      fixed set of ids read out of the dialog; this lists them, so an insertion
 *      that displaced a field, renamed one, or turned the mount into something
 *      the handler reads, is red here and names the editor.
 *
 * MUTATION ACTUALLY RUN: renaming `#ct-seodesc` to `#ct-seodesc2` in the
 * category editor's preview call leaves this file green (the preview simply
 * stops following that box) and is caught by the selector assertion below, which
 * is why that assertion exists as well as the count.
 */

/** @return string the source of one admin partial */
function s7Partial(string $name): string
{
    $path = resource_path('views/admin/partials/'.$name.'.blade.php');

    expect(is_file($path))->toBeTrue("{$name} is missing");

    return (string) file_get_contents($path);
}

/**
 * Every id the SEO block of one editor posts, read off the editor's own save
 * handler rather than listed twice.
 *
 * @return list<string>
 */
function s7SeoPayloadIds(string $source, string $marker, string $endMarker): array
{
    /*
     * THE FIRST `seo: {` IN THE FILE IS NOT THE SAVE BLOCK. All three editors
     * initialise their model with `seo: {}` or `seo: null` a few hundred lines
     * above the save handler, so taking the first occurrence gave an EMPTY block
     * and the comparison below was three ids against nothing. Found by running
     * it. So the block wanted is the first one that actually carries the noindex
     * key, which is the one the handler posts.
     */
    $offset = 0;
    $block = '';

    while (($start = strpos($source, $marker, $offset)) !== false) {
        $end = strpos($source, $endMarker, $start);

        if ($end === false) {
            break;
        }

        $candidate = substr($source, $start, $end - $start);
        $offset = $start + strlen($marker);

        if (str_contains($candidate, 'noindex')) {
            $block = $candidate;
            break;
        }
    }

    expect($block)->not->toBe('', "could not find a {$marker} block carrying noindex");

    /*
     * THREE SPELLINGS, because the three editors read a box three different
     * ways: `val('ct-seotitle')`, `document.getElementById('bz-seonoindex')` and
     * the article editor's `$('#pj-seo-title')`. Matching only the first two left
     * the article's list EMPTY and the assertion below comparing three ids
     * against nothing — caught by running it, which is the only thing that finds
     * an extractor that extracts nothing.
     */
    preg_match_all(
        "/val\('([a-z0-9\-]+)'\)|getElementById\('([a-z0-9\-]+)'\)|\\\$\('#([a-z0-9\-]+)'\)/i",
        $block,
        $m
    );

    $ids = array_values(array_unique(array_filter(array_merge($m[1], $m[2], $m[3]))));
    sort($ids);

    return $ids;
}

it('mounts the preview exactly once in the category editor, and saves the same five keys', function () {
    $source = s7Partial('category-tree-screen');

    expect(substr_count($source, 'window.kbbSeoPreview({'))->toBe(1);
    expect(substr_count($source, "id=\"ct-seoprev\""))->toBe(1);
    expect(substr_count($source, "mount: '#ct-seoprev'"))->toBe(1);

    // And it follows the boxes it says it follows. A mistyped selector is a
    // preview that silently stops updating — no error, no symptom.
    foreach (["'#ct-seotitle'", "'#ct-seodesc'", "'#ct-name'", "'#ct-slug'", "'#ct-desc'"] as $selector) {
        expect(str_contains($source, $selector))->toBeTrue("the category preview does not read {$selector}");
        // The box it names really exists on this form.
        expect(str_contains($source, 'id="'.trim($selector, "'#").'"'))
            ->toBeTrue('the category editor has no '.$selector.' box');
    }

    expect(s7SeoPayloadIds($source, "seo: {", '},'))->toBe([
        'ct-seocanon', 'ct-seodesc', 'ct-seonoindex', 'ct-seoog', 'ct-seotitle',
    ]);

    // The mount is not a field. If it ever appears in the save block somebody
    // has turned a preview into something the shop stores.
    expect(str_contains($source, "val('ct-seoprev')"))->toBeFalse();
});

it('mounts the preview exactly once in the brand editor, and saves the same five keys', function () {
    $source = s7Partial('brands-editor-screen');

    expect(substr_count($source, 'window.kbbSeoPreview({'))->toBe(1);
    expect(substr_count($source, "id=\"bz-seoprev\""))->toBe(1);
    expect(substr_count($source, "mount: '#bz-seoprev'"))->toBe(1);

    foreach (["'#bz-seotitle'", "'#bz-seodesc'", "'#bz-name'", "'#bz-slug'", "'#bz-desc'"] as $selector) {
        expect(str_contains($source, $selector))->toBeTrue("the brand preview does not read {$selector}");
        expect(str_contains($source, 'id="'.trim($selector, "'#").'"'))
            ->toBeTrue('the brand editor has no '.$selector.' box');
    }

    expect(s7SeoPayloadIds($source, "seo: {", '},'))->toBe([
        'bz-seocanon', 'bz-seodesc', 'bz-seonoindex', 'bz-seoog', 'bz-seotitle',
    ]);

    expect(str_contains($source, "val('bz-seoprev')"))->toBeFalse();
});

it('mounts the preview exactly once in the article editor, and saves the same three keys', function () {
    $source = s7Partial('post-editor-screen');

    expect(substr_count($source, 'window.kbbSeoPreview({'))->toBe(1);
    expect(substr_count($source, 'id="pj-seo-prev"'))->toBe(1);
    expect(substr_count($source, "mount: '#pj-seo-prev'"))->toBe(1);

    /*
     * `#pj-slug` is drawn ONLY WHILE CREATING — the address is set once and the
     * box is absent afterwards. That is a supported state, not a gap: the
     * preview's own value() answers '' for a selector that matches nothing, and
     * an existing article's path comes off the stored row anyway. It is asserted
     * rather than assumed because a preview that threw on a missing box would
     * take the whole editor down with it.
     */
    foreach (["'#pj-seo-title'", "'#pj-seo-desc'", "'#pj-title'", "'#pj-slug'", "'#pj-excerpt'"] as $selector) {
        expect(str_contains($source, $selector))->toBeTrue("the article preview does not read {$selector}");
    }

    expect(s7SeoPayloadIds($source, 'seo: {', '},'))->toBe([
        'pj-seo-desc', 'pj-seo-noindex', 'pj-seo-title',
    ]);
});

it('mounts the preview exactly once on the SEO settings tab, and adds no control', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "mount: '#seo_home_prev'"))->toBe(1);
    expect(substr_count($app, "'<div id=\"seo_home_prev\"></div>'+"))->toBe(1);

    /*
     * THE ORDER IS LOAD-BEARING and is the one thing here that was got wrong
     * first. The two textareas on this tab are filled by PROPERTY
     * (`document.getElementById('seo_home_d').value = …`) rather than
     * interpolated into the markup, and setting `.value` from script fires no
     * `input` event. So a preview wired BEFORE those three lines draws the empty
     * boxes and never repaints — it would show "no description is published for
     * this page at all" on a shop that has one.
     */
    $fill = strpos($app, "document.getElementById('seo_home_d').value");
    $wire = strpos($app, "mount: '#seo_home_prev'");

    expect($fill)->not->toBeFalse();
    expect($wire)->not->toBeFalse();
    expect($wire)->toBeGreaterThan($fill, 'the homepage preview is wired before the textareas are filled, so it draws them empty');
});

it('guards the preview call on every screen, so a package without the partial still opens', function () {
    /*
     * FOUR SCREENS, FOUR GUARDS. The preview lives in its own partial and every
     * one of these files is included from the same bundle — but the packages that
     * carry them are built per change, and a package that ships one of these
     * editors without the partial would otherwise throw on `window.kbbSeoPreview`
     * the moment the dialog opened. The category editor already guards
     * window.kbbPickMedia for exactly this reason; this follows it.
     *
     * A dead preview is a missing picture. An unguarded call is a dead editor.
     */
    foreach ([
        'category-tree-screen',
        'brands-editor-screen',
        'post-editor-screen',
    ] as $name) {
        expect(substr_count(s7Partial($name), "typeof window.kbbSeoPreview === 'function'"))
            ->toBe(1, "{$name} calls the preview without guarding that it exists");
    }

    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "typeof window.kbbSeoPreview === 'function'"))->toBe(1);
});
