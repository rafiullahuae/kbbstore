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

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_kbb_export_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_kbb_export_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_kbb_export_reset', array( __CLASS__, 'ajax_reset' ) );
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

	public static function ajax_reset() {
		self::guard();

		$runner = new KBB_Export_Runner();
		$runner->reset();

		wp_send_json( array( 'ok' => true, 'error' => '' ) );
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
				<code><?php echo esc_html( KBB_Export_Wp::uploads_dir() . '/kbb-export/' ); ?></code>.
				Download the folder over FTP when it finishes, then <strong>delete it from the server</strong> &mdash;
				<code>customers.csv</code> holds every shopper&rsquo;s address and password hash and
				<code>reviews.csv</code> holds reviewers&rsquo; email addresses and IPs.
			</p>

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
			<p>
				<label>Rows per batch
					<input type="number" id="kbb-batch" value="200" min="10" max="1000" step="10">
				</label>
				&nbsp;
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

			<div id="kbb-state" style="margin:1em 0;padding:.75em 1em;border-left:4px solid #72aee6;background:#fff;">
				<p id="kbb-status"><strong>Idle.</strong> Nothing is running.</p>
				<div style="background:#f0f0f1;height:22px;border-radius:3px;overflow:hidden;max-width:640px;">
					<div id="kbb-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s;"></div>
				</div>
				<p id="kbb-detail" class="description"></p>
				<div id="kbb-group-bars"></div>
			</div>

			<div id="kbb-notes"></div>

			<script>
			(function () {
				var running = false;
				var nonce = <?php echo wp_json_encode( $nonce ); ?>;
				var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var GROUPS = <?php echo wp_json_encode( KBB_Export_Groups::all() ); ?>;
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
						return;
					}

					say(running ? 'Running' : 'Paused',
						'Writing ' + p.stage + ' — file ' + (p.stage_index + 1) + ' of ' + p.stage_count +
						' — ' + p.rows_done + ' of about ' + p.rows_total + ' rows.');

					if (running) { post('kbb_export_step', {}, render); }
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

				recompute();
			})();
			</script>
		</div>
		<?php
	}
}
