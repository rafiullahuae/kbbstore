<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

/** A check in ImageRenamer said no; carries the picture it is about. (Lane IR) */
final class RenameRefused extends \RuntimeException
{
    public function __construct(public readonly string $path, public readonly string $reason)
    {
        parent::__construct($reason.' ['.$path.']');
    }
}
