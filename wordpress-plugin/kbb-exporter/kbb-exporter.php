<?php
/**
 * Plugin Name:       KBB Store Exporter
 * Plugin URI:        https://kbeautybliss.com/
 * Description:       Exports this WooCommerce shop as the CSV set the KBB Laravel storefront imports. Batched and resumable from the admin screen, because this host has no shell.
 * Version:           1.0.0
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

define( 'KBB_EXPORTER_VERSION', '1.0.0' );
define( 'KBB_EXPORTER_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-kbb-export-csv.php';
require_once __DIR__ . '/includes/class-kbb-export-wp.php';
require_once __DIR__ . '/includes/class-kbb-export-media-index.php';
require_once __DIR__ . '/includes/class-kbb-export-groups.php';
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
}
