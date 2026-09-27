<?php

declare(strict_types=1);

/*
 * =============================================================================
 * THE ENGINE DECIDES KEY ORDER, SO A TEST MAY NOT
 * =============================================================================
 *
 * Tests\Support\KeyOrder carries the two measured mechanisms in full. This file
 * is the part that can be asserted rather than described, and it is deliberately
 * engine-independent: every case below passes on BOTH configs, because the point
 * of the helper is that the two engines stop disagreeing.
 *
 * Case 3 is the one that earns the file. Sorting a list as well as an object
 * would ALSO have made ContentPageEditorTest and SeoBackOfficePayloadTest green,
 * and it would have thrown away the thing those payload fixtures exist to catch
 * — a reordered category, a reordered product in an itemListElement, a reordered
 * basket line. No payload test would have noticed the loss, because a fixture
 * recorded and compared through the same sort agrees with itself. So the list
 * branch is pinned here, where it is the subject rather than a side effect.
 *
 * MUTATIONS, each applied and run, with the counts as they actually came back:
 *   - Comment out the `ksort($out)` in canonical(). RUN: 3 failed, 1 passed —
 *     cases 1, 3 and 4. Case 3 goes with it because its second half asserts that
 *     objects INSIDE a list are still normalised; case 2 survives because it is
 *     about difference rather than order.
 *   - Sort the `array_is_list()` branch too (`sort()` after the array_map). RUN:
 *     1 failed, 3 passed — case 3 alone. That is the mutation this file is for.
 *   - `return $value;` as canonical()'s first line. RUN: 3 failed, 1 passed —
 *     cases 1, 3 and 4, case 2 green.
 */

use Tests\Support\KeyOrder;

it('makes two objects that differ only in key order compare identical', function () {
    /*
     * The literal shape MySQL's `json` type produces. `pages.seo` is written
     * title/desc/og_image and read back desc/title/og_image, because the engine
     * stores object members by (key length, then bytewise) — desc(4), title(5),
     * og_image(8). ContentPageEditorTest asserted the written order with toBe()
     * and was red on the engine the shop runs.
     */
    $written = ['title' => 'T', 'desc' => 'D', 'og_image' => 'I'];
    $readBack = ['desc' => 'D', 'title' => 'T', 'og_image' => 'I'];

    expect($written)->not->toBe($readBack);
    expect(KeyOrder::canonical($written))->toBe(KeyOrder::canonical($readBack));
});

it('still reports a changed value, a new key and a missing key', function () {
    /*
     * The half that must NOT be lost. If canonical() quieted any of these it
     * would be a way to clear a red payload test rather than a way to drop one
     * untrue assertion from it.
     */
    $base = ['title' => 'T', 'desc' => 'D'];

    expect(KeyOrder::canonical($base))
        ->not->toBe(KeyOrder::canonical(['desc' => 'CHANGED', 'title' => 'T']))
        ->not->toBe(KeyOrder::canonical(['desc' => 'D', 'title' => 'T', 'extra' => 1]))
        ->not->toBe(KeyOrder::canonical(['title' => 'T']));
});

it('leaves a list in the order it arrived, and canonicalises inside it', function () {
    /*
     * A list is display order — the categories down a select, the products in a
     * schema.org itemListElement, the lines on a basket. Reordering one is a
     * regression a shopper sees, so it stays pinned.
     */
    $recorded = [['name' => 'Ampoules'], ['name' => 'Cleansers']];
    $reordered = [['name' => 'Cleansers'], ['name' => 'Ampoules']];

    expect(KeyOrder::canonical($recorded))->not->toBe(KeyOrder::canonical($reordered));

    // …while the objects INSIDE a list are still normalised, without the list
    // members swapping places.
    expect(KeyOrder::canonical([['b' => 2, 'a' => 1]]))->toBe([['a' => 1, 'b' => 2]]);
});

it('recurses, and leaves a scalar alone', function () {
    expect(KeyOrder::canonical(['outer' => ['b' => 2, 'a' => ['d' => 4, 'c' => 3]]]))
        ->toBe(['outer' => ['a' => ['c' => 3, 'd' => 4], 'b' => 2]]);

    expect(KeyOrder::canonical('a string'))->toBe('a string');
    expect(KeyOrder::canonical(null))->toBeNull();
    expect(KeyOrder::canonical(7))->toBe(7);
});
