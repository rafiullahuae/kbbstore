<?php

declare(strict_types=1);

namespace App\Services\CategoryHierarchy;

/** A message safe to show the operator as-is: no URL internals, no stack. */
final class HierarchySourceError extends \RuntimeException
{
}
