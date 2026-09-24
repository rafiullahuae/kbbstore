<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for integrity checking, report-only.
 *
 * A NEW ROUTE. routes/security-admin.php grows
 * `POST /admin-api/security/integrity` — the Check now button — and the router
 * dispatches against bootstrap/cache/routes-*.php rather than against the
 * source. Without this the button answers 404 on a server whose route table was
 * compiled before the package landed. Every package on this project that adds a
 * route ships one of these; see CLAUDE.md.
 *
 * ONE CHANGED BLADE, compiled into storage/framework/views and keyed by PATH
 * rather than by contents: the freshness check is a filemtime compare and an
 * unzip's timestamps are not reliably newer than what is already on disk. A
 * stale copy here is a Security screen with no integrity card on it.
 *
 * What changed:
 *
 *   database/migrations/2026_12_12_000000_add_manifest_to_update_releases.php
 *                                   `update_releases.manifest` — what the
 *                                   package actually contained, path by path
 *                                   with its SHA-256. It was thrown away after
 *                                   every apply until now.
 *
 *   app/Services/IntegrityChecker.php
 *                                   hashes what a package installed and
 *                                   compares it with what the package said it
 *                                   was. REPORT ONLY: it may not name copy(),
 *                                   file_put_contents(), unlink() or rename()
 *                                   and a test reads it as text for each.
 *
 *   app/Services/Update/UpdateRunner.php
 *                                   records the manifest on the release row as
 *                                   the package applies. Wrapped so a problem
 *                                   recording it can never fail an update, the
 *                                   same way archivePackage() already is.
 *
 *   app/Services/SecurityModule.php the three integrity settings, the integrity
 *                                   half of the report and its verdict branch;
 *                                   a hook on ModuleToggle, which changed the
 *                                   shop and left no row; and a hard ceiling on
 *                                   the size of the trail.
 *
 *   app/Http/Controllers/Admin/SecurityController.php
 *   routes/security-admin.php
 *   app/Support/AdminCapabilities.php
 *   resources/views/admin/partials/security-screen.blade.php
 *                                   Store -> Security grows an "Integrity of
 *                                   the files packages installed" card and a
 *                                   Check now button, behind the new owner-only
 *                                   `security.integrity` capability — its own,
 *                                   not `security.view`, because reading the
 *                                   report and making the server walk its own
 *                                   filesystem are different acts.
 *
 * FOUR NEW SETTINGS, AND TWO OF THEM MOVE SOMETHING. Called out here rather
 * than buried, as CLAUDE.md requires:
 *
 *   `sec_integrity_on` ships ON. The owner asked for exactly this in Phase 18
 *   item 3 ("the part that answers auto reverse it"), and a check that ships
 *   off checks nothing until somebody finds the switch. It runs ONLY when the
 *   owner opens Store -> Security, never on the storefront, never before
 *   routing and never on a model event. It refuses nothing and writes nothing
 *   outside `audit_events`.
 *
 *   `sec_max_rows` ships at 20000, where the old behaviour was unbounded. That
 *   is a deliberate change and it is the answer to a gap this lane named in
 *   round one: retention runs when the screen is opened, and a shop nobody
 *   opens the screen on kept every row for ever. The admin login throttle
 *   allows five attempts a minute, so a patient attacker can write 7,200 rows a
 *   day into a table nothing was trimming. On this shop the table is days old
 *   and holds far fewer than 20,000 rows, so applying this package deletes
 *   nothing.
 *
 *   `sec_integrity_hours` (6) and `sec_integrity_action` ('alert') are new
 *   controls for new behaviour and move nothing that existed.
 *
 * `sec_integrity_action` HAS EXACTLY ONE OPTION, and that is the point of it.
 * Phase 18 records "Restore automatically, or alert and wait?" as an OPEN
 * QUESTION FOR THE OWNER, with a recommendation of alert-by-default. A lane
 * does not answer that by shipping code for one branch, so this ships the alert
 * half and a seam: SecurityModule::cast() stores a select value only if it is
 * one of that field's own options, so a hand-rolled POST of 'restore' is stored
 * as 'alert'.
 *
 * NOTHING IS BLOCKED AND NOTHING IS RESTORED by this package. Phase 18's order
 * is audit trail and reporting screen -> integrity checking in report-only mode
 * -> CSP report-only -> the request gate in observe mode -> enforcement one
 * rule at a time. This is the second step and only the second step.
 *
 * NO SETTING ROWS ARE WRITTEN by this migration. SecurityModule carries every
 * default, and an absent row and a row holding the default are the same thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; Store -> Security now checks the files\n"
                ."packages installed against the hashes those packages declared, and reports\n"
                ."what differs. It blocks nothing and it restores nothing.\n";
        }
    }

    public function down(): void {}
};
