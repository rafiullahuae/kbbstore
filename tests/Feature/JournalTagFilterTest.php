<?php

declare(strict_types=1);

use App\Models\Post;
use Tests\Support\ArabicShop;

/**
 * The Journal's tag filter — Lane FJ.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * setTag() found the active chip by comparing its RENDERED TEXT against the tag
 * value:
 *
 *     c.classList.toggle('on', c.textContent.trim() === t)
 *
 * A chip's text is what the shopper reads; a tag is what a post is filed under.
 * They are the same string only while nothing is translated, so the first
 * translated label kills the active-chip highlight for EVERY chip rather than
 * for the one that was translated — the click still filters, and the row stops
 * saying what it filtered by.
 *
 * 'All' was the same mistake in the other direction: a WORD used as a sentinel,
 * in three places at once (the chip list, the chip that starts active, and the
 * "show everything" branch). Translating it would have turned the All chip into
 * a filter for posts tagged "الكل" and emptied the page.
 *
 * ── AND THE ESCAPING HOLE IN THE SAME LINE ──────────────────────────────────
 *
 * The chips were built by concatenating each tag into an HTML string, escaping
 * only the apostrophe and only for the inner JavaScript literal. Measured in
 * Chromium against the code as it stood: a post tagged
 * `<img src=x onerror=alert(1)>" onmouseover="alert(2)` produced an alert on
 * load, an `onmouseover` attribute the template never wrote, and a chip whose
 * own onclick was a syntax error — so that tag could not be filtered by at all.
 *
 * ── WHY THE INTERESTING HALF NEEDS A REAL BROWSER ───────────────────────────
 *
 * Every claim above is about what a PARSER produced and what a CLICK did. The
 * string cases below are worth having and are not the proof; the browser case
 * is, and it skips rather than lying when Chromium is not present.
 */

