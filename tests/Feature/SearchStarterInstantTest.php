<?php

declare(strict_types=1);

/**
 * Tapping the search field shows the trending words at once, not after a fetch.
 *
 * THE DEFECT, ON THE LIVE SHOP (2 October 2026). The owner: "the default search
 * tags box showing with little delays upon click on search box. it must be
 * shown immidiately". initSearchStarter()'s focus handler was
 *
 *     const data = await loadStarter();          // GET /api/search/starter
 *     if (renderStarter(panel, data)) panel.classList.add('on');
 *
 * and loadStarter() kept its answer in a page variable, so the first tap on
 * EVERY page waited a full round trip with nothing on screen. Measured in
 * Chromium with the endpoint held for 800 ms: the panel opened 8xx ms after
 * the tap before, and in the same frame after.
 *
 * Now the header prints the trending words into #kbbSuggest[data-starter]
 * (settings only, no query), the focus handler paints from that -- or from this
 * tab's sessionStorage copy -- in the same task, and the fetch runs behind it.
 *
 * MUTATIONS, RUN:
 *   - put `const data = await loadStarter();` back in front of renderStarter()
 *     in the focus handler: the second case is red;
 *   - drop data-starter from partials/header.blade.php: the first case is red.
 */

function ssiFocusHandler(): string
{
    $js = (string) file_get_contents(resource_path('js/kbb/search.js'));
    $start = strpos($js, "input.addEventListener('focus', () => {\n        if (!starterOwnsPanel()) return;");
    expect($start)->not->toBeFalse('the starter focus handler is not where this test looks for it');

    return substr($js, $start, strpos($js, "\n    });", $start) - $start);
}

it('prints the trending words into the header, so the panel needs no fetch to open', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(preg_match('/id="kbbSuggest"[^>]*data-starter="([^"]*)"/s', $html, $m))->toBe(1, 'no data-starter on #kbbSuggest');

    $seed = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    $header = app(\App\Services\HeaderSettings::class);

    expect($seed['trending'])->toBe($header->trendingWords())
        ->and($seed['trending'])->not->toBe([])
        ->and($seed['mobile'])->toBe((int) $header->get('trending_limit_mobile'))
        ->and($seed['layout'])->toBe($header->get('search_panel'));
});

it('opens the panel in the focus handler itself, before any fetch is awaited', function () {
    $handler = ssiFocusHandler();

    expect($handler)->not->toContain('await')
        ->and($handler)->not->toContain('async');

    $paint = strpos($handler, "panel.classList.add('on')");
    $fetch = strpos($handler, 'loadStarter()');

    expect($paint)->not->toBeFalse()
        ->and($fetch)->not->toBeFalse()
        ->and($paint)->toBeLessThan($fetch, 'the panel must be on before the refresh is even asked for');
});

it('reads the seed and the tab copy without trusting either', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/search.js'));

    // Both parses are guarded: a blocked sessionStorage (Safari private mode)
    // or a malformed attribute must fall through, never stop the panel.
    expect(substr_count($js, 'JSON.parse(sessionStorage.getItem(key)'))->toBe(1)
        ->and(substr_count($js, 'JSON.parse(panel.dataset.starter'))->toBe(1)
        ->and($js)->toContain('Date.now() - kept.at < STARTER_TTL')
        // The words are still escaped on the way into the DOM, seeded or not.
        ->and($js)->toContain('data-sg-term="${escapeHtml(t)}">${escapeHtml(t)}</a>');
});
