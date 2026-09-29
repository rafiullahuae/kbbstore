<?php
/**
 * `menus.csv` and `menu_items.csv` -- the navigation, which is the last thing a
 * WordPress shop carries that no export had ever read.
 *
 * ── WHY THIS IS THE LAST UNFILLED SLOT AND NOT A NICE-TO-HAVE ───────────────
 *
 * docs/IE-IMPORT-READINESS.md §2.3: the navigation "is the header and the
 * mobile drawer -- which categories, in which order, under which names", and
 * until this stage existed the whole of it was re-entered by hand on cutover
 * night. The shop's own `menus` and `menu_items` tables have carried
 * `source_term_id` and `source_post_id` since the first schema migration,
 * waiting for exactly this; §7 of that document sweeps every `source_*` column
 * in the schema and finds no third gap of this shape behind it.
 *
 * ── HOW WORDPRESS STORES A MENU, WHICH IS WHY THIS IS TWO FILES ─────────────
 *
 * A menu is a TERM in the `nav_menu` taxonomy. Its items are POSTS of type
 * `nav_menu_item`, joined to that term through `term_relationships`, each
 * carrying what it points at in postmeta:
 *
 *   _menu_item_type              'post_type' | 'taxonomy' | 'custom' | 'post_type_archive'
 *   _menu_item_object            'page' | 'post' | 'product' | 'product_cat' | 'pa_brands' | 'custom'
 *   _menu_item_object_id         the WordPress id of the thing pointed at
 *   _menu_item_menu_item_parent  the nav_menu_item post id of the parent, or 0
 *   _menu_item_url               the address, for a 'custom' item and only then
 *   _menu_item_target            '_blank', or empty
 *   _menu_item_classes           a serialised array of CSS classes
 *
 * Two tables on the old site, two tables on the new one, so two files. One file
 * with a `type` column -- posts.csv's shape -- would be wrong here: a menu and
 * an item share no columns at all, where a post and a page differ by one.
 *
 * ── THE LABEL IS NOT ALWAYS THE MENU ITEM'S OWN TITLE ───────────────────────
 *
 * MEASURED ON THE FIXTURE, not anticipated. WordPress writes `post_title` on a
 * nav_menu_item only when the owner TYPES a label over the default; leave the
 * box alone and the row's title is the empty string, and the theme prints the
 * target's own name instead. So an export that emitted `post_title` would carry
 * an empty label for every item the owner never renamed -- which is most of
 * them -- and `menu_items.label` on the new shop is NOT NULL.
 *
 * The fallback is resolved HERE, against the object the item points at, because
 * this is the only side of the pipe that still has the object: the new shop
 * refuses a WordPress page by name, so the importer could not look up the title
 * of the one item that most needs it. `label_source` says which of the two a
 * row's label came from, so the import report can tell the owner "this is
 * WordPress's own word for it" rather than pretending he typed it.
 *
 * ── AND WHICH SLOT THE MENU OCCUPIED ────────────────────────────────────────
 *
 * `theme_mods_<stylesheet>` holds `nav_menu_locations`: the theme's own map of
 * location slug => menu term id. It is what makes one of four menus THE header.
 * It is exported as a `locations` column and it is NOT an instruction: the
 * importer deliberately does not mount an imported menu (see MenuImporter).
 * It is how the owner is told which of his menus was the header, in his own
 * theme's words, on the screen where he switches it on.
 */

defined( 'ABSPATH' ) || exit;

/**
 * `menus.csv` -- one row per `nav_menu` term.
 */
class KBB_Export_Stage_Menus extends KBB_Export_Stage {

	/** The taxonomy WordPress keeps menus in. Core, not a setting. */
	const TAXONOMY = 'nav_menu';

	public function file() {
		return 'menus.csv';
	}

	/**
	 * Exactly what App\Services\Import\Entities\MenuImporter asks a Row for,
	 * plus `locations` and `count`, which are for the report rather than for a
	 * column.
	 */
	public function columns() {
		return array( 'term_id', 'name', 'slug', 'description', 'locations', 'count' );
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'term_taxonomy WHERE taxonomy = '
				. KBB_Export_Wp::quote( self::TAXONOMY )
		);
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT t.term_id, t.name, t.slug, tt.description, tt.count
			 FROM ' . $wpdb->prefix . 'term_taxonomy tt
			 JOIN ' . $wpdb->prefix . 'terms t ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = ' . KBB_Export_Wp::quote( self::TAXONOMY ) . '
			   AND t.term_id > ' . (int) $cursor . '
			 ORDER BY t.term_id
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$locations = self::locations();
		$out       = array();
		$last      = (int) $cursor;

