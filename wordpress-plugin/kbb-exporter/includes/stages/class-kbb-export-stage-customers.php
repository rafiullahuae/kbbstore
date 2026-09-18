<?php
/**
 * `customers.csv` -- WordPress users, read by CustomerImporter.
 *
 * ── EVERY USER, NOT JUST THE ONES WITH THE `customer` ROLE ──────────────────
 *
 * A six-year-old shop has shoppers whose role was changed, shoppers who were
 * given `subscriber` by a newsletter plugin, and an administrator who has
 * certainly ordered from his own shop. Filtering on the role would drop those
 * orders' owner and leave OrderImporter to synthesise a guest customer for a
 * person who has an account -- a second row for one shopper, with their history
 * split across the two.
 *
 * So every row of `wp_users` goes out, the role breakdown goes into
 * manifest.json's notes so the owner can see what he is importing, and the
 * decision stays his. CustomerImporter makes each one a customer row with their
 * WordPress password hash in `legacy_password`, which is what
 * CustomerAuthController expects.
 *
 * ── THE PASSWORD COLUMN IS THE WORDPRESS HASH AND NOTHING ELSE ──────────────
 *
 * CustomerImporter: "`legacy_password` takes the WordPress phpass or wp-bcrypt
 * hash and `password` is left NULL ... Writing the WP hash into `password`
 * instead would be worse than useless -- the column is cast `hashed`, so
 * assigning it re-hashes the hash and nobody can ever sign in." This file
 * writes one column, `password_hash`, which is the first alias that importer
 * reads, and never anything called `password`.
 *
 * ── AND THIS IS THE FILE THAT MUST NOT BE LEFT LYING ABOUT ──────────────────
 *
 * It holds every shopper's name, address, phone number and password hash. The
 * runner writes an index.php and a deny-all .htaccess beside it and the admin
 * screen says, in words, to delete the folder once the export is downloaded.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Stage_Customers extends KBB_Export_Stage {

	/** @var array<int,string>|null */
	private $meta_keys;

	public function file() {
		return 'customers.csv';
	}

	public function columns() {
		return array_merge(
			array( 'user_id', 'email', 'name', 'first_name', 'last_name', 'phone', 'registered', 'password_hash', 'roles' ),
			$this->address_columns( 'billing' ),
			$this->address_columns( 'shipping' )
		);
	}

	/**
	 * The address columns AddressWriter::readType() looks for, in its own
	 * spelling.
	 *
	 * `billing_address_1` and not `billing_line1`: that class lists the aliases
	 * most canonical first and `<type>_address_1` is the first one, which is
	 * also what `wp_usermeta` is keyed by. There is no reason to make the
	 * importer fall through its alias list on every row of every file.
	 *
	 * `<type>_country` is emitted exactly as WordPress holds it -- two letters
	 * on any shop WooCommerce has ever configured. AddressWriter REFUSES a
	 * three-letter code rather than truncating it, because "addresses.country is
	 * varchar(2) on a Phase 0 server, so a longer code would be truncated to its
	 * first two letters and the shipping zones ... would silently stop matching."
	 * That refusal is the right behaviour and this plugin does not paper over it.
	 *
	 * @return array<int,string>
	 */
	private function address_columns( $type ) {
		return array(
			$type . '_first_name', $type . '_last_name', $type . '_company',
			$type . '_address_1', $type . '_address_2', $type . '_city',
			$type . '_state', $type . '_postcode', $type . '_country', $type . '_phone',
		);
	}

	/** @return array<int,string> */
	private function meta_keys() {
		global $wpdb;

		if ( null !== $this->meta_keys ) {
			return $this->meta_keys;
		}

		$keys = array( 'first_name', 'last_name', 'nickname', $wpdb->prefix . 'capabilities' );

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( $this->address_columns( $type ) as $column ) {
				$keys[] = $column;
			}
		}

		$this->meta_keys = $keys;

		return $this->meta_keys;
	}

	public function total() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'users' );
	}

	public function batch( $cursor, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT ID, user_email, user_login, user_nicename, display_name, user_pass, user_registered
			 FROM ' . $wpdb->prefix . 'users
			 WHERE ID > ' . (int) $cursor . '
			 ORDER BY ID
			 LIMIT ' . (int) $limit,
			ARRAY_A
		);

		$rows = (array) $rows;

		if ( empty( $rows ) ) {
			$this->report_roles();

			return array( 'rows' => array(), 'cursor' => (int) $cursor, 'done' => true );
		}

		$ids  = $this->ids_of( $rows );
		$meta = KBB_Export_Wp::user_meta( $ids, $this->meta_keys() );

		$out = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			$m  = isset( $meta[ $id ] ) ? $meta[ $id ] : array();

			$get = function ( $key ) use ( $m ) {
				return isset( $m[ $key ] ) ? (string) $m[ $key ] : '';
			};

			$record = array(
				'user_id'    => $id,
				'email'      => $row['user_email'],
				// display_name, which is what the shopper sees themselves
				// called. CustomerImporter falls back to first + last when this
				// is blank, so an empty cell is not a loss.
				'name'       => $row['display_name'],
				'first_name' => $get( 'first_name' ),
				'last_name'  => $get( 'last_name' ),
				// WordPress has no phone field of its own; the billing one is
				// the only number a WooCommerce shopper ever gives.
				'phone'      => $get( 'billing_phone' ),
				'registered' => $row['user_registered'],
				'password_hash' => $row['user_pass'],
				'roles'      => $this->roles( $get( $wpdb->prefix . 'capabilities' ) ),
			);

			foreach ( array( 'billing', 'shipping' ) as $type ) {
				foreach ( $this->address_columns( $type ) as $column ) {
					$record[ $column ] = $get( $column );
				}
			}

			$out[] = $record;
		}

		$done = count( $rows ) < (int) $limit;

		if ( $done ) {
			$this->report_roles();
		}

		return array( 'rows' => $out, 'cursor' => (int) end( $ids ), 'done' => $done );
	}

	/** `a:1:{s:8:"customer";b:1;}` as `customer`. */
	private function roles( $raw ) {
		$value = maybe_unserialize( (string) $raw );

		if ( ! is_array( $value ) ) {
			return '';
		}

		$roles = array();

		foreach ( $value as $role => $granted ) {
			if ( $granted ) {
				$roles[] = (string) $role;
			}
		}

		return $this->commas( $roles );
	}

	/**
	 * The role breakdown, so "every user is a customer here" is a fact the
	 * owner reads before the import rather than a surprise afterwards.
	 */
	private function report_roles() {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT meta_value, COUNT(*) AS n FROM ' . $wpdb->prefix . 'usermeta
			 WHERE meta_key = ' . KBB_Export_Wp::quote( $wpdb->prefix . 'capabilities' ) . '
			 GROUP BY meta_value',
			ARRAY_A
		);

		$tally = array();

		foreach ( (array) $rows as $row ) {
			$value = maybe_unserialize( (string) $row['meta_value'] );

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $role => $granted ) {
				if ( $granted ) {
					$tally[ (string) $role ] = ( isset( $tally[ $role ] ) ? $tally[ $role ] : 0 ) + (int) $row['n'];
				}
			}
		}

		if ( empty( $tally ) ) {
			return;
		}

		ksort( $tally );

		$parts = array();

		foreach ( $tally as $role => $count ) {
			$parts[] = $count . ' ' . $role;
		}

		$this->note(
			'customers.csv carries EVERY WordPress user, not only the ones with the customer role ('
				. implode( ', ', $parts ) . '). A shopper whose role was changed, or an administrator who has '
				. 'ordered, would otherwise be left out and OrderImporter would synthesise a second, guest '
				. 'customer row for the same person.'
		);
	}
}
