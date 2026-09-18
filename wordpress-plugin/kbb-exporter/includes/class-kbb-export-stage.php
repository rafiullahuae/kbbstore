<?php
/**
 * One exported file.
 *
 * A stage answers three questions and nothing else: what am I called, what
 * columns do I write, and -- given a cursor -- what is the next batch of rows
 * and where does the cursor go next. The runner owns the file handle, the
 * checkpoint, the counting and the manifest, for the same reason
 * App\Services\Import\EntityImporter's own header gives: one place where a
 * batch is written alongside its progress, so resuming is a property of the
 * system rather than something every stage has to remember.
 *
 * ── THE CURSOR IS A KEY, NOT AN OFFSET ──────────────────────────────────────
 *
 * Every batch() below is `WHERE id > :cursor ORDER BY id LIMIT :n`. That is not
 * a stylistic preference over LIMIT/OFFSET:
 *
 *  - OFFSET 40000 makes MySQL walk and discard forty thousand rows to return
 *    the next hundred, so the last batch of an order export costs four hundred
 *    times the first. On a shared host with a 110-second limit that is the
 *    difference between finishing and never finishing.
 *  - An OFFSET is also WRONG under resume. The shop keeps trading while the
 *    export runs; one order placed during it shifts every later offset by one
 *    and the export silently skips a row. A key cannot skip: `id > 10233` means
 *    the same set whatever was inserted.
 *
 * ── STAGES DO NOT FILTER FOR THE IMPORTER'S CONVENIENCE, EXCEPT TWICE ───────
 *
 * The two exceptions are the WordPress trash, and they are the importer's own
 * instruction rather than this plugin's idea. ProductImporter refuses a trashed
 * product with "empty the trash or filter the export", and CouponImporter
 * refuses a non-published coupon because `coupons` has no status column and an
 * imported draft is a live discount code. So the trash is filtered HERE, by an
 * option that defaults to on, and the count of what was held back goes into
 * manifest.json's notes -- because a row silently absent from an export is the
 * one thing worse than a row the importer refuses.
 */

defined( 'ABSPATH' ) || exit;

abstract class KBB_Export_Stage {

	/** @var array<string,mixed> */
	protected $options;

	/** @var array<int,string> Notes this stage wants in manifest.json. */
	protected $notes = array();

	public function __construct( array $options = array() ) {
		$this->options = $options;
	}

	/** The file this stage writes, e.g. 'products.csv'. */
	abstract public function file();

	/** @return array<int,string> */
	abstract public function columns();

	/**
	 * The next batch.
	 *
	 * @param int $cursor 0 on the first call, then whatever was returned.
	 * @param int $limit
	 * @return array{rows: array<int,array<string,mixed>>, cursor: int, done: bool}
	 */
	abstract public function batch( $cursor, $limit );

	/**
	 * How many rows this stage will write, asked once before it starts.
	 *
	 * THE DENOMINATOR, and the reason it is a method rather than a guess.
	 * docs/GD-MEDIA-SIDELOADER.md's live page records that the catalogue stage
	 * "cannot draw a bar" because nothing knows how many rows a CSV holds until
	 * it has been read to the end; manifest.json's `rows` is what settles that
	 * for the shop, and this is what settles it for the export's own screen.
	 * A bar with no denominator is the fake 100% that lane removed.
	 *
	 * It is allowed to be an over-estimate (a row the batch later skips), never
	 * an under-estimate: a bar that reaches 100% and keeps going is a bar
	 * nobody believes again.
	 */
	abstract public function total();

	/** @return array<int,string> */
	public function notes() {
		return $this->notes;
	}

	protected function note( $text ) {
		$this->notes[] = $text;
	}

	protected function option( $key, $default = null ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
	}

