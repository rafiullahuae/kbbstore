<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE A — Phase 13, item 4: the screen told the owner to delete a folder he
 * cannot reach, and it holds every shopper's password hash
 * ════════════════════════════════════════════════════════════════════════════
 *
 * THE DEFECT, which was two defects sitting on top of each other.
 *
 * The export screen says, correctly, to delete the folder once everything is
 * downloaded: `customers.csv` carries every shopper's address and their
 * WordPress password hash, `reviews.csv` carries reviewer emails and the IPs
 * they posted from. The owner has no shell and no FTP — the paragraph two lines
 * above that instruction says so itself. There was no control that did it.
 *
 * AND THE OBVIOUS FIX WAS A TRAP. `wp_ajax_kbb_export_reset` was already
 * registered and reachable. Wired to a button labelled Delete it would answer
 * `ok`, the bar would go back to zero, and the screen would look exactly as it
 * does after a real delete — because `KBB_Export_Runner::reset()` calls
 * `delete_option()` and nothing else. The hashes stay on disk, now with nothing
 * in the admin referring to them and no download link left to reach them:
 * WORSE than before, because the owner has been told they are gone.
 *
 * SO THIS FILE ASSERTS ABSENCE FROM THE DISK, never a 200 and never an `ok`.
 * Every test below ends in `is_file()`, and the first one it runs is the trap
 * itself: reset() is called and the hashes are still asserted to be there.
 *
 * ── HOW WORDPRESS CODE IS TESTED HERE ───────────────────────────────────────
 *
 * In a SEPARATE PHP PROCESS, the way GnExportScreenTest's settings probe does,
 * and for the reason it gives: the plugin's files begin
 * `defined('ABSPATH') || exit`, declare add_action() callbacks and would put a
 * second definition of the plugin's classes beside whatever else is loaded.
 * A subprocess with the two files it actually needs is the honest reproduction
 * of what WordPress does and cannot contaminate the suite it is run from.
 *
 * NO MySQL IS NEEDED and none is used. The option store is stubbed in memory;
 * everything this feature does happens on a filesystem, which is real.
 *
 * `expect(...)->not->toContain($x, $message)` IS NOT USED ANYWHERE HERE.
 * `toContain` is variadic, so the message is read as a second needle and the
 * assertion passes vacuously — CLAUDE.md records it.
 */

use Illuminate\Support\Facades\File;

/** Where the probe builds its pretend wp-content/uploads. */
function gpUploads(): string
{
    return sys_get_temp_dir().'/kbb-purge-'.getmypid();
}

function gpRoot(): string
{
    return gpUploads().'/kbb-export';
}

/**
 * Build an uploads folder holding two finished exports, the way the plugin
 * leaves one.
 *
 * @return list<string> every file written, absolute
 */
function gpSeed(): array
{
    File::deleteDirectory(gpUploads());

    $written = [];

    foreach (['3f7a1c22-0001-4000-8000-aaaabbbbcccc', '91b0ee54-0002-4000-8000-ddddeeeeffff'] as $id) {
        $dir = gpRoot().'/'.$id;

        File::ensureDirectoryExists($dir.'/nested');

        $files = [
            $dir.'/customers.csv' => "id,email,password_hash,address_1\n1,a@b.test,\$P\$Bxxxxxxxxxxxxxxxxxxxx,12 Al Wasl Road\n",
            $dir.'/reviews.csv' => "id,author_email,ip\n1,r@b.test,81.2.3.4\n",
            $dir.'/orders.csv' => "id,total\n1,199.00\n",
            $dir.'/manifest.json' => '{"export_id":"'.$id.'"}',
            $dir.'/index.php' => "<?php\n// Silence is golden.\n",
            $dir.'/kbb-export-customers-'.$id.'.zip' => 'PK'."\x03\x04".'not really a zip',
            $dir.'/nested/deeper.csv' => "a\n1\n",
        ];

        foreach ($files as $path => $body) {
            file_put_contents($path, $body);
            $written[] = $path;
        }
    }

    // The folder's own guards, which purge() is required to LEAVE.
    file_put_contents(gpRoot().'/index.php', "<?php\n// Silence is golden.\n");
    file_put_contents(gpRoot().'/.htaccess', "Require all denied\n");

    return $written;
}

/**
 * Run one call against the real plugin classes in a subprocess.
 *
 * `$caps` is the capability set the stubbed current_user_can() answers from, so
 * "fails closed" is something this file can actually enter rather than assert
 * about code it has read.
 *
 * @param  array<string, mixed>  $post
 * @param  list<string>  $caps
 * @return array<string, mixed>
 */
