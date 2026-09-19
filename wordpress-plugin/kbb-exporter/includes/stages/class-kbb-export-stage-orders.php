<?php
/**
 * `orders.csv`, `order_items.csv`, `refunds.csv` and `order_notes.csv`.
 *
 * The first two have importers behind them; the last two are **gap** files.
 *
 * ── WHY `subtotal` AND `fee_total` ARE COMPUTED HERE ────────────────────────
 *
 * WooCommerce does not store either on the order. `_order_total` is stored,
 * `_order_tax` is stored, `_order_shipping` is stored -- and the subtotal is
 * the sum of the line items' `_line_subtotal`, while the fees are line items of
 * their own with type `fee`. An exporter that left those two cells blank would
 * import every order with `subtotal = 0`, and OrderImporter would take it
 * without a word because Row::moneyOrZero() reads a blank cell as zero. The
 * order page would then show a total that does not agree with the lines above
 * it, on every order in the shop.
 *
 * So one query per batch sums the line items, and it is the same query that
 * produces `shipping_method` and `coupon_code`, both of which are also line
 * items in WooCommerce and neither of which is anywhere else.
 *
 * ── AND WHY `date_created_gmt` IS IN THE FILE ───────────────────────────────
 *
 * OrderImporter::checkDeclaredTimezone() reads it, compares it against the
 * `--timezone` the owner passed, and reports -- once, with a count -- if the
 * two disagree. Its own words: what is not recoverable "is not being told,
 * because by then the shop is live and the four-hour shift is
 * indistinguishable from history". The column costs twenty bytes a row and it
 * is the only thing in the export that can catch a wrong `--timezone`.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Line-item arithmetic shared by the order and refund stages.
 */
trait KBB_Export_Order_Lines {

	/**
	 * Per-order sums and the line items that are not products.
	 *
	 * @param array<int,int> $order_ids
	 * @return array<int,array<string,mixed>>
	 */
	protected function line_totals( array $order_ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $order_ids ) ) {
			return $out;
		}

		$list = implode( ',', array_map( 'intval', $order_ids ) );

		$rows = $wpdb->get_results(
			"SELECT i.order_id, i.order_item_id, i.order_item_type, i.order_item_name,
			        MAX(CASE WHEN m.meta_key = '_line_subtotal' THEN m.meta_value END) AS line_subtotal,
			        MAX(CASE WHEN m.meta_key = '_line_total'    THEN m.meta_value END) AS line_total
			 FROM {$wpdb->prefix}woocommerce_order_items i
			 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = i.order_item_id
			 WHERE i.order_id IN ({$list})
			 GROUP BY i.order_id, i.order_item_id, i.order_item_type, i.order_item_name
			 ORDER BY i.order_id, i.order_item_id",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$id = (int) $row['order_id'];

			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					// '0.00' and not '0': every other money cell in this file
					// is a two-decimal string, and a file whose zeroes are
					// spelled two ways is one an eyeball diff cannot read.
					'subtotal'  => '0.00',
					'fee_total' => '0.00',
					'shipping'  => array(),
					'coupons'   => array(),
				);
			}

			switch ( $row['order_item_type'] ) {
				case 'line_item':
					$out[ $id ]['subtotal'] = $this->add( $out[ $id ]['subtotal'], $row['line_subtotal'] );
					break;
				case 'fee':
					$out[ $id ]['fee_total'] = $this->add( $out[ $id ]['fee_total'], $row['line_total'] );
					break;
				case 'shipping':
					$out[ $id ]['shipping'][] = (string) $row['order_item_name'];
					break;
				case 'coupon':
					$out[ $id ]['coupons'][] = (string) $row['order_item_name'];
					break;
			}
		}

		return $out;
	}

	/**
	 * Add two decimal money strings WITHOUT constructing a float.
	 *
	 * The same rule App\Services\Import\Money states and for the same reason:
	 * "Binary floating point cannot represent 0.29 or 19.99 exactly, so `$v *
	 * 100` produces 28.999999999999996". An exporter that summed line subtotals
	 * with `+=` would hand the importer `298.50000000000006`, which
	 * Money::fils() then REFUSES -- "carries more precision than fils can
	 * represent" -- and the whole order is rejected over arithmetic this plugin
	 * did on the way past. Done in integer minor units internally and turned
	 * back into a decimal string at the end, so the cell is what WooCommerce
	 * would have written.
	 */
	protected function add( $carry, $value ) {
		return $this->from_minor( $this->to_minor( $carry ) + $this->to_minor( $value ) );
	}

	protected function to_minor( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		$negative = 0 === strpos( $value, '-' );
		$value    = ltrim( $value, '+-' );

		$parts = explode( '.', $value, 2 );
		$whole = preg_replace( '/\D/', '', $parts[0] );
		$frac  = isset( $parts[1] ) ? preg_replace( '/\D/', '', $parts[1] ) : '';
		$frac  = substr( str_pad( $frac, 2, '0' ), 0, 2 );

		$minor = ( (int) $whole ) * 100 + (int) $frac;

		return $negative ? -$minor : $minor;
	}

	protected function from_minor( $minor ) {
		$minor    = (int) $minor;
		$negative = $minor < 0;
		$minor    = abs( $minor );

		return ( $negative ? '-' : '' ) . intdiv( $minor, 100 ) . '.' . str_pad( (string) ( $minor % 100 ), 2, '0', STR_PAD_LEFT );
	}
}

