<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Thrown at the end of a dry run, purely to roll its transaction back.
 *
 * Its own class rather than a generic exception so that nothing else can ever
 * be mistaken for it. `catch (\Exception)` around the dry run would swallow a
 * real failure and report the preview as successful; catching exactly this
 * cannot.
 */
final class DryRunComplete extends \RuntimeException {}
