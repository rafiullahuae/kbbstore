<?php
/**
 * `products.csv` and `variations.csv`.
 *
 * ── EVERY COLUMN IN products.csv WAS READ OUT OF ProductImporter ────────────
 *
 * Not out of WooCommerce's documentation, which is what the contract requires.
 * The mapping, alias by alias, is in docs/GE-WP-EXPORTER.md; what is worth
 * having here are the four places where emitting the obvious thing would have
 * been wrong.
 *
 * 1. THE GALLERY IS PIPE-SEPARATED. ProductImporter chooses its separator by
 *    looking -- pipe wins where it appears, comma otherwise -- and its own
 *    comment records what the comma cost: "every multi-image product imported
 *    with its whole gallery as a single entry ... The product page then renders
 *    one broken image instead of the four that were exported, and the import
 *    report says 'created' either way." A URL cannot contain a bare `|` and a
 *    filename can contain a comma, so the pipe is the branch that cannot be
 *    ambiguous. The comma branch is left to exports this plugin did not write.
 *
 * 2. THE SLUG IS ALWAYS EMITTED, even though Str::slug($name) would usually
 *    produce the same thing. ProductImporter: "When the export carries no slug
 *    column ... this line invents one with Str::slug($name), which is a
 *    transliteration, not a copy ... turns 'مرطب الوجه — Creme Hydratante 保湿'
 *    into 'mrtb-alogh-creme-hydratante'." On a shop selling Korean cosmetics
 *    with Arabic titles that is not a corner case, and the cost is a 404 on
 *    every product Google holds. `post_name` is always there in `wp_posts`; not
 *    writing it would be inventing the problem.
 *
 * 3. THE TRASH IS FILTERED, BY THE IMPORTER'S OWN INSTRUCTION. ProductImporter
 *    refuses `status = trash` with "empty the trash or filter the export".
 *    Filtering it here is doing what it asked; doing it SILENTLY is not, so the
 *    count goes into manifest.json's notes and the option can be turned off.
 *
 * 4. `is_visible` IS COMPUTED, NOT COPIED. Woo's catalog visibility is a
 *    `product_visibility` TERM, not a meta value, and it has four states:
 *    visible, catalog, search, hidden. Row::bool() reads 'visible' as true and
 *    would read 'catalog' -- which means "in the shop but not in search" -- as
 *    FALSE, hiding a product the owner had published. Folded here into the
 *    yes/no the column actually means, with the raw four-state value carried
 *    alongside under a name nothing reads, so the nuance is in the export and
 *    named in the import report's discard list rather than lost.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Products extends KBB_Export_Stage {

	const META_KEYS = array(
		'_sku', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to',
		'_stock', '_stock_status', '_manage_stock', '_backorders', '_low_stock_amount',
		'_thumbnail_id', '_product_image_gallery', 'total_sales',
		'_weight', '_length', '_width', '_height', '_tax_status', '_tax_class',
		'_virtual', '_downloadable', '_purchase_note', '_upsell_ids', '_crosssell_ids',
		// Inventory tab, "Limit purchases to 1 item per order". See columns().
		'_sold_individually',
		'_product_attributes', '_default_attributes', '_children',
		// Not Yoast's SEO: Yoast is also where WooCommerce's PRIMARY category
		// is stored, and ProductImporter writes `products.category_id` from
		// the FIRST id in `category_term_ids`. Ordering by term id instead
		// would make the primary category whichever one happened to be
		// created first -- on this shop, the broad parent rather than the leaf.
		'_yoast_wpseo_primary_product_cat',
	);

	/** Statuses a product row can be in that this export carries. */
	private function statuses() {
		$statuses = array( 'publish', 'draft', 'private', 'pending', 'future' );

		if ( ! $this->option( 'skip_trashed', true ) ) {
			$statuses[] = 'trash';
		}

		return $statuses;
	}

	public function file() {
		return 'products.csv';
	}

	public function columns() {
		return array(
			// Read by ProductImporter.
			'id', 'name', 'slug', 'sku', 'status', 'type',
			'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at',
			'stock_status', 'stock', 'manage_stock', 'featured', 'is_visible',
			'brand_term_id', 'category_term_ids', 'position', 'date_created',
			'image', 'images', 'short_description', 'description', 'total_sales',
			// Carried and not read by anything here. Each one is a real thing
			// the old shop holds and the new one has no column for, and the
			// import's own discard channel names them with a sample value --
			// which is the designed way for the owner to approve a loss rather
			// than discover it. See docs/FV-IMPORT-AT-VOLUME.md §10.
			'date_modified', 'product_visibility', 'backorders', 'low_stock_amount',
			'weight', 'length', 'width', 'height', 'tax_status', 'tax_class',
			'shipping_class', 'virtual', 'downloadable', 'purchase_note',
			'upsell_ids', 'cross_sell_ids', 'grouped_ids', 'tag_term_ids', 'attribute_summary',
			/*
			 * ── THREE THAT WERE ON HIS EDIT PAGE AND IN NO FILE AT ALL ──────
			 *
			 * Added by Lane PX, and they are a different KIND of gap from the
			 * nineteen above. Those are exported and then named in the import's
			 * discard channel, which is the designed way for the owner to
			 * approve a loss. THESE THREE WERE NOT EXPORTED, so no channel could
			 * name them: the migration census only classifies columns the export
			 * writes, and the importer's discard list only names columns that
			 * arrive. A field missing from the file is invisible to every report
			 * this migration has, which makes it the only loss that genuinely
			 * cannot be discovered before a customer finds it.
			 *
			 *   sold_individually -- Inventory tab, "Limit purchases to 1 item
			 *   per order". A product with it set that arrives without it can be
			 *   added to a basket ten times.
			 *
			 *   reviews_enabled -- Advanced tab, "Enable reviews", which
			 *   WordPress keeps in posts.comment_status and not in meta. A
			 *   product whose reviews the owner had turned OFF arrives with them
			 *   on. posts.csv has carried comment_status all along; products.csv
			 *   had not.
			 *
			 *   default_attributes -- Variations tab, "Default Form Values",
			 *   which is WHICH SIZE a variable product's page opens on. This one
			 *   was the worst of the three to find and the cheapest to fix:
			 *   `_default_attributes` WAS ALREADY IN META_KEYS ABOVE, fetched on
			 *   every batch and then emitted by nothing. So the query cost was
			 *   already being paid, and anyone reading META_KEYS -- which is the
			 *   list this file offers as its account of what it reads out of
			 *   WooCommerce -- would reasonably conclude the field crossed. A
			 *   fetch with no column is worse than an absence, because it reads
			 *   as coverage.
			 *
			 * All three are carried and none is read: ProductImporter names them
			 * in the discard channel with their value, exactly like the rest.
			 */
			'sold_individually', 'reviews_enabled', 'default_attributes',
		);
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'product' AND post_status IN (" . $this->status_list() . ')'
		);
	}

	private function status_list() {
		return implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), $this->statuses() ) );
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, post_title, post_name, post_status, post_date, post_modified, post_content, post_excerpt, menu_order, comment_status
			 FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'product' AND post_status IN (" . $this->status_list() . ')
			   AND ID > ' . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			$this->report_trash();

			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids   = $this->ids_of( $rows );
		$last  = end( $ids );
		$meta  = KBB_Export_Wp::post_meta( $ids, self::META_KEYS );
		$brand = KBB_Export_Wp::brand_taxonomy();

		$taxonomies = array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' );

		if ( '' !== $brand ) {
			$taxonomies[] = $brand;
		}

		$terms = KBB_Export_Wp::terms_for( $ids, $taxonomies );

		$out = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			$m  = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$t  = isset( $terms[ $id ] ) ? $terms[ $id ] : array();

			$get = function ( $key, $default = '' ) use ( $m ) {
				return isset( $m[ $key ] ) ? (string) $m[ $key ] : $default;
			};

			$visibility = $this->visibility( $t );

			$out[] = array(
				'id'   => $id,
				'name' => $row['post_title'],
				// post_name, always. See note 2 in the class header.
				'slug' => $row['post_name'],
				'sku'  => $get( '_sku' ),
				// publish | draft | private | pending | future | trash, exactly
				// as WordPress holds it. ProductImporter maps the first five and
				// refuses the last with a sentence naming what to do about it.
				'status' => $row['post_status'],
				// simple | variable | grouped | external, from the product_type
				// taxonomy. Woo has never stored this in meta.
				'type'   => $this->first_slug( $t, 'product_type', 'simple' ),
				'regular_price'  => $this->money( $get( '_regular_price' ) ),
				'sale_price'     => $this->money( $get( '_sale_price' ) ),
				'sale_starts_at' => $this->date( $get( '_sale_price_dates_from' ) ),
				'sale_ends_at'   => $this->date( $get( '_sale_price_dates_to' ) ),
				'stock_status'   => $get( '_stock_status', 'instock' ),
				// Present-and-empty is not zero: ProductImporter writes NULL for
				// a product that does not manage stock and 0 for one that does
				// and has none, and Row::text() is what tells the two apart.
				'stock'          => $get( '_stock' ),
				'manage_stock'   => $this->yesno( $get( '_manage_stock' ) ),
				'featured'       => $this->has_term( $t, 'product_visibility', 'featured' ) ? 'yes' : 'no',
				'is_visible'     => in_array( $visibility, array( 'visible', 'catalog' ), true ) ? 'yes' : 'no',
				'brand_term_id'  => '' === $brand ? '' : $this->first_term_id( $t, $brand ),
				'category_term_ids' => $this->commas( $this->category_ids( $t, $get( '_yoast_wpseo_primary_product_cat' ) ) ),
				'position'       => (int) $row['menu_order'],
				'date_created'   => $row['post_date'],
				'image'          => KBB_Export_Wp::attachment_url( $get( '_thumbnail_id' ) ),
				'images'         => $this->gallery( $get( '_product_image_gallery' ) ),
				'short_description' => $row['post_excerpt'],
				'description'    => $row['post_content'],
				'total_sales'    => $get( 'total_sales', '0' ),

				'date_modified'  => $row['post_modified'],
				'product_visibility' => $visibility,
				'backorders'     => $get( '_backorders' ),
				'low_stock_amount' => $get( '_low_stock_amount' ),
				'weight'         => $get( '_weight' ),
				'length'         => $get( '_length' ),
				'width'          => $get( '_width' ),
				'height'         => $get( '_height' ),
				'tax_status'     => $get( '_tax_status' ),
				'tax_class'      => $get( '_tax_class' ),
				'shipping_class' => $this->first_slug( $t, 'product_shipping_class', '' ),
				'virtual'        => $this->yesno( $get( '_virtual' ) ),
				'downloadable'   => $this->yesno( $get( '_downloadable' ) ),
				'purchase_note'  => $get( '_purchase_note' ),
				'upsell_ids'     => $this->id_list( $get( '_upsell_ids' ) ),
				'cross_sell_ids' => $this->id_list( $get( '_crosssell_ids' ) ),
				'grouped_ids'    => $this->id_list( $get( '_children' ) ),
				'tag_term_ids'   => $this->commas( $this->term_ids( $t, 'product_tag' ) ),
				'attribute_summary' => $this->attribute_summary( $get( '_product_attributes' ) ),
				'sold_individually' => $this->yesno( $get( '_sold_individually' ) ),
				// 'open' or 'closed', and Woo writes 'closed' when the box is
				// unticked. Normalised to the same yes/no every other flag in
				// this row uses rather than passed through, so the importer has
				// one convention to read and not two.
				'reviews_enabled' => 'closed' === $row['comment_status'] ? 'no' : 'yes',
				'default_attributes' => $this->default_attributes( $get( '_default_attributes' ) ),
			);
		}

		$done = count( $rows ) < (int) $limit;

		if ( $done ) {
			$this->report_trash();
		}

		return array( 'rows' => $out, 'cursor' => (int) $last, 'done' => $done );
	}

	/**
	 * Say how many products were held back, and never say nothing.
	 *
	 * A row silently absent from an export is worse than a row the importer
	 * refuses: the refusal is in a report the owner reads, and the absence is
	 * in no report at all.
	 */
	private function report_trash() {
		if ( ! $this->option( 'skip_trashed', true ) ) {
			return;
		}

		global $wpdb;

		$trashed = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts WHERE post_type = 'product' AND post_status = 'trash'"
		);

		if ( $trashed > 0 ) {
			$this->note(
				$trashed . ' product' . ( 1 === $trashed ? ' is' : 's are' ) . ' in the WordPress trash and '
					. 'NOT in products.csv. ProductImporter refuses a trashed product outright -- importing it '
					. 'would make it a live row -- so this export does what that refusal asks. Empty the trash or '
					. 'turn off "skip trashed" and re-export if you want them.'
			);
		}
	}

	/**
	 * The `product_visibility` taxonomy, folded to Woo's own four-state value.
	 *
	 * Woo stores the state as the ABSENCE of terms: a fully visible product has
	 * neither `exclude-from-catalog` nor `exclude-from-search`. Reading it as a
	 * meta value -- which several exporters do, because `_visibility` was the
	 * meta key before WooCommerce 3.0 -- returns nothing on any shop updated
	 * this decade.
	 */
	private function visibility( array $terms ) {
		$hidden_catalog = $this->has_term( $terms, 'product_visibility', 'exclude-from-catalog' );
		$hidden_search  = $this->has_term( $terms, 'product_visibility', 'exclude-from-search' );

		if ( $hidden_catalog && $hidden_search ) {
			return 'hidden';
		}

		if ( $hidden_catalog ) {
			return 'search';
		}

		if ( $hidden_search ) {
			return 'catalog';
		}

		return 'visible';
	}

	private function has_term( array $terms, $taxonomy, $slug ) {
		foreach ( $this->slugs( $terms, $taxonomy ) as $found ) {
			if ( $found === $slug ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<int,string> */
	private function slugs( array $terms, $taxonomy ) {
		$out = array();

		if ( ! isset( $terms[ $taxonomy ] ) ) {
			return $out;
		}

		foreach ( $terms[ $taxonomy ] as $term ) {
			$out[] = $term['slug'];
		}

		return $out;
	}

	private function first_slug( array $terms, $taxonomy, $default ) {
		$slugs = $this->slugs( $terms, $taxonomy );

		return empty( $slugs ) ? $default : $slugs[0];
	}

	private function first_term_id( array $terms, $taxonomy ) {
		if ( empty( $terms[ $taxonomy ] ) ) {
			return '';
		}

		return (string) $terms[ $taxonomy ][0]['term_id'];
	}

	/**
	 * The product's categories, primary first.
	 *
	 * ProductImporter takes `$categoryIds[0]` as `products.category_id` -- the
	 * PRIMARY category, which decides the breadcrumb and the canonical listing
	 * the product belongs to -- and writes the whole list to the pivot. So the
	 * order of this cell is not cosmetic.
	 *
	 * Ordering it by term id, which is what a plain join returns, makes the
	 * primary category whichever one was CREATED first. On a shop whose
	 * categories were built top down that is reliably the broad parent
	 * ("Skincare") rather than the leaf the product actually sits in ("Face
	 * Cleansers"), so every product would file itself one level too high.
	 *
	 * `_yoast_wpseo_primary_product_cat` is the shop's own answer where Yoast
	 * wrote one. Where it did not, the join order stands and is no worse than
	 * it was.
	 *
	 * @return array<int,string>
	 */
	private function category_ids( array $terms, $primary ) {
		$ids     = $this->term_ids( $terms, 'product_cat' );
		$primary = trim( (string) $primary );

		if ( '' === $primary || ! in_array( $primary, $ids, true ) ) {
			return $ids;
		}

		return array_merge( array( $primary ), array_values( array_diff( $ids, array( $primary ) ) ) );
	}

	/** @return array<int,string> */
	private function term_ids( array $terms, $taxonomy ) {
		$out = array();

		if ( ! isset( $terms[ $taxonomy ] ) ) {
			return $out;
		}

		foreach ( $terms[ $taxonomy ] as $term ) {
			$out[] = (string) $term['term_id'];
		}

		return $out;
	}

	/**
	 * `_product_image_gallery` is a comma-separated list of ATTACHMENT IDS.
	 * The shop wants URLs, and it wants them pipe-separated.
	 */
	private function gallery( $raw ) {
		$ids  = array_filter( array_map( 'intval', explode( ',', (string) $raw ) ) );
		$urls = array();

		foreach ( $ids as $id ) {
			$url = KBB_Export_Wp::attachment_url( $id );

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $this->pipes( $urls );
	}

	/** A serialised id array as a comma list. */
	private function id_list( $raw ) {
		$value = maybe_unserialize( (string) $raw );

		if ( ! is_array( $value ) ) {
			return '';
		}

		return $this->commas( array_map( 'strval', array_map( 'intval', $value ) ) );
	}

	/**
	 * `_product_attributes` reduced to `name=value|name=value`.
	 *
	 * The full structure -- the per-attribute position, visible and variation
	 * flags -- is in attributes.csv for taxonomy attributes. This is the
	 * CUSTOM ones, typed into the product and stored nowhere else, which would
	 * otherwise leave the shop entirely.
	 */
	/**
	 * "Default Form Values" -- which variation a variable product opens on.
	 *
	 * `_default_attributes` is a serialised map keyed by the attribute's own
	 * lowercased name, taxonomy or not: array( 'pa_size' => '50ml', 'scent' =>
	 * 'Unscented' ). Emitted in the same `name=value | name=value` shape as
	 * attribute_summary() so the importer has ONE convention to read across this
	 * row and not two, and so the value is legible in the import report's
	 * discard sample -- which is the only place it currently goes.
	 *
	 * A PIPE IN A VALUE WOULD SPLIT IT, exactly as it would in
	 * attribute_summary(), so a `|` becomes `/` here too. A default attribute
	 * value is a term slug or a short label and a pipe in one is not a real
	 * case; corrupting the separator silently would be.
	 */
	private function default_attributes( $raw ) {
		$value = maybe_unserialize( (string) $raw );

		if ( ! is_array( $value ) ) {
			return '';
		}

		$parts = array();

		foreach ( $value as $key => $chosen ) {
			// Woo has written arrays in here on shops with odd plugins. A
			// non-scalar has no legible one-line form, so it is skipped rather
			// than turned into "Array" -- an example value in a discard report
			// that says "Array" is worse than one column fewer.
			if ( ! is_scalar( $chosen ) ) {
				continue;
			}

			$chosen = (string) $chosen;

			if ( '' === $chosen ) {
				continue;
			}

			$parts[] = (string) $key . '=' . str_replace( '|', '/', $chosen );
		}

		return $this->pipes( $parts );
	}

	private function attribute_summary( $raw ) {
		$value = maybe_unserialize( (string) $raw );

		if ( ! is_array( $value ) ) {
			return '';
		}

		$parts = array();

		foreach ( $value as $key => $attribute ) {
			if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) ) {
				continue;
			}

			$name = isset( $attribute['name'] ) ? (string) $attribute['name'] : (string) $key;
			$val  = isset( $attribute['value'] ) ? (string) $attribute['value'] : '';

			$parts[] = $name . '=' . str_replace( '|', '/', $val );
		}

		return $this->pipes( $parts );
	}
}

/**
 * `variations.csv` -- a **gap** file, and the largest missing entity by revenue.
 *
 * docs/FV-IMPORT-AT-VOLUME.md §11: "A variable product imports as its parent
 * only. The shop then sells '50ml or 100ml' as one price. This is the largest
 * missing entity by revenue."
 *
 * A variation is a `wp_posts` row of type `product_variation` whose post_parent
 * is the variable product and whose chosen values are meta keys named
 * `attribute_pa_size` => the TERM SLUG (not the term id, and not the label).
 * Those keys cannot be named in advance, so they are fetched by prefix and
 * flattened into one `attributes` cell of `attribute_pa_size=50ml|...`.
 *
 * `status` carries the variation's own post_status: Woo disables a variation by
 * setting it to `private`, and a future importer that ignored that would put a
 * disabled size back on sale.
 */
class KBB_Export_Stage_Variations extends KBB_Export_Stage {

	const META_KEYS = array(
		'_sku', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to',
		'_stock', '_stock_status', '_manage_stock', '_backorders', '_thumbnail_id',
		'_weight', '_length', '_width', '_height', '_variation_description', '_tax_class',
	);

	public function file() {
		return 'variations.csv';
	}

	public function columns() {
		return array(
			'id', 'parent_id', 'sku', 'status', 'position',
			'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at',
			'stock_status', 'stock', 'manage_stock', 'backorders',
			'weight', 'length', 'width', 'height', 'tax_class',
			'image', 'description', 'attributes', 'date_created',
		);
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts WHERE post_type = 'product_variation'"
		);
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, post_parent, post_status, post_date, post_excerpt, menu_order
			 FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'product_variation' AND ID > " . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids        = $this->ids_of( $rows );
		$meta       = KBB_Export_Wp::post_meta( $ids, self::META_KEYS );
		$attributes = KBB_Export_Wp::post_meta_like( $ids, array( 'attribute_' ) );

		$out = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			$m  = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$a  = isset( $attributes[ $id ] ) ? $attributes[ $id ] : array();

			$get = function ( $key, $default = '' ) use ( $m ) {
				return isset( $m[ $key ] ) ? (string) $m[ $key ] : $default;
			};

			$pairs = array();

			foreach ( $a as $key => $value ) {
				$pairs[] = $key . '=' . str_replace( '|', '/', (string) $value );
			}

			sort( $pairs );

			$out[] = array(
				'id'             => $id,
				'parent_id'      => (int) $row['post_parent'],
				'sku'            => $get( '_sku' ),
				'status'         => $row['post_status'],
				'position'       => (int) $row['menu_order'],
				'regular_price'  => $this->money( $get( '_regular_price' ) ),
				'sale_price'     => $this->money( $get( '_sale_price' ) ),
				'sale_starts_at' => $this->date( $get( '_sale_price_dates_from' ) ),
				'sale_ends_at'   => $this->date( $get( '_sale_price_dates_to' ) ),
				'stock_status'   => $get( '_stock_status' ),
				'stock'          => $get( '_stock' ),
				'manage_stock'   => $this->yesno( $get( '_manage_stock' ) ),
				'backorders'     => $get( '_backorders' ),
				'weight'         => $get( '_weight' ),
				'length'         => $get( '_length' ),
				'width'          => $get( '_width' ),
				'height'         => $get( '_height' ),
				'tax_class'      => $get( '_tax_class' ),
				'image'          => KBB_Export_Wp::attachment_url( $get( '_thumbnail_id' ) ),
				'description'    => '' !== $get( '_variation_description' ) ? $get( '_variation_description' ) : (string) $row['post_excerpt'],
				'attributes'     => $this->pipes( $pairs ),
				'date_created'   => $row['post_date'],
			);
		}

		return array(
			'rows'   => $out,
			'cursor' => (int) end( $ids ),
			'done'   => count( $rows ) < (int) $limit,
		);
	}
}
