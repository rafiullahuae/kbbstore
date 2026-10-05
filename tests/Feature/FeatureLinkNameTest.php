<?php

declare(strict_types=1);

use App\Services\SettingsService;

/**
 * Lane PS -- "Links do not have a discernible name".
 *
 * WHAT GOOGLE REPORTED (PageSpeed Insights, extrabeauty.ae, 5 Oct 2026,
 * Accessibility, both tabs; and again as "Accessibility tree is not
 * well-formed" for AI agents):
 *
 *   div.wrap > div.hs-feat > div.hs-fp > a.hs-fim
 *   <a class="hs-fim" href="/collections/skincare/sunscreens/" tabindex="-1"
 *      style="background:linear-gradient(135deg,#ffe9a8,#f3c969)">
 *
 * The two-column feature with no photo chosen is an empty link painted with a
 * gradient: a screen reader announces "link" and nothing else. It is now named
 * by the panel's own title; nothing visible changes (an attribute).
 *
 * MUTATION NOTE: remove the `@elseif ($fimName !== '') aria-label=…` branch in
 * partials/home/hs-feature.blade.php and the first case is red.
 */
function flnLinks(): array
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
    $html = (string) test()->get('/')->assertOk()->getContent();
    preg_match_all('#<a class="hs-fim"[^>]*>(.*?)</a>#s', $html, $m, PREG_SET_ORDER);

    return $m;
}

beforeEach(function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
});

it('names a picture link that has no picture by the panel\'s own title', function () {
    $s = app(SettingsService::class);
    $s->set('home_ft_l_img', '');
    $s->set('home_ft_r_img', '');
    $s->set('home_ft_l_title', 'Embrace the Sunshine!');

    $links = flnLinks();
    expect($links)->toHaveCount(2, 'the two-column feature did not render');

    expect($links[0][0])->toContain(' tabindex="-1" aria-label="Embrace the Sunshine!" style="');
    foreach ($links as $link) {
        expect($link[1])->toBe('')
            ->and(preg_match('/ aria-label="[^"]+"| aria-hidden="true"/', $link[0]))->toBe(1, 'a nameless link: '.$link[0]);
    }
});

it('leaves a link that carries a described photo exactly as it was', function () {
    $s = app(SettingsService::class);
    $s->set('home_ft_l_img', '/uploads/fln/one.webp');
    $s->set('home_ft_l_alt', 'Sunscreens in the sun');

    $first = flnLinks()[0][0];

    expect($first)->not->toContain('aria-label')
        ->and($first)->not->toContain('aria-hidden')
        ->and($first)->toContain('alt="Sunscreens in the sun"');
});