		foreach ( $rows as $row ) {
			$id   = (int) $row['term_id'];
			$last = $id;

			$out[] = array(
				'term_id'     => $id,
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'description' => $row['description'],
				'locations'   => isset( $locations[ $id ] ) ? $this->commas( $locations[ $id ] ) : '',
				'count'       => (int) $row['count'],
			);
		}

		return array( 'rows' => $out, 'cursor' => $last, 'done' => count( $rows ) < (int) $limit );
	}

	/**
	 * menu term id => the theme locations it fills.
	 *
	 * EVERY `theme_mods_*` OPTION, not only the active theme's. A shop that
	 * switched themes last year still has the old theme's assignment sitting in
	 * the options table, and the owner recognises "primary" from whichever theme
	 * he set it in. Reading only the live one would silently answer "no
	 * location" for a menu that has been the header for six years, on a shop
	 * whose current theme happens to register its slots under other names.
	 *
	 * @return array<int,array<int,string>>
	 */
	private static function locations() {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT option_name, option_value FROM ' . $wpdb->prefix . "options
			 WHERE option_name LIKE 'theme\\_mods\\_%' ORDER BY option_name",
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$mods = maybe_unserialize( $row['option_value'] );

			if ( ! is_array( $mods ) || empty( $mods['nav_menu_locations'] ) || ! is_array( $mods['nav_menu_locations'] ) ) {
				continue;
			}

			foreach ( $mods['nav_menu_locations'] as $slot => $term_id ) {
				$term_id = (int) $term_id;

				if ( $term_id <= 0 || ! is_string( $slot ) || '' === $slot ) {
					continue;
				}

				if ( ! isset( $out[ $term_id ] ) ) {
					$out[ $term_id ] = array();
				}

				if ( ! in_array( $slot, $out[ $term_id ], true ) ) {
					$out[ $term_id ][] = $slot;
				}
			}
		}

		return $out;
	}
}

/**
 * `menu_items.csv` -- one row per `nav_menu_item` post that belongs to a menu.
 */
class KBB_Export_Stage_Menu_Items extends KBB_Export_Stage {

	const POST_TYPE = 'nav_menu_item';

	/**
	 * Statuses that are a menu item somebody can see.
	 *
	 * `draft` is here and is NOT a mistake: WordPress leaves a half-added item
	 * as a draft, and dropping it would mean the export's count of the menu
	 * disagrees with the count WordPress shows on Appearance -> Menus. The
	 * status travels on the row and the importer decides.
	 */
	const STATUSES = array( 'publish', 'draft' );

	public function file() {
		return 'menu_items.csv';
	}

	/**
	 * What App\Services\Import\Entities\MenuItemImporter asks a Row for.
	 *
	 * `object_slug` is not used to resolve anything -- the importer resolves on
	 * the WordPress ID, which is unique across every post type and every
	 * taxonomy, so the NAME of the type is never load-bearing. It is here so
	 * that an item the new shop cannot place is reported as
	 * `page 7002 (about-us)` rather than as a bare number the owner would have
	 * to go back to WordPress to identify -- and on cutover night WordPress is
	 * off.
	 */
	public function columns() {
		return array(
			'id', 'menu_term_id', 'parent_id', 'position', 'label', 'label_source',
			'type', 'object', 'object_id', 'object_slug', 'url', 'target', 'classes',
			'description', 'status',
		);
	}

