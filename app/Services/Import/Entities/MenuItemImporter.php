<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Post;
use App\Models\Product;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\NavigationService;
use App\Support\SafeUrl;
use App\Support\UrlScheme;

/**
 * WordPress `nav_menu_item` posts, out of `menu_items.csv` and into
 * `menu_items`.
 *
 * =============================================================================
 * A MENU ITEM IS A POINTER, AND RESOLVING IT IS THE WHOLE JOB
 * =============================================================================
 *
 * The export carries WordPress's own three columns verbatim and resolves
 * nothing: `type` ('post_type' | 'taxonomy' | 'custom'), `object` ('product_cat'
 * | 'page' | 'post' | 'product' | 'pa_brands' | …) and `object_id`, the
 * WordPress id of the thing pointed at. None of those is an address, and none
 * of them is a key in this database.
 *
 * ── RESOLVED ON THE ID, NEVER ON THE NAME OF THE TYPE ───────────────────────
 *
 * A WordPress id is unique across every post type AND every taxonomy — terms
 * and posts each have their own sequence, and `type` says which of the two a
 * row means. So the resolution is:
 *
 *   type=taxonomy   -> categories.source_term_id, then brands.source_term_id
 *   type=post_type  -> products.wc_id,            then posts.source_post_id
 *   type=custom     -> the row's own `url`, scheme-checked
 *
 * and `object` is used for the REPORT and for nothing else. That is deliberate
 * and it is the opposite of the obvious design. `BrandImporter`'s header
 * records that this shop's brands are not a brands taxonomy at all — they are
 * terms of the `pa_brands` product ATTRIBUTE, 93 of them — and the taxonomy a
 * given shop keeps its brands in is a setting the export reads rather than a
 * constant. An importer that branched on the string `pa_brands` would place
 * every brand item on the fixture and none on a shop running `product_brand`,
 * `berocket_brand` or `yith_product_brand`, and it would do it silently,
 * because "no brand with that name" and "no brand" look the same from here.
 * The id is exact on every shop.
 *
 * ── AND THE ADDRESS COMES FROM UrlScheme, NEVER FROM A LITERAL ──────────────
 *
 * `App\Support\UrlScheme` is the one place each shape is written down, and its
 * own header names "two menu builders" among the ten writers of
 * `/product-category/` that existed before it. The scheme moved in the last
 * round — categories are `/collections/{path}/` now — and a menu row that spelled
 * a path would be the eleventh writer and the one nobody remembered to move.
 * A CATEGORY item lands on THIS shop's category at THIS shop's address, which
 * means `categories.path` (the cached nesting, `skincare/face-cleansers`) and
 * not the term's own slug.
 *
 * =============================================================================
 * WHAT HAPPENS TO AN ITEM POINTING AT SOMETHING THAT WAS NOT IMPORTED
 * =============================================================================
 *
 * This is the question the whole entity is arranged around, and the fixture has
 * the exact case: "About us" points at WordPress page 7002, and `PostImporter`
 * refuses every WordPress page BY NAME because this shop ships its own
 * `/about/`, `/delivery/`, `/faqs/`, `/privacy-policy/` and
 * `/terms-and-conditions/`. The pointer is perfectly valid; the target is
 * deliberately absent and always will be. The same shape arrives from a post
 * type this shop has no screen for, and from a category the owner ticked out of
 * the export.
 *
 * The two obvious answers are both wrong and they are wrong in opposite
 * directions:
 *
 *   DROP IT SILENTLY loses a row the owner authored. He typed that label, put
 *   it in that position, and the one thing this whole migration exists to avoid
 *   is a row that is in the export and not in the database with nothing saying
 *   so. It is also unrecoverable in the way that matters: after the cutover the
 *   old `wp_posts` is gone and there is nothing to compare against.
 *
 *   KEEP IT AS A DEAD LINK puts a 404 in the header of EVERY PAGE of the shop.
 *   Not one broken page — the navigation is on all of them, and the shopper
 *   finds it by clicking. That is worse than the loss, because it is damage to
 *   pages that were working.
 *
 * ── THE THIRD ANSWER: IMPORTED, PARKED, AND NAMED ──────────────────────────
 *
 * The row is written — label, position, parent, its whole place in the tree —
 * with `url` NULL and `target_type` = `unresolved`, and
 * `NavigationService::tree()` does not render an item in that state. So:
 *
 *   NOTHING IS LOST. The item is in the database and on the Mega Menu screen,
 *   in its right position, with the label the owner typed. Giving it a
 *   destination is one field on a screen that already exists.
 *
 *   NOTHING 404s. It never reaches the header, the drawer or the footer, so the
 *   dead link does not exist to be clicked — not before the owner mounts the
 *   menu and not after.
 *
 *   IT HEALS ITSELF. The gate is `target_type = 'unresolved'` AND no url.
 *   `MegaMenuApiController::update()` writes `url` and does not touch
 *   `target_type`, so the moment the owner types an address on the Mega Menu
 *   screen the item appears — with no second step, no re-import, and nothing
 *   for him to know about this mechanism at all. And because the marker is a
 *   column no screen in this application has ever written, an item an admin
 *   created by hand with no URL — a mega-panel column heading, which is a real
 *   thing — cannot be caught by it. Only rows this importer parked are parked.
 *
 *   AND HE IS TOLD WHICH ONES, BY NAME. Every parked item is an ADJUSTMENT in
 *   the import report — not a discard, because `discarded()` means "in the
 *   export and not in the database", and this row IS in the database. The
 *   sample names what it pointed at (`page 7002 (about-us)`), which is the only
 *   form of the fact that survives the old site being switched off.
 *
 * Re-running the import after importing the missing thing resolves it: the row
 * is matched on `source_post_id`, gets its address, and stops being parked.
 * That is the same repair `order_items.csv` already offers for a line whose
 * product arrived later.
 *
 * ── IDEMPOTENCE, AND THE MENU THE OWNER TYPED ───────────────────────────────
 *
 * Matched on `menu_items.source_post_id`, which is unique and is the WordPress
 * post id unchanged, so a second pass over the same export updates the same
 * rows and creates nothing. Nothing here deletes, and nothing here can even
 * SELECT a row the owner typed: everything his screens create has
 * `source_post_id` NULL.
 *
 * The consequence, stated so it is a decision rather than an omission: an item
 * DELETED in WordPress between two exports is not deleted here. The import is
 * additive by construction, because the alternative — deleting the rows of a
 * menu that are not in the file — cannot tell a deleted item from an item the
 * owner added on this shop, and would throw away his work on the one screen he
 * has been doing it on.
 *
 * `badge`, `icon`, `highlight_color`, `columns` and `visibility` are absent
 * from the attribute list on purpose. WordPress has no such concepts, so
 * writing them would mean writing NULL over whatever the owner set on the Mega
 * Menu screen on every delta pass.
 */
