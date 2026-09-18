<?php
/**
 * The four taxonomy files: categories, brands, tags, attributes.
 *
 * ── WHERE THE COLUMNS CAME FROM ─────────────────────────────────────────────
 *
 * categories.csv and brands.csv were derived by reading
 * App\Services\Import\Entities\CategoryImporter and ::BrandImporter and nothing
 * else. Both match on `source_term_id`, both accept `term_id` / `id` /
 * `<entity>_id` as its spelling, and both read exactly:
 *
 *   term_id, name, slug, description, position (menu_order | order)
 *   + categories: parent (parent_id | parent_term_id), image (thumbnail)
 *   + brands:     logo (image | thumbnail)
 *
 * tags.csv and attributes.csv have no importer yet -- they are two of the seven
 * the contract marks **gap** -- so their columns are chosen to be sufficient
 * rather than to match something. Both carry `product_ids`, because a tag or an
 * attribute value with no products attached to it is a row nobody can do
 * anything with, and the association exists nowhere else in the file set.
 *
 * ── BRANDS ARE AN ATTRIBUTE ON THIS SHOP, AND THIS ASKS RATHER THAN ASSUMES ─
 *
 * BrandImporter's header: "Production does not have a brands taxonomy: brands
 * live as the `pa_brands` product attribute, 93 terms of it". So the brand
 * stage reads whichever taxonomy KBB_Export_Wp::brand_taxonomy() actually finds
 * rows in, names it in a manifest note, and exports nothing at all if there is
 * none -- rather than writing 93 rows out of a taxonomy that was never there.
 * Whatever taxonomy that turns out to be is also excluded from attributes.csv,
 * so no term is exported twice under two meanings.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared machinery: a keyset scan over `term_taxonomy` for one taxonomy.
 */
abstract class KBB_Export_Stage_Terms extends KBB_Export_Stage {

	/** The taxonomy this stage reads, or '' when the shop has none. */
	abstract protected function taxonomy();

	public function total() {
		global $wpdb;

		$taxonomy = $this->taxonomy();

		if ( '' === $taxonomy ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'term_taxonomy WHERE taxonomy = ' . KBB_Export_Wp::quote( $taxonomy )
		);
	}

	/**
	 * The next batch of terms.
	 *
	 * Keyed on `t.term_id`, which is unique within a taxonomy and monotonic, so
	 * a term added while the export runs lands after the cursor instead of
	 * shifting an offset and silently skipping its neighbour.
	 *
	 * @return array{rows: array<int,array<string,string>>, cursor: int, done: bool}
	 */
	protected function term_batch( $cursor, $limit ) {
		global $wpdb;

		$taxonomy = $this->taxonomy();

		if ( '' === $taxonomy ) {
			return array( 'rows' => array(), 'cursor' => 0, 'done' => true );
		}

		$rows = $wpdb->get_results(
			'SELECT t.term_id, t.name, t.slug, tt.parent, tt.description, tt.count, tt.term_taxonomy_id
			 FROM ' . $wpdb->prefix . 'term_taxonomy tt
			 JOIN ' . $wpdb->prefix . 'terms t ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = ' . KBB_Export_Wp::quote( $taxonomy ) . ' AND t.term_id > ' . (int) $cursor . '
			 ORDER BY t.term_id
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$last = (int) $cursor;

		foreach ( $rows as $row ) {
			$last = (int) $row['term_id'];
		}

		return array( 'rows' => $rows, 'cursor' => $last, 'done' => count( $rows ) < (int) $limit );
	}

	/**
	 * `position` and `image`, from term meta.
	 *
	 * WooCommerce stores a product category's ordering under `order` and its
	 * picture under `thumbnail_id`. Both are read in one query for the batch;
	 * `get_term_meta()` per row is one query per term, which on 400 categories
	 * is 400 queries to fetch 400 integers.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<string,string>>
	 */
	protected function term_extras( array $ids ) {
		return KBB_Export_Wp::term_meta( $ids, array( 'order', 'thumbnail_id', 'product_count_product_cat' ) );
	}

	/**
	 * The product ids filed under each of these terms.
	 *
	 * Restricted to post_type = product on purpose: `term_relationships` holds
	 * every object attached to a term, and on a shop running a blog the same
	 * tag can be on posts as well.
	 *
	 * @param array<int,int> $term_taxonomy_ids
	 * @return array<int,array<int,int>> keyed by term_taxonomy_id
	 */
	protected function products_for( array $term_taxonomy_ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $term_taxonomy_ids ) ) {
			return $out;
		}