function gpCall(string $action, array $post, array $caps = ['manage_woocommerce', 'delete_users']): array
{
    $script = <<<'PHP'
        define('ABSPATH', '/tmp/');

        $CAPS    = json_decode($argv[4], true);
        $UPLOADS = $argv[1];
        $OPTIONS = array();

        function current_user_can($cap) { global $CAPS; return in_array($cap, $CAPS, true); }
        function check_ajax_referer($action, $field) {
            if (!isset($_POST[$field]) || $_POST[$field] !== 'nonce-for-' . $action) {
                echo json_encode(array('ok' => false, 'error' => 'bad nonce', 'http' => 403));
                exit;
            }
            return true;
        }
        function wp_send_json($data, $status = 200) {
            $data['http'] = $status;
            echo json_encode($data);
            exit;
        }
        function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
        function get_option($n, $d = false) { global $OPTIONS; return array_key_exists($n, $OPTIONS) ? $OPTIONS[$n] : $d; }
        function update_option($n, $v) { global $OPTIONS; $OPTIONS[$n] = $v; return true; }
        function delete_option($n) { global $OPTIONS; unset($OPTIONS[$n]); return true; }
        function wp_upload_dir() { global $UPLOADS; return array('basedir' => $UPLOADS, 'baseurl' => 'https://old.test/wp-content/uploads'); }
        function home_url($p = '') { return 'https://old.test/' . ltrim((string) $p, '/'); }
        function add_action() {}
        function add_management_page() {}
        function wp_create_nonce($a) { return 'nonce-for-' . $a; }
        function wp_json_encode($v) { return json_encode($v); }
        function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
        function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
        function disabled($a, $b = true, $c = true) { return ''; }
        function wp_die($m) { echo json_encode(array('ok' => false, 'error' => (string) $m)); exit; }
        function wp_nonce_url($u, $a) { return $u; }
        function admin_url($p = '') { return 'https://old.test/wp-admin/' . ltrim((string) $p, '/'); }
        function nocache_headers() {}
        function maybe_unserialize($v) { return $v; }

        $base = $argv[2] . '/wordpress-plugin/kbb-exporter';

        require $base . '/includes/class-kbb-export-csv.php';
        require $base . '/includes/class-kbb-export-wp.php';
        require $base . '/includes/class-kbb-export-media-index.php';
        require $base . '/includes/class-kbb-export-groups.php';
        require $base . '/includes/class-kbb-export-zip.php';
        require $base . '/includes/class-kbb-export-stage.php';
        require $base . '/includes/class-kbb-export-orders-source.php';
        require $base . '/includes/class-kbb-export-runner.php';
        require $base . '/admin/class-kbb-export-admin.php';

        $_POST = json_decode($argv[5], true);

        $action = $argv[3];

        if ('purge' === $action) {
            KBB_Export_Admin::ajax_purge();
        } elseif ('reset' === $action) {
            // THE TRAP, called exactly as a Delete button wired to the endpoint
            // that already existed would have called it.
            (new KBB_Export_Runner())->reset();
            echo json_encode(array('ok' => true, 'http' => 200));
        } elseif ('exports' === $action) {
            echo json_encode(array('ok' => true, 'http' => 200, 'exports' => (new KBB_Export_Runner())->exports()));
        }
    PHP;

    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' -r '.escapeshellarg($script)
            .' '.escapeshellarg(gpUploads())
            .' '.escapeshellarg(base_path())
            .' '.escapeshellarg($action)
            .' '.escapeshellarg((string) json_encode($caps))
            .' '.escapeshellarg((string) json_encode($post))
            .' 2>&1',
        $lines,
        $status
    );

    expect($status)->toBe(0, 'the plugin probe failed: '.implode("\n", $lines));

    $decoded = json_decode(implode('', $lines), true);

    expect($decoded)->toBeArray('the plugin probe printed something that is not JSON: '.implode("\n", $lines));

    return $decoded;
}

/** The nonce the real ajax_purge() checks, as the stub above mints it. */
const GP_NONCE = 'nonce-for-kbb_export_purge';

afterEach(function (): void {
    File::deleteDirectory(gpUploads());
});

/* ========================================================================== */
/*  THE TRAP, ASSERTED FIRST                                                   */
/* ========================================================================== */

