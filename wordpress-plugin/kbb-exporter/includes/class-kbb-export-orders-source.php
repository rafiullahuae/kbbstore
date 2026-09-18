<?php
/**
 * Where this shop keeps its orders, and how to read them a batch at a time.
 *
 * ============================================================================
 * BOTH STORAGES ARE SUPPORTED. THIS IS THE CLASS THAT MAKES THAT TRUE.
 * ============================================================================
 *
 * WooCommerce has two order storages live in the wild at once:
 *
 *  - LEGACY: orders are `wp_posts` rows of post_type `shop_order`, their money
 *    and addresses are `wp_postmeta` keys (`_order_total`, `_billing_email`),
 *    and their status is the post_status (`wc-completed`).
 *
 *  - HPOS ("High-Performance Order Storage", WooCommerce 8.2+): orders are rows
 *    of `wp_wc_orders`, addresses are `wp_wc_order_addresses`, the money
 *    breakdown is `wp_wc_order_operational_data`, and anything else is
 *    `wp_wc_orders_meta`.
 *
 * A shop can also run BOTH with synchronisation on, in which case `wp_posts`
 * carries a stub row whose post_status is `wc-completed` and whose meta is
 * partially populated. That is the case that makes "support one and claim both"
 * dangerous rather than merely incomplete: an exporter that reads `wp_posts` on
 * a synchronised HPOS shop finds rows, exports them, reports a row count that
 * looks right, and silently writes zeroes for every total that lives only in
 * the operational-data table.
 *
 * SO THE DETECTION IS EXPLICIT AND IS REPORTED. `storage()` answers `hpos` or
 * `posts`, the answer goes into manifest.json's `source.order_storage`, and the
 * admin screen prints it in a sentence before the export starts. If the option
 * says HPOS and the table is not there, or the other way round, the export
 * REFUSES rather than falling back -- a fallback here is exactly how a shop
 * ends up with 4,000 orders of zeroes.
 *
 * WHAT IS THE SAME EITHER WAY, and is therefore not in this class: line items
 * (`wp_woocommerce_order_items` + `_itemmeta`, unchanged by HPOS), order notes
 * (`wp_comments` with comment_type `order_note`, unchanged by HPOS), and
 * coupons, products, terms and users. HPOS moved the order header and nothing
 * else.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Orders_Source {

	const HPOS  = 'hpos';
	const POSTS = 'posts';

	/** @var string */
	private $storage;

	private function __construct( $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Work out which storage is authoritative, or explain why it cannot.
	 *
	 * @return array{source: KBB_Export_Orders_Source|null, error: string, detail: string}
	 */
	public static function detect() {
		$declared = strtolower( trim( KBB_Export_Wp::option( 'woocommerce_custom_orders_table_enabled', 'no' ) ) );
		$has_hpos = self::table_exists( 'wc_orders' );
		$has_posts = self::table_exists( 'posts' );

		if ( 'yes' === $declared && ! $has_hpos ) {
			return array(
				'source' => null,
				'error'  => 'This shop has HPOS switched on (woocommerce_custom_orders_table_enabled = yes) and the '
					. 'wc_orders table does not exist. Exporting from wp_posts instead would read the synchronisation '
					. 'stubs and write zero for every order total, so nothing is exported until this is resolved.',
				'detail' => 'declared=yes wc_orders=missing',
			);
		}

		if ( 'yes' === $declared ) {
			return array( 'source' => new self( self::HPOS ), 'error' => '', 'detail' => 'declared=yes wc_orders=present' );
		}

		if ( ! $has_posts ) {
			return array(
				'source' => null,
				'error'  => 'Neither wc_orders nor wp_posts could be read. This does not look like a WordPress database.',
				'detail' => 'declared=' . $declared . ' wc_orders=' . ( $has_hpos ? 'present' : 'missing' ) . ' posts=missing',
			);
		}

		return array( 'source' => new self( self::POSTS ), 'error' => '', 'detail' => 'declared=' . $declared . ' wc_orders=' . ( $has_hpos ? 'present-but-not-authoritative' : 'missing' ) );
	}

	public function storage() {
		return $this->storage;
	}

	public function is_hpos() {
		return self::HPOS === $this->storage;
	}

	/** Human wording for manifest.json and the admin screen. */
	public function describe() {
		return $this->is_hpos()
			? 'HPOS (wp_wc_orders): the order header, addresses and money breakdown come from the WooCommerce order tables.'
			: 'legacy (wp_posts): orders are shop_order posts and their fields are wp_postmeta keys.';
	}

	private static function table_exists( $suffix ) {
		global $wpdb;

		$name  = $wpdb->prefix . $suffix;
		$found = $wpdb->get_var( 'SHOW TABLES LIKE ' . KBB_Export_Wp::quote( $name ) );

		return is_string( $found ) && '' !== $found;
	}

	/**
	 * How many orders of this type there are.
	 *
	 * `shop_order` for the orders file, `shop_order_refund` for the refunds
	 * one. Refunds are CHILD ROWS of an order in both storages -- a `wp_posts`
	 * row with post_parent set, or a `wc_orders` row with parent_order_id --
	 * which is why they share this class rather than having their own.
	 */
	public function count( $type = 'shop_order' ) {
		global $wpdb;

		if ( $this->is_hpos() ) {
			return (int) $wpdb->get_var(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'wc_orders WHERE type = ' . KBB_Export_Wp::quote( $type )
			);
		}

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'posts WHERE post_type = ' . KBB_Export_Wp::quote( $type )
		);
	}

	/**
	 * One batch of order headers, normalised to the same array shape whichever
	 * storage they came from.
	 *
	 * The keys are the EXPORT's names, not either storage's, so the stage above
	 * does not have to know which one it is reading. Everything is a string;
	 * money in particular stays the decimal string the database holds, per the
	 * contract's last rule.
	 *
	 * @return array{rows: array<int,array<string,string>>, cursor: int}
	 */
	public function batch( $cursor, $limit, $type = 'shop_order' ) {
		return $this->is_hpos()
			? $this->batch_hpos( (int) $cursor, (int) $limit, $type )
			: $this->batch_posts( (int) $cursor, (int) $limit, $type );
	}

	/** @return array{rows: array<int,array<string,string>>, cursor: int} */
	private function batch_hpos( $cursor, $limit, $type ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT o.id, o.status, o.currency, o.type, o.tax_amount, o.total_amount, o.customer_id,
			        o.billing_email, o.date_created_gmt, o.date_updated_gmt, o.parent_order_id,
			        o.payment_method, o.payment_method_title, o.transaction_id, o.customer_note,
			        d.created_via, d.date_paid_gmt, d.date_completed_gmt, d.order_key,
			        d.discount_total_amount, d.discount_tax_amount,
			        d.shipping_total_amount, d.shipping_tax_amount, d.cart_tax_amount
			 FROM ' . $wpdb->prefix . 'wc_orders o
			 LEFT JOIN ' . $wpdb->prefix . 'wc_order_operational_data d ON d.order_id = o.id
			 WHERE o.type = ' . KBB_Export_Wp::quote( $type ) . ' AND o.id > ' . (int) $cursor . '
			 ORDER BY o.id
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => $cursor );
		}

		$ids       = array();
		$last      = $cursor;
		$by_id     = array();

		foreach ( $rows as $row ) {
			$id          = (int) $row['id'];
			$ids[]       = $id;
			$last        = $id;
			$by_id[ $id ] = $row;
		}

		$addresses = $this->hpos_addresses( $ids );
		$meta      = $this->hpos_meta( $ids );

		$out = array();

		foreach ( $ids as $id ) {
			$row  = $by_id[ $id ];
			$m    = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$addr = isset( $addresses[ $id ] ) ? $addresses[ $id ] : array();

			$order = array(
				'order_id'         => (string) $id,
				'parent_order_id'  => (string) (int) $row['parent_order_id'],
				'status'           => (string) $row['status'],
				'currency'         => (string) $row['currency'],
				'customer_id'      => (string) (int) $row['customer_id'],
				'billing_email'    => (string) $row['billing_email'],
				'date_created'     => KBB_Export_Wp::local_from_gmt( $row['date_created_gmt'] ),
				'date_created_gmt' => (string) $row['date_created_gmt'],
				'date_modified'    => KBB_Export_Wp::local_from_gmt( $row['date_updated_gmt'] ),
				'date_paid'        => KBB_Export_Wp::local_from_gmt( $row['date_paid_gmt'] ),
				'date_completed'   => KBB_Export_Wp::local_from_gmt( $row['date_completed_gmt'] ),
				'discount_total'   => (string) $row['discount_total_amount'],
				'shipping_total'   => (string) $row['shipping_total_amount'],
				'tax_total'        => (string) $row['tax_amount'],
				'total'            => (string) $row['total_amount'],
				'payment_method'   => (string) $row['payment_method'],
				'payment_method_title' => (string) $row['payment_method_title'],
				'transaction_id'   => (string) $row['transaction_id'],
				'customer_note'    => (string) $row['customer_note'],
				'origin'           => (string) $row['created_via'],
				'order_key'        => (string) $row['order_key'],
				// `_order_number` is written by every sequential-numbering
				// plugin and by none of WooCommerce core. Absent, OrderImporter
				// falls back to the id, which is decision D5's other half.
				'order_number'     => isset( $m['_order_number'] ) ? $m['_order_number'] : '',
				'invoice_number'   => isset( $m['_wf_invoice_number'] ) ? $m['_wf_invoice_number'] : '',
				'refund_amount'    => isset( $m['_refund_amount'] ) ? $m['_refund_amount'] : '',
				'refund_reason'    => isset( $m['_refund_reason'] ) ? $m['_refund_reason'] : '',
				'refunded_by'      => isset( $m['_refunded_by'] ) ? $m['_refunded_by'] : '',
			);

			foreach ( $addr as $type_key => $fields ) {
				foreach ( $fields as $field => $value ) {
					$order[ $type_key . '_' . $field ] = $value;
				}
			}

			$out[] = $order;
		}

		return array( 'rows' => $out, 'cursor' => $last );
	}

	/** @return array<int,array<string,array<string,string>>> */
	private function hpos_addresses( array $ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $ids ) ) {
			return $out;
		}

		$rows = $wpdb->get_results(
			'SELECT order_id, address_type, first_name, last_name, company, address_1, address_2,
			        city, state, postcode, country, email, phone
			 FROM ' . $wpdb->prefix . 'wc_order_addresses
			 WHERE order_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')',
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$type = 'shipping' === $row['address_type'] ? 'shipping' : 'billing';

			$out[ (int) $row['order_id'] ][ $type ] = array(
				'first_name' => (string) $row['first_name'],
				'last_name'  => (string) $row['last_name'],
				'company'    => (string) $row['company'],
				'address_1'  => (string) $row['address_1'],
				'address_2'  => (string) $row['address_2'],
				'city'       => (string) $row['city'],
				'state'      => (string) $row['state'],
				'postcode'   => (string) $row['postcode'],
				'country'    => (string) $row['country'],
				'phone'      => (string) $row['phone'],
			);
		}

		return $out;
	}

	/** @return array<int,array<string,string>> */
	private function hpos_meta( array $ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $ids ) ) {
			return $out;
		}

		$keys = array( '_order_number', '_wf_invoice_number', '_refund_amount', '_refund_reason', '_refunded_by' );

		$rows = $wpdb->get_results(
			'SELECT order_id, meta_key, meta_value
			 FROM ' . $wpdb->prefix . 'wc_orders_meta
			 WHERE order_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')
			   AND meta_key IN (' . implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), $keys ) ) . ')',
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['order_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}

		return $out;
	}

	/**
	 * The legacy storage, where every field above is a postmeta key.
	 *
	 * @return array{rows: array<int,array<string,string>>, cursor: int}
	 */
	private function batch_posts( $cursor, $limit, $type ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, post_status, post_date, post_date_gmt, post_modified, post_parent, post_excerpt
			 FROM ' . $wpdb->prefix . 'posts
			 WHERE post_type = ' . KBB_Export_Wp::quote( $type ) . ' AND ID > ' . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => $cursor );
		}

		$ids  = array();
		$last = $cursor;

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['ID'];
			$last  = (int) $row['ID'];
		}

		$meta = KBB_Export_Wp::post_meta( $ids, self::LEGACY_META_KEYS );

		$out = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			$m  = isset( $meta[ $id ] ) ? $meta[ $id ] : array();

			$get = function ( $key ) use ( $m ) {
				return isset( $m[ $key ] ) ? (string) $m[ $key ] : '';
			};

			$order = array(
				'order_id'         => (string) $id,
				'parent_order_id'  => (string) (int) $row['post_parent'],
				'status'           => (string) $row['post_status'],
				'currency'         => $get( '_order_currency' ),
				'customer_id'      => $get( '_customer_user' ),
				'billing_email'    => $get( '_billing_email' ),
				'date_created'     => (string) $row['post_date'],
				'date_created_gmt' => (string) $row['post_date_gmt'],
				'date_modified'    => (string) $row['post_modified'],
				// Woo writes _date_paid and _date_completed as UNIX timestamps,
				// and _paid_date / _completed_date as local datetime strings on
				// older stores. Both spellings are read; the timestamp is
				// converted so one --timezone covers the whole file.
				'date_paid'        => $this->legacy_date( $get( '_date_paid' ), $get( '_paid_date' ) ),
				'date_completed'   => $this->legacy_date( $get( '_date_completed' ), $get( '_completed_date' ) ),
				'discount_total'   => $get( '_cart_discount' ),
				'shipping_total'   => $get( '_order_shipping' ),
				'tax_total'        => $get( '_order_tax' ),
				'total'            => $get( '_order_total' ),
				'payment_method'   => $get( '_payment_method' ),
				'payment_method_title' => $get( '_payment_method_title' ),
				'transaction_id'   => $get( '_transaction_id' ),
				'customer_note'    => (string) $row['post_excerpt'],
				'origin'           => $get( '_created_via' ),
				'order_key'        => $get( '_order_key' ),
				'order_number'     => $get( '_order_number' ),
				'invoice_number'   => $get( '_wf_invoice_number' ),
				'refund_amount'    => $get( '_refund_amount' ),
				'refund_reason'    => $get( '_refund_reason' ),
				'refunded_by'      => $get( '_refunded_by' ),
			);

			foreach ( array( 'billing', 'shipping' ) as $prefix ) {
				foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ) as $field ) {
					$order[ $prefix . '_' . $field ] = $get( '_' . $prefix . '_' . $field );
				}
			}

			$out[] = $order;
		}

		return array( 'rows' => $out, 'cursor' => $last );
	}

	/** A UNIX timestamp, or the older local-string spelling, as a local string. */
	private function legacy_date( $timestamp, $legacy_string ) {
		$timestamp = trim( (string) $timestamp );

		if ( '' !== $timestamp && preg_match( '/^\d{9,11}$/', $timestamp ) ) {
			return KBB_Export_Wp::local_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $timestamp ) );
		}

		return trim( (string) $legacy_string );
	}

	/**
	 * Every legacy order meta key this export reads.
	 *
	 * Named rather than `SELECT *`: on a shop that has run for six years an
	 * order carries fifty meta keys from payment gateways, shipping plugins and
	 * fraud tools, and pulling all of them for a batch of 500 orders is tens of
	 * megabytes to use twenty values.
	 */
	const LEGACY_META_KEYS = array(
		'_order_currency', '_customer_user', '_billing_email', '_order_total', '_order_tax',
		'_order_shipping', '_cart_discount', '_payment_method', '_payment_method_title',
		'_transaction_id', '_created_via', '_order_key', '_order_number', '_wf_invoice_number',
		'_date_paid', '_paid_date', '_date_completed', '_completed_date',
		'_refund_amount', '_refund_reason', '_refunded_by',
		'_billing_first_name', '_billing_last_name', '_billing_company', '_billing_address_1',
		'_billing_address_2', '_billing_city', '_billing_state', '_billing_postcode',
		'_billing_country', '_billing_phone',
		'_shipping_first_name', '_shipping_last_name', '_shipping_company', '_shipping_address_1',
		'_shipping_address_2', '_shipping_city', '_shipping_state', '_shipping_postcode',
		'_shipping_country', '_shipping_phone',
	);
}
