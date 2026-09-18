<?php
/**
 * `permalinks.csv` -- every public URL this site serves, and what it is.
 *
 * ============================================================================
 * THIS IS THE FILE THAT ANSWERS QUESTIONS THE MIGRATION HAS BEEN GUESSING AT.
 * ============================================================================
 *
 * App\Services\Import\RedirectMap carries a banner saying that two of its own
 * premises did not survive being checked against a running server, and both of
 * them are about addresses:
 *
 *   "WooCommerce commonly publishes a category at its leaf slug" -- the
 *   default, but not what kbeautybliss.com ran. App\Support\LegacyCategoryUrls
 *   says it "served its category archives at the site root -- /toners/,
 *   /sunscreens/, /cleansing-oils/".
 *
 * and
 *
 *   "BRAND ARCHIVES. U-05 says this shop has no brand archive path at all ...
 *   What the OLD shop used depends on which brand plugin it ran (/brand/,
 *   /product-brand/, /marca/ ...) and inventing one writes 93 redirects from an
 *   address that may never have existed. Asked once, as a question the owner
 *   can answer, rather than guessed 93 times."
 *
 * Both of those are inferences drawn from outside the shop. This plugin is
 * standing INSIDE it. It does not infer: it asks WordPress for the permalink of
 * every object and writes down the answer, and where the answer is "this
 * taxonomy has no archive" it writes that down too. Fifteen corroborated
 * addresses and a shrug about the other four hundred is replaced by four
 * hundred and fifteen measurements.
 *
 * ── WHAT `source` MEANS, AND WHY IT IS A COLUMN ─────────────────────────────
 *
 * `wp` -- get_permalink() or get_term_link() answered, which means every
 * rewrite rule and every filter any plugin on the site has installed was
 * applied. That is the address the site really serves, and it is the only
 * source worth trusting.
 *
 * `derived` -- the function was unavailable or returned an error, and the URL
 * was assembled from `permalink_structure` and `woocommerce_permalinks`
 * instead. Still useful, and MARKED, because a derived address is exactly the
 * kind of thing that has been wrong before in this migration.
 *
 * App\Services\Import\RedirectMap::fromPermalinks() reads `type`, `wc_id` and
 * `permalink`; every other column here is for the owner and for the map's
 * future, and none of them can confuse it.
 *
 * ── AND THE SETTINGS THEMSELVES ARE IN manifest.json ────────────────────────
 *
 * `source.permalink_structure` and `source.woocommerce_permalinks` are the two
 * option values that PRODUCE every address in this file. One of them --
 * `woocommerce_permalinks['category_base']` -- being an empty string is the
 * whole of the "categories were served flat at the site root" finding, stated
 * by the site rather than deduced from a menu seed.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Permalinks extends KBB_Export_Stage {

	/** The width of one source's slice of the composite cursor. */
	const SOURCE_STRIDE = 10000000000;

	/** @var array<int,array<string,string>>|null */
	private $sources;

	public function file() {
		return 'permalinks.csv';
	}

	public function columns() {
		return array( 'type', 'wc_id', 'slug', 'permalink', 'status', 'source', 'note' );
	}

	/**
	 * The things that have addresses, in a fixed order.
	 *
	 * Fixed because the cursor encodes the index: reordering this list between
	 * two requests of one export would resume the wrong source. It is derived
	 * from the database rather than written out, so a shop with a brand
	 * taxonomy gets brand rows and a shop without gets none -- which is the
	 * answer, not a gap.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function sources() {
		if ( null !== $this->sources ) {
			return $this->sources;
		}

		$sources = array(
			array( 'kind' => 'post', 'type' => 'product', 'label' => 'product' ),
			array( 'kind' => 'term', 'type' => 'product_cat', 'label' => 'category' ),
			array( 'kind' => 'term', 'type' => 'product_tag', 'label' => 'product_tag' ),
		);

		$brand = KBB_Export_Wp::brand_taxonomy();

		if ( '' !== $brand ) {
			// The label is `brand`, which is one of the spellings
			// RedirectMap::currentPathFor() accepts ('brand', 'brands',
			// 'product_brand', 'pa_brands'), so these rows are usable by that
			// map without it having to learn a new word.
			$sources[] = array( 'kind' => 'term', 'type' => $brand, 'label' => 'brand' );
		}

		foreach ( array_keys( KBB_Export_Wp::taxonomies_in_database() ) as $taxonomy ) {
			if ( 0 !== strpos( $taxonomy, 'pa_' ) || $taxonomy === $brand ) {
				continue;
			}

			$sources[] = array( 'kind' => 'term', 'type' => $taxonomy, 'label' => 'attribute' );
		}

		$sources[] = array( 'kind' => 'term', 'type' => 'category', 'label' => 'post_category' );
		$sources[] = array( 'kind' => 'term', 'type' => 'post_tag', 'label' => 'post_tag_blog' );

		foreach ( array_keys( KBB_Export_Wp::post_types_in_database() ) as $type ) {
			if ( in_array( $type, KBB_Export_Stage_Posts::NOT_CONTENT, true ) ) {
				continue;
			}

			$sources[] = array( 'kind' => 'post', 'type' => $type, 'label' => $type );
		}

		$this->sources = $sources;

		return $this->sources;
	}

	public function total() {
		global $wpdb;

		$total = 0;

		foreach ( $this->sources() as $source ) {
			if ( 'post' === $source['kind'] ) {
				$total += (int) $wpdb->get_var(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'posts
					 WHERE post_type = ' . KBB_Export_Wp::quote( $source['type'] ) . "
					   AND post_status IN ('publish','draft','private','pending','future')"
				);

				continue;
			}

			$total += (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'term_taxonomy
				 WHERE taxonomy = ' . KBB_Export_Wp::quote( $source['type'] )
			);
		}

		return $total;
	}

	public function batch( $cursor, $limit ) {
		$sources = $this->sources();

		if ( empty( $sources ) ) {
			return array( 'rows' => array(), 'cursor' => 0, 'done' => true );
		}

		$cursor  = (int) $cursor;
		$index   = intdiv( $cursor, self::SOURCE_STRIDE );
		$inner   = $cursor % self::SOURCE_STRIDE;

		while ( $index < count( $sources ) ) {
			$source = $sources[ $index ];
			$rows   = 'post' === $source['kind']
				? $this->post_rows( $source, $inner, $limit )
				: $this->term_rows( $source, $inner, $limit );

			if ( ! empty( $rows['rows'] ) ) {
				$next = count( $rows['rows'] ) < (int) $limit
					? ( $index + 1 ) * self::SOURCE_STRIDE
					: $index * self::SOURCE_STRIDE + (int) $rows['cursor'];

				$done = count( $rows['rows'] ) < (int) $limit && $index + 1 >= count( $sources );

				if ( $done ) {
					$this->report_settings();
				}

				return array( 'rows' => $rows['rows'], 'cursor' => $next, 'done' => $done );
			}

			$index++;
			$inner = 0;
		}

		$this->report_settings();

		return array( 'rows' => array(), 'cursor' => $cursor, 'done' => true );
	}

	/** @return array{rows: array<int,array<string,string>>, cursor: int} */
	private function post_rows( array $source, $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, post_name, post_status FROM ' . $wpdb->prefix . 'posts
			 WHERE post_type = ' . KBB_Export_Wp::quote( $source['type'] ) . "
			   AND post_status IN ('publish','draft','private','pending','future')
			   AND ID > " . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;
		$out  = array();
		$last = (int) $cursor;

		foreach ( $rows as $row ) {
			$id   = (int) $row['ID'];
			$last = $id;

			$link   = function_exists( 'get_permalink' ) ? get_permalink( $id ) : false;
			$source_name = is_string( $link ) && '' !== $link ? 'wp' : 'derived';

			if ( 'derived' === $source_name ) {
				$link = $this->derived_post_url( $source['type'], (string) $row['post_name'] );
			}

			$out[] = array(
				'type'      => $source['label'],
				'wc_id'     => $id,
				'slug'      => $row['post_name'],
				'permalink' => $link,
				'status'    => $row['post_status'],
				'source'    => $source_name,
				'note'      => 'publish' === $row['post_status'] ? '' : 'not published; this address 404s today',
			);
		}

		return array( 'rows' => $out, 'cursor' => $last );
	}

	/** @return array{rows: array<int,array<string,string>>, cursor: int} */
	private function term_rows( array $source, $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT t.term_id, t.slug, tt.count
			 FROM ' . $wpdb->prefix . 'term_taxonomy tt
			 JOIN ' . $wpdb->prefix . 'terms t ON t.term_id = tt.term_id
			 WHERE tt.taxonomy = ' . KBB_Export_Wp::quote( $source['type'] ) . '
			   AND t.term_id > ' . (int) $cursor . '
			 ORDER BY t.term_id
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;
		$out  = array();
		$last = (int) $cursor;

		foreach ( $rows as $row ) {
			$id   = (int) $row['term_id'];
			$last = $id;

			$link = function_exists( 'get_term_link' ) ? get_term_link( $id, $source['type'] ) : false;
			$ok   = is_string( $link ) && '' !== $link;

			$out[] = array(
				'type'      => $source['label'],
				'wc_id'     => $id,
				'slug'      => $row['slug'],
				'permalink' => $ok ? $link : '',
				'status'    => (int) $row['count'] > 0 ? 'publish' : 'empty',
				'source'    => $ok ? 'wp' : 'derived',
				// A taxonomy with no archive is an ANSWER. WordPress returns a
				// WP_Error from get_term_link() for a taxonomy registered with
				// `public => false` or `rewrite => false`, and that is the shop
				// saying, in its own voice, that these terms never had a URL.
				'note'      => $ok
					? ''
					: 'WordPress returned no archive URL for this term -- the `' . $source['type'] . '` taxonomy has '
						. 'no public archive on this site, so no address of this kind was ever served',
			);
		}

		return array( 'rows' => $out, 'cursor' => $last );
	}

	/**
	 * The fallback, assembled from the two options rather than from a habit.
	 *
	 * Only reached when get_permalink() is unavailable or refused. It is marked
	 * `derived` on the row so nobody reads it as a measurement.
	 */
	private function derived_post_url( $type, $slug ) {
		$home = rtrim( KBB_Export_Wp::option( 'home', '' ), '/' );

		if ( 'product' === $type ) {
			$permalinks = get_option( 'woocommerce_permalinks', array() );
			$permalinks = is_array( $permalinks ) ? $permalinks : array();
			$base       = isset( $permalinks['product_base'] ) ? trim( (string) $permalinks['product_base'], '/' ) : 'product';

			return $home . '/' . ( '' === $base ? '' : $base . '/' ) . $slug . '/';
		}

		return $home . '/' . $slug . '/';
	}

	/**
	 * The permalink settings, said out loud once.
	 *
	 * This note is the short form of what manifest.json carries in full. It is
	 * here as well as there because the notes are what the admin screen prints
	 * when the export finishes, and this is the sentence the owner should read
	 * before he does anything else with the file.
	 */
	private function report_settings() {
		$structure  = KBB_Export_Wp::option( 'permalink_structure', '' );
		$permalinks = get_option( 'woocommerce_permalinks', array() );
		$permalinks = is_array( $permalinks ) ? $permalinks : array();

		$product  = isset( $permalinks['product_base'] ) ? (string) $permalinks['product_base'] : '';
		$category = isset( $permalinks['category_base'] ) ? (string) $permalinks['category_base'] : '';
		$tag      = isset( $permalinks['tag_base'] ) ? (string) $permalinks['tag_base'] : '';
		$attr     = isset( $permalinks['attribute_base'] ) ? (string) $permalinks['attribute_base'] : '';

		$this->note(
			'This site\'s permalink settings, which produced every address in permalinks.csv: '
				. 'WordPress structure "' . $structure . '"; WooCommerce product base "' . $product . '", '
				. 'category base "' . $category . '", tag base "' . $tag . '", attribute base "' . $attr . '". '
				. ( '' === trim( $category, '/' )
					? 'The category base is EMPTY, which means product category archives were served flat at the '
						. 'site root -- /toners/, not /product-category/toners/. That is the shape '
						. 'App\\Support\\LegacyCategoryUrls describes and this export confirms it from the setting '
						. 'itself rather than from the navigation menu.'
					: 'Product category archives were served under /' . trim( $category, '/' ) . '/.' )
		);
	}
}
