<?php
/**
 * WordPress, reduced to the fourteen functions and one global this plugin uses.
 *
 * ============================================================================
 * WHY THIS EXISTS AND WHAT IT IS AND IS NOT EVIDENCE OF
 * ============================================================================
 *
 * There is no WordPress in this sandbox and every external host is blocked, so
 * the plugin cannot be installed and run. What CAN be done is the thing that
 * actually matters: build WordPress-SHAPED tables in the same MySQL the shop's
 * own suite runs against, fill them with a realistic and deliberately nasty
 * catalogue, and run the plugin's real stage classes over them.
 *
 * WHAT THAT PROVES: every SQL statement the plugin issues is executed by a real
 * MySQL against real WordPress table shapes, every column derivation is
 * exercised on real rows, and the CSVs that come out are the CSVs the plugin
 * writes -- which are then fed to this repository's real importer, which is the
 * whole point.
 *
 * WHAT IT DOES NOT PROVE: that `get_permalink()` on the live site returns what
 * the stub below returns. A live WordPress runs every rewrite rule and every
 * plugin filter, and no stub can stand in for that. That is why permalinks.csv
 * carries a `source` column saying `wp` or `derived` per row, and why
 * docs/GE-WP-EXPORTER.md names one real run on the live site as the thing still
 * outstanding. The stub deliberately implements what CORE WordPress does from
 * `permalink_structure` and `woocommerce_permalinks`, so the harness exercises
 * the shape the export is meant to carry -- not so that anyone can claim the
 * live answer is known.
 *
 * KEPT HONEST BY BEING SMALL. KBB_Export_Wp's header names every WordPress
 * function this plugin is allowed to call. If a stage ever calls a fifteenth,
 * it fails here with an undefined-function error rather than working under the
 * harness and failing on the live site, or the reverse.
 */

// ---------------------------------------------------------------------------
// The one global.
// ---------------------------------------------------------------------------

/**
 * $wpdb over PDO.
 *
 * Only the four read methods and the two escaping helpers the plugin uses.
 * WordPress's own $wpdb has fifty more; implementing them would be inventing a
 * surface nothing exercises.
 */
class KBB_Harness_Wpdb {

	/** @var PDO */
	public $pdo;

	/** @var string */
	public $prefix;

	/** @var array<int,string> every statement issued, for the tests to assert on */
	public $queries = array();

	public function __construct( PDO $pdo, $prefix = 'wp_' ) {
		$this->pdo    = $pdo;
		$this->prefix = $prefix;
	}

	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;

		$statement = $this->pdo->query( $sql );

		return false === $statement ? array() : $statement->fetchAll( PDO::FETCH_ASSOC );
	}

	public function get_row( $sql, $output = null ) {
		$rows = $this->get_results( $sql );

		return empty( $rows ) ? null : $rows[0];
	}

	public function get_var( $sql ) {
		$rows = $this->get_results( $sql );

		if ( empty( $rows ) ) {
			return null;
		}

		$first = reset( $rows[0] );

		return $first;
	}

	public function get_col( $sql ) {
		$out = array();

		foreach ( $this->get_results( $sql ) as $row ) {
			$out[] = reset( $row );
		}

		return $out;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;

		return $this->pdo->exec( $sql );
	}

	/** KBB_Export_Wp::quote() prefers this when it is there. */
	public function kbb_quote( $value ) {
		return $this->pdo->quote( (string) $value );
	}

	public function _real_escape( $value ) {
		return trim( $this->pdo->quote( (string) $value ), "'" );
	}
}

// ---------------------------------------------------------------------------
// Options, in a table, because the plugin's checkpoint lives in one.
// ---------------------------------------------------------------------------

function get_option( $name, $default = false ) {
	global $wpdb;

	$row = $wpdb->get_row(
		'SELECT option_value FROM ' . $wpdb->prefix . 'options WHERE option_name = ' . $wpdb->kbb_quote( $name ) . ' LIMIT 1'
	);

	if ( null === $row ) {
		return $default;
	}

	return maybe_unserialize( $row['option_value'] );
}

