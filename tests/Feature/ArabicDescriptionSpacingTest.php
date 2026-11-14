<?php

declare(strict_types=1);

use App\Support\BlockStructure;

/*
 * The owner, 14 Nov 2026, with screenshots of /ar/product/medicube-vanilla-deodrant-and-body-mist-duo/:
 * "in arabic version, the spacing and line breaking spacing has issues ... fix this permanently".
 *
 * THE DEFECT ON THE SHOP: the English description has a one-line blank (`<div class="kbb-bl"></div>`,
 * stored as `<div><br></div>` / `<p>&nbsp;</p>`) after each list and between its two one-item lists;
 * the Arabic -- hand-translated from the 16 October English, before the English was re-spaced --
 * had ONE two-item list and no blank anywhere, so "طريقة الاستخدام :" sat right under the bullets and
 * "الخطوة 2" right under step 1's list.
 *
 * 2.60.665 put a blank after every Arabic list at render time (RichText::spaceAfterLists()). This lane
 * replaces it with App\Support\BlockStructure, which lines the Arabic up with ITS English: the blank
 * lines go exactly where the English has them (none where it has none), a merged list is split, extra
 * blank lines go, and no word changes (checked). The fixtures are the live rendered tab bodies.
 *
 * MUTATIONS: make align() return $same at the top -> the medicube cases are red. Drop the words() guard
 * and the "never changes a word" case still holds only because no edit carries words; drop the
 * blankShape() guard and the "cannot be lined up" case is red.
 */

function ardsFixture(string $slug): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/arabic-spacing/'.$slug.'.json')), true);
}

it('lines the live medicube duo up with its English: split list, blank lines back, words untouched', function () {
    $f = ardsFixture('medicube-vanilla-deodrant-and-body-mist-duo');

    expect(BlockStructure::shape($f['en']))->toBe('P B L1 B L1 B P B P L3 B P L4')
        ->and(BlockStructure::shape($f['ar']))->toBe('P L2 P B P L5 P L5');

    $a = BlockStructure::align($f['en'], $f['ar']);

    expect($a['ok'])->toBeTrue()->and($a['changed'])->toBeTrue()
        // the English's line shape exactly (its later edit dropped bullets the Arabic still has: L5 stays L5)
        ->and(BlockStructure::shape($a['html']))->toBe('P B L1 B L1 B P B P L5 B P L5')
        ->and(BlockStructure::words($a['html']))->toBe(BlockStructure::words($f['ar']))
        // the heading after the list now has its blank line, the step-2 heading too
        ->and($a['html'])->toContain("</ul>\n<div class=\"kbb-bl\"></div><strong>طريقة الاستخدام")
        ->and($a['html'])->toContain('</ul></div><div class="kbb-bl"></div><div><strong>الخطوة 2')
        // the two-item list is two lists with a blank line between, as in English
        ->and($a['html'])->toContain('</li></ul><div class="kbb-bl"></div><ul>');

    // Idempotent: a lined-up body comes back byte for byte.
    expect(BlockStructure::align($f['en'], $a['html'])['changed'])->toBeFalse()
        ->and(BlockStructure::align($f['en'], $a['html'])['html'])->toBe($a['html']);
});

it('reads every stored blank form as the same blank line', function () {
    // The same English stored four ways; an Arabic missing the blank is repaired against each.
    $ar = '<p><b>العنوان</b></p><ul><li>أ</li></ul><p>نص</p>';
    foreach (['<div><br></div>', '<p>&nbsp;</p>', '<p><br></p>', '<div class="kbb-bl"></div>', '<div>&nbsp;</div>'] as $blank) {
        $en = '<p><b>Title</b></p><ul><li>a</li></ul>'.$blank.'<p>Text</p>';

        expect(BlockStructure::shape($en))->toBe('P L1 B P', $blank)
            ->and(BlockStructure::align($en, $ar)['html'])->toBe('<p><b>العنوان</b></p><ul><li>أ</li></ul><div class="kbb-bl"></div><p>نص</p>', $blank);
    }
});

