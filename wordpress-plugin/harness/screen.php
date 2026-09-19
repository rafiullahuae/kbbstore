<?php
/**
 * Render Tools -> KBB Export as a standalone HTML page.
 *
 *   php wordpress-plugin/harness/screen.php --db=kbb_ge_wp > /tmp/screen.html
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Everything this lane added to the screen -- the group ticks, the dependency
 * warnings, the per-group bars -- is JAVASCRIPT, and the PHP suite cannot see a
 * line of it. Measured rather than assumed: deleting `body.set('groups', ...)`
 * from the screen, so that every export is a whole export whatever is ticked,
 * left the whole suite GREEN. See docs/GK-EXPORT-GROUPS.md.
 *
 * So the screen is rendered here with the same WordPress stubs the export
 * harness uses, and driven in a real browser by screen-drive.mjs beside it.
 * Nothing about the page is reconstructed: KBB_Export_Admin::screen() writes it,
 * and KBB_Export_Groups declares the groups it draws.
 *
 * The admin-ajax endpoint is not stubbed here. The browser script intercepts
 * fetch() and answers it, because what has to be proved is what the page SENDS
 * and what it does with what comes back -- and a fake server that answers
 * whatever it likes is the only way to put the page into "mid-run" and
 * "stalled" without a WordPress.
 */

$args = array();

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z_]+)=(.*)$/', $argument, $matches ) ) {
		$args[ $matches[1] ] = $matches[2];
	}
}

$dsn    = isset( $args['dsn'] ) ? $args['dsn'] : 'mysql:host=127.0.0.1;port=3306;dbname=' . ( isset( $args['db'] ) ? $args['db'] : 'kbb_ge_wp' ) . ';charset=utf8mb4';
$user   = isset( $args['user'] ) ? $args['user'] : 'kbb';
$pass   = isset( $args['pass'] ) ? $args['pass'] : 'kbb';
$prefix = isset( $args['prefix'] ) ? $args['prefix'] : 'wp_';

