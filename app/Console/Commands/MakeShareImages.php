<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ImageVariants;
use App\Support\ShareImage;
use Illuminate\Console\Command;

/**
 * Make the JPEG share card behind og:image for every product.       (Lane QB)
 *
 *   php artisan kbb:share-images
 *
 * The storefront makes each one after the first page view that finds it
 * missing (App\Support\ShareImage). This is the backfill, so a link shared
 * straight after the package is applied already carries a picture WhatsApp
 * will show. The live server has a shell (CLAUDE.md); run it once after the
 * package, and again any time — a card already up to date is skipped in a
 * stat() call.
 */
class MakeShareImages extends Command
{
    protected $signature = 'kbb:share-images {--limit=0 : Stop after this many products (0 = all)}';

    protected $description = 'Make the 1200x630 JPEG link-preview picture (og:image) for every product photograph';

    public function handle(): int
    {
        if (! ImageVariants::available()) {
            $this->error('This PHP has no GD image library, so no share pictures can be made. og:image stays the original photograph.');

            return self::FAILURE;
        }

        $made = $fresh = $skipped = $bytes = 0;
        $limit = max(0, (int) $this->option('limit'));
        $seen = 0;

        Product::query()->whereNotNull('image')->where('image', '!=', '')
            ->orderBy('id')->select(['id', 'image', 'seo'])
            ->chunkById(200, function ($products) use (&$made, &$fresh, &$skipped, &$bytes, &$seen, $limit) {
                foreach ($products as $product) {
                    if ($limit > 0 && $seen >= $limit) {
                        return false;
                    }

                    $seen++;
                    $seo = is_array($product->seo) ? $product->seo : [];
                    $image = ImageVariants::rootRelative((string) ($seo['og_image'] ?? $product->image));
                    $result = ShareImage::make($image);

                    if ($result['made']) {
                        $made++;
                        $bytes += $result['bytes'];
                    } elseif ($result['reason'] === 'fresh') {
                        $fresh++;
                    } else {
                        $skipped++;
                        $this->line("  #{$product->id} {$image}: {$result['reason']}");
                    }
                }

                return true;
            });

        $avg = $made > 0 ? (int) round($bytes / $made / 1024) : 0;
        $this->info("Share pictures: {$made} made (average {$avg} KB), {$fresh} already up to date, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
