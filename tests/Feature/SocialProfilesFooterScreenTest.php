<?php

declare(strict_types=1);

/*
 * Appearance → Footer → Social profiles (Lane QK2).
 *
 * The owner, on the Footer screen: "we don't have any social profiles
 * anywhere". The addresses existed only at Store → SEO & Meta → Settings →
 * Social profiles, the live shop had them empty, and so no icon showed in the
 * footer, no Instagram card on the Contact page, nothing in the emails.
 *
 * The Footer screen now edits the SAME global `social_*` rows, through the same
 * door the SEO screen uses (PUT admin-api/settings, capability store.settings),
 * which now refuses anything but an http/https address.
 */

use App\Http\Controllers\Admin\AdminController;
use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteFooter;
use App\Support\SocialProfiles;
use Illuminate\Support\Str;

function qkForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

function qkAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'QK '.$role, 'email' => 'qk-'.$role.'-'.Str::random(6).'@example.test',
        'password' => bcrypt('password-long-enough'), 'role' => $role,
    ]);
}

function qkRow(string $key): ?string
{
    $v = Setting::query()->where('key', $key)->value('value');

    return $v === null ? null : (string) $v;
}

function qkContact(): string
{
    qkForget();
    if (auth('admin')->check()) {
        auth('admin')->logout();
    }

    return (string) test()->get('/contact-us/')->assertOk()->getContent();
}

beforeEach(function () {
    $this->withoutDefer();
    // The live shop: every profile saved empty.
    foreach (array_keys(SocialProfiles::FIELDS) as $key) {
        app(SettingsService::class)->set($key, '');
    }
    qkForget();
});

it('saves an Instagram address from the Footer screen into the global key, and the Contact page then shows the Instagram card', function () {
    /*
     * The defect: the Footer screen had no social fields at all, and the shop
     * showed no Instagram card anywhere. MUTATION: drop `'socials' =>` from
     * SlimFooterApiController::show() -> red on the payload; save to a
     * `sitefooter_social_instagram` copy instead -> red on the Contact page,
     * which reads the global key.
     */
    expect(qkContact())->not->toContain('data-ct="ig"');

    $owner = qkAdmin('owner');
    $this->actingAs($owner, 'admin');

    $body = $this->getJson('/admin-api/slim-footer')->assertOk()->json('socials');
    expect($body['editable'])->toBeTrue()
        ->and(array_column($body['fields'], 'key'))->toBe(array_keys(SocialProfiles::FIELDS))
        ->and(array_column($body['fields'], 'value'))->each->toBe('');

    // Exactly what the screen sends: only the box that moved, PUT, settings.
    $this->putJson('/admin-api/settings', ['settings' => ['social_instagram' => 'https://www.instagram.com/my.shop/']])
        ->assertOk()->assertJson(['ok' => true, 'saved' => 1]);

    expect(qkRow('social_instagram'))->toBe('https://www.instagram.com/my.shop/')
        ->and(Setting::query()->where('key', 'like', '%sitefooter%social%')->count())->toBe(0);

    qkForget();
    $again = $this->getJson('/admin-api/slim-footer')->json('socials.fields.0');
    expect($again['key'])->toBe('social_instagram')->and($again['value'])->toBe('https://www.instagram.com/my.shop/');

    $html = qkContact();
    expect($html)->toContain('data-ct="ig"')
        ->and($html)->toContain('href="https://ig.me/m/my.shop"')
        ->and($html)->toContain('@my.shop');
});

