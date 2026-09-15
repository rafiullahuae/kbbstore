<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

/**
 * The driver will not do what was asked, and the message says why in words the
 * owner can act on: nothing uploaded, something already running, a second tab
 * trying to step the same run.
 *
 * Distinct from the \RuntimeException the checkpoint raises when a source file
 * has changed under a part-finished entity — that one is caught inside the
 * step and turned into an offer to restart that entity, not into a refusal of
 * the request.
 */
final class ImportDriverRefused extends \RuntimeException {}