		$rows = $wpdb->get_results(
			'SELECT tr.term_taxonomy_id, tr.object_id
			 FROM ' . $wpdb->prefix . 'term_relationships tr
			 JOIN ' . $wpdb->prefix . "posts p ON p.ID = tr.object_id AND p.post_type = 'product'
			 WHERE tr.term_taxonomy_id IN (" . implode( ',', array_map( 'intval', $term_taxonomy_ids ) ) . ')
			 ORDER BY tr.term_taxonomy_id, tr.object_id',
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['term_taxonomy_id'] ][] = (int) $row['object_id'];
		}

		return $out;
	}
}

/**
 * `categories.csv` -- WooCommerce `product_cat` terms, read by CategoryImporter.
 */
class KBB_Export_Stage_Categories extends KBB_Export_Stage_Terms {

	public function file() {
		return 'categories.csv';
	}

	protected function taxonomy() {
		return 'product_cat';
	}

	/**
	 * Exactly the columns CategoryImporter::import() asks a Row for.
	 *
	 * `parent` is the PARENT'S TERM ID and not a local key, which is what that
	 * importer's two-pass design needs: "the row pass writes every category
	 * with its parent's TERM id resolved where it can be and null where it
	 * cannot, and finalise() then walks the whole table once". A top-level
	 * category's parent is 0 in `term_taxonomy`, and Row::id() answers null for
	 * 0 -- deliberately, per its own comment -- so 0 needs no special case here.
	 */
	public function columns() {
		return array( 'term_id', 'name', 'slug', 'parent', 'description', 'image', 'position' );
	}

	public function batch( $cursor, $limit ) {
		$batch = $this->term_batch( $cursor, $limit );

		if ( empty( $batch['rows'] ) ) {
			return $batch;
		}

		$ids    = $this->ids_of( $batch['rows'], 'term_id' );
		$extras = $this->term_extras( $ids );

		$out = array();

		foreach ( $batch['rows'] as $row ) {
			$id    = (int) $row['term_id'];
			$extra = isset( $extras[ $id ] ) ? $extras[ $id ] : array();

			$out[] = array(
				'term_id'     => $id,
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'parent'      => (int) $row['parent'],
				'description' => $row['description'],
				'image'       => isset( $extra['thumbnail_id'] ) ? KBB_Export_Wp::attachment_url( $extra['thumbnail_id'] ) : '',
				'position'    => isset( $extra['order'] ) ? (int) $extra['order'] : 0,
			);
		}

		$batch['rows'] = $out;

		return $batch;
	}
}

/**
 * `brands.csv` -- whichever taxonomy this shop really keeps brands in.
 */
class KBB_Export_Stage_Brands extends KBB_Export_Stage_Terms {

	/** @var string|null */
	private $taxonomy;

	public function file() {
		return 'brands.csv';
	}

	protected function taxonomy() {
		if ( null === $this->taxonomy ) {
			$this->taxonomy = KBB_Export_Wp::brand_taxonomy();

			$this->note(
				'' === $this->taxonomy
					? 'brands.csv is empty: no brand taxonomy was found in this database. Looked for pa_brands, '
						. 'pa_brand, product_brand, yith_product_brand, pwb-brand, berocket_brand and brand.'
					: 'brands.csv was read from the `' . $this->taxonomy . '` taxonomy. BrandImporter promotes these '
						. 'terms to their own table with source_term_id carrying this term id.'
			);
		}

		return $this->taxonomy;
	}

	/** Exactly what BrandImporter::import() reads: term_id, name, slug, description, logo, position. */
	public function columns() {
		return array( 'term_id', 'name', 'slug', 'description', 'logo', 'position' );
	}

	public function batch( $cursor, $limit ) {
		$batch = $this->term_batch( $cursor, $limit );

		if ( empty( $batch['rows'] ) ) {
			return $batch;
		}

		$ids    = $this->ids_of( $batch['rows'], 'term_id' );
		$extras = $this->term_extras( $ids );

		$out = array();

		foreach ( $batch['rows'] as $row ) {
			$id    = (int) $row['term_id'];
			$extra = isset( $extras[ $id ] ) ? $extras[ $id ] : array();

			$out[] = array(
				'term_id'     => $id,
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'description' => $row['description'],
				'logo'        => isset( $extra['thumbnail_id'] ) ? KBB_Export_Wp::attachment_url( $extra['thumbnail_id'] ) : '',
				'position'    => isset( $extra['order'] ) ? (int) $extra['order'] : 0,
			);
		}

		$batch['rows'] = $out;

		return $batch;
	}
}

