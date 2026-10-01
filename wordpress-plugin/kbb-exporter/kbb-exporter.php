<?php
/**
 * Plugin Name:       KBB Store Exporter
 * Plugin URI:        https://kbeautybliss.com/
 * Description:       Exports this WooCommerce shop as the CSV set the KBB Laravel storefront imports. Batched and resumable from the admin screen, because this host has no shell.
 * Version:           1.8.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            KBB migration
 * License:           GPL-2.0-or-later
 * Text Domain:       kbb-exporter
 *
 * ============================================================================
 * THIS IS WORDPRESS CODE. IT IS NOT PART OF THE LARAVEL SHOP.
 * ============================================================================
 *
 * It lives in `wordpress-plugin/` at the repository root and it must never be
 * shipped in a Core Updates package. `App\Services\Update\UpdateGuard` refuses
 * it already -- `wordpress-plugin/` is not one of its ALLOWED_PREFIXES, so
 * checkPath() answers "Path outside the permitted areas" and the whole package
 * is rejected before a byte is written. That refusal is asserted in
 * tests/Feature/GeWpExporterTest.php rather than assumed, because "the guard
 * would have caught it" is exactly the sentence that preceded packages
 * 2.60.102-.106.
 *
 * It has NO COMPOSER DEPENDENCIES and no build step, on purpose: it installs by
 * uploading a zip through Plugins -> Add New on shared hosting, which is the
 * only door the owner has.
 *
 * ── WHAT IT WRITES ──────────────────────────────────────────────────────────
 *
 * The file set named in docs/WP-EXPORT-CONTRACT.md, into
 * wp-content/uploads/kbb-export/<export id>/, with manifest.json written LAST.
 * Ten of those files already have an importer with tests behind them in the
 * Laravel shop, and every column in those ten was derived by READING that
 * importer -- see docs/GE-WP-EXPORTER.md for the column-by-column derivation.
 * Nothing here came from WooCommerce's documentation or from memory.
 *
 * ── HOW IT SURVIVES A REAL SHOP ─────────────────────────────────────────────
 *
 * Every stage is a keyset scan: `WHERE id > ? ORDER BY id LIMIT ?`, never
 * `posts_per_page => -1` and never a deep OFFSET, so the cost of the ten
 * thousandth row is the cost of the first. The admin screen drives it one
 * batch per AJAX request, so no single request has to survive longer than a
 * batch, and the position is written to an option after every batch -- a
 * request killed at 110 seconds resumes on the row after the last one written.
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── ONE VALUE, TWO PLACES, AND IT HAD NOT MOVED IN TWELVE COMMITS ──────────
 *
 * This constant and the `Version:` line in the header above MUST agree: the
 * header is what WordPress prints on Plugins, the constant is what the runner
 * writes into `manifest.json` as `source.plugin_version`, and the importer's
 * report shows that. They sat at 1.0.0 from the first commit through group
 * selection, per-group downloads, the real delete and three new product
 * columns, so an owner looking at Plugins could not tell which build was on his
 * site and a manifest could not say which build produced the files.
 *
 * GeWpExporterTest now fails if the two disagree. The history they were given
 * is in CHANGELOG.md beside this file, derived from the commits rather than
 * from memory.
 */
define( 'KBB_EXPORTER_VERSION', '1.8.0' );
define( 'KBB_EXPORTER_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-kbb-export-csv.php';
require_once __DIR__ . '/includes/class-kbb-export-wp.php';
require_once __DIR__ . '/includes/class-kbb-export-media-index.php';
require_once __DIR__ . '/includes/class-kbb-export-review-photos.php';
require_once __DIR__ . '/includes/class-kbb-export-groups.php';
require_once __DIR__ . '/includes/class-kbb-export-zip.php';
require_once __DIR__ . '/includes/class-kbb-export-stage.php';
require_once __DIR__ . '/includes/class-kbb-export-orders-source.php';
require_once __DIR__ . '/includes/class-kbb-export-runner.php';

foreach ( glob( __DIR__ . '/includes/stages/*.php' ) as $kbb_exporter_stage_file ) {
	require_once $kbb_exporter_stage_file;
}
unset( $kbb_exporter_stage_file );

require_once __DIR__ . '/admin/class-kbb-export-admin.php';

if ( function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', array( 'KBB_Export_Admin', 'boot' ) );

	/*
	 * ── HPOS: SAY SO, BECAUSE THIS PLUGIN ALREADY DOES IT ──────── 1.7.1 ──
	 *
	 * WooCommerce 8.2 and later list every plugin that has not declared
	 * itself compatible with High-Performance Order Storage under
	 * WooCommerce -> Settings -> Advanced -> Features, as
	 * "incompatible with HPOS", and warn about it whenever the shop owner
	 * opens that screen.
	 *
	 * THIS PLUGIN IS COMPATIBLE AND IS MEASURED TO BE. The orders stage
	 * reads HPOS's own `wc_orders` tables when the shop is on HPOS and
	 * `wp_posts` when it is not -- KBB_Export_Orders_Source picks -- and
	 * wordpress-plugin/harness/run-export.php runs the real stages both
	 * ways. `--storage=posts` and `--storage=hpos` produce BYTE-IDENTICAL
	 * CSVs; that is asserted in tests/Feature/GeWpExporterTest.php.
	 *
	 * It had simply never said so, and the cost of not saying so is not
	 * cosmetic: the one screen the owner is sent to before a migration
	 * tells him the export plugin is incompatible with the way his orders
	 * are stored. Doing the export anyway is the correct action and looks
	 * like the reckless one. A plugin that works and claims not to is worse
	 * than one that says nothing.
	 *
	 * Guarded twice over -- the hook does not exist before WooCommerce 7.1
	 * and the class does not exist before 7.5 -- so this is inert rather
	 * than fatal on an older shop.
	 */
	add_action(
		'before_woocommerce_init',
		function () {
			if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables',
					__FILE__,
					true
				);
			}
		}
	);

	/*
	 * ── AND SAY SO WHEN WOOCOMMERCE IS NOT THERE AT ALL ───────── 1.7.1 ──
	 *
	 * There was no check of any kind. Activated on a site whose WooCommerce
	 * is deactivated -- which is a thing that happens by accident during a
	 * migration, and a thing a host does on its own when a licence lapses --
	 * Tools -> KBB Export appeared exactly as usual, and the export then
	 * failed partway through on `wp_woocommerce_order_items` not existing.
	 * A stage dying on a missing table is the worst available way to learn
	 * this, because it happens after the owner has started the run.
	 *
	 * A NOTICE AND NOT A `Requires Plugins:` HEADER. That header (WordPress
	 * 6.5+) would make WordPress REFUSE TO ACTIVATE this plugin whenever it
	 * cannot match the slug `woocommerce` -- including on a shop whose
	 * WooCommerce is installed in a differently named folder, which shared
	 * hosts and staging copies do produce. Blocking the migration tool over
	 * a folder name is a worse failure than the one being fixed, so this
	 * tells the owner and gets out of the way.
	 */
	add_action(
		'admin_notices',
		function () {
			if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p><strong>KBB Store Exporter:</strong> '
				. 'WooCommerce is not active on this site, so there are no orders, products or '
				. 'customers for this plugin to export. Activate WooCommerce and reload this page.'
				. '</p></div>';
		}
	);
}
