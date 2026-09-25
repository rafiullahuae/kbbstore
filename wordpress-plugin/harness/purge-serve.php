<?php
/**
 * Serve Tools -> KBB Export over HTTP, with a REAL delete behind the button.
 *
 *   KBB_PURGE_UPLOADS=/tmp/kbb-purge-shots \
 *     php -S 127.0.0.1:8731 wordpress-plugin/harness/purge-serve.php
 *
 * ── WHY THIS IS NOT screen.php + screen-drive.mjs ───────────────────────────
 *
 * screen.php renders the page once, and screen-drive.mjs INTERCEPTS fetch() and
 * answers admin-ajax itself. That is right for the export: putting the page into
 * "mid-run" and "stalled" needs a server that answers whatever the test likes.
 *
 * It is wrong for the delete, and wrongly in the exact direction this feature
 * exists to guard against. A faked admin-ajax that answers `{"ok":true}` draws
 * the same screen whether the files went or stayed -- which is precisely the
 * trap `wp_ajax_kbb_export_reset` sets, reproduced in the harness. A picture of
 * that is a picture of nothing.
 *
 * So this serves the plugin's OWN KBB_Export_Admin::ajax_purge() over HTTP,
 * against real files in a real folder. When the shot labelled "after" shows an
 * empty list, `ls` of the folder agrees with it, and /state says so in JSON.
 *
 * ── NO DATABASE ─────────────────────────────────────────────────────────────
 *
 * Deliberately. Everything the delete touches is a filesystem, and the only
 * thing on this screen that reads the shop is
 * KBB_Export_Orders_Source::detect(), which asks `SHOW TABLES LIKE`. A four-line
 * $wpdb answers that. Tying the one harness that takes the pictures to a running
 * MySQL would mean the pictures stop being takeable on the day MySQL is not up,
 * and the options store is a JSON file for the same reason.
 *
 * ── ROUTES ──────────────────────────────────────────────────────────────────
 *
 *   GET  /            the real screen, in wp-admin-ish CSS
 *   GET  /seed        rebuild two finished exports on disk; answers the census
 *   GET  /state       what is on disk right now, as JSON, read with `find`
 *   POST /wp-admin/admin-ajax.php   the real ajax_purge()
 */

define( 'ABSPATH', __DIR__ . '/' );

$kbb_uploads = getenv( 'KBB_PURGE_UPLOADS' );
$kbb_uploads = $kbb_uploads ? $kbb_uploads : sys_get_temp_dir() . '/kbb-purge-shots';
$kbb_options = $kbb_uploads . '/.harness-options.json';

if ( ! is_dir( $kbb_uploads ) ) {
	mkdir( $kbb_uploads, 0777, true );
}

/* ── WordPress, reduced to what this screen and this endpoint call ────────── */

function kbb_harness_options() {
	global $kbb_options;

	$raw = is_file( $kbb_options ) ? json_decode( (string) file_get_contents( $kbb_options ), true ) : array();

	return is_array( $raw ) ? $raw : array();
}

function kbb_harness_options_put( array $options ) {
	global $kbb_options;

	file_put_contents( $kbb_options, json_encode( $options ) );
}

