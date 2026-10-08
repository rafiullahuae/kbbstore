<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\PictureTrash;
use Illuminate\Console\Command;

/**
 * Catalog → Products → edit: pictures a product save took off the server are
 * deleted for good 30 days later -- each asked "is anything using you?" again
 * first, and put back if something is. (Lane RPL)
 */
class PictureTrashPurgeCommand extends Command
{
    protected $signature = 'kbb:picture-trash-purge {--limit=100 : at most this many pictures per run}';

    protected $description = 'Delete replaced or removed product pictures whose 30 days in the trash are up.';

    public function handle(): int
    {
        $done = PictureTrash::purgeDue(max(1, min(1000, (int) $this->option('limit'))));

        $this->info(sprintf('Deleted %d pictures for good; put %d back (still in use).', $done['purged'], $done['restored']));

        return self::SUCCESS;
    }
}