final class MenuItemImporter extends EntityImporter
{
    /**
     * The marker that means "this row has no destination yet".
     *
     * A value no screen in this application has ever written to `target_type`,
     * which is what makes it safe to gate rendering on — see the class comment.
     */
    public const UNRESOLVED = 'unresolved';


    public function name(): string
    {
        return 'menu-items';
    }

    public function conventionalFile(): string
    {
        return 'menu_items.csv';
    }

    public function countImported(): ?int
    {
        return MenuItem::query()->whereNotNull('source_post_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $id = $row->requireId('id', 'id', 'post_id', 'item_id', 'ID');
        $report = $context->report->for($this->name());

        $menuTermId = $row->requireId('menu_term_id', 'menu_term_id', 'menu_id', 'term_id');
        $menuId = $context->localId('menus', $menuTermId);

        if ($menuId === null) {
            throw RowRejected::because(
                'no menu with source_term_id '.$menuTermId.' — import menus.csv before menu_items.csv. '
                .'`menu_items.menu_id` is NOT NULL and an item belonging to no menu is a row nothing can '
                .'ever show or find'
            );
        }

        $label = $row->text('label', 'title', 'name');

        if ($label === null) {
            /*
             * The export resolves the label off the target when the item
             * carries none, so a blank one here means the target is gone from
             * WordPress too — a menu item pointing at a deleted post, which
             * WordPress itself draws as an empty row. `menu_items.label` is NOT
             * NULL, and an item with no label is not a thing the owner can
             * recognise on the Mega Menu screen to repair.
             */
            throw RowRejected::because(
                'label is required and this row has none — `menu_items.label` is NOT NULL. The export fills '
                .'it from the item\'s own title or, failing that, from the name of whatever it points at '
                .'(`label_source` says which), so an empty one means the target is missing in WordPress as '
                .'well and this item draws as a blank row there too'
            );
        }

        $label = $this->fit($label, 60, 'label', $row, $report);

        $item = MenuItem::query()->where('source_post_id', $id)->first() ?? new MenuItem;

        $status = mb_strtolower($row->text('status', 'post_status') ?? 'publish');

        /*
         * A DRAFT MENU ITEM IS PARKED RATHER THAN REFUSED OR PUBLISHED.
         *
         * `menu_items` has no status column, so the three choices are: refuse
         * the row (lose something half-typed that the owner may be in the
         * middle of), import it as live (publish, on his header, work he had
         * not finished), or park it — which is the same answer this entity
         * already gives an item with nowhere to point, for the same reason. It
         * is in the database and on the Mega Menu screen, and it is not in the
         * header until he says so.
         *
         * The export carries `draft` deliberately: dropping it there would make
         * the export's count of a menu disagree with the count WordPress shows
         * on Appearance → Menus, which is the number the owner checks against.
         */
        $destination = $status === 'publish'
            ? $this->resolve($row, $label, $report)
            : $this->park($row, $label, 'draft', '', null, $report, quiet: true);

        if ($status !== 'publish') {
            $report->adjusted(
                'a menu item WordPress had not published — imported, and held out of the header and the '
                .'drawer until you publish it here, because `menu_items` has no draft state and importing '
                .'it live would put unfinished work on the shop. It is on Store → Modules → Mega Menu',
                $row->line,
                $this->identify($row),
                'status',
                $status,
                '(parked — not rendered)',
            );
        }

        $attributes = [
            'source_post_id' => $id,
            'menu_id' => $menuId,
            'label' => $label,
            'url' => $destination['url'],
            'target_type' => $destination['target_type'],
            'target_id' => $destination['target_id'],
            'position' => $row->int(0, 'position', 'menu_order', 'order'),
            /*
             * AN INT AND NOT A BOOL, and it is the difference between an
             * idempotent import and one that rewrites every row forever.
             * `menu_items.new_tab` has no cast on the model, so the value read
             * back from either engine is the integer 0. Laravel's dirty check
             * compares with `!==` for anything non-numeric, and `is_numeric
             * (false)` is false -- so writing the bool made every row dirty on
             * every pass, and the report said "updated" for 7 rows that had not
             * changed. That report is the only evidence this import is
             * idempotent; one that never says "unchanged" is evidence of
             * nothing. MEASURED: 7 updated, 0 unchanged, on a second pass over
             * a byte-identical export.
             */
            'new_tab' => $this->newTab($row) ? 1 : 0,
        ];

        $parentSourceId = $row->id('parent_id', 'parent_id', 'parent', 'menu_item_parent');

        /*
         * ── THE PARENT IS KEPT ON THE ROW, NOT IN A PROPERTY ────────────────
         *
         * `_menu_item_menu_item_parent` is a nav_menu_item POST id, so it has
         * to be translated, and a child often arrives before its parent.
         * CategoryImporter holds the unresolved ones in an instance array and
         * fixes them in finalise(); that cannot work here, because **a batch is
         * a separate HTTP request**. Store → Import steps this entity a slice at
         * a time and ImportRunner calls finalise() on EVERY call — so a slice
         * holding the child and not the parent would resolve nothing, clear its
         * array, and lose the link for good.
         *
         * Measured rather than reasoned: the sliced run left a child at the top
         * level where the single-pass run nested it, and on the owner's server
         * every import is sliced. `menu_items.source_parent_post_id` is what
         * lets finalise() repair from the DATABASE instead of from memory.
         */
        $attributes['source_parent_post_id'] = $parentSourceId;
        $attributes['parent_id'] = $parentSourceId === null
            ? null
            : $this->localItemId($context, $parentSourceId);

        /*
         * A parent that is not here YET must not null a link an earlier run
         * already made — that would be this defect with an extra step. Left out
         * of the write entirely, and repaired by finalise().
         */
        if ($parentSourceId !== null && $attributes['parent_id'] === null) {
            unset($attributes['parent_id']);
        }

        $outcome = $context->apply($item, $attributes);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $id, (int) $item->id);
    }