function get_option( $name, $default = false ) { // phpcs:ignore
	$options = kbb_harness_options();

	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function update_option( $name, $value ) { // phpcs:ignore
	$options          = kbb_harness_options();
	$options[ $name ] = $value;

	kbb_harness_options_put( $options );

	return true;
}

function delete_option( $name ) { // phpcs:ignore
	$options = kbb_harness_options();

	unset( $options[ $name ] );
	kbb_harness_options_put( $options );

	return true;
}

function wp_upload_dir() { // phpcs:ignore
	global $kbb_uploads;

	return array( 'basedir' => $kbb_uploads, 'baseurl' => 'https://kbeautybliss.test/wp-content/uploads' );
}

function home_url( $path = '' ) { // phpcs:ignore
	return 'https://kbeautybliss.test/' . ltrim( (string) $path, '/' );
}

/*
 * STEERABLE, so the screenshot of "a shop manager, who may not delete" is the
 * plugin's own refusal rather than a hand-written paragraph. ?caps=... on any
 * request picks the set; unset means the administrator the owner is.
 */
function current_user_can( $capability ) { // phpcs:ignore
	$caps = isset( $_GET['caps'] )
		? array_filter( explode( ',', (string) $_GET['caps'] ) )
		: array( 'manage_woocommerce', 'manage_options', 'delete_users' );

	return in_array( $capability, $caps, true );
}

function wp_create_nonce( $action ) { // phpcs:ignore
	return 'harness-nonce-' . md5( (string) $action );
}

function check_ajax_referer( $action, $field ) { // phpcs:ignore
	if ( ! isset( $_POST[ $field ] ) || $_POST[ $field ] !== wp_create_nonce( $action ) ) {
		wp_send_json( array( 'ok' => false, 'error' => 'The security check failed. Reload this page.' ), 403 );
	}

	return true;
}

function wp_send_json( $data, $status = 200 ) { // phpcs:ignore
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}

function wp_unslash( $value ) { // phpcs:ignore
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_json_encode( $value ) { // phpcs:ignore
	return json_encode( $value, JSON_UNESCAPED_SLASHES );
}

function esc_html( $text ) { // phpcs:ignore
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) { // phpcs:ignore
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function disabled( $condition, $value = true, $echo = true ) { // phpcs:ignore
	$out = ( $condition == $value ) ? ' disabled="disabled"' : ''; // phpcs:ignore

	if ( $echo ) {
		echo $out; // phpcs:ignore
	}

	return $out;
}

function admin_url( $path = '' ) { // phpcs:ignore
	return '/wp-admin/' . ltrim( (string) $path, '/' );
}

function wp_nonce_url( $url, $action ) { // phpcs:ignore
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_wpnonce=' . wp_create_nonce( $action );
}

function wp_verify_nonce( $nonce, $action ) { // phpcs:ignore
	return wp_create_nonce( $action ) === $nonce ? 1 : false;
}

function wp_die( $message, $title = '', $args = array() ) { // phpcs:ignore
	http_response_code( isset( $args['response'] ) ? (int) $args['response'] : 403 );
	echo esc_html( (string) $message );
	exit;
}

function add_action( $hook, $callback ) {} // phpcs:ignore
function add_management_page( $a, $b, $c, $d, $e ) {} // phpcs:ignore
function nocache_headers() {} // phpcs:ignore
function maybe_unserialize( $value ) { return $value; } // phpcs:ignore

/**
 * $wpdb, reduced to the one question this screen asks it.
 *
 * KBB_Export_Orders_Source::detect() runs `SHOW TABLES LIKE '<prefix><name>'`
 * and nothing else is queried by the page, so a shop that keeps its orders in
 * wp_posts is four lines rather than a database.
 */
class KBB_Purge_Harness_Wpdb { // phpcs:ignore
	public $prefix = 'wp_';

	/*
	 * KBB_Export_Wp::quote() prefers kbb_quote() and falls back to WordPress's
	 * own _real_escape(). The harness offers the first, the way
	 * harness/wp-stubs.php does, so nothing here reaches for a mysqli handle
	 * that does not exist.
	 */
	public function kbb_quote( $value ) { // phpcs:ignore
		return "'" . addslashes( (string) $value ) . "'";
	}

	public function get_var( $sql ) { // phpcs:ignore
		return ( false !== strpos( $sql, "'wp_posts'" ) ) ? 'wp_posts' : null;
	}
}

$wpdb = new KBB_Purge_Harness_Wpdb();

require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-csv.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-wp.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-media-index.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-groups.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-zip.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-stage.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-orders-source.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-runner.php';
require __DIR__ . '/../kbb-exporter/admin/class-kbb-export-admin.php';

/* ── What is really on the disk, walked here and not asked of the plugin ──── */

function kbb_harness_census() {
	global $kbb_uploads;

	$root  = $kbb_uploads . '/kbb-export';
	$files = array();

	if ( is_dir( $root ) ) {
		$walk = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $walk as $item ) {
			if ( $item->isFile() ) {
				$files[] = str_replace( $root . '/', '', $item->getPathname() );
			}
		}
	}

	sort( $files );

	return array(
		'root'              => $root,
		'files'             => count( $files ),
		'names'             => $files,
		'customers_present' => in_array( '3f7a1c22-0001-4000-8000-aaaabbbbcccc/customers.csv', $files, true ),
		'guards'            => array(
			'index.php' => is_file( $root . '/index.php' ),
			'.htaccess' => is_file( $root . '/.htaccess' ),
		),
		'outside'           => array(
			'media'    => is_file( $kbb_uploads . '/2026/09/serum.jpg' ),
			'sibling'  => is_file( $kbb_uploads . '/kbb-export-old/customers.csv' ),
		),
	);
}

function kbb_harness_seed() {
	global $kbb_uploads;

	$root = $kbb_uploads . '/kbb-export';

	// Only ever inside the harness's own uploads folder.
	if ( is_dir( $root ) ) {
		exec( 'rm -rf ' . escapeshellarg( $root ) );
	}

	mkdir( $root, 0777, true );
	file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" );
	file_put_contents( $root . '/.htaccess', "Require all denied\n" );

	$ids = array( '3f7a1c22-0001-4000-8000-aaaabbbbcccc', '91b0ee54-0002-4000-8000-ddddeeeeffff' );

	foreach ( $ids as $index => $id ) {
		$dir = $root . '/' . $id;

		mkdir( $dir, 0777, true );

		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		file_put_contents( $dir . '/manifest.json', json_encode( array( 'export_id' => $id ) ) );
		file_put_contents( $dir . '/customers.csv', "id,email,password_hash,address_1\n"
			. str_repeat( "1,a@b.test,\$P\$Bxxxxxxxxxxxxxxxxxxxx,12 Al Wasl Road\n", 9000 ) );
		file_put_contents( $dir . '/reviews.csv', "id,author_email,ip\n"
			. str_repeat( "1,r@b.test,81.2.3.4\n", 6000 ) );
		file_put_contents( $dir . '/orders.csv', "id,total\n" . str_repeat( "1,199.00\n", 20000 ) );
		file_put_contents( $dir . '/products.csv', "id,sku\n" . str_repeat( "1,KBB-1\n", 3000 ) );
		file_put_contents( $dir . '/kbb-export-catalogue-' . $id . '.zip', str_repeat( 'PK', 40000 * ( $index + 1 ) ) );
	}

	// Neighbours that must survive: the media library, and a sibling whose name
	// merely BEGINS with the export root's.
	foreach ( array( '/2026/09/serum.jpg' => 'not a jpeg', '/kbb-export-old/customers.csv' => "id,keep\n1,me\n" ) as $rel => $body ) {
		$path = $kbb_uploads . $rel;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, $body );
	}

	delete_option( KBB_Export_Runner::STATE_OPTION );

	return kbb_harness_census();
}

/* ── Routing ─────────────────────────────────────────────────────────────── */

$path = parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );

