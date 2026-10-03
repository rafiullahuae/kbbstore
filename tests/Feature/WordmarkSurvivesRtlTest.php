<?php

declare(strict_types=1);

use Tests\Support\ArabicShop;

/**
 * THE SHOP'S OWN NAME RENDERED BACKWARDS ON EVERY ARABIC PHONE. (Lane AR)
 *
 * ── WHAT IT LOOKED LIKE ────────────────────────────────────────────────────
 *
 * With the mirrored layout on, at 390px, the header wordmark read
 *
 *      BlissK-Beauty
 *
 * instead of K-BeautyBliss. Photographed:
 * docs/lane-ar-shots/approved-rtl-product-390.jpg against
 * docs/lane-ar-shots/en-product-390.jpg. At 1280 it was correct, which is what
 * made it survive: every desktop check of the mirrored layout passed.
 *
 * ── WHY, AND WHY THE RTL AUDIT COULD NOT SEE IT ────────────────────────────
 *
 * The markup is one element with two runs in it -- `K-Beauty` as a bare text
 * node and `Bliss` in a <span> that colours it pink. As inline text that is one
 * bidi run and the Unicode algorithm keeps it in order whatever the paragraph
 * direction is, which is why the desktop header was always fine.
 *
 * Then a touch-target pass gave every tap target a 44px minimum:
 *
 *     @media (max-width: N) { .logo{min-height:44px;display:inline-flex;…} }
 *
 * `display:inline-flex` turns the text node into an ANONYMOUS FLEX ITEM and the
 * <span> into a second one. Flex lays items along the inline axis IN THE
 * DOCUMENT'S DIRECTION, and it is not bidi -- `dir="rtl"` reverses the pair
 * outright. Two items, swapped.
 *
 * docs/rtl-audit.md's instrument is a CSS DECLARATION READER, and there is no
 * physical declaration here to find: `display:inline-flex` is direction-neutral
 * right up until a document flips. That is the class of RTL defect a property
 * rename cannot reach, and it is why §11 of that audit exists -- this one was
 * simply below the resolution of the pictures it took, because nobody
 * photographed a phone-width mirrored HEADER with the wordmark legible.
 *
 * ── THE FIX, AND WHY <bdi> AND NOT dir="ltr" ───────────────────────────────
 *
 * <bdi> wraps the whole wordmark, so the flex container has ONE item and there
 * is nothing left to reorder. It is also the element whose entire purpose is
 * this: it isolates its contents' directionality from the paragraph around it.
 *
 * `dir="ltr"` on the <a> would have worked for the seven hard-coded copies and
 * been WRONG for the header's, whose two halves are the settings
 * `header_logo_text` and `header_logo_accent` -- the owner may type Arabic into
 * them, and forcing LTR onto his Arabic would break the thing this fixes. <bdi>
 * is correct either way: Latin stays Latin-ordered, Arabic reads Arabic.
 *
 * NOT a <span>: `.logo span` is a DESCENDANT selector in six stylesheets and
 * sets the accent colour, so a <span> wrapper would have matched it and turned
 * the whole wordmark pink. Nothing styles `.logo bdi`.
 *
 * MUTATION: take the <bdi> off any one of the eight and this is red, naming the
 * file. Ran it on partials/header.blade.php and store/checkout.blade.php.
 */
it('wraps every wordmark so the document direction cannot reorder it', function () {
    /*
     * All eight, by reading the templates: the header, the slim header, the
     * drawer, the footer, the checkout, the order-received page and the two
     * standalone blog layouts. Seven hard-code the name and one reads it from
     * settings; all eight carry `.logo`, so all eight get `display:inline-flex`
     * from the same touch-target rule and all eight had the same defect.
     */
    $files = [
        'partials/header.blade.php',
        'partials/header-slim.blade.php',
        'partials/drawers.blade.php',
        // (Lane HB) The previous footer, kept as the "Previous" design. The
        // new one (footer-bliss) wraps its `.kft-logo` wordmark in <bdi> too.
        'partials/footer-classic.blade.php',
        'store/checkout.blade.php',
        'store/checkout-success.blade.php',
        'store/blog.blade.php',
        'store/post.blade.php',
    ];

    foreach ($files as $file) {
        $html = (string) file_get_contents(resource_path('views/'.$file));

        // Strip Blade comments first: this repo has been caught before by a
        // source scan reading an explanation as though it were markup.
        $html = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $html);

        expect((bool) preg_match('/class="logo"/', $html))
            ->toBeTrue($file.' no longer carries a .logo wordmark -- update this list');

        /*
         * The accent <span> must sit INSIDE the <bdi>. Outside it there are two
         * flex items again and the bug is back with the isolation looking like
         * it is there.
         */
        expect((bool) preg_match('/<bdi>[^<]*<span>[^<]*<\/span><\/bdi>/', $html))
            ->toBeTrue($file.': the wordmark is not one <bdi> containing its accent span');
    }
});

it('leaves no wordmark as two bare runs in a flex row', function () {
    /*
     * The shape of the defect, forbidden by name. `.logo` followed by a text
     * node and then a <span> with no isolation between them is exactly what
     * flex reorders, and it is what all eight looked like.
     */
    $bad = [];

    foreach (glob(resource_path('views/**/*.blade.php')) as $path) {
        $html = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

        if (preg_match('/class="logo"[^>]*>(?!<bdi>)\s*\S[^<]*<span>/', $html) === 1) {
            $bad[] = str_replace(resource_path('views').'/', '', $path);
        }
    }

    expect($bad)->toBe([], 'wordmarks that flex can still reorder: '.implode(', ', $bad));
});

it('renders the wordmark in one piece on an arabic page', function () {
    /*
     * The markup assertions above are structural. This one drives the shop:
     * on /ar, the header must still contain the two halves in their written
     * order, inside one isolating element.
     *
     * It cannot assert the PIXELS -- that is what
     * docs/lane-ar-shots/approved-rtl-product-390.jpg is for, and CLAUDE.md
     * forbids measuring layout from script anyway. What it can assert is that
     * the isolation reaches the rendered page rather than only the template,
     * which is the half a Blade-source scan cannot prove.
     */
    ArabicShop::on();

    $html = $this->get('/ar/')->assertOk()->getContent();

    expect((bool) preg_match('/<a class="logo"[^>]*><bdi>/', $html))
        ->toBeTrue('the rendered Arabic header has no <bdi> around its wordmark');
});
