<?php
/**
 * What each group's zip actually weighs at the real shop's volume.
 *
 *   php wordpress-plugin/harness/volume.php
 *   php wordpress-plugin/harness/volume.php --description=6000 --json
 *
 * ── WHY THIS EXISTS RATHER THAN AN ESTIMATE ─────────────────────────────────
 *
 * The owner's requirement is "so will have no any heavy file". That is a claim
 * about SIZE, and the only honest way to decide whether a group needs splitting
 * into numbered parts is to weigh it -- at the real shop's volume, not at the
 * fixture's. tests/Fixtures/kbb-export is five products and three orders; the
 * shop is 671 products, 4,159 orders, 10,571 line items, 3,712 customers and
 * 2,514 reviews, and the ratio between those two is four orders of magnitude.
 *
 * Building a splitter for a file that turns out to be 400 KB would be building
 * something nothing uses; NOT building one for a file that turns out to be
 * 40 MB would be handing the owner exactly the heavy file he asked not to have.
 * So: measure, then decide, and write the figures down.
 * docs/GL-GROUP-DOWNLOADS.md section 3 is the table this prints.
 *
 * ── WHAT IS REAL HERE AND WHAT IS SYNTHESISED ───────────────────────────────
 *
 * REAL: the column set of every file (read out of tests/Fixtures/kbb-export, so
 * they are the plugin's own headers and not a guess at them), the row counts,
 * the grouping, the plan, and the compression -- the archives are built by
 * KBB_Export_Zip itself, the shipped class, not by a zip command.
 *
 * SYNTHESISED: the cell contents, because 671 real products are not in this
 * repository and cannot be. This matters for one reason and it is the reason
 * the generator is careful: ZIP SIZE IS A FUNCTION OF ENTROPY, and a generator
 * that writes str_repeat('x', 3000) into every description would compress to
 * nothing and report a flattering figure that means nothing. So text cells are
 * built from a word pool with realistic HTML around them, ids and prices and
 * dates vary per row the way real ones do, emails and password hashes are
 * high-entropy the way real ones are, and repeated values (statuses, countries,
 * currencies) repeat the way real ones do.
 *
 * The number that is genuinely uncertain is how long a product description is,
 * because that is the largest single contributor and it is a property of how
 * the owner writes. It is therefore a FLAG -- --description=N -- and
 * docs/GL-GROUP-DOWNLOADS.md reports the range rather than one number.
 */

$args = array();

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( preg_match( '/^--([a-z_]+)(?:=(.*))?$/', $argument, $matches ) ) {
		$args[ $matches[1] ] = isset( $matches[2] ) ? $matches[2] : '1';
	}
}

/*
 * BEFORE the requires, not after. Every plugin file opens with
 * `defined( 'ABSPATH' ) || exit;` -- a direct-access guard that is correct in
 * WordPress and, if the constant is defined a line too late, silently exits
 * this script with status 0 and no output. Which it did.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-groups.php';
require __DIR__ . '/../kbb-exporter/includes/class-kbb-export-zip.php';

/** KBB_Export_Zip::group_manifest() reaches for the format constant. */
if ( ! class_exists( 'KBB_Export_Runner' ) ) {
	class KBB_Export_Runner { // phpcs:ignore
		const FORMAT = 'kbb-export/1';
	}
}

/*
 * THE REAL SHOP, as the owner stated it. Everything else is derived: a file
 * nobody gave a figure for is scaled from the fixture's own ratio to one of
 * these, which is stated per file below rather than hidden in a constant.
 */
