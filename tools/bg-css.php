<?php
/*
 * Dump the exact gradient each treatment paints, per moment, as JSON.
 *                                                                    (Lane BG)
 *   php tools/bg-css.php > docs/bg-shots/gradients.json
 *
 * The contact sheet draws a strip of these beside the page shots, because the
 * page shots cannot quite answer the question the owner is asking. On a real
 * page most of the pixels are cards, photographs and type; the wash is the
 * margin. A strip of the wash ITSELF at 0%, 33% and 66% of the cycle is the one
 * picture that shows what "colour changing time to time" actually buys.
 *
 * It is not a mock-up: PageWash::css() is the only producer of these strings,
 * and this reads them out of it by reflection rather than restating them. A
 * gradient that changed in the service and not here is not expressible.
 *
 * No Laravel boot: everything it touches is static and container-free.
 */
require __DIR__.'/../vendor/autoload.php';

use App\Services\PageWash;

$gradient = (new ReflectionClass(PageWash::class))->getMethod('gradient');
$gradient->setAccessible(true);

$out = [];

foreach (PageWash::TREATMENTS as $key => $t) {
    $values = $t + ['c1' => '#FFF7EE', 'c2' => '#FDECF1', 'c3' => '#F1EAFA'];
    $moments = PageWash::moments($values);

    /*
     * THE ORDER IS THE ORDER THE EYE SEES, NOT THE ORDER OF THE ARRAY.
     * css() paints moments[0] on body::before (visible at 0%), moments[1] on
     * body::after (visible at 33%) and moments[2] on html::before (the floor,
     * which is what shows at 66% when both of the others are transparent).
     */
    $out[$key] = [
        'name' => $t['name'],
        'cycle' => $t['cycle'],
        'floor' => PageWash::floorColour($values),
        'contrast' => PageWash::contrastReport($values),
        'frames' => [
            0 => $gradient->invoke(null, $moments[0], 0),
            33 => $gradient->invoke(null, $moments[1], 1),
            66 => $gradient->invoke(null, $moments[2], 2),
        ],
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
