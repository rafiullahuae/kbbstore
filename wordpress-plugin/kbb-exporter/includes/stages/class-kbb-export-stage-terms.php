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
	 * On the LAST batch of a category or brand stage, the term-meta census.
	 * (1.11.0)
	 *
	 * Once per stage and only when the stage is done, because it is one
	 * GROUP BY over the whole taxonomy and its answer does not change between
	 * batches. The note is the thing that names where the old shop really
	 * keeps a category's banner -- see KBB_Export_Term_Header.
	 *
	 * @param array{rows: array, cursor: int, done: bool} $batch
	 */
	protected function header_census( array $batch ) {
		if ( empty( $batch['done'] ) ) {
			return;
		}

		$taxonomy = $this->taxonomy();

		if ( '' === $taxonomy ) {
			return;
		}

		$this->note( KBB_Export_Term_Header::census_note( $taxonomy, $this->file() ) );
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
		return array_merge(
			array( 'term_id', 'name', 'slug', 'parent', 'description', 'image', 'position' ),
			KBB_Export_Term_Header::COLUMNS
		);
	}

	public function batch( $cursor, $limit ) {
		$batch = $this->term_batch( $cursor, $limit );

		if ( empty( $batch['rows'] ) ) {
			$this->header_census( $batch );

			return $batch;
		}

		$ids    = $this->ids_of( $batch['rows'], 'term_id' );
		$extras = $this->term_extras( $ids );
		$header = KBB_Export_Term_Header::for_terms( $ids );

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
			) + KBB_Export_Term_Header::row( $header, $id );
		}

		$batch['rows'] = $out;

		$this->header_census( $batch );

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
		return array_merge(
			array( 'term_id', 'name', 'slug', 'description', 'logo', 'position' ),
			KBB_Export_Term_Header::COLUMNS
		);
	}

	public function batch( $cursor, $limit ) {
		$batch = $this->term_batch( $cursor, $limit );

		if ( empty( $batch['rows'] ) ) {
			$this->header_census( $batch );

			return $batch;
		}

		$ids    = $this->ids_of( $batch['rows'], 'term_id' );
		$extras = $this->term_extras( $ids );
		$header = KBB_Export_Term_Header::for_terms( $ids );

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
			) + KBB_Export_Term_Header::row( $header, $id );
		}

		$batch['rows'] = $out;

		$this->header_census( $batch );

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

/**
 * A category's (or brand's) TITLE HEADER on the old shop: the banner picture
 * behind the title, and any title or subtitle written for it. (1.11.0)
 *
 * ── WHAT THE OWNER ASKED, AND WHY THIS DOES NOT GUESS ONE META KEY ──────────
 *
 * "We have a banner image on each category on the old site. Need to bring that
 * on the category pages as title background, like on /sunscreens/ -- and the
 * same title, same description." The description is `term_taxonomy.description`
 * and categories.csv has always carried it. The picture is the question: on a
 * WooCommerce + Rey shop it may be WooCommerce's own category thumbnail
 * (`thumbnail_id`, already exported as `image`), a Rey or ACF term field, a
 * Rey "page cover" global section assigned to the term, or another plugin's
 * meta. Nobody writing this could see the live site, so nothing here names
 * one key as THE banner. Instead:
 *
 *  1. every term-meta key whose NAME reads like a picture, a cover, a banner,
 *     a header, a title or a subtitle is read, for every term;
 *  2. a picture value is RESOLVED whatever shape it is stored in -- an
 *     attachment id, an ACF image array, a serialized array, a JSON object or
 *     a plain URL -- and an id that names a non-attachment post (a Rey global
 *     section used as a cover) is followed to the first picture in that post's
 *     Elementor data, or its featured image;
 *  3. the best-named picture becomes `banner_image`, and `banner_source_key`
 *     says which key it came from, so the choice is visible in the file;
 *  4. a census note lists EVERY term-meta key the taxonomy carries, with how
 *     many terms carry it and a sample value -- so the owner's first export
 *     names the real storage even if none of the names above matched.
 *
 * `thumbnail_id` is deliberately NOT a banner candidate: it is already the
 * `image` column, and a square category thumbnail stretched behind a title is
 * a different picture from the one the owner means. If the census shows the
 * shop has no banner key at all, the banner IS the thumbnail -- and the new
 * shop has a switch for exactly that (Appearance -> Site layout -> Category
 * header -> "When no banner was imported, use the category picture").
 */