function update_option( $name, $value ) {
	global $wpdb;

	$stored = is_scalar( $value ) ? (string) $value : serialize( $value );

	$wpdb->query(
		'INSERT INTO ' . $wpdb->prefix . 'options (option_name, option_value, autoload) VALUES ('
			. $wpdb->kbb_quote( $name ) . ', ' . $wpdb->kbb_quote( $stored ) . ", 'no')
		 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
	);

	return true;
}

function delete_option( $name ) {
	global $wpdb;

	$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'options WHERE option_name = ' . $wpdb->kbb_quote( $name ) );

	return true;
}

/**
 * WordPress's own, near enough: a serialised string comes back as data and
 * anything else comes back untouched.
 *
 * `['allowed_classes' => false]` matters and is not decoration --
 * App\Support\YoastTiers::gtinFrom() uses the same guard for the same reason,
 * and a harness that unserialised objects would be a harness with a wider
 * attack surface than the thing it is testing.
 */
function maybe_unserialize( $value ) {
	if ( ! is_string( $value ) ) {
		return $value;
	}

	$trimmed = trim( $value );

	if ( preg_match( '/^[aOsbdiN];?/', $trimmed ) !== 1 ) {
		return $value;
	}

	$result = @unserialize( $trimmed, array( 'allowed_classes' => false ) );

	return false === $result && 'b:0;' !== $trimmed ? $value : $result;
}

// ---------------------------------------------------------------------------
// Uploads and URLs.
// ---------------------------------------------------------------------------

function wp_upload_dir() {
	return array(
		'basedir' => getenv( 'KBB_HARNESS_UPLOADS' ) ?: sys_get_temp_dir() . '/kbb-harness-uploads',
		'baseurl' => rtrim( (string) get_option( 'home', 'https://example.test' ), '/' ) . '/wp-content/uploads',
	);
}

function home_url( $path = '' ) {
	return rtrim( (string) get_option( 'home', '' ), '/' ) . '/' . ltrim( (string) $path, '/' );
}

function wp_get_attachment_url( $id ) {
	global $wpdb;

	$row = $wpdb->get_row(
		'SELECT meta_value FROM ' . $wpdb->prefix . "postmeta
		 WHERE post_id = " . (int) $id . " AND meta_key = '_wp_attached_file' LIMIT 1"
	);

	if ( null === $row ) {
		return false;
	}

	$dir = wp_upload_dir();

	return $dir['baseurl'] . '/' . ltrim( (string) $row['meta_value'], '/' );
}

function wp_get_attachment_metadata( $id ) {
	global $wpdb;

	$row = $wpdb->get_row(
		'SELECT meta_value FROM ' . $wpdb->prefix . "postmeta
		 WHERE post_id = " . (int) $id . " AND meta_key = '_wp_attachment_metadata' LIMIT 1"
	);

	return null === $row ? false : maybe_unserialize( $row['meta_value'] );
}

/**
 * Core WordPress's product/post permalink, from `permalink_structure` and
 * `woocommerce_permalinks` and nothing else. See the file header on exactly
 * what this is and is not evidence of.
 */
function get_permalink( $id ) {
	global $wpdb;

	$row = $wpdb->get_row(
		'SELECT post_name, post_type, post_parent FROM ' . $wpdb->prefix . 'posts WHERE ID = ' . (int) $id . ' LIMIT 1'
	);

	if ( null === $row ) {
		return false;
	}

	$home  = rtrim( (string) get_option( 'home', '' ), '/' );
	$slug  = (string) $row['post_name'];
	$links = get_option( 'woocommerce_permalinks', array() );
	$links = is_array( $links ) ? $links : array();

	if ( 'product' === $row['post_type'] ) {
		$base = isset( $links['product_base'] ) ? trim( (string) $links['product_base'], '/' ) : 'product';

		return $home . '/' . ( '' === $base ? '' : $base . '/' ) . $slug . '/';
	}

	if ( 'page' === $row['post_type'] ) {
		$path   = $slug;
		$parent = (int) $row['post_parent'];
		$guard  = 0;

		while ( $parent > 0 && $guard++ < 10 ) {
			$up = $wpdb->get_row( 'SELECT post_name, post_parent FROM ' . $wpdb->prefix . 'posts WHERE ID = ' . $parent . ' LIMIT 1' );

			if ( null === $up ) {
				break;
			}

			$path   = $up['post_name'] . '/' . $path;
			$parent = (int) $up['post_parent'];
		}

		return $home . '/' . $path . '/';
	}

	// The blog. `/%postname%/` is what the owner confirmed for articles --
	// 2026_09_14_160000_seed_phase9_post_url_redirects records it -- and the
	// structure option is what produces it.
	$structure = (string) get_option( 'permalink_structure', '/%postname%/' );

	if ( false !== strpos( $structure, '%postname%' ) && '/%postname%/' === $structure ) {
		return $home . '/' . $slug . '/';
	}

	return $home . '/?p=' . (int) $id;
}

