<?php

declare(strict_types=1);

/**
 * THE PARSER THE TWO CSS TOOLS SHARE — WHICH IS tests/Support/CssRules.php.
 *
 * This file used to CARRY the parser, and the guard in
 * tests/Feature/GridSkinCopiesTest.php carried a second copy of it. Two copies
 * of the thing whose entire job is to find two copies that have drifted apart
 * is not a joke worth shipping: the survey and the guard would have answered
 * differently the first time either was edited, and the one that disagreed
 * would have been the one nobody ran.
 *
 * So the parser lives in tests/Support/CssRules.php, where the guard can
 * autoload it, and this is a thin shim so the tools keep their function names.
 * Required by path rather than autoloaded because these tools run as plain
 * `php tools/…` with no framework booted.
 */
require_once __DIR__.'/../tests/Support/CssRules.php';

use Tests\Support\CssRules;

function kbbStripComments(string $css): string
{
    return CssRules::stripComments($css);
}

function kbbSplitDeclarations(string $body): array
{
    return CssRules::splitDeclarations($body);
}

function kbbNormalizeSelector(string $sel): string
{
    return CssRules::normalizeSelector($sel);
}

function kbbParseCss(string $css): array
{
    return CssRules::parse($css);
}

function kbbDeclarationIndex(array $rules): array
{
    return CssRules::declarationIndex($rules);
}
