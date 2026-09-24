<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\RedirectsApiController;
use App\Models\AdminUser;

/**
 * What a redirect's destination may be, now that a row fires before the router.
 *
 * THE EXPOSURE CHANGED UNDER THIS CONTROLLER WITHOUT IT BEING TOUCHED. Until
 * `CheckRedirects` was registered in the global pipeline, a row only ever fired
 * on an address that already 404'd. It now fires on addresses the shop serves.
 * Meanwhile `target` was validated as `string|max:2048` and went straight into
 * a `Location` header — so this project's own rule, that a URL from a setting
 * is scheme-checked before it becomes a destination, had never been applied to
 * the one table whose entire purpose is to be a destination.
 *
 * A REDIRECT TABLE LEGITIMATELY POINTS OFF-SITE, so the rule is an allowlist of
 * schemes rather than a same-host check: a shop that moved a policy page to its
 * parent company's domain is doing a normal thing.
 *
 * The lane that registered the middleware found this and correctly did not
 * reach into a file it did not own. This is the integrator taking it.
 *
 * MUTATION: delete the `regex` line from
 * `RedirectsApiController::TARGET_RULES` and every refusal below goes green,
 * which is the defect.
 */
function rtOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'RT Owner',
        'email' => 'rt-owner@example.test',
        'password' => 'rt-owner-password',
        'role' => 'owner',
    ]);
}

it('accepts the shapes a real redirect table needs', function (string $target) {
    test()->actingAs(rtOwner(), 'admin')
        ->postJson('/admin-api/redirects', [
            'source' => '/old-'.md5($target).'/',
            'target' => $target,
            'code' => 301,
        ])
        ->assertOk();
})->with([
    'a site-relative path' => '/shop/',
    'a nested path' => '/product-category/skincare/toners/',
    'the site root' => '/',
    // Off-site is allowed on purpose: moving a policy page to a parent
    // company's domain is a normal thing for a shop to do.
    'an https URL' => 'https://example.com/policies/returns',
    'an http URL' => 'http://example.com',
]);

it('refuses a destination the browser would treat as code or as content', function (string $target) {
    test()->actingAs(rtOwner(), 'admin')
        ->postJson('/admin-api/redirects', [
            'source' => '/old-'.md5($target).'/',
            'target' => $target,
            'code' => 301,
        ])
        ->assertStatus(422);
})->with([
    'javascript' => 'javascript:alert(1)',
    // Case matters: a scheme is case-insensitive and a check that is not,
    // is not a check.
    'javascript, mixed case' => 'JaVaScRiPt:alert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'vbscript' => 'vbscript:msgbox',
    'file' => 'file:///etc/passwd',
    // Protocol-relative: reads as a path, is not one. `//evil.test/x` sends a
    // shopper off the shop entirely while looking like a slash-prefixed route.
    'protocol-relative' => '//evil.test/x',
    'no leading slash at all' => 'shop/',
    // A newline in a Location header is response splitting.
    'a CRLF' => "/ok\r\nLocation: https://evil.test",
    'a bare newline' => "/ok\nSet-Cookie: a=b",
]);

it('states the rule once, so the two writers cannot drift apart', function () {
    $source = (string) file_get_contents(
        app_path('Http/Controllers/Admin/RedirectsApiController.php'),
    );

    /*
     * `store()` and `update()` each had their own copy of
     * `['required','string','max:2048']`. Two copies of a validation rule is
     * how one of them eventually stops matching the other — and the one that
     * stops matching is whichever the tests happen not to drive.
     */
    expect(RedirectsApiController::TARGET_RULES)->toBeArray()
        ->and(substr_count($source, 'self::TARGET_RULES'))->toBe(2)
        ->and($source)->not->toContain("'target' => ['required', 'string', 'max:2048']");
});
