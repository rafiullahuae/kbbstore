<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Compare a decoded payload without asserting the ORDER of its object keys,
 * while still asserting the order of its lists.
 *
 * ── WHY A TEST MAY NOT PIN OBJECT-KEY ORDER ─────────────────────────────────
 *
 * PHP's `===` on arrays requires the same key/value pairs IN THE SAME ORDER, so
 * `expect($decoded)->toBe([...])` pins key order whether the author meant to or
 * not. That is fine on SQLite and false on MySQL, and this store runs MySQL.
 * Two separate mechanisms reorder keys on the way through the real engine, and
 * neither is a defect anybody can fix in the query:
 *
 *   1. A MYSQL `json` COLUMN DOES NOT STORE KEY ORDER. It parses the document
 *      and stores object members sorted by (key length, then bytewise), which is
 *      what makes a member lookup a binary search instead of a scan. SQLite has
 *      no JSON type at all — Illuminate's SQLiteGrammar compiles `json()` to
 *      `text` — so SQLite hands back the bytes it was given. Measured, same
 *      value written to a `json` and a `text` column on MySQL 8.0.46:
 *
 *          written : {"title":"T","desc":"D","og_image":"I","canonical":"C","noindex":false}
 *          json    : {"desc": "D", "title": "T", "noindex": false, "og_image": "I", "canonical": "C"}
 *          text    : {"title":"T","desc":"D","og_image":"I","canonical":"C","noindex":false}
 *
 *      desc(4) · title(5) · noindex(7) · og_image(8) · canonical(9) — length
 *      order exactly. There are 27 `json` columns in this schema, `pages.seo`
 *      and the four other `seo` blobs among them.
 *
 *   2. AN UNORDERED `select` RETURNS ROWS IN THE ENGINE'S ORDER, and a map built
 *      by iterating those rows inherits it. `settings` is `PRIMARY KEY (key)` on
 *      a varchar, so InnoDB's clustered index hands `Setting::map()` its rows in
 *      KEY ALPHABETICAL order, while SQLite walks the implicit rowid and hands
 *      back INSERTION order. Measured on the same seeded database:
 *
 *          mysql : canonical_host, gift_enabled, gift_fee, host_aliases, …
 *          sqlite: seo_default_description, gift_enabled, gift_fee, canonical_host, …
 *
 * JSON objects are unordered by specification, so in both cases the order is not
 * something the shop promises, cannot be observed by any screen that reads the
 * payload as a dictionary, and cannot be asserted without asserting something
 * untrue about production.
 *
 * ── WHAT THIS DELIBERATELY STILL PINS ───────────────────────────────────────
 *
 * LIST ORDER, untouched. A list is display order — the categories down a select,
 * the products in an `itemListElement`, the lines on a basket — and reordering
 * one IS a regression a shopper sees. So `canonical()` sorts the keys of
 * associative arrays and recurses into lists WITHOUT reordering them, which
 * keeps every guarantee SeoBackOfficePayloadTest's header claims for its `===`:
 * a reordered category is still red, a moved default is still red (values are
 * compared), an extra or missing key is still red. The one thing it stops
 * reporting is the position of a key, which is the engine talking rather than
 * the application.
 *
 * It is therefore NOT a way to quiet a payload test. `canonical()` on both sides
 * of a comparison removes exactly one class of difference and leaves every other
 * class exactly as strict as it was.
 *
 * ── MUTATIONS, EACH ONE APPLIED AND RUN, WITH WHAT ACTUALLY WENT RED ────────
 *
 *   1. Compare with plain `toBe()` at the two call sites instead of through
 *      canonical(). RUN on -c phpunit-mysql.xml: red — ContentPageEditorTest's
 *      per-row SEO case and SeoBackOfficePayloadTest. RUN on the default config:
 *      green. That asymmetry is the whole reason this class exists.
 *
 *   2. Comment out the `ksort($out)`. RUN: 3 failed, 1 passed in KeyOrderTest —
 *      cases 1, 3 and 4. Case 3 goes with it because it asserts that the objects
 *      INSIDE a list are still normalised, and case 4 because it asserts the
 *      recursion; only "still reports a changed value" survives, since that one
 *      is about difference rather than order.
 *
 *   3. Sort the list branch as well (`sort()` after the array_map). RUN: 1
 *      failed, 3 passed — case 3 alone, "a list is display order". This is the
 *      mutation worth recording, because sorting lists too would ALSO have made
 *      ContentPageEditorTest and SeoBackOfficePayloadTest green while throwing
 *      away the reordered-category guarantee their fixtures exist for, and NO
 *      payload test would have noticed: a fixture recorded and compared through
 *      the same sort agrees with itself. That is why the list branch is pinned in
 *      KeyOrderTest, where it is the subject rather than a side effect.
 *
 *   4. `return $value;` as canonical()'s first line. RUN: 3 failed, 1 passed —
 *      cases 1, 3 and 4 again, and case 2 green for the same reason as above.
 */
final class KeyOrder
{
    /**
     * The same structure with every associative array's keys sorted, and every
     * list left in the order it arrived.
     */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        /*
         * A list keeps its order and only its MEMBERS are canonicalised, so
         * `[{b,a}, {b,a}]` normalises inside each element without the two
         * elements swapping places.
         */
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonical($item), $value);
        }

        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = self::canonical($item);
        }

        ksort($out);

        return $out;
    }
}
