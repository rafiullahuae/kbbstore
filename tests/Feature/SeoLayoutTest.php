<?php

/**
 * The layout is where the settings were being thrown away: it set
 * title_is_final on every page (skipping the title template entirely) and
 * never passed type => 'home', which made seo_home_title and
 * seo_home_description unreachable settings.
 *
 * These go through the real HTTP stack so the assertion is about what a
 * visitor's browser receives, not about what a helper returns.
 */

use App\Models\Setting;

function layoutSettings(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

it('renders the saved homepage title on the home page', function () {
    layoutSettings([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_home_title' => 'Korean skincare, delivered across the UAE',
        'seo_home_description' => 'Authentic K-beauty from Dubai.',
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('<title>Korean skincare, delivered across the UAE</title>')
        ->toContain('Authentic K-beauty from Dubai.')
        ->toContain('<link rel="canonical" href="https://kbeautybliss.test/">');
});

it('applies the title template to an ordinary page', function () {
    layoutSettings([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'Glow Lab',
        'seo_separator' => '–',
        'seo_title_template' => '{title} {sep} {sitename}',
    ]);

    $html = $this->get('/korean-skincare-brands/')->assertOk()->getContent();

    // resources/views/store/brands.blade.php yields the bare title "All brands".
    expect($html)->toContain('<title>All brands – Glow Lab</title>');
});

it('emits an absolute canonical on a listing page', function () {
    layoutSettings(['site_url' => 'https://kbeautybliss.test']);

    $html = $this->get('/korean-skincare-brands/')->assertOk()->getContent();

    // No trailing slash asserted HERE: Laravel's test client trims it from the
    // URI (MakesHttpRequests::prepareUrlForRequest), so getPathInfo() never
    // carries one under the harness. The layout's slash-preservation is proven
    // directly in SeoRenderTest instead.
    expect($html)->toContain('<link rel="canonical" href="https://kbeautybliss.test/korean-skincare-brands')
        ->not->toContain('<link rel="canonical" href="/');
});