if ( '/seed' === $path ) {
	header( 'Content-Type: application/json' );
	echo json_encode( kbb_harness_seed() );
	exit;
}

if ( '/state' === $path ) {
	header( 'Content-Type: application/json' );
	echo json_encode( kbb_harness_census() );
	exit;
}

if ( '/wp-admin/admin-ajax.php' === $path ) {
	$action = isset( $_POST['action'] ) ? (string) $_POST['action'] : '';

	if ( 'kbb_export_purge' === $action ) {
		KBB_Export_Admin::ajax_purge();
	}

	wp_send_json( array( 'ok' => false, 'error' => 'This harness only serves kbb_export_purge.' ), 400 );
}

ob_start();
KBB_Export_Admin::screen();
$kbb_screen = ob_get_clean();

/*
 * The half-dozen wp-admin rules the markup leans on, copied from screen.php so
 * the two harnesses draw the same page, plus `button-link-delete` -- the
 * destructive-button class wp-admin ships and screen.php never needed.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KBB Export &lsaquo; kbeautybliss &#8212; WordPress</title>
<style>
	body { margin: 0; background: #f0f0f1; color: #3c434a; font: 13px/1.6 -apple-system, "Segoe UI", Roboto, sans-serif; }
	.wrap { max-width: 1100px; margin: 0; padding: 10px 20px 40px; }
	h1 { font-size: 23px; font-weight: 400; margin: .5em 0 1em; }
	h2 { font-size: 15px; margin: 1.6em 0 .6em; }
	p { margin: .8em 0; }
	code { background: rgba(0,0,0,.07); padding: 1px 4px; border-radius: 2px; font-size: 12px;
		overflow-wrap: anywhere; }
	.description { color: #646970; font-size: 13px; font-style: italic; }
	.button { display: inline-block; background: #f6f7f7; border: 1px solid #2271b1; color: #2271b1;
		border-radius: 3px; padding: 4px 12px; font-size: 13px; cursor: pointer; }
	.button-primary { background: #2271b1; border-color: #2271b1; color: #fff; }
	.button-link-delete { background: #f6f7f7; border-color: #b32d2e; color: #b32d2e; }
	.button[disabled] { background: #f6f7f7; border-color: #dcdcde; color: #a7aaad; cursor: default; }
	input[type=text] { border: 1px solid #8c8f94; border-radius: 4px; padding: 4px 8px; font-size: 13px; }
	table.widefat { border-collapse: collapse; width: 100%; background: #fff;
		border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
	table.widefat th { text-align: left; padding: 10px; border-bottom: 1px solid #c3c4c7; }
	table.widefat td { padding: 10px; border-top: 1px solid #f0f0f1; vertical-align: top; }
	table.striped tbody tr:nth-child(odd) { background: #f6f7f7; }
	.notice { background: #fff; border: 1px solid #c3c4c7; border-left-width: 4px; margin: 1em 0; padding: 1px 12px; }
	.notice-error { border-left-color: #d63638; }
	.notice-warning { border-left-color: #dba617; }
	.inline { display: block; }
</style>
</head>
<body>
<?php echo $kbb_screen; // phpcs:ignore ?>
</body>
</html>
