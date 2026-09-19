<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;

/**
 * The state one run shares between its entities: the options, the report, and
 * the maps from WordPress ids to local ids.
 *
 * THE MAPS ARE NOT A CACHE, they are how the entities join to each other. An
 * order row names its customer by `wp_user_id`; an order line names its product
 * by the Woo post id. Neither of those is the local primary key, and looking
 * each one up with its own SELECT would be one query per line item — several
 * hundred thousand of them on a five-year store, on shared hosting. The maps are
 * int => int and a store with 5,000 products and 40,000 customers needs a few
 * megabytes for all of them, so they are simply held.
 *
 * They are filled lazily and written through on every create, so a customer
 * synthesised while importing order 10233 is found by name when order 10240
 * arrives two rows later without another query.
 *
 * WHAT THE PERSISTER IS FOR. Every write in this importer goes through
 * apply(), which exists to answer one question honestly: did this row change?
 * "Created / updated / unchanged" is the only evidence a second pass produces
 * that it was idempotent — and it is evidence the importer cannot fake, because
 * "unchanged" comes from Eloquent's own dirty-attribute comparison against the
 * row as the database has it, not from the importer deciding it did nothing.
 * A second full pass over an unchanged export should report every row unchanged
 * and nothing else. If it does not, something is being rewritten every run.
 *
 * forceFill(), not fill(). Address guards `customer_id` on purpose — a model
 * that mass-assigns it is one careless $request->all() away from letting a
 * shopper file an address under someone else's account — and the importer needs
 * to set it. This is trusted code with no request data anywhere near it, so it
 * writes past the guard explicitly rather than the guard being loosened for
 * everybody. The one exception in the app is the one place it is safe.
 */
final class ImportContext
{
    /** @var array<string, array<int|string, int>> entity => external id => local id */
    private array $maps = [];

    /**
     * Lowercased email => customer id, for the collision check that has to work
     * identically on MySQL (case-insensitive index, would refuse the insert) and
     * SQLite (case-sensitive index, would accept it).
     *
     * @var array<string, int>
     */
    private array $emails = [];

    private bool $emailsLoaded = false;

    public function __construct(
        public readonly ImportOptions $options,
        public readonly ImportReport $report,
    ) {}

    public function dryRun(): bool
    {
        return $this->options->dryRun;
    }

    public function timezone(): string
    {
        return $this->options->sourceTimezone;
    }

    /**
     * Write a model and say what that did.
     *
     * @param  array<string, mixed>  $attributes
     * @return 'created'|'updated'|'unchanged'
     */
    public function apply(Model $model, array $attributes): string
    {
        $isNew = ! $model->exists;

        if (! $isNew) {
            $attributes = $this->withoutEquivalentJson($model, $attributes);
        }

        $model->forceFill($attributes);

        // getDirty() on an existing row compares against the attributes as they
        // were loaded from the database, so a value that is being written back
        // identical shows as clean. That is what makes "unchanged" trustworthy.
        $changed = $isNew || $model->isDirty();

        if ($changed) {
            $model->save();
        }

        if ($isNew) {
            return 'created';
        }

        return $changed ? 'updated' : 'unchanged';
    }

