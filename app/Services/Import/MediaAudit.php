<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Seo\SeoSettings;
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
 * SO THIS IS A SEPARATE PASS, RUN AFTER, and it is read-only. It does not fetch
 * a missing file — but that is now a division of labour and no longer a
 * prohibition, and the sentence that used to sit here is corrected rather than
 * deleted because its objection was a good one.
 *
 * It read: "a downloader that silently half-succeeds would leave the owner
 * worse off than a list of names." True of one that half-succeeds SILENTLY, and
 * not a property of downloading. `App\Services\Import\MediaSideloader` does
 * fetch, and answers the objection the way `ImportRunner` answered it for rows:
 * the work list is re-derived from the catalogue every request, "already done"
 * means the file is on disk rather than a row saying so, every fetch lands by a
 * single rename() so a killed request leaves no truncated file, and IDLE,
 * RUNNING and STALLED are three distinct states on its progress page rather
 * than one frozen bar. See docs/GD-MEDIA-SIDELOADER.md.
 *
 * This class still does not fetch, and that is right: a counter that changes
 * what it counts is a counter nobody can check.
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
 *  - STILL ON THE OLD SITE. An absolute URL pointing at ANOTHER host — this
 *    shop's own is not one, and reading "has a host" as "is remote" was a false
 *    positive in the exact number this command exists to produce. Every image
 *    picked from the Media Library is stored absolute (`Media::urlFor()` builds
 *    `site_url . '/' . $path` for an admin upload, and the product editor
 *    stores what it is given), so on a shop that has never run a WooCommerce
 *    import every photograph the owner uploaded himself was counted as still
 *    being served by WordPress. See judge(). These
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
     * Every place this schema holds an image URL. There is no join to make
     * here: the association IS the string on the row.
     *
     * FOUR OF THEM ARE `App\Support\MediaUsage`'s inventory — products,
     * brands, categories. THE JOURNAL IS NOT IN THAT INVENTORY and used to be
     * absent here too, which was a hole rather than a simplification:
     * `PostImporter` writes `posts.cover` and `posts.body` straight out of a
     * WooCommerce export, where both carry full URLs on the old site. So every
     * article's photographs were hot-linked to WordPress, `remote` did not
     * count them, and the number the runbook tells the owner to watch reached
     * zero with the whole Journal still depending on a site he was about to
     * switch off.
     *
     * `posts.body` is a DOCUMENT, so its addresses are read out of the HTML by
     * `DocumentMediaRewrite::sources()` — the same parser that re-points them.
     * One implementation: an address the audit cannot see is a file the
     * sideloader never fetches, so the rewrite that depends on it would report
     * ABSENT for ever. That parser now reads `<a href>` to an uploads FILE as
     * well as `<img src>`, which is how the full-size image behind every
     * WordPress thumbnail link finally became visible here.
     *
     * AND THE SETTINGS, which have no owning row at all. `og_default_image` and
     * `org_logo` are URLs the storefront publishes on every page — Seo.php
     * reads both — and NOTHING IN THIS CLASS OPENED `settings`. A share image
     * left on the old host was not counted as remote, not fetched by the
     * sideloader and not re-pointed by anything, so `remote => 0` could be
     * reached with the shop's own share preview still served by a site about to
     * be switched off. It breaks in Facebook, WhatsApp and Google's card and
     * nowhere a person looks.
     *
     * The list is `App\Support\MediaUsage::SITE_KEYS` and not a copy of it.
     * That class is where "which settings hold a picture" is decided for the
     * Media Library's delete guard, and two lists would mean the migration and
     * the delete guard disagreeing about the same setting.
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

        /*
         * `cursor()` and two columns, because `posts.body` is a longText and a
         * five-year Journal is megabytes of HTML. Hydrating all of it at once
         * is the memory spike a shared host answers with a blank page.
         */
        foreach (Post::query()->select(['id', 'slug', 'cover', 'body'])->cursor() as $post) {
            $owner = 'article '.$post->id.' ('.$post->slug.')';

            if (is_string($post->cover)) {
                yield [$owner, 'posts.cover', $post->cover];
            }

            /*
             * ONE ROW PER ADDRESS PER ARTICLE, which is what `sources()` used
             * to give for free and has to be done here now that the same file
             * can be named twice — `<a href="x.jpg"><img src="x.jpg">` is one
             * picture, and counting it twice would put a number on the screen
             * that no amount of copying could ever bring to zero.
             *
             * The TAG goes in the field, because the owner acts differently on
             * the two: a picture that stops loading is visible on the article,
             * and a link that stops working is not visible at all until
             * somebody clicks it. A file that is both is a picture — that is
             * the one he can see.
             */
            $carried = [];

            foreach (DocumentMediaRewrite::addresses($post->body) as $address) {
                $carried[$address['url']] ??= [];
                $carried[$address['url']][$address['tag']] = true;
            }

            foreach ($carried as $url => $tags) {
                yield [$owner, isset($tags['img']) ? 'posts.body' : 'posts.body (link)', (string) $url];
            }
        }

        /*
         * ONE settings read for both keys, and `SeoSettings::map()` rather than
         * `Setting::map()` — that is what `Seo.php` itself reads, and the two
         * are different stores. `MediaUsage` records the same correction: using
         * the wrong one made the site images look unused while the storefront
         * was publishing them.
         */
        $settings = SeoSettings::map();

        foreach (MediaUsage::SITE_KEYS as $key => $label) {
            $value = $settings[$key] ?? null;

            if (is_string($value)) {
                yield ['site settings ('.$label.')', 'settings.'.$key, $value];
            }
        }
    }

    /**
     * @return array{owner: string, field: string, url: string, path: string, verdict: string, decision: string, reason: string}
     */
    private function judge(string $owner, string $field, string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);

        /*
         * THIS SHOP'S OWN HOST IS NOT "THE OLD SITE", and reading a host as
         * proof that it is was a false positive in the one number the runbook
         * tells the owner to watch.
         *
         * Every image chosen from the Media Library is stored ABSOLUTE.
         * `MediaUploadController` writes `Media::urlFor($path)`, which for an
         * admin upload (`uploads/…`) builds `site_url . '/' . $path` — a full
         * URL with a host — and `ProductEditorApiController` stores whatever
         * the editor sent straight into `products.image`. So on a shop that has
         * never seen WooCommerce, every product photograph the owner uploaded
         * himself counted as "still served by the old site".
         *
         * `remote` is the number that decides whether a migration is finished.
         * A count that includes the shop's own uploads is a count nobody can
         * act on, and the first time it is looked at is the day the old site is
         * switched off.
         *
         * The shop's own host is `site_url`, falling back to `app.url` —
         * exactly the pair `Media::urlFor()` uses, so the two cannot drift.
         * Matching is on HOST ONLY: scheme moves (http → https behind the
         * host's TLS proxy, which `AppServiceProvider` forces in production)
         * and a port does not make a file a different file.
         */
        if (is_string($host) && $host !== '' && $this->isOwnHost($host)) {
            return $this->judgeLocal($owner, $field, $url, $this->ownPath($url));
        }

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

        return $this->judgeLocal($owner, $field, $url, MediaUsage::normalise($url));
    }

    /** Is this the host this shop is served from? */
    public function isOwnHost(string $host): bool
    {
        $host = strtolower(trim($host));

        foreach ($this->ownHosts() as $own) {
            if ($own === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every host this shop answers to, lowercased.
     *
     * @return list<string>
     */
    private function ownHosts(): array
    {
        $out = [];

        foreach ([Setting::map()['site_url'] ?? null, config('app.url')] as $candidate) {
            $host = parse_url((string) $candidate, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $out[] = strtolower($host);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The path under the WEB ROOT that one of this shop's own URLs names.
     *
     * The subtlety is the subfolder. `site_url` already carries whatever folder
     * the app is served under — `https://…/kbb-upgrade` — and `public_path()`
     * IS that folder on disk, so `/kbb-upgrade/uploads/x.png` resolves to
     * `public_path('uploads/x.png')` and not to `public_path('kbb-upgrade/…')`.
     * `Media::urlFor()` adds that prefix; this takes it back off, and the two
     * are inverses on purpose.
     */
    private function ownPath(string $url): string
    {
        $path = MediaUsage::normalise($url);
        $base = rtrim((string) (parse_url((string) (Setting::map()['site_url'] ?? config('app.url')), PHP_URL_PATH) ?: ''), '/');

        if ($base !== '' && $base !== '/' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        }

        return $path;
    }

    /**
     * @return array{owner: string, field: string, url: string, path: string, verdict: string, decision: string, reason: string}
     */
    private function judgeLocal(string $owner, string $field, string $url, string $path): array
    {
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