    /**
     * Where this item points on THIS shop.
     *
     * @return array{url: string|null, target_type: string, target_id: int|null}
     */
    private function resolve(Row $row, string $label, \App\Services\Import\EntityReport $report): array
    {
        $type = mb_strtolower($row->text('type', 'menu_item_type') ?? 'custom');
        $object = mb_strtolower($row->text('object', 'menu_item_object') ?? '');
        $objectId = $row->id('object_id', 'object_id', 'menu_item_object_id');

        /*
         * ── THE SHOP ARCHIVE, AND WHY THIS ONE BRANCHES ON A NAME ──────────
         *
         * WordPress's `post_type_archive` item is the "Shop" entry on nearly
         * every WooCommerce header, and it is the one pointer in this export
         * that HAS NO ID TO RESOLVE ON. `_menu_item_object_id` is 0 for an
         * archive -- there is no post and no term behind it, only a post TYPE
         * -- so it fell through to custom(), which found no `_menu_item_url`
         * either, and parked the row. The shop's own front door arrived on the
         * Mega Menu screen with no address on it.
         *
         * THE CLASS HEADER SAYS "RESOLVED ON THE ID, NEVER ON THE NAME OF THE
         * TYPE", and this is the exception that rule could not have covered.
         * That rule exists because a TAXONOMY name is a fact about one shop's
         * plugins -- this shop keeps brands in the `pa_brands` ATTRIBUTE, and
         * an importer branching on that string would place nothing on a shop
         * running `product_brand`. `product` is not that kind of name: it is
         * WooCommerce's own post type, registered by the plugin itself and the
         * same four letters on every WooCommerce installation there has ever
         * been. There is no id to prefer, and no shop for the name to be wrong
         * on.
         *
         * NARROW ON PURPOSE. Only `product` resolves. An archive of some other
         * post type -- a `portfolio`, an `event` -- is a listing this shop has
         * no screen for, so it keeps parking, which is the honest answer.
         */
        if ($type === 'post_type_archive' && $object === 'product') {
            return [
                'url' => UrlScheme::shop(),
                'target_type' => 'shop',
                'target_id' => null,
            ];
        }

        if ($type === 'custom' || $objectId === null) {
            return $this->custom($row, $label, $report);
        }

        if ($type === 'taxonomy') {
            $category = Category::query()->where('source_term_id', $objectId)->first();

            if ($category !== null) {
                return [
                    // `path`, not `slug`: the address of a nested category is
                    // its whole path, and CategoryImporter caches it in that
                    // column precisely so nothing has to walk the tree to
                    // answer this.
                    'url' => UrlScheme::collection((string) ($category->path ?? $category->slug)),
                    'target_type' => 'category',
                    'target_id' => (int) $category->id,
                ];
            }

            $brand = Brand::query()->where('source_term_id', $objectId)->first();

            if ($brand !== null) {
                return [
                    'url' => UrlScheme::brand((string) $brand->slug),
                    'target_type' => 'brand',
                    'target_id' => (int) $brand->id,
                ];
            }
        }

        if ($type === 'post_type') {
            $product = Product::query()->where('wc_id', $objectId)->first();

            if ($product !== null) {
                return [
                    'url' => UrlScheme::product((string) $product->slug),
                    'target_type' => 'product',
                    'target_id' => (int) $product->id,
                ];
            }

            $post = Post::query()->where('source_post_id', $objectId)->first();

            if ($post !== null) {
                return [
                    'url' => UrlScheme::article((string) $post->slug),
                    'target_type' => 'article',
                    'target_id' => (int) $post->id,
                ];
            }
        }

        return $this->park($row, $label, $type, $object, $objectId, $report);
    }

