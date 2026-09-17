<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\MediaUsage;

/**
 * Does the file each imported row names actually exist?
 *
 * WHAT THE IMPORTER DOES WITH AN IMAGE TODAY, exactly: it copies the string.
 * `ProductImporter` line 192 assigns `image` and line 205 assigns `images`
 * straight from the export, `BrandImporter` line 64 does the same for `logo`
 * and `CategoryImporter` line 84 for `image`. Nothing opens a file, nothing
 * downloads anything, and nothing checks that what was named is there. That is
 * the RIGHT call for the import itself — an image that 404s is not a reason to
 * refuse an order, and the owner copies `wp-content/uploads` across separately
 * and often afterwards — but it means a clean import report is not evidence
 * that a single picture will load.
 *
 * SO THIS IS A SEPARATE PASS, RUN AFTER, and it is deliberately read-only. It
 * cannot fetch a missing file: the host has no shell, the old site may already
 * be gone, and a downloader that silently half-succeeds would leave the owner
 * worse off than a list of names.
 *
 * THE THREE ANSWERS, and only the first is good:
 *
 *  - PRESENT. The path resolves to a real file under the web root. Nothing to
 *    do.
 *
 *  - MISSING. A local path that names nothing. This is the ordinary outcome of
 *    importing the catalogue before copying the uploads folder, so it is
 *    expected on the first pass and alarming on the last one.
 *
 *  - STILL ON THE OLD SITE. An absolute URL pointing at another host. These
 *    LOOK FINE IN A BROWSER, which is what makes them the dangerous category:
 *    the shop renders perfectly while WooCommerce is still up, and every
 *    product image breaks on the day the old site is switched off. A migration
 *    that is declared finished on the strength of "the pictures all load" is
 *    exactly the one this catches.
 *
 * WHERE THE WEB ROOT IS. `bootstrap/app.php` ends with `usePublicPath(...)`
 * pointing at `public_html/kbb-upgrade`, which is a DIFFERENT DIRECTORY from
 * the application root — so `public_path()` is the only correct way to ask
 * this question on the server, and `__DIR__`-relative guesses are wrong there
 * while looking right in a checkout.
 *
 * PATHS ARE REDUCED BY `MediaUsage::normalise()` and nothing else. That class
 * says in as many words that nothing outside it should re-derive a path
 * another way, and it already handles the query string, the fragment and the
 * percent-escaping that a WordPress attachment URL carries.
 */
final class MediaAudit
{
    public const PRESENT = 'present';

    public const MISSING = 'missing';

    public const REMOTE = 'remote';

    /**
     * Every image reference in the catalogue, with a verdict on each.
     *
     * @return list<array{owner: string, field: string, url: string, path: string, verdict: string, decision: string, reason: string}>
     */
    public function audit(): array
    {
        $out = [];

        foreach ($this->references() as $reference) {
            [$owner, $field, $url] = $reference;

            $url = trim($url);

            if ($url === '') {
                continue;
            }

            $out[] = $this->judge($owner, $field, $url);
        }

        return $out;
    }

    /**
     * The four columns that hold an image URL in this schema, per
     * `App\Support\MediaUsage`'s inventory. There is no join to make here: the
     * association IS the string on the row.
     *
     * @return iterable<int, array{0: string, 1: string, 2: string}>
     */
    private function references(): iterable
    {
        foreach (Product::query()->select(['id', 'slug', 'image', 'images'])->cursor() as $product) {
            $owner = 'product '.$product->id.' ('.$product->slug.')';

            if (is_string($product->image)) {
                yield [$owner, 'products.image', $product->image];
            }

            foreach ((array) ($product->images ?? []) as $image) {
                if (is_string($image)) {
                    yield [$owner, 'products.images', $image];
                }
            }
        }

        foreach (Brand::query()->select(['id', 'slug', 'logo'])->cursor() as $brand) {
            if (is_string($brand->logo)) {
                yield ['brand '.$brand->id.' ('.$brand->slug.')', 'brands.logo', $brand->logo];
            }
        }

        foreach (Category::query()->select(['id', 'slug', 'image'])->cursor() as $category) {
            if (is_string($category->image)) {
                yield ['category '.$category->id.' ('.$category->slug.')', 'categories.image', $category->image];
            }
        }
    }

    /**
     * @return array{owner: string, field: string, url: string, path: string, verdict: string, decision: string, reason: string}
     */
    private function judge(string $owner, string $field, string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return [
                'owner' => $owner,
                'field' => $field,
                'url' => $url,
                'path' => '',
                'verdict' => self::REMOTE,
                'decision' => RedirectMap::ASK,
                'reason' => 'this image is still served by '.$host.'. It will load for as long as that site is up '
                    .'and break on the day it is switched off — which is usually the day after the migration is '
                    .'declared finished. Copy the file across and re-point the row, or confirm the host is staying.',
            ];
        }

        $path = MediaUsage::normalise($url);

        if ($path === '') {
            return [
                'owner' => $owner,
                'field' => $field,
                'url' => $url,
                'path' => '',
                'verdict' => self::MISSING,
                'decision' => RedirectMap::ASK,
                'reason' => 'this value is not a usable path at all, so nothing can be served for it',
            ];
        }

        $full = public_path(ltrim($path, '/'));

        if (is_file($full)) {
            return [
                'owner' => $owner,
                'field' => $field,
                'url' => $url,
                'path' => $path,
                'verdict' => self::PRESENT,
                'decision' => RedirectMap::MIGRATE,
                'reason' => 'the file is where the row says it is',
            ];
        }

        return [
            'owner' => $owner,
            'field' => $field,
            'url' => $url,
            'path' => $path,
            'verdict' => self::MISSING,
            'decision' => RedirectMap::ASK,
            'reason' => 'no file at this path under the web root. If the uploads folder has not been copied across '
                .'yet this is expected; if it has, this image is genuinely lost and the row needs a new one.',
        ];
    }

    /**
     * @param  list<array{owner: string, field: string, url: string, path: string, verdict: string, decision: string, reason: string}>  $rows
     * @return array{present: int, missing: int, remote: int}
     */
    public function summarise(array $rows): array
    {
        $out = ['present' => 0, 'missing' => 0, 'remote' => 0];

        foreach ($rows as $row) {
            $out[$row['verdict']]++;
        }

        return $out;
    }
}