	/**
	 * A money cell, exactly as WooCommerce holds it.
	 *
	 * THE CONTRACT'S LAST RULE: "Money stays as the decimal string WooCommerce
	 * holds. The importers already convert to integer fils and are tested on
	 * it; a plugin that pre-converts would double it." So this trims and
	 * returns; it does not round, does not reformat, does not add a currency
	 * and above all does not multiply by a hundred.
	 *
	 * The one thing it does do is turn Woo's empty string into an empty cell,
	 * which App\Services\Import\Money reads as NULL -- and NULL is not zero:
	 * "a product with no sale price and a product on sale at AED 0.00 are
	 * different rows".
	 */
	protected function money( $raw ) {
		$value = trim( (string) $raw );

		if ( '' === $value ) {
			return '';
		}

		/*
		 * ── THE ONE NORMALISATION, AND IT CANNOT CHANGE A VALUE ─────────────
		 *
		 * MEASURED, not anticipated. Exporting the same shop from each storage
		 * and diffing the two files: HPOS keeps money in `DECIMAL(26,8)`
		 * columns, so MySQL hands back `358.50000000` and `0.00000000`, while
		 * the legacy storage hands back the postmeta STRING, `358.50` and
		 * `0.00`. Same shop, same order, different bytes -- which would make
		 * "both storages are supported" unverifiable, because the two exports
		 * could never be compared.
		 *
		 * So trailing ZEROS past the second decimal are dropped. Nothing else
		 * is: `99.12345678` -- which an 8-decimal column really can hold, and a
		 * currency-conversion plugin really does write -- is passed through
		 * untouched so that App\Services\Import\Money REFUSES it, by name,
		 * with the value quoted. That refusal is correct and this must not
		 * quietly round it away: "a rounding rule applied silently to 40,000
		 * line items is a number the owner cannot reconcile against WooCommerce
		 * afterwards."
		 *
		 * This is not the pre-conversion the contract forbids. The cell is
		 * still the decimal string WooCommerce holds, to the fil; what comes
		 * off is padding MySQL added on the way out.
		 */
		if ( preg_match( '/^([+-]?\d+\.\d{2})0+$/', $value, $matches ) ) {
			return $matches[1];
		}

		return $value;
	}

	/**
	 * A WooCommerce date as `Y-m-d H:i:s`, which is one of DateParser::FORMATS.
	 *
	 * Woo stores `_sale_price_dates_from` and friends as UNIX timestamps and
	 * post_date as a local datetime string. DateParser accepts a 9-11 digit
	 * timestamp too, but it reads it as UTC while post_date is local -- so a
	 * shop would get its sale windows in one zone and its order dates in
	 * another. Converted here, once, into the shop's local string form so that
	 * ONE --timezone is right for every date in the export.
	 */
	protected function date( $raw ) {
		$value = trim( (string) $raw );

		if ( '' === $value || '0' === $value || '0000-00-00 00:00:00' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{9,11}$/', $value ) ) {
			// Through the same converter the order dates use, so a shop with
			// daylight saving gets one answer rather than two: gmt_offset is
			// the CURRENT offset and knows nothing about the summer a sale
			// window was set in.
			return KBB_Export_Wp::local_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $value ) );
		}

		return $value;
	}

	/** WooCommerce's yes/no, as something Row::bool() reads. */
	protected function yesno( $raw ) {
		return in_array( strtolower( trim( (string) $raw ) ), array( 'yes', '1', 'true', 'on' ), true ) ? 'yes' : 'no';
	}

	/**
	 * A list cell.
	 *
	 * PIPE-SEPARATED FOR URL LISTS AND COMMA FOR ID LISTS, and the difference
	 * is load-bearing. ProductImporter::import() chooses its separator by
	 * looking: "A pipe is the unambiguous case, so it wins where it appears; a
	 * URL cannot contain a bare '|'. Otherwise the comma is what Woo wrote."
	 * Its own comment records what the comma cost -- a whole gallery imported as
	 * one string that is not a URL, four broken images per product, and a report
	 * that said "created" either way. A filename with a comma in it is not
	 * exotic, so galleries go out pipe-separated and land on the unambiguous
	 * branch. Ids cannot contain either character, so they stay comma-separated,
	 * which is what `category_term_ids` in the checked-in fixture holds.
	 */
	protected function pipes( array $values ) {
		return implode( '|', array_filter( array_map( 'trim', $values ), 'strlen' ) );
	}

	protected function commas( array $values ) {
		return implode( ',', array_filter( array_map( 'trim', $values ), 'strlen' ) );
	}

	/** @return array<int,int> */
	protected function ids_of( array $rows, $column = 'ID' ) {
		$out = array();

		foreach ( $rows as $row ) {
			$out[] = (int) $row[ $column ];
		}

		return $out;
	}
}