/**
 * `orders.csv`.
 */
class KBB_Export_Stage_Orders extends KBB_Export_Stage {

	use KBB_Export_Order_Lines;

	/** @var KBB_Export_Orders_Source */
	private $orders;

	public function __construct( array $options, KBB_Export_Orders_Source $orders ) {
		parent::__construct( $options );

		$this->orders = $orders;
	}

	public function file() {
		return 'orders.csv';
	}

	public function columns() {
		return array_merge(
			array(
				'order_id', 'order_number', 'status', 'currency', 'customer_id', 'billing_email',
				'date_created', 'date_created_gmt', 'date_modified', 'date_paid', 'date_completed',
				'subtotal', 'discount_total', 'shipping_total', 'fee_total', 'tax_total', 'total',
				'payment_method', 'payment_method_title', 'transaction_id', 'shipping_method',
				'coupon_code', 'customer_note', 'origin', 'invoice_number', 'order_key',
			),
			$this->address_columns( 'billing' ),
			$this->address_columns( 'shipping' )
		);
	}

	/** @return array<int,string> */
	private function address_columns( $type ) {
		return array(
			$type . '_first_name', $type . '_last_name', $type . '_company',
			$type . '_address_1', $type . '_address_2', $type . '_city',
			$type . '_state', $type . '_postcode', $type . '_country', $type . '_phone',
		);
	}

	public function total() {
		return $this->orders->count( 'shop_order' );
	}