	/**
	 * The join that says "this item belongs to a menu".
	 *
	 * An orphaned nav_menu_item -- one whose term relationship was deleted and
	 * whose post row was not -- is real, common on a long-lived shop, and
	 * belongs to no menu. WordPress does not render it, so neither does this
	 * export carry it: a row with no `menu_term_id` has nowhere to land.
	 */
	private function from_where() {
		global $wpdb;

		$states = implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), self::STATUSES ) );

		return ' FROM ' . $wpdb->prefix . 'posts p
			 JOIN ' . $wpdb->prefix . 'term_relationships tr ON tr.object_id = p.ID
			 JOIN ' . $wpdb->prefix . 'term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE p.post_type = ' . KBB_Export_Wp::quote( self::POST_TYPE ) . '
			   AND p.post_status IN (' . $states . ')
			   AND tt.taxonomy = ' . KBB_Export_Wp::quote( KBB_Export_Stage_Menus::TAXONOMY );
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*)' . $this->from_where() );
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT p.ID, p.post_title, p.post_content, p.post_status, p.menu_order, tt.term_id'
				. $this->from_where() . ' AND p.ID > ' . (int) $cursor . '
			 ORDER BY p.ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			$this->report_totals();

			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids  = $this->ids_of( $rows );
		$meta = KBB_Export_Wp::post_meta(
			$ids,
			array(
				'_menu_item_type', '_menu_item_object', '_menu_item_object_id',
				'_menu_item_menu_item_parent', '_menu_item_url', '_menu_item_target',
				'_menu_item_classes',
			)
		);

		$targets = $this->targets( $rows, $meta );

		$out = array();

		foreach ( $rows as $row ) {
			$id  = (int) $row['ID'];
			$m   = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$key = $this->target_key( $m );

			$typed  = isset( $m['_menu_item_type'] ) ? (string) $m['_menu_item_type'] : 'custom';
			$title  = trim( (string) $row['post_title'] );
			$target = isset( $targets[ $key ] ) ? $targets[ $key ] : array( 'title' => '', 'slug' => '' );

			$label = '' !== $title ? $title : (string) $target['title'];

			$out[] = array(
				'id'           => $id,
				'menu_term_id' => (int) $row['term_id'],
				'parent_id'    => isset( $m['_menu_item_menu_item_parent'] ) ? (int) $m['_menu_item_menu_item_parent'] : 0,
				'position'     => (int) $row['menu_order'],
				'label'        => $label,
				'label_source' => '' !== $title ? 'item' : ( '' !== $label ? 'object' : 'none' ),
				'type'         => $typed,
				'object'       => isset( $m['_menu_item_object'] ) ? (string) $m['_menu_item_object'] : '',
				'object_id'    => isset( $m['_menu_item_object_id'] ) ? (int) $m['_menu_item_object_id'] : 0,
				'object_slug'  => (string) $target['slug'],
				'url'          => isset( $m['_menu_item_url'] ) ? (string) $m['_menu_item_url'] : '',
				'target'       => isset( $m['_menu_item_target'] ) ? (string) $m['_menu_item_target'] : '',
				'classes'      => $this->classes( isset( $m['_menu_item_classes'] ) ? $m['_menu_item_classes'] : '' ),
				'description'  => (string) $row['post_content'],
				'status'       => (string) $row['post_status'],
			);
		}

		$done = count( $rows ) < (int) $limit;

		if ( $done ) {
			$this->report_totals();
		}

		return array( 'rows' => $out, 'cursor' => (int) end( $ids ), 'done' => $done );
	}

	/** 'taxonomy:15' / 'post_type:7002', or '' for a custom item. */
	private function target_key( array $meta ) {
		$type = isset( $meta['_menu_item_type'] ) ? (string) $meta['_menu_item_type'] : '';
		$id   = isset( $meta['_menu_item_object_id'] ) ? (int) $meta['_menu_item_object_id'] : 0;

		if ( $id <= 0 || ( 'taxonomy' !== $type && 'post_type' !== $type ) ) {
			return '';
		}

		return $type . ':' . $id;
	}

	/**
	 * The title and slug of everything this batch points at, in two queries.
	 *
	 * Two, and not one per item: a header with a brand column is sixty items,
	 * and `get_the_title()` per row is sixty round trips to fetch sixty
	 * strings -- on a shared host inside a 110-second request that already has
	 * an export to finish.
	 *
	 * @return array<string,array{title:string,slug:string}>
	 */
	private function targets( array $rows, array $meta ) {
		global $wpdb;

		$posts = array();
		$terms = array();

		foreach ( $rows as $row ) {
			$m  = isset( $meta[ (int) $row['ID'] ] ) ? $meta[ (int) $row['ID'] ] : array();
			$id = isset( $m['_menu_item_object_id'] ) ? (int) $m['_menu_item_object_id'] : 0;

			if ( $id <= 0 ) {
				continue;
			}

			$type = isset( $m['_menu_item_type'] ) ? (string) $m['_menu_item_type'] : '';

			if ( 'post_type' === $type ) {
				$posts[ $id ] = $id;
			} elseif ( 'taxonomy' === $type ) {
				$terms[ $id ] = $id;
			}
		}

		$out = array();

		if ( ! empty( $posts ) ) {
			$found = $wpdb->get_results(
				'SELECT ID, post_title, post_name FROM ' . $wpdb->prefix . 'posts
				 WHERE ID IN (' . implode( ',', array_map( 'intval', $posts ) ) . ')',
				ARRAY_A
			);

			foreach ( (array) $found as $row ) {
				$out[ 'post_type:' . (int) $row['ID'] ] = array(
					'title' => (string) $row['post_title'],
					'slug'  => (string) $row['post_name'],
				);
			}
		}

		if ( ! empty( $terms ) ) {
			$found = $wpdb->get_results(
				'SELECT term_id, name, slug FROM ' . $wpdb->prefix . 'terms
				 WHERE term_id IN (' . implode( ',', array_map( 'intval', $terms ) ) . ')',
				ARRAY_A
			);

			foreach ( (array) $found as $row ) {
				$out[ 'taxonomy:' . (int) $row['term_id'] ] = array(
					'title' => (string) $row['name'],
					'slug'  => (string) $row['slug'],
				);
			}
		}

		return $out;
	}

	/**
	 * `_menu_item_classes` is a serialised ARRAY, usually of one empty string.
	 *
	 * Emitting the serialised blob would put `a:1:{i:0;s:0:"";}` in a CSV cell
	 * and make the importer parse PHP serialisation off an untrusted file. It
	 * comes out as a comma list, which is what every other list cell in this
	 * export already is.
	 */
	private function classes( $raw ) {
		$value = maybe_unserialize( $raw );

		if ( is_string( $value ) ) {
			$value = array( $value );
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$flat = array();

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$flat[] = (string) $item;
			}
		}

		return $this->commas( $flat );
	}

	/**
	 * What this export carries, stated as a count the owner can check against
	 * Appearance -> Menus.
	 *
	 * The counterpart to the note the posts stage used to print. Until 1.7.0
	 * that note said THE NAVIGATION MENU IS NOT IN THIS EXPORT AND HAS TO BE
	 * RE-ENTERED BY HAND, with the count of how much retyping; the whole point
	 * of this stage is that the sentence is no longer true, and a note that
	 * outlives the gap it described is worse than no note at all.
	 */
	private function report_totals() {
		global $wpdb;

		$items = $this->total();

		if ( 0 === $items ) {
			return;
		}

		$menus = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'term_taxonomy WHERE taxonomy = '
				. KBB_Export_Wp::quote( KBB_Export_Stage_Menus::TAXONOMY )
		);

		$orphans = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'posts p
			 WHERE p.post_type = ' . KBB_Export_Wp::quote( self::POST_TYPE ) . '
			   AND p.ID NOT IN (
				SELECT tr.object_id FROM ' . $wpdb->prefix . 'term_relationships tr
				JOIN ' . $wpdb->prefix . 'term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = ' . KBB_Export_Wp::quote( KBB_Export_Stage_Menus::TAXONOMY ) . '
			   )'
		);

		$note = 'THE NAVIGATION IS IN THIS EXPORT: ' . $items . ' menu item' . ( 1 === $items ? '' : 's' )
			. ' across ' . $menus . ' menu' . ( 1 === $menus ? '' : 's' ) . ', in menus.csv and '
			. 'menu_items.csv. Each item names what it points at by its WordPress id, and the new shop '
			. 'resolves that id against what it imported -- so run the navigation AFTER the catalogue and '
			. 'the articles, or the items that point at them cannot be placed. An imported menu arrives '
			. 'switched OFF on the new shop and does not replace a menu already there; it is switched on '
			. 'from Store -> Modules -> Mega Menu.';

		if ( $orphans > 0 ) {
			$note .= ' ' . $orphans . ' further nav_menu_item row'
				. ( 1 === $orphans ? ' belongs' : 's belong' ) . ' to no menu at all and '
				. ( 1 === $orphans ? 'is' : 'are' ) . ' not exported: WordPress does not render '
				. ( 1 === $orphans ? 'it' : 'them' ) . ' either.';
		}

		$this->note( $note );
	}
}
