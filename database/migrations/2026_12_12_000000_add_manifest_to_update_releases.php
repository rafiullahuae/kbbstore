<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the package actually contained — Phase 18, item 3 (report-only).
 *
 * ── WHAT WAS MISSING ────────────────────────────────────────────────────────
 *
 * Every package this shop applies carries `update.json`, and that manifest
 * holds a SHA-256 for every path it ships. UpdatePackage::checkChecksums()
 * verifies each file against it before a byte is written — and then the
 * manifest was thrown away. `update_releases` kept the version, the status, the
 * backup id and a file COUNT, which can tell you that 23 files landed and
 * nothing at all about which 23 or what they said.
 *
 * That gap is the reason this host cannot answer its most basic question. There
 * is no shell on it: the owner cannot diff anything, cannot list a directory,
 * cannot check a hash. A package applied twice, half-applied after a timeout,
 * or hand-edited by a support agent with FTP is invisible today — including the
 * case this project has already paid for, where 2.60.102–.106 were built
 * against a stale tree, applied anyway, reverted three files and 500'd every
 * product page.
 *
 * ── ONE COLUMN, NOT A TABLE ─────────────────────────────────────────────────
 *
 * A `release_files` table would be the normalised answer: one row per path per
 * release, indexed, joinable. It is the wrong one here. Nothing joins it —
 * IntegrityChecker reads every path of every applied release and overlays them
 * into one map in PHP, which is a whole-manifest read whichever shape it is
 * stored in. A join per file on a screen that lists files is the N+1 this
 * project measures for. And the rows would be an order of magnitude more rows
 * than `update_releases` has ever held, on a 1 GB shared plan, to answer no
 * question the JSON does not.
 *
 * NULLABLE, and it stays nullable. Every release applied before this column
 * existed has none, and IntegrityChecker recovers those from the zip
 * UpdateRunner::archivePackage() kept — writing the result back here, so the
 * zip is opened once ever. A nullable column is what makes the shop able to
 * speak about its own past rather than only about packages shipped from today.
 *
 * ── IT DOES NOT MAKE ANYTHING HAPPEN ────────────────────────────────────────
 *
 * Adding a column changes no page, refuses no request and alters no response.
 * The integrity check is report-only by the plan's own sequencing; this is the
 * record it reads.
 *
 * NO ->after() ANYWHERE, for the reason 2026_09_15_020000_repair_order_tables
 * already paid for on this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('update_releases')) {
            return;
        }

        if (Schema::hasColumn('update_releases', 'manifest')) {
            return;
        }

        Schema::table('update_releases', function (Blueprint $t) {
            // longText: a manifest is path => 64-char hash, and a large package
            // on this project runs to a few hundred files. TEXT's 64 KB would
            // hold most of them and truncate the one that mattered.
            $t->longText('manifest')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('update_releases') && Schema::hasColumn('update_releases', 'manifest')) {
            Schema::table('update_releases', function (Blueprint $t) {
                $t->dropColumn('manifest');
            });
        }
    }
};