	public function batch( $cursor, $limit ) {
		$batch = $this->orders->batch( (int) $cursor, (int) $limit, 'shop_order' );

		if ( empty( $batch['rows'] ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids = array();

		foreach ( $batch['rows'] as $order ) {
			$ids[] = (int) $order['order_id'];
		}

		$lines = $this->line_totals( $ids );

		$out = array();

		foreach ( $batch['rows'] as $order ) {
			$id = (int) $order['order_id'];
			$l  = isset( $lines[ $id ] ) ? $lines[ $id ] : array( 'subtotal' => '0.00', 'fee_total' => '0.00', 'shipping' => array(), 'coupons' => array() );

			$row = array(
				'order_id' => $id,
				// Absent, OrderImporter falls back to the WooCommerce id, which
				// is decision D5's `--order-number=id` behaviour by default.
				'order_number' => '' !== $order['order_number'] ? $order['order_number'] : (string) $id,
				// `wc-completed` as WordPress holds it. The importer strips the
				// `wc-` itself and its comment says why: "this application's own
				// statuses have no prefix, and `wc-completed` would be counted
				// as revenue by nothing".
				'status'       => $order['status'],
				'currency'     => $order['currency'],
				// 0 for a guest, which Row::id() reads as null -- exactly the
				// distinction OrderImporter's guest path needs.
				'customer_id'  => '0' === $order['customer_id'] ? '' : $order['customer_id'],
				'billing_email' => $order['billing_email'],
				'date_created'     => $order['date_created'],
				'date_created_gmt' => $order['date_created_gmt'],
				'date_modified'    => $order['date_modified'],
				'date_paid'        => $order['date_paid'],
				'date_completed'   => $order['date_completed'],
				'subtotal'         => $l['subtotal'],
				'discount_total'   => $this->money( $order['discount_total'] ),
				'shipping_total'   => $this->money( $order['shipping_total'] ),
				'fee_total'        => $l['fee_total'],
				'tax_total'        => $this->money( $order['tax_total'] ),
				'total'            => $this->money( $order['total'] ),
				'payment_method'   => $order['payment_method'],
				'payment_method_title' => $order['payment_method_title'],
				'transaction_id'   => $order['transaction_id'],
				'shipping_method'  => implode( ', ', $l['shipping'] ),
				'coupon_code'      => implode( ',', $l['coupons'] ),
				'customer_note'    => $order['customer_note'],
				'origin'           => $order['origin'],
				'invoice_number'   => $order['invoice_number'],
			);

			foreach ( array( 'billing', 'shipping' ) as $type ) {
				foreach ( $this->address_columns( $type ) as $column ) {
					$row[ $column ] = isset( $order[ $column ] ) ? $order[ $column ] : '';
				}
			}

			$out[] = $row;
		}

		return array(
			'rows'   => $out,
			'cursor' => (int) $batch['cursor'],
			'done'   => count( $batch['rows'] ) < (int) $limit,
		);
	}
}

/**
 * `order_items.csv` -- read by OrderItemImporter, matched on `wc_item_id`.
 *
 * ── REFUND LINES ARE NOT IN THIS FILE, AND THAT IS NOT AN OVERSIGHT ─────────
 *
 * WooCommerce writes a refund's lines into the SAME `woocommerce_order_items`
 * table, with `order_id` pointing at the refund rather than at the order. Those
 * rows look exactly like order lines apart from their negative quantities. An
 * exporter that selected the table wholesale would put them in this file, and
 * OrderItemImporter would refuse every one of them -- "order 10310 is not in
 * this database" -- because refunds.csv is where their parent went. On the
 * shop this migration is for that is 207 refusals in a report the owner is
 * meant to read for real problems.
 *
 * So the select is restricted to items whose order is a `shop_order`, which is
 * a different subquery under each storage; the refund lines are carried in
 * refunds.csv instead, on their refund's own row.
 */
class KBB_Export_Stage_Order_Items extends KBB_Export_Stage {

	/** @var KBB_Export_Orders_Source|null */
	private $orders;

	public function file() {
		return 'order_items.csv';
	}

	public function columns() {
		return array(
			'item_id', 'order_id', 'product_id', 'variation_id', 'name', 'sku', 'brand',
			'quantity', 'subtotal', 'total', 'tax_total',
		);
	}

	private function orders() {
		if ( null === $this->orders ) {
			$detected     = KBB_Export_Orders_Source::detect();
			$this->orders = $detected['source'];
		}

		return $this->orders;
	}

	/** The `EXISTS` that keeps refund lines out. */
	private function parent_is_an_order() {
		global $wpdb;

		$orders = $this->orders();

		if ( null !== $orders && $orders->is_hpos() ) {
			return 'EXISTS (SELECT 1 FROM ' . $wpdb->prefix . "wc_orders o WHERE o.id = i.order_id AND o.type = 'shop_order')";
		}

		return 'EXISTS (SELECT 1 FROM ' . $wpdb->prefix . "posts p WHERE p.ID = i.order_id AND p.post_type = 'shop_order')";
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "woocommerce_order_items i
			 WHERE i.order_item_type = 'line_item' AND " . $this->parent_is_an_order()
		);
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT i.order_item_id, i.order_id, i.order_item_name
			 FROM ' . $wpdb->prefix . "woocommerce_order_items i
			 WHERE i.order_item_type = 'line_item' AND i.order_item_id > " . (int) $cursor . '
			   AND ' . $this->parent_is_an_order() . '
			 ORDER BY i.order_item_id
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$item_ids = array();

		foreach ( $rows as $row ) {
			$item_ids[] = (int) $row['order_item_id'];
		}

		$meta = $this->item_meta( $item_ids );

		// The SKU and the brand are attributes of the PRODUCT, not of the line,
		// and Woo stores neither on the line. Looked up in one query for the
		// batch rather than one per row.
		$product_ids = array();

		foreach ( $meta as $m ) {
			if ( isset( $m['_product_id'] ) && (int) $m['_product_id'] > 0 ) {
				$product_ids[] = (int) $m['_product_id'];
			}
			if ( isset( $m['_variation_id'] ) && (int) $m['_variation_id'] > 0 ) {
				$product_ids[] = (int) $m['_variation_id'];
			}
		}

		$product_ids = array_values( array_unique( $product_ids ) );
		$skus        = KBB_Export_Wp::post_meta( $product_ids, array( '_sku' ) );
		$brand_tax   = KBB_Export_Wp::brand_taxonomy();
		$brands      = '' === $brand_tax ? array() : KBB_Export_Wp::terms_for( $product_ids, array( $brand_tax ) );

		$out = array();

		foreach ( $rows as $row ) {
			$item_id = (int) $row['order_item_id'];
			$m       = isset( $meta[ $item_id ] ) ? $meta[ $item_id ] : array();

			$get = function ( $key, $default = '' ) use ( $m ) {
				return isset( $m[ $key ] ) ? (string) $m[ $key ] : $default;
			};

			$product   = (int) $get( '_product_id', '0' );
			$variation = (int) $get( '_variation_id', '0' );

			// THE PARENT, NOT THE VARIATION. products.csv carries parents;
			// variations are in variations.csv and nothing imports that yet.
			// Naming the variation here would leave every variable-product line
			// with a null product_id and a note, when the parent is right there
			// and is what the order page needs to link to. The variation id is
			// carried in its own column so the link is not lost -- nothing
			// reads it yet, and the import report names it as a column nothing
			// reads, which is the truth.
			$sku_source = $variation > 0 && isset( $skus[ $variation ]['_sku'] ) && '' !== $skus[ $variation ]['_sku']
				? $variation
				: $product;

			$brand = '';

			if ( $product > 0 && isset( $brands[ $product ][ $brand_tax ][0]['name'] ) ) {
				$brand = $brands[ $product ][ $brand_tax ][0]['name'];
			}

			$out[] = array(
				'item_id'      => $item_id,
				'order_id'     => (int) $row['order_id'],
				'product_id'   => $product > 0 ? $product : '',
				'variation_id' => $variation > 0 ? $variation : '',
				// NOT NULL in the shop and a snapshot of what was bought:
				// "a line whose name came from today's catalogue is not a record
				// of what was bought".
				'name'         => $row['order_item_name'],
				'sku'          => isset( $skus[ $sku_source ]['_sku'] ) ? $skus[ $sku_source ]['_sku'] : '',
				'brand'        => $brand,
				'quantity'     => $get( '_qty', '0' ),
				'subtotal'     => $this->money( $get( '_line_subtotal' ) ),
				'total'        => $this->money( $get( '_line_total' ) ),
				'tax_total'    => $this->money( $get( '_line_tax' ) ),
			);
		}

		return array(
			'rows'   => $out,
			'cursor' => (int) end( $item_ids ),
			'done'   => count( $rows ) < (int) $limit,
		);
	}

	/**
	 * @param array<int,int> $item_ids
	 * @return array<int,array<string,string>>
	 */
	private function item_meta( array $item_ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $item_ids ) ) {
			return $out;
		}

