<?php
/**
 * One CSV file, written a batch at a time and reopened between requests.
 *
 * APPEND, ALWAYS. A resumable export cannot hold a file handle across an HTTP
 * request, so every batch reopens the file in 'a' mode and the header is
 * written only when the file does not yet exist. Truncating on open would make
 * a resumed export a file containing only its last batch -- which is the
 * failure the whole checkpoint design exists to prevent, arriving through the
 * writer instead of through the runner.
 *
 * THE ROW COUNT IS COUNTED, NOT DERIVED. manifest.json's `rows` excludes the
 * header (the contract says so in as many words), and this class is the only
 * thing that knows how many data rows it actually wrote. Recomputing it later
 * by reading the file back would be a second implementation of CSV parsing,
 * and the two would eventually disagree about an embedded newline.
 *
 * NO fputcsv(). PHP's fputcsv changed its escaping behaviour in 7.4 and again
 * in 8.1 (the `$escape` parameter is deprecated in 8.1+ and the default
 * backslash escape corrupts a value ending in a backslash on the way back
 * through str_getcsv). This shop's reader is App\Services\Import\Sources\
 * CsvRowSource; the writer below emits plain RFC 4180 -- quote every field,
 * double an embedded quote, nothing else -- which every reader agrees about.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Csv {

	/** @var string */
	private $path;

	/** @var array<int,string> */
	private $columns;

	public function __construct( $path, array $columns ) {
		$this->path    = $path;
		$this->columns = $columns;
	}

	public function path() {
		return $this->path;
	}

	/** @return array<int,string> */
	public function columns() {
		return $this->columns;
	}

	public function exists() {
		return file_exists( $this->path );
	}

	/**
	 * Create the file with its header row, if it is not there yet.
	 *
	 * Called for EVERY file at the start of an export, including the ones that
	 * will turn out to have no rows: the contract says a file with no rows is
	 * still listed with "rows": 0, and that "absent from files" is a different
	 * statement -- "this shop has no coupons" against "this export does not
	 * carry coupons". A header-only file is how the first of those is said.
	 */
	public function open() {
		if ( $this->exists() ) {
			return;
		}

		$dir = dirname( $this->path );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		file_put_contents( $this->path, $this->line( $this->columns ) );
	}

	/**
	 * Append rows. Anything the row does not name is written as an empty cell,
	 * and a key the columns do not name is DROPPED -- silently on purpose: a
	 * stage that returns a stray key would otherwise shift every later column
	 * of that row by one, which is the kind of corruption that reads as a data
	 * bug for a week.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return int rows written
	 */
	public function append( array $rows ) {
		if ( empty( $rows ) ) {
			return 0;
		}

		$out = '';

		foreach ( $rows as $row ) {
			$cells = array();

			foreach ( $this->columns as $column ) {
				$cells[] = isset( $row[ $column ] ) ? $row[ $column ] : '';
			}

			$out .= $this->line( $cells );
		}

		file_put_contents( $this->path, $out, FILE_APPEND );

		return count( $rows );
	}

	/**
	 * One RFC 4180 record.
	 *
	 * Every field is quoted rather than only the ones that need it. It costs
	 * two bytes a cell and it removes the entire class of "this value did not
	 * need quoting until the day somebody put a comma in a product name".
	 */
	private function line( array $cells ) {
		$parts = array();

		foreach ( $cells as $cell ) {
			if ( is_bool( $cell ) ) {
				$cell = $cell ? 'yes' : 'no';
			}

			if ( null === $cell ) {
				$cell = '';
			}

			$parts[] = '"' . str_replace( '"', '""', (string) $cell ) . '"';
		}

		return implode( ',', $parts ) . "\n";
	}
}
