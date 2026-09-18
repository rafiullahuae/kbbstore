<?php
/**
 * The export: which stages, in which order, and where it got to.
 *
 * ── RESUME IS THE POINT, NOT A FEATURE ──────────────────────────────────────
 *
 * The owner's WordPress host has no shell either, so this runs from the admin
 * screen in HTTP requests, and a shared host will kill a request at 30, 60 or
 * 110 seconds without warning or notice. So no single request does more than
 * one batch, and the state below -- stage, cursor, rows written per file -- is
 * saved to an option AFTER the batch is appended to the file and BEFORE the
 * response is sent. The two orders are not interchangeable: saving first and
 * writing second loses a batch when the request dies between them, while
 * writing first and saving second at worst repeats one batch, and a repeated
 * batch is visible (duplicate rows in one file) where a lost one is not.
 *
 * ── THE MANIFEST IS WRITTEN LAST, AND IS THE PROOF THE REST FINISHED ────────
 *
 * docs/WP-EXPORT-CONTRACT.md: "sha256 is of the file's bytes as written, and
 * the manifest is written last, after every file it describes is closed." An
 * export folder with no manifest.json is therefore an export that did not
 * finish, and the shop can say so in a sentence instead of importing three
 * quarters of a catalogue. That is the whole reason the order matters.
 *
 * ── AND IT NAMES EVERY FILE, INCLUDING THE EMPTY ONES ───────────────────────
 *
 * Also the contract: a file with no rows is listed with "rows": 0, because
 * "this shop has no coupons" and "this export does not carry coupons" are not
 * the same fact. Every stage's file is created with its header row at start(),
 * before any of them has a row, so an empty one is empty rather than missing.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Runner {

	const STATE_OPTION = 'kbb_exporter_state';
	const FORMAT       = 'kbb-export/1';
	const PLUGIN_VERSION = '1.0.0';

	/** @var array<string,mixed> */
	private $state;

	/** @var array<int,KBB_Export_Stage> */
	private $stages = array();

	/** @var KBB_Export_Orders_Source|null */
	private $orders;

	/** @var array<string,mixed> */
	private $settings;

	public function __construct( array $settings = array() ) {
		$this->settings = array_merge(
			array(
				// The importer's own instruction, not this plugin's opinion:
				// ProductImporter refuses a trashed product ("empty the trash
				// or filter the export") and CouponImporter refuses an
				// unpublished coupon. Held back here, and COUNTED in the
				// manifest notes, so the omission is stated rather than silent.
				'skip_trashed'   => true,
				'batch'          => 200,
				// wp_users holds administrators and editors as well as
				// shoppers. Every one of them is a person who may have ordered,
				// so all of them are exported and the role breakdown goes in
				// the notes; CustomerImporter makes each a customer row and the
				// owner filters afterwards if he wants to.
				'include_posts'  => true,
			),
			$settings
		);

		$this->state = $this->load_state();
	}

	/** @return array<string,mixed> */
	public function state() {
		return $this->state;
	}

	public function settings() {
		return $this->settings;
	}

	/**
	 * Begin a new export. Throws away any half-finished one.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function start() {
		$detected = KBB_Export_Orders_Source::detect();

		if ( null === $detected['source'] ) {
			return array( 'ok' => false, 'error' => $detected['error'] );
		}

		$this->orders = $detected['source'];

		$export_id = $this->export_id();
		$dir       = $this->export_dir( $export_id );

		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
			return array( 'ok' => false, 'error' => 'Could not create ' . $dir . '. Check the uploads folder is writable.' );
		}

		$this->write_index_guard( $dir );

		$this->state = array(
			'export_id'     => $export_id,
			'dir'           => $dir,
			'started_at'    => KBB_Export_Wp::now_iso(),
			'stage'         => 0,
			'cursor'        => 0,
			'written'       => array(),
			'totals'        => array(),
			'notes'         => array(),
			'order_storage' => $this->orders->storage(),
			'storage_note'  => $this->orders->describe(),
			'storage_detail'=> $detected['detail'],
			'done'          => false,
		);

		foreach ( $this->stages() as $stage ) {
			$file = $stage->file();

			$this->state['totals'][ $file ]  = (int) $stage->total();
			$this->state['written'][ $file ] = 0;

			$this->csv( $stage )->open();
		}

		$this->state['notes'][] = 'Orders were read from ' . $this->orders->describe();

		/*
		 * THE SETTINGS ARE PINNED TO THE EXPORT, NOT TO THE REQUEST.
		 *
		 * The admin screen sends the form with every batch, so an operator who
		 * ticks "include trashed" halfway through would change which rows the
		 * REMAINING batches select -- and the file would then hold the first
		 * two thousand products under one rule and the rest under another, with
		 * nothing in it to say where the line is. Whatever was chosen when Start
		 * was pressed is what the whole export runs under; `batch` is the one
		 * exception, because how many rows fit in a request is a property of the
		 * host and not of the data.
		 */
		$this->state['settings'] = $this->settings;

		$this->save_state();

		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Do one batch. Returns the progress the screen prints.
	 *
	 * @return array<string,mixed>
	 */
	public function step() {
		if ( empty( $this->state ) || ! isset( $this->state['stage'] ) ) {
			return array( 'ok' => false, 'error' => 'No export is running. Start one first.' );
		}

		if ( ! empty( $this->state['done'] ) ) {
			return $this->progress();
		}

		$stages = $this->stages();
		$index  = (int) $this->state['stage'];

		if ( $index >= count( $stages ) ) {
			$this->finish();

			return $this->progress();
		}

		$stage = $stages[ $index ];
		$batch = $stage->batch( (int) $this->state['cursor'], (int) $this->settings['batch'] );

		$written = $this->csv( $stage )->append( $batch['rows'] );

		$file = $stage->file();

		$this->state['written'][ $file ] = (int) $this->state['written'][ $file ] + $written;
		$this->state['cursor']           = (int) $batch['cursor'];

		foreach ( $stage->notes() as $note ) {
			if ( ! in_array( $note, $this->state['notes'], true ) ) {
				$this->state['notes'][] = $note;
			}
		}

		if ( ! empty( $batch['done'] ) ) {
			$this->state['stage']  = $index + 1;
			$this->state['cursor'] = 0;

			if ( $this->state['stage'] >= count( $stages ) ) {
				$this->save_state();
				$this->finish();

				return $this->progress();
			}
		}

		// Written first, saved second: a request killed between them repeats a
		// batch, which is visible; the other order loses one, which is not.
		$this->save_state();

		return $this->progress();
	}

	/**
	 * Write manifest.json, after every file it describes is closed.
	 *
	 * @return array<string,mixed> the manifest, as written
	 */
	public function finish() {
		$files = array();
		$counts = array();

		foreach ( $this->stages() as $stage ) {
			$file = $stage->file();
			$path = $this->state['dir'] . '/' . $file;

			$bytes  = file_exists( $path ) ? (int) filesize( $path ) : 0;
			$sha    = file_exists( $path ) ? hash_file( 'sha256', $path ) : '';
			$rows   = isset( $this->state['written'][ $file ] ) ? (int) $this->state['written'][ $file ] : 0;

			$files[ $file ] = array(
				'rows'   => $rows,
				'bytes'  => $bytes,
				'sha256' => $sha,
			);

			$counts[ $this->entity_for( $file ) ] = $rows;
		}

		$manifest = array(
			'format'       => self::FORMAT,
			'export_id'    => (string) $this->state['export_id'],
			'generated_at' => KBB_Export_Wp::now_iso(),
			'source'       => array(
				'site_url'        => KBB_Export_Wp::option( 'siteurl', '' ),
				'home_url'        => KBB_Export_Wp::option( 'home', '' ),
				'wp_version'      => $this->wp_version(),
				'woo_version'     => KBB_Export_Wp::option( 'woocommerce_version', '' ),
				'plugin_version'  => self::PLUGIN_VERSION,
				// Not in the contract's example and not forbidden by it either
				// -- "Unknown keys are ignored, never fatal" -- and it is the
				// one figure App\Services\Import\DateParser refuses to guess.
				// Without it the owner has to know his own site's timezone
				// before `kbb:import --timezone=` is safe to run.
				'timezone'        => KBB_Export_Wp::timezone_name(),
				'order_storage'   => (string) $this->state['order_storage'],
				'permalink_structure'   => KBB_Export_Wp::option( 'permalink_structure', '' ),
				'woocommerce_permalinks' => $this->woocommerce_permalinks(),
			),
			'files'        => $files,
			'counts'       => $counts,
			'notes'        => array_values( (array) $this->state['notes'] ),
		);

		$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		file_put_contents( $this->state['dir'] . '/manifest.json', $json . "\n" );

		$this->state['done'] = true;
		$this->save_state();

		return $manifest;
	}

	/** @return array<string,mixed> */
	public function progress() {
		$stages = $this->stages();
		$index  = isset( $this->state['stage'] ) ? (int) $this->state['stage'] : 0;

		$total   = 0;
		$written = 0;

		foreach ( $stages as $stage ) {
			$file     = $stage->file();
			$total   += isset( $this->state['totals'][ $file ] ) ? (int) $this->state['totals'][ $file ] : 0;
			$written += isset( $this->state['written'][ $file ] ) ? (int) $this->state['written'][ $file ] : 0;
		}

		return array(
			'ok'        => true,
			'error'     => '',
			'export_id' => isset( $this->state['export_id'] ) ? $this->state['export_id'] : '',
			'dir'       => isset( $this->state['dir'] ) ? $this->state['dir'] : '',
			'stage'     => $index < count( $stages ) ? $stages[ $index ]->file() : 'manifest.json',
			'stage_index' => $index,
			'stage_count' => count( $stages ),
			'written'   => $this->state['written'],
			'totals'    => $this->state['totals'],
			// The denominator manifest.json exists to give the shop, given to
			// the export's own screen for the same reason: a bar without one is
			// the fake 100% docs/GD-MEDIA-SIDELOADER.md already removed once.
			'rows_done' => $written,
			'rows_total'=> $total,
			'percent'   => $total > 0 ? min( 100, (int) floor( $written * 100 / $total ) ) : ( ! empty( $this->state['done'] ) ? 100 : 0 ),
			'done'      => ! empty( $this->state['done'] ),
			'storage'   => isset( $this->state['storage_note'] ) ? $this->state['storage_note'] : '',
			'notes'     => isset( $this->state['notes'] ) ? $this->state['notes'] : array(),
		);
	}

	/**
	 * The stages, in the order the shop's importer needs them read.
	 *
	 * The ORDER of the files in the folder does not matter to the importer --
	 * App\Services\Import\ImportRunner::entities() fixes its own order, and its
	 * comments explain why coupons come after products and before orders. What
	 * this order buys is the EXPORT's own failure mode: the catalogue comes out
	 * first, so an export that dies at 60% has the products and the orders and
	 * not just the tags.
	 *
	 * @return array<int,KBB_Export_Stage>
	 */
	public function stages() {
		if ( ! empty( $this->stages ) ) {
			return $this->stages;
		}

		if ( null === $this->orders ) {
			$detected     = KBB_Export_Orders_Source::detect();
			$this->orders = $detected['source'];
		}

		$s = $this->pinned_settings();

		$this->stages = array(
			new KBB_Export_Stage_Categories( $s ),
			new KBB_Export_Stage_Brands( $s ),
			new KBB_Export_Stage_Tags( $s ),
			new KBB_Export_Stage_Attributes( $s ),
			new KBB_Export_Stage_Products( $s ),
			new KBB_Export_Stage_Variations( $s ),
			new KBB_Export_Stage_Seo( $s ),
			new KBB_Export_Stage_Coupons( $s ),
			new KBB_Export_Stage_Customers( $s ),
			new KBB_Export_Stage_Orders( $s, $this->orders ),
			new KBB_Export_Stage_Order_Items( $s ),
			new KBB_Export_Stage_Refunds( $s, $this->orders ),
			new KBB_Export_Stage_Order_Notes( $s ),
			new KBB_Export_Stage_Reviews( $s ),
			new KBB_Export_Stage_Posts( $s ),
			new KBB_Export_Stage_Permalinks( $s ),
			new KBB_Export_Stage_Media( $s ),
		);

		return $this->stages;
	}

	/**
	 * The settings this export was started with, with only `batch` taken from
	 * the current request. See start().
	 *
	 * @return array<string,mixed>
	 */
	private function pinned_settings() {
		if ( empty( $this->state['settings'] ) || ! is_array( $this->state['settings'] ) ) {
			return $this->settings;
		}

		$pinned          = $this->state['settings'];
		$pinned['batch'] = $this->settings['batch'];

		return $pinned;
	}

	private function csv( KBB_Export_Stage $stage ) {
		return new KBB_Export_Csv( $this->state['dir'] . '/' . $stage->file(), $stage->columns() );
	}

	/** The `counts` key for a file, which is the entity name the shop uses. */
	private function entity_for( $file ) {
		$name = preg_replace( '/\.csv$/', '', $file );

		return 'order_items' === $name ? 'order_items' : $name;
	}

	/**
	 * A random export id.
	 *
	 * The contract's example is a UUID and the shop only ever compares it for
	 * equality, so any value distinct per export will do. random_bytes() is
	 * used where it exists and mt_rand() where it does not -- this has to run
	 * on whatever PHP a shared host is on, and a predictable export id is not a
	 * security property here: nothing authorises on it.
	 */
	private function export_id() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				$bytes = random_bytes( 16 );
			} catch ( Exception $e ) { // phpcs:ignore
				$bytes = '';
			}
		} else {
			$bytes = '';
		}

		if ( 16 !== strlen( $bytes ) ) {
			$bytes = '';

			for ( $i = 0; $i < 16; $i++ ) {
				$bytes .= chr( mt_rand( 0, 255 ) );
			}
		}

		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		$hex = bin2hex( $bytes );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 )
			. '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	public function export_dir( $export_id ) {
		return KBB_Export_Wp::uploads_dir() . '/kbb-export/' . $export_id;
	}

	/**
	 * An empty index.php beside the export.
	 *
	 * THE EXPORT IS CUSTOMER DATA SITTING IN THE WEB ROOT. customers.csv holds
	 * every shopper's address and their WordPress password hash; reviews.csv
	 * holds the reviewer's email and the IP they posted from -- the exact pair
	 * CLAUDE.md names as having leaked from /api/* before. An uploads folder is
	 * served by the web server and on most hosts it is indexable, so a
	 * predictable path plus directory listing is the whole breach.
	 *
	 * The export id in the path is random, index.php stops the listing, and
	 * .htaccess denies the folder outright on Apache, which is what this host
	 * runs. None of the three is sufficient alone and the combination is what
	 * every backup plugin ships. The admin screen also says, in words, to
	 * delete the folder once the export has been downloaded -- because the only
	 * completely safe copy is the one that is not there.
	 */
	private function write_index_guard( $dir ) {
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		$parent = dirname( $dir );

		if ( ! file_exists( $parent . '/index.php' ) ) {
			file_put_contents( $parent . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		if ( ! file_exists( $parent . '/.htaccess' ) ) {
			file_put_contents(
				$parent . '/.htaccess',
				"# This folder holds a full customer export: addresses, password hashes,\n"
				. "# reviewer emails and IPs. Nothing here is meant to be fetched over HTTP.\n"
				. "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
			);
		}
	}

	private function woocommerce_permalinks() {
		$raw = get_option( 'woocommerce_permalinks', array() );

		if ( is_string( $raw ) ) {
			$raw = maybe_unserialize( $raw );
		}

		return is_array( $raw ) ? $raw : array();
	}

	private function wp_version() {
		global $wp_version;

		return isset( $wp_version ) ? (string) $wp_version : KBB_Export_Wp::option( 'kbb_wp_version', '' );
	}

	/** @return array<string,mixed> */
	private function load_state() {
		$raw = get_option( self::STATE_OPTION, array() );

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $raw ) ? $raw : array();
	}

	private function save_state() {
		update_option( self::STATE_OPTION, $this->state );
	}

	public function reset() {
		delete_option( self::STATE_OPTION );

		$this->state = array();
	}
}