		$keys = array( '_product_id', '_variation_id', '_qty', '_line_subtotal', '_line_total', '_line_tax', '_line_subtotal_tax' );

		$rows = $wpdb->get_results(
			'SELECT order_item_id, meta_key, meta_value
			 FROM ' . $wpdb->prefix . 'woocommerce_order_itemmeta
			 WHERE order_item_id IN (' . implode( ',', array_map( 'intval', $item_ids ) ) . ')
			   AND meta_key IN (' . implode( ',', array_map( array( 'KBB_Export_Wp', 'quote' ), $keys ) ) . ')
			 ORDER BY meta_id DESC',
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['order_item_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}

		return $out;
	}
}

/**
 * `refunds.csv` -- a **gap** file.
 *
 * docs/FV-IMPORT-AT-VOLUME.md §11: "**Money the shop gave back.**
 * `orders.status = refunded` imports, and the amount refunded does not. A
 * partial refund imports as an order at its full total."
 *
 * A refund is a child order in both storages. Its own lines -- which is where a
 * PARTIAL refund's detail lives, since the header amount alone cannot say which
 * of five items came back -- are carried on the row as
 * `item_id:quantity:total`, so one file is enough and there is no second file
 * that can be half-imported.
 */
class KBB_Export_Stage_Refunds extends KBB_Export_Stage {

	use KBB_Export_Order_Lines;

	/** @var KBB_Export_Orders_Source */
	private $orders;

	public function __construct( array $options, KBB_Export_Orders_Source $orders ) {
		parent::__construct( $options );

		$this->orders = $orders;
	}

	public function file() {
		return 'refunds.csv';
	}

	public function columns() {
		return array( 'refund_id', 'order_id', 'date_created', 'amount', 'reason', 'refunded_by', 'currency', 'total', 'refunded_items' );
	}

	public function total() {
		return $this->orders->count( 'shop_order_refund' );
	}

