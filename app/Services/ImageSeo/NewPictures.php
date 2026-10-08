<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * A picture newly put on a product gets its SEO name and its description at
 * once, from the module that already decides both. (Lane RPL)
 *
 * The owner: "the new one will have properly alt name, can be taken from the
 * product title".
 *
 *   proposeAlts()   BEFORE the save: an empty description box on a picture this
 *                   product did not have gets AltText::propose() for its slot.
 *                   A description the owner typed is never touched.
 *   nameNewFiles()  AFTER the save: a new picture whose upload name is generic
 *                   (IMG_1234, image-1, whatsapp-image-…, a timestamp — exactly
 *                   ImageScore::isRandom()) is renamed by ImageSeoPlanner +
 *                   ImageRenamer, the Image SEO rename itself: the slot's
 *                   variation name, its img-cache copies moved with it, every
 *                   reference rewritten, a 301 from the old name. A name the
 *                   owner chose before uploading is left alone, and so is a
 *                   picture another product also uses.
 */
final class NewPictures
{
    /**
     * @param  array<string, mixed>  $data  the validated save payload, changed in place
     * @param  list<array{url: string}>  $before  PictureTrash::slots() before the save
     */
    public static function proposeAlts(array &$data, Product $product, array $before): void
    {
        if (! array_key_exists('image', $data) && ! array_key_exists('images', $data)) {
            return;
        }

        $list = self::listFrom($data, $product);
        $had = array_flip(array_column($before, 'url'));
        $new = array_values(array_filter($list, static fn (string $u) => ! isset($had[$u])));

        if ($new === []) {
            return;
        }

        $alts = array_key_exists('image_alts', $data) ? (array) ($data['image_alts'] ?? []) : (is_array($product->image_alts) ? $product->image_alts : []);
        $empty = array_values(array_filter($new, static fn (string $u) => trim((string) ($alts[$u] ?? '')) === ''));

        if ($empty === []) {
            return;
        }

        $brandId = array_key_exists('brand_id', $data) ? $data['brand_id'] : $product->brand_id;
        $categoryId = $data['primary_category_id'] ?? $product->category_id;
        $brand = $brandId ? Brand::query()->whereKey($brandId)->value('name') : null;
        $category = $categoryId ? Category::query()->whereKey($categoryId)->value('name') : null;
        $name = (string) ($data['name'] ?? $product->name ?? '');

        $proposed = AltText::propose(is_string($brand) ? $brand : null, $name, is_string($category) ? $category : null, count($list));

        foreach ($list as $i => $url) {
            $alt = (string) ($proposed[$i] ?? '');

            if (in_array($url, $empty, true) && AltText::acceptable($alt)) {
                $alts[$url] = $alt;
            }
        }

        $data['image_alts'] = $alts;
    }

    /**
     * @param  list<array{url: string}>  $before
     * @return array<string, string> old relative path => new relative path, for every picture renamed
     */
    public static function nameNewFiles(Product $product, array $before, ?object $admin = null): array
    {
        $had = [];

        foreach ($before as $s) {
            $rel = ImageFiles::local($s['url']);

            if ($rel !== null) {
                $had[$rel] = true;
            }
        }

        $generic = [];

        foreach (array_merge([$product->image], (array) ($product->images ?? [])) as $url) {
            $rel = ImageFiles::local(is_string($url) ? $url : null);

            if ($rel !== null && ! isset($had[$rel]) && ImageScore::isRandom((string) pathinfo($rel, PATHINFO_FILENAME)) && ImageFiles::exists($rel)) {
                $generic[$rel] = true;
            }
        }

        if ($generic === []) {
            return [];
        }

        // The Image SEO module's own lock: never beside a rename job it is running.
        $lock = Cache::lock(ImageSeoJobs::LOCK, 60);

        if (! $lock->get()) {
            return [];
        }

        try {
            $fresh = Product::query()->with(['brand:id,name', 'category:id,name'])->find($product->id);

            if ($fresh === null) {
                return [];
            }

            $plan = (new ImageSeoPlanner(ImageNamer::STRATEGY_VARIATIONS))->plan([$fresh], [(int) $fresh->id => array_keys($generic)])[0] ?? null;

            if ($plan === null) {
                return [];
            }

            $any = false;

            foreach ($plan['images'] as &$image) {
                if ($image['action'] !== 'rename' || ! isset($generic[(string) $image['rel']])) {
                    $image['action'] = 'skip';
                } else {
                    $any = true;
                }
            }
            unset($image);

            if (! $any) {
                return [];
            }

            $done = [];

            foreach ((new ImageRenamer())->renameProduct($plan, ['admin_id' => $admin->id ?? null, 'admin_name' => $admin->name ?? null]) as $r) {
                if ($r['status'] === 'renamed' && is_string($r['to'])) {
                    $done[(string) $r['rel']] = $r['to'];
                }
            }

            if ($done !== []) {
                ImageSeo::rescore([(int) $fresh->id]);
            }

            return $done;
        } finally {
            $lock->release();
        }
    }

    /**
     * The product's pictures as apply() will store them: main first, then the
     * gallery without the main image and without repeats.
     *
     * @return list<string>
     */
    private static function listFrom(array $data, Product $product): array
    {
        $main = trim((string) (array_key_exists('image', $data) ? ($data['image'] ?? '') : ($product->image ?? '')));
        $gallery = array_key_exists('images', $data) ? (array) ($data['images'] ?? []) : (array) ($product->images ?? []);
        $out = $main === '' ? [] : [$main];

        foreach ($gallery as $u) {
            $u = trim((string) $u);

            if ($u !== '' && ! in_array($u, $out, true)) {
                $out[] = $u;
            }
        }

        return $out;
    }
}
