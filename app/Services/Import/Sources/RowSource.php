<?php

declare(strict_types=1);

namespace App\Services\Import\Sources;

/**
 * Where rows come from.
 *
 * The whole reason this is an interface with one implementation is the second
 * implementation that is not written yet. WooCommerce can be read two ways: a
 * CSV export, which the owner can produce from wp-admin with no credentials and
 * no API keys, and the REST API, which needs a consumer key and a site that
 * will stay up for the length of the run. CSV is first because it is the one
 * that can be done today and can be re-run from a file that does not change
 * under the importer.
 *
 * The mapping — which Woo column means which schema column, what a valid value
 * looks like, what happens when it is missing — lives entirely in the entity
 * importers, which see only `array<string, string>` rows. Nothing in them knows
 * whether those arrays came from fgetcsv or from json_decode of a
 * /wp-json/wc/v3/orders page. Adding the REST source is therefore a new class
 * implementing this interface that flattens the API's nested JSON into the same
 * flat keys, and no change at all to the eight hundred lines of mapping and
 * validation that are the actual work.
 *
 * `fingerprint()` is what makes resume safe. A checkpoint that says "18,000
 * rows of orders are done" is a lie the moment the file behind it changes, so
 * the fingerprint is recorded with the checkpoint and compared on resume: a
 * different file means the run is refused rather than continued from an offset
 * that now points at a different row.
 */
interface RowSource
{
    /**
     * The rows, in a stable order, one array per record.
     *
     * A generator, not an array. A full order export is hundreds of thousands
     * of lines and this has to run inside a shared host's memory limit.
     *
     * @return iterable<int, array<string, string>> line number => row
     */
    public function rows(): iterable;

    /**
     * A digest of the source content, stable across runs of the same input and
     * different for different input. Recorded with the checkpoint so a resume
     * against a changed export is refused instead of silently mis-aligned.
     */
    public function fingerprint(): string;

    /** Where this came from, for the report and for error messages. */
    public function describe(): string;
}