$volume = array(
	'categories.csv'  => 48,     // a catalogue this size has dozens, not hundreds
	'brands.csv'      => 37,
	'tags.csv'        => 120,
	'attributes.csv'  => 260,    // attribute terms, not attributes: sizes, shades
	'products.csv'    => 671,    // stated
	'variations.csv'  => 1180,   // ~1.8 per product, which is a shade/size shop
	'seo.csv'         => 720,    // a Yoast row per product plus the pages
	'coupons.csv'     => 64,
	'customers.csv'   => 3712,   // stated
	'orders.csv'      => 4159,   // stated
	'order_items.csv' => 10571,  // stated
	'refunds.csv'     => 190,
	'order_notes.csv' => 9400,   // WooCommerce writes several per order
	'reviews.csv'     => 2514,   // stated
	'posts.csv'       => 140,    // the Journal
	'permalinks.csv'  => 1700,   // every product, category, brand, tag and post
	'media.csv'       => 4800,   // ~6 referenced pictures per product
);

$description_bytes = isset( $args['description'] ) ? max( 0, (int) $args['description'] ) : 3000;
$fixture           = dirname( __DIR__, 2 ) . '/tests/Fixtures/kbb-export';
$dir               = rtrim( isset( $args['out'] ) ? $args['out'] : sys_get_temp_dir() . '/kbb-volume-' . getmypid(), '/' );

if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}

foreach ( glob( $dir . '/*' ) as $stale ) {
	unlink( $stale );
}

/*
 * A word pool with the statistics of English prose rather than of a random
 * number generator. Deflate's window finds repeated phrases in real copy, and a
 * pool this size reproduces that; /dev/urandom would not compress at all and
 * would report a figure twice too large, which is the opposite error but still
 * an error.
 */
$words = explode(
	' ',
	'serum essence toner ampoule cleanser hydrating brightening ginseng propolis snail mucin '
	. 'centella rice water niacinamide vitamin barrier repair soothing gentle daily routine morning '
	. 'evening skin texture glow radiance moisture plump smooth pores fine lines dullness sensitive '
	. 'combination oily dry korean beauty formula extract ferment complex peptide ceramide hyaluronic '
	. 'acid squalane panthenol allantoin tea tree mugwort artemisia bakuchiol retinal azelaic'
);

mt_srand( 20260919 );

function kbb_words( $count ) {
	global $words;

	$out = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$out[] = $words[ mt_rand( 0, count( $words ) - 1 ) ];
	}

	return implode( ' ', $out );
}

/** Product-description-shaped HTML of about $bytes bytes. */
function kbb_html( $bytes ) {
	if ( $bytes <= 0 ) {
		return '';
	}

	$out = '';

	while ( strlen( $out ) < $bytes ) {
		$out .= '<p>' . ucfirst( kbb_words( mt_rand( 18, 34 ) ) ) . '.</p>'
			. '<ul><li>' . kbb_words( 6 ) . '</li><li>' . kbb_words( 5 ) . '</li></ul>';
	}

	return substr( $out, 0, $bytes );
}

/**
 * A cell for a column, shaped like what that column really holds.
 *
 * Keyed on the column NAME, because the same name means the same kind of thing
 * in every file the plugin writes -- that is what makes the column set a
 * contract rather than seventeen unrelated headers.
 */
