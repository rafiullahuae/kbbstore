<?php

/**
 * A review photo that will not load leaves the card, not a broken frame.
 *
 * THE DEFECT, ON THE LIVE SHOP (2 October 2026): on the Medicube Collagen
 * Night Wrapping Mask page three review cards showed an empty frame with a
 * broken-image icon and "1" over it. Their photos still carried the old site's
 * address (https://kbeautybliss.com/wp-content/uploads/2025/03/
 * medicube-collagen-night-wrapping-mask-75ml-2.webp) because the picture pass
 * had not fetched them, and the old address did not load.
 *
 * resources/js/kbb/reviews.js initBrokenPhotos() removes a photo that fails,
 * fixes the "📷 N" count, and drops the strip -- and the card's place under
 * "With Photos" -- when none are left. Measured in Chromium at 390 and 1280 on
 * a preview: a card whose only photo 404s keeps no frame (data-photos 0); a
 * card with one bad and one good photo keeps the good one and reads "📷 1".
 *
 * Pinned on the source because the behaviour is the browser's: MUTATION, RUN —
 * drop the initBrokenPhotos(section) call from initReviews() and this is red.
 */
it('removes a review photo that fails to load, and keeps the count honest', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/reviews.js'));

    preg_match('/export function initReviews\(\) \{(.*?)\n\}/s', $js, $init);
    expect($init[1] ?? '')->toContain('initBrokenPhotos(section);');

    preg_match('/function initBrokenPhotos\(section\) \{(.*?)\n\}\n/s', $js, $fn);
    $body = $fn[1] ?? '';

    expect($body)->toContain("addEventListener('error'")
        // error does not bubble: without capture the section never hears it.
        ->and($body)->toContain('}, true);')
        ->and($body)->toContain("card.dataset.photos = '0'")
        ->and($body)->toContain('.sr-pc');

    // No layout is measured (CLAUDE.md rule 4).
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'getComputedStyle'] as $api) {
        expect(str_contains($body, $api))->toBeFalse("initBrokenPhotos measures layout with {$api}");
    }
});