/**
 * A term archive URL, or a WP_Error-shaped false for a taxonomy with none.
 *
 * `false` rather than a WP_Error object because the plugin tests `is_string()`
 * -- it never inspects the error -- and a stub that returned an object would be
 * modelling a class nothing reads.
 */
function get_term_link( $term_id, $taxonomy = '' ) {
	global $wpdb;

	$row = $wpdb->get_row(
		'SELECT t.slug FROM ' . $wpdb->prefix . 'terms t
		 JOIN ' . $wpdb->prefix . 'term_taxonomy tt ON tt.term_id = t.term_id
		 WHERE t.term_id = ' . (int) $term_id . ' AND tt.taxonomy = ' . $wpdb->kbb_quote( $taxonomy ) . ' LIMIT 1'
	);

	if ( null === $row ) {
		return false;
	}

	$home  = rtrim( (string) get_option( 'home', '' ), '/' );
	$links = get_option( 'woocommerce_permalinks', array() );
	$links = is_array( $links ) ? $links : array();

	$bases = array(
		'product_cat' => isset( $links['category_base'] ) ? (string) $links['category_base'] : 'product-category',
		'product_tag' => isset( $links['tag_base'] ) ? (string) $links['tag_base'] : 'product-tag',
		'category'    => 'category',
		'post_tag'    => 'tag',
	);

	if ( isset( $bases[ $taxonomy ] ) ) {
		$base = trim( $bases[ $taxonomy ], '/' );

		return $home . '/' . ( '' === $base ? '' : $base . '/' ) . $row['slug'] . '/';
	}

	if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
		// A product attribute has an archive only when it was registered with
		// `attribute_public = 1`, which is a column in
		// wp_woocommerce_attribute_taxonomies. This is the branch that answers
		// "did brand archives exist at all" for a shop whose brands are an
		// attribute, and it answers it from the shop's own setting.
		$definition = $wpdb->get_row(
			'SELECT attribute_public FROM ' . $wpdb->prefix . 'woocommerce_attribute_taxonomies
			 WHERE attribute_name = ' . $wpdb->kbb_quote( substr( $taxonomy, 3 ) ) . ' LIMIT 1'
		);

		if ( null === $definition || ! (int) $definition['attribute_public'] ) {
			return false;
		}

		$base = isset( $links['attribute_base'] ) ? trim( (string) $links['attribute_base'], '/' ) : '';

		return $home . '/' . ( '' === $base ? '' : $base . '/' ) . substr( $taxonomy, 3 ) . '/' . $row['slug'] . '/';
	}

	return false;
}

// ---------------------------------------------------------------------------
// The admin surface, stubbed to nothing: the harness never draws the screen.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
	function add_management_page( $page, $menu, $cap, $slug, $callback ) {}
	function current_user_can( $cap ) { return true; }
	function check_ajax_referer( $action, $field ) { return true; }
	function wp_create_nonce( $action ) { return 'harness'; }
	function wp_send_json( $data, $status = 200 ) { echo json_encode( $data ); }
	function admin_url( $path ) { return home_url( 'wp-admin/' . $path ); }
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
	function disabled( $value, $compare = true, $echo = true ) { return ''; }
	function wp_json_encode( $data ) { return json_encode( $data ); }
	function wp_die( $message ) { throw new RuntimeException( (string) $message ); }
}

define( 'ABSPATH', __DIR__ . '/' );

// WordPress's $wpdb output constants. The plugin always passes ARRAY_A.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'ARRAY_N', 'ARRAY_N' );
	define( 'OBJECT', 'OBJECT' );
}