/** Node, playwright, Chromium and the probe. */
function fjBrowserPrereqs(): array
{
    $chrome = env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');

    $missing = [];

    if (! env('KBB_BROWSER_TESTS')) {
        $missing[] = 'KBB_BROWSER_TESTS is not set';
    }

    if (! is_file($chrome)) {
        $missing[] = "no Chromium at {$chrome}";
    }

    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        $missing[] = 'node is not on PATH';
    }

    if (! is_file(base_path('tests/browser/fj-journal-tag-filter.mjs'))) {
        $missing[] = 'tests/browser/fj-journal-tag-filter.mjs is missing';
    }

    // The probe imports playwright by name, so it needs this checkout's own
    // node_modules. A fresh worktree has none (CLAUDE.md: assets are built by
    // hand and CI does not build them), and skipping says so instead of
    // reporting a browser failure that is really a missing package.
    if (! is_dir(base_path('node_modules/playwright'))) {
        $missing[] = 'node_modules/playwright is not installed in this checkout';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

/**
 * The tag a hostile — or merely careless — admin could type into a post.
 *
 * Not anonymous input: `posts.tag` is written from the back office. It is still
 * exactly the shape of hole that turns one compromised admin session into a
 * persistent one, and a tag has no business carrying markup in either case.
 */
const FJ_HOSTILE_TAG = '<img src=x onerror=alert(1)>" onmouseover="alert(2)';

/** Three posts, one of them tagged with the above. */
function fjJournalPosts(): void
{
    foreach ([
        ['Routine', 'A morning that works'],
        ['Ingredients', 'What niacinamide does'],
        [FJ_HOSTILE_TAG, 'Filed under something awkward'],
    ] as $i => [$tag, $title]) {
        Post::create([
            'slug' => 'fj-journal-' . $i,
            'title' => $title,
            'excerpt' => 'x',
            'body' => 'x',
            'status' => 'published',
            'tag' => $tag,
            'published_at' => now()->subDays($i + 1),
        ]);
    }
}

/** The Journal as an Arabic shopper is served it. */
function fjArabicJournal(): string
{
    ArabicShop::on();
    ArabicShop::string('store.js.blog_tag_all', 'الكل');

    fjJournalPosts();

    return (string) test()->get('/ar/skincare-guide/')->assertOk()->getContent();
}

it('renders the All chip\'s label in the shopper\'s language', function () {
    $html = fjArabicJournal();

    // The label, server-rendered. This page is a standalone document with no
    // window.KBB_T of its own, so the one string its script needs arrives as a
    // JSON literal — and the key is a real `store.js.*` key, so the Translation
    // console reaches it like every other front-end string.
    // @json() escapes non-ASCII, so the expectation is built the same way
    // rather than typed out — a hand-written \u escape is the kind of literal
    // that goes stale silently.
    expect($html)->toContain('window.KBB_BLOG_ALL_LABEL = ' . json_encode('الكل'));
});

it('never compares a chip\'s rendered text with a tag, and never filters by a word', function () {
    $html = fjArabicJournal();

    // From the sentinel's declaration, which stands above setTag(), to the end
    // of the chip builder below it.
    $script = substr($html, (int) strpos($html, '/* The absence of a tag.'), 3000);

    expect(str_contains($script, 'textContent.trim()==='))
        ->toBeFalse('the active chip is matched on its rendered text again');
    expect(str_contains($script, "==='All'") || str_contains($script, "=== 'All'"))
        ->toBeFalse("'All' is a linguistic string and is being used as a sentinel again");

    // What replaced both: the value lives in data-tag, the sentinel is the
    // ABSENCE of one, and the label is only ever drawn.
    expect($script)->toContain('ALL_TAGS = null');
});

it('puts a tag carrying markup in an attribute and not in the page\'s HTML', function () {
    $html = fjArabicJournal();

    // The card, which was always escaped by Blade, and which is where the tag
    // legitimately appears.
    expect($html)->toContain('data-tag="&lt;img src=x onerror=alert(1)&gt;&quot; onmouseover=&quot;alert(2)"');

    // And the chip builder, which is now markup-free: no onclick carrying a
    // tag, and no template literal interpolating one into HTML.
    // From setTag() onwards, which is BELOW the comment block that quotes the
    // old builder verbatim — searching the whole page would match that quote
    // and fail on its own documentation.
    $script = substr($html, (int) strpos($html, 'function setTag'), 2500);

    expect(str_contains($script, 'onclick="setTag('))
        ->toBeFalse('the chips are being built with an inline onclick again');
    expect(str_contains($script, 'innerHTML'))
        ->toBeFalse('the chips are being built by string concatenation again');
    expect($script)->toContain('createElement')->toContain('textContent');
});

it('highlights the chip the shopper clicked, with Arabic labels, in a real browser', function () {
    ['chrome' => $chrome, 'missing' => $missing] = fjBrowserPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped('Needs real Chromium: ' . implode('; ', $missing) . '.');
    }

    $html = fjArabicJournal();

    // The fixture is only worth measuring if it really produced the chips and
    // the hostile tag. A page that stopped rendering either would otherwise
    // pass everything below for the wrong reason.
    expect($html)->toContain('id="chips"')
        ->toContain('onerror=alert(1)');

    $file = tempnam(sys_get_temp_dir(), 'fj-journal-') . '.html';
    file_put_contents($file, $html);

    try {
        $raw = shell_exec(sprintf(
            'KBB_BROWSER_CHROME=%s KBB_BROWSER_FILE=%s node %s 2>/dev/null',
            escapeshellarg($chrome),
            escapeshellarg($file),
            escapeshellarg(base_path('tests/browser/fj-journal-tag-filter.mjs'))
        ));
    } finally {
        @unlink($file);
    }

    $seen = json_decode((string) $raw, true);

    expect($seen)->toBeArray()
        ->and($seen['ok'] ?? false)->toBeTrue('the browser probe did not run: ' . ($seen['error'] ?? (string) $raw));

    // NOTHING EXECUTED. Against the code as it stood this list held "1".
    expect($seen['dialogs'])->toBe([])
        ->and($seen['errors'])->toBe([]);

    $labels = array_column($seen['initial']['chips'], 'label');
    $tags = array_column($seen['initial']['chips'], 'tag');

    // Four chips: All, and one per tag. The first is the Arabic label over the
    // sentinel; the hostile tag is a chip like any other, as TEXT.
    expect($labels)->toBe(['الكل', 'Routine', 'Ingredients', FJ_HOSTILE_TAG])
        ->and($tags)->toBe([null, 'Routine', 'Ingredients', FJ_HOSTILE_TAG]);

    // Every chip in turn: exactly one is lit, and it is the one clicked.
    foreach ($seen['clicks'] as $i => $state) {
        $lit = array_values(array_keys(array_filter(array_column($state['chips'], 'on'))));

        expect($lit)->toBe([$i], "clicking chip {$i} lit " . json_encode($lit));

        $expected = $i === 0
            ? ['Routine', 'Ingredients', FJ_HOSTILE_TAG]
            : [$tags[$i]];

        expect($state['visible'])->toBe($expected, "chip {$i} showed the wrong posts");
    }
});
