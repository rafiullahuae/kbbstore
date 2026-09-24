<?php
/**
 * The admin screen: Tools -> KBB Export.
 *
 * ── ONE BATCH PER REQUEST, DRIVEN FROM THE BROWSER ──────────────────────────
 *
 * The owner's WordPress host has no shell, so there is no WP-CLI and no cron
 * worth relying on. The browser is the scheduler: it POSTs `kbb_export_step`,
 * the server does ONE batch and answers with the progress, and the page POSTs
 * again. A request that dies -- a 110-second limit, a dropped connection, a
 * closed laptop -- costs one batch, and pressing Resume picks up from the
 * option the last successful batch wrote.
 *
 * ── THE THREE STATES ARE DISTINCT, WHICH IS THE LESSON FROM LANE GD ─────────
 *
 * docs/GD-MEDIA-SIDELOADER.md: "IDLE, RUNNING and STALLED are three distinct
 * states on its progress page rather than one frozen bar." The same three are
 * here. A bar that has not moved because the export finished and a bar that has
 * not moved because the request died look identical unless somebody makes them
 * different, and the second one is the only one that needs a person.
 *
 * ── CAPABILITY AND NONCE ON EVERY ENDPOINT ──────────────────────────────────
 *
 * This export writes customers.csv, which holds every shopper's address and
 * password hash. `manage_woocommerce` is the capability a shop manager has and
 * a subscriber does not; the nonce stops a page on another site from POSTing
 * the export into existence in an administrator's browser. Both are checked on
 * both endpoints, not just on the screen that draws the button.
 */

defined( 'ABSPATH' ) || exit;

class KBB_Export_Admin {

	const CAPABILITY = 'manage_woocommerce';
	const NONCE      = 'kbb_export';

	/*
	 * A SEPARATE NONCE FOR THE DOWNLOAD, and it is separate on purpose.
	 *
	 * The export nonce travels in a POST body that the page holds for as long as
	 * the tab is open. The download is a GET -- it has to be, because a browser
	 * saves a navigation and not a fetch -- so its nonce ends up in a URL, which
	 * is a place URLs get: the history, a referrer, a screenshot, an over-the-
	 * shoulder photograph of the thing he is asking for help with. Giving it its
	 * own action means a leaked download URL cannot be replayed as a request to
	 * START an export, and vice versa. WordPress's own admin does the same thing
	 * for the same reason.
	 */
	const DOWNLOAD_NONCE = 'kbb_export_download';

	/*
	 * ── THE DELETE HAS A CAPABILITY AND A NONCE OF ITS OWN ──────────────────
	 *
	 * `manage_woocommerce` is the right answer for running and downloading an
	 * export: it is what a shop manager has, and the export is a shop manager's
	 * job. It is the WRONG answer for destroying one. The export is the only
	 * copy of a several-minute run over the whole shop, and on cutover day it is
	 * the only copy of the shop's data that is not on the site being switched
	 * off. Deleting it is not a shop-management task, it is an administrator's.
	 *
	 * So the destructive door gets its own capability, strictly narrower than
	 * the one that opens the rest of the screen:
	 *
	 *   `kbb_export_delete`  a capability of this plugin's own, so a site can
	 *                        grant exactly this to exactly one person without
	 *                        granting anything else;
	 *   `delete_users`       the fallback, and it is deliberately not
	 *                        `manage_options`: `delete_users` is administrator-
	 *                        only in core WordPress and a shop manager does not
	 *                        have it, which is the line being drawn.
	 *
	 * It FAILS CLOSED. A user with neither gets 403 from the endpoint, not a
	 * hidden button -- add_management_page() decides what is in a menu and not
	 * what answers a URL, which is the same reason download() re-checks.
	 *
	 * And its own nonce, for DOWNLOAD_NONCE's reason one step further: a leaked
	 * URL or a replayed body that could START an export is a nuisance; one that
	 * could DELETE one is the incident.
	 */
	const DELETE_CAPABILITY = 'kbb_export_delete';

	const DELETE_FALLBACK_CAPABILITY = 'delete_users';

	const PURGE_NONCE = 'kbb_export_purge';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_kbb_export_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_kbb_export_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_kbb_export_zip', array( __CLASS__, 'ajax_zip' ) );
		add_action( 'wp_ajax_kbb_export_reset', array( __CLASS__, 'ajax_reset' ) );
		add_action( 'wp_ajax_kbb_export_purge', array( __CLASS__, 'ajax_purge' ) );

