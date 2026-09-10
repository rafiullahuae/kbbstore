<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class ImportCatalog extends Command
{
    protected $signature = 'kbb:import-catalog {file=storage/catalog/products.json}';
    protected $description = 'Import products from a WooCommerce JSON export (idempotent on wc_id)';

    public function handle()
    {
        /*
         * DISABLED BY PHASE 0.
         *
         * This imported storage/catalog/products.json into the original flat
         * products table. That table was replaced in Phase 0 (brand and category
         * are now real relations, prices are integer fils), so every run failed
         * and kept kbb-finish.php from ever completing.
         *
         * Real catalogue data arrives through the WordPress Migrator, not from
         * this demo file, so there is nothing to rewrite here.
         */
        $this->info('kbb:import-catalog is disabled — catalogue data now comes from the WordPress Migrator.');

        return self::SUCCESS;
    }

    private function handleLegacy(): int
    {
        $path = base_path($this->argument('file'));
        if (!is_file($path)) {
            $this->error("Catalog file not found: {$path}");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);
        if (is_array($data) && isset($data['products']) && is_array($data['products'])) {
            $data = $data['products'];
        }
        if (!is_array($data)) {
            $this->error('Catalog JSON is not an array of products.');
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $bar = $this->output->createProgressBar(count($data));
        $bar->start();

        foreach ($data as $p) {
            $concerns = $p['concerns'] ?? [];
            $attrs = [
                'slug'             => $p['slug'] ?? \Illuminate\Support\Str::slug($p['name'] ?? ('product-' . ($p['wc_id'] ?? uniqid()))),
                'name'             => $p['name'] ?? null,
                'brand'            => $p['brand'] ?? null,
                'category'         => $p['category'] ?? null,
                'concerns'         => is_array($concerns) ? implode(',', $concerns) : (string) $concerns,
                'price'            => $p['price'] ?? null,
                'sale_price'       => $p['sale_price'] ?? null,
                'sku'              => $p['sku'] ?? null,
                'stock'            => $p['stock'] ?? null,
                'in_stock'         => (int) ($p['in_stock'] ?? 1),
                'featured'         => (int) ($p['featured'] ?? 0),
                'position'         => (int) ($p['position'] ?? 0),
                'rating'           => $p['rating'] ?? null,
                'reviews'          => (int) ($p['reviews'] ?? 0),
                'status'           => $p['status'] ?? 'active',
                'image'            => $p['image'] ?? (isset($p['images'][0]) ? $p['images'][0] : null),
                'images_json'      => isset($p['images']) ? json_encode($p['images']) : null,
                'collections_json' => isset($p['collections']) ? json_encode($p['collections']) : null,
                'labels_json'      => isset($p['labels']) ? json_encode($p['labels']) : null,
                'custom_tabs_json' => isset($p['custom_tabs']) ? json_encode($p['custom_tabs']) : null,
                'variants_json'    => isset($p['variants']) ? json_encode($p['variants']) : null,
                'variant_axis'     => $p['variant_axis'] ?? null,
                'short_description'=> $p['short_description'] ?? null,
                'description'      => $p['description'] ?? null,
            ];

            $existing = isset($p['wc_id']) ? Product::where('wc_id', $p['wc_id'])->first() : null;
            if ($existing) {
                $existing->update($attrs);
                $updated++;
            } else {
                Product::create($attrs + ['wc_id' => $p['wc_id'] ?? null]);
                $created++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Catalog import complete — {$created} created, {$updated} updated.");
        return self::SUCCESS;
    }
}
