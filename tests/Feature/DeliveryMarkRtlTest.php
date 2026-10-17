<?php

declare(strict_types=1);

use App\Support\TrustShareIcons;

/**
 * The product page's FAST DELIVERY mark on /ar.
 *
 * 16 October 2026: in the yellow delivery box, "FAST" and "DELIVERY" were
 * printed on top of the truck and the arrow pointed right, against the way
 * Arabic reads. 2.60.529 mirrored the SVG with CSS and turned each word back
 * with transform-box:fill-box.
 *
 * 17 October 2026, what that looked like on the owner's iPhone: the truck and
 * the arrow, mirrored, and NO words at all -- "the word FAST is missing in
 * arabic version in the delivery box beside the vehicle". So Arabic now gets
 * its own SVG, TRUCK_RTL: the shapes mirrored by an SVG transform ATTRIBUTE,
 * the two words written at their mirrored places with no transform, and no
 * CSS transform anywhere on the mark.
 *
 * MUTATION: put the CSS scaleX rules back, or print TRUCK on /ar, or wrap a
 * <text> inside the mirrored group -- each is an assertion below.
 */
it('draws Arabic its own ready-mirrored mark, with the words outside any transform, and no CSS flip', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-pdp-trust.css'));

    expect($css)->not->toContain('.pts-truck{direction:ltr;transform:scaleX(-1)}')
        ->and($css)->not->toContain('transform-box:fill-box')
        ->and(preg_match('/pts-truck[^{]*\{[^}]*transform/', $css))->toBe(0);

    $svg = TrustShareIcons::TRUCK_RTL;
    $group = substr($svg, strpos($svg, '<g transform="matrix(-1 0 0 1 124 0)">'), strrpos($svg, '</g>') - strpos($svg, '<g transform="matrix(-1 0 0 1 124 0)">'));

    expect($svg)->toContain('<g transform="matrix(-1 0 0 1 124 0)">')
        ->and($group)->not->toContain('<text')                       // no word is mirrored
        ->and(substr_count($svg, 'direction="ltr"'))->toBe(3)         // svg + both words
        ->and($svg)->toContain('>FAST</text>')->toContain('>DELIVERY</text>')
        // The words sit where TRUCK's sit, reflected (x' = 124 - x - textLength).
        ->and($svg)->toContain('<text x="18" y="19"')->toContain('<text x="2" y="32"');

    // English keeps its own mark, byte for byte.
    expect(TrustShareIcons::TRUCK)->not->toContain('transform')->and(TrustShareIcons::TRUCK)->toContain('<text x="66" y="19"');
});

it('prints TRUCK_RTL only on a right-to-left page', function () {
    $view = (string) file_get_contents(resource_path('views/partials/product/delivery-box.blade.php'));

    expect($view)->toContain('\App\Support\Locale::isRtl() ? \App\Support\TrustShareIcons::TRUCK_RTL : \App\Support\TrustShareIcons::TRUCK');
});

it('closes the authenticity panel with a grey disc and a white cross', function () {
    // The owner, 17 October 2026: "make the red cross icon and background grey
    // bg with white cross icon and apply this on english and arabic version both".
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-pdp-trust.css'));

    expect($css)->toContain('border-radius:50%;border:0;background:#8C828A;color:#fff;cursor:pointer;')
        ->toContain('.pts-auth-x:hover{background:#6E656C}')
        ->not->toContain('#E5484D');
});
