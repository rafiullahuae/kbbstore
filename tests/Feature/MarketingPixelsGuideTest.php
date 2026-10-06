<?php

declare(strict_types=1);

use App\Services\MarketingPixels;
use App\Support\AdminConsoleAssets;

/**
 * Growth & Marketing → Marketing Pixels → the eye in front of each ID. (Lane PX)
 *
 * The owner: "on this page, i need proper guide for each one, along with live
 * urls to create these things. make a beautiful popup eye icon infront of each
 * one." Before this the screen had three bare boxes and a help line each; an
 * owner who did not already know where Meta hides a pixel ID had nowhere to
 * start. These cases fail on the screen as it was (no partial, no guide).
 *
 * The wiring is pinned at its FINISHED state (CLAUDE.md): docs/px-wiring.json
 * is applied in memory where the integrator has not run tools/px-wire.php, so
 * the include count is 1 in the lane and after the merge.
 */
const PXG_HOSTS = [
    'business.facebook.com', 'www.facebook.com', 'developers.facebook.com',
    'analytics.google.com', 'support.google.com',
    'ads.tiktok.com',
];

function pxgHtml(): string
{
    static $html = null;

    return $html ??= view('admin.partials.marketing-pixels-guide')->render();
}

/** @return array<string, string> template id key => its inner HTML */
function pxgGuides(): array
{
    preg_match_all('~<template id="pxg-t-([a-z0-9_]+)" data-name="([^"]*)">(.*?)</template>~s', pxgHtml(), $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as [, $key, $name, $body]) {
        $out[$key] = ['name' => html_entity_decode($name), 'body' => $body];
    }

    return $out;
}