function kbb_cell( $column, $row, $description_bytes ) {
	$slugish = str_replace( ' ', '-', kbb_words( 3 ) );

	switch ( true ) {
		case 'description' === $column:
			return kbb_html( $description_bytes );

		case 'short_description' === $column:
			return kbb_html( (int) ( $description_bytes / 8 ) );

		case 'content' === $column:
			// A review body, or a Journal article. Both are prose.
			return kbb_words( mt_rand( 20, 70 ) );

		case 'password_hash' === $column:
			// bcrypt: 60 characters of base64, and genuinely incompressible.
			return '$2y$10$' . substr( str_shuffle( str_repeat( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789./', 2 ) ), 0, 53 );

		case 'email' === $column || false !== strpos( $column, '_email' ):
			return kbb_words( 1 ) . $row . '.' . kbb_words( 1 ) . '@' . kbb_words( 1 ) . '.com';

		case 'ip' === $column:
			return mt_rand( 2, 223 ) . '.' . mt_rand( 0, 255 ) . '.' . mt_rand( 0, 255 ) . '.' . mt_rand( 1, 254 );

		case 'sku' === $column:
			return strtoupper( substr( kbb_words( 1 ), 0, 3 ) ) . '-' . ( 1000 + $row ) . '-' . mt_rand( 10, 99 );

		case 'slug' === $column:
			return $slugish . '-' . $row;

		case false !== strpos( $column, 'url' ) || 'image' === $column || 'permalink' === $column:
			return 'https://kbeautybliss.com/wp-content/uploads/20' . mt_rand( 18, 25 ) . '/'
				. str_pad( (string) mt_rand( 1, 12 ), 2, '0', STR_PAD_LEFT ) . '/' . $slugish . '-' . $row . '.jpg';

		case 'images' === $column:
			$out = array();

			for ( $i = 0; $i < 5; $i++ ) {
				$out[] = 'https://kbeautybliss.com/wp-content/uploads/2021/05/' . $slugish . '-' . $row . '-' . $i . '.jpg';
			}

			return implode( ',', $out );

		case false !== strpos( $column, 'date' ) || 'registered' === $column:
			return sprintf(
				'20%02d-%02d-%02d %02d:%02d:%02d',
				mt_rand( 18, 26 ),
				mt_rand( 1, 12 ),
				mt_rand( 1, 28 ),
				mt_rand( 0, 23 ),
				mt_rand( 0, 59 ),
				mt_rand( 0, 59 )
			);

		case false !== strpos( $column, 'total' ) || false !== strpos( $column, 'price' ) || 'subtotal' === $column:
			return number_format( mt_rand( 300, 90000 ) / 100, 2, '.', '' );

		case false !== strpos( $column, '_id' ) || 'id' === $column:
			return (string) ( 1000 + $row );

		case false !== strpos( $column, 'country' ):
			return 'AE';

		case false !== strpos( $column, 'postcode' ):
			return (string) mt_rand( 10000, 99999 );

		case false !== strpos( $column, 'phone' ):
			return '+9715' . mt_rand( 10000000, 99999999 );

		case false !== strpos( $column, 'address_1' ):
			return mt_rand( 1, 400 ) . ' ' . ucfirst( kbb_words( 2 ) ) . ' Street';

		case false !== strpos( $column, 'city' ):
			return 'Dubai';

		case 'currency' === $column:
			return 'AED';

		case 'status' === $column || false !== strpos( $column, 'status' ):
			$pool = array( 'publish', 'wc-completed', 'wc-processing', 'instock' );

			return $pool[ $row % count( $pool ) ];

		case 'name' === $column || 'title' === $column || 'author' === $column:
			return ucfirst( kbb_words( mt_rand( 2, 5 ) ) );

		default:
			return kbb_words( mt_rand( 1, 3 ) );
	}
}

/*
 * The real header of every file, read out of the fixture the plugin wrote. Not
 * retyped: a measurement against columns that are not the plugin's columns is a
 * measurement of something else.
 */
$files = array();

foreach ( $volume as $file => $rows ) {
	$path = $fixture . '/' . $file;

	if ( ! file_exists( $path ) ) {
		fwrite( STDERR, "MISSING FIXTURE: {$file}\n" );
		exit( 2 );
	}

	$handle  = fopen( $path, 'rb' );
	$columns = fgetcsv( $handle );
	fclose( $handle );

	$out = fopen( $dir . '/' . $file, 'wb' );

	fputcsv( $out, $columns );

	for ( $row = 0; $row < $rows; $row++ ) {
		$line = array();

		foreach ( $columns as $column ) {
			$line[] = kbb_cell( $column, $row, 'products.csv' === $file ? $description_bytes : 0 );
		}

		fputcsv( $out, $line );
	}

	fclose( $out );

	$files[ $file ] = array(
		'rows'   => $rows,
		'bytes'  => (int) filesize( $dir . '/' . $file ),
		'sha256' => hash_file( 'sha256', $dir . '/' . $file ),
	);
}

$manifest = array(
	'format'       => 'kbb-export/1',
	'export_id'    => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
	'generated_at' => date( 'c' ),
	'source'       => array( 'site_url' => 'https://kbeautybliss.com' ),
	'files'        => $files,
	'counts'       => array(),
	'groups'       => array( 'selected' => KBB_Export_Groups::keys() ),
	'notes'        => array(),
);

file_put_contents( $dir . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT ) . "\n" );

// THE SHIPPED CLASS does the zipping, one unit at a time, exactly as the runner
// drives it. Measuring a `zip -r` would measure a different compressor.
$units   = 0;
$slowest = 0.0;

foreach ( KBB_Export_Zip::plan( $manifest ) as $unit ) {
	$before = microtime( true );
	$result = KBB_Export_Zip::add( $dir, $manifest, $unit );
	$slowest = max( $slowest, microtime( true ) - $before );

	if ( ! $result['ok'] ) {
		fwrite( STDERR, 'FAILED: ' . $result['error'] . "\n" );
		exit( 5 );
	}

	$units++;
}

$report = array(
	'description_bytes' => $description_bytes,
	'cap_bytes'         => KBB_Export_Zip::PART_CAP_BYTES,
	'units'             => $units,
	'slowest_unit_ms'   => (int) round( $slowest * 1000 ),
	'groups'            => array(),
	'folder_bytes'      => 0,
	'largest_zip_bytes' => 0,
);

foreach ( KBB_Export_Zip::status( $dir, $manifest ) as $key => $group ) {
	foreach ( $group['parts'] as $part ) {
		$raw = 0;

		foreach ( $part['files'] as $file ) {
			$raw += $files[ $file ]['bytes'];
		}

		$report['groups'][] = array(
			'group'       => $key,
			'label'        => $group['label'],
			'part'         => $part['part'] . '/' . $part['parts'],
			'files'        => implode( ' ', $part['files'] ),
			'rows'         => array_sum( array_map( static function ( $f ) use ( $files ) { return $files[ $f ]['rows']; }, $part['files'] ) ),
			'raw_bytes'    => $raw,
			'zip_bytes'    => $part['bytes'],
			'ratio'        => $raw > 0 ? round( $part['bytes'] / $raw, 3 ) : 0,
			'over_cap'     => $part['bytes'] > KBB_Export_Zip::PART_CAP_BYTES,
			'raw_over_cap' => $raw > KBB_Export_Zip::PART_CAP_BYTES,
		);

		$report['folder_bytes']      += $raw;
		$report['largest_zip_bytes']  = max( $report['largest_zip_bytes'], $part['bytes'] );
	}
}

if ( isset( $args['keep'] ) ) {
	$report['dir'] = $dir;
} else {
	foreach ( glob( $dir . '/*' ) as $file ) {
		unlink( $file );
	}

	rmdir( $dir );
}

if ( isset( $args['json'] ) ) {
	echo json_encode( $report, JSON_PRETTY_PRINT ) . "\n";
	exit( 0 );
}

$kb = static function ( $n ) {
	return $n >= 1048576 ? number_format( $n / 1048576, 2 ) . ' MB' : number_format( $n / 1024, 1 ) . ' KB';
};

printf(
	"%-26s %-10s %10s %10s %7s  %s\n",
	'GROUP',
	'PART',
	'RAW',
	'ZIP',
	'RATIO',
	'FILES'
);

foreach ( $report['groups'] as $row ) {
	printf(
		"%-26s %-10s %10s %10s %6.1f%%  %s%s\n",
		$row['label'],
		$row['part'],
		$kb( $row['raw_bytes'] ),
		$kb( $row['zip_bytes'] ),
		$row['ratio'] * 100,
		$row['files'],
		$row['over_cap'] ? '   *** OVER CAP ***' : ''
	);
}

printf(
	"\nwhole folder %s   largest zip %s   cap %s   units %d   slowest unit %d ms   descriptions %d bytes\n",
	$kb( $report['folder_bytes'] ),
	$kb( $report['largest_zip_bytes'] ),
	$kb( KBB_Export_Zip::PART_CAP_BYTES ),
	$report['units'],
	$report['slowest_unit_ms'],
	$description_bytes
);
