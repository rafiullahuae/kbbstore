<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Seo\SeoSettings;
use App\Support\MediaUsage;
use Illuminate\Support\Facades\DB;

/**
 * Take the shop's photographs off the old site.
 *
 * =============================================================================
 * WHAT THE IMPORT LEAVES BEHIND, IN ONE SENTENCE
 * =============================================================================
 *
 * `ProductImporter` copies the image column as a STRING, and a WooCommerce
 * product export writes that column as a full URL on the site it was exported
 * from. Checked in `tests/Fixtures/woo/products.csv`, which is the shape this
 * repository pins the importer against:
 *
 *     image  = https://kbeautybliss.com/wp-content/uploads/2019/03/ginseng-serum.jpg
 *     images = https://kbeautybliss.com/…/ginseng-serum-2.jpg,https://…-3.jpg
 *
 * So after a clean, fully verified import with every row accounted for, **every
 * product photograph on the new shop is hot-linked to WordPress**. The pages
 * look perfect. They keep looking perfect right up to the day the old site is
 * switched off, which is normally the day after somebody decides the migration
 * went well. `MediaAudit` counts these; nothing moved them.
 *
 * =============================================================================
 * WHY A REWRITE AND NOT A DOWNLOAD
 * =============================================================================
 *
 * `MediaAudit` says in its own header why it will not fetch: no shell, the old
 * site may already be gone, and a downloader that half-succeeds leaves the
 * owner worse off than a list. That is still true and this does not fetch
 * either. The uploads folder is copied across by the owner, by FTP or from the
 * host's file manager, the same way `wp-content/uploads` is copied in every
 * WordPress migration — and THEN this re-points the rows at the copy.
 *
 * The check that makes it safe is that it only rewrites a row whose file is
 * ALREADY THERE. Rewriting first and copying afterwards would turn a page that
 * renders into a page of broken frames, and the owner would be looking at the
 * damage with no way to tell which rows had been touched.
 *
 * =============================================================================
 * IT NEVER GUESSES WHICH HOST IS THE OLD SHOP
 * =============================================================================
 *
 * The host has to be named. `hostsSeen()` lists every host the catalogue points
 * at, with a count and with this shop's own host marked, and nothing is
 * rewritten until one of them is passed in. Guessing "the most common host" is
 * how a CDN, an embedded supplier photograph or a partner's banner gets
 * rewritten into a local path that does not exist.
 *
 * =============================================================================
 * THE SHAPE IT WRITES, AND THE ONE THING THAT IS NOT FREE ABOUT IT
 * =============================================================================
 *
 * `Media::urlFor($path)` — the application's own media URL builder, and the
 * single place that knows the shop has TWO upload roots that are not
 * interchangeable (`wp-content/uploads/…` from the WordPress import,
 * `uploads/…` from an admin upload). Nothing new is invented here, so an
 * imported photograph ends up spelled exactly like one the Media Library would
 * produce for the same file, and a `wp-content` path cannot be handed to the
 * `uploads` branch by accident.
 *
 * THAT VALUE CARRIES THE BASE PATH. For a `wp-content` path it goes through
 * `Url::media()` and then `Url::raw()`,
 * which prefixes `KBB_BASE_PATH`, so on staging a row is written as
 * `/kbb-upgrade/wp-content/uploads/…`. This is deliberate and it is NOT the
 * mistake `RedirectMap`'s header warns about, because the two columns are read
 * in opposite ways: `redirects.source` is compared against `getPathInfo()`,
 * which strips the prefix, whereas `products.image` is printed straight into a
 * `src` attribute by `store/home.blade.php`, `partials/home/grid.blade.php` and
 * the rest — no helper, no prefixing. A base-free path in that column is a 404
 * on every tile of a shop served from a subfolder.
 *
 * The cost is that the value has to be rewritten again if the shop moves to the
 * domain root, and re-running propose() will NOT do it — by then the rows carry
 * no host, so the host filter does not see them. `proposeRebase()` is the
 * answer, and it is a separate entry point on purpose; its own comment says
 * why. `uploadsRelative()` is what makes both of them survive a base change,
 * because it cuts at the uploads root rather than at whatever `Url::base()`
 * happens to return today.
 *
 * =============================================================================
 * REVERSIBLE WITHOUT A LEDGER TABLE
 * =============================================================================
 *
 * The transformation drops a scheme and a host and keeps the path byte for
 * byte, so it is exactly invertible: `restore()` puts `https://<host>` back in
 * front of the same path. No table records what was done, because a table that
 * records it is a table that can disagree with the rows — and the rows are the
 * only thing that matters here.
 */
