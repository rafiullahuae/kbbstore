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