    /**
     * Drop array attributes whose stored value already says the same thing.
     *
     * THIS EXISTS BECAUSE OF MYSQL, AND ONLY MYSQL, AND IT IS THE KIND OF
     * DIVERGENCE THE PARITY SUITE IS FOR. It was green on SQLite and failed on
     * MySQL on the first run of the parity job.
     *
     * A MySQL `json` column is not text. The server parses it and stores a
     * binary form with object keys REORDERED — by key length first, then
     * alphabetically — so an order whose `billing_address` went in as
     * {first_name, last_name, company, line1, ...} reads back as
     * {city, line1, phone, state, country, postcode, last_name, first_name}.
     * SQLite stores the bytes it was given, which is why the suite could not
     * see this.
     *
     * Laravel's own change detection then compares the two DECODED arrays with
     * `===`, which for arrays is order-sensitive. So the attribute is dirty on
     * every single pass, forever. Nothing is corrupted — the value written is
     * identical to the value already there — but every delta pass rewrites
     * every order that has an address, and, worse, the report says "updated"
     * when nothing changed. That report is the evidence the import was
     * idempotent, and an idempotency proof that never says "unchanged" is not a
     * proof of anything.
     *
     * `==` on arrays is order-insensitive and compares keys and values, which
     * is exactly the question being asked: does the stored JSON already say
     * this? Only array attributes are treated this way; everything else keeps
     * Laravel's strict comparison.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withoutEquivalentJson(Model $model, array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $current = $model->getAttribute($key);

            if (is_array($current) && $current == $value) {
                unset($attributes[$key]);
            }
        }

        return $attributes;
    }

    /**
     * Record an outcome against an entity's tally.
     *
     * @param  'created'|'updated'|'unchanged'  $outcome
     */
    public function record(string $entity, string $outcome): void
    {
        $report = $this->report->for($entity);

        match ($outcome) {
            'created' => $report->created(),
            'updated' => $report->updated(),
            default => $report->unchanged(),
        };
    }

    /* ------------------------------------------------------------- id maps */

    public function remember(string $entity, int|string $externalId, int $localId): void
    {
        $this->maps[$entity][$externalId] = $localId;
    }

    public function localId(string $entity, int|string $externalId): ?int
    {
        if (isset($this->maps[$entity][$externalId])) {
            return $this->maps[$entity][$externalId];
        }

        $id = match ($entity) {
            'products' => Product::query()->where('wc_id', $externalId)->value('id'),
            /*
             * `variations` resolves from the database and not only from the
             * in-memory map, because the map is per RUN and an order-items
             * bucket can be imported on its own -- `--only=order-items`, or the
             * Sales group's zip landing after the Catalogue group's did. Every
             * other entity here is in this list for the same reason.
             */
            'variations' => ProductVariant::query()->where('wc_id', $externalId)->value('id'),
            'categories' => Category::query()->where('source_term_id', $externalId)->value('id'),
            'brands' => Brand::query()->where('source_term_id', $externalId)->value('id'),
            'customers' => Customer::query()->where('wp_user_id', $externalId)->value('id'),
            'orders' => Order::query()->where('wc_order_id', $externalId)->value('id'),
            default => null,
        };

        if ($id === null) {
            return null;
        }

        $this->maps[$entity][$externalId] = (int) $id;

        return (int) $id;
    }

    /* -------------------------------------------------------------- emails */

    /**
     * The customer id already holding this address, or null.
     *
     * Loaded once from the database and kept current in memory, so the check is
     * the same on both engines. Doing it with a query per row would be correct
     * on MySQL and WRONG on SQLite — `WHERE email = 'a@x.com'` there does not
     * match a stored 'A@x.com', so the collision would be missed under the test
     * suite and found on the live server. Every address written by this importer
     * is lowercased first (see Emails), so the in-memory map is exact on both.
     */
    public function customerIdForEmail(string $lowercasedEmail): ?int
    {
        $this->loadEmails();

        return $this->emails[$lowercasedEmail] ?? null;
    }

    public function rememberEmail(string $lowercasedEmail, int $customerId): void
    {
        $this->loadEmails();

        $this->emails[$lowercasedEmail] = $customerId;
    }

    private function loadEmails(): void
    {
        if ($this->emailsLoaded) {
            return;
        }

        $this->emailsLoaded = true;

        Customer::query()
            ->withTrashed()
            ->select(['id', 'email'])
            ->chunkById(2000, function ($rows): void {
                foreach ($rows as $row) {
                    $this->emails[mb_strtolower((string) $row->email)] = (int) $row->id;
                }
            });
    }
}