function pxgWiredConsole(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $edits = json_decode((string) file_get_contents(base_path('docs/px-wiring.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($edits as $e) {
        if (str_contains($src, $e['replacement'])) {
            continue;   // the integrator has applied it
        }
        expect(substr_count($src, $e['anchor']))->toBe($e['count'], "block {$e['n']}: anchor moved");
        $src = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return $src;
}

it('has one guide per field, named for that field, and nothing else', function () {
    /* MUTATION: drop the tiktok_id entry from $pxgGuides in the partial -> red (two guides for three fields). */
    $guides = pxgGuides();

    expect(array_keys($guides))->toBe(array_keys(MarketingPixels::SCHEMA));

    foreach (MarketingPixels::SCHEMA as $key => $def) {
        expect($guides[$key]['name'])->toBe('How to get your '.$def['label'])
            ->and($guides[$key]['body'])->toContain('<ol class="pxg-steps">')
            ->and(substr_count($guides[$key]['body'], '<li>'))->toBeGreaterThanOrEqual(3 + 3); // steps + links
    }
});

it('puts an eye button in front of each field label that opens that field\'s own guide', function () {
    /*
     * The buttons are made by the partial's script from the screen's own
     * markup, so this pins both halves of that contract: mpField() still
     * renders the label inside .ecl and the input with data-mp="<key>", and the
     * script finds every [data-mp], puts a button FIRST in its row's .ecl, names
     * it from the guide (aria-label "How to get your Meta Pixel ID") and opens
     * the template keyed by that same data-mp.
     * MUTATION: change 'pxg-t-' + key to a fixed id -> red; every eye would open one guide.
     */
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    preg_match('~function mpField\(f\)\{.*?\n\}~s', $console, $mp);
    expect($mp)->not->toBeEmpty()
        ->and($mp[0])->toContain('<div class="ecl"><label>')
        ->and($mp[0])->toContain('data-mp="${f.key}"');

    $html = pxgHtml();
    expect($html)->toContain("document.querySelectorAll('#content [data-mp]')")
        ->and($html)->toContain("document.getElementById('pxg-t-' + key)")
        ->and($html)->toContain("ecl.insertBefore(b, ecl.firstChild)")
        ->and($html)->toContain("b.setAttribute('aria-label', tpl.getAttribute('data-name'))")
        ->and($html)->toContain("b.onclick = function () { open(this.getAttribute('data-pxg'), this); }")
        // the dialog: Esc, the backdrop, ×, a Tab trap, focus returned
        ->and($html)->toContain("e.key === 'Escape'")
        ->and($html)->toContain('if (t === bg || t.closest(\'[data-pxg-close]\'))')
        ->and($html)->toContain("if (e.key !== 'Tab') return;")
        ->and($html)->toContain('opener.focus()')
        ->and($html)->toContain('role="dialog" aria-modal="true"')
        // phones get a bottom sheet
        ->and($html)->toContain('@media (max-width:640px){')
        ->and($html)->toContain('.pxg-bg{align-items:flex-end;padding:0}');

    // It wraps paintPixels once, guarded against wrapping its own wrapper.
    expect(substr_count($html, 'window.paintPixels = wrapped;'))->toBe(1)
        ->and($html)->toContain("window.paintPixels.__pxg) return;");
});

it('links only to official Meta, Google and TikTok pages, over https, in a new tab', function () {
    /* MUTATION: point a link at bit.ly, or drop noreferrer from the template -> red. */
    preg_match_all('~<a\s([^>]*)>~', pxgHtml(), $m);
    $external = 0;

    foreach ($m[1] as $attrs) {
        preg_match('~href="([^"]*)"~', $attrs, $h);
        $href = html_entity_decode($h[1] ?? '');

        if ($href === '#modules') {
            expect($attrs)->toContain('data-pxg-go');   // the in-console link to Store → Modules

            continue;
        }

        $external++;
        $parts = parse_url($href);
        expect($parts['scheme'] ?? null)->toBe('https', $href)
            ->and(in_array($parts['host'] ?? '', PXG_HOSTS, true))->toBeTrue("{$href} is not on the allowlist")
            ->and($attrs)->toContain('target="_blank"')
            ->and($attrs)->toContain('rel="noopener noreferrer"');
    }

    expect($external)->toBe(13);

    // Each guide opens its own console and at least two of its own help pages.
    $want = ['meta_id' => 'facebook.com', 'ga4_id' => 'google.com', 'tiktok_id' => 'ads.tiktok.com'];
    foreach (pxgGuides() as $key => $g) {
        preg_match_all('~href="(https://[^"]+)"~', $g['body'], $l);
        expect(count($l[1]))->toBeGreaterThanOrEqual(3);
        foreach ($l[1] as $u) {
            expect(str_ends_with((string) parse_url($u, PHP_URL_HOST), $want[$key]))->toBeTrue("{$key} links off-network: {$u}");
        }
    }
});

it('tells the owner the module must be on, linked to Store → Modules, in every guide', function () {
    foreach (pxgGuides() as $key => $g) {
        expect($g['body'])->toContain('<a href="#modules" data-pxg-go>Store → Modules → Marketing Pixels</a>');
    }
    expect(pxgHtml())->toContain("window.go('modules')")
        ->and(pxgHtml())->toContain("MP.module_on");
});

it('lists the events the shop really fires, read from the field\'s own help line', function () {
    /* MUTATION: hard-code the chips and rename an event in MarketingPixels::SCHEMA -> red. */
    $want = [
        'meta_id' => ['PageView', 'ViewContent', 'InitiateCheckout', 'Purchase'],
        'ga4_id' => ['page_view', 'view_item', 'begin_checkout', 'purchase'],
        'tiktok_id' => ['page browse', 'CompletePayment'],
    ];

    foreach (pxgGuides() as $key => $g) {
        preg_match('~<p class="pxg-ev">(.*?)</p>~s', $g['body'], $p);
        preg_match_all('~<span>([^<]*)</span>~', $p[1] ?? '', $chips);
        expect($chips[1])->toBe($want[$key]);
        foreach ($want[$key] as $ev) {
            expect(MarketingPixels::SCHEMA[$key]['help'])->toContain($ev);
        }
    }
});

it('shows ID samples in the shapes the networks issue and this shop accepts', function () {
    $sample = fn (string $key) => preg_match('~<code class="pxg-code">([^<]*)</code>~', pxgGuides()[$key]['body'], $m) ? $m[1] : '';

    expect($sample('meta_id'))->toMatch('/^\d{15,16}$/')
        ->and($sample('ga4_id'))->toMatch('/^G-[A-Z0-9]{4,24}$/')    // Analytics::validId()'s G- shape
        ->and($sample('tiktok_id'))->toMatch('/^C[A-Z0-9]{19}$/');
});

it('stays light: no request, no timer, both blocks inline under the asset threshold', function () {
    $html = pxgHtml();

    expect($html)->not->toContain('fetch(')
        ->and($html)->not->toContain('XMLHttpRequest')
        ->and($html)->not->toContain('setInterval')
        ->and($html)->not->toContain('getBoundingClientRect')
        ->and(AdminConsoleAssets::blocks($html))->toBe([]);   // nothing for `npx vite build` to hold
});

it('is included in the console exactly once, after the script that defines paintPixels', function () {
    /* MUTATION: duplicate the include line in docs/px-wiring.json's replacement -> red (count 2). */
    $console = pxgWiredConsole();

    expect(substr_count($console, "@include('admin.partials.marketing-pixels-guide')"))->toBe(1)
        ->and(strpos($console, "@include('admin.partials.marketing-pixels-guide')"))
        ->toBeGreaterThan(strpos($console, 'function paintPixels(){'));
});