    /**
     * A hand-typed address, checked the way the storefront will check it.
     *
     * `SafeUrl::href()` is the gate `NavigationService::tree()` already puts
     * every menu URL through — the class comment there names the WordPress
     * import as the reason it exists — so a `javascript:` row would render as
     * `/` whatever this importer stored. Refusing it HERE as well means the
     * owner is told, on the screen where he can act, rather than finding a menu
     * item that silently goes to the homepage.
     *
     * @return array{url: string|null, target_type: string, target_id: int|null}
     */
    private function custom(Row $row, string $label, \App\Services\Import\EntityReport $report): array
    {
        $raw = $row->text('url', 'menu_item_url', 'link');

        if ($raw === null) {
            return $this->park($row, $label, 'custom', '', null, $report);
        }

        /*
         * A path, a query or a fragment — `/super-sale/`, `#contact` — is not a
         * scheme and SafeUrl passes it through untouched. Only something
         * carrying a scheme this shop will not follow comes back refused.
         */
        if (SafeUrl::href($raw, '') === '') {
            $report->adjusted(
                'a menu address this shop will not follow — the storefront turns anything but http, https, '
                .'mailto and tel into a link to the home page, so it is not stored as one',
                $row->line,
                $this->identify($row),
                'url',
                $raw,
                '(parked — no address)',
            );

            return $this->park($row, $label, 'custom', '', null, $report, quiet: true);
        }

        /*
         * `menu_items.url` is varchar(255). MySQL REFUSES a longer value with
         * SQLSTATE 22001 and SQLite silently keeps it, so this would be green
         * on the suite and fatal on the owner's server, mid-import, on one row
         * of a menu. Cut and reported: the address is wrong either way, but a
         * cut one is visible on the Mega Menu screen and a refused row takes
         * the whole batch down.
         */
        $url = $this->fit($raw, 255, 'url', $row, $report);

        return ['url' => $url, 'target_type' => 'custom', 'target_id' => null];
    }

