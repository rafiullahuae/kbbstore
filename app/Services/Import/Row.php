<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * One source row, read through column aliases.
 *
 * WHY ALIASES. There is no single "WooCommerce CSV". Woo's built-in product
 * exporter writes "Regular price" and "In stock?". The WebToffee order exporter
 * writes `order_number` and `billing_email`. A hand-rolled `SELECT` against
 * wp_posts writes `ID` and `post_status`. A REST response flattened into the
 * same shape writes `date_created` and `total`. All four are things the owner
 * might plausibly hand this importer, and demanding one exact spelling would
 * mean the first thing they do is rename forty columns by hand — which is
 * itself a step that introduces errors nobody will find.
 *
 * So every field is declared with the names it is known by, most canonical
 * first, and the first one PRESENT in the row wins. Present, not truthy: a
 * `sale_price` column that exists and is empty means "no sale price" and must
 * not fall through to some other column that happens to have a number in it.
 * That distinction is the whole reason this is a class and not `$row['x'] ??
 * $row['y']`.
 */
final class Row
{
    /**
     * @param  array<string, string>  $cells
     */
    public function __construct(
        public readonly int $line,
        private readonly array $cells,
    ) {}

    /**
     * The first alias that EXISTS in the row, trimmed. Null when none of them do.
     */
    public function raw(string ...$aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $this->cells)) {
                return $this->cells[$alias];
            }
        }

        return null;
    }

    /** The value, or null when absent or blank. */
    public function text(string ...$aliases): ?string
    {
        $value = $this->raw(...$aliases);

        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A value this row cannot be imported without.
     *
     * @throws RowRejected
     */
    public function requireText(string $label, string ...$aliases): string
    {
        $value = $this->text(...$aliases);

        if ($value === null) {
            throw RowRejected::because(
                $label.' is required and this row has none (looked for: '.implode(', ', $aliases).')'
            );
        }

        return $value;
    }

    /**
     * A WordPress / WooCommerce id.
     *
     * Rejects anything that is not a positive integer rather than casting it.
     * `(int) "abc"` is 0, and 0 is a valid-looking id that would collide with
     * every other unparseable row in the file on a unique index — turning a
     * legible "this cell is not a number" into "duplicate entry 0".
     *
     * @throws RowRejected
     */
    public function id(string $label, string ...$aliases): ?int
    {
        $value = $this->text(...$aliases);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw RowRejected::because($label.": '".$value."' is not a WordPress id");
        }

        $id = (int) $value;

        if ($id === 0) {
            return null;
        }

        return $id;
    }

    /**
     * @throws RowRejected
     */
    public function requireId(string $label, string ...$aliases): int
    {
        $id = $this->id($label, ...$aliases);

        if ($id === null) {
            throw RowRejected::because(
                $label.' is required — without it this row cannot be matched on a re-run '
                .'and the next pass would insert a second copy of it'
            );
        }

        return $id;
    }

    /**
     * @throws RowRejected
     */
    public function money(string $label, string ...$aliases): ?int
    {
        return Money::fils($this->raw(...$aliases), $label);
    }

    /**
     * @throws RowRejected
     */
    public function moneyOrZero(string $label, string ...$aliases): int
    {
        return Money::filsOrZero($this->raw(...$aliases), $label);
    }

    /**
     * @throws RowRejected
     */
    public function date(string $label, string $timezone, string ...$aliases): ?\Carbon\CarbonImmutable
    {
        return DateParser::utc($this->raw(...$aliases), $label, $timezone);
    }

    /**
     * WooCommerce booleans arrive as 1/0, yes/no, true/false, and — in the
     * product exporter — as an empty cell meaning false.
     */
    public function bool(?bool $default, string ...$aliases): ?bool
    {
        $value = $this->text(...$aliases);

        if ($value === null) {
            return $default;
        }

        return in_array(mb_strtolower($value), ['1', 'yes', 'y', 'true', 't', 'on', 'visible', 'published'], true);
    }

    public function int(int $default, string ...$aliases): int
    {
        $value = $this->text(...$aliases);

        if ($value === null || preg_match('/^-?\d+$/', $value) !== 1) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * A delimited list cell, e.g. "skincare, cleansers" or "a|b".
     *
     * @return list<string>
     */
    public function list(string $separator, string ...$aliases): array
    {
        $value = $this->text(...$aliases);

        if ($value === null) {
            return [];
        }

        $parts = array_map('trim', explode($separator, $value));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    public function has(string $alias): bool
    {
        return array_key_exists($alias, $this->cells);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->cells;
    }
}
