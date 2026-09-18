<?php

declare(strict_types=1);

namespace App\Services\Invoices;

/**
 * A bulk print that will not happen, and the sentence the operator gets.
 *
 * Two strings rather than one: `headline` is what went wrong, `advice` is what
 * to do about it. They are separate because the page that renders them is read
 * by somebody standing at a printer with a stack of parcels, and "that is 240
 * orders, and one document holds at most 100" is only half an instruction.
 *
 * THIS IS AN HTML PAGE, NOT A JSON BODY, for the reason
 * InvoiceController::missing() already gives: these routes sit in a JSON group
 * but the browser window they answer was opened by window.open(), and handing
 * that window `{"error":"too_many"}` as raw text is a worse answer than a
 * sentence. It carries a 400 all the same, so a caller that does check the
 * status still learns nothing was printed.
 */
final class BulkDocumentRefused extends \RuntimeException
{
    public function __construct(
        public readonly string $headline,
        public readonly string $advice,
    ) {
        parent::__construct($headline . ' ' . $advice);
    }
}