it('puts no blank where the English has none, and takes an extra one out', function () {
    $en = '<div><b>Key Benefits:</b></div><div><ul><li>a</li><li>b</li></ul></div><div>Made in Korea</div>';
    $ar = '<div><b>الفوائد:</b></div><div><ul><li>أ</li><li>ب</li></ul></div><p>&nbsp;</p><div>صُنع في كوريا</div>';

    $a = BlockStructure::align($en, $ar);

    expect($a['changed'])->toBeTrue()
        ->and($a['html'])->toBe('<div><b>الفوائد:</b></div><div><ul><li>أ</li><li>ب</li></ul></div><div>صُنع في كوريا</div>')
        // 2.60.665's blind rule would have ADDED a blank here that the English page does not have
        ->and(BlockStructure::forDisplay($en, '<div><b>الفوائد:</b></div><div><ul><li>أ</li><li>ب</li></ul></div><div>صُنع في كوريا</div>'))
        ->toBe('<div><b>الفوائد:</b></div><div><ul><li>أ</li><li>ب</li></ul></div><div>صُنع في كوريا</div>');
});

it('uses a <br> for the blank inside a paragraph, never a <div> inside a <p>', function () {
    $en = '<p><strong>How to use</strong><br><br>Apply daily.</p>';
    $ar = '<p><strong>طريقة الاستخدام</strong><br>ضعيه يوميًا.</p>';

    expect(BlockStructure::align($en, $ar)['html'])->toBe('<p><strong>طريقة الاستخدام</strong><br><br>ضعيه يوميًا.</p>');
});

it('leaves a body it cannot line up with certainty exactly as it is, and says why (the live EGF NAD set)', function () {
    // The English gained a whole section after the Arabic was written: 4 lines of text vs 3.
    $f = ardsFixture('medicube-egf-nad-firming-glow-set');
    $a = BlockStructure::align($f['en'], $f['ar']);

    expect($a['ok'])->toBeFalse()->and($a['changed'])->toBeFalse()
        ->and($a['html'])->toBe($f['ar'])
        ->and($a['problem'])->toContain('lines of text');
});

it('never changes a word, whatever the copy', function () {
    foreach (glob(base_path('tests/Fixtures/arabic-spacing/*.json')) as $file) {
        $f = json_decode((string) file_get_contents($file), true);
        $a = BlockStructure::align($f['en'], $f['ar']);

        expect(BlockStructure::words($a['html']))->toBe(BlockStructure::words($f['ar']), basename($file))
            ->and(BlockStructure::words(BlockStructure::forDisplay($f['en'], $f['ar'])))->toBe(BlockStructure::words($f['ar']), basename($file));
    }
});

it('falls back to a blank after a list only when the English is spaced that way itself', function () {
    // Not alignable (the Arabic has an extra paragraph), English style: blank after lists.
    $en = '<p>A</p><ul><li>a</li></ul><div class="kbb-bl"></div><p>B</p>';
    $ar = '<p>أ</p><p>إضافة</p><ul><li>أ</li></ul><p>ب</p>';
    expect(BlockStructure::forDisplay($en, $ar))->toBe('<p>أ</p><p>إضافة</p><ul><li>أ</li></ul><div class="kbb-bl"></div><p>ب</p>');

    // An English with no blank after its list: the translation is left alone.
    $plain = '<p>A</p><ul><li>a</li></ul><p>B</p><p>C</p><p>D</p>';
    expect(BlockStructure::forDisplay($plain, $ar))->toBe($ar);
});

it('takes the fast path only when the two bodies really draw the same lines', function () {
    // key() is how align() skips the line reader for the ~98% of bodies that already match. A first cut
    // collapsed every tag to "<t>" and called the live k-beauty dark-spots bundle (English: blank lines
    // around four pictures; Arabic: the pictures inside one-line blanks) "the same" -- the T pass also
    // matched the tag names. MUTATION: make key() return a constant -> this case and six others are red.
    foreach (glob(base_path('tests/Fixtures/arabic-spacing/*.json')) as $file) {
        $f = json_decode((string) file_get_contents($file), true);

        if (BlockStructure::key($f['en']) === BlockStructure::key($f['ar'])) {
            expect(BlockStructure::shape($f['en']))->toBe(BlockStructure::shape($f['ar']), basename($file));
        }
    }

    $k = ardsFixture('k-beauty-dark-spots-care-bundle');
    expect(BlockStructure::key($k['en']))->not->toBe(BlockStructure::key($k['ar']))
        ->and(BlockStructure::key('<p>A</p><p>&nbsp;</p><ul><li>b</li></ul>'))->toBe(BlockStructure::key("<p>أ</p><p>\u{00A0}</p><ul><li>ب</li></ul>"))
        ->and(BlockStructure::key('<p>A</p><p>&nbsp;</p>'))->not->toBe(BlockStructure::key('<p>A</p><p></p>'));
});
