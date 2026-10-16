<?php

declare(strict_types=1);

/**
 * The admin search running on the owner's email by itself. (16 October 2026)
 *
 * What it looked like in the admin: opening Translation → Language settings,
 * on every visit and every refresh, put "rite2rafi@gmail.com" in the sidebar
 * search and opened "Nothing in the menu matches …" under it. Chrome's password
 * manager saw the screen's API-key field (type=password), took the text box
 * before it -- the search -- for a username, filled the saved login's email in
 * and fired `input`. It ignores autocomplete="off" for saved logins.
 *
 * Two halves: the search ignores text that arrives while it is not focused
 * (measured in Chromium, tools/ksr-autofill.cjs: autofilled value cleared, no
 * popup; typed "logo" still finds 30), and the secret fields ask for
 * autocomplete="new-password", which Chrome does honour.
 *
 * MUTATION: put `input.addEventListener('input', soon);` back -- red; set a
 * secret field back to autocomplete="off" -- red.
 */
it('ignores text a password manager puts in the admin search', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/admin-search.blade.php'));

    expect($src)->toContain("if (document.activeElement !== input) { input.value = ''; return; }")
        ->and($src)->not->toContain("input.addEventListener('input', soon);")
        ->and($src)->toContain('data-1p-ignore data-lpignore="true" data-bwignore');
});

it('keeps saved logins out of the admin secret fields', function () {
    $fields = [
        'views/admin/partials/translation-screens.blade.php' => 'id="tr-key" autocomplete="new-password"',
        'views/admin/partials/instagram-screen.blade.php' => 'id="igs-secret" type="password" autocomplete="new-password"',
        'views/admin/partials/marketing-pixels-connect.blade.php' => '<input type="password" autocomplete="new-password"',
    ];

    foreach ($fields as $file => $needle) {
        expect((string) file_get_contents(resource_path($file)))->toContain($needle);
    }

    expect((string) file_get_contents(resource_path('views/admin/partials/instagram-screen.blade.php')))
        ->toContain('id="igs-fbsecret" type="password" autocomplete="new-password"');
});
