<?php
/**
 * The only place this plugin touches WordPress.
 *
 * Every stage goes through here, which is what makes the export testable
 * without a WordPress installation: the harness in wordpress-plugin/harness
 * stubs `$wpdb` over PDO and the fourteen functions named below, and the stages
 * themselves are then exercised byte for byte as they run on the live site.
 *
 * THE FUNCTIONS THIS PLUGIN CALLS, AND NOTHING ELSE:
 *
 *   get_option, update_option, delete_option
 *   wp_upload_dir, home_url
 *   get_permalink, get_term_link, get_post_type_archive_link
 *   get_taxonomies, get_post_types, taxonomy_exists
 *   wp_get_attachment_url, wp_get_attachment_metadata, maybe_unserialize
 *
 * A list that can be read in one breath is a list somebody can check. Adding a
 * call to a fifteenth function without adding it here is how a stage comes to
 * work on the live site and not under the harness -- or, far worse, the other
 * way round.
 *
 * ── META IS FETCHED IN BULK, NEVER PER ROW ──────────────────────────────────
 *
 * get_post_meta() inside a loop over 4,000 orders is 4,000 queries on a shared
 * host, and it is the single most common reason a WordPress export times out.
 * Every stage here fetches one batch of ids, then ONE query for all of that
 * batch's meta, and pivots it in PHP. Same for terms.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Wp {

	/**
	 * Post meta for a batch of posts, pivoted to [post_id][meta_key] => value.
	 *
	 * Only the keys asked for, because `wp_postmeta` on a real shop carries
	 * every key every plugin ever wrote and selecting all of them for 500
	 * products is tens of megabytes for the six values a stage wants.
	 *
	 * A REPEATED meta key resolves to the same value get_post_meta($id, $key,
	 * true) returns, which is the row with the LOWEST meta_id. The query is
	 * ordered DESC and the pivot overwrites, so the lowest is assigned last and
	 * wins. Getting this backwards would pick a different value from the one
	 * the shop's own pages show, on exactly the rows where a plugin has written
	 * a key twice.
	 *
	 * @param array<int,int>    $ids
	 * @param array<int,string> $keys
	 * @return array<int,array<string,string>>
	 */
	public static function post_meta( array $ids, array $keys ) {
		return self::meta_for( 'postmeta', 'post_id', $ids, $keys );
	}

	/** @return array<int,array<string,string>> */
	public static function comment_meta( array $ids, array $keys ) {
		return self::meta_for( 'commentmeta', 'comment_id', $ids, $keys );
	}

	/** @return array<int,array<string,string>> */
	public static function user_meta( array $ids, array $keys ) {
		return self::meta_for( 'usermeta', 'user_id', $ids, $keys );
	}

	/** @return array<int,array<string,string>> */
	public static function term_meta( array $ids, array $keys ) {
		return self::meta_for( 'termmeta', 'term_id', $ids, $keys );
	}

	/** @return array<int,array<string,string>> */
	private static function meta_for( $table, $owner_column, array $ids, array $keys ) {
		global $wpdb;

		$out = array();

		if ( empty( $ids ) || empty( $keys ) ) {
			return $out;
		}

		$id_list  = implode( ',', array_map( 'intval', $ids ) );
		$key_list = implode( ',', array_map( array( __CLASS__, 'quote' ), $keys ) );
		$full     = $wpdb->prefix . $table;

		// `wp_usermeta` names its primary key `umeta_id` and every other meta
		// table names it `meta_id`. A WordPress quirk, not a choice, and a
		// query that assumes the common spelling fails on exactly one of the
		// four tables -- which is the shape of bug that passes every test that
		// happens not to cover customers.
		$key_column = 'usermeta' === $table ? 'umeta_id' : 'meta_id';

		// DESC so that the FIRST row by that key is the last one assigned,
		// which is the value get_post_meta($id, $key, true) returns.
		$rows = $wpdb->get_results(
			"SELECT {$owner_column} AS owner_id, meta_key, meta_value
			 FROM {$full}
			 WHERE {$owner_column} IN ({$id_list}) AND meta_key IN ({$key_list})
			 ORDER BY {$key_column} DESC",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['owner_id'] ][ $row['meta_key'] ] = $row['meta_value'];
		}

		return $out;
	}

	/**
	 * EVERY meta key a batch of posts carries, pivoted the same way.
	 *
	 * Used only by the SEO stage, which cannot name its keys in advance: a shop
	 * may run Yoast free, Yoast Premium or the WooCommerce SEO add-on, and
	 * App\Support\YoastTiers exists precisely because which keys are present is
	 * the question. Narrowed by a LIKE on the two prefixes rather than selecting
	 * the lot.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<string,string>>
	 */
	public static function post_meta_like( array $ids, array $prefixes ) {
		global $wpdb;

		$out = array();

		if ( empty( $ids ) || empty( $prefixes ) ) {
			return $out;
		}

		$id_list = implode( ',', array_map( 'intval', $ids ) );
		$clauses = array();

		foreach ( $prefixes as $prefix ) {
			$clauses[] = 'meta_key LIKE ' . self::quote( $prefix . '%' );
		}

		$where = implode( ' OR ', $clauses );
		$full  = $wpdb->prefix . 'postmeta';

		$rows = $wpdb->get_results(
			"SELECT post_id AS owner_id, meta_key, meta_value
			 FROM {$full}
			 WHERE post_id IN ({$id_list}) AND ({$where})
			 ORDER BY meta_id DESC",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['owner_id'] ][ $row['meta_key'] ] = $row['meta_value'];
		}

		return $out;
	}

	/**
	 * The terms a batch of objects carries, per taxonomy.
	 *
	 * @param array<int,int>    $ids
	 * @param array<int,string> $taxonomies
	 * @return array<int,array<string,array<int,array{term_id:int,name:string,slug:string}>>>
	 */
	public static function terms_for( array $ids, array $taxonomies ) {
		global $wpdb;

		$out = array();

		if ( empty( $ids ) || empty( $taxonomies ) ) {
			return $out;
		}

		$id_list = implode( ',', array_map( 'intval', $ids ) );
		$tax_list = implode( ',', array_map( array( __CLASS__, 'quote' ), $taxonomies ) );

		$rows = $wpdb->get_results(
			"SELECT tr.object_id, tt.taxonomy, t.term_id, t.name, t.slug, tt.term_taxonomy_id
			 FROM {$wpdb->prefix}term_relationships tr
			 JOIN {$wpdb->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 JOIN {$wpdb->prefix}terms t ON t.term_id = tt.term_id
			 WHERE tr.object_id IN ({$id_list}) AND tt.taxonomy IN ({$tax_list})
			 ORDER BY tr.object_id, tt.taxonomy, t.term_id",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['object_id'] ][ $row['taxonomy'] ][] = array(
				'term_id' => (int) $row['term_id'],
				'name'    => (string) $row['name'],
				'slug'    => (string) $row['slug'],
			);
		}

		return $out;
	}

	/**
	 * Which taxonomy on this site holds brands, or '' when none does.
	 *
	 * ASKED, NOT ASSUMED, and this is one of the two questions the whole
	 * permalinks file exists to settle. App\Services\Import\RedirectMap says in
	 * as many words that it will not guess a brand archive base because "what
	 * the OLD shop used depends on which brand plugin it ran (/brand/,
	 * /product-brand/, /marca/ ...) and inventing one writes 93 redirects from
	 * an address that may never have existed". This plugin is standing inside
	 * that shop and can simply look.
	 *
	 * The order is deliberate: `pa_brands` first, because that is what
	 * App\Services\Import\Entities\BrandImporter says production runs -- "brands
	 * live as the pa_brands product attribute, 93 terms of it". The others are
	 * the taxonomies the common brand plugins register, checked only if the
	 * first is absent.
	 */
	public static function brand_taxonomy() {
		$candidates = array( 'pa_brands', 'pa_brand', 'product_brand', 'yith_product_brand', 'pwb-brand', 'berocket_brand', 'brand' );

		foreach ( $candidates as $taxonomy ) {
			if ( self::taxonomy_has_rows( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		return '';
	}

	/** Does this taxonomy exist in the database with at least one term? */
	public static function taxonomy_has_rows( $taxonomy ) {
		global $wpdb;

		$count = $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'term_taxonomy WHERE taxonomy = ' . self::quote( $taxonomy )
		);

		return (int) $count > 0;
	}

	/** Every taxonomy that actually has rows in this database, with its term count. */
	public static function taxonomies_in_database() {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT taxonomy, COUNT(*) AS terms FROM ' . $wpdb->prefix . 'term_taxonomy GROUP BY taxonomy ORDER BY taxonomy',
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['taxonomy'] ] = (int) $row['terms'];
		}

		return $out;
	}

	/** Every post type that actually has rows, with its count, by status. */
	public static function post_types_in_database() {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT post_type, post_status, COUNT(*) AS n FROM ' . $wpdb->prefix . 'posts GROUP BY post_type, post_status ORDER BY post_type, post_status',
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['post_type'] ][ (string) $row['post_status'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * The attachment URL for an id, or ''.
	 *
	 * wp_get_attachment_url() is asked rather than the path being assembled,
	 * because a shop may be on a CDN or an offloaded uploads directory and the
	 * filter that rewrites the URL is the whole reason those work. What the
	 * catalogue REFERENCES is what the new shop has to fetch, and that is this
	 * string and not the one on disk.
	 */
	public static function attachment_url( $id ) {
		$id = (int) $id;

		if ( $id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_url( $id );

		return is_string( $url ) ? $url : '';
	}

	/** A site option, as a plain string. */
	public static function option( $name, $default = '' ) {
		$value = get_option( $name, $default );

		return is_scalar( $value ) ? (string) $value : $default;
	}

	/** The uploads base URL, no trailing slash. */
	public static function uploads_url() {
		$dir = wp_upload_dir();

		return isset( $dir['baseurl'] ) ? rtrim( (string) $dir['baseurl'], '/' ) : '';
	}

	/** The uploads base directory, no trailing slash. */
	public static function uploads_dir() {
		$dir = wp_upload_dir();

		return isset( $dir['basedir'] ) ? rtrim( (string) $dir['basedir'], '/' ) : '';
	}

	/**
	 * A UTC `Y-m-d H:i:s` as the same instant in the shop's own timezone.
	 *
	 * HPOS STORES EVERY DATE IN GMT AND THE LEGACY POST TABLE STORES BOTH.
	 * `wp_posts` carries post_date (local) beside post_date_gmt (UTC), while
	 * `wc_orders` carries only date_created_gmt. An export that emitted the raw
	 * column from each would be local on a legacy shop and UTC on an HPOS one,
	 * so ONE `--timezone` could never be right for both -- and the shop would
	 * discover it as a four-hour shift across its whole order history, which
	 * OrderImporter's own header calls out as the failure that is
	 * "indistinguishable from history" once the site is live.
	 *
	 * So both storages emit local in `date_created` and UTC in
	 * `date_created_gmt`, converted here, and OrderImporter's
	 * checkDeclaredTimezone() then has the GMT column it needs to check
	 * --timezone against and say so if the owner passed the wrong one.
	 *
	 * timezone_string is preferred over gmt_offset because it is the only one
	 * that knows about daylight saving: a shop in Europe/London that exports in
	 * July and imports in December gets an hour wrong from the offset and
	 * nothing from the name.
	 */
	public static function local_from_gmt( $gmt ) {
		$value = trim( (string) $gmt );

		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '';
		}

		try {
			$utc = new DateTime( $value, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) { // phpcs:ignore
			return $value;
		}

		$tz = self::option( 'timezone_string', '' );

		if ( '' !== $tz ) {
			try {
				$utc->setTimezone( new DateTimeZone( $tz ) );

				return $utc->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) { // phpcs:ignore
				// Falls through to the numeric offset.
			}
		}

		$seconds = (int) round( (float) self::option( 'gmt_offset', '0' ) * 3600 );

		return gmdate( 'Y-m-d H:i:s', $utc->getTimestamp() + $seconds );
	}

	/**
	 * A literal for direct interpolation.
	 *
	 * $wpdb->prepare() is the right tool for a fixed number of placeholders and
	 * the wrong one for an IN list whose width changes per batch: building the
	 * format string dynamically is where the placeholder-count bugs live. Ids
	 * go through intval and only strings come through here, which escapes with
	 * the driver's own routine rather than with addslashes.
	 */
	public static function quote( $value ) {
		global $wpdb;

		if ( method_exists( $wpdb, 'kbb_quote' ) ) {
			return $wpdb->kbb_quote( (string) $value );
		}

		return "'" . $wpdb->_real_escape( (string) $value ) . "'";
	}

	/**
	 * WordPress's local time as `Y-m-d H:i:s`, matching post_date.
	 *
	 * Deliberately NOT current_time(): this plugin has to produce the same
	 * string under the harness, and an ISO-8601 stamp with the site's offset is
	 * what manifest.json wants anyway.
	 */
	public static function now_iso() {
		$offset = self::option( 'gmt_offset', '0' );
		$tz     = self::option( 'timezone_string', '' );

		if ( '' !== $tz ) {
			try {
				$now = new DateTime( 'now', new DateTimeZone( $tz ) );

				return $now->format( 'c' );
			} catch ( Exception $e ) { // phpcs:ignore
				// Falls through to the numeric offset below.
			}
		}

		$seconds = (int) round( (float) $offset * 3600 );
		$sign    = $seconds < 0 ? '-' : '+';
		$abs     = abs( $seconds );
		$name    = sprintf( '%s%02d:%02d', $sign, intdiv( $abs, 3600 ), intdiv( $abs % 3600, 60 ) );

		$now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$now->modify( ( $seconds >= 0 ? '+' : '-' ) . $abs . ' seconds' );

		return $now->format( 'Y-m-d\TH:i:s' ) . $name;
	}

	/**
	 * The IANA timezone this shop's local dates are in, for `--timezone`.
	 *
	 * THE ONE FIGURE IN THE MANIFEST THE IMPORTER CANNOT DO WITHOUT.
	 * App\Services\Import\DateParser refuses to default it -- "the source
	 * timezone is a required argument, not a default. A caller that does not
	 * know it has to go and find out" -- and reading a Dubai timestamp as UTC
	 * shifts every order in the store by four hours. This is the shop saying
	 * which zone it wrote its dates in, so nobody has to find out.
	 *
	 * A site configured with a bare UTC offset rather than a city has no IANA
	 * name; the offset is reported as-is and the export says so, because a
	 * guessed city is worse than a stated offset.
	 */
	public static function timezone_name() {
		$tz = self::option( 'timezone_string', '' );

		if ( '' !== $tz ) {
			return $tz;
		}

		$offset = (float) self::option( 'gmt_offset', '0' );

		if ( 0.0 === $offset ) {
			return 'UTC';
		}

		$sign = $offset < 0 ? '-' : '+';
		$abs  = abs( $offset );

		return sprintf( 'UTC%s%02d:%02d', $sign, (int) $abs, (int) round( ( $abs - (int) $abs ) * 60 ) );
	}
}
