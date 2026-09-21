<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE SETUP KEY MUST BE READABLE ON A HOST WITH NO FILE MANAGER
 * =============================================================================
 *
 * Screen one of `public-web-root/install.php` writes a 64-character key to
 * `storage/INSTALL-TOKEN.txt` and asks the owner to paste it back. It is the
 * gate on the whole installer: nothing past it runs, and there is no way around
 * it by design — that is the point of it.
 *
 * It used to name exactly one way to read that file: "open it in your hosting
 * panel's File Manager". That is a complete instruction on cPanel and a dead
 * end everywhere else.
 *
 * ── HOW IT WAS FOUND ────────────────────────────────────────────────────────
 *
 * On Cloudways, during a real install. Cloudways gives you SSH and no file
 * manager at all — the feature the screen named does not exist in that panel.
 * The owner reached step 1 of 5, read the only route offered, went looking for
 * a menu item that was never there, and was stopped on the first screen with
 * the shop one paste away and every other requirement already met. Nothing was
 * broken; the installer simply described a world he was not in.
 *
 * A VPS, a DigitalOcean or Vultr box, an SFTP-only host, and every panel that
 * calls its file manager something else are all the same case. This is not an
 * edge: it is most hosts that are not cPanel.
 *
 * ── WHY A TEST ──────────────────────────────────────────────────────────────
 *
 * Because this copy has no other reader. install.php is uploaded by hand and
 * never shipped in a package (UpdateGuard forbids `public-web-root/`), it is
 * exercised once per shop, by someone who is not us, and it deletes itself when
 * it is done. If the second route is dropped in a later tidy, nothing fails,
 * nothing logs, and the next owner on a non-cPanel host is simply stuck — and
 * we hear about it, if at all, as "the installer doesn't work".
 *
 * MUTATION: delete the `cat ` line from paneToken(). Red.
 */
function itsSource(): string
{
    return (string) file_get_contents(base_path('public-web-root/install.php'));
}

it('names a way to read the setup key that needs no file manager', function () {
    $src = itsSource();

    /*
     * `cat <path>` specifically, and not merely the word "SSH" or "terminal".
     * An owner who has never used a shell needs the command, not the category;
     * telling him the file is readable "over SSH" and leaving him to work out
     * how is the same dead end one layer down.
     */
    // Single-quoted: the needle contains a PHP variable name that must NOT be
    // interpolated here -- it is being searched for as literal source text.
    expect(str_contains($src, '<code>cat <?= htmlspecialchars($tokenPathForHumans, ENT_QUOTES) ?></code>'))->toBeTrue(
        'the setup-key screen no longer prints a `cat` command, so an owner on a host without a file '
        .'manager (Cloudways, any VPS, SFTP-only) has no way to read the key and cannot get past screen 1'
    );
});

it('does not present a file manager as the only route', function () {
    $src = itsSource();

    /*
     * Both routes named, neither described as the normal one. The failure this
     * guards is not "File Manager is mentioned" -- it should be, it is the
     * right answer on cPanel -- it is "File Manager is mentioned and nothing
     * else is".
     */
    $start = strpos($src, 'function paneToken(');
    $end = strpos($src, "/* ── 2 · the server", (int) $start);
    $pane = substr($src, (int) $start, (int) $end - (int) $start);

    expect(str_contains($pane, 'File Manager'))->toBeTrue(
        'the cPanel route is gone; it is still the right answer on cPanel'
    );

    // Conditional on both sides, so neither reads as the default.
    expect(str_contains($pane, 'If your host has a File Manager'))->toBeTrue(
        'the file-manager route is stated unconditionally again, which is how it became the only route'
    );

    expect(str_contains($pane, 'If you have SSH or a terminal'))->toBeTrue(
        'the shell route is no longer offered alongside the file-manager one'
    );
});

it('says which line of the file to copy', function () {
    /*
     * The file is the key, a blank line, and three lines of prose explaining
     * what it is for. An owner who selects the whole file pastes all of it.
     *
     * The handler survives that -- `$('#tk').value.trim().split(/\s+/)[0]`
     * takes the first whitespace-delimited word, which is the key -- and this
     * test does NOT rely on that, because a screen whose instruction only works
     * because of a defensive split is a screen that reads as broken the moment
     * the split changes. Both halves are pinned.
     */
    $src = itsSource();

    expect(str_contains($src, 'long line at the top'))->toBeTrue(
        'the screen no longer says which line of the file is the key'
    );

    expect(str_contains($src, "\$('#tk').value.trim().split(/\\s+/)[0]"))->toBeTrue(
        'the setup-key field no longer takes the first word, so pasting the whole file now fails'
    );
});

