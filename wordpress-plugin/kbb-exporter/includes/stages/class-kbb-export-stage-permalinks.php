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

		/*
		 * LAST, AND LAST ON PURPOSE.
		 *
		 * The cursor encodes this list's INDEX (see SOURCE_STRIDE), so a source
		 * inserted anywhere but the end renumbers every source after it and a
		 * resumed export continues into the wrong one. Appending is the only
		 * safe edit to this list, and it is also why `old-slug` is emitted
		 * after every current address rather than beside the object it belongs
		 * to: a reader grouping permalinks.csv by `type` still finds the
		 * object's CURRENT address first.
		 */
		$sources[] = array( 'kind' => 'old_slug', 'type' => '', 'label' => 'old-slug' );

		$this->sources = $sources;

		return $this->sources;
	}

	public function total() {
		global $wpdb;

		$total = 0;

		foreach ( $this->sources() as $source ) {
			if ( 'old_slug' === $source['kind'] ) {
				$total += (int) $wpdb->get_var(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'postmeta pm
					 JOIN ' . $wpdb->prefix . "posts p ON p.ID = pm.post_id
					 WHERE pm.meta_key = '_wp_old_slug'
					   AND p.post_status IN ('publish','draft','private','pending','future')
					   AND p.post_type NOT IN (" . $this->not_content_list() . ')'
				);

				continue;
			}

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

			if ( 'old_slug' === $source['kind'] ) {
				$rows = $this->old_slug_rows( $inner, $limit );
			} elseif ( 'post' === $source['kind'] ) {
				$rows = $this->post_rows( $source, $inner, $limit );
			} else {
				$rows = $this->term_rows( $source, $inner, $limit );
			}

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
	 * The post types whose previous addresses are NOT worth a row, as SQL.
	 *
	 * `KBB_Export_Stage_Posts::NOT_CONTENT` is the nearest existing list and it
	 * is ALMOST this one -- but not quite, and the difference is the whole
	 * catalogue.
	 *
	 * ── WHY `product` IS TAKEN BACK OUT, WHICH IS NOT A DETAIL ──────────────
	 *
	 * NOT_CONTENT answers a different question. It is posts.csv's list of "post
	 * types this file does not carry", and `product` is in it because
	 * products.csv carries products -- not because a product has no address. A
	 * product has the most valuable address on the shop.
	 *
	 * Used verbatim here it therefore drops EVERY PRODUCT RENAME, which is the
	 * single largest source of old addresses on a six-year-old catalogue and
	 * the one the owner most needs to keep: a renamed product's old URL is on
	 * Google, in newsletters, and on other people's blogs. Measured, not
	 * argued -- with NOT_CONTENT used as-is the fixture's two `_wp_old_slug`
	 * rows for product 4021 came out of the export and the other two did not.
	 *
	 * MUTATION NOTE. Delete the `'product' !== $type` guard below and
	 * `it('carries every previous address, products included')` goes red naming
	 * both of them.
	 *
	 * Every other NOT_CONTENT entry belongs here for the reason this method
	 * wants: an order, a variation, a revision, a menu item, an attachment and
	 * a block template are either WordPress's own plumbing or a thing this shop
	 * does not serve at a public address, so a redirect from one goes nowhere
	 * useful and a redirect from an attachment points a picture at a page.
	 *
	 * Shared by the count and the batch so the two cannot drift: a type counted
	 * and not emitted makes `total()` a denominator the bar never reaches.
	 */
	private function not_content_list() {
		$out = array();

		foreach ( KBB_Export_Stage_Posts::NOT_CONTENT as $type ) {
			if ( 'product' !== $type ) {
				$out[] = KBB_Export_Wp::quote( $type );
			}
		}

		return implode( ',', $out );
	}

	/**
	 * `_wp_old_slug` -- every address this post USED to have.
	 *
	 * ========================================================================
	 * THIS IS THE ONLY RECORD OF AN ADDRESS THE SITE NO LONGER SERVES, AND
	 * NOTHING IN THIS MIGRATION HAS EVER READ IT.
	 * ========================================================================
	 *
	 * Every other row in permalinks.csv is an address the site serves TODAY.
	 * Those are the easy half: the object is still there, and what is wanted is
	 * the mapping from its old shape to its new one.
	 *
	 * `_wp_old_slug` is the hard half. WordPress writes one of these rows every
	 * time a PUBLISHED post's slug changes, and `wp_old_slug_redirect()` -- core,
	 * on every front-end 404 -- then answers the OLD address with a 301 to the
	 * new one. So a product renamed from `vitamin-c-serum` to
	 * `glow-vitamin-c-serum` in 2021 has been quietly 301ing ever since, Google
	 * still holds the old address, shoppers still have it bookmarked, and
	 * inbound links still point at it.
	 *
	 * That redirect is a WORDPRESS FEATURE. It dies with WordPress. Switch the
	 * old shop off without carrying these rows and every one of those addresses
	 * becomes a hard 404 on the new shop on day one -- and there is no way to
	 * recover the list afterwards, because it only ever existed in the database
	 * that was turned off.
	 *
	 * ── WHY THE URL IS BUILT FROM THE CURRENT PERMALINK ─────────────────────
	 *
	 * `_wp_old_slug` stores a SLUG, not a URL. Turning it back into the address
	 * the site served means knowing the base it sat under -- and this plugin's
	 * whole reason for existing is that it does not guess at bases.
	 *
	 * So it does not assemble one. It takes `get_permalink()`'s answer for the
	 * post -- the real address, with every rewrite rule and filter applied --
	 * and swaps the LAST path segment for the old slug. For every permalink
	 * structure ending in `%postname%`, which is what produces a `_wp_old_slug`
	 * row in the first place, that is exact rather than derived.
	 *
	 * Where the last segment is NOT the post's current slug -- a numeric or
	 * id-based structure, or a draft whose permalink is `?p=123` -- the address
	 * CANNOT be reconstructed, and the row says so in its note with an empty
	 * `permalink`. RedirectMap skips an empty permalink, so a row this plugin
	 * could not measure proposes nothing; it is counted and named instead. The
	 * alternative is inventing an address that may never have existed, which is
	 * the mistake the brand-archive note in this file's header exists to avoid.
	 *
	 * ── AND IT NEEDS NO IMPORTER ────────────────────────────────────────────
	 *
	 * `RedirectMap::fromPermalinks()` reads `type`, `wc_id` and `permalink` and
	 * resolves the object's CURRENT path from `wc_id`. An old-slug row carries
	 * the same `type` and the same `wc_id` as that object's current row, so the
	 * map already does exactly the right thing with it: source = the old
	 * address, target = wherever the object lives in this shop now. Nothing on
	 * the Laravel side has to learn a new word.
	 *
	 * @return array{rows: array<int,array<string,string>>, cursor: int}
	 */
	private function old_slug_rows( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT pm.meta_id, pm.post_id, pm.meta_value AS old_slug, p.post_type, p.post_name, p.post_status
			 FROM ' . $wpdb->prefix . 'postmeta pm
			 JOIN ' . $wpdb->prefix . "posts p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_wp_old_slug'
			   AND p.post_status IN ('publish','draft','private','pending','future')
			   AND p.post_type NOT IN (" . $this->not_content_list() . ')
			   AND pm.meta_id > ' . (int) $cursor . '
			 ORDER BY pm.meta_id
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;
		$out  = array();
		$last = (int) $cursor;

		foreach ( $rows as $row ) {
			$last = (int) $row['meta_id'];

			$id       = (int) $row['post_id'];
			$old_slug = (string) $row['old_slug'];

			$current = function_exists( 'get_permalink' ) ? get_permalink( $id ) : false;
			$current = is_string( $current ) ? $current : '';
			$address = $this->swap_last_segment( $current, (string) $row['post_name'], $old_slug );

			$out[] = array(
				// The object's own label, so RedirectMap resolves `wc_id`
				// against the same table it would for the current address.
				// `product` is the one type whose label is not its post type.
				'type'      => 'product' === $row['post_type'] ? 'product' : (string) $row['post_type'],
				'wc_id'     => $id,
				'slug'      => $old_slug,
				'permalink' => $address,
				// NOT the post's status. This address is retired by definition:
				// the post answers at a different one now.
				'status'    => 'old-slug',
				// `derived` and never `wp`: get_permalink() answered about the
				// CURRENT address, and the last segment was substituted. The
				// column means "this was measured", and this was not.
				'source'    => 'derived',
				'note'      => '' === $address
					? 'WordPress recorded `' . $old_slug . '` as a previous slug of this ' . $row['post_type']
						. ", but its current permalink ('" . $current . "') does not end in its current slug ('"
						. $row['post_name'] . "'), so the address this site served for the old slug cannot be "
						. 'reconstructed and is NOT guessed at. wp_old_slug_redirect() is answering it on '
						. 'WordPress today and nothing will answer it after the cutover.'
					: 'a PREVIOUS address of this ' . $row['post_type'] . '. WordPress core (wp_old_slug_redirect) '
						. '301s it to the current address today; that redirect stops the moment WordPress does, '
						. 'so it has to become a row in this shop or it becomes a 404.',
			);
		}

		return array( 'rows' => $out, 'cursor' => $last );
	}

	/**
	 * `$url` with its last path segment replaced, or '' when it is not `$from`.
	 *
	 * The guard is the point of the function. Returning a URL when the last
	 * segment was something else would be inventing an address, and an invented
	 * address that looks measured is worse than no address at all -- it would
	 * be written as a 301 and send a real shopper somewhere the old site never
	 * had.
	 */
	private function swap_last_segment( $url, $from, $to ) {
		if ( '' === $url || '' === $from || '' === $to ) {
			return '';
		}

		$path = parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$trailing = '/' === substr( $path, -1 );
		$segments = explode( '/', trim( $path, '/' ) );
		$leaf     = end( $segments );

		// WordPress stores post_name percent-encoded for a non-ASCII slug and
		// get_permalink() returns it the same way, so the two compare directly.
		if ( rawurldecode( (string) $leaf ) !== rawurldecode( $from ) ) {
			return '';
		}

		$segments[ count( $segments ) - 1 ] = $to;

		$rebuilt = '/' . implode( '/', $segments ) . ( $trailing ? '/' : '' );
		$parts   = parse_url( $url );

		$prefix = '';

		if ( isset( $parts['scheme'], $parts['host'] ) ) {
			$prefix = $parts['scheme'] . '://' . $parts['host']
				. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}

		return $prefix . $rebuilt;
	}

	/**
	 * How many previous addresses this site is still answering, said out loud.
	 *
	 * The count matters on its own: it is the number of addresses that go from
	 * "301, quietly, for years" to "404" on cutover day, and it is a number
	 * nobody in this migration has ever had.
	 */
	private function report_old_slugs() {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'postmeta pm
			 JOIN ' . $wpdb->prefix . "posts p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_wp_old_slug'
			   AND p.post_status IN ('publish','draft','private','pending','future')
			   AND p.post_type NOT IN (" . $this->not_content_list() . ')'
		);

		if ( 0 === $total ) {
			return;
		}

		$this->note(
			$total . ' previous address' . ( 1 === $total ? '' : 'es' ) . ' (`_wp_old_slug`) '
				. ( 1 === $total ? 'is' : 'are' ) . " in permalinks.csv with status `old-slug`. WordPress core "
				. 'answers these with a 301 on every front-end 404 (wp_old_slug_redirect), which is why they still '
				. 'work today and why nobody has noticed them. That is a WordPress feature and it stops when '
				. 'WordPress does: without these rows every one of these addresses 404s on the new shop, and the '
				. 'list cannot be recovered afterwards because it only ever existed in the old database.'
		);
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
		$this->report_old_slugs();

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