    /**
     * Imported, and held out of the rendered menu until it has a destination.
     *
     * See the class comment for why this is the answer and not one of the two
     * obvious ones.
     *
     * @return array{url: null, target_type: string, target_id: null}
     */
    private function park(
        Row $row,
        string $label,
        string $type,
        string $object,
        ?int $objectId,
        \App\Services\Import\EntityReport $report,
        bool $quiet = false,
    ): array {
        if (! $quiet) {
            $slug = $row->text('object_slug');

            /*
             * `label_source` = 'object' means the owner never typed this label
             * — WordPress was printing the target's own name. Worth saying,
             * because it changes what he does about it: a label he typed is one
             * he wants kept, and one WordPress supplied is one he may prefer to
             * replace along with the address.
             */
            $whose = $row->text('label_source') === 'object'
                ? ' (WordPress\'s own name for it, not a label you typed)'
                : '';

            $pointed = $objectId === null
                ? 'nothing this shop can place ('.($type === '' ? 'no type' : $type).')'
                : ($object === '' ? $type : $object).' '.$objectId.($slug === null ? '' : ' ('.$slug.')');

            $report->adjusted(
                /*
                 * ONE KIND FOR THE WHOLE CLASS, with the item in the SAMPLE —
                 * PostImporter's own precedent. A kind naming the label would
                 * produce one kind per item, each with a count of 1, which is
                 * the shape that makes a report unreadable and therefore
                 * unread.
                 */
                'a menu item pointing at something this shop did not import — the item is HERE, with its '
                .'label and its position, and is held out of the header and the drawer until it has an '
                .'address, so nothing is lost and nothing is a dead link. Give it one on Store → Modules → '
                .'Mega Menu, or import the thing it points at and run this file again',
                $row->line,
                $this->identify($row),
                'url',
                $label.$whose.' → '.$pointed,
                '(parked — no address)',
            );
        }

        return ['url' => null, 'target_type' => self::UNRESOLVED, 'target_id' => null];
    }

