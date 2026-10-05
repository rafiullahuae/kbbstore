<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SettingsService;
use App\Support\SuperSaleOrder;
use Illuminate\Console\Command;

/**
 * php artisan kbb:super-sale-order          copy /super-sale/'s order from the old site
 * php artisan kbb:super-sale-order --clear  forget it (back to the Reorder order)
 *
 * The same thing as Pages → Page banners → Super Sale products → "Copy the
 * order from kbeautybliss.com/super-sale/", for a shell. (2.60.388)
 */
final class SuperSaleOrderCommand extends Command
{
    protected $signature = 'kbb:super-sale-order {--clear : Use the Reorder order again}';

    protected $description = "Copy /super-sale/'s product order from kbeautybliss.com/super-sale/";

    public function handle(SettingsService $settings): int
    {
        if ($this->option('clear')) {
            SuperSaleOrder::clear($settings);
            $this->info('/super-sale/ uses the Reorder order again.');

            return self::SUCCESS;
        }

        $this->line('Reading '.SuperSaleOrder::SOURCE.' …');
        $got = SuperSaleOrder::fetchSlugs();

        if ($got['slugs'] === []) {
            $this->error($got['error'] ?? 'No products were found on the old page.');

            return self::FAILURE;
        }

        $stored = SuperSaleOrder::store($settings, $got['slugs']);
        $this->info("Copied: {$stored['count']} products in the old order, from {$got['pages']} page(s).");

        if ($stored['missing'] !== []) {
            $this->warn(count($stored['missing']).' on the old page are not in this shop: '.implode(', ', $stored['missing']));
        }

        return self::SUCCESS;
    }
}
