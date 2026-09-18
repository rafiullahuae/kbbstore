<?php
/**
 * `coupons.csv` -- WooCommerce `shop_coupon` posts, read by CouponImporter.
 *
 * ── THE EXPIRY DATE IS EMITTED AS A BARE DATE ON PURPOSE ────────────────────
 *
 * This is the one place in the whole export where formatting a value MORE
 * precisely would silently retire a live discount code a day early.
 *
 * WooCommerce stores `date_expires` as a UNIX timestamp at midnight, site time,
 * of the day the coupon expires -- and Woo treats that day as INCLUSIVE: a
 * coupon expiring on the 1st works all day on the 1st. This shop's rule is
 * `now() > expires_at`, so a stored `2027-01-01 00:00:00` stops working at one
 * second past midnight. CouponImporter knows this and corrects for it, but only
 * when it can tell a bare date from a datetime:
 *
 *     if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) { return $parsed; }
 *     $inclusive = $parsed->addDay()->subSecond();
 *
 * So an exporter that formatted the timestamp as `2027-01-01 00:00:00` would
 * defeat that branch -- the value is not a bare date, the correction does not
 * fire, and the coupon retires 24 hours early with an adjustment line that
 * never appears. Emitted as `Y-m-d` when the time component is midnight, which
 * is what Woo means by it, and as a full datetime when it genuinely is one.
 *
 * ── AND ONLY PUBLISHED COUPONS GO OUT ───────────────────────────────────────
 *
 * CouponImporter: "`coupons` has no status column, so an imported draft or
 * trashed coupon is a LIVE, WORKING discount code -- the opposite of what the
 * owner did when they unpublished it", and it refuses the row. Held back here
 * with a count in the manifest notes, for the same reason the trashed products
 * are.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Coupons extends KBB_Export_Stage {

	/**
	 * WooCommerce coupon meta, which -- unlike product meta -- mostly carries
	 * NO leading underscore. Both spellings are read because older stores and
	 * several coupon plugins write the underscored form.
	 */
	const META_KEYS = array(
		'discount_type', 'coupon_amount', 'individual_use', 'product_ids', 'exclude_product_ids',
		'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'usage_count',
		'date_expires', 'expiry_date', 'free_shipping', 'product_categories',
		'exclude_product_categories', 'exclude_sale_items', 'minimum_amount', 'maximum_amount',
		'customer_email',
		'_wc_sc_start_date',
		'_discount_type', '_coupon_amount', '_individual_use', '_product_ids', '_exclude_product_ids',
		'_usage_limit', '_usage_limit_per_user', '_limit_usage_to_x_items', '_usage_count',
		'_date_expires', '_expiry_date', '_free_shipping', '_product_categories',
		'_exclude_product_categories', '_exclude_sale_items', '_minimum_amount', '_maximum_amount',
		'_customer_email',
	);

	private function statuses() {
		return $this->option( 'skip_trashed', true )
			? array( 'publish' )
			: array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
	}

	private function status_list() {
		return implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), $this->statuses() ) );
	}

	public function file() {
		return 'coupons.csv';
	}

	/** Exactly the aliases CouponImporter reads, plus `used_by`, which it discards by name. */
	public function columns() {
		return array(
			'id', 'code', 'post_status', 'description', 'discount_type', 'coupon_amount',
			'date_created', 'date_starts', 'date_expires',
			'usage_count', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items',
			'free_shipping', 'individual_use', 'exclude_sale_items',
			'minimum_amount', 'maximum_amount',
			'product_ids', 'exclude_product_ids', 'product_categories', 'exclude_product_categories',
			'customer_email', 'used_by',
		);
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'shop_coupon' AND post_status IN (" . $this->status_list() . ')'
		);
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, post_title, post_status, post_excerpt, post_date
			 FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'shop_coupon' AND post_status IN (" . $this->status_list() . ')
			   AND ID > ' . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			$this->report_withheld();

			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids     = $this->ids_of( $rows );
		$meta    = KBB_Export_Wp::post_meta( $ids, self::META_KEYS );
		$used_by = $this->used_by( $ids );

		$out = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			$m  = isset( $meta[ $id ] ) ? $meta[ $id ] : array();

			// Woo's own key first, the underscored spelling second.
			$get = function ( $key, $default = '' ) use ( $m ) {
				if ( isset( $m[ $key ] ) && '' !== $m[ $key ] ) {
					return (string) $m[ $key ];
				}

				return isset( $m[ '_' . $key ] ) ? (string) $m[ '_' . $key ] : $default;
			};

			$out[] = array(
				'id' => $id,
				// The coupon code IS the post title in WooCommerce. Emitted as
				// stored; CouponImporter lower-cases it and reports doing so,
				// which is the right place for that to happen -- it is what
				// Coupon::scopeCode() matches on and what the admin prints.
				'code'        => $row['post_title'],
				'post_status' => $row['post_status'],
				'description' => $row['post_excerpt'],
				'discount_type' => $get( 'discount_type' ),
				'coupon_amount' => $this->money( $get( 'coupon_amount' ) ),
				'date_created'  => $row['post_date'],
				// NOT date_created: CouponImporter::startsAt() reads only a real
				// start column, because "writing the creation date into it would
				// be harmless today and wrong the moment the owner back-dates a
				// coupon". `_wc_sc_start_date` is what the scheduled-coupons
				// plugins write, and that class names it by that spelling.
				'date_starts'   => $this->date( $get( '_wc_sc_start_date' ) ),
				'date_expires'  => $this->expiry( $get( 'date_expires' ), $get( 'expiry_date' ) ),
				'usage_count'   => $get( 'usage_count' ),
				'usage_limit'   => $get( 'usage_limit' ),
				'usage_limit_per_user'   => $get( 'usage_limit_per_user' ),
				'limit_usage_to_x_items' => $get( 'limit_usage_to_x_items' ),
				'free_shipping'      => $this->yesno( $get( 'free_shipping' ) ),
				'individual_use'     => $this->yesno( $get( 'individual_use' ) ),
				'exclude_sale_items' => $this->yesno( $get( 'exclude_sale_items' ) ),
				'minimum_amount'     => $this->money( $get( 'minimum_amount' ) ),
				'maximum_amount'     => $this->money( $get( 'maximum_amount' ) ),
				'product_ids'        => $this->ids( $get( 'product_ids' ) ),
				'exclude_product_ids' => $this->ids( $get( 'exclude_product_ids' ) ),
				'product_categories'  => $this->ids( $get( 'product_categories' ) ),
				'exclude_product_categories' => $this->ids( $get( 'exclude_product_categories' ) ),
				'customer_email'     => $this->emails( $get( 'customer_email' ) ),
				'used_by'            => isset( $used_by[ $id ] ) ? $this->commas( $used_by[ $id ] ) : '',
			);
		}

		$done = count( $rows ) < (int) $limit;

		if ( $done ) {
			$this->report_withheld();
		}

		return array( 'rows' => $out, 'cursor' => (int) end( $ids ), 'done' => $done );
	}

	private function report_withheld() {
		if ( ! $this->option( 'skip_trashed', true ) ) {
			return;
		}

		global $wpdb;

		$held = (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'shop_coupon' AND post_status <> 'publish'"
		);

		if ( $held > 0 ) {
			$this->note(
				$held . ' coupon' . ( 1 === $held ? ' is' : 's are' ) . ' not published (draft, pending or trashed) '
					. 'and NOT in coupons.csv. The shop has no status column for a coupon, so importing one would '
					. 'turn a code the owner had withdrawn back into a live discount; CouponImporter refuses them '
					. 'for that reason and this export does not offer them.'
			);
		}
	}

	/**
	 * A bare date where Woo means a whole day. See the class header.
	 */
	private function expiry( $timestamp, $legacy ) {
		$timestamp = trim( (string) $timestamp );

		if ( '' === $timestamp || ! preg_match( '/^\d{9,11}$/', $timestamp ) ) {
			// `expiry_date` was already `Y-m-d` on the stores that wrote it.
			return trim( (string) $legacy );
		}

		$local = $this->date( $timestamp );

		return preg_match( '/ 00:00:00$/', $local ) ? substr( $local, 0, 10 ) : $local;
	}

	/** A serialised or comma-separated id list, normalised to a comma list. */
	private function ids( $raw ) {
		$value = maybe_unserialize( (string) $raw );

		if ( ! is_array( $value ) ) {
			$value = explode( ',', (string) $raw );
		}

		return $this->commas( array_map( 'strval', array_filter( array_map( 'intval', $value ) ) ) );
	}

	private function emails( $raw ) {
		$value = maybe_unserialize( (string) $raw );

		if ( ! is_array( $value ) ) {
			$value = explode( ',', (string) $raw );
		}

		return $this->commas( array_map( 'strval', $value ) );
	}

	/**
	 * `_used_by` is a REPEATED meta key -- one row per redemption -- so the
	 * usual "one value per key" pivot loses all but one of them.
	 *
	 * Worth the extra query even though nothing imports it: CouponImporter's
	 * NO_HOME list names `used_by` explicitly and reports it in the discard
	 * channel with the value the export really carried, so this is the
	 * difference between the owner reading "who has already redeemed this code
	 * was dropped" with the list beside it and reading it with one name beside
	 * it.
	 *
	 * @param array<int,int> $ids
	 * @return array<int,array<int,string>>
	 */
	private function used_by( array $ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $ids ) ) {
			return $out;
		}

		$rows = $wpdb->get_results(
			'SELECT post_id, meta_value FROM ' . $wpdb->prefix . "postmeta
			 WHERE post_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ") AND meta_key = '_used_by'
			 ORDER BY meta_id",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ][] = (string) $row['meta_value'];
		}

		return $out;
	}
}
