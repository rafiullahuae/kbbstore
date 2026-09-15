<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * One source row cannot be imported, and the import carries on without it.
 *
 * Thrown by the mappers, caught by the runner, turned into a line in the
 * report. It exists so "this row is wrong" is expressed the same way
 * everywhere and can never be confused with "this row is fine". A silent skip
 * is the failure mode that makes an import untrustworthy: the totals come out
 * plausible and nobody finds the missing orders until a customer asks where
 * theirs went.
 *
 * The message IS the report line, so it names the field and the offending
 * value. "malformed money" is not an actionable reason. "total: '1.234,50' is
 * ambiguous — is the comma a decimal point or a thousands separator?" is.
 */
final class RowRejected extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