final class MediaRewrite
{
    /** The row will be re-pointed at this shop. */
    public const REWRITE = 'rewrite';

    /** Already pointing where this would point it. */
    public const SAME = 'same';

    /** Named host, but the file is not under the web root yet. */
    public const ABSENT = 'absent';

    /**
     * The columns that hold an image URL, per App\Support\MediaUsage — plus
     * the one it does not know about.
     *
     * `posts.cover` IS A CELL and belongs here. `MediaUsage`'s inventory stops
     * at products, brands and categories because those are what the Media
     * Library's delete guard asks about, and the Journal was empty when it was
     * written. It is not empty now: `PostImporter` fills `posts` from
     * `posts.csv`, and a WooCommerce export writes the featured image as a full
     * URL on the site it came from — exactly as it does for a product. So after
     * an import every article's cover photograph was hot-linked to WordPress
     * and NOTHING IN THIS APPLICATION COULD RE-POINT IT.
     *
     * `posts.body` is NOT here, and that is the other half of the same fix. It
     * is a document, not a cell: see App\Services\Import\DocumentMediaRewrite,
     * which does to the `<img>` and `<a href>` tags inside it what this does to
     * a column.
     *
     * NEITHER ARE THE SETTINGS, and for a third reason: `og_default_image` and
     * `org_logo` are not columns at all but rows in `settings`, keyed by name.
     * references() yields them alongside these and replace() has a branch for
     * them, because the alternative — a fourth class for two strings — is more
     * moving parts than the thing it would hold.
     */
    private const COLUMNS = [
        [Product::class, 'products', 'image', false],
        [Product::class, 'products', 'images', true],
        [Brand::class, 'brands', 'logo', false],
        [Category::class, 'categories', 'image', false],
        [Post::class, 'posts', 'cover', false],
    ];

    /**
     * Every host the catalogue's image columns point at, most references first.
     *
     * @return list<array{host: string, references: int, own: bool}>
     */
    public function hostsSeen(): array
    {
        $audit = new MediaAudit;
        $counts = [];

        foreach ($this->references() as $reference) {
            $host = parse_url($reference['url'], PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            $host = strtolower($host);
            $counts[$host] = ($counts[$host] ?? 0) + 1;
        }

        arsort($counts);

        $out = [];

        foreach ($counts as $host => $references) {
            $out[] = ['host' => (string) $host, 'references' => $references, 'own' => $audit->isOwnHost((string) $host)];
        }

        return $out;
    }

    /**
     * What a rewrite would do, without doing any of it.
     *
     * @param  list<string>  $hosts  the old site's hosts, named by the owner
     * @return list<array{owner_type: string, table: string, id: int, field: string, from: string, to: string, path: string, decision: string, reason: string}>
     */
    public function propose(array $hosts): array
    {
        $hosts = array_values(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            $hosts,
        ), static fn (string $h): bool => $h !== ''));

        if ($hosts === []) {
            return [];
        }

        $out = [];

        foreach ($this->references() as $reference) {
            $host = parse_url($reference['url'], PHP_URL_HOST);

            if (! is_string($host) || ! in_array(strtolower($host), $hosts, true)) {
                continue;
            }

            $relative = $this->uploadsRelative($reference['url']);

            if ($relative === null) {
                continue;
            }

            $path = $relative;
            $to = Media::urlFor($relative);
            $full = public_path($relative);

            if (! is_file($full)) {
                $out[] = $reference + [
                    'to' => $to,
                    'path' => $path,
                    'decision' => self::ABSENT,
                    'reason' => 'nothing at '.$full.' yet. Copy wp-content/uploads across from the old host first; '
                        .'re-pointing a row at a file that is not there turns a picture that loads into one that '
                        .'does not, and there is no way to see from the shop which rows were touched.',
                ];

                continue;
            }

            if ($to === $reference['url']) {
                $out[] = $reference + [
                    'to' => $to,
                    'path' => $path,
                    'decision' => self::SAME,
                    'reason' => 'already exactly what this would write',
                ];

                continue;
            }

            $out[] = $reference + [
                'to' => $to,
                'path' => $path,
                'decision' => self::REWRITE,
                'reason' => 'the file is under this shop\'s web root, so the row can stop depending on '.$host,
            ];
        }