		/*
		 * admin-post.php and NOT admin-ajax.php, and not a URL under uploads.
		 *
		 * `admin_post_<action>` is reached only by a logged-in user -- the
		 * nopriv twin, which this deliberately does not register, is the hook a
		 * logged-out request lands on -- and the handler then checks the
		 * capability and the nonce itself rather than trusting that. A link
		 * straight into wp-content/uploads would be none of those things: it is
		 * served by the web server with no WordPress in the path, so the only
		 * thing standing between a stranger and every shopper's password hash
		 * would be nobody having guessed the folder name.
		 */
		add_action( 'admin_post_kbb_export_download', array( __CLASS__, 'download' ) );
	}

	public static function menu() {
		add_management_page(
			'KBB Export',
			'KBB Export',
			self::CAPABILITY,
			'kbb-export',
			array( __CLASS__, 'screen' )
		);
	}

	private static function guard() {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'You do not have permission to run this export.' ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	public static function ajax_start() {
		self::guard();

		$runner = new KBB_Export_Runner( self::settings_from_request() );
		$runner->reset();

		$runner = new KBB_Export_Runner( self::settings_from_request() );
		$result = $runner->start();

		if ( ! $result['ok'] ) {
			wp_send_json( array( 'ok' => false, 'error' => $result['error'] ) );
		}

		wp_send_json( $runner->progress() );
	}

	public static function ajax_step() {
		self::guard();

		/*
		 * A thrown exception here would be a 500 with WordPress's own HTML in
		 * it, which the screen's fetch() reads as a parse failure and reports
		 * as "stalled" -- true, but with no reason on it. Caught and returned
		 * as the error the screen already knows how to print.
		 */
		try {
			$runner = new KBB_Export_Runner( self::settings_from_request() );

			wp_send_json( $runner->step() );
		} catch ( Exception $e ) { // phpcs:ignore
			wp_send_json( array( 'ok' => false, 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * ONE BOUNDED UNIT OF THE ZIP PHASE, driven by the browser exactly as the
	 * export's batches are. See KBB_Export_Runner::zip_step().
	 */
	public static function ajax_zip() {
		self::guard();

		try {
			$runner = new KBB_Export_Runner( self::settings_from_request() );

			wp_send_json( $runner->zip_step() );
		} catch ( Exception $e ) { // phpcs:ignore
			wp_send_json( array( 'ok' => false, 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Send one group's archive to the browser.
	 *
	 * ============================================================================
	 * THIS IS THE ENDPOINT THAT HANDS OVER EVERY SHOPPER'S PASSWORD HASH
	 * ============================================================================
	 *
	 * customers.csv carries every shopper's address and their WordPress password
	 * hash; reviews.csv carries the reviewer's email and the IP they posted from
	 * -- the exact pair this repository has already had leak out of an
	 * unauthenticated /api/* route. So four things, and none of them is
	 * sufficient alone:
	 *
	 *  1. CAPABILITY. manage_woocommerce is what a shop manager has and a
	 *     subscriber does not. Checked here and not merely on the menu entry,
	 *     because add_management_page() decides what is in a menu and not what
	 *     answers a URL.
	 *
	 *  2. NONCE, its own (see DOWNLOAD_NONCE). Without it a page on another site
	 *     can put <img src="...admin-post.php?action=kbb_export_download..."> in
	 *     front of a logged-in administrator and read the response.
	 *
	 *  3. NO PATH FROM THE REQUEST. The request names a group and a part. The
	 *     folder comes from the runner's own state and the file name is computed
	 *     from that state's export id -- see KBB_Export_Runner::archive_path().
	 *     Nothing the browser sent is concatenated into a path, so there is no
	 *     traversal to sanitise rather than a sanitiser to get right.
	 *
	 *  4. THE FOLDER STAYS DENIED. The archive is written INSIDE
	 *     uploads/kbb-export/<id>/, which already carries index.php and whose
	 *     parent carries a deny-all .htaccess. Putting the zip anywhere the web
	 *     server would serve it -- the uploads root, a "public" folder -- would
	 *     hand back with one hand what the guard took with the other. The test
	 *     `it does not undo the folder guard by adding an archive to it` reads
	 *     the guard files back AFTER the archives are written.
	 *
	 * ── AND IT STREAMS ─────────────────────────────────────────────────────
	 *
	 * file_get_contents() on a 40 MB archive is 40 MB of PHP memory on a shared
	 * host whose limit is frequently 128 MB and is shared with whatever else the
	 * request loaded. Read in 8 KB chunks with the buffers torn down first, so
	 * the memory cost is the chunk and not the file -- and so the download
	 * starts moving immediately rather than after the whole thing is in RAM,
	 * which is also the difference between a progress bar and a hung browser.
	 */
	public static function download() {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to download this export.', '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';

		if ( ! wp_verify_nonce( $nonce, self::DOWNLOAD_NONCE ) ) {
			wp_die( 'That download link has expired. Reload the export screen and try again.', '', array( 'response' => 403 ) );
		}

		$group = isset( $_GET['group'] ) ? preg_replace( '/[^a-z_]/', '', strtolower( (string) $_GET['group'] ) ) : '';
		$part  = isset( $_GET['part'] ) ? (int) $_GET['part'] : 1;

		$found = ( new KBB_Export_Runner() )->archive_path( $group, $part );

		if ( ! $found['ok'] ) {
			/*
			 * ONE ANSWER FOR EVERY WAY OF NOT HAVING IT. A group that was never
			 * exported, a part that was never planned and a group key that does
			 * not exist all get the same sentence and the same status, so the
			 * endpoint cannot be used to ask which groups this shop exported.
			 * The screen already knows all of it and says so there, where the
			 * person asking is the person entitled to the answer.
			 */
			wp_die( esc_html( $found['error'] ), '', array( 'response' => 404 ) );
		}

		// Every buffer torn down before a byte goes out, or the "stream" is a
		// buffer that holds the whole file anyway. @ because a host with
		// output_buffering off has none to close and says so loudly.
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore
		}

		nocache_headers();

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $found['name'] . '"' );
		header( 'Content-Length: ' . filesize( $found['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );

		$handle = fopen( $found['path'], 'rb' );

		if ( false === $handle ) {
			wp_die( 'The archive could not be opened for reading.', '', array( 'response' => 500 ) );
		}

		while ( ! feof( $handle ) ) {
			echo fread( $handle, 8192 ); // phpcs:ignore

			flush();
		}

		fclose( $handle );

		exit;
	}

	/**
	 * The URL for one group's archive, nonce and all.
	 *
	 * Built here rather than in the page's JavaScript so that the nonce is
	 * minted by WordPress on the server for the action it actually guards.
	 */
	public static function download_url( $group, $part = 1 ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=kbb_export_download&group=' . rawurlencode( $group ) . '&part=' . (int) $part ),
			self::DOWNLOAD_NONCE
		);
	}

	/**
	 * FORGET THE RUN. This deletes no file, and the name says so.
	 *
	 * Left exactly as it was, and documented here because it is the trap: wired
	 * to a button labelled Delete it would answer `ok`, reset the bar, and
	 * leave every shopper's password hash on the server with nothing on the
	 * screen still referring to it. ajax_purge() is the one that deletes.
	 */
	public static function ajax_reset() {
		self::guard();

		$runner = new KBB_Export_Runner();
		$runner->reset();

		wp_send_json( array( 'ok' => true, 'error' => '' ) );
	}

	/** Can the current user destroy an export? See DELETE_CAPABILITY. */
	private static function can_delete() {
		return current_user_can( self::DELETE_CAPABILITY )
			|| current_user_can( self::DELETE_FALLBACK_CAPABILITY );
	}

	/**
	 * DELETE THE EXPORT FROM THE SERVER.
	 *
	 * Three things stand in front of it and none of them is sufficient alone:
	 *
	 *  1. THE CAPABILITY, its own and narrower than the screen's. Checked here
	 *     rather than inferred from the button being drawn.
	 *  2. THE NONCE, its own (PURGE_NONCE), so nothing that leaked from the
	 *     export or the download can be replayed into a delete.
	 *  3. THE TYPED CONFIRMATION, checked by KBB_Export_Runner::purge() on the
	 *     server. The page disables the button until the word is typed; that is
	 *     a courtesy to the person using it, not a guard, because the endpoint
	 *     is reachable without the page.
	 *
	 * The answer is the runner's, unaltered, and the runner computes `ok` from
	 * a fresh walk of the folder rather than from how many unlink() calls
	 * succeeded. An endpoint that reported success because it had tried is the
	 * exact failure this whole path exists to avoid.
	 */
	public static function ajax_purge() {
		if ( ! self::can_delete() ) {
			wp_send_json(
				array(
					'ok'    => false,
					'error' => 'You do not have permission to delete the export from this server. It needs the '
						. 'kbb_export_delete capability, which an administrator has.',
				),
				403
			);
		}

		check_ajax_referer( self::PURGE_NONCE, 'nonce' );

		$confirm = isset( $_POST['confirm'] ) ? (string) wp_unslash( $_POST['confirm'] ) : ''; // phpcs:ignore

		$runner = new KBB_Export_Runner();
		$result = $runner->purge( $confirm );

		// Whatever happened, the screen redraws from the disk rather than from
		// what it thinks it just did.
		$result['exports'] = $runner->exports();

		wp_send_json( $result );
	}

	/** @return array<string,mixed> */
	private static function settings_from_request() {
		$batch = isset( $_POST['batch'] ) ? (int) $_POST['batch'] : 200;

		return array(
			// Bounded at both ends. Too small and a 4,000-order shop needs
			// hundreds of round trips; too large and one batch outlives the
			// host's request limit, which is the thing the batching is for.
			'batch'        => max( 10, min( 1000, $batch ) ),
			'skip_trashed' => ! isset( $_POST['include_trashed'] ) || '1' !== (string) $_POST['include_trashed'],
			/*
			 * The ticked groups, and the dependencies the operator confirmed
			 * are already in the new shop. Both are re-checked server side in
			 * KBB_Export_Runner::start(); nothing here trusts the form.
			 *
			 * A request with no `groups` at all gets every group, not none: an
			 * older browser tab, or a POST from anything that predates this
			 * screen, should produce the whole export the plugin has always
			 * produced rather than an empty one.
			 */
			'groups'       => isset( $_POST['groups'] )
				? self::list_from_request( $_POST['groups'] )
				: KBB_Export_Groups::keys(),
			'confirmed'    => isset( $_POST['confirmed'] )
				? self::list_from_request( $_POST['confirmed'] )
				: array(),
		);
	}

	/**
	 * A comma-separated field from the form, as a clean list of keys.
	 *
	 * Only `a-z`, `_` and `:` survive, which is the whole alphabet a group key
	 * and a dependency id are built from. Everything here is compared with
	 * in_array() against lists this plugin declares, so nothing unrecognised can
	 * do anything -- but a value that reaches manifest.json is a value the shop
	 * will read, and it should be one of ours.
	 *
	 * @param mixed $raw
	 * @return array<int,string>
	 */
	private static function list_from_request( $raw ) {
		$out = array();

		foreach ( explode( ',', (string) $raw ) as $piece ) {
			$clean = preg_replace( '/[^a-z_:]/', '', strtolower( trim( $piece ) ) );

			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}

		return array_values( array_unique( $out ) );
	}

	public static function screen() {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}

		$detected = KBB_Export_Orders_Source::detect();
		$runner   = new KBB_Export_Runner();
		$state    = $runner->state();
		$nonce    = wp_create_nonce( self::NONCE );

		?>
		<div class="wrap">
			<h1>KBB Export</h1>

			<p>
				Writes the CSV set the new shop imports into
				<code><?php echo esc_html( KBB_Export_Wp::uploads_dir() . '/kbb-export/' ); ?></code>,
				and then packs <strong>one zip per group</strong> so you can download each one straight from this
				page &mdash; no FTP, and no single heavy file. Each zip is a complete import on its own: unpack it
				and point the new shop at the folder.
			</p>
			<p>
				When you have downloaded them all, <strong>delete the export from the server</strong> &mdash;
				<code>customers.csv</code> holds every shopper&rsquo;s address and password hash and
				<code>reviews.csv</code> holds reviewers&rsquo; email addresses and IPs. The folder is already
				protected (a random name, an <code>index.php</code> and a deny-all <code>.htaccess</code>), and the
				downloads below go through WordPress with your login checked &mdash; but the only completely safe
				copy is the one that is not there. <strong>There is a button for it at the bottom of this
				page</strong>, under &ldquo;Delete the export from this server&rdquo;; you do not need FTP or a
				shell.
			</p>
			<?php if ( ! KBB_Export_Zip::available() ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( KBB_Export_Zip::unavailable_reason() ); ?></p></div>
			<?php endif; ?>

			<h2>Where this shop keeps its orders</h2>
			<?php if ( null === $detected['source'] ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $detected['error'] ); ?></p></div>
			<?php else : ?>
				<p><strong><?php echo esc_html( $detected['source']->describe() ); ?></strong><br>
				<span class="description"><?php echo esc_html( $detected['detail'] ); ?></span></p>
			<?php endif; ?>

			<h2>What to export</h2>
			<p class="description" style="max-width:52em">
				Tick the groups you want. Each one is a set of files that belong together, named the way the
				new shop&rsquo;s <strong>Store &rarr; Import</strong> screen names them, so the two read as one
				system. A group you leave out is <em>absent</em> from <code>manifest.json</code> rather than
				present with zero rows &mdash; the new shop can tell &ldquo;this export does not carry
				coupons&rdquo; from &ldquo;this shop has no coupons&rdquo;.
			</p>

			<table class="widefat striped" id="kbb-groups-table" style="max-width:60em">
				<tbody>
				<?php foreach ( KBB_Export_Groups::all() as $kbb_key => $kbb_group ) : ?>
					<tr>
						<td style="width:2em;vertical-align:top">
							<input type="checkbox" class="kbb-group" id="kbb-group-<?php echo esc_attr( $kbb_key ); ?>"
								value="<?php echo esc_attr( $kbb_key ); ?>" checked>
						</td>
						<td>
							<label for="kbb-group-<?php echo esc_attr( $kbb_key ); ?>">
								<strong><?php echo esc_html( $kbb_group['label'] ); ?></strong>
								&mdash; <?php echo esc_html( $kbb_group['summary'] ); ?>
							</label>
							<p class="description" style="margin:.25em 0 0">
								<?php echo esc_html( $kbb_group['help'] ); ?><br>
								<code><?php echo esc_html( implode( '  ', $kbb_group['files'] ) ); ?></code>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="kbb-all">Tick everything</button>
				<button type="button" class="button" id="kbb-none">Untick everything</button>
			</p>

			<div id="kbb-warnings"></div>

			<h2>Run</h2>
			<?php
			/*
			 * ── ONE DECISION HERE, AND THE PLUMBING IS BELOW ────────────────
			 *
			 * The owner asked, looking at this row: "why you mentioned number
			 * of rows to select? what's the purpose?" -- and he was right to.
			 * Trashed products is a DECISION: he knows whether his trash holds
			 * something he wants, the new shop refuses them either way, and the
			 * answer is his. `Rows per batch` is not a decision, it is how this
			 * screen avoids a host's request limit, and there is no value he
			 * could sensibly pick: docs/GL-GROUP-DOWNLOADS.md measured the real
			 * shop -- 671 products, 4,159 orders, 10,571 line items, 3,712
			 * customers -- and the slowest bounded unit at the default 200 was
			 * 293 ms against a limit that is typically 30 seconds. Two orders of
			 * magnitude of headroom is not a dial, and putting it in the main
			 * flow made plumbing look like something he had to have an opinion
			 * about before pressing Start.
			 *
			 * So it is MOVED, not removed. An unusually strict host is the case
			 * it exists for and that host is real, so it stays reachable, keeps
			 * its 10-1000 bounds and still posts with every request. A
			 * <details> does all of that with no JavaScript and no CSS: the
			 * input is in the DOM whether or not the disclosure was ever opened,
			 * so post()'s getElementById('kbb-batch').value is unchanged and the
			 * default 200 goes out exactly as it did before.
			 *
			 * It sits AFTER the buttons on purpose. It is reached by somebody
			 * who has already pressed Start and watched the export stop, which
			 * is the only moment it is the answer to anything -- and the way out
			 * from there is Resume, which takes the new value on the next batch
			 * because `batch` is deliberately the one setting start() does not
			 * pin. See KBB_Export_Runner::pinned_settings().
			 */
			?>
			<p>
				<label>
					<input type="checkbox" id="kbb-include-trashed">
					Include trashed products and unpublished coupons
					<span class="description">(the new shop refuses both; leave this off unless you know why you want them)</span>
				</label>
			</p>

			<p>
				<button class="button button-primary" id="kbb-start" <?php disabled( null === $detected['source'] ); ?>>Start a new export</button>
				<button class="button" id="kbb-resume" <?php disabled( empty( $state ) ); ?>>Resume</button>
				<button class="button" id="kbb-stop">Pause</button>
				<span id="kbb-blocked" class="description" style="color:#b32d2e"></span>
			</p>

			<details id="kbb-batch-details" style="margin:0 0 1em;max-width:52em">
				<summary style="cursor:pointer">Only if the export keeps stopping before it finishes</summary>
				<div style="margin:.5em 0 0;padding:.75em 1em;border-left:4px solid #c3c4c7;background:#fff">
					<p style="margin-top:0">
						The export is written in small pieces rather than all at once, so that no single piece
						takes long enough for your web host to cut it off part way.
						<strong>Rows per batch</strong> is how many rows go into one piece.
					</p>
					<p>
						<strong>Leave this alone.</strong> On a shop this size the slowest piece takes about a
						third of a second, and hosts normally allow thirty &mdash; so there is nothing to gain by
						changing it. The one time it helps is a host that is stricter than that: if the export
						keeps stopping on its own, make this number smaller and press <strong>Resume</strong>.
						It carries on from where it got to, and nothing already written is lost or done twice.
					</p>
					<p style="margin-bottom:0">
						<label>Rows per batch
							<input type="number" id="kbb-batch" value="200" min="10" max="1000" step="10">
						</label>
					</p>
				</div>
			</details>

			<div id="kbb-state" style="margin:1em 0;padding:.75em 1em;border-left:4px solid #72aee6;background:#fff;">
				<p id="kbb-status"><strong>Idle.</strong> Nothing is running.</p>
				<div style="background:#f0f0f1;height:22px;border-radius:3px;overflow:hidden;max-width:640px;">
					<div id="kbb-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s;"></div>
				</div>
				<p id="kbb-detail" class="description"></p>
				<div id="kbb-group-bars"></div>
			</div>

			<h2>Download</h2>
			<p class="description" id="kbb-download-intro" style="max-width:52em">
				One zip per group. Each one carries its own <code>manifest.json</code> describing only its own
				files, and every zip of one export carries the <strong>same export id</strong> &mdash; which is how
				the new shop can tell these are parts of one export rather than several. Import them in the order
				they are listed above.
			</p>
			<div id="kbb-downloads"></div>

			<div id="kbb-notes"></div>

			<?php
			/*
			 * ── THE DELETE, WHICH IS THE ONLY WAY HE HAS ────────────────────
			 *
			 * The paragraph at the top of this screen has always said to delete
			 * the folder once the download is done, and it was an instruction
			 * to do something he cannot do: no shell, no FTP. This is the
			 * control that makes it true.
			 *
			 * WHAT IS ON THE SERVER IS READ OFF THE DISK, not out of the state
			 * option. The option knows about the export this plugin is part-way
			 * through; the disk knows about the four from last week that were
			 * downloaded and left, which are the ones this section exists for.
			 *
			 * THE BUTTON IS DRAWN ONLY FOR SOMEBODY WHO COULD USE IT, and that
			 * is a courtesy rather than the guard -- ajax_purge() checks the
			 * capability itself, because a menu decides what is in a menu and
			 * not what answers a URL.
			 */
			$kbb_exports = $runner->exports();
			?>

			<h2 id="kbb-delete-heading">Delete the export from this server</h2>

			<?php if ( ! self::can_delete() ) : ?>
				<p class="description" style="max-width:52em">
					Deleting the export needs the <code>kbb_export_delete</code> capability, which an administrator
					has and a shop manager does not. Ask an administrator to open this page and press the button.
				</p>
			<?php else : ?>
				<p class="description" style="max-width:52em">
					This removes the exported files from
					<code><?php echo esc_html( $runner->exports_root() ); ?></code> for good. Download everything
					you need first &mdash; <strong>there is no copy anywhere else</strong> and the export takes
					several minutes to run again. The folder&rsquo;s own <code>index.php</code> and
					<code>.htaccess</code> are left in place; they hold nothing.
				</p>

				<div id="kbb-purge-list"></div>

				<p>
					<label for="kbb-purge-confirm">
						Type <code><?php echo esc_html( KBB_Export_Runner::PURGE_PHRASE ); ?></code> to confirm
					</label><br>
					<input type="text" id="kbb-purge-confirm" value="" autocomplete="off"
						placeholder="<?php echo esc_attr( KBB_Export_Runner::PURGE_PHRASE ); ?>" style="max-width:12em">
					<button type="button" class="button button-link-delete" id="kbb-purge" disabled>
						Delete the export from this server
					</button>
				</p>

				<p id="kbb-purge-said" class="description"></p>
			<?php endif; ?>

			<script>
			(function () {
				var running = false;
				var nonce = <?php echo wp_json_encode( $nonce ); ?>;
				var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var GROUPS = <?php echo wp_json_encode( KBB_Export_Groups::all() ); ?>;
				var DOWNLOAD_BASE = <?php echo wp_json_encode( admin_url( 'admin-post.php?action=kbb_export_download' ) ); ?>;
				var DOWNLOAD_NONCE = <?php echo wp_json_encode( wp_create_nonce( self::DOWNLOAD_NONCE ) ); ?>;
				/*
				 * WHAT THIS SCREEN ALREADY HAS, drawn before anything is clicked.
				 *
				 * He will close this tab. An export that finished on Tuesday and
				 * eight archives sitting in the folder are worth nothing if the
				 * only way to reach them is to have kept the page open since,
				 * so the finished state is rendered from the server on load and
				 * the same function redraws it live.
				 */
				var INITIAL_ZIP = <?php echo wp_json_encode( ! empty( $state['done'] ) ? $runner->zip_progress() : null ); ?>;
				var lastMoved = Date.now();
				var lastDone = -1;

				function ticked() {
					var out = [];
					Array.prototype.forEach.call(document.querySelectorAll('.kbb-group'), function (box) {
						if (box.checked) { out.push(box.value); }
					});
					return out;
				}

				function confirmed() {
					var out = [];
					Array.prototype.forEach.call(document.querySelectorAll('.kbb-confirm'), function (box) {
						if (box.checked) { out.push(box.value); }
					});
					return out;
				}

				/*
				 * THE SAME RULE THE SERVER APPLIES, drawn before the button is
				 * pressable. KBB_Export_Groups::unmet() is the authority and
				 * start() refuses independently of anything here; this exists so
				 * the consequence is READ rather than discovered, which is the
				 * whole point of not simply refusing or silently auto-ticking.
				 */
				function unmet() {
					var chosen = ticked();
					var out = [];

					chosen.forEach(function (key) {
						var needs = GROUPS[key].needs || {};
						Object.keys(needs).forEach(function (needed) {
							if (chosen.indexOf(needed) !== -1) { return; }
							out.push({
								id: key + ':' + needed,
								group: key,
								groupLabel: GROUPS[key].label,
								needs: needed,
								needsLabel: GROUPS[needed].label,
								severity: needs[needed].severity,
								consequence: needs[needed].consequence
							});
						});
					});

					return out;
				}

				function recompute() {
					var edges = unmet();
					var held = confirmed();
					var box = document.getElementById('kbb-warnings');
					var outstanding = 0;
					var html = '';

					if (!ticked().length) {
						box.innerHTML = '<div class="notice notice-warning inline"><p>' +
							'Nothing is ticked, so there is nothing to export.</p></div>';
						setBlocked('Tick at least one group.');
						return;
					}

					edges.forEach(function (edge) {
						var ok = held.indexOf(edge.id) !== -1;
						if (!ok) { outstanding++; }

						var loses = edge.severity === 'loses';

						html += '<div class="notice ' + (loses ? 'notice-error' : 'notice-warning') +
							'" style="padding:.75em 1em">' +
							'<p style="margin-top:0"><strong>' +
							(loses ? 'This can lose rows without saying so: ' : 'Exported without what it points at: ') +
							esc(edge.groupLabel) + ' without ' + esc(edge.needsLabel) + '</strong></p>' +
							'<p>' + esc(edge.consequence) + '</p>' +
							'<p>' +
							'<button type="button" class="button kbb-add" data-group="' + esc(edge.needs) + '">Add ' +
							esc(edge.needsLabel) + ' to this export</button> &nbsp; or &nbsp;' +
							'<label><input type="checkbox" class="kbb-confirm" value="' + esc(edge.id) + '"' +
							(ok ? ' checked' : '') + '> ' +
							esc(edge.needsLabel) + ' is <strong>already imported</strong> into the new shop' +
							'</label></p>' +
							'</div>';
					});

					box.innerHTML = html;

					Array.prototype.forEach.call(box.querySelectorAll('.kbb-confirm'), function (el) {
						el.addEventListener('change', recompute);
					});

					Array.prototype.forEach.call(box.querySelectorAll('.kbb-add'), function (el) {
						el.addEventListener('click', function () {
							document.getElementById('kbb-group-' + el.getAttribute('data-group')).checked = true;
							recompute();
						});
					});

					setBlocked(outstanding
						? (outstanding === 1 ? 'One dependency above is unanswered.' : outstanding + ' dependencies above are unanswered.')
						: '');
				}

				function setBlocked(why) {
					document.getElementById('kbb-blocked').textContent = why;
					document.getElementById('kbb-start').disabled = !!why || <?php echo null === $detected['source'] ? 'true' : 'false'; ?>;
				}

				function esc(text) {
					var d = document.createElement('div');
					d.textContent = text === undefined || text === null ? '' : String(text);
					return d.innerHTML;
				}

				function post(action, extra, then) {
					var body = new URLSearchParams();
					body.set('action', action);
					body.set('nonce', nonce);
					body.set('batch', document.getElementById('kbb-batch').value);
					body.set('include_trashed', document.getElementById('kbb-include-trashed').checked ? '1' : '0');
					// Posted with EVERY batch, because that is how a
					// browser-driven resumable job works -- and ignored after
					// the first, because the runner pins its settings to the
					// export. See KBB_Export_Runner::pinned_settings().
					body.set('groups', ticked().join(','));
					body.set('confirmed', confirmed().join(','));
					for (var k in extra) { body.set(k, extra[k]); }

					fetch(ajax, {method: 'POST', credentials: 'same-origin', body: body})
						.then(function (r) { return r.json(); })
						.then(then)
						.catch(function (e) {
							running = false;
							// A dead request is STALLED, not finished, and it
							// says so rather than leaving the bar where it was.
							say('Stalled', 'The last batch did not come back (' + e + '). Nothing is lost — press Resume.');
						});
				}

				function say(state, detail) {
					document.getElementById('kbb-status').innerHTML = '<strong>' + state + '.</strong>';
					document.getElementById('kbb-detail').textContent = detail || '';
				}

				/*
				 * ONE BAR PER GROUP, plus the overall one above it.
				 *
				 * The owner ticked groups, so the progress has to be reported in
				 * groups. `state` is pending / running / done and is drawn
				 * differently for each, for the reason docs/GD-MEDIA-SIDELOADER.md
				 * gives: a bar that has not moved because its group has not
				 * started and a bar that has not moved because the request died
				 * look identical unless somebody makes them different.
				 */
				function renderGroups(groups) {
					if (!groups || !groups.length) { return; }

					var html = '<table class="widefat" style="margin-top:.75em;max-width:640px"><tbody>';

					groups.forEach(function (g) {
						var colour = g.state === 'done' ? '#00a32a' : (g.state === 'running' ? '#2271b1' : '#c3c4c7');

						html += '<tr><td style="width:14em">' + esc(g.label) +
							'<br><span class="description">' + esc(g.state) + '</span></td>' +
							'<td><div style="background:#f0f0f1;height:14px;border-radius:3px;overflow:hidden">' +
							'<div style="background:' + colour + ';height:100%;width:' + (g.percent || 0) + '%"></div>' +
							'</div></td>' +
							'<td style="width:11em;text-align:right" class="description">' +
							g.rows_done + ' / ' + g.rows_total + ' rows</td></tr>';
					});

					document.getElementById('kbb-group-bars').innerHTML = html + '</tbody></table>';
				}

				function render(p) {
					if (!p || p.ok === false) {
						running = false;
						say('Stopped', p && p.error ? p.error : 'Unknown error.');
						return;
					}

					document.getElementById('kbb-bar').style.width = (p.percent || 0) + '%';
					renderGroups(p.groups);

					if (p.rows_done !== lastDone) { lastMoved = Date.now(); lastDone = p.rows_done; }

					if (p.done) {
						running = false;
						say('Finished', p.rows_done + ' rows written to ' + p.dir + '. manifest.json is written last, so its presence means the export completed.');
						renderNotes(p.notes || []);
						// The export is complete and importable at this moment
						// whether or not anything is zipped. Packing is the next
						// phase, not part of this one.
						renderDownloads(p.zip);
						packing(p.zip);
						return;
					}

					say(running ? 'Running' : 'Paused',
						'Writing ' + p.stage + ' — file ' + (p.stage_index + 1) + ' of ' + p.stage_count +
						' — ' + p.rows_done + ' of about ' + p.rows_total + ' rows.');

					if (running) { post('kbb_export_step', {}, render); }
				}

				/*
				 * ── THE DOWNLOAD TABLE, AND ITS THREE HONEST STATES ─────────
				 *
				 * "Keep the screen honest about what it has." A group that was
				 * not exported has no archive, and the row SAYS SO instead of
				 * offering a button that answers 404 -- because a 404 tells him
				 * nothing about which of the three reasons it is, and the screen
				 * knows all three.
				 *
				 *   ready    -- a link, with the archive's name and its size, so
				 *               he can see before clicking that it is not heavy.
				 *   building -- the export is done and this group is still being
				 *               packed. Not pressable, and it says which.
				 *   absent   -- not in this export. Not pressable, and it says
				 *               so in words rather than by being missing: a row
				 *               that is simply not drawn reads as a bug.
				 */
				function downloadUrl(group, part) {
					return DOWNLOAD_BASE + '&group=' + encodeURIComponent(group) +
						'&part=' + encodeURIComponent(part) + '&_wpnonce=' + encodeURIComponent(DOWNLOAD_NONCE);
				}

				function bytes(n) {
					if (n < 1024) { return n + ' B'; }
					if (n < 1024 * 1024) { return (n / 1024).toFixed(0) + ' KB'; }
					return (n / 1024 / 1024).toFixed(1) + ' MB';
				}

				function renderDownloads(zip) {
					var box = document.getElementById('kbb-downloads');

					if (!zip) { box.innerHTML = ''; return; }

					if (zip.available === false) {
						box.innerHTML = '<div class="notice notice-warning inline"><p>' + esc(zip.reason) + '</p></div>';
						return;
					}

					var html = '<table class="widefat striped" id="kbb-download-table" style="max-width:60em"><tbody>';

					(zip.groups || []).forEach(function (g) {
						var cell = '';

						if (g.state === 'absent') {
							cell = '<button type="button" class="button kbb-download" disabled ' +
								'data-group="' + esc(g.key) + '" data-state="absent">Not in this export</button>' +
								'<br><span class="description">' + esc(g.why) + '</span>';
						} else {
							(g.parts || []).forEach(function (part) {
								if (part.ready) {
									cell += '<a class="button button-primary kbb-download" data-state="ready" ' +
										'data-group="' + esc(g.key) + '" data-part="' + esc(part.part) + '" ' +
										'href="' + esc(downloadUrl(g.key, part.part)) + '">Download' +
										(part.parts > 1 ? ' part ' + esc(part.part) + ' of ' + esc(part.parts) : '') +
										'</a> <span class="description">' + esc(part.archive) + ' &middot; ' +
										esc(bytes(part.bytes)) + '</span><br>';
								} else {
									cell += '<button type="button" class="button kbb-download" disabled ' +
										'data-group="' + esc(g.key) + '" data-state="building">Packing&hellip;</button><br>';
								}

								cell += '<span class="description">' + esc((part.files || []).join('  ')) + '</span><br>';
							});
						}

						html += '<tr><td style="width:14em"><strong>' + esc(g.label) + '</strong></td>' +
							'<td>' + cell + '</td></tr>';
					});

					box.innerHTML = html + '</tbody></table>';
				}

				/*
				 * THE ZIP PHASE IS A LOOP, exactly like the export's, because it
				 * is bounded the same way: one file into one archive per
				 * request. A group is not a unit of work a shared host's
				 * 110-second limit respects, and 10,571 order lines compressed
				 * inside the request that finished the export is that limit put
				 * straight back.
				 */
				function packing(z) {
					if (!z || z.ok === false) {
						say('Stopped', z && z.error ? z.error : 'The archives could not be packed.');
						return;
					}

					renderDownloads(z);

					if (z.done || z.available === false) {
						say('Finished', z.available === false
							? 'The export is written to the folder above. ' + z.reason
							: 'Every group is packed. Download each one below, then delete the export from this server with the button at the bottom of this page.');
						return;
					}

					say('Packing', 'Building the downloads — ' + z.units_done + ' of ' + z.units + '.');

					post('kbb_export_zip', {}, packing);
				}

				function renderNotes(notes) {
					if (!notes.length) { return; }
					var html = '<h2>What this export says about your shop</h2><ul style="list-style:disc;margin-left:1.5em">';
					notes.forEach(function (n) {
						html += '<li>' + esc(n) + '</li>';
					});
					document.getElementById('kbb-notes').innerHTML = html + '</ul>';
				}

				Array.prototype.forEach.call(document.querySelectorAll('.kbb-group'), function (box) {
					box.addEventListener('change', recompute);
				});

				document.getElementById('kbb-all').addEventListener('click', function () {
					Array.prototype.forEach.call(document.querySelectorAll('.kbb-group'), function (box) { box.checked = true; });
					recompute();
				});

				document.getElementById('kbb-none').addEventListener('click', function () {
					Array.prototype.forEach.call(document.querySelectorAll('.kbb-group'), function (box) { box.checked = false; });
					recompute();
				});

				document.getElementById('kbb-start').addEventListener('click', function () {
					running = true;
					say('Running', 'Counting rows…');
					post('kbb_export_start', {}, render);
				});

				document.getElementById('kbb-resume').addEventListener('click', function () {
					running = true;
					post('kbb_export_step', {}, render);
				});

				document.getElementById('kbb-stop').addEventListener('click', function () {
					running = false;
					say('Paused', 'Nothing is lost. Press Resume to carry on from the last completed batch.');
				});

				/*
				 * ── THE DELETE ─────────────────────────────────────────────
				 *
				 * Its own nonce (PURGE_NONCE) and therefore its own post, not
				 * post() above: that one carries the export nonce and the batch
				 * settings, and a destructive endpoint sharing a body with the
				 * one that starts an export is how a replay of the second
				 * becomes the first.
				 *
				 * THE LIST IS REDRAWN FROM THE SERVER'S ANSWER, and the answer
				 * is the server's fresh walk of the folder -- not "we deleted
				 * 12 files". If something could not be removed it is still in
				 * the list afterwards, by name, which is the whole reason this
				 * section is not a button that says Done.
				 */
				var PURGE_NONCE = <?php echo wp_json_encode( self::can_delete() ? wp_create_nonce( self::PURGE_NONCE ) : '' ); ?>;
				var PURGE_PHRASE = <?php echo wp_json_encode( KBB_Export_Runner::PURGE_PHRASE ); ?>;
				var EXPORTS = <?php echo wp_json_encode( self::can_delete() ? $kbb_exports : array() ); ?>;

				function renderExports(list) {
					var box = document.getElementById('kbb-purge-list');

					if (!box) { return; }

					if (!list || !list.length) {
						box.innerHTML = '<p class="description"><em>There is no export on this server.</em></p>';
						return;
					}

					var html = '<table class="widefat striped" style="max-width:60em"><thead><tr>' +
						'<th>Export</th><th>Files</th><th>Size</th><th>What is in it</th></tr></thead><tbody>';

					list.forEach(function (e) {
						html += '<tr><td><code>' + esc(e.id) + '</code>' +
							(e.current ? ' <span class="description">(the current one)</span>' : '') +
							'<br><span class="description">' + esc(e.created) + '</span></td>' +
							'<td>' + esc(e.files) + '</td><td>' + esc(e.size) + '</td><td>' +
							((e.sensitive && e.sensitive.length)
								? '<strong>' + esc(e.sensitive.join(', ')) + '</strong>' +
									'<br><span class="description">addresses, password hashes, reviewer emails and IPs</span>'
								: '<span class="description">no personal data files</span>') +
							'</td></tr>';
					});

					box.innerHTML = html + '</tbody></table>';
				}

				var purgeBox = document.getElementById('kbb-purge-confirm');
				var purgeButton = document.getElementById('kbb-purge');

				if (purgeBox && purgeButton) {
					purgeBox.addEventListener('input', function () {
						// A courtesy, not a guard: KBB_Export_Runner::purge()
						// checks the same phrase server side and refuses
						// without it whatever this page does.
						purgeButton.disabled = (purgeBox.value.trim() !== PURGE_PHRASE);
					});

					purgeButton.addEventListener('click', function () {
						var said = document.getElementById('kbb-purge-said');

						purgeButton.disabled = true;
						said.textContent = 'Deleting…';

						var body = new URLSearchParams();
						body.set('action', 'kbb_export_purge');
						body.set('nonce', PURGE_NONCE);
						body.set('confirm', purgeBox.value.trim());

						fetch(ajax, {method: 'POST', credentials: 'same-origin', body: body})
							.then(function (r) { return r.json(); })
							.then(function (r) {
								renderExports(r.exports || []);
								purgeBox.value = '';

								if (r.ok) {
									said.textContent = 'Deleted ' + r.deleted + ' item(s), ' + (r.size || '') +
										' freed. ' + (r.note || '');
									return;
								}

								said.textContent = (r.error || '') + ' ' + (r.note || '') +
									((r.remaining && r.remaining.length)
										? ' Still on the server: ' + r.remaining.join(', ')
										: '');
							})
							.catch(function () {
								said.textContent = 'The delete request did not come back. Reload this page — the ' +
									'list above is read from the disk, so it will tell you what is really there.';
							});
					});

					renderExports(EXPORTS);
				}

				recompute();
				renderDownloads(INITIAL_ZIP);

				/*
				 * A FINISHED EXPORT WHOSE ARCHIVES ARE NOT ALL THERE gets picked
				 * up where it was left. He closed the tab during the zip phase,
				 * or the request died; either way the cursor is in the option
				 * and the work left is whatever is left. Nothing is re-exported.
				 */
				if (INITIAL_ZIP && INITIAL_ZIP.available !== false && !INITIAL_ZIP.done) {
					packing(INITIAL_ZIP);
				}
			})();
			</script>
		</div>
		<?php
	}
}