	public function batch( $cursor, $limit ) {
		$batch = $this->orders->batch( (int) $cursor, (int) $limit, 'shop_order_refund' );

		if ( empty( $batch['rows'] ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids = array();

		foreach ( $batch['rows'] as $refund ) {
			$ids[] = (int) $refund['order_id'];
		}

		$lines = $this->refund_lines( $ids );

		$out = array();

		foreach ( $batch['rows'] as $refund ) {
			$id = (int) $refund['order_id'];

			$out[] = array(
				'refund_id'    => $id,
				'order_id'     => $refund['parent_order_id'],
				'date_created' => $refund['date_created'],
				// `_refund_amount` is the positive figure Woo shows in the
				// admin; `total` on the refund row is the same money as a
				// NEGATIVE. Both are carried because they are two different
				// conventions and an importer guessing which one it has is how
				// a refund gets applied twice or backwards.
				'amount'       => $this->money( $refund['refund_amount'] ),
				'reason'       => $refund['refund_reason'],
				'refunded_by'  => $refund['refunded_by'],
				'currency'     => $refund['currency'],
				'total'        => $this->money( $refund['total'] ),
				'refunded_items' => isset( $lines[ $id ] ) ? $this->pipes( $lines[ $id ] ) : '',
			);
		}

		return array(
			'rows'   => $out,
			'cursor' => (int) $batch['cursor'],
			'done'   => count( $batch['rows'] ) < (int) $limit,
		);
	}

	/**
	 * Which lines of the original order a refund gave back, and how much.
	 *
	 * `_refunded_item_id` on the refund's line points at the ORIGINAL order's
	 * item, which is the handle a future importer needs to attach the money to
	 * the right line rather than to the order as a lump.
	 *
	 * @param array<int,int> $refund_ids
	 * @return array<int,array<int,string>>
	 */
	private function refund_lines( array $refund_ids ) {
		global $wpdb;

		$out = array();

		if ( empty( $refund_ids ) ) {
			return $out;
		}

		$list = implode( ',', array_map( 'intval', $refund_ids ) );

		$rows = $wpdb->get_results(
			"SELECT i.order_id, i.order_item_id,
			        MAX(CASE WHEN m.meta_key = '_refunded_item_id' THEN m.meta_value END) AS refunded_item_id,
			        MAX(CASE WHEN m.meta_key = '_qty'              THEN m.meta_value END) AS qty,
			        MAX(CASE WHEN m.meta_key = '_line_total'       THEN m.meta_value END) AS line_total
			 FROM {$wpdb->prefix}woocommerce_order_items i
			 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = i.order_item_id
			 WHERE i.order_id IN ({$list}) AND i.order_item_type = 'line_item'
			 GROUP BY i.order_id, i.order_item_id
			 ORDER BY i.order_id, i.order_item_id",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$target = (int) $row['refunded_item_id'];

			$out[ (int) $row['order_id'] ][] = ( $target > 0 ? $target : (int) $row['order_item_id'] )
				. ':' . (string) $row['qty'] . ':' . (string) $row['line_total'];
		}

		return $out;
	}
}

/**
 * `order_notes.csv` -- a **gap** file.
 *
 * Order notes are `wp_comments` rows with comment_type `order_note`, and HPOS
 * did not move them, so this stage is the same under both storages.
 * `is_customer_note` separates the notes the shopper was emailed from the ones
 * only the shop ever saw, which is the distinction that decides whether a note
 * can be shown on the customer's own order page.
 */
class KBB_Export_Stage_Order_Notes extends KBB_Export_Stage {

	public function file() {
		return 'order_notes.csv';
	}

	public function columns() {
		return array( 'note_id', 'order_id', 'date_created', 'date_created_gmt', 'author', 'author_email', 'content', 'is_customer_note' );
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "comments WHERE comment_type = 'order_note'"
		);
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT comment_ID, comment_post_ID, comment_date, comment_date_gmt, comment_author, comment_author_email, comment_content
			 FROM ' . $wpdb->prefix . "comments
			 WHERE comment_type = 'order_note' AND comment_ID > " . (int) $cursor . '
			 ORDER BY comment_ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = (int) $row['comment_ID'];
		}

		$meta = KBB_Export_Wp::comment_meta( $ids, array( 'is_customer_note' ) );

		$out = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['comment_ID'];

			$out[] = array(
				'note_id'      => $id,
				'order_id'     => (int) $row['comment_post_ID'],
				'date_created' => $row['comment_date'],
				'date_created_gmt' => $row['comment_date_gmt'],
				'author'       => $row['comment_author'],
				'author_email' => $row['comment_author_email'],
				'content'      => $row['comment_content'],
				'is_customer_note' => isset( $meta[ $id ]['is_customer_note'] ) && '1' === (string) $meta[ $id ]['is_customer_note'] ? 'yes' : 'no',
			);
		}

		return array(
			'rows'   => $out,
			'cursor' => (int) end( $ids ),
			'done'   => count( $rows ) < (int) $limit,
		);
	}
}
