<?php
/**
 * The group and dependency logic, answered without a database.
 *
 *   php wordpress-plugin/harness/groups.php
 *   php wordpress-plugin/harness/groups.php --selection=sales --confirm=sales:customers
 *
 * ── WHY THIS IS A SECOND SCRIPT AND NOT A FLAG ON run-export.php ────────────
 *
 * run-export.php opens a MySQL connection as its first act and exits 3 when
 * there is none, because everything it does needs WordPress-shaped tables.
 * KBB_Export_Groups needs no tables at all: it is a declaration of which files
 * belong together and which of them point at which. Asking it a question
 * through a script that cannot answer without MySQL would make the dependency
 * guard -- THE thing this lane is for -- a test that skips in CI, which is
 * where it most needs to run.
 *
 * ── AND STILL A SEPARATE PROCESS ────────────────────────────────────────────
 *
 * For the reason run-export.php gives: the plugin is WordPress code and defines
 * `defined('ABSPATH') || exit;` at the top of every file. Loading it into the
 * shop's test process would put a WordPress-shaped world beside Laravel's for
 * the length of the suite. Defining one constant here is the whole of what this
 * needs, and it dies with the process.
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-groups.php';

require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-zip.php';

/*
 * KBB_Export_Zip reaches for one constant on the runner. Requiring the runner
 * itself here would drag in the stages and, through them, $wpdb -- which is the
 * MySQL this script exists to do without.
 */
if ( ! class_exists( 'KBB_Export_Runner' ) ) {
	class KBB_Export_Runner { // phpcs:ignore
		const FORMAT = 'kbb-export/1';
	}
}

$args = array();

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z_]+)=(.*)$/', $argument, $matches ) ) {
		$args[ $matches[1] ] = $matches[2];
	}
}

$list = function ( $key ) use ( $args ) {
	if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) {
		return array();
	}

	return explode( ',', $args[ $key ] );
};

$selection = isset( $args['selection'] ) ? $list( 'selection' ) : KBB_Export_Groups::keys();
$confirmed = $list( 'confirm' );

/*
 * ── THE SPLITTER, REACHED WITHOUT A 25 MB FIXTURE ───────────────────────────
 *
 *   php wordpress-plugin/harness/groups.php --zip_probe=1 --bytes=orders.csv:30000000
 *
 * KBB_Export_Zip::parts_for() decides whether a group is written in numbered
 * parts, and the real shop does not reach that decision -- docs/GL-GROUP-DOWNLOADS.md
 * measures the largest group at 7.98 MB of CSV against a 25 MiB cap. A guard
 * nothing can reach is a guard nothing checks, and "it never fires here" is
 * exactly how it would come to be broken by the time it does.
 *
 * So the file SIZES are handed in directly. parts_for() reads nothing but the
 * manifest's `bytes`, so a manifest asserting that orders.csv is 30 MB is the
 * whole of what a 30 MB orders.csv would give it -- without a fixture nobody
 * wants to generate, and without MySQL, so it runs in CI.
 *
 * `--bytes=seo.csv:-` REMOVES a file from the manifest, which is what a group
 * that was never ticked looks like: absent from `files` rather than present
 * with zero rows. That group must get no archive at all.
 */
if ( isset( $args['zip_probe'] ) ) {
	$files = array();

	foreach ( KBB_Export_Groups::every_file() as $file ) {
		$files[ $file ] = array( 'rows' => 10, 'bytes' => 1024, 'sha256' => str_repeat( 'a', 64 ) );
	}

	foreach ( $list( 'bytes' ) as $pair ) {
		$pieces = explode( ':', $pair );

		if ( 2 === count( $pieces ) ) {
			if ( '-' === $pieces[1] ) {
				unset( $files[ $pieces[0] ] );
			} else {
				$files[ $pieces[0] ]['bytes'] = (int) $pieces[1];
			}
		}
	}

	$manifest = array(
		'format'    => 'kbb-export/1',
		'export_id' => isset( $args['export_id'] ) ? $args['export_id'] : 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
		'files'     => $files,
		'counts'    => array(),
		'groups'    => array( 'selected' => KBB_Export_Groups::normalise( $selection ) ),
		'notes'     => array(),
	);

	$manifests = array();

	/*
	 * A MANIFEST PER PART, keyed "<group>:<part>", not one per group.
	 *
	 * Found by mutation (M8, docs/GL-GROUP-DOWNLOADS.md §9): making
	 * files_in_part() ignore the part it was asked for and always answer part 1
	 * left the whole suite GREEN. Nothing on this shop splits, so every group
	 * has exactly one part and $parts[0] and $parts[$index] are the same list --
	 * and the splitter test read plan(), which computes its parts separately.
	 *
	 * The consequence of that mutation is the worst thing an archive can be: a
	 * part 2 whose manifest describes part 1's files. So every part's manifest
	 * is reported and the test compares each against that part's own plan.
	 */
	$counted = array();

	foreach ( KBB_Export_Zip::plan( $manifest ) as $unit ) {
		$counted[ $unit['group'] ][ $unit['part'] ] = (int) $unit['parts'];
	}

	foreach ( $counted as $key => $parts ) {
		foreach ( $parts as $part => $total ) {
			$in_part = KBB_Export_Zip::files_in_part( $manifest, $key, $part );

			$manifests[ $key . ':' . $part ] = KBB_Export_Zip::group_manifest(
				$manifest,
				$key,
				$in_part,
				$part,
				$total
			);
		}
	}

	echo json_encode(
		array(
			'cap'       => KBB_Export_Zip::PART_CAP_BYTES,
			'available' => KBB_Export_Zip::available(),
			'plan'      => KBB_Export_Zip::plan( $manifest ),
			'names'     => array(
				'single' => KBB_Export_Zip::zip_name( 'sales', $manifest['export_id'], 1, 1 ),
				'part'   => KBB_Export_Zip::zip_name( 'sales', $manifest['export_id'], 2, 3 ),
			),
			'manifests' => $manifests,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";

	exit( 0 );
}

echo json_encode(
	array(
		'keys'        => KBB_Export_Groups::keys(),
		'every_file'  => KBB_Export_Groups::every_file(),
		'groups'      => KBB_Export_Groups::all(),
		'selection'   => KBB_Export_Groups::normalise( $selection ),
		'files'       => KBB_Export_Groups::files_for( $selection ),
		'unmet'       => KBB_Export_Groups::unmet( $selection ),
		'outstanding' => KBB_Export_Groups::outstanding( $selection, $confirmed ),
		'refusal'     => KBB_Export_Groups::refusal( KBB_Export_Groups::outstanding( $selection, $confirmed ) ),
		'manifest'    => KBB_Export_Groups::manifest_block( $selection, $confirmed ),
		'notes'       => KBB_Export_Groups::notes( $selection, $confirmed ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