it('writes the setup key so a human on the other user account can read it', function () {
    /*
     * =========================================================================
     * THE MODE ON THIS ONE FILE IS THE INSTALLER'S SINGLE POINT OF FAILURE
     * =========================================================================
     *
     * It is written by PHP and read by a person, and on most hosting those are
     * two different user accounts: PHP runs as www-data (or nobody, or a pool
     * user) and the owner logs in as the account that owns the files.
     *
     * At 0600 -- "the creating user, nobody else" -- the owner runs the exact
     * `cat` command this page prints him and gets:
     *
     *     cat: .../storage/INSTALL-TOKEN.txt: Permission denied
     *
     * That is a total lockout. The key gates screen 1 of 5, nothing past it
     * runs, and the installer has just told him to read a file it made
     * unreadable. Found on Cloudways on a real install, with every other
     * requirement already green.
     *
     * 0640 gives the shared group the read, which is how the owner's own files
     * on such a host already look (`-rw-rw-r-- master www-data`).
     *
     * MUTATION: put it back to 0600. Red.
     */
    $src = itsSource();

    $start = strpos($src, 'function kbb_token(');
    $end = strpos($src, 'function kbb_check_token(', (int) $start);
    $fn = substr($src, (int) $start, (int) $end - (int) $start);

    preg_match('/@chmod\(\$file,\s*0(\d{3})\)/', $fn, $m);

    expect($m)->not->toBe([], 'kbb_token() no longer sets a mode on the setup-key file at all');

    $mode = octdec($m[1]);

    expect($mode & 0040)->toBe(
        0040,
        'the setup-key file is written without group read (mode 0'.$m[1].'), so on any host where PHP '
        .'and the login account are different users the owner cannot read the key that gates the install'
    );

    // And not world-readable: other tenants on shared hosting are the reason
    // this has a mode at all.
    expect($mode & 0004)->toBe(
        0,
        'the setup-key file is world-readable (mode 0'.$m[1].')'
    );
});

it('prints a way back in when even the group read is not enough', function () {
    /*
     * 0640 covers the hosts where the web server and the login account share a
     * group. It does not cover PHP running as `nobody`, or a login account in a
     * group of its own — and there the owner is locked out of screen 1 with no
     * recovery flow, which is the failure this whole file is about.
     *
     * kbb_token() returns the first line of the file when it matches
     * ^[a-f0-9]{64}$ and mints a new key only otherwise, so a key the owner
     * wrote himself is a first-class key. That is not a workaround bolted on
     * afterwards — it is the function's existing contract — but it is worth
     * nothing while it is a secret.
     *
     * MUTATION: delete the Permission-denied paragraph. Red.
     */
    $src = itsSource();

    expect(str_contains($src, 'Permission denied'))->toBeTrue(
        'the setup-key screen no longer tells a locked-out owner what to do, and there is no other '
        .'place he could find out'
    );

    expect(str_contains($src, 'openssl rand -hex 32'))->toBeTrue(
        'the recovery route no longer prints a command that produces a key kbb_token() will accept'
    );

    /*
     * The two halves have to agree. `openssl rand -hex 32` emits 64 hex
     * characters; the reader accepts exactly ^[a-f0-9]{64}$. A change to either
     * that leaves the other alone hands the owner a key that is refused every
     * time he pastes it, which looks identical to typing it wrong.
     */
    expect(str_contains($src, "'/^[a-f0-9]{64}$/'"))->toBeTrue(
        'kbb_token() no longer accepts a 64-character hex first line, so the recovery command on the '
        .'screen now produces a key the installer refuses'
    );
});

it('prints the recovery command as one line that survives being pasted as one line', function () {
    /*
     * =========================================================================
     * A SHELL SNIPPET THAT ONLY WORKS ON SEPARATE LINES IS A BROKEN SNIPPET
     * =========================================================================
     *
     * The recovery route was written as two lines:
     *
     *     T=<path>
     *     rm -f "$T" && openssl rand -hex 32 > "$T" && cat "$T"
     *
     * Terminals, chat clients and copy buttons join pasted blocks, and the
     * joined form is not the same program. `T=<path> rm -f "$T"` is not an
     * assignment -- it is an environment prefix scoped to the `rm` process --
     * so $T expands to EMPTY in the shell actually running the line and the
     * owner gets:
     *
     *     bash: : No such file or directory
     *
     * Reported from a real paste. It happened on the RECOVERY route, to
     * someone already locked out of the normal one, which is what makes it
     * worth a test rather than a tidier: it is the second dead end on the same
     * screen, and the screen is the gate on the whole installer.
     *
     * A single line with `;` separators cannot break that way however it is
     * pasted, so this pins the shape rather than the wording.
     *
     * MUTATION: put the newline back between the assignment and the `rm`. Red.
     */
    $src = itsSource();

    $start = strpos($src, "<code class=\"cmd\">");

    expect($start)->not->toBeFalse('the recovery command block is gone from the setup-key screen');

    $cmd = substr($src, (int) $start, (int) strpos($src, '</code>', (int) $start) - (int) $start);

    /*
     * The block is built by concatenating JS string literals, so a line break
     * in the SOURCE is fine -- what must not appear is an escaped newline that
     * reaches the rendered command. That is the thing a paste can eat.
     */
    expect(str_contains($cmd, '\\n'))->toBeFalse(
        'the recovery command contains a newline escape, so pasting it as one line silently turns the '
        .'assignment into an environment prefix and $T expands to nothing'
    );

    // The separator that makes a single line behave.
    expect(preg_match('/\?>;\s/', $cmd))->toBe(
        1,
        'the path assignment is not terminated with `;`, so everything after it on the line becomes '
        .'part of one command instead of running in the shell'
    );
});
