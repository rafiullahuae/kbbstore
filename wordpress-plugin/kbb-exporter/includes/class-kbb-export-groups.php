<?php
/**
 * The named groups the owner ticks, and the dependencies between them.
 *
 * ============================================================================
 * GROUPING IS THE EASY HALF. THE DEPENDENCIES ARE THE JOB.
 * ============================================================================
 *
 * The owner asked for the export to be sectioned the way the import already is:
 * "Products + categories + brands and anything related to products, then
 * customers + orders etc". That part is a list. The part that can cost him data
 * is that every file this plugin writes has an importer on the other side, and
 * those importers resolve their foreign keys BY EXTERNAL ID -- so a file that
 * arrives before the file it points at does not fail, it resolves to nothing.
 *
 * Three facts decide everything below:
 *
 *  1. Exporting one group alone is OFTEN CORRECT. The catalogue may already be
 *     in the new shop from an earlier export; re-exporting 671 products to get
 *     40 coupons is exactly the waste this screen exists to remove. So an
 *     unsatisfied dependency cannot be a refusal.
 *
 *  2. One unsatisfied dependency costs rows in a way the owner will not connect
 *     to this export. `orders` reaching the shop before `customers`:
 *     OrderImporter links by billing email, synthesises a guest customer with a
 *     NULL wp_user_id, and the genuine WordPress user is then REFUSED as an
 *     email collision -- in the NEXT import, not this one.
 *     docs/FV-IMPORT-AT-VOLUME.md section 6 measured 14 of 80 customers lost.
 *     The refusals are named at the time; what nothing says is that the export
 *     pressed a fortnight earlier is why.
 *
 *  3. Every other unsatisfied dependency is NAMED IN THE IMPORT REPORT of the
 *     run that causes it -- a review of an absent product is refused by name, an
 *     order line whose product is absent is imported with a null product_id and
 *     noted, a coupon whose whole include list fails to translate is refused
 *     with the ids on it. Those are recoverable and visible.
 *
 * That difference is what `severity` records, and it is the only thing the two
 * levels mean: `loses` is damage the import report of the run that causes it
 * does not describe; `reported` is damage it does.
 *
 * ── WHAT THE SCREEN DOES WITH IT ────────────────────────────────────────────
 *
 * Neither refuse nor auto-tick. Both take the decision away from the person who
 * is the only one who knows what is already in the new shop. Instead: the
 * consequence is printed in words BEFORE the button is pressable, and the
 * button stays disabled until the owner has either added the missing group or
 * ticked "this is already in the new shop" against that exact sentence. The
 * server checks the same thing in start(), because a disabled button is a
 * statement about one browser and not about the export.
 *
 * Whatever he confirms is written into manifest.json, so the claim travels with
 * the export instead of living in his memory.
 *
 * ── THE VOCABULARY IS THE IMPORT SCREEN'S ───────────────────────────────────
 *
 * The labels below are App\Services\ImportConsole\ImportWorkspace::ENTITIES'
 * own labels, verbatim where a group is one entity ("Customers", "Reviews",
 * "SEO (Yoast)", "Journal articles", "Order lines"). Two screens describing the
 * same rows in two vocabularies is how an owner comes to believe they are two
 * systems.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Groups {

	/**
	 * Damage the import report of the run that causes it does NOT describe.
	 * There is exactly one of these and it should stay that way.
	 */
	const LOSES = 'loses';

	/** Damage the import report names, row by row, as it happens. */
	const REPORTED = 'reported';

	/**
	 * The groups, in the order the runner writes their files.
	 *
	 * `files` is the authority: KBB_Export_Runner::stages() is filtered through
	 * it, and `it puts every stage in exactly one group` pins the two lists
	 * against each other so a stage added later cannot quietly become
	 * unexportable. ImportWorkspace carries the same warning about the same
	 * hazard, for the same reason, and it is there because the two lists did
	 * drift once.
	 *
	 * `needs` is the dependency, keyed by the group depended on.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		return array(
			'catalogue' => array(
				'label' => 'Catalogue',
				'summary' => 'Products, and everything that files them',
				'help'  => 'Categories, brands, tags, attributes, the products themselves and every variation. '
					. 'Nothing outside this group is needed to import it.',
				'files' => array(
					'categories.csv',
					'brands.csv',
					'tags.csv',
					'attributes.csv',
					'products.csv',
					'variations.csv',
				),
				'needs' => array(),
			),

			'seo' => array(
				'label' => 'SEO (Yoast)',
				'summary' => 'Your Yoast titles, descriptions and GTINs',
				'help'  => 'One row per post that carries Yoast meta. Only the rows that are products are '
					. 'imported; the rest are named in the import report.',
				'files' => array( 'seo.csv' ),
				'needs' => array(
					'catalogue' => array(
						'severity' => self::REPORTED,
						'consequence' => 'SeoImporter matches every row to a product by its WooCommerce id and '
							. 'WILL NOT CREATE ONE. Without the products, every row is refused by name '
							. '("no product with wc_id 4021 - import products before seo"). Nothing is lost '
							. 'and nothing is written; you would be exporting a file that imports as a page '
							. 'of refusals.',
					),
				),
			),

			'coupons' => array(
				'label' => 'Coupons',
				'summary' => 'Your discount codes',
				'help'  => 'Published coupons only, with their restrictions. An unpublished coupon is held '
					. 'back and counted, because this shop has no draft state for one.',
				'files' => array( 'coupons.csv' ),
				'needs' => array(
					'catalogue' => array(
						'severity' => self::REPORTED,
						'consequence' => 'A coupon restricted to particular products or categories names them '
							. 'by their WooCommerce ids, which CouponImporter translates through the '
							. 'catalogue. Where nothing in an include list translates the coupon is REFUSED '
							. 'by name -- deliberately, because an empty include list reads as "valid on '
							. 'everything" and a 50% code meant for three lines would go live on the whole '
							. 'shop. Unrestricted coupons import correctly either way.',
					),
				),
			),

			'customers' => array(
				'label' => 'Customers',
				'summary' => 'Every WordPress user, with their addresses',
				'help'  => 'Administrators and editors too, not only the customer role: anyone may have '
					. 'ordered, and a missing one splits their order history in two.',
				'files' => array( 'customers.csv' ),
				'needs' => array(),
			),

			'sales' => array(
				'label' => 'Orders',
				'summary' => 'Orders, order lines, refunds and order notes',
				'help'  => 'The four files that make up an order: its header and money, the lines inside it, '
					. 'the money given back, and the history written on it.',
				'files' => array(
					'orders.csv',
					'order_items.csv',
					'refunds.csv',
					'order_notes.csv',
				),
				'needs' => array(
					/*
					 * THE ONE THAT COSTS ROWS WITHOUT SAYING SO. Read the class
					 * comment above before touching the severity on this entry.
					 */
					'customers' => array(
						'severity' => self::LOSES,
						'consequence' => 'Orders must reach the new shop AFTER the customers, whether in this '
							. 'export or an earlier one. An order whose customer is not there yet links by '
							. 'billing email and creates a guest customer with no WordPress id -- and when '
							. 'the real customers are imported later, every one of them whose email a guest '
							. 'row already holds is REFUSED as a collision. Measured on a rehearsal of this '
							. 'migration: 14 of 80 customers lost '
							. '(docs/FV-IMPORT-AT-VOLUME.md section 6). Those refusals are printed -- but in '
							. 'the customer import weeks later, which is why nobody connects them to this '
							. 'export.',
					),
					'catalogue' => array(
						'severity' => self::REPORTED,
						'consequence' => 'Each order line names its product by WooCommerce id. Without the '
							. 'catalogue the line still imports with its name, SKU and money intact, but with '
							. 'a null product_id and a note saying so, so a product-level sales report will '
							. 'not see it. Re-importing order_items.csv once the products are in repairs '
							. 'every line -- the importer rewrites product_id on each pass.',
					),
				),
			),

			'reviews' => array(
				'label' => 'Reviews',
				'summary' => 'Product reviews and your replies to them',
				'help'  => 'Comments carrying a rating. A product comment with no rating is a question, not '
					. 'a review, and is counted rather than exported.',
				'files' => array( 'reviews.csv' ),
				'needs' => array(
					'catalogue' => array(
						'severity' => self::REPORTED,
						'consequence' => 'ReviewImporter refuses a review whose product is not in the shop, by '
							. 'name, rather than importing it unattached -- an orphaned review would be '
							. 'published on the homepage wall belonging to nothing. Without the catalogue the '
							. 'whole file imports as refusals.',
					),
					'customers' => array(
						'severity' => self::REPORTED,
						'consequence' => 'A review by a registered shopper is attached to that customer. '
							. 'Without the customers it still imports, under the name and email on the '
							. 'comment, but it is not linked to the account -- so it does not appear on the '
							. 'shopper\'s own page. Re-importing reviews.csv after the customers relinks them.',
					),
				),
			),

			'content' => array(
				'label' => 'Journal articles',
				'summary' => 'Your blog and your pages',
				'help'  => 'Every WordPress post type that is not another file\'s job. Only articles are '
					. 'imported; a page or anything else is named in the import report rather than written.',
				'files' => array( 'posts.csv' ),
				'needs' => array(),
			),

			'addresses' => array(
				'label' => 'Addresses and pictures',
				'summary' => 'The old URLs to redirect, and the image files to fetch',
				'help'  => 'permalinks.csv is what every old address becomes on the new shop. media.csv lists '
					. 'the picture files the catalogue and the blog actually reference, so the new shop can '
					. 'download them itself -- the image LINKS are already inside products.csv and posts.csv '
					. 'and travel with those groups.',
				'files' => array( 'permalinks.csv', 'media.csv' ),
				'needs' => array(
					'catalogue' => array(
						'severity' => self::REPORTED,
						'consequence' => 'A redirect row is only usable once the shop can say where that '
							. 'product or category lives now. RedirectMap answers every row whose subject is '
							. 'absent with "nothing in this shop carries product id 4021 - either it was '
							. 'never imported, or it is in the discard bucket" and puts it in the ASK pile '
							. 'for you to decide, so nothing is written wrongly. Run it again after the '
							. 'catalogue and the same rows resolve.',
					),
					'content' => array(
						'severity' => self::REPORTED,
						'consequence' => 'The same, for the blog: a redirect from an old article address has '
							. 'nothing to point at until the articles are imported, and lands in the ASK pile '
							. 'until they are.',
					),
				),
			),
		);
	}

	/** @return array<int,string> */
	public static function keys() {
		return array_keys( self::all() );
	}

	public static function exists( $key ) {
		$all = self::all();

		return isset( $all[ $key ] );
	}

	/**
	 * Every file every group writes, in group order.
	 *
	 * @return array<int,string>
	 */
	public static function every_file() {
		$out = array();

		foreach ( self::all() as $group ) {
			foreach ( $group['files'] as $file ) {
				$out[] = $file;
			}
		}

		return $out;
	}

	/**
	 * The group a file belongs to, or '' if none does.
	 *
	 * @return string
	 */
	public static function group_for_file( $file ) {
		foreach ( self::all() as $key => $group ) {
			if ( in_array( $file, $group['files'], true ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * The files the given selection writes.
	 *
	 * @param array<int,string> $selection
	 * @return array<int,string>
	 */
	public static function files_for( array $selection ) {
		$all = self::all();
		$out = array();

		foreach ( self::normalise( $selection ) as $key ) {
			foreach ( $all[ $key ]['files'] as $file ) {
				$out[] = $file;
			}
		}

		return $out;
	}

	/**
	 * A selection, cleaned: unknown keys dropped, duplicates removed, put back
	 * into the declared order.
	 *
	 * ORDER MATTERS AND IS NOT THE OPERATOR'S. The runner's stage list is
	 * filtered through this, and the stage order is the export's own failure
	 * mode -- the catalogue is written first so an export that dies at 60% has
	 * the products and not just the tags. A selection arriving as
	 * ['sales','catalogue'] must not reorder the export.
	 *
	 * @param array<int,string> $selection
	 * @return array<int,string>
	 */
	public static function normalise( array $selection ) {
		$out = array();

		foreach ( self::keys() as $key ) {
			if ( in_array( $key, $selection, true ) ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * Every dependency this selection does not satisfy.
	 *
	 * One entry per crossed edge, carrying both ends, the severity and the
	 * sentence the screen prints. `id` is what the operator's confirmation is
	 * keyed on, so a confirmation cannot drift onto a different dependency when
	 * the selection changes.
	 *
	 * @param array<int,string> $selection
	 * @return array<int,array<string,string>>
	 */
	public static function unmet( array $selection ) {
		$all      = self::all();
		$selected = self::normalise( $selection );
		$out      = array();

		foreach ( $selected as $key ) {
			foreach ( $all[ $key ]['needs'] as $needed => $edge ) {
				if ( in_array( $needed, $selected, true ) ) {
					continue;
				}

				$out[] = array(
					'id'          => $key . ':' . $needed,
					'group'       => $key,
					'group_label' => $all[ $key ]['label'],
					'needs'       => $needed,
					'needs_label' => $all[ $needed ]['label'],
					'severity'    => $edge['severity'],
					'consequence' => $edge['consequence'],
				);
			}
		}

		return $out;
	}

	/**
	 * The unmet dependencies the operator has NOT confirmed are already in the
	 * new shop. While this is non-empty the export must not start.
	 *
	 * @param array<int,string> $selection
	 * @param array<int,string> $confirmed dependency ids, e.g. 'sales:customers'
	 * @return array<int,array<string,string>>
	 */
	public static function outstanding( array $selection, array $confirmed ) {
		$out = array();

		foreach ( self::unmet( $selection ) as $edge ) {
			if ( ! in_array( $edge['id'], $confirmed, true ) ) {
				$out[] = $edge;
			}
		}

		return $out;
	}

	/**
	 * The sentence start() refuses with, and the screen prints on the button.
	 *
	 * It names the groups rather than the edge ids, because the operator ticked
	 * groups and has never seen an edge id.
	 *
	 * @param array<int,array<string,string>> $outstanding
	 * @return string
	 */
	public static function refusal( array $outstanding ) {
		if ( empty( $outstanding ) ) {
			return '';
		}

		$parts = array();

		foreach ( $outstanding as $edge ) {
			$parts[] = $edge['group_label'] . ' needs ' . $edge['needs_label'];
		}

		return 'This selection would export ' . implode( '; ', $parts )
			. '. Either add the missing group, or confirm on the screen that it is already imported into '
			. 'the new shop. The consequence of each is printed beside it.';
	}

	/**
	 * What manifest.json records about the selection.
	 *
	 * docs/WP-EXPORT-CONTRACT.md already makes "absent from `files`" and
	 * `"rows": 0` different facts, and this is what makes that distinction
	 * load-bearing: a group that was not exported has NO entry in `files`, so
	 * the shop can tell "this export does not carry coupons" from "this shop has
	 * no coupons" without being told twice. This block says the same thing in
	 * the owner's own vocabulary, and adds the one thing the file list cannot
	 * carry -- what he confirmed was already imported, which is a claim about
	 * the OTHER shop and can only come from him.
	 *
	 * The contract permits it: "Unknown keys are ignored, never fatal."
	 *
	 * @param array<int,string> $selection
	 * @param array<int,string> $confirmed
	 * @return array<string,mixed>
	 */
	public static function manifest_block( array $selection, array $confirmed ) {
		$all      = self::all();
		$selected = self::normalise( $selection );
		$skipped  = array();

		foreach ( self::keys() as $key ) {
			if ( ! in_array( $key, $selected, true ) ) {
				$skipped[] = $key;
			}
		}

		$assumed = array();

		foreach ( self::unmet( $selected ) as $edge ) {
			if ( in_array( $edge['id'], $confirmed, true ) ) {
				$assumed[] = array(
					'group'    => $edge['group'],
					'needs'    => $edge['needs'],
					'severity' => $edge['severity'],
					'claim'    => $all[ $edge['needs'] ]['label'] . ' was already imported into the new shop '
						. 'when this export was taken. The operator stated this; the plugin cannot see the '
						. 'other shop and did not check it.',
				);
			}
		}

		return array(
			'selected' => $selected,
			'skipped'  => $skipped,
			'files'    => self::files_for( $selected ),
			'assumed_already_imported' => $assumed,
		);
	}

	/**
	 * The notes manifest.json carries about the selection, in words.
	 *
	 * A skipped group is a real absence and the contract's whole argument is
	 * that an absence has to be STATED. `files` states it structurally; this
	 * states it in the sentence the owner reads on the import screen.
	 *
	 * @param array<int,string> $selection
	 * @param array<int,string> $confirmed
	 * @return array<int,string>
	 */
	public static function notes( array $selection, array $confirmed ) {
		$all      = self::all();
		$selected = self::normalise( $selection );
		$notes    = array();

		$labels = array();

		foreach ( $selected as $key ) {
			$labels[] = $all[ $key ]['label'];
		}

		$notes[] = count( $selected ) === count( self::keys() )
			? 'Every group was exported: ' . implode( ', ', $labels ) . '.'
			: 'This is a PARTIAL export. Groups exported: ' . implode( ', ', $labels ) . '.';

		foreach ( self::keys() as $key ) {
			if ( in_array( $key, $selected, true ) ) {
				continue;
			}

			$notes[] = 'NOT in this export: ' . $all[ $key ]['label'] . ' (' . implode( ', ', $all[ $key ]['files'] )
				. '). Those files are absent from `files` in this manifest rather than present with zero rows, '
				. 'which is the contract\'s way of saying the export does not carry them rather than that the '
				. 'shop has none.';
		}

		foreach ( self::unmet( $selected ) as $edge ) {
			if ( ! in_array( $edge['id'], $confirmed, true ) ) {
				continue;
			}

			$notes[] = $edge['group_label'] . ' was exported without ' . $edge['needs_label']
				. ' because the operator confirmed ' . $edge['needs_label'] . ' is already imported into the '
				. 'new shop. If that is not true, read this before importing: ' . $edge['consequence'];
		}

		return $notes;
	}
}
