<?php

declare(strict_types=1);

/**
 * Lane DD — every verification token the SEO screen collects reaches the page.
 *
 * Store -> SEO & Meta -> "Verification & tracking" offers four boxes: Google,
 * Bing, Pinterest and Baidu. All four are written by the screen and all four
 * are on AdminController::SETTING_RULES, so all four saved. Only two of them
 * were ever rendered: Seo::render() emitted google-site-verification and
 * msvalidate.01 and stopped there.
 *
 * So `pinterest_site_verification` and `baidu_site_verification` were keys a
 * screen wrote and nothing read. The screen said "Saved", the token sat in the
 * settings table, the tag never appeared in the <head>, and the owner's
 * Pinterest and Baidu verification failed with nothing on either screen to say
 * why. The same shape as the search tab that round-tripped perfectly and
 * changed nothing.
 *
 * The assertions below go through Seo::render() rather than reading the source
 * of Seo.php: a source scan would pass on a line inside a comment, which is how
 * several guards in this repo have been fooled.
 */

use App\Http\Controllers\Admin\AdminController;
use App\Models\Setting;
use App\Support\Seo;

function verifSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

it('renders the Pinterest verification tag the SEO screen saved', function () {
    verifSettings(['pinterest_site_verification' => 'abc123pinterest']);

    $html = Seo::render(['title' => 'Home']);

    expect(str_contains($html, '<meta name="p:domain_verify" content="abc123pinterest">'))
        ->toBeTrue('Store -> SEO & Meta saves a Pinterest token; nothing put it in the <head>.');
});

it('renders the Baidu verification tag the SEO screen saved', function () {
    verifSettings(['baidu_site_verification' => 'abc123baidu']);

    $html = Seo::render(['title' => 'Home']);

    expect(str_contains($html, '<meta name="baidu-site-verification" content="abc123baidu">'))
        ->toBeTrue('Store -> SEO & Meta saves a Baidu token; nothing put it in the <head>.');
});

it('emits no verification tag for a token that was never set', function () {
    verifSettings([
        'pinterest_site_verification' => '',
        'baidu_site_verification' => '',
    ]);

    $html = Seo::render(['title' => 'Home']);

    expect(str_contains($html, 'p:domain_verify'))->toBeFalse('An empty token must not print an empty tag.');
    expect(str_contains($html, 'baidu-site-verification'))->toBeFalse('An empty token must not print an empty tag.');
});

it('escapes a verification token rather than letting it close the tag', function () {
    verifSettings(['pinterest_site_verification' => 'a"><script>alert(1)</script>']);

    $html = Seo::render(['title' => 'Home']);

    expect(str_contains($html, '<script>alert(1)</script>'))
        ->toBeFalse('A settings value lands inside a meta content attribute and is untrusted there.');
});

/**
 * The standing guard. Every `*_site_verification` key the settings endpoint
 * accepts has to reach the page, or it is another box that saves into nothing.
 * Driven off SETTING_RULES so a fifth search engine added to that list cannot
 * ship without its tag.
 */
it('renders every verification token the settings endpoint accepts', function () {
    $keys = array_values(array_filter(
        array_keys(AdminController::SETTING_RULES),
        static fn (string $k) => str_ends_with($k, '_site_verification')
    ));

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $i => $key) {
        $token = 'tok' . $i . 'value';
        verifSettings([$key => $token]);

        $html = Seo::render(['title' => 'Home']);

        expect(str_contains($html, $token))
            ->toBeTrue($key . ' is accepted by PUT /admin-api/settings and never reaches the page.');

        verifSettings([$key => '']);
    }
});
