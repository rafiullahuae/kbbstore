<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Brand;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\SlugGuard;
use Illuminate\Support\Str;

/**
 * Brands.
 *
 * Production does not have a brands taxonomy: brands live as the `pa_brands`
 * product attribute, 93 terms of it, and this schema promotes them to their own
 * table with `source_term_id` carrying the original attribute term id. So the
 * export the owner produces for this entity is a list of pa_brands terms, and
 * the term ids in it are the same ones the product rows reference.
 *
 * Flat — no parent, no depth, no path — so unlike categories this is a single
 * pass with nothing to finalise.
 */
final class BrandImporter extends EntityImporter
{
    public function name(): string
    {
        return 'brands';
    }

    public function conventionalFile(): string
    {
        return 'brands.csv';
    }

    /** Brands the export supplied. A brand with no `source_term_id` is a demo-catalogue placeholder this import did not claim. */
    public function countImported(): ?int
    {
        return Brand::query()->whereNotNull('source_term_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $termId = $row->requireId('term_id', 'term_id', 'id', 'brand_id');
        $name = $row->requireText('name', 'name', 'title');

        $slug = $row->text('slug') ?? Str::slug($name);

        if ($slug === '') {
            throw RowRejected::because("name '".$name."' does not reduce to a usable slug");
        }

        $brand = Brand::query()->where('source_term_id', $termId)->first();

        if ($brand === null) {
            $adopt = SlugGuard::resolve(
                Brand::query(), 'brands', 'source_term_id', $slug, $termId, $context, $this->name(),
            );

            $brand = $adopt === null ? new Brand : Brand::query()->findOrFail($adopt);
        }

        $outcome = $context->apply($brand, [
            'source_term_id' => $termId,
            'slug' => $slug,
            'name' => $name,
            'description' => $row->text('description'),
            'logo' => $row->text('logo', 'image', 'thumbnail'),
            'position' => $row->int((int) ($brand->position ?? 0), 'position', 'menu_order', 'order'),
        ]);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $termId, (int) $brand->id);
    }
}
