<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

/**
 * An uploaded file the screen will not accept, with a sentence saying why.
 *
 * Its message is shown to the owner verbatim, so it is written for them and not
 * for a log: it says what the file appears to be and what to do instead. It
 * never quotes the uploaded filename back — that string came from the browser
 * and reflecting it into the page is how a refusal message becomes an XSS
 * vector on an admin screen.
 */
final class ImportUploadRejected extends \RuntimeException {}