        return $out;
    }

    /**
     * The other way an image path goes wrong: the shop moved, the paths did not.
     *
     * =========================================================================
     * WHY THIS IS A SECOND ENTRY POINT AND NOT A FLAG ON propose()
     * =========================================================================
     *
     * `products.image` is printed straight into a `src` with no helper, so the
     * value has to carry whatever subfolder the shop is served from. The day
     * `KBB_BASE_PATH` changes — staging at `/kbb-upgrade` going live at the
     * domain root is the case this project will actually hit — every one of
     * those rows is spelled for a folder that is no longer there, and every
     * photograph on the site is a broken frame. No host is involved, so
     * propose() cannot see them: it is filtering on the old shop's hostname.
     *
     * Kept separate rather than folded in with a flag because the two jobs
     * answer to different people. "Take the pictures off WordPress" is a
     * migration step the owner does once, naming a host. "The shop moved" is
     * maintenance, names nothing, and must not be something a migration run can
     * do by accident.
     *
     * SAFE BY THE SAME TWO RULES as the host rewrite: only paths under one of
     * this application's two upload roots are touched, so an address an admin
     * typed by hand is skipped rather than "corrected"; and only where the file
     * is actually on disk.
     *
     * @return list<array{owner_type: string, table: string, id: int, field: string, from: string, to: string, path: string, decision: string, reason: string}>
     */
    public function proposeRebase(): array
    {
        $out = [];

        foreach ($this->references() as $reference) {
            if (parse_url($reference['url'], PHP_URL_HOST) !== null) {
                continue;
            }

            $relative = $this->uploadsRelative($reference['url']);

            if ($relative === null) {
                continue;
            }

            $to = Media::urlFor($relative);

            if ($to === $reference['url']) {
                continue;
            }

            $row = $reference + ['to' => $to, 'path' => $relative];

            $out[] = is_file(public_path($relative))
                ? $row + [
                    'decision' => self::REWRITE,
                    'reason' => 'this path is spelled for a different subfolder from the one this shop is served '
                        .'from today; the file itself is where it should be',
                ]
                : $row + [
                    'decision' => self::ABSENT,
                    'reason' => 'nothing at '.public_path($relative).', so re-spelling the row would not make it '
                        .'load — the uploads folder is not where this shop expects it',
                ];
        }

        return $out;
    }

    /**
     * Apply the rewrites in a proposal. Returns how many rows were changed.
     *
     * One transaction, because a half-applied gallery is a product page with
     * some pictures on one host and some on another and no way to tell which.
     *
     * @param  list<array{owner_type: string, table: string, id: int, field: string, from: string, to: string, path: string, decision: string, reason: string}>  $proposals
     */
    public function apply(array $proposals): int
    {
        $written = 0;

        DB::transaction(function () use ($proposals, &$written): void {
            foreach ($proposals as $proposal) {
                if ($proposal['decision'] !== self::REWRITE) {
                    continue;
                }

                if ($this->replace($proposal['owner_type'], (int) $proposal['id'], $proposal['field'], $proposal['from'], $proposal['to'])) {
                    $written++;
                }
            }
        });

        return $written;
    }

    /**
     * Put a host back in front of every row this would have rewritten.
     *
     * The inverse of apply(), and the reason no ledger table exists. A row is
     * only restored when its current value is byte-identical to what apply()
     * writes for that path, so a row an admin has since re-pointed is left
     * alone — the same rule `kbb:import-redirects --rollback` follows.
     *
     * @return array{restored: int, kept: list<string>}
     */
    public function restore(string $host): array
    {
        $host = trim($host);
        $restored = 0;
        $kept = [];

        if ($host === '') {
            return ['restored' => 0, 'kept' => []];
        }

        $scheme = str_contains($host, '://') ? '' : 'https://';

        DB::transaction(function () use ($host, $scheme, &$restored, &$kept): void {
            foreach ($this->references() as $reference) {
                $current = $reference['url'];

                if (parse_url($current, PHP_URL_HOST) !== null) {
                    continue;
                }

                $relative = $this->uploadsRelative($current);

                if ($relative === null || Media::urlFor($relative) !== $current) {
                    // "settings.og_default_image", not "settings 0.og_…": a
                    // setting has no row id and printing a zero for one is a
                    // number the owner would try to look up.
                    $kept[] = ($reference['id'] === 0
                        ? $reference['table'].'.'.$reference['field']
                        : $reference['table'].' '.$reference['id'].'.'.$reference['field'])
                        .' — "'.$current.'" is not the shape this writes, so somebody else set it';

                    continue;
                }

                $to = rtrim($scheme.$host, '/').'/'.$relative;

                if ($this->replace($reference['owner_type'], (int) $reference['id'], $reference['field'], $current, $to)) {
                    $restored++;
                }
            }
        });

        return ['restored' => $restored, 'kept' => $kept];
    }

    /**
     * @param  list<array{decision: string}>  $rows
     * @return array{rewrite: int, same: int, absent: int}
     */
    public function summarise(array $rows): array
    {
        $out = [self::REWRITE => 0, self::SAME => 0, self::ABSENT => 0];

        foreach ($rows as $row) {
            $out[$row['decision']]++;
        }

        return ['rewrite' => $out[self::REWRITE], 'same' => $out[self::SAME], 'absent' => $out[self::ABSENT]];
    }

    /**
     * The part of a path that is relative to the WEB ROOT, or null.
     *
     * =========================================================================
     * WHY THIS CUTS AT THE UPLOADS ROOT AND NOT AT `Url::base()`
     * =========================================================================
     *
     * `public_path()` IS the subfolder on disk — the web root on this host is
     * `public_html/kbb-upgrade`, not the folder above it — so a stored URL's
     * `/kbb-upgrade` has to come off before the file is looked for. The obvious
     * way to do that is to strip whatever `Url::base()` currently says, and it
     * is wrong in the one case that matters: the base CHANGES. A shop moved
     * from `/kbb-upgrade` to the domain root has 2,600 rows spelled with a
     * prefix that `Url::base()` no longer returns, so nothing would strip it,
     * nothing would recognise them, and every photograph on the site would be a
     * broken frame with no command able to see why.
     *
     * So the cut is made at the thing that does not move: the uploads root.
     * This application has exactly two, and `App\Models\Media::urlFor()` is
     * the place that says so — `wp-content/uploads/` for everything the
     * WordPress import brought across, `uploads/` for everything an admin
     * uploaded. Whatever sits in front of either is a base path, of any
     * vintage, and is discarded.
     *
     * The longer root is tested first, because `wp-content/uploads/…` contains
     * `uploads/` and matching the shorter one first would cut in the middle of
     * it and produce a path that exists nowhere.
     *
     * Null for anything that is under neither root. That is not a failure — it
     * is a value this class has no business rewriting, such as an address an
     * admin typed by hand — and the callers skip it rather than guessing.
     */
    private function uploadsRelative(string $path): ?string
    {
        return self::uploadsRelativeTo($path);
    }

    /**
     * The same answer, reachable without an instance.
     *
     * `DocumentMediaRewrite` has to cut a path at exactly the same place this
     * does, or a picture inside an article would be looked for somewhere a
     * picture on a product is not. One implementation, two callers — the rule
     * this file already follows for `Media::urlFor()`.
     */
    public static function uploadsRelativeTo(string $path): ?string
    {
        $path = ltrim(MediaUsage::normalise($path), '/');

        foreach (['wp-content/uploads/', 'uploads/'] as $root) {
            $at = strpos($path, $root);

            if ($at !== false) {
                return substr($path, $at);
            }
        }

        return null;
    }

    /**
     * Write one value back, scalar column or gallery entry.
     *
     * The gallery is a json list and the entry is matched by VALUE, not by
     * index: `products.images` is reordered by the editor, and an index taken
     * when the proposal was built can name a different photograph by the time
     * it is applied.
     */
    private function replace(string $model, int $id, string $field, string $from, string $to): bool
    {
        /*
         * A SETTING IS NOT A ROW WITH AN id, so it cannot go through the find()
         * below: `settings` is keyed by its `key` column and the value lives in
         * `value`. Same two guarantees as every other write here — the current
         * value must still be byte-identical to what the proposal was built
         * from, and nothing is written when it is not.
         *
         * THE READ IS THE TABLE, NOT THE MAP. `Setting::map()` memoises in a
         * process-level static as well as the cache (CLAUDE.md), so inside one
         * `apply()` the second setting would be compared against a snapshot
         * taken before the first was written. The row is read directly and the
         * memo is dropped afterwards, so the storefront serves the new value on
         * the next request rather than up to five minutes later.
         */
        if ($model === Setting::class) {
            $setting = Setting::query()->find($field);

            if ($setting === null || (string) $setting->value !== $from) {
                return false;
            }

            $setting->value = $to;
            $setting->save();

            Setting::flushMap();

            return true;
        }

        /** @var Product|Brand|Category|null $row */
        $row = $model::query()->find($id);

        if ($row === null) {
            return false;
        }

        if ($field === 'images') {
            $images = (array) ($row->images ?? []);
            $changed = false;

            foreach ($images as $index => $image) {
                if ($image === $from) {
                    $images[$index] = $to;
                    $changed = true;
                }
            }

            if (! $changed) {
                return false;
            }

            $row->images = array_values($images);
            $row->save();

            return true;
        }

        if ((string) $row->{$field} !== $from) {
            return false;
        }

        $row->{$field} = $to;
        $row->save();

        return true;
    }

    /**
     * Every image reference in the catalogue, as a row this can write back to.
     *
     * @return iterable<int, array{owner_type: string, table: string, id: int, field: string, from: string, url: string}>
     */
    private function references(): iterable
    {
        /*
         * THE SETTINGS FIRST, because they are the ones that were missing.
         *
         * `og_default_image` and `org_logo` are pictures with no owning ROW:
         * the storefront publishes both on every page (Seo.php), and nothing in
         * this class could re-point either, so a share image left on the old
         * host stayed there through every "bring these across" the owner ever
         * pressed. `MediaAudit` could not see them either — the pair is fixed
         * together, from the one list, because a picture this can rewrite and
         * the audit cannot see is a rewrite nobody is ever told to make.
         *
         * `App\Support\MediaUsage::SITE_KEYS` is that list. The Media Library's
         * delete guard reads it too, so "which settings hold a picture" has one
         * answer on this shop rather than three.
         *
         * `id` is 0 and is never looked up — settings have no row id, and
         * `MediaUsage` already uses zero for exactly this, consistently. The
         * KEY is the field, which is what replace() writes back to.
         */
        $settings = SeoSettings::map();

        foreach (MediaUsage::SITE_KEYS as $key => $label) {
            $value = $settings[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            yield [
                'owner_type' => Setting::class,
                'table' => 'settings',
                'id' => 0,
                'field' => $key,
                'from' => $value,
                'url' => trim($value),
            ];
        }

        foreach (self::COLUMNS as [$model, $table, $field, $isList]) {
            $column = $field === 'images' ? 'images' : $field;

            foreach ($model::query()->select(['id', $column])->cursor() as $row) {
                $values = $isList ? (array) ($row->{$column} ?? []) : [$row->{$column}];

                foreach ($values as $value) {
                    if (! is_string($value) || trim($value) === '') {
                        continue;
                    }

                    yield [
                        'owner_type' => $model,
                        'table' => $table,
                        'id' => (int) $row->id,
                        'field' => $field,
                        'from' => $value,
                        'url' => trim($value),
                    ];
                }
            }
        }
    }
}