try {
	$pdo = new PDO( $dsn, $user, $pass, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
} catch ( Exception $e ) {
	fwrite( STDERR, "NO-MYSQL: " . $e->getMessage() . "\n" );
	exit( 3 );
}

putenv( 'KBB_HARNESS_UPLOADS=' . sys_get_temp_dir() . '/kbb-screen-uploads' );

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/shop.php';

$wpdb = new KBB_Harness_Wpdb( $pdo, $prefix );

kbb_harness_build( $pdo, $prefix, isset( $args['storage'] ) ? $args['storage'] : 'posts' );

/*
 * The handful of admin-side WordPress functions the screen calls that the
 * export harness never needed. Each is what WordPress does, reduced to what the
 * page uses: escaping that really escapes, a nonce that is a string, and
 * capability checks that pass because the screen is only ever rendered for
 * somebody who has already got past add_management_page().
 */
function current_user_can( $capability ) { // phpcs:ignore
	return true;
}

function wp_die( $message ) { // phpcs:ignore
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_json_encode( $value ) {
	return json_encode( $value, JSON_UNESCAPED_SLASHES );
}

function wp_create_nonce( $action ) {
	return 'harness-nonce-' . md5( (string) $action );
}

function admin_url( $path = '' ) {
	return 'https://kbeautybliss.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function disabled( $condition, $value = true, $echo = true ) {
	$out = ( $condition == $value ) ? ' disabled="disabled"' : ''; // phpcs:ignore

	if ( $echo ) {
		echo $out; // phpcs:ignore
	}

	return $out;
}

function add_action( $hook, $callback ) { // phpcs:ignore
}

function add_management_page( $page_title, $menu_title, $capability, $slug, $callback ) { // phpcs:ignore
}

require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-csv.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-wp.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-media-index.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-groups.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-stage.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-orders-source.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-runner.php';

foreach ( glob( __DIR__ . '/../kbb-exporter/includes/stages/*.php' ) as $file ) {
	require $file;
}

require __DIR__ . '/../kbb-exporter/admin/class-kbb-export-admin.php';

// A fresh screen: no half-finished export, so Resume is disabled the way it is
// on a shop that has never run one.
if ( ! isset( $args['keep_state'] ) ) {
	delete_option( KBB_Export_Runner::STATE_OPTION );
}

/*
 * ── WHAT THE SERVER MAKES OF A REQUEST, probed directly ─────────────────────
 *
 * settings_from_request() decides what a POST means, and the case that matters
 * is the one the screen never produces: a request with NO `groups` field at
 * all. An older browser tab, a bookmarked POST, anything predating this screen.
 * That has to mean "the whole export the plugin has always produced" and not
 * "nothing" -- and the difference is one line that no browser test can reach,
 * because the browser always sends the field.
 *
 * Reflection rather than making the method public: its visibility is a real
 * statement about the plugin's surface and a test is not a reason to widen it.
 */
if ( isset( $args['probe'] ) && 'settings' === $args['probe'] ) {
	$read = function ( array $post ) {
		$_POST  = $post;
		$method = new ReflectionMethod( 'KBB_Export_Admin', 'settings_from_request' );

		$method->setAccessible( true );

		$settings = $method->invoke( null );

		/*
		 * AND WHAT THE RUNNER MAKES OF IT. settings_from_request() cleans the
		 * characters; the runner's constructor is what drops a key no group
		 * answers to, and that is the one that decides which files are opened.
		 * Both are reported, because "the request was sanitised" and "the
		 * export ran on real groups" are different claims.
		 */
		$settings['runner_groups'] = ( new KBB_Export_Runner( $settings ) )->settings()['groups'];

		return $settings;
	};

	echo json_encode(
		array(
			'no_groups_field' => $read( array( 'batch' => '200' ) ),
			'empty_groups'    => $read( array( 'batch' => '200', 'groups' => '' ) ),
			'junk_groups'     => $read(
				array(
					'batch'     => '200',
					'groups'    => 'catalogue, SALES ,<script>,not_a_group,catalogue',
					'confirmed' => 'sales:customers,  ,bad!value',
				)
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "
";

	exit( 0 );
}

ob_start();
KBB_Export_Admin::screen();
$screen = ob_get_clean();

/*
 * wp-admin's own stylesheet is not here and is not needed: the page is being
 * looked at for its STRUCTURE -- which boxes exist, which warnings appear, which
 * button is disabled. These are the half-dozen wp-admin rules the markup
 * actually leans on, so a screenshot is readable rather than pretty.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>KBB Export &lsaquo; kbeautybliss &#8212; WordPress</title>
<style>
	body { margin: 0; background: #f0f0f1; color: #3c434a; font: 13px/1.6 -apple-system, "Segoe UI", Roboto, sans-serif; }
	.wrap { max-width: 1100px; margin: 0; padding: 10px 20px 40px; }
	h1 { font-size: 23px; font-weight: 400; margin: .5em 0 1em; }
	h2 { font-size: 15px; margin: 1.6em 0 .6em; }
	p { margin: .8em 0; }
	code { background: rgba(0,0,0,.07); padding: 1px 4px; border-radius: 2px; font-size: 12px; }
	.description { color: #646970; font-size: 13px; font-style: italic; }
	.button { display: inline-block; background: #f6f7f7; border: 1px solid #2271b1; color: #2271b1;
		border-radius: 3px; padding: 4px 12px; font-size: 13px; cursor: pointer; }
	.button-primary { background: #2271b1; border-color: #2271b1; color: #fff; }
	.button[disabled] { background: #f6f7f7; border-color: #dcdcde; color: #a7aaad; cursor: default; }
	table.widefat { border-collapse: collapse; width: 100%; background: #fff;
		border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
	table.widefat td { padding: 10px; border-top: 1px solid #f0f0f1; vertical-align: top; }
	table.striped tbody tr:nth-child(odd) { background: #f6f7f7; }
	.notice { background: #fff; border: 1px solid #c3c4c7; border-left-width: 4px; margin: 1em 0; padding: 1px 12px; }
	.notice-error { border-left-color: #d63638; }
	.notice-warning { border-left-color: #dba617; }
	.notice-info { border-left-color: #72aee6; }
	.inline { display: block; }
</style>
</head>
<body>
<?php echo $screen; // phpcs:ignore ?>
</body>
</html>
