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
