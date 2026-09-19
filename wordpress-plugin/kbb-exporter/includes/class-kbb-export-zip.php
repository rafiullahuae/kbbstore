<?php
/**
 * One downloadable zip per group.
 *
 * ============================================================================
 * THE OWNER ASKED FOR THE OUTPUT, NOT THE SELECTION
 * ============================================================================
 *
 * Lane GK gave him eight tickable groups. His reply:
 *
 *   "you didn't group. please group it, allow to download each group seperate
 *    files. so will have no any heavy file. please do it properly and group
 *    these according."
 *
 * The selection was already there. What was not was the OUTPUT: every group
 * wrote its CSVs into one folder under wp-content/uploads/kbb-export/<id>/ and
 * the screen's instruction was "download the folder over FTP". That is one
 * heavy thing, fetched with a second tool, by somebody who has no shell and did
 * not ask for an FTP client. This class is the other half: one zip per group,
 * fetched from the browser, each one small enough not to be an event.
 *
 * ── EACH ZIP IS INDEPENDENTLY IMPORTABLE, WHICH IS THE WHOLE DESIGN ─────────
 *
 * A zip that is a slice of a folder is not a deliverable, it is a chore: the
 * operator would have to unpack eight of them into one directory before the new
 * shop could read any of it, and the first one he unpacked in the wrong order
 * would cost him the customer rows docs/FV-IMPORT-AT-VOLUME.md section 6
 * measured. So every zip carries ITS OWN manifest.json describing ONLY its own
 * files, which makes it a complete kbb-export/1 export in its own right:
 * unpack it into an empty folder, point `kbb:import` at that folder, done.
 *
 * The thing that stops eight complete exports from reading as eight unrelated
 * exports is that they all carry the SAME `export_id`. docs/WP-EXPORT-CONTRACT.md:
 * "export_id identifies one export". These are parts of one export and they say
 * so with the one field the contract already reserves for saying it.
 *
 * ── THE ARCHIVE SHAPE, STATED ONCE, FOR LANE GM ────────────────────────────
 *
 * Lane GM is teaching the Laravel import screen to accept a zip, so the shape
 * is a contract between two lanes and is written down rather than discovered:
 *
 *   - Entries sit at the ARCHIVE ROOT. There is NO wrapping directory.
 *   - The entries are that group's CSV files under their contract names --
 *     `orders.csv`, `order_items.csv` and so on -- plus `manifest.json`.
 *   - Nothing else is in the archive. No index.php, no .htaccess, no uploads.
 *
 * So `unzip -d /somewhere kbb-export-sales-8f14e45f.zip` produces exactly the
 * directory layout ImportRunner already takes, with no path fixing.
 *
 * ── ZipArchive, AND WHAT HAPPENS WHERE IT IS ABSENT ────────────────────────
 *
 * No Composer dependency and no build step: the plugin installs by uploading a
 * zip through Plugins -> Add New, which is the only door this host has. PHP's
 * own ZipArchive is therefore the only sane choice, and it ships with nearly
 * every PHP build -- but "nearly" is not "every", and a shared host with
 * ext-zip disabled is a real thing.
 *
 * Where it is absent this class does not fatal and does not pretend. available()
 * answers false, the runner skips the zip phase instead of dying halfway through
 * an export that has already written every CSV correctly, and the screen prints
 * one sentence naming the missing extension and falls back to the FTP
 * instruction that was the only route before this lane. A missing zip extension
 * costs the convenience; it must not cost the export.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Zip {

	/**
	 * The UNCOMPRESSED bytes one part may carry before a group is written in
	 * numbered parts.
	 *
	 * ── RAW BYTES, NOT ZIP BYTES, AND THAT IS THE AWKWARD PART ─────────────
	 *
	 * What the owner cares about is the size of the DOWNLOAD, and the size of
	 * the download is not knowable until the compression has been done -- by
	 * which time splitting means doing it all again. So the cap is on the input,
	 * and the number is chosen from the measured ratio rather than picked.
	 *
	 * ── THE MEASUREMENT ────────────────────────────────────────────────────
	 *
	 * wordpress-plugin/harness/volume.php builds every CSV at the real shop's
	 * volume -- 671 products, 4,159 orders, 10,571 line items, 3,712 customers,
	 * 2,514 reviews -- and zips each group through this class. At 3 KB product
	 * descriptions the whole folder is 15.85 MB and the ratios are:
	 *
	 *     Catalogue                3.33 MB raw -> 710 KB zip   20.8%
	 *     Orders                   7.98 MB raw -> 2.16 MB zip  27.1%
	 *     Customers                1.75 MB raw -> 638 KB zip   35.6%   <- worst
	 *     Reviews                  1.42 MB raw -> 366 KB zip   25.2%
	 *     Addresses and pictures   1.18 MB raw -> 252 KB zip   20.9%
	 *
	 * docs/GL-GROUP-DOWNLOADS.md section 3 has the whole table and the
	 * sensitivity run. Customers is the worst ratio and it is obvious why: a
	 * bcrypt hash per row is 60 characters of base64 that deflate cannot do
	 * anything with at all. Nothing else in the export has that shape, so 35.6%
	 * is the ceiling and not a midpoint -- rounded to 40% for a shop that is not
	 * this one.
	 *
	 * 25 MiB raw at 40% is a 10 MB download, which is the largest thing worth
	 * handing somebody on shared hosting in one piece. It also leaves the real
	 * shop's largest group, Orders at 7.98 MB raw, comfortably in one part --
	 * which is the finding, and section 3 states it as one: NOTHING IN THIS SHOP
	 * NEEDS SPLITTING TODAY.
	 *
	 * The splitter is kept anyway, and not because it might be needed one day.
	 * The cap is the only thing standing between this feature and its own
	 * failure mode: a browser that gives up part way through a 40 MB response on
	 * a shared host's timeout, which looks to the owner exactly like the export
	 * having failed, and which arrives without warning the first time the shop
	 * has grown enough. A guard that has never fired is doing its job.
	 */
	const PART_CAP_BYTES = 26214400; // 25 MiB of CSV

	/** Is the zip extension here at all? */
	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Why not, in a sentence the owner can act on.
	 *
	 * @return string '' when it is available.
	 */
	public static function unavailable_reason() {
		if ( self::available() ) {
			return '';
		}

		return 'This PHP has no zip extension (ext-zip), so per-group downloads are not available. '
			. 'The export itself is unaffected: every CSV is written to the folder named above and can '
			. 'still be fetched over FTP. Ask your host to enable ext-zip if you would rather download '
			. 'from this screen.';
	}

	/**
	 * The file name a group's zip gets.
	 *
	 * The group is in the name because the owner asked for it to be -- he will
	 * have several of these in his Downloads folder at once and "export.zip (3)"
	 * is not a thing anybody can import in the right order. The export id's
	 * first block is in it because two exports of the same group on the same day
	 * are otherwise the same file name, and the browser silently renames the
	 * second one.
	 *
	 * @param string $group
	 * @param string $export_id
	 * @param int    $part  1-based; 0 or 1 when the group is not split.
	 * @param int    $parts total parts.
	 * @return string
	 */
	public static function zip_name( $group, $export_id, $part = 1, $parts = 1 ) {
		$short = substr( preg_replace( '/[^a-f0-9]/', '', (string) $export_id ), 0, 8 );
		$name  = 'kbb-export-' . $group . '-' . $short;

		if ( $parts > 1 ) {
			$name .= '-part' . $part . 'of' . $parts;
		}

		return $name . '.zip';
	}

	/**
	 * The work the zip phase has to do, as a flat list of bounded units.
	 *
	 * ONE UNIT IS ONE FILE INTO ONE ARCHIVE, and that is the whole reason this
	 * is a list rather than a loop. The runner's CSV phase already answers one
	 * batch per HTTP request because this host kills a request at 110 seconds;
	 * a zip phase that compressed 10,571 order lines in the request that
	 * finished the export would put the timeout back exactly where the batching
	 * took it from. So the browser drives this the same way it drives the
	 * export, and the largest thing any single request has to survive is
	 * compressing one CSV.
	 *
	 * The units are ordered by group, in the declared group order, so a zip
	 * phase interrupted half way has WHOLE zips for the early groups rather than
	 * eight half-written ones -- the same reasoning that puts the catalogue
	 * first in the stage list.
	 *
	 * @param array<string,mixed> $manifest the whole export's manifest
	 * @return array<int,array<string,mixed>>
	 */
	public static function plan( array $manifest ) {
		$written = isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array();
		$units   = array();

		foreach ( KBB_Export_Groups::all() as $key => $group ) {
			/*
			 * ONLY THE FILES THIS EXPORT ACTUALLY WROTE. A group that was not
			 * ticked has no files in `files` -- that is the contract's
			 * absent-versus-"rows": 0 distinction, which Lane GK made
			 * load-bearing -- so it gets no zip at all, and the screen says so
			 * rather than offering a button that 404s.
			 */
			$files = array();

			foreach ( $group['files'] as $file ) {
				if ( isset( $written[ $file ] ) ) {
					$files[] = $file;
				}
			}

			if ( empty( $files ) ) {
				continue;
			}

			$parts = self::parts_for( $files, $written );

			foreach ( $parts as $index => $part_files ) {
				foreach ( $part_files as $file ) {
					$units[] = array(
						'group' => $key,
						'part'  => $index + 1,
						'parts' => count( $parts ),
						'file'  => $file,
					);
				}

				// The manifest goes in LAST, after every file it describes is
				// closed, which is the contract's rule for the folder and is no
				// less true of the archive. A zip whose manifest is present is a
				// zip that finished.
				$units[] = array(
					'group' => $key,
					'part'  => $index + 1,
					'parts' => count( $parts ),
					'file'  => 'manifest.json',
				);
			}
		}

		return $units;
	}

	/**
	 * How a group's files divide into parts.
	 *
	 * NOTHING IN THIS SHOP NEEDS THIS TODAY and the measurement in
	 * docs/GL-GROUP-DOWNLOADS.md says so with figures. What it does is bound the
	 * failure for a shop that has grown past the cap: the group's files are
	 * distributed across numbered parts, whole files at a time, largest first,
	 * so each part stays under the cap and each part is still a set of COMPLETE
	 * CSVs.
	 *
	 * WHOLE FILES, NEVER HALF OF ONE. Splitting orders.csv down the middle and
	 * putting order_items.csv beside one half would produce a part whose line
	 * items point at orders that are not in it -- OrderItemImporter would import
	 * them against nothing, which is the null product_id failure one level up.
	 * A file that is on its own larger than the cap therefore gets its own part
	 * and exceeds it, and the screen says the part is large rather than
	 * producing something that imports wrongly. A slow download is recoverable;
	 * a wrong import is not.
	 *
	 * @param array<int,string>   $files
	 * @param array<string,mixed> $written  manifest `files` block
	 * @return array<int,array<int,string>>
	 */
	public static function parts_for( array $files, array $written ) {
		$total = 0;

		foreach ( $files as $file ) {
			$total += isset( $written[ $file ]['bytes'] ) ? (int) $written[ $file ]['bytes'] : 0;
		}

		// The common case, and on this shop the only case: everything fits.
		// Compressed it will be a fraction of this, so comparing the RAW bytes
		// against the cap is deliberately pessimistic -- it splits sooner than
		// it has to rather than discovering the size after the work is done.
		if ( $total <= self::PART_CAP_BYTES || count( $files ) < 2 ) {
			return array( $files );
		}

		$ordered = $files;

		usort(
			$ordered,
			static function ( $a, $b ) use ( $written ) {
				$left  = isset( $written[ $a ]['bytes'] ) ? (int) $written[ $a ]['bytes'] : 0;
				$right = isset( $written[ $b ]['bytes'] ) ? (int) $written[ $b ]['bytes'] : 0;

				return $right - $left;
			}
		);

		$parts = array();

		foreach ( $ordered as $file ) {
			$bytes  = isset( $written[ $file ]['bytes'] ) ? (int) $written[ $file ]['bytes'] : 0;
			$placed = false;

			foreach ( $parts as $index => $part ) {
				$used = 0;

				foreach ( $part as $existing ) {
					$used += isset( $written[ $existing ]['bytes'] ) ? (int) $written[ $existing ]['bytes'] : 0;
				}

				if ( $used + $bytes <= self::PART_CAP_BYTES ) {
					$parts[ $index ][] = $file;
					$placed            = true;
					break;
				}
			}

			if ( ! $placed ) {
				$parts[] = array( $file );
			}
		}

		/*
		 * Back into the export's own file order within each part. The order the
		 * files were SIZED in is not the order they should be listed in: the
		 * manifest and the screen both read better in the order the runner
		 * wrote them, and a part listing order_items.csv before orders.csv
		 * invites exactly the wrong reading of what to import first.
		 */
		$out = array();

		foreach ( $parts as $part ) {
			$ordered_part = array();

			foreach ( $files as $file ) {
				if ( in_array( $file, $part, true ) ) {
					$ordered_part[] = $file;
				}
			}

			$out[] = $ordered_part;
		}

		return $out;
	}

	/**
	 * The manifest ONE zip carries: its own files, and nothing else's.
	 *
	 * ── WHAT IS COPIED AND WHY ─────────────────────────────────────────────
	 *
	 * `format`, `export_id`, `generated_at` and `source` are copied UNCHANGED.
	 * They are facts about the export, not about the slice -- and `export_id`
	 * being identical across every zip of one export is the single field that
	 * tells the new shop these are parts of one thing rather than eight
	 * unrelated exports. docs/WP-EXPORT-CONTRACT.md already says export_id
	 * identifies one export; this is that sentence being used, not extended.
	 *
	 * `files` and `counts` are NARROWED to this part's files. That is the
	 * contract's own absent-versus-zero rule applied one level down: a zip that
	 * does not carry customers.csv must not list it with "rows": 0, because the
	 * shop reads that as "the shop has no customers" and it means "this archive
	 * does not carry them".
	 *
	 * `groups` is rewritten from this zip's point of view -- selected is this
	 * group alone, skipped is every other group -- because that is what an
	 * importer pointed at this folder is looking at. The whole export's own
	 * selection is not lost: it is in `zip.of_export` below, which is what
	 * answers "what else should I be looking for".
	 *
	 * @param array<string,mixed> $whole
	 * @param string              $group
	 * @param array<int,string>   $files
	 * @param int                 $part
	 * @param int                 $parts
	 * @return array<string,mixed>
	 */
	public static function group_manifest( array $whole, $group, array $files, $part = 1, $parts = 1 ) {
		$all       = KBB_Export_Groups::all();
		$label     = isset( $all[ $group ]['label'] ) ? $all[ $group ]['label'] : $group;
		$export_id = isset( $whole['export_id'] ) ? (string) $whole['export_id'] : '';

		$mine   = array();
		$counts = array();

		foreach ( $files as $file ) {
			if ( isset( $whole['files'][ $file ] ) ) {
				$mine[ $file ] = $whole['files'][ $file ];
			}

			if ( isset( $whole['counts'][ self::entity_for( $file ) ] ) ) {
				$counts[ self::entity_for( $file ) ] = $whole['counts'][ self::entity_for( $file ) ];
			}
		}

		$of_export = isset( $whole['groups']['selected'] ) ? (array) $whole['groups']['selected'] : array( $group );

		$manifest = array(
			'format'       => isset( $whole['format'] ) ? $whole['format'] : KBB_Export_Runner::FORMAT,
			'export_id'    => $export_id,
			'generated_at' => isset( $whole['generated_at'] ) ? $whole['generated_at'] : '',
			'source'       => isset( $whole['source'] ) ? $whole['source'] : array(),
			'files'        => $mine,
			'counts'       => $counts,
			'groups'       => array(
				'selected' => array( $group ),
				'skipped'  => array_values( array_diff( KBB_Export_Groups::keys(), array( $group ) ) ),
				'files'    => array_values( $files ),
				/*
				 * The operator's claims about the other shop travel with every
				 * zip, not only with the one they were made against. He may
				 * import these on different days; the claim that customers were
				 * already in the new shop is the reason this export has orders
				 * and no customers, and it has to be readable from whichever
				 * archive he opens first.
				 */
				'assumed_already_imported' => isset( $whole['groups']['assumed_already_imported'] )
					? $whole['groups']['assumed_already_imported']
					: array(),
			),
			/*
			 * WHICH PART OF WHAT THIS IS. Permitted by the contract's own rule
			 * -- "Unknown keys are ignored, never fatal" -- and read by nothing
			 * today. It exists so that a person or a future importer holding one
			 * archive can answer "is there more of this, and how much" without
			 * being told; docs/GL-GROUP-DOWNLOADS.md section 7 is the note to
			 * the integrator about whether the shop should act on it.
			 */
			'zip'          => array(
				'group'       => $group,
				'group_label' => $label,
				'part'        => (int) $part,
				'parts'       => (int) $parts,
				'of_export'   => array_values( $of_export ),
				'archive'     => self::zip_name( $group, $export_id, $part, $parts ),
			),
			'notes'        => self::notes_for( $whole, $group, $label, $files, $part, $parts, $of_export ),
		);

		return $manifest;
	}

	/**
	 * The same facts in the words the owner reads on the import screen.
	 *
	 * The whole export's notes are KEPT -- they are facts about the shop (where
	 * the orders were read from, how many trashed products were held back) and
	 * they are no less true of a slice of it. What is prepended is the one fact
	 * that is only true of the slice: that this archive is one group of a larger
	 * export, which others exist, and that they share an id.
	 *
	 * @return array<int,string>
	 */
	private static function notes_for( array $whole, $group, $label, array $files, $part, $parts, array $of_export ) {
		$all   = KBB_Export_Groups::all();
		$notes = array();

		$sentence = 'This archive carries ONE GROUP of export ' . ( isset( $whole['export_id'] ) ? $whole['export_id'] : '' )
			. ': ' . $label . ' (' . implode( ', ', $files ) . ').';

		if ( $parts > 1 ) {
			$sentence .= ' It is part ' . $part . ' of ' . $parts . ' for this group; the other parts carry the'
				. ' rest of its files and each is a complete import of its own.';
		}

		$others = array();

		foreach ( $of_export as $key ) {
			if ( $key !== $group ) {
				$others[] = isset( $all[ $key ]['label'] ) ? $all[ $key ]['label'] : $key;
			}
		}

		if ( ! empty( $others ) ) {
			$sentence .= ' The same export also produced: ' . implode( ', ', $others ) . '.'
				. ' Every archive of this export carries the same export_id, which is how this shop can tell'
				. ' they are parts of one export rather than several. Import them in the order they are listed'
				. ' on the export screen -- that order is not cosmetic, and importing orders before the'
				. ' customers they belong to costs customer rows.';
		} else {
			$sentence .= ' It is the only group this export carries.';
		}

		$notes[] = $sentence;

		foreach ( isset( $whole['notes'] ) ? (array) $whole['notes'] : array() as $note ) {
			$notes[] = $note;
		}

		return $notes;
	}

	/** The `counts` key for a file, which is the entity name the shop uses. */
	private static function entity_for( $file ) {
		return preg_replace( '/\.csv$/', '', $file );
	}

	/**
	 * Do ONE unit: put one file into one group's archive.
	 *
	 * Opening the archive, adding one entry and closing it again per request is
	 * deliberate and it is not the expensive thing it looks like. libzip copies
	 * the entries that are already there as COMPRESSED BYTES and only compresses
	 * the one being added, so the cost of a unit is one file's compression plus
	 * a copy of what the archive already weighs -- bounded, small, and it cannot
	 * grow into the request limit the way compressing a whole group in one go
	 * can.
	 *
	 * @param string              $dir      the export folder
	 * @param array<string,mixed> $manifest the whole export's manifest
	 * @param array<string,mixed> $unit     one entry from plan()
	 * @return array{ok: bool, error: string, archive: string}
	 */
	public static function add( $dir, array $manifest, array $unit ) {
		if ( ! self::available() ) {
			return array( 'ok' => false, 'error' => self::unavailable_reason(), 'archive' => '' );
		}

		$export_id = isset( $manifest['export_id'] ) ? (string) $manifest['export_id'] : '';
		$archive   = self::zip_name( $unit['group'], $export_id, $unit['part'], $unit['parts'] );
		$path      = rtrim( $dir, '/' ) . '/' . $archive;

		$zip  = new ZipArchive();
		$open = $zip->open( $path, ZipArchive::CREATE );

		if ( true !== $open ) {
			return array(
				'ok'      => false,
				'error'   => 'Could not open ' . $archive . ' for writing (ZipArchive code ' . $open . '). '
					. 'Check the uploads folder is writable and has space.',
				'archive' => $archive,
			);
		}

		if ( 'manifest.json' === $unit['file'] ) {
			/*
			 * THE MANIFEST IS BUILT, NOT COPIED. Copying the export's own
			 * manifest into every zip would put customers.csv in the catalogue
			 * archive's `files` -- a file list describing rows that are not in
			 * the archive, which is the one thing a manifest must never be.
			 */
			$files = self::files_in_part( $manifest, $unit['group'], $unit['part'] );

			$json = json_encode(
				self::group_manifest( $manifest, $unit['group'], $files, $unit['part'], $unit['parts'] ),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);

			$zip->addFromString( 'manifest.json', $json . "\n" );
		} else {
			$source = rtrim( $dir, '/' ) . '/' . $unit['file'];

			if ( ! file_exists( $source ) ) {
				$zip->close();

				return array(
					'ok'      => false,
					'error'   => $unit['file'] . ' is named in the manifest but is not on disk.',
					'archive' => $archive,
				);
			}

			/*
			 * NO DIRECTORY INSIDE THE ARCHIVE. addFile()'s second argument is
			 * the entry name and it is the base name on purpose: Lane GM's
			 * import screen unpacks this into a folder and points ImportRunner
			 * at it, and a wrapping directory would mean every consumer has to
			 * know to descend one level. See the class comment -- this is the
			 * half of the shape that is a contract between two lanes.
			 */
			$zip->addFile( $source, $unit['file'] );
		}

		$zip->close();

		return array( 'ok' => true, 'error' => '', 'archive' => $archive );
	}

	/**
	 * The files one part of one group carries, recomputed from the manifest.
	 *
	 * Recomputed rather than carried in the unit, so that the manifest written
	 * into the archive and the files put into it cannot disagree: both come
	 * from parts_for() reading the same `files` block.
	 *
	 * @return array<int,string>
	 */
	public static function files_in_part( array $manifest, $group, $part ) {
		$all     = KBB_Export_Groups::all();
		$written = isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array();

		if ( ! isset( $all[ $group ] ) ) {
			return array();
		}

		$files = array();

		foreach ( $all[ $group ]['files'] as $file ) {
			if ( isset( $written[ $file ] ) ) {
				$files[] = $file;
			}
		}

		$parts = self::parts_for( $files, $written );
		$index = max( 1, (int) $part ) - 1;

		return isset( $parts[ $index ] ) ? $parts[ $index ] : array();
	}

	/**
	 * What a group's download looks like right now, for the screen.
	 *
	 * THE SCREEN MUST BE HONEST ABOUT WHAT IT HAS. Three states, and they are
	 * three because a button that 404s is worse than no button: the owner presses
	 * it, gets WordPress's error page, and has no way of telling whether the
	 * export failed, the group was never ticked, or the file has been deleted.
	 *
	 *   absent    -- the group was not in this export. There is nothing to
	 *                download and the screen says which groups were.
	 *   building  -- the export is done and the zip phase has not reached this
	 *                group. It will arrive; the button is not pressable yet.
	 *   ready     -- the archive is on disk and its size is known.
	 *
	 * @param string              $dir
	 * @param array<string,mixed> $manifest
	 * @return array<string,array<string,mixed>>
	 */
	public static function status( $dir, array $manifest ) {
		$out   = array();
		$plan  = self::plan( $manifest );
		$known = array();

		foreach ( $plan as $unit ) {
			$known[ $unit['group'] ][ $unit['part'] ] = (int) $unit['parts'];
		}

		foreach ( KBB_Export_Groups::all() as $key => $group ) {
			if ( ! isset( $known[ $key ] ) ) {
				$out[ $key ] = array(
					'key'    => $key,
					'label'  => $group['label'],
					'state'  => 'absent',
					'why'    => $group['label'] . ' was not in this export, so there is no file to download.',
					'parts'  => array(),
				);

				continue;
			}

			$parts = array();
			$ready = 0;

			foreach ( $known[ $key ] as $part => $total ) {
				$archive = self::zip_name( $key, isset( $manifest['export_id'] ) ? $manifest['export_id'] : '', $part, $total );
				$path    = rtrim( $dir, '/' ) . '/' . $archive;
				$here    = file_exists( $path );

				if ( $here ) {
					$ready++;
				}

				$parts[] = array(
					'part'    => (int) $part,
					'parts'   => (int) $total,
					'archive' => $archive,
					'ready'   => $here,
					'bytes'   => $here ? (int) filesize( $path ) : 0,
					'files'   => self::files_in_part( $manifest, $key, $part ),
				);
			}

			$out[ $key ] = array(
				'key'   => $key,
				'label' => $group['label'],
				'state' => $ready === count( $parts ) ? 'ready' : 'building',
				'why'   => $ready === count( $parts ) ? '' : 'Still being packed.',
				'parts' => $parts,
			);
		}

		return $out;
	}
}
