<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Every choice the run makes, settled once and passed down.
 *
 * Two of these are decisions docs/IMPORT-READINESS.md says the owner has to
 * make, not the importer. They are flags rather than constants so the owner can
 * make them, with the documented recommendation as the default so that doing
 * nothing does the recommended thing:
 *
 *  - $synthesiseGuests (D3). A Woo guest order imports with customer_id NULL
 *    and then sits outside every per-customer figure, because each of those is
 *    a join through `customers`. On a store where most checkouts are guest
 *    checkouts that is most of the money. Default TRUE — create a customer row
 *    per distinct guest email — because it is what this application's own
 *    checkout already does: Customer::firstOrCreate() makes a row for a shopper
 *    who does not sign in. Importing guests as unlinked would create a second
 *    class of guest the application does not otherwise produce.
 *
 *  - $orderNumberFrom (D5). `orders.order_number` is UNIQUE NOT NULL, and a Woo
 *    store with a sequential-number plugin has two different values: the post id
 *    and the display number. Only one can go in. Default 'number' with a
 *    fall-back to the id, because that column's stated job in the Phase 0 schema
 *    is "human-facing number, preserved exactly from WooCommerce" — the number
 *    on the invoice the customer already has. `--order-number=id` switches to
 *    the post id, which is guaranteed unique and guaranteed not to match any
 *    paperwork.
 *
 * $sourceTimezone has no safe default and so has a loud one: the store's
 * WordPress timezone, Asia/Dubai for this store. It is an option because an
 * export from a site configured in UTC needs UTC, and getting it wrong shifts
 * every order in the store by four hours.
 *
 * $adoptBySlug exists because of something this importer found on its first
 * real run and nothing in the readiness audit predicted. The migration
 * 2026_08_27_100000_seed_demo_catalogue seeds a demo catalogue on EVERY
 * install, production included, and it includes brands slugged `cosrx` and
 * `beauty-of-joseon` and categories slugged `cleansers`, `toners`, `serums`.
 * Those are real brands this store really sells. `brands.slug` is unique, so
 * importing the genuine COSRX term hits a slug already held by a placeholder
 * row that has no WooCommerce origin at all, and is refused.
 *
 * Refusing is the right DEFAULT — matching on a slug is precisely what
 * docs/IMPORT-READINESS.md rule 1 forbids, because slugs get edited and a
 * wrong adoption silently merges two different things. But the owner staring
 * at 93 refused brands needs a better answer than editing 93 rows by hand.
 *
 * So: with this flag, and ONLY where the holder's external id is NULL — a row
 * that demonstrably did not come from WooCommerce — the importer claims the
 * existing row by writing the Woo term id onto it, and reports every adoption
 * individually. A slug held by a row that came from a DIFFERENT Woo term is
 * still refused, flag or no flag, because that is a genuine conflict between
 * two real terms and only the owner can say which one keeps the slug.
 */
final class ImportOptions
{
    /**
     * @param  string  $directory  where the CSV files live
     * @param  array<string, string>  $files  entity => explicit path, overriding the convention
     * @param  list<string>  $only  entities to run; empty means all of them
     * @param  int  $batchSize  rows per committed transaction
     * @param  int  $limit  stop after this many rows per entity; 0 means no limit
     */
    public function __construct(
        public readonly string $directory,
        public readonly array $files = [],
        public readonly array $only = [],
        public readonly bool $dryRun = false,
        public readonly int $batchSize = 500,
        public readonly int $limit = 0,
        public readonly string $runKey = 'default',
        public readonly bool $restart = false,
        public readonly bool $synthesiseGuests = true,
        public readonly string $orderNumberFrom = 'number',
        public readonly string $sourceTimezone = 'Asia/Dubai',
        public readonly bool $adoptBySlug = false,
    ) {}

    public function fileFor(string $entity, string $conventionalName): ?string
    {
        if (isset($this->files[$entity])) {
            return $this->files[$entity];
        }

        $path = rtrim($this->directory, '/').'/'.$conventionalName;

        return is_file($path) ? $path : null;
    }

    public function wants(string $entity): bool
    {
        return $this->only === [] || in_array($entity, $this->only, true);
    }
}
