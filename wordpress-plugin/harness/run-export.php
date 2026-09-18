<?php
/**
 * Run the plugin's export against a WordPress-shaped MySQL database.
 *
 *   php wordpress-plugin/harness/run-export.php \
 *       --storage=posts --out=/tmp/export --db=kbb_ge_wp [--batch=2]
 *
 * ── WHY THIS IS A SCRIPT AND NOT A TEST ─────────────────────────────────────
 *
 * The plugin defines global functions -- get_option(), get_permalink() and the
 * rest -- and so does WordPress. Loading those stubs into the shop's own test
 * process would put a second `get_option()` beside Laravel's world for the
 * length of the suite. A separate process has one job and cannot leak.
 *
 * tests/Feature/GeWpExporterTest.php shells out to this and then feeds what
 * comes out into this repository's real ImportRunner, which is the round trip
 * the whole lane is for.
 *
 * ── --batch IS THE RESUME TEST ──────────────────────────────────────────────
 *
 * Every batch is a separate call into the runner that reloads its state from
 * the options table, exactly as a separate HTTP request would. So running the
 * same shop at --batch=1 and at --batch=1000 exercises one row per request
 * against everything-in-one, and the two must produce byte-identical files. A
 * checkpoint that is off by one shows up there and nowhere else.
 */

$args = array();

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z_]+)=(.*)$/', $argument, $matches ) ) {
		$args[ $matches[1] ] = $matches[2];
	}
}

$storage = isset( $args['storage'] ) ? $args['storage'] : 'posts';
$out     = isset( $args['out'] ) ? $args['out'] : sys_get_temp_dir() . '/kbb-export-harness';
$batch   = isset( $args['batch'] ) ? max( 1, (int) $args['batch'] ) : 500;
$dsn     = isset( $args['dsn'] ) ? $args['dsn'] : 'mysql:host=127.0.0.1;port=3306;dbname=' . ( isset( $args['db'] ) ? $args['db'] : 'kbb_ge_wp' ) . ';charset=utf8mb4';
$user    = isset( $args['user'] ) ? $args['user'] : 'kbb';
$pass    = isset( $args['pass'] ) ? $args['pass'] : 'kbb';
$prefix  = isset( $args['prefix'] ) ? $args['prefix'] : 'wp_';

try {
	$pdo = new PDO( $dsn, $user, $pass, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
} catch ( Exception $e ) {
	fwrite( STDERR, "NO-MYSQL: " . $e->getMessage() . "\n" );
	exit( 3 );
}

// The uploads directory the stub reports, with two real files in it so
// media.csv's `exists` column is exercised in both directions: the serum's
// full size is on disk and its -300x300 variant is not, which is exactly the
// state a shop is in after somebody deletes a thumbnail cache.
$uploads = rtrim( $out, '/' ) . '/uploads';
putenv( 'KBB_HARNESS_UPLOADS=' . $uploads );

foreach ( array( '2019/03', '2020/01', '2021/05' ) as $folder ) {
	if ( ! is_dir( $uploads . '/' . $folder ) ) {
		mkdir( $uploads . '/' . $folder, 0755, true );
	}
}

file_put_contents( $uploads . '/2019/03/ginseng-serum.jpg', str_repeat( 'x', 2048 ) );
file_put_contents( $uploads . '/2020/01/boj-logo.png', str_repeat( 'y', 1024 ) );

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/shop.php';

$wpdb = new KBB_Harness_Wpdb( $pdo, $prefix );

kbb_harness_build( $pdo, $prefix, $storage );

// The plugin, loaded exactly as WordPress would load it.
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-csv.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-wp.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-media-index.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-stage.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-orders-source.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-runner.php';

foreach ( glob( __DIR__ . '/../kbb-exporter/includes/stages/*.php' ) as $file ) {
	require $file;
}

$settings = array(
	'batch'        => $batch,
	'skip_trashed' => ! isset( $args['include_trashed'] ) || '1' !== $args['include_trashed'],
);

$runner = new KBB_Export_Runner( $settings );
$runner->reset();

$runner = new KBB_Export_Runner( $settings );
$result = $runner->start();

if ( ! $result['ok'] ) {
	fwrite( STDERR, 'REFUSED: ' . $result['error'] . "\n" );
	exit( 4 );
}

$steps = 0;

/*
 * --flip-after=N changes `skip_trashed` after N batches, which is what an
 * operator does by ticking the box on the admin screen halfway through: the
 * form is posted with EVERY batch, so a change lands on the next one.
 *
 * The export must come out the same as an unflipped run. Without the runner
 * pinning its settings at start(), the remaining batches would select a
 * different set of rows and the file would hold the first half under one rule
 * and the rest under another, with nothing in it to say where the line is.
 */
$flip_after = isset( $args['flip_after'] ) ? max( 1, (int) $args['flip_after'] ) : 0;

do {
	// A FRESH RUNNER PER BATCH. This is the whole point of the harness being
	// a loop rather than a method: each iteration reloads the checkpoint from
	// the options table the way a new HTTP request would, so a stage that kept
	// anything in a property between batches is caught here rather than on the
	// owner's server at row 3,000.
	if ( $flip_after > 0 && $steps >= $flip_after ) {
		$settings['skip_trashed'] = ! $settings['skip_trashed'];
	}

	$runner   = new KBB_Export_Runner( $settings );
	$progress = $runner->step();

	if ( empty( $progress['ok'] ) ) {
		fwrite( STDERR, 'FAILED: ' . $progress['error'] . "\n" );
		exit( 5 );
	}

	$steps++;
} while ( empty( $progress['done'] ) && $steps < 20000 );

if ( empty( $progress['done'] ) ) {
	fwrite( STDERR, "FAILED: the export did not finish in 20,000 batches.\n" );
	exit( 6 );
}

// The runner writes into uploads/kbb-export/<export id>/; move it to a stable
// place so the test does not have to go looking for a random directory.
$target = rtrim( $out, '/' ) . '/export';

if ( is_dir( $target ) ) {
	foreach ( glob( $target . '/*' ) as $file ) {
		unlink( $file );
	}
} else {
	mkdir( $target, 0755, true );
}

foreach ( glob( $progress['dir'] . '/*' ) as $file ) {
	copy( $file, $target . '/' . basename( $file ) );
}

echo json_encode(
	array(
		'dir'       => $target,
		'storage'   => $storage,
		'steps'     => $steps,
		'export_id' => $progress['export_id'],
		'rows'      => $progress['rows_done'],
		'notes'     => $progress['notes'],
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