it('proves that the endpoint which already existed deletes nothing', function () {
    /*
     * THIS IS THE TEST THE WHOLE FEATURE EXISTS BECAUSE OF, and it passes both
     * before and after this lane — deliberately. It is not asserting a fix, it
     * is pinning the reason the fix could not be a wire-up.
     *
     * `wp_ajax_kbb_export_reset` is registered and reachable. Wired to a Delete
     * button it answers ok:true, the screen redraws as though the export were
     * gone, and every shopper's password hash is exactly where it was.
     *
     * MUTATION: make KBB_Export_Runner::reset() call purge(PURGE_PHRASE) and
     * this goes red — which is the point. A Delete button on THIS endpoint is
     * the bug, not the feature.
     */
    $files = gpSeed();

    $answer = gpCall('reset', []);

    expect($answer['ok'])->toBeTrue();

    foreach ($files as $file) {
        expect(is_file($file))->toBeTrue($file.' should still be there — reset() deletes no file');
    }

    expect(is_file(gpRoot().'/3f7a1c22-0001-4000-8000-aaaabbbbcccc/customers.csv'))->toBeTrue();
});

/* ========================================================================== */
/*  THE REAL DELETE                                                            */
/* ========================================================================== */

it('really removes every export file from the disk', function () {
    /*
     * THE FINISH LINE, AND IT IS `is_file()` AND NOT A STATUS CODE. The brief
     * is explicit: a test that asserts the endpoint answered 200 asserts the
     * bug. Every file written by gpSeed() is checked individually.
     */
    $files = gpSeed();

    expect(count($files))->toBe(14);

    $answer = gpCall('purge', ['nonce' => GP_NONCE, 'confirm' => 'DELETE']);

    expect($answer['ok'])->toBeTrue()
        ->and($answer['remaining'])->toBe([])
        ->and($answer['deleted'])->toBeGreaterThan(0);

    $left = array_values(array_filter($files, static fn (string $f): bool => file_exists($f)));

    expect($left)->toBe([])
        ->and(is_dir(gpRoot().'/3f7a1c22-0001-4000-8000-aaaabbbbcccc'))->toBeFalse()
        ->and(is_dir(gpRoot().'/91b0ee54-0002-4000-8000-ddddeeeeffff'))->toBeFalse();

    // And the screen redraws from the disk: nothing left to list.
    expect($answer['exports'])->toBe([]);
});

it('leaves the folder guards in place, because they are what protect it', function () {
    /*
     * `index.php` and `.htaccess` hold nothing and are what stop the folder
     * being listed or served. Removing them to tidy up would open a window: if
     * anything recreated the folder before write_index_guard() next ran, a
     * directory listing of an unguarded uploads folder is the whole breach.
     */
    gpSeed();

    gpCall('purge', ['nonce' => GP_NONCE, 'confirm' => 'DELETE']);

    expect(is_file(gpRoot().'/index.php'))->toBeTrue()
        ->and(is_file(gpRoot().'/.htaccess'))->toBeTrue()
        ->and(str_contains((string) file_get_contents(gpRoot().'/.htaccess'), 'Require all denied'))->toBeTrue();
});

/* ========================================================================== */
/*  THE THREE GUARDS, EACH ENTERED                                             */
/* ========================================================================== */

it('deletes nothing without the typed confirmation', function () {
    /*
     * A word rather than a tick box, because the action cannot be undone: the
     * export is the only copy of a several-minute run, and on cutover day the
     * only copy of the shop's data that is not on the site being switched off.
     *
     * Checked on the SERVER. The page's disabled button is a courtesy to the
     * person using it; the endpoint is reachable without the page.
     *
     * MUTATION: delete the `self::PURGE_PHRASE !== $typed` branch from
     * KBB_Export_Runner::purge() and all three cases below go red.
     */
    $files = gpSeed();

    foreach (['', 'delete', 'Delete', 'yes', 'DELETE '.PHP_EOL.'x'] as $typed) {
        $answer = gpCall('purge', ['nonce' => GP_NONCE, 'confirm' => $typed]);

        expect($answer['ok'])->toBeFalse()
            ->and(str_contains((string) $answer['error'], 'Nothing was deleted'))->toBeTrue();
    }

    foreach ($files as $file) {
        expect(is_file($file))->toBeTrue($file.' was deleted without the confirmation');
    }
});

