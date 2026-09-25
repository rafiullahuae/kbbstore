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

	/**
	 * What the owner has to type before anything is deleted.
	 *
	 * A word rather than a tick box, and an upper-case one, because the action
	 * it confirms cannot be undone: the export is the only copy of a several-
	 * minute run, and on cutover day it is the only copy of the shop's data
	 * that is not on the old site. A tick box is one mis-click; this is not.
	 * Checked on the server in purge(), not only by the page.
	 */
	const PURGE_PHRASE = 'DELETE';

	/** Recursion ceiling for the delete and for the measure that checks it. */
	const PURGE_MAX_DEPTH = 8;

	/**
	 * The files that are the reason the folder must not be left on the server.
	 *
	 * Named rather than counted, on the screen and in the answer, because "12
	 * files" is not a reason to press a destructive button and "customers.csv,
	 * which holds every shopper's address and password hash" is.
	 *
	 * @var array<int,string>
	 */
	const SENSITIVE_FILES = array( 'customers.csv', 'reviews.csv', 'orders.csv' );
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
				/*
				 * WHICH GROUPS, and what the operator said about the ones he
				 * left out. Defaulting to every group keeps the old behaviour
				 * exactly: a caller that knows nothing about groups -- the
				 * harness before this lane, a future WP-CLI command -- gets the
				 * whole export and no dependency is ever unmet.
				 */
				'groups'         => KBB_Export_Groups::keys(),
				'confirmed'      => array(),
			),
			$settings
		);

		$this->settings['groups']    = KBB_Export_Groups::normalise( (array) $this->settings['groups'] );
		$this->settings['confirmed'] = array_values( (array) $this->settings['confirmed'] );

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
		/*
		 * THE DEPENDENCY GUARD, AND IT IS HERE AND NOT ONLY IN THE BROWSER.
		 *
		 * The admin screen disables the button, which is a statement about one
		 * browser with JavaScript running in it. This is the statement about the
		 * export. A selection whose dependencies are neither satisfied nor
		 * confirmed does not start, and the sentence it refuses with is the same
		 * one the screen prints, so the two cannot describe the hazard
		 * differently.
		 *
		 * It refuses rather than auto-ticking the missing group on purpose: the
		 * missing group may well already be in the new shop, and re-exporting
		 * 671 products to get 40 coupons is the waste this screen exists to
		 * remove. Only the owner knows which, so only the owner can say.
		 */
		$outstanding = KBB_Export_Groups::outstanding(
			(array) $this->settings['groups'],
			(array) $this->settings['confirmed']
		);

		if ( ! empty( $outstanding ) ) {
			return array( 'ok' => false, 'error' => KBB_Export_Groups::refusal( $outstanding ) );
		}

		if ( empty( $this->settings['groups'] ) ) {
			return array( 'ok' => false, 'error' => 'Nothing is ticked. Choose at least one group to export.' );
		}

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
		 * WHAT THIS EXPORT DOES NOT CARRY, in words, in the file the shop reads.
		 *
		 * A row silently absent from an export is worse than a row the importer
		 * refuses -- the refusal is in a report the owner reads and the absence
		 * is in no report at all. A whole GROUP silently absent is that same
		 * defect multiplied by four files, so every skipped group gets a
		 * sentence, and so does every dependency the operator waved through on
		 * the grounds that it is already in the new shop.
		 */
		foreach ( KBB_Export_Groups::notes( $this->settings['groups'], $this->settings['confirmed'] ) as $note ) {
			$this->state['notes'][] = $note;
		}

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
		$pinned = $this->pinned_settings();
		$files  = array();
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
				/*
				 * WHAT ELSE THIS SITE HAS, answered by the export rather than
				 * by asking the owner.
				 *
				 * Every taxonomy and every post type that actually has rows,
				 * with its count. `permalinks.csv` says what each thing's
				 * address is; this says what things there ARE -- including the
				 * ones no file carries, which is the list somebody will want
				 * the first time a page turns out to be missing. A taxonomy
				 * nobody remembered registering, a custom post type from a
				 * plugin removed in 2022: both are one line here and invisible
				 * everywhere else.
				 *
				 * The contract permits it: "Unknown keys are ignored, never
				 * fatal."
				 */
				'taxonomies'    => KBB_Export_Wp::taxonomies_in_database(),
				'post_types'    => KBB_Export_Wp::post_types_in_database(),
			),
			'files'        => $files,
			'counts'       => $counts,
			/*
			 * WHICH GROUPS THIS EXPORT CARRIES, said out loud.
			 *
			 * `files` above already says it structurally -- a skipped group's
			 * files are absent from it rather than present with "rows": 0, and
			 * docs/WP-EXPORT-CONTRACT.md is explicit that those are different
			 * facts, App\Services\ImportConsole\ImportManifest::lists() is the
			 * reader, and the import screen prints the difference per entity.
			 * This block is the same fact in the owner's vocabulary plus the one
			 * thing a file list cannot carry: what he confirmed was ALREADY in
			 * the new shop, which is a claim about the other shop that only he
			 * can make.
			 */
			'groups'       => KBB_Export_Groups::manifest_block(
				(array) $pinned['groups'],
				(array) $pinned['confirmed']
			),
			'notes'        => array_values( (array) $this->state['notes'] ),
		);

		$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		file_put_contents( $this->state['dir'] . '/manifest.json', $json . "\n" );

		/*
		 * ── AND NOW THE ZIPS, AS THEIR OWN PHASE ────────────────────────────
		 *
		 * The owner's words: "allow to download each group seperate files. so
		 * will have no any heavy file." One archive per group, built HERE and
		 * not during the export, because the manifest each archive carries is
		 * derived from this one and this one is written last.
		 *
		 * The queue is seeded and NOT drained. Compressing 10,571 order lines in
		 * the request that finished the export would put the timeout back
		 * exactly where the per-batch loop took it from, so the browser drives
		 * the zip phase the same way it drives the export: one file into one
		 * archive per request. See KBB_Export_Zip::plan().
		 *
		 * `done` keeps its existing meaning -- every CSV written and the
		 * manifest closed -- because the folder is a complete, importable export
		 * at that moment whether or not anything is zipped, and every test and
		 * every reader that already depends on that sentence is right. The zip
		 * phase reports itself separately.
		 */
		$this->state['zip'] = array(
			'cursor'    => 0,
			'plan'      => KBB_Export_Zip::plan( $manifest ),
			'error'     => KBB_Export_Zip::available() ? '' : KBB_Export_Zip::unavailable_reason(),
			'available' => KBB_Export_Zip::available(),
		);

		$this->state['done'] = true;
		$this->save_state();

		return $manifest;
	}

	/**
	 * The manifest this export wrote, read back off disk.
	 *
	 * Read back rather than kept in the state on purpose: it is what the ZIPS
	 * are built from, and building them from the file that will actually be
	 * shipped means a zip cannot describe a manifest that differs from the one
	 * beside it in the folder.
	 *
	 * @return array<string,mixed>
	 */
	public function manifest() {
		if ( empty( $this->state['dir'] ) ) {
			return array();
		}

		$path = $this->state['dir'] . '/manifest.json';

		if ( ! file_exists( $path ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * ONE BOUNDED UNIT OF THE ZIP PHASE: one file into one group's archive.
	 *
	 * Driven by the browser exactly as step() is, and for exactly the same
	 * reason -- this host kills a request at 110 seconds and a group is not a
	 * unit of work that respects that. A request that dies costs one file, and
	 * the cursor in the option means pressing Resume picks up on the next one.
	 *
	 * @return array<string,mixed>
	 */
	public function zip_step() {
		if ( empty( $this->state ) || empty( $this->state['done'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'There is no finished export to pack. Run the export first.',
			);
		}

		if ( ! KBB_Export_Zip::available() ) {
			/*
			 * NOT FATAL, AND NOT SILENT. A host without ext-zip has a complete,
			 * correct export sitting in the folder; what it does not have is the
			 * convenience of downloading it from this screen. Saying so and
			 * stopping is the honest answer, and the screen prints the sentence
			 * beside the FTP path.
			 */
			$this->state['zip'] = array(
				'cursor'    => 0,
				'plan'      => array(),
				'error'     => KBB_Export_Zip::unavailable_reason(),
				'available' => false,
			);

			$this->save_state();

			return $this->zip_progress();
		}

		if ( empty( $this->state['zip'] ) || ! isset( $this->state['zip']['plan'] ) ) {
			// An export finished before this lane existed has no queue. Build
			// one now rather than telling him to run the whole export again.
			$this->state['zip'] = array(
				'cursor'    => 0,
				'plan'      => KBB_Export_Zip::plan( $this->manifest() ),
				'error'     => '',
				'available' => true,
			);
		}

		$plan   = (array) $this->state['zip']['plan'];
		$cursor = (int) $this->state['zip']['cursor'];

		if ( $cursor >= count( $plan ) ) {
			return $this->zip_progress();
		}

		$result = KBB_Export_Zip::add( $this->state['dir'], $this->manifest(), $plan[ $cursor ] );

		if ( ! $result['ok'] ) {
			$this->state['zip']['error'] = $result['error'];

			$this->save_state();

			return array( 'ok' => false, 'error' => $result['error'] );
		}

		// Written first, saved second, for the reason step() gives: a request
		// killed between them repeats a unit, which is harmless because adding
		// the same entry to the same archive again is the same archive.
		$this->state['zip']['cursor'] = $cursor + 1;

		$this->save_state();

		return $this->zip_progress();
	}

	/**
	 * What the screen prints beside each group's Download button.
	 *
	 * @return array<string,mixed>
	 */
	public function zip_progress() {
		$manifest = $this->manifest();
		$zip      = isset( $this->state['zip'] ) ? (array) $this->state['zip'] : array();
		$plan     = isset( $zip['plan'] ) ? (array) $zip['plan'] : array();
		$cursor   = isset( $zip['cursor'] ) ? (int) $zip['cursor'] : 0;
		$ok       = ! isset( $zip['available'] ) || ! empty( $zip['available'] );

		return array(
			'ok'         => true,
			'error'      => '',
			'available'  => $ok,
			'reason'     => isset( $zip['error'] ) ? (string) $zip['error'] : '',
			'units_done' => min( $cursor, count( $plan ) ),
			'units'      => count( $plan ),
			'done'       => ! $ok || $cursor >= count( $plan ),
			'percent'    => count( $plan ) > 0
				? ( $cursor >= count( $plan ) ? 100 : (int) floor( $cursor * 100 / count( $plan ) ) )
				: 100,
			'groups'     => empty( $manifest ) ? array() : array_values( KBB_Export_Zip::status( $this->state['dir'], $manifest ) ),
		);
	}

	/**
	 * The archive a download request is asking for, resolved SERVER SIDE.
	 *
	 * ── NO PATH FROM THE REQUEST EVER REACHES THE FILESYSTEM ────────────────
	 *
	 * The request carries a group key and a part number and nothing else. The
	 * folder comes from this runner's own state and the file name is computed by
	 * KBB_Export_Zip::zip_name() from that state's export id. There is no
	 * concatenation of anything the browser sent into a path, so there is no
	 * traversal to defend against rather than a defence to get right -- which is
	 * the difference between this and a sanitised `?file=` parameter.
	 *
	 * The group key is checked against the plugin's own declaration and the part
	 * against the queue, so an id that was never issued and a group that was
	 * never exported do the same work and give the same answer. That is the same
	 * shape docs/CLAUDE notes require of QuizSubmission::findByPublicToken().
	 *
	 * @param string $group
	 * @param int    $part
	 * @return array{ok: bool, error: string, path: string, name: string}
	 */
	public function archive_path( $group, $part = 1 ) {
		$miss = array( 'ok' => false, 'error' => 'There is no such download.', 'path' => '', 'name' => '' );

		if ( empty( $this->state['dir'] ) || empty( $this->state['done'] ) ) {
			return $miss;
		}

		if ( ! KBB_Export_Groups::exists( (string) $group ) ) {
			return $miss;
		}

		$manifest = $this->manifest();

		if ( empty( $manifest ) ) {
			return $miss;
		}

		$part = max( 1, (int) $part );

		foreach ( KBB_Export_Zip::plan( $manifest ) as $unit ) {
			if ( $unit['group'] !== $group || (int) $unit['part'] !== $part ) {
				continue;
			}

			$name = KBB_Export_Zip::zip_name( $group, $manifest['export_id'], $part, $unit['parts'] );
			$path = $this->state['dir'] . '/' . $name;

			if ( ! file_exists( $path ) ) {
				return array(
					'ok'    => false,
					'error' => 'That archive has not been packed yet.',
					'path'  => '',
					'name'  => $name,
				);
			}

			return array( 'ok' => true, 'error' => '', 'path' => $path, 'name' => $name );
		}

		return $miss;
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
			'groups'    => $this->group_progress( $stages, $index ),
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
			'rows_total'=> max( $total, $written ),
			/*
			 * ── 100% MEANS FINISHED AND NOTHING ELSE ────────────────────────
			 *
			 * MEASURED, and it was wrong first. The media stage's total() counts
			 * the objects that can reference a picture -- one per product -- and
			 * it writes one row per (url, referrer, field), which on the fixture
			 * is FOUR rows for one product and on a real shop is a featured
			 * image plus a gallery of four plus whatever is in the description.
			 * So its denominator under-estimates by about five to one.
			 *
			 * The old line clamped with min(100, ...), which turned that into a
			 * bar sitting at 100% while the export carried on for another
			 * minute: the exact fake 100% docs/GD-MEDIA-SIDELOADER.md already
			 * found and removed once, reintroduced through the denominator
			 * instead of through the bar.
			 *
			 * Two changes, and neither of them is "make the estimate better",
			 * because an estimate that has to be right is a bug waiting for a
			 * shop shaped differently from this one:
			 *
			 *   - the denominator is max(total, written), so the bar cannot run
			 *     past its own end however wrong the estimate is;
			 *   - the percentage is capped at 99 until `done`, so 100% is a
			 *     statement about the export having finished rather than about
			 *     arithmetic.
			 */
			'percent'   => ! empty( $this->state['done'] )
				? 100
				: ( max( $total, $written ) > 0 ? min( 99, (int) floor( $written * 100 / max( $total, $written ) ) ) : 0 ),
			'done'      => ! empty( $this->state['done'] ),
			'storage'   => isset( $this->state['storage_note'] ) ? $this->state['storage_note'] : '',
			'notes'     => isset( $this->state['notes'] ) ? $this->state['notes'] : array(),
			/*
			 * The zip phase, on the same document the bar is drawn from, so the
			 * screen does not have to ask twice to know whether a group can be
			 * downloaded yet. Empty until the export finishes, because there is
			 * nothing to pack before the manifest exists.
			 */
			'zip'       => ! empty( $this->state['done'] ) ? $this->zip_progress() : null,
		);
	}

	/**
	 * One bar per ticked group, which is what the owner asked for.
	 *
	 * ── THE SAME HONESTY RULE AS THE WHOLE-EXPORT BAR ───────────────────────
	 *
	 * Per group, not only overall, because "Writing media.csv, file 14 of 17" is
	 * a sentence about a file and the owner ticked GROUPS. It uses exactly the
	 * arithmetic progress() uses for the whole export, for the same reason and
	 * with the same two corrections: the denominator is max(total, written), so
	 * a stage that writes more rows than its total() predicted -- the media
	 * stage really does, about five to one -- cannot push a bar past its own
	 * end; and the percentage is capped at 99 until the group's last file is
	 * finished, so a full bar means the group is done and nothing else.
	 *
	 * `state` is the same three the whole screen uses and
	 * docs/GD-MEDIA-SIDELOADER.md insisted on: a group that has not started, the
	 * one being written, and the ones finished. A bar sitting still because its
	 * group has not begun and a bar sitting still because the request died must
	 * not look the same.
	 *
	 * @param array<int,KBB_Export_Stage> $stages
	 * @param int                         $index
	 * @return array<int,array<string,mixed>>
	 */
	private function group_progress( array $stages, $index ) {
		$all      = KBB_Export_Groups::all();
		$selected = KBB_Export_Groups::normalise( (array) $this->pinned_settings()['groups'] );
		$out      = array();

		foreach ( $selected as $key ) {
			$total    = 0;
			$written  = 0;
			$files    = array();
			$first    = null;
			$last     = null;

			foreach ( $stages as $position => $stage ) {
				$file = $stage->file();

				if ( ! in_array( $file, $all[ $key ]['files'], true ) ) {
					continue;
				}

				$files[] = $file;
				$first   = null === $first ? $position : $first;
				$last    = $position;

				$total   += isset( $this->state['totals'][ $file ] ) ? (int) $this->state['totals'][ $file ] : 0;
				$written += isset( $this->state['written'][ $file ] ) ? (int) $this->state['written'][ $file ] : 0;
			}

			if ( null === $first ) {
				continue;
			}

			$done = ! empty( $this->state['done'] ) || $index > $last;

			$out[] = array(
				'key'        => $key,
				'label'      => $all[ $key ]['label'],
				'files'      => $files,
				'rows_done'  => $written,
				'rows_total' => max( $total, $written ),
				'percent'    => $done
					? 100
					: ( max( $total, $written ) > 0
						? min( 99, (int) floor( $written * 100 / max( $total, $written ) ) )
						: 0 ),
				'state'      => $done ? 'done' : ( $index >= $first ? 'running' : 'pending' ),
			);
		}

		return $out;
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

		if ( null === $this->orders ) {
			/*
			 * start() refuses when detection fails, so getting here means the
			 * shop CHANGED under a running export -- HPOS switched on, or the
			 * wc_orders table dropped, between two batches. Rare, and the reason
			 * to handle it is what the alternative looks like: passing null to
			 * KBB_Export_Stage_Orders' typed constructor is a TypeError, which
			 * on a WordPress admin screen is a white page with nothing on it,
			 * on a host whose owner has no error log to read.
			 */
			throw new RuntimeException(
				'The order storage changed while this export was running (' . $detected['error'] . '). '
					. 'Nothing written so far is lost -- start a new export, which will detect the storage again.'
			);
		}

		$s = $this->pinned_settings();

		$all = array(
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

		/*
		 * ── THE SELECTION FILTERS THE STAGE LIST, IT DOES NOT REORDER IT ────
		 *
		 * The order above is the export's own failure mode: the catalogue comes
		 * out first, so an export that dies at 60% has the products and the
		 * orders and not just the tags. A selection is therefore a SUBSET of
		 * this sequence, never a resequencing of it -- KBB_Export_Groups::
		 * normalise() puts the operator's ticks back into declared order for
		 * the same reason.
		 *
		 * Filtering here rather than at every call site is what makes Pause and
		 * Resume work per group with no extra state: `stage` is an index into
		 * THIS list, `total()` is asked only of the stages in it, and every file
		 * a skipped group would have written is never opened, so it is absent
		 * from the folder and absent from the manifest -- which is the fact
		 * docs/WP-EXPORT-CONTRACT.md distinguishes from "rows": 0.
		 *
		 * The settings are pinned to the export (see pinned_settings), so
		 * `groups` cannot change between batches. Un-ticking a group mid-run
		 * would shorten this list under a cursor that is an index into it, and
		 * the export would carry on inside a different file.
		 */
		$wanted = KBB_Export_Groups::files_for( (array) $s['groups'] );

		$this->stages = array();

		foreach ( $all as $stage ) {
			if ( in_array( $stage->file(), $wanted, true ) ) {
				$this->stages[] = $stage;
			}
		}

		// array_values, because the runner indexes this list by position and a
		// filtered array keeps its original keys.
		$this->stages = array_values( $this->stages );

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

		// An export started before groups existed has no `groups` key in its
		// state. It was a whole export, so that is what it resumes as.
		if ( ! isset( $pinned['groups'] ) ) {
			$pinned['groups'] = KBB_Export_Groups::keys();
		}

		if ( ! isset( $pinned['confirmed'] ) ) {
			$pinned['confirmed'] = array();
		}

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

	/**
	 * Forget where the export got to. THIS DELETES NO FILE.
	 *
	 * Named and commented so that nobody wires it to a Delete button again.
	 * `ajax_start()` calls it to begin a new export from row one, which is the
	 * whole of its job. `customers.csv` and its password hashes are exactly
	 * where they were when this returns; see purge() for the one that removes
	 * them, and KBB_Export_Admin::ajax_purge() for the door it is behind.
	 */
	public function reset() {
		delete_option( self::STATE_OPTION );

		$this->state = array();
	}

	/* ====================================================================== */
	/*  THE REAL DELETE                                                        */
	/* ====================================================================== */

	/**
	 * The folder every export is written under. Computed, never received.
	 *
	 * NOTHING A BROWSER SENDS REACHES THIS. The uploads base comes from
	 * WordPress and the last segment is a literal, so there is no path to
	 * sanitise because there is no path from the request. That is the same
	 * property KBB_Export_Admin::download() relies on, and it matters more here
	 * -- a traversal into a download hands over one file it should not, and a
	 * traversal into a recursive delete takes the site with it.
	 */
	public function exports_root() {
		return KBB_Export_Wp::uploads_dir() . '/kbb-export';
	}

	/**
	 * What is on the server right now: one entry per export, with its size.
	 *
	 * READ FROM THE DISK, never from the state option. The option knows about
	 * the export this plugin is part-way through; the disk knows about the four
	 * from last week that were downloaded and left there, which are the ones
	 * this screen exists to get rid of. An export whose state was reset is
	 * invisible to the option and is still every shopper's address on a public
	 * web server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function exports() {
		$root = $this->exports_root();
		$out  = array();

		if ( ! is_dir( $root ) ) {
			return $out;
		}

		$entries = scandir( $root );

		if ( false === $entries ) {
			return $out;
		}

		$current = isset( $this->state['export_id'] ) ? (string) $this->state['export_id'] : '';

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $root . '/' . $entry;

			if ( ! is_dir( $path ) || is_link( $path ) ) {
				continue;
			}

			$measured = $this->measure( $path );

			$out[] = array(
				'id'      => $entry,
				'files'   => $measured['files'],
				'bytes'   => $measured['bytes'],
				'size'    => self::human( $measured['bytes'] ),
				'created' => gmdate( 'Y-m-d H:i', (int) filemtime( $path ) ),
				'current' => ( '' !== $current && $entry === $current ),
				/*
				 * Said per export and not once at the top, because it is the
				 * sentence that makes the button worth pressing: these two
				 * files are why the folder is not something to leave lying
				 * about.
				 */
				'sensitive' => $this->sensitive_files( $path ),
			);
		}

		return $out;
	}

	/**
	 * DELETE EVERY EXPORT FROM THE SERVER, for real.
	 *
	 * ==========================================================================
	 * WHAT THIS IS FOR, AND WHY reset() IS NOT IT
	 * ==========================================================================
	 *
	 * The screen has always told the owner, correctly, to delete the export
	 * folder once he has downloaded it: `customers.csv` holds every shopper's
	 * address and their WordPress password hash, and `reviews.csv` holds
	 * reviewers' emails and the IPs they posted from. He has no shell and no
	 * FTP -- the paragraph two lines above that instruction says so itself --
	 * so there was no way for him to follow it.
	 *
	 * `wp_ajax_kbb_export_reset` was already registered and reachable, and it
	 * would have made a perfectly convincing Delete button: it answers `ok`, the
	 * progress bar goes back to zero, and the screen looks exactly as it does
	 * after a real delete. It calls reset(), which calls delete_option(). THE
	 * HASHES STAY ON DISK, now with nothing in the admin screen referring to
	 * them and no download link left to reach them -- worse than before,
	 * because the owner has been told they are gone.
	 *
	 * So this is a new destructive path rather than a wire-up, and it answers
	 * with what it can still SEE rather than with what it did.
	 *
	 * ==========================================================================
	 * THE ANSWER IS A RE-SCAN, NOT A COUNTER
	 * ==========================================================================
	 *
	 * `ok` is true when a fresh walk of the folder finds no file left, and
	 * `remaining` names what is still there when it is not. A count of
	 * successful unlink() calls would report success for a folder half of which
	 * could not be removed -- exactly the failure this whole feature exists to
	 * stop reporting. On shared hosting the thing that actually happens is a
	 * file owned by a different UID than PHP runs as, and the owner has to be
	 * told its name.
	 *
	 * AND A RE-SCAN HAS TO ADMIT WHERE IT COULD NOT LOOK, or it is a counter
	 * again with a longer walk in front of it. measure() and remove() share the
	 * depth ceiling and share scandir(), so the two places the walk gives up --
	 * past PURGE_MAX_DEPTH, and a directory scandir() will not read -- are
	 * exactly the two places the delete gave up. Those used to measure as zero
	 * files, `ok` was computed from zero, and the screen said every export file
	 * was gone with customers.csv still on disk. measure() now returns them in
	 * `blind`, they are merged into `remaining`, and `ok` is false while any of
	 * them exists. An answer that cannot see a folder must not be read as an
	 * answer that the folder is empty.
	 *
	 * ==========================================================================
	 * EVERY DELETE IS INSIDE THE ROOT, PROVED PER ENTRY
	 * ==========================================================================
	 *
	 *  1. The root is computed (exports_root()), so no request supplies a path.
	 *  2. Each entry's realpath() must still be under the root's realpath()
	 *     before anything happens to it. A string check alone has been wrong
	 *     before, in this repository and everywhere else.
	 *  3. A SYMLINK IS UNLINKED AND NEVER FOLLOWED. `is_link()` is tested
	 *     before `is_dir()`, because a symlink to a directory answers true to
	 *     both -- and recursing into one is how a delete inside uploads
	 *     becomes a delete of wp-config.php.
	 *  4. Depth is bounded. A recursive delete with no ceiling is a stack
	 *     overflow away from a half-deleted folder.
	 *
	 * ==========================================================================
	 * THE FOLDER'S OWN GUARDS SURVIVE
	 * ==========================================================================
	 *
	 * `kbb-export/index.php` and `kbb-export/.htaccess` are left in place. They
	 * hold no data, they are what stops the folder being listed or served, and
	 * removing them to leave things tidy would open a window: if anything
	 * recreated the folder before write_index_guard() next ran, a directory
	 * listing of an unguarded uploads folder is the whole breach. Everything
	 * INSIDE each export folder goes, the export folders themselves go, and the
	 * two guards stay.
	 *
	 * @param string $confirmation what the owner typed. Must equal PURGE_PHRASE.
	 * @return array<string,mixed>
	 */
	public function purge( $confirmation ) {
		$typed = is_string( $confirmation ) ? trim( $confirmation ) : '';

		/*
		 * THE TYPED CONFIRMATION IS CHECKED HERE, not only on the screen. A
		 * disabled button is a courtesy to the person using the page; it is not
		 * a guard, because the endpoint is reachable without the page. Compared
		 * case-sensitively and in full: "delete" is a word somebody types by
		 * habit, and the point of the phrase is that it cannot be typed by
		 * habit.
		 */
		if ( self::PURGE_PHRASE !== $typed ) {
			return array(
				'ok'        => false,
				'error'     => 'Type ' . self::PURGE_PHRASE . ' in the box to confirm. Nothing was deleted.',
				'deleted'   => 0,
				'bytes'     => 0,
				'remaining' => array(),
			);
		}

		$root = $this->exports_root();

		if ( ! is_dir( $root ) ) {
			return array(
				'ok'        => true,
				'error'     => '',
				'deleted'   => 0,
				'bytes'     => 0,
				'remaining' => array(),
				'note'      => 'There is no export folder on this server.',
			);
		}

		$real = realpath( $root );

		if ( false === $real ) {
			return array(
				'ok'        => false,
				'error'     => 'The export folder could not be resolved on disk. Nothing was deleted.',
				'deleted'   => 0,
				'bytes'     => 0,
				'remaining' => array(),
			);
		}

		$before  = $this->measure( $root, true );
		$deleted = 0;

		$entries = scandir( $root );
		$entries = ( false === $entries ) ? array() : $entries;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			// The two guards stay. See the block above.
			if ( 'index.php' === $entry || '.htaccess' === $entry ) {
				continue;
			}

			$deleted += $this->remove( $root . '/' . $entry, $real, 0 );
		}

		/*
		 * The state option goes too, and AFTER the files rather than before.
		 * It names the folder that has just been deleted, so leaving it would
		 * leave Resume pointing at nothing; removing it first would have left
		 * the only record of where the files are gone while the files were
		 * still there.
		 */
		$this->reset();

		$after = $this->measure( $root, true );

		/*
		 * WHAT IS STILL THERE, read off the disk after the fact, AND WHERE THE
		 * READING ITSELF FAILED. `blind` is the second half and it is not a
		 * refinement: measure() and remove() carry the same depth ceiling and
		 * call the same scandir(), so a folder the walk could not enter is a
		 * folder the delete could not empty. Counting it as nothing made this
		 * method answer `ok: true` with customers.csv on disk -- which is the
		 * one thing it was written to never do.
		 */
		$remaining = array_merge( $after['names'], $after['blind'] );
		$clean     = 0 === $after['files'] && 0 === count( $after['blind'] );

		return array(
			'ok'        => $clean,
			'error'     => '',
			'deleted'   => $deleted,
			'bytes'     => $before['bytes'] - $after['bytes'],
			'size'      => self::human( $before['bytes'] - $after['bytes'] ),
			'remaining' => $remaining,
			'note'      => $clean
				? 'Every export file is gone from this server. The folder guards (index.php and .htaccess) '
					. 'were left in place; they hold nothing.'
				: 'Some of the export could not be removed and is named above. On shared hosting that is '
					. 'normally a file or folder owned by a different user than PHP runs as -- your host can '
					. 'delete it. Treat it as still holding personal data until it is gone.',
		);
	}

	/**
	 * Delete one entry, recursively, refusing anything outside $root.
	 *
	 * @param string $path  the entry
	 * @param string $root  realpath of the exports root
	 * @param int    $depth recursion guard
	 * @return int files and directories actually removed
	 */
	private function remove( $path, $root, $depth ) {
		if ( $depth > self::PURGE_MAX_DEPTH ) {
			return 0;
		}

		/*
		 * A SYMLINK IS TESTED FOR FIRST. is_dir() answers true for a symlink
		 * pointing at a directory, so a check in the other order would recurse
		 * through the link and delete whatever it points at -- which on a
		 * shared host is anything PHP can write.
		 */
		if ( is_link( $path ) ) {
			return @unlink( $path ) ? 1 : 0; // phpcs:ignore
		}

		$real = realpath( $path );

		/*
		 * INSIDE THE ROOT, PROVED, and proved on the resolved path rather than
		 * on the one that was built. The separator is appended to both sides so
		 * that a sibling folder whose name merely begins with the root's cannot
		 * pass -- `/uploads/kbb-export-old` is not inside `/uploads/kbb-export`.
		 */
		if ( false === $real || 0 !== strpos( $real . '/', rtrim( $root, '/' ) . '/' ) ) {
			return 0;
		}

		if ( ! is_dir( $real ) ) {
			return @unlink( $real ) ? 1 : 0; // phpcs:ignore
		}

		$removed = 0;
		$entries = scandir( $real );
		$entries = ( false === $entries ) ? array() : $entries;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$removed += $this->remove( $real . '/' . $entry, $root, $depth + 1 );
		}

		return @rmdir( $real ) ? $removed + 1 : $removed; // phpcs:ignore
	}

	/**
	 * Walk a folder and say how much is in it, and what of it is sensitive.
	 *
	 * ── AND WHERE IT COULD NOT LOOK, WHICH IS NOT THE SAME AS NOTHING ──────
	 *
	 * `blind` names every folder this walk had to give up on. There are exactly
	 * two ways that happens and both of them ALSO stop remove() -- it carries
	 * the same ceiling and calls the same scandir() -- so a folder that is
	 * missing from this answer is a folder that still has whatever was in it.
	 *
	 *  1. THE DEPTH CEILING. Past PURGE_MAX_DEPTH the walk stops. remove()
	 *     stopped at the same place, so everything below is still on disk and
	 *     every rmdir() on the way back up failed for a non-empty directory.
	 *  2. AN UNREADABLE DIRECTORY. scandir() answering false is the shared-
	 *     hosting case this whole feature is written for: a folder owned by a
	 *     different UID than PHP runs as. It was returning an empty list, which
	 *     reads as "there is nothing in it".
	 *
	 * Either one used to return zeros, purge() computed `ok` from zeros, and the
	 * screen told the owner every export file was gone while customers.csv was
	 * still sitting there -- the exact sentence this feature exists to stop the
	 * plugin from saying. A walk that cannot see must say so.
	 *
	 * @return array{files:int,bytes:int,names:array<int,string>,sensitive:array<int,string>,blind:array<int,string>}
	 */
	private function measure( $path, $skip_guards = false, $depth = 0, $rel = '' ) {
		$out = array(
			'files'     => 0,
			'bytes'     => 0,
			'names'     => array(),
			'sensitive' => array(),
			'blind'     => array(),
		);

		if ( ! is_dir( $path ) || is_link( $path ) ) {
			return $out;
		}

		if ( $depth > self::PURGE_MAX_DEPTH ) {
			$out['blind'][] = ( '' === $rel ? basename( $path ) : $rel ) . '/ (nested too deeply to read or remove)';

			return $out;
		}

		$entries = scandir( $path );

		if ( false === $entries ) {
			$out['blind'][] = ( '' === $rel ? basename( $path ) : $rel ) . '/ (this folder could not be read)';

			return $out;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			/*
			 * THE ROOT'S OWN GUARDS ARE NOT EXPORT DATA and are never counted
			 * as something left behind; purge() leaves them there on purpose.
			 *
			 * Asked for by the caller rather than inferred from $depth === 0.
			 * An export folder carries an index.php of its own, and measuring
			 * one of those from exports() starts at depth 0 too -- so a depth
			 * test would have quietly under-counted every export by one file
			 * while looking exactly right.
			 */
			if ( $skip_guards && 0 === $depth && ( 'index.php' === $entry || '.htaccess' === $entry ) ) {
				continue;
			}

			$full = $path . '/' . $entry;

			if ( is_dir( $full ) && ! is_link( $full ) ) {
				$inner = $this->measure( $full, false, $depth + 1, ( '' === $rel ? $entry : $rel . '/' . $entry ) );

				$out['files']    += $inner['files'];
				$out['bytes']    += $inner['bytes'];
				$out['names']     = array_merge( $out['names'], $inner['names'] );
				$out['sensitive'] = array_merge( $out['sensitive'], $inner['sensitive'] );
				$out['blind']     = array_merge( $out['blind'], $inner['blind'] );

				continue;
			}

			$out['files']++;
			$out['bytes'] += (int) @filesize( $full ); // phpcs:ignore
			$out['names'][] = $entry;

			if ( in_array( $entry, self::SENSITIVE_FILES, true ) ) {
				$out['sensitive'][] = $entry;
			}
		}

		return $out;
	}

	/**
	 * The files in one export that carry personal data, by name.
	 *
	 * @return array<int,string>
	 */
	private function sensitive_files( $path ) {
		$out = array();

		foreach ( self::SENSITIVE_FILES as $name ) {
			if ( is_file( $path . '/' . $name ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/** Bytes as something a person reads. */
	public static function human( $bytes ) {
		$bytes = (int) $bytes;

		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}

		$units = array( 'KB', 'MB', 'GB' );
		$value = $bytes;

		foreach ( $units as $unit ) {
			$value = $value / 1024;

			if ( $value < 1024 || 'GB' === $unit ) {
				return ( $value < 10 ? number_format( $value, 1 ) : number_format( $value, 0 ) ) . ' ' . $unit;
			}
		}

		return $bytes . ' B';
	}
}