final class KBB_Export_Term_Header {

	/** The columns appended to categories.csv and brands.csv, in this order. */
	const COLUMNS = array( 'banner_image', 'banner_source_key', 'title_override', 'subtitle' );

	/** Keys that are WooCommerce's own bookkeeping, never a header field. */
	const SKIP = array( 'order', 'thumbnail_id', 'display_type', 'product_count_product_cat', 'product_count_product_tag' );

	/** A picture-ish name, ranked: lower is a better banner. */
	const IMAGE_WORDS = array(
		'banner'     => 0,
		'cover'      => 1,
		'header'     => 2,
		'hero'       => 2,
		'background' => 3,
		'bg'         => 3,
		'image'      => 4,
		'img'        => 4,
		'picture'    => 4,
		'photo'      => 4,
		'thumb'      => 5,
	);

	/** Search-appearance keys: a title for Google, not for the page. */
	const SEO_WORDS = '/(seo|yoast|rank_?math|wpseo|og_|opengraph|twitter|meta_?title|aioseo)/i';

	/**
	 * Every header field for a batch of terms, keyed by term id.
	 *
	 * ONE query for the batch's meta (not one per term), and at most two more
	 * per cover id that names a post rather than an attachment.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<string,string>>
	 */
	public static function for_terms( array $ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $ids ) ) {
			return $out;
		}

		$rows = (array) $wpdb->get_results(
			'SELECT term_id, meta_key, meta_value FROM ' . $wpdb->prefix . 'termmeta
			 WHERE term_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')
			 ORDER BY meta_id DESC',
			ARRAY_A
		);

		// DESC and overwrite: the lowest meta_id wins, which is what
		// get_term_meta( $id, $key, true ) returns. KBB_Export_Wp::meta_for()
		// carries the same rule and the reason for it.
		$meta = array();

		foreach ( $rows as $row ) {
			$meta[ (int) $row['term_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}

		foreach ( $ids as $id ) {
			$out[ (int) $id ] = self::fields( isset( $meta[ (int) $id ] ) ? $meta[ (int) $id ] : array() );
		}

		return $out;
	}

	/**
	 * The four column values for one term, '' where there is nothing.
	 *
	 * @param array<int,array<string,string>> $header
	 * @return array<string,string>
	 */
	public static function row( array $header, $id ) {
		$fields = isset( $header[ (int) $id ] ) ? $header[ (int) $id ] : array();
		$out    = array();

		foreach ( self::COLUMNS as $column ) {
			$out[ $column ] = isset( $fields[ $column ] ) ? (string) $fields[ $column ] : '';
		}

		return $out;
	}

	/**
	 * Pick the banner, the title and the subtitle out of one term's meta.
	 *
	 * @param array<string,string> $meta key => raw value
	 * @return array<string,string>
	 */
	public static function fields( array $meta ) {
		$images    = array();
		$titles    = array();
		$subtitles = array();

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;

			if ( self::skipped( $key ) ) {
				continue;
			}

			$lower = strtolower( $key );

			if ( preg_match( '/(subtitle|sub_title|sub-title|subheading|sub_heading|tagline)/', $lower ) === 1 ) {
				$text = self::text( $value );

				if ( '' !== $text ) {
					$subtitles[ $key ] = $text;
				}

				continue;
			}

			$rank = self::image_rank( $lower );

			if ( null !== $rank ) {
				$resolved = self::picture( $value );

				if ( '' !== $resolved['url'] ) {
					$images[] = array( 'rank' => $rank, 'key' => $key, 'url' => $resolved['url'], 'via' => $resolved['via'] );
				}

				continue;
			}

			if ( preg_match( '/(^|[_-])(title|heading)([_-]|$)/', $lower ) === 1 && preg_match( self::SEO_WORDS, $lower ) !== 1 ) {
				$text = self::text( $value );

				if ( '' !== $text ) {
					$titles[ $key ] = $text;
				}
			}
		}

		usort(
			$images,
			function ( $a, $b ) {
				return $a['rank'] === $b['rank'] ? strcmp( $a['key'], $b['key'] ) : $a['rank'] - $b['rank'];
			}
		);

		ksort( $titles );
		ksort( $subtitles );

		$out = array( 'banner_image' => '', 'banner_source_key' => '', 'title_override' => '', 'subtitle' => '' );

		if ( ! empty( $images ) ) {
			$out['banner_image']      = $images[0]['url'];
			$out['banner_source_key'] = $images[0]['key'] . ( '' === $images[0]['via'] ? '' : ' (' . $images[0]['via'] . ')' );
		}

		if ( ! empty( $titles ) ) {
			$out['title_override'] = (string) reset( $titles );
		}

		if ( ! empty( $subtitles ) ) {
			$out['subtitle'] = (string) reset( $subtitles );
		}

		return $out;
	}

	/** Bookkeeping, ACF's `_field` references and product counts are never header fields. */
	private static function skipped( $key ) {
		return in_array( $key, self::SKIP, true )
			|| '' === $key
			|| '_' === substr( $key, 0, 1 )
			|| 0 === strpos( $key, 'product_count_' );
	}

	/** @return int|null the rank of a picture-ish key name, or null when it is not one */
	private static function image_rank( $lower ) {
		$best = null;

		foreach ( self::IMAGE_WORDS as $word => $rank ) {
			if ( false !== strpos( $lower, $word ) && ( null === $best || $rank < $best ) ) {
				$best = $rank;
			}
		}

		return $best;
	}

	/** A single line of plain text, or '' for anything that is not one. */
	private static function text( $raw ) {
		$value = maybe_unserialize( $raw );

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) );

		// A bare number is an id, never words someone wrote for a header.
		if ( '' === $value || is_numeric( $value ) ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 300 ) : substr( $value, 0, 300 );
	}

	/**
	 * A picture address out of whatever shape a meta value stores it in.
	 *
	 * @return array{url: string, via: string}
	 */
	public static function picture( $raw ) {
		$value = maybe_unserialize( $raw );

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );

			if ( '' !== $trimmed && '{' === substr( $trimmed, 0, 1 ) ) {
				$decoded = json_decode( $trimmed, true );

				if ( is_array( $decoded ) ) {
					$value = $decoded;
				}
			}
		}

		if ( is_array( $value ) ) {
			foreach ( array( 'url', 'src', 'full' ) as $field ) {
				if ( isset( $value[ $field ] ) && is_string( $value[ $field ] ) && self::is_url( $value[ $field ] ) ) {
					return array( 'url' => trim( $value[ $field ] ), 'via' => '' );
				}
			}

			foreach ( array( 'id', 'ID', 'attachment_id' ) as $field ) {
				if ( isset( $value[ $field ] ) && is_numeric( $value[ $field ] ) ) {
					return self::from_id( (int) $value[ $field ] );
				}
			}

			$first = reset( $value );

			return is_numeric( $first ) ? self::from_id( (int) $first ) : array( 'url' => '', 'via' => '' );
		}

		if ( is_numeric( $value ) ) {
			return self::from_id( (int) $value );
		}

		if ( is_string( $value ) && self::is_url( $value ) ) {
			return array( 'url' => trim( $value ), 'via' => '' );
		}

		return array( 'url' => '', 'via' => '' );
	}

	private static function is_url( $value ) {
		$value = trim( (string) $value );

		return 1 === preg_match( '#^(https?:)?//[^\s"<>]+$#i', $value )
			|| 1 === preg_match( '#^/wp-content/uploads/[^\s"<>]+$#i', $value );
	}

	/**
	 * An attachment's address -- or, for an id naming any OTHER post, the first
	 * picture that post shows. That second branch is a Rey "page cover": a
	 * global section assigned to the term, whose picture is an Elementor
	 * background inside it rather than an attachment of its own.
	 *
	 * @return array{url: string, via: string}
	 */
	private static function from_id( $id ) {
		if ( $id <= 0 ) {
			return array( 'url' => '', 'via' => '' );
		}

		$url = KBB_Export_Wp::attachment_url( $id );

		if ( '' !== $url ) {
			return array( 'url' => $url, 'via' => '' );
		}

		global $wpdb;

		$post = $wpdb->get_row(
			'SELECT ID, post_type FROM ' . $wpdb->prefix . 'posts WHERE ID = ' . (int) $id . ' LIMIT 1',
			ARRAY_A
		);

		if ( empty( $post ) ) {
			return array( 'url' => '', 'via' => '' );
		}

		$meta = KBB_Export_Wp::post_meta( array( $id ), array( '_elementor_data', '_thumbnail_id' ) );
		$meta = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
		$via  = $post['post_type'] . ' ' . $id;

		if ( isset( $meta['_elementor_data'] ) ) {
			$found = self::first_elementor_image( json_decode( (string) $meta['_elementor_data'], true ) );

			if ( '' !== $found ) {
				return array( 'url' => $found, 'via' => $via );
			}
		}

		if ( isset( $meta['_thumbnail_id'] ) ) {
			$url = KBB_Export_Wp::attachment_url( (int) $meta['_thumbnail_id'] );

			if ( '' !== $url ) {
				return array( 'url' => $url, 'via' => $via . ' featured image' );
			}
		}

		return array( 'url' => '', 'via' => '' );
	}

	/** Depth-first, in document order: the first background or image a section shows. */
	private static function first_elementor_image( $node ) {
		if ( ! is_array( $node ) ) {
			return '';
		}

		if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
			foreach ( array( 'background_image', 'background_overlay_image', 'image', 'bg_image' ) as $field ) {
				$setting = isset( $node['settings'][ $field ] ) ? $node['settings'][ $field ] : null;

				if ( is_array( $setting ) && isset( $setting['url'] ) && is_string( $setting['url'] ) && self::is_url( $setting['url'] ) ) {
					return trim( $setting['url'] );
				}
			}
		}

		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			$children = $node['elements'];
		} elseif ( isset( $node[0] ) ) {
			$children = $node;
		} else {
			$children = array();
		}

		foreach ( $children as $child ) {
			$found = self::first_elementor_image( $child );

			if ( '' !== $found ) {
				return $found;
			}
		}

		return '';
	}

	/**
	 * The census: every term-meta key this taxonomy carries, with counts.
	 *
	 * ALWAYS written, "none" included, so the owner's first export answers
	 * "where does the old shop keep a category banner?" in the manifest
	 * whether or not any name above matched.
	 */
	public static function census_note( $taxonomy, $file ) {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			'SELECT tm.meta_key, COUNT(DISTINCT tm.term_id) AS terms, MIN(tm.meta_value) AS sample
			 FROM ' . $wpdb->prefix . 'termmeta tm
			 JOIN ' . $wpdb->prefix . 'term_taxonomy tt ON tt.term_id = tm.term_id
			 WHERE tt.taxonomy = ' . KBB_Export_Wp::quote( $taxonomy ) . '
			 GROUP BY tm.meta_key
			 ORDER BY terms DESC, tm.meta_key',
			ARRAY_A
		);

		$label = 'TERM META ON `' . $taxonomy . '` (' . $file . ')';

		if ( empty( $rows ) ) {
			return $label . ': none. No term of this taxonomy carries any term meta, so banner_image, '
				. 'title_override and subtitle are empty for every row.';
		}

		$parts  = array();
		$banner = array();

		foreach ( $rows as $row ) {
			$key    = (string) $row['meta_key'];
			$sample = trim( (string) preg_replace( '/\s+/', ' ', (string) $row['sample'] ) );

			if ( strlen( $sample ) > 60 ) {
				$sample = substr( $sample, 0, 57 ) . '...';
			}

			$parts[] = $key . ' x' . (int) $row['terms'] . ( '' === $sample ? '' : ' (e.g. "' . $sample . '")' );

			if ( ! self::skipped( $key ) && null !== self::image_rank( strtolower( $key ) ) ) {
				$banner[] = $key;
			}
		}

		return $label . ': ' . count( $rows ) . ' key' . ( 1 === count( $rows ) ? '' : 's' ) . ' -- '
			. implode( '; ', $parts ) . '. '
			. ( empty( $banner )
				? 'NONE of them is named like a banner, cover, header or background picture, so banner_image is '
					. 'empty: if the old shop shows a picture behind its category titles, it is the category '
					. 'thumbnail (`thumbnail_id`, the `image` column) or something not stored on the term.'
				: 'Read as banner candidates: ' . implode( ', ', $banner ) . ' -- banner_source_key on each row says '
					. 'which one it came from.' );
	}
}
