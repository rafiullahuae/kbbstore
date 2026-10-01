<?php

/**
 * A NEGATIVE PRICE IN THE EXPORT IS REFUSED, BY NAME, AND NOTHING ELSE MOVES.
 *
 * THE DEFECT. `Money::fils()` permits negatives on purpose -- refunds and order
 * totals need them -- and neither the product nor the variation importer
 * checked, so `regular_price = -5.00` in products.csv became `price = -500`.
 * The storefront then prints "AED -5" on the tile and the product page, and
 * the basket subtracts it: a product that pays the shopper to take it.
 * docs/IE-IMPORT-END-TO-END.md §7.1 recorded it as found and not fixed.
 *
 * The rows come from the plugin's own fixture export, with three cells made
 * negative: a regular price, a sale price under a positive regular price, and
 * a variation's price. The control is the same export unmodified, which must
 * refuse nothing for this reason -- and the 0.00 sample sachet in it must
 * still import, because free is a price the shop sells at.
 *
 * MUTATION, RUN: delete the `$fils < 0` refusal from ProductImporter and the
 * first case goes red with `price = -500` in the database; delete it from
 * VariationImporter and the variation half goes red the same way.
 */

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;

function npExport(array $productCells = [], array $variationCells = []): string
{
    $source = base_path('tests/Fixtures/kbb-export');
    $dir = sys_get_temp_dir().'/kbb-np-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    foreach (glob($source.'/*') as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    foreach (['products.csv' => $productCells, 'variations.csv' => $variationCells] as $name => $cells) {
        if ($cells === []) {
            continue;
        }

        $in = fopen($dir.'/'.$name, 'rb');
        $header = fgetcsv($in);
        $rows = [];

        while (($line = fgetcsv($in)) !== false) {
            $row = array_combine($header, $line);

            foreach ($cells[$row['id']] ?? [] as $column => $value) {
                $row[$column] = $value;
            }

            $rows[] = $row;
        }

        fclose($in);

        $out = fopen($dir.'/'.$name, 'wb');
        fputcsv($out, $header);

        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }

        fclose($out);
    }

    return $dir;
}

function npImport(string $dir): ImportReport
{
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    return (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
        runKey: 'np-'.bin2hex(random_bytes(4)),
    ));
}

/** @return array<string, string> source id => reason, for the "is negative" refusals only */
function npNegativeRefusals(ImportReport $report, string $entity): array
{
    $found = [];

    foreach ($report->for($entity)->rejections() as $rejection) {
        if (str_contains($rejection['reason'], 'is negative')) {
            $found[(string) $rejection['id']] = $rejection['reason'];
        }
    }

    return $found;
}

it('refuses a product whose regular or sale price is negative, and names the cell', function () {
    $report = npImport(npExport(productCells: [
        '4022' => ['regular_price' => '-5.00'],
        '4021' => ['sale_price' => '-1.00'],
    ]));

    $refused = npNegativeRefusals($report, 'products');

    expect(array_keys($refused))->toHaveCount(2, 'refused for a negative price: '.json_encode($refused));

    $reasons = implode(' || ', $refused);
    expect(str_contains($reasons, "regular_price '-5.00' is negative"))->toBeTrue($reasons)
        ->and(str_contains($reasons, "sale_price '-1.00' is negative"))->toBeTrue($reasons);

    // Neither reached the catalogue, and nothing in it carries a negative price.
    expect(Product::query()->whereIn('wc_id', [4021, 4022])->count())->toBe(0)
        ->and(Product::query()->where('price', '<', 0)->count())->toBe(0)
        ->and(Product::query()->where('sale_price', '<', 0)->count())->toBe(0);

    // The free sample beside them still imports at zero.
    expect(Product::query()->where('wc_id', 4025)->value('price'))->toBe(0);
});

it('refuses a variation whose price is negative, and keeps its sibling', function () {
    $report = npImport(npExport(variationCells: [
        '4101' => ['regular_price' => '-60.00'],
    ]));

    $refused = npNegativeRefusals($report, 'variations');

    expect(array_keys($refused))->toBe(['id=4101'], 'refused for a negative price: '.json_encode($refused));
    expect(str_contains($refused['id=4101'], "regular_price '-60.00' is negative"))->toBeTrue($refused['id=4101']);

    expect(ProductVariant::query()->where('wc_id', 4101)->count())->toBe(0)
        ->and(ProductVariant::query()->where('price', '<', 0)->count())->toBe(0)
        ->and(ProductVariant::query()->where('wc_id', 4102)->value('price'))->toBe(10000);
});

it('refuses nothing for this reason in the export as the plugin wrote it', function () {
    $report = npImport(npExport());

    expect(npNegativeRefusals($report, 'products'))->toBe([])
        ->and(npNegativeRefusals($report, 'variations'))->toBe([])
        ->and(Product::query()->where('wc_id', 4025)->value('price'))->toBe(0);
});
