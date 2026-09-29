<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Menu;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\NavigationService;
use Illuminate\Support\Str;

/**
 * WordPress `nav_menu` terms, out of `menus.csv` and into `menus`.
 *
 * ── THE RULE THAT SHAPES THIS WHOLE CLASS: AN IMPORTED MENU ARRIVES OFF ─────
 *
 * `menus.show_desktop`, `show_mobile` and `show_footer` are the three slots
 * that decide what the storefront actually draws. This importer writes all
 * three FALSE on create and never writes them again.
 *
 * Both halves are load-bearing and both are about the same person:
 *
 *   NEVER MOUNTED ON CREATE, because the owner has been retyping this menu by
 *   hand for weeks. An import that took the header would replace, in one
 *   command and with no undo, the thing he has been building; and it would do
 *   it at the moment an import is most likely to be a rehearsal. The header of
 *   every page on the shop is not a safe thing to change as a side effect. So
 *   the menu lands beside his, as an extra row on Store -> Modules -> Mega
 *   Menu, and he switches it on after he has looked at it.
 *
 *   NEVER WRITTEN AGAIN ON UPDATE, because once he HAS switched it on, a second
 *   import must not switch it back off. A delta pass the week after the cutover
 *   would otherwise take the header down. `show_*` is therefore absent from the
 *   attribute list an existing row is updated with, which is not the same as
 *   writing the current value back: `ImportContext::apply()` reports "updated"
 *   for anything it touches, and a field written back identical every run is a
 *   report that never says "unchanged".
 *
 * That is also what makes CLAUDE.md's first rule true of this entity: importing
 * a menu changes nothing anybody can see until a human moves a switch.
 *
 * ── MATCHED ON source_term_id, WHICH IS WHY A HAND-TYPED MENU IS SAFE ───────
 *
 * `menus.source_term_id` is unique and nullable. Every menu this shop's own
 * screens create -- the demo loader, the seeded kbeautybliss menu, anything the
 * owner typed -- has NULL there, so no query in this importer can ever select
 * one, and nothing here deletes. The import is purely additive with respect to
 * everything that was already in the table.
 *
 * ── WHAT `locations` IS FOR, AND WHY IT IS NOT AN INSTRUCTION ───────────────
 *
 * The export reads `nav_menu_locations` out of the theme's `theme_mods_*`
 * option: the theme's own map of slot => menu. It is the only record of which
 * of four menus WAS the header. It is reported, by name, as a note -- and it is
 * deliberately not acted on, for the reason above. The owner is told which one
 * it was; he is the one who mounts it.
 */
final class MenuImporter extends EntityImporter
{
    public function name(): string
    {
        return 'menus';
    }

    public function conventionalFile(): string
    {
        return 'menus.csv';
    }

    /**
     * Menus that came from WordPress.
     *
     * On `source_term_id`, not on the table: every install ships the seeded
     * kbeautybliss menu, so `SELECT COUNT(*) FROM menus` would report a menu
     * this import did not produce.
     */
    public function countImported(): ?int
    {
        return Menu::query()->whereNotNull('source_term_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $termId = $row->requireId('term_id', 'term_id', 'id', 'menu_id');
        $name = $row->text('name', 'title');
        $report = $context->report->for($this->name());

        if ($name === null) {
            throw RowRejected::because(
                'name is required and this row has none — `menus.name` is NOT NULL and a menu with no name '
                .'cannot be told from another one in the picker on Store → Modules → Mega Menu'
            );
        }

        /*
         * `menus.name` has no length limit in the schema, but the Mega Menu
         * screen validates `max:60` on every save. A longer name would import
         * and then be unsaveable: the owner opens the menu, renames nothing,
         * presses Save and is refused by a rule about a field he never typed.
         */
        $name = $this->fit($name, 60, 'name', $row, $report);

        $menu = Menu::query()->where('source_term_id', $termId)->first();

        $attributes = [
            'source_term_id' => $termId,
            'name' => $name,
        ];

        if ($menu === null) {
            $menu = new Menu;

            /*
             * SLUG ON CREATE ONLY. `menus.slug` is unique and is not shown
             * anywhere; renaming a menu in WordPress must not try to move it,
             * because the slug the shop settled on may already have been taken
             * by something else since.
             */
            $attributes['slug'] = $this->uniqueSlug($row->text('slug') ?? $name);

            // See the class comment: this is the one write of the three slots
            // and it happens exactly once, on creation.
            $attributes['show_desktop'] = false;
            $attributes['show_mobile'] = false;
            $attributes['show_footer'] = false;
        }

        $outcome = $context->apply($menu, $attributes);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $termId, (int) $menu->id);

        $this->reportLocations($row, $name, $report);

        if ($outcome === 'created') {
            $report->note(
                'imported menus arrive SWITCHED OFF and do not replace a menu already here — "'.$name.'" is '
                .'on Store → Modules → Mega Menu, in the menu picker, and shows on the storefront only once '
                .'its Desktop / Mobile / Footer boxes are ticked there'
            );
        }
    }

    /**
     * Which theme slot WordPress had this menu in.
     *
     * A note and not a write. The export carries the theme's own
     * `nav_menu_locations` because it is the only record of which menu was the
     * header, and it is the answer to the only question the owner has when he
     * sees four imported menus in the picker.
     */
    private function reportLocations(Row $row, string $name, \App\Services\Import\EntityReport $report): void
    {
        $locations = $row->list(',', 'locations', 'theme_locations');

        if ($locations === []) {
            return;
        }

        $report->note(
            '"'.$name.'" filled the '.implode(' and ', array_map(
                static fn (string $slot): string => '`'.$slot.'`',
                $locations,
            )).' slot'.(count($locations) === 1 ? '' : 's').' of the WordPress theme — that is what made it '
            .'the header on the old site, and it is the menu to tick Desktop and Mobile against here'
        );
    }

    /**
     * A value cut to what a later screen will accept, with the cut reported.
     *
     * Reported as an ADJUSTMENT rather than refused: a menu with a long name is
     * still the owner's menu, and losing every item in it over its title would
     * be the wrong trade.
     */
    private function fit(string $value, int $limit, string $field, Row $row, \App\Services\Import\EntityReport $report): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $cut = mb_substr($value, 0, $limit);

        $report->adjusted(
            'longer than the admin screen will accept, shortened — Store → Modules → Mega Menu validates '
            .'this field and would refuse to save the row otherwise',
            $row->line,
            $this->identify($row),
            $field,
            $value,
            $cut,
        );

        return $cut;
    }

    /** A slug nothing else holds. `menus.slug` is unique. */
    private function uniqueSlug(string $basis): string
    {
        $slug = Str::slug($basis);

        if ($slug === '') {
            $slug = 'menu';
        }

        $candidate = $slug;
        $n = 2;

        while (Menu::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$n++;
        }

        return $candidate;
    }

    /**
     * The navigation cache, after the menus are in.
     *
     * `NavigationService::menu()` caches each of `kbb.nav.primary`,
     * `kbb.nav.mobile` and `kbb.nav.footer` for FIVE MINUTES, and every admin
     * write in `MegaMenuApiController` already calls `flush()` for exactly this
     * reason. An import is a write like any other.
     *
     * On the first import of a menu this is a no-op by construction — nothing
     * imported is mounted, so no cached slot can be describing it. It matters
     * on the SECOND import, the delta pass onto a menu the owner has since
     * switched on: without this, up to five minutes of every visitor's header
     * is the menu as it was before the import, and the owner refreshes, sees no
     * change, and imports again.
     */
    public function finalise(ImportContext $context): void
    {
        app(NavigationService::class)->flush();
    }
}