/**
 * `tags.csv` -- a **gap** file. No importer reads it yet.
 *
 * It carries `product_ids` because docs/FV-IMPORT-AT-VOLUME.md §11 names what
 * is missing as "`tags`, `product_tag` | tags.csv | Product tags, and the tag
 * archives Google has indexed" -- both halves, the terms AND the pivot. A tags
 * file without the pivot would need a second file before anything could use it.
 */
class KBB_Export_Stage_Tags extends KBB_Export_Stage_Terms {

	public function file() {
		return 'tags.csv';
	}

	protected function taxonomy() {
		return 'product_tag';
	}

	public function columns() {
		return array( 'term_id', 'name', 'slug', 'description', 'parent', 'count', 'product_ids' );
	}

	public function batch( $cursor, $limit ) {
		$batch = $this->term_batch( $cursor, $limit );

		if ( empty( $batch['rows'] ) ) {
			return $batch;
		}

		$tt_ids = array();

		foreach ( $batch['rows'] as $row ) {
			$tt_ids[] = (int) $row['term_taxonomy_id'];
		}

		$products = $this->products_for( $tt_ids );

		$out = array();

		foreach ( $batch['rows'] as $row ) {
			$tt = (int) $row['term_taxonomy_id'];

			$out[] = array(
				'term_id'     => (int) $row['term_id'],
				'name'        => $row['name'],
				'slug'        => $row['slug'],
				'description' => $row['description'],
				'parent'      => (int) $row['parent'],
				'count'       => (int) $row['count'],
				'product_ids' => isset( $products[ $tt ] ) ? $this->commas( array_map( 'strval', $products[ $tt ] ) ) : '',
			);
		}

		$batch['rows'] = $out;

		return $batch;
	}
}

/**
 * `attributes.csv` -- a **gap** file: every `pa_*` attribute term that is not a
 * brand, one row per term, with the attribute's own definition repeated on it.
 *
 * ── WHY THE DEFINITION IS DENORMALISED ONTO EVERY ROW ───────────────────────
 *
 * `wp_woocommerce_attribute_taxonomies` holds the attribute (label, type,
 * whether its archive is public) and `wp_terms` holds its values, and a future
 * importer has to write BOTH -- docs/FV-IMPORT-AT-VOLUME.md §11 names three
 * empty tables for this one file: `attributes`, `attribute_values` and
 * `product_attribute_value`. Two files would need an order and a join; one file
 * with the label repeated is the same information and cannot be half-imported.
 *
 * `attribute_public` is carried because it is the answer to a question this
 * migration has been guessing at: an attribute with a public archive has URLs
 * in Google's index (`/pa_size/50ml/`) and one without has none. The permalinks
 * stage reads the same flag.
 */
class KBB_Export_Stage_Attributes extends KBB_Export_Stage_Terms {

	/** @var array<int,array<string,string>>|null taxonomy => definition */
	private $definitions;

	/** @var array<int,string>|null */
	private $taxonomies;

	/** @var int index into $taxonomies */
	private $current = 0;

	public function file() {
		return 'attributes.csv';
	}

	public function columns() {
		return array(
			'taxonomy', 'attribute_id', 'attribute_name', 'attribute_label', 'attribute_type',
			'attribute_orderby', 'attribute_public',
			'term_id', 'name', 'slug', 'description', 'count', 'product_ids',
		);
	}

	/**
	 * Every `pa_*` taxonomy with terms, minus the one holding brands.
	 *
	 * `wp_woocommerce_attribute_taxonomies` is the authority for what an
	 * attribute IS; `term_taxonomy` is the authority for which of them a
	 * six-year-old shop still has terms in. Both are asked, and an attribute
	 * registered with no terms is skipped rather than exported as a row with no
	 * value in it.
	 *
	 * @return array<int,string>
	 */
	private function taxonomies() {
		if ( null !== $this->taxonomies ) {
			return $this->taxonomies;
		}

		global $wpdb;

		$this->definitions = array();

		$defs = $wpdb->get_results(
			'SELECT attribute_id, attribute_name, attribute_label, attribute_type, attribute_orderby, attribute_public
			 FROM ' . $wpdb->prefix . 'woocommerce_attribute_taxonomies
			 ORDER BY attribute_id',
			ARRAY_A
		);

		foreach ( (array) $defs as $def ) {
			$this->definitions[ 'pa_' . $def['attribute_name'] ] = $def;
		}

		$brand = KBB_Export_Wp::brand_taxonomy();
		$found = array();

		foreach ( KBB_Export_Wp::taxonomies_in_database() as $taxonomy => $count ) {
			if ( 0 !== strpos( $taxonomy, 'pa_' ) || $taxonomy === $brand ) {
				continue;
			}

			$found[] = $taxonomy;
		}

		sort( $found );

		$this->taxonomies = $found;

		if ( '' !== $brand ) {
			$this->note(
				'attributes.csv excludes `' . $brand . '`, which brands.csv carries instead -- exporting it in both '
					. 'would make every brand an attribute value as well as a brand.'
			);
		}

		return $this->taxonomies;
	}