    /** WordPress's `_menu_item_target`, which is `_blank` or nothing. */
    private function newTab(Row $row): bool
    {
        return mb_strtolower((string) ($row->text('target', 'menu_item_target') ?? '')) === '_blank';
    }

    /**
     * The local id of a menu item, from this run's map or from the database.
     *
     * The database half is what makes a RESUMED import work: the map is per
     * run, and a parent written by the run before this one is not in it.
     */
    private function localItemId(ImportContext $context, int $sourcePostId): ?int
    {
        $known = $context->localId($this->name(), $sourcePostId);

        if ($known !== null) {
            return $known;
        }

        $id = MenuItem::query()->where('source_post_id', $sourcePostId)->value('id');

        if ($id === null) {
            return null;
        }

        $context->remember($this->name(), $sourcePostId, (int) $id);

        return (int) $id;
    }

    /** @see MenuImporter::fit() — same rule, same reason. */
    private function fit(string $value, int $limit, string $field, Row $row, \App\Services\Import\EntityReport $report): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $cut = mb_substr($value, 0, $limit);

        $report->adjusted(
            'longer than this column and the Mega Menu screen will hold, shortened — MySQL refuses an '
            .'over-long value outright (SQLite does not, which is why this is caught here and not by the '
            .'test suite), and the admin screen would refuse to save the row afterwards',
            $row->line,
            $this->identify($row),
            $field,
            $value,
            $cut,
        );

        return $cut;
    }

    /**
     * Link the parents that arrived after their children, then flush the nav.
     *
     * A SET OPERATION OVER THE WHOLE TABLE, in two queries, and deliberately
     * not over "the rows this call touched". A slice that imported only the
     * parent has to be able to repair a child imported by the slice before it,
     * and it knows nothing about that child — the `source_parent_post_id`
     * column is the whole point. Idempotent and cheap: a menu is tens of rows,
     * and CategoryImporter recomputes its whole tree on the same reasoning.
     */
    public function finalise(ImportContext $context): void
    {
        $rows = MenuItem::query()
            ->whereNotNull('source_parent_post_id')
            ->get(['id', 'menu_id', 'source_post_id', 'source_parent_post_id', 'parent_id']);

        if ($rows->isNotEmpty()) {
            $localBySource = MenuItem::query()
                ->whereIn('source_post_id', $rows->pluck('source_parent_post_id')->unique()->all())
                ->pluck('id', 'source_post_id');

            $orphans = [];

            foreach ($rows as $child) {
                $parentId = $localBySource[(int) $child->source_parent_post_id] ?? null;

                if ($parentId === null) {
                    $orphans[] = (int) $child->source_parent_post_id;

                    continue;
                }

                if ((int) $parentId === (int) $child->id) {
                    $context->report->for($this->name())->note(
                        'menu item '.$child->source_post_id.' lists itself as its own parent; imported at '
                        .'the top level'
                    );

                    continue;
                }

                if ((int) $parentId !== (int) $child->parent_id) {
                    MenuItem::query()->whereKey($child->id)->update(['parent_id' => $parentId]);
                }
            }

            $orphans = array_values(array_unique($orphans));

            if ($orphans !== []) {
                $context->report->for($this->name())->note(
                    count($orphans).' menu item'.(count($orphans) === 1 ? ' is' : 's are').' named as a '
                    .'parent and '.(count($orphans) === 1 ? 'is' : 'are').' not in this export (WordPress '
                    .'id'.(count($orphans) === 1 ? ' ' : 's ').implode(', ', array_slice($orphans, 0, 10))
                    .'); their children were imported at the top level of their menu'
                );
            }
        }

        // See MenuImporter::finalise(). The five-minute `kbb.nav.*` cache is
        // what a second import onto a mounted menu would otherwise sit behind.
        app(NavigationService::class)->flush();
    }
}
