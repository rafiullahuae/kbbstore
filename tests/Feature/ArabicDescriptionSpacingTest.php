<?php

declare(strict_types=1);

use App\Support\RichText;

/*
 * The owner, 14 Nov 2026, with screenshots of /ar/product/medicube-vanilla-deodrant-and-body-mist-duo/:
 * "in arabic version, the spacing and line breaking spacing has issues". The English copy has a
 * one-line blank (`<div class="kbb-bl"></div>`) after each list; the Arabic translation dropped them,
 * so "طريقة الاستخدام :" sat right under the last bullet and "الخطوة 2" right under step 1's list.
 * RichText::spaceAfterLists() puts the blank back on translated pages (ProductController, tab bodies).
 * MUTATION: return $html unchanged from spaceAfterLists() and the first case is red.
 */

const ARDS_BODY = '<b>ماذا تتضمّن هذه المجموعة: </b>'
    ."\n<ul>\n <li><strong>مزيل العرق: </strong>نص</li>\n <li><strong>بخاخ العطر: </strong>نص</li>\n</ul>\n"
    .'<strong>طريقة الاستخدام : </strong><div class="kbb-bl"></div><div><strong>الخطوة 1: </strong>مزيل العرق</div>'
    .'<div><ul><li><span>أديري المزيل.</span></li></ul></div><div><strong>الخطوة 2: </strong><span>بخاخ العطر</span></div>'
    .'<div><ul><li><span>رجّي البخاخ.</span></li></ul></div>';

it('puts the one-line blank back after a list that a heading follows (the live medicube duo shape)', function () {
    $out = RichText::spaceAfterLists(ARDS_BODY);

    expect($out)->toContain("</ul><div class=\"kbb-bl\"></div>\n<strong>طريقة الاستخدام")
        ->and($out)->toContain('</ul></div><div class="kbb-bl"></div><div><strong>الخطوة 2')
        ->and(substr_count($out, 'kbb-bl') - substr_count(ARDS_BODY, 'kbb-bl'))->toBe(2);
});

it('adds nothing after the last list, nothing where a blank is already there, and nothing a second time', function () {
    $out = RichText::spaceAfterLists(ARDS_BODY);

    // the body ends with a list: no trailing blank line under the copy
    expect($out)->toEndWith('</ul></div>')
        // idempotent: a body that already has its blanks comes back byte for byte
        ->and(RichText::spaceAfterLists($out))->toBe($out)
        ->and(RichText::spaceAfterLists('<p>بلا قائمة</p>'))->toBe('<p>بلا قائمة</p>')
        ->and(RichText::spaceAfterLists('<ul><li>a</li></ul><p class="kbb-bl"></p><p>b</p>'))->toBe('<ul><li>a</li></ul><p class="kbb-bl"></p><p>b</p>');
});

it('only runs on translated pages: the English tab body keeps its own spacing exactly', function () {
    $controller = (string) file_get_contents(app_path('Http/Controllers/Store/ProductController.php'));

    expect($controller)->toContain("if (! \\App\\Support\\Locale::isDefault()) {\n                \$trimmed = \\App\\Support\\RichText::spaceAfterLists(\$trimmed);");
});