	protected function taxonomy() {
		$all = $this->taxonomies();

		return isset( $all[ $this->current ] ) ? $all[ $this->current ] : '';
	}

	public function total() {
		global $wpdb;

		$all = $this->taxonomies();

		if ( empty( $all ) ) {
			return 0;
		}

		$list = implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), $all ) );

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'term_taxonomy WHERE taxonomy IN (' . $list . ')'
		);
	}

	/**
	 * The cursor encodes BOTH the taxonomy being walked and the term reached:
	 * `taxonomyIndex * 10^10 + termId`.
	 *
	 * One integer, because the runner's checkpoint is one integer -- and it has
	 * to be, for resume to be a property of the runner rather than something
	 * each stage reimplements. 10^10 is above any term id a WordPress site will
	 * hold (the column is BIGINT but term ids are allocated sequentially from
	 * 1) and the whole value stays inside a 64-bit int, which PHP has on every
	 * host this can run on.
	 */
	public function batch( $cursor, $limit ) {
		$all = $this->taxonomies();

		if ( empty( $all ) ) {
			return array( 'rows' => array(), 'cursor' => 0, 'done' => true );
		}

		$cursor        = (int) $cursor;
		$this->current = intdiv( $cursor, 10000000000 );
		$term_cursor   = $cursor % 10000000000;

		while ( $this->current < count( $all ) ) {
			$batch = $this->term_batch( $term_cursor, $limit );

			if ( ! empty( $batch['rows'] ) ) {
				$rows = $this->decorate( $batch['rows'], $all[ $this->current ] );

				$next = $this->current * 10000000000 + (int) $batch['cursor'];

				if ( ! empty( $batch['done'] ) ) {
					$next = ( $this->current + 1 ) * 10000000000;
				}

				return array(
					'rows'   => $rows,
					'cursor' => $next,
					'done'   => ! empty( $batch['done'] ) && $this->current + 1 >= count( $all ),
				);
			}

			$this->current++;
			$term_cursor = 0;
		}

		return array( 'rows' => array(), 'cursor' => $cursor, 'done' => true );
	}

	/** @return array<int,array<string,string>> */
	private function decorate( array $rows, $taxonomy ) {
		$definition = isset( $this->definitions[ $taxonomy ] ) ? $this->definitions[ $taxonomy ] : array();

		$tt_ids = array();

		foreach ( $rows as $row ) {
			$tt_ids[] = (int) $row['term_taxonomy_id'];
		}

		$products = $this->products_for( $tt_ids );

		$out = array();

		foreach ( $rows as $row ) {
			$tt = (int) $row['term_taxonomy_id'];

			$out[] = array(
				'taxonomy'          => $taxonomy,
				'attribute_id'      => isset( $definition['attribute_id'] ) ? (int) $definition['attribute_id'] : '',
				'attribute_name'    => isset( $definition['attribute_name'] ) ? $definition['attribute_name'] : substr( $taxonomy, 3 ),
				'attribute_label'   => isset( $definition['attribute_label'] ) ? $definition['attribute_label'] : '',
				'attribute_type'    => isset( $definition['attribute_type'] ) ? $definition['attribute_type'] : '',
				'attribute_orderby' => isset( $definition['attribute_orderby'] ) ? $definition['attribute_orderby'] : '',
				'attribute_public'  => isset( $definition['attribute_public'] ) ? (int) $definition['attribute_public'] : 0,
				'term_id'           => (int) $row['term_id'],
				'name'              => $row['name'],
				'slug'              => $row['slug'],
				'description'       => $row['description'],
				'count'             => (int) $row['count'],
				'product_ids'       => isset( $products[ $tt ] ) ? $this->commas( array_map( 'strval', $products[ $tt ] ) ) : '',
			);
		}

		return $out;
	}
}
