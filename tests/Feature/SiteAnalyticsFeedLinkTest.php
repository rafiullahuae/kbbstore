<?php

/**
 * Analytics -> Live -> Happening now: a viewed page opens the shop page.
 *
 * The owner, on a screenshot of the feed: "these should also be linked to the
 * original pages, so i can click on them and see what page it is." Before,
 * every row was plain text -- "medicube - PDRN Pink Collagen Jelly Eye Mask..."
 * with no way to reach the page.
 *
 * The path is what a visitor's browser reported, so the link is built only
 * for a same-site path and on this site's own origin. The behaviour is run in
 * node when it is installed (it is on CI and every lane machine); the pins
 * below hold without it.
 */

use Symfony\Component\Process\Process;

function sfaScreen(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/site-analytics-screen.blade.php'));
}

it('links a viewed page in the feed, in a new tab, with noopener (MUTATION: drop pageLink() from paintFeed and this is red)', function () {
    $src = sfaScreen();

    expect($src)->toContain(": pageLink(f.path, esc(f.title || path(f.path)));")
        ->and($src)->toContain('target="_blank" rel="noopener noreferrer"')
        ->and($src)->toContain("esc(location.origin + p)");
});

it('never turns a hostile path into a link: other hosts, javascript:, spaces stay text; quotes are escaped', function () {
    $src = sfaScreen();
    $esc = substr($src, strpos($src, 'function esc(s)'));
    $esc = substr($esc, 0, strpos($esc, "});") + 3) . "\n  }";
    $fn = substr($src, strpos($src, 'function pageLink'), strpos($src, 'function paintFeed') - strpos($src, 'function pageLink'));

    $cases = [
        '/product/jumiso-awe-sun-airy-fit-sunscreen/' => 'href="https://shop.test/product/jumiso-awe-sun-airy-fit-sunscreen/"',
        '//evil.com/x' => 'T',
        '/\\evil.com' => 'T',
        'javascript:alert(1)' => 'T',
        'https://evil.com' => 'T',
        '/a b' => 'T',
        '/a"><script>' => 'href="https://shop.test/a&quot;&gt;&lt;script&gt;"',
    ];

    $js = $esc."\nvar location = { origin: 'https://shop.test' };\n".$fn
        ."\nprocess.stdout.write(JSON.stringify(".json_encode(array_keys($cases)).".map(function (p) { return pageLink(p, 'T'); })));";

    $node = new Process(['node', '-e', $js]);
    $node->run();

    if (! $node->isSuccessful() && str_contains($node->getErrorOutput(), 'not found')) {
        $this->markTestSkipped('node is not installed here');
    }

    $out = json_decode($node->getOutput(), true);
    expect($out)->toBeArray();

    foreach (array_values($cases) as $i => $want) {
        $want === 'T' ? expect($out[$i])->toBe('T') : expect($out[$i])->toContain($want);
    }
});