it('fails closed for a user who can run the export but not destroy it', function () {
    /*
     * `manage_woocommerce` opens the rest of this screen and is what a shop
     * manager has. It does not open this. The capability is `kbb_export_delete`
     * with `delete_users` as the fallback — administrator-only in core
     * WordPress — so the person who can EXPORT is not automatically the person
     * who can DESTROY.
     *
     * MUTATION: change can_delete() to `current_user_can(self::CAPABILITY)` and
     * this goes red: the shop manager's call succeeds and the files go.
     */
    $files = gpSeed();

    $answer = gpCall('purge', ['nonce' => GP_NONCE, 'confirm' => 'DELETE'], ['manage_woocommerce']);

    expect($answer['ok'])->toBeFalse()
        ->and($answer['http'])->toBe(403)
        ->and(str_contains((string) $answer['error'], 'kbb_export_delete'))->toBeTrue();

    foreach ($files as $file) {
        expect(is_file($file))->toBeTrue($file.' was deleted for a user without the capability');
    }

    // And the capability on its own is enough, without delete_users: it is a
    // real grant a site can make, not decoration.
    $granted = gpCall('purge', ['nonce' => GP_NONCE, 'confirm' => 'DELETE'], ['kbb_export_delete']);

    expect($granted['ok'])->toBeTrue()
        ->and(is_file($files[0]))->toBeFalse();
});

it('deletes nothing without its own nonce', function () {
    // Its own, not the export's: a leaked or replayed body that could start an
    // export is a nuisance, and one that could delete one is the incident.
    $files = gpSeed();

    $answer = gpCall('purge', ['nonce' => 'nonce-for-kbb_export', 'confirm' => 'DELETE']);

    expect($answer['ok'])->toBeFalse();

    foreach ($files as $file) {
        expect(is_file($file))->toBeTrue($file.' was deleted on the wrong nonce');
    }
});

/* ========================================================================== */
/*  IT CANNOT BE STEERED OUT OF THE FOLDER                                     */
/* ========================================================================== */

it('unlinks a symlink instead of following it out of the uploads folder', function () {
    /*
     * `is_dir()` answers true for a symlink pointing at a directory, so a
     * recursive delete that tested for a directory first would walk the link
     * and remove whatever it points at — which on a shared host is anything PHP
     * can write. `is_link()` is tested FIRST in KBB_Export_Runner::remove().
     *
     * MUTATION: move the is_link() branch below the is_dir() branch and the
     * outside file disappears, which this catches.
     */
    gpSeed();

    $outside = gpUploads().'/precious';
    File::ensureDirectoryExists($outside);
    file_put_contents($outside.'/wp-config.php', "<?php // do not delete me\n");

    symlink($outside, gpRoot().'/3f7a1c22-0001-4000-8000-aaaabbbbcccc/escape');

    $answer = gpCall('purge', ['nonce' => GP_NONCE, 'confirm' => 'DELETE']);

    expect($answer['ok'])->toBeTrue()
        // The link is gone from inside the export folder...
        ->and(is_link(gpRoot().'/3f7a1c22-0001-4000-8000-aaaabbbbcccc/escape'))->toBeFalse()
        // ...and what it pointed at is untouched.
        ->and(is_file($outside.'/wp-config.php'))->toBeTrue()
        ->and(is_dir($outside))->toBeTrue();
});

it('lists what is really on the server, read off the disk and not out of the option', function () {
    /*
     * The state option knows about the export this plugin is part-way through.
     * The disk knows about the ones from last week that were downloaded and
     * left there, which are the ones this screen exists to get rid of — and an
     * export whose state was reset is invisible to the option while still being
     * every shopper's address on a public web server.
     *
     * MUTATION: build exports() from `$this->state` instead of scandir() and
     * this reports nothing at all, because no state was ever written here.
     */
    gpSeed();

    $answer = gpCall('exports', []);
    $ids = array_column($answer['exports'], 'id');

    sort($ids);

    expect($ids)->toBe(['3f7a1c22-0001-4000-8000-aaaabbbbcccc', '91b0ee54-0002-4000-8000-ddddeeeeffff']);

    foreach ($answer['exports'] as $export) {
        // Named, not counted: "12 files" is not a reason to press a destructive
        // button and "customers.csv, which holds every shopper's password
        // hash" is.
        expect($export['sensitive'])->toBe(['customers.csv', 'reviews.csv', 'orders.csv'])
            ->and($export['files'])->toBe(7)
            ->and($export['bytes'])->toBeGreaterThan(0);
    }
});
