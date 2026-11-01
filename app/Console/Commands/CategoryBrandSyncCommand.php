<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CategoryBrandSync;
use Illuminate\Console\Command;

/**
 * Catalog -> Categories -> "Same products as brand", brought level for every
 * linked category (2.60.633). Scheduled daily in routes/console.php; a
 * product or category save already keeps them level, this catches imports
 * and bulk edits. Two set-based statements per linked category.
 */
class CategoryBrandSyncCommand extends Command
{
    protected $signature = 'kbb:category-brand-sync';

    protected $description = 'Keep each category that mirrors a brand level with that brand';

    public function handle(): int
    {
        $r = CategoryBrandSync::sync();
        $this->line("Linked {$r['linked']}, branded {$r['branded']}, other-brand products left in place: {$r['other_brand']}.");

        return self::SUCCESS;
    }
}