it('refuses a javascript: address, and every other non-web one, writing nothing', function () {
    /*
     * The defect: the settings endpoint stored social_* as free `text`, so
     * `javascript:alert(1)` was saved as happily as a profile. MUTATION: set the
     * six keys back to 'text' in AdminController::SETTING_RULES -> red.
     */
    $owner = qkAdmin('owner');
    $this->actingAs($owner, 'admin');

    foreach ([
        'javascript:alert(1)',
        'JaVaScRiPt:alert(1)',
        'data:text/html,<script>alert(1)</script>',
        'mailto:hello@example.com',
        'instagram.com/my.shop',
        'https://insta gram.com/x',
        'https://'.str_repeat('a', 500).'.com',
    ] as $bad) {
        $this->putJson('/admin-api/settings', ['settings' => ['social_instagram' => $bad, 'social_tiktok' => 'https://www.tiktok.com/@ok']])
            ->assertStatus(422)->assertJsonPath('ok', false);

        // Nothing written, not even the valid key beside it.
        expect(qkRow('social_instagram'))->toBe('', $bad)->and(qkRow('social_tiktok'))->toBe('', $bad);
    }

    expect(qkContact())->not->toContain('data-ct="ig"');

    // Empty still clears; an UNCHANGED legacy value posted back by the SEO tab
    // still passes (MUTATION: drop the $stored check in `profileurl` -> red).
    $this->actingAs($owner, 'admin');
    Setting::query()->where('key', 'social_linkedin')->update(['value' => 'linkedin.com/company/x']);
    $this->putJson('/admin-api/settings', ['settings' => ['social_youtube' => '', 'social_linkedin' => 'linkedin.com/company/x']])
        ->assertOk();
});

it('fails closed for a role that may edit the footer but not Store settings', function () {
    /*
     * An editor holds slimfooter.manage and not store.settings. MUTATION: give
     * the save its own route under slimfooter.manage, or compute `editable`
     * as true -> red.
     */
    $editor = qkAdmin('editor');
    $this->actingAs($editor, 'admin');

    $socials = $this->getJson('/admin-api/slim-footer')->assertOk()->json('socials');
    expect($socials['editable'])->toBeFalse();

    $this->putJson('/admin-api/settings', ['settings' => ['social_instagram' => 'https://www.instagram.com/hijack/']])
        ->assertForbidden();

    expect(qkRow('social_instagram'))->toBe('');
});

it('opens on what the shop prints for a profile whose row was never written', function () {
    /*
     * An empty box means "remove this icon", so a key with no row must open
     * showing the footer's own fallback, or a Save nobody typed into strips the
     * icon. MUTATION: change a SHOP_DEFAULTS entry -> red.
     */
    Setting::query()->whereIn('key', array_keys(SocialProfiles::FIELDS))->delete();
    qkForget();

    $s = app(SettingsService::class);
    $shop = [];
    foreach (SiteFooter::socials($s) as [$name, , $href]) {
        $shop['social_'.$name] = $href;
    }

    expect(array_filter(SocialProfiles::values($s)))->toBe($shop);
});

it('draws the section on the Footer screen and points to it from the SEO and Contact screens', function () {
    $footer = file_get_contents(resource_path('views/admin/partials/slim-footer-screen.blade.php'));
    $app = file_get_contents(resource_path('views/admin/app.blade.php'));
    $contact = file_get_contents(resource_path('views/admin/partials/contact-inquiries-screen.blade.php'));

    expect($footer)->toContain("<h3>Social profiles</h3>")
        ->and($footer)->toContain('The same links as Store → SEO &amp; Meta → Social profiles; changing them here changes them everywhere (footer icons, Contact page, emails, Google).')
        ->and($footer)->toContain("api('/settings', { settings: payload }, undefined, 'PUT')")
        ->and(substr_count($footer, 'function socialsHTML('))->toBe(1)
        ->and(substr_count($app, 'Also editable at Appearance → Footer.'))->toBe(1)
        ->and(substr_count($contact, 'Shows when Instagram is filled in at Appearance → Footer → Social profiles.'))->toBe(1);

    // The six the SEO screen posts are the six this screen edits, and all six
    // carry the URL rule.
    foreach (array_keys(SocialProfiles::FIELDS) as $key) {
        expect(AdminController::SETTING_RULES[$key][0])->toBe('profileurl')
            ->and($app)->toContain('social_'.substr($key, 7).':sval(');
    }
});
