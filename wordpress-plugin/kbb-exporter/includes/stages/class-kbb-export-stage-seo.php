<?php
/**
 * `seo.csv` -- one row per product, every Yoast column this database holds.
 *
 * ── THE COLUMNS ARE DISCOVERED, NOT LISTED ──────────────────────────────────
 *
 * App\Support\YoastSeo names five keys it imports and nineteen it deliberately
 * does not; App\Support\YoastTiers names a WIDER set again, "because a key
 * nobody has documented comes back as tier `unknown` and is reported as
 * unknown. 'We did not import it' and 'we never heard of it' look identical in
 * a database and only one of them is a decision."
 *
 * An exporter that wrote only the keys those two classes already know would
 * make that distinction impossible to draw: an undocumented key would be absent
 * from the export, and absent is indistinguishable from "this shop never had
 * one". So the columns are one `SELECT DISTINCT meta_key` over the two
 * prefixes, and whatever this shop really wrote comes out. YoastTiers::
 * unrecognised() then has something to be unrecognised ABOUT.
 *
 * ── THE ONE KEY THAT DOES NOT START WITH AN UNDERSCORE ──────────────────────
 *
 * `wpseo_global_identifier_values` -- the GTIN map, from the WooCommerce SEO
 * add-on. docs/FX-YOAST-TIER-CENSUS.md §2 makes the point that it "DOES NOT
 * START WITH `_yoast_`. It is `wpseo_global_identifier_values`: no leading
 * underscore, no `yoast`", which is exactly why a LIKE on `_yoast_wpseo_%`
 * alone misses it, and why that document calls the GTIN blocker "an import gap,
 * not a data gap". Both prefixes are scanned. The variation-level spelling,
 * `wpseo_variation_global_identifiers_values` (Yoast's own plural asymmetry, not
 * a typo), is picked up by the same scan because it shares the `wpseo_` prefix.
 *
 * ── AND A SHOP WITH NO YOAST AT ALL WRITES NO ROWS, NOT BLANK ONES ──────────
 *
 * SeoImporter refuses a row with no Yoast column: "no _yoast_wpseo_* column in
 * this row -- is this file the Yoast export?", one refusal per row. A shop that
 * never ran Yoast would produce 671 of those out of a file this plugin wrote,
 * which is a report nobody can read about a problem nobody has. So when the
 * scan finds nothing, the file is written with its header and no rows, which
 * the contract says means exactly what it looks like: this shop has no Yoast
 * data, as against this export not carrying any.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Seo extends KBB_Export_Stage {

	/** @var array<int,string>|null */
	private $keys;

	public function file() {
		return 'seo.csv';
	}

	/**
	 * Every `_yoast_wpseo_*` and `wpseo_*` meta key in this database.
	 *
	 * ONE query, on a prefix, which `wp_postmeta`'s `meta_key` index serves as
	 * a range scan rather than a table scan. Cached per request because the
	 * runner asks for the columns once per batch and the answer cannot change
	 * mid-export.
	 *
	 * @return array<int,string>
	 */
	private function yoast_keys() {
		if ( null !== $this->keys ) {
			return $this->keys;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT DISTINCT meta_key FROM ' . $wpdb->prefix . "postmeta
			 WHERE meta_key LIKE '\\_yoast\\_wpseo\\_%' OR meta_key LIKE 'wpseo\\_%'
			 ORDER BY meta_key",
			ARRAY_A
		);

		$keys = array();

		foreach ( (array) $rows as $row ) {
			$keys[] = (string) $row['meta_key'];
		}

		$this->keys = $keys;

		return $this->keys;
	}

	public function columns() {
		$keys = $this->yoast_keys();

		if ( empty( $keys ) ) {
			// A header that still says what the file is for, so an owner who
			// opens it sees an empty Yoast export rather than a mystery.
			$keys = array(
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_canonical',
				'_yoast_wpseo_opengraph-image',
				'_yoast_wpseo_meta-robots-noindex',
			);
		}

		return array_merge( array( 'id' ), $keys );
	}

	public function total() {
		if ( empty( $this->yoast_keys() ) ) {
			$this->note( 'seo.csv is empty: no _yoast_wpseo_* or wpseo_* post meta exists in this database, so this shop has no Yoast data to carry.' );

			return 0;
		}

		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'product' AND post_status IN ('publish','draft','private','pending','future')"
		);
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$keys = $this->yoast_keys();

		if ( empty( $keys ) ) {
			return array( 'rows' => array(), 'cursor' => 0, 'done' => true );
		}

		$rows = $wpdb->get_results(
			'SELECT ID FROM ' . $wpdb->prefix . "posts
			 WHERE post_type = 'product' AND post_status IN ('publish','draft','private','pending','future')
			   AND ID > " . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids  = $this->ids_of( $rows );
		$meta = KBB_Export_Wp::post_meta_like( $ids, array( '_yoast_wpseo_', 'wpseo_' ) );

		$out = array();

		foreach ( $ids as $id ) {
			$row = array( 'id' => $id );
			$m   = isset( $meta[ $id ] ) ? $meta[ $id ] : array();

			foreach ( $keys as $key ) {
				// The value goes out EXACTLY as stored, serialised blob and
				// all. YoastTiers::gtinFrom() unpicks both the JSON and the
				// PHP-serialised shape itself -- "with unserialize(..., [
				// 'allowed_classes' => false])" -- and a plugin that helpfully
				// decoded it here would hand that class a shape it has no
				// branch for.
				$row[ $key ] = isset( $m[ $key ] ) ? $m[ $key ] : '';
			}

			$out[] = $row;
		}

		return array(
			'rows'   => $out,
			'cursor' => (int) end( $ids ),
			'done'   => count( $rows ) < (int) $limit,
		);
	}
}
