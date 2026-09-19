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
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-groups.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-stage.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-orders-source.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-runner.php';

foreach ( glob( __DIR__ . '/../kbb-exporter/includes/stages/*.php' ) as $file ) {
	require $file;
}

/*
 * --groups=catalogue,customers  exports only those groups, exactly as ticking
 * their boxes on the admin screen does. Omitted means every group, which is
 * what the screen offers by default and what every test written before this
 * lane expects.
 *
 * --confirm=sales:customers     is the operator ticking "Customers is already
 * imported into the new shop" against that dependency. Without it a selection
 * with an unmet dependency is REFUSED by start(), which is the guard this
 * harness exists to exercise from outside the browser: the admin screen only
 * disables a button, and a disabled button is a statement about one browser.
 */
$settings = array(
	'batch'        => $batch,
	'skip_trashed' => ! isset( $args['include_trashed'] ) || '1' !== $args['include_trashed'],
	// --groups absent means every group, which is what the screen offers by
	// default. --groups= (empty) is an EMPTY selection and has to stay tellable
	// from absent, because "export nothing" is a thing the runner refuses and a
	// refusal that cannot be reached is not a refusal.
	'groups'       => isset( $args['groups'] )
		? explode( ',', $args['groups'] )
		: KBB_Export_Groups::keys(),
	'confirmed'    => isset( $args['confirm'] ) && '' !== $args['confirm']
		? explode( ',', $args['confirm'] )
		: array(),
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

$peak_while_running = 0;

/*
 * The highest percentage EACH GROUP's own bar showed while the export was still
 * running. 100 on a group that was not finished is the same fake 100% the
 * whole-export bar already had removed once, reintroduced one bar down.
 */
$group_peaks = array();

do {
	// A FRESH RUNNER PER BATCH. This is the whole point of the harness being
	// a loop rather than a method: each iteration reloads the checkpoint from
	// the options table the way a new HTTP request would, so a stage that kept
	// anything in a property between batches is caught here rather than on the
	// owner's server at row 3,000.
	if ( $flip_after > 0 && $steps >= $flip_after ) {
		$settings['skip_trashed'] = ! $settings['skip_trashed'];
	}

	/*
	 * --flip_groups_after=N un-ticks a group mid-export, which is what an
	 * operator does by clicking a checkbox while it runs: the form is posted
	 * with EVERY batch, so the change lands on the next one.
	 *
	 * `stage` is an INDEX INTO THE FILTERED STAGE LIST. Shortening that list
	 * between two batches does not stop the export -- it carries on at the same
	 * index, which now points at a different file, and finishes early with one
	 * file half written and another never opened, while the manifest describes
	 * neither. Pinning `groups` at start() is what makes this a no-op, and this
	 * flag is how that is measured rather than asserted.
	 */
	if ( isset( $args['flip_groups_after'] ) && $steps >= max( 1, (int) $args['flip_groups_after'] ) ) {
		$settings['groups'] = array( 'catalogue' );
	}

	$runner   = new KBB_Export_Runner( $settings );
	$progress = $runner->step();

	if ( empty( $progress['ok'] ) ) {
		fwrite( STDERR, 'FAILED: ' . $progress['error'] . "\n" );
		exit( 5 );
	}

	// The highest percentage the screen would have shown while the export was
	// STILL RUNNING. 100 here means the bar claimed to be finished and then
	// carried on, which is the fake 100% docs/GD-MEDIA-SIDELOADER.md removed
	// once already and which this export reintroduced through its denominator.
	if ( empty( $progress['done'] ) ) {
		$peak_while_running = max( $peak_while_running, (int) $progress['percent'] );

		foreach ( isset( $progress['groups'] ) ? $progress['groups'] : array() as $group ) {
			if ( 'done' === $group['state'] ) {
				continue;
			}

			$key = $group['key'];

			$group_peaks[ $key ] = isset( $group_peaks[ $key ] )
				? max( $group_peaks[ $key ], (int) $group['percent'] )
				: (int) $group['percent'];
		}
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

/*
 * ── PROBING THE PROGRESS ARITHMETIC DIRECTLY ────────────────────────────────
 *
 * The guard being checked is "the bar never says 100% before the export is
 * done", and the case that breaks it is a stage writing more rows than its
 * total() predicted -- which the media stage really does, about five to one.
 *
 * End to end on this fixture that is invisible: `percent` is computed over the
 * SUM of every stage's rows and every stage's total, and media's eight rows
 * against twelve do not move a sum that includes fifty other rows. On a real
 * shop with four gallery images per product it does, which is exactly the shape
 * a fixture cannot have without being contorted into one.
 *
 * So the arithmetic is exercised on its own, through the real method, with a
 * state that has already crossed: written past total, done still false. Nothing
 * here is a stub of the thing being tested -- progress() is the shipped method,
 * reading the shipped state.
 */
if ( isset( $args['probe'] ) && 'progress' === $args['probe'] ) {
	$state = get_option( KBB_Export_Runner::STATE_OPTION, array() );

	$state['done']    = false;
	$state['totals']  = array( 'media.csv' => 10 );
	$state['written'] = array( 'media.csv' => 47 );

	update_option( KBB_Export_Runner::STATE_OPTION, $state );

	$probe = ( new KBB_Export_Runner( $settings ) )->progress();

	echo json_encode(
		array(
			'probe'      => 'progress',
			'percent'    => $probe['percent'],
			'rows_done'  => $probe['rows_done'],
			'rows_total' => $probe['rows_total'],
			'done'       => $probe['done'],
		),
		JSON_PRETTY_PRINT
	) . "\n";

	exit( 0 );
}

echo json_encode(
	array(
		'dir'       => $target,
		'storage'   => $storage,
		'steps'     => $steps,
		'export_id' => $progress['export_id'],
		'rows'      => $progress['rows_done'],
		'peak_while_running' => $peak_while_running,
		'group_progress' => isset( $progress['groups'] ) ? $progress['groups'] : array(),
		'group_peaks'    => $group_peaks,
		'notes'     => $progress['notes'],
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
