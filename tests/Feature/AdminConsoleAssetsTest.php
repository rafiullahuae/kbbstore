<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\AdminConsoleAssets;

/**
 * Lane AP -- the admin console's static <script>/<style> blocks as cached
 * files, and why that can never serve the wrong code.
 *
 * What it answers: the console was a 4.76 MB document (1.35 MB gzip) sent in
 * full on every load, never cached -- about 6 s to DOMContentLoaded on the
 * throttled profile however often the owner had opened it. Served from the
 * cached build files a normal visit is ~343 KB / 81 KB gzip and ready in ~1.4 s.
 *
 * MUTATION NOTES, each one run:
 *   - key() hashing anything but the exact bytes (e.g. trim($content))
 *       -> 'swaps a block only for the file holding its exact bytes' fails.
 *   - blocks() accepting `<script type="...">`
 *       -> 'never touches a typed script' fails.
 *   - drop doubleEscapes() from blocks()
 *       -> 'never touches a block the parser could read past' fails.
 *   - serve() externalizing without the cookie
 *       -> 'serves inline to a browser that cannot hold the files' fails.
 *   - serve() ignoring the no-cache request header
 *       -> the hard-refresh case fails.
 */
function aaBlock(string $kind, string $fill = 'x'): string
{
    $body = $kind === 'style'
        ? "\n.a{color:red}\n".str_repeat('/* '.$fill.' */', 1200)
        : "\nvar a=1;\n".str_repeat('/* '.$fill.' */', 1200);

    return '<'.$kind.'>'.$body.'</'.$kind.'>';
}

function aaContent(string $block): string
{
    return (string) preg_replace('#^<(script|style)>|</(script|style)>$#', '', $block);
}

it('swaps a block only for the file holding its exact bytes, keeping everything else in place', function () {
    $js = aaBlock('script', 'a');
    $css = aaBlock('style', 'b');
    $other = aaBlock('script', 'c');
    $html = "<html><head>{$css}</head><body><p>one</p>{$js}<p>two</p>{$other}</body></html>";

    $manifest = [
        AdminConsoleAssets::key('script', aaContent($js)) => ['file' => 'assets/admin-js.js'],
        AdminConsoleAssets::key('style', aaContent($css)) => ['file' => 'assets/admin-css.css'],
    ];
    $out = AdminConsoleAssets::externalize($html, $manifest);

    expect($out)->toContain('<link rel="stylesheet" href="/build/assets/admin-css.css">')
        ->and($out)->toContain('<script src="/build/assets/admin-js.js"></script>')
        ->and($out)->not->toContain(aaContent($js))
        ->and($out)->not->toContain(aaContent($css))
        // The block the build does not hold stays exactly as it was.
        ->and($out)->toContain($other)
        // Order: head link, then <p>one</p>, then the script, then <p>two</p>.
        ->and(strpos($out, '<link'))->toBeLessThan(strpos($out, '<p>one</p>'))
        ->and(strpos($out, '<p>one</p>'))->toBeLessThan(strpos($out, '<script src='))
        ->and(strpos($out, '<script src='))->toBeLessThan(strpos($out, '<p>two</p>'));

    // One byte different and the key no longer matches.
    expect(AdminConsoleAssets::key('script', aaContent($js).' '))->not->toBe(AdminConsoleAssets::key('script', aaContent($js)));
});

it('never touches a typed script, a small block, or a block inside an HTML comment', function () {
    $big = aaContent(aaBlock('script'));
    $html = '<script type="application/json">'.$big.'</script>'
        .'<script type="module">'.$big.'</script>'
        .'<!-- <script>'.$big.'</script> -->'
        .'<script>var small=1;</script>';

    expect(AdminConsoleAssets::blocks($html))->toBe([]);
});

it('never touches a block the HTML parser could read past', function () {
    // '<!--' then '<script' inside a script: the parser's double-escaped
    // state, where the first '</script>' need not end the element.
    $body = aaContent(aaBlock('script')).'var s="<!-- <script>";';
    expect(AdminConsoleAssets::doubleEscapes($body))->toBeTrue()
        ->and(AdminConsoleAssets::blocks('<script>'.$body.'</script>'))->toBe([]);

    // A closed comment before a '<script' mention is harmless.
    expect(AdminConsoleAssets::doubleEscapes('a="<!-- x -->"; b="<script>";'))->toBeFalse();
});

it('serves inline to a browser that cannot hold the files, and cached files to one that does', function () {
    config(['kbb.admin_external_assets' => true]);
    $owner = AdminUser::create(['name' => 'AA Owner', 'email' => 'aa-owner@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);

    // First visit: no cookie. Inline, as before, plus the after-load prefetch
    // and the cookie that says the files are on their way.
    $first = $this->actingAs($owner, 'admin')->get('/admin')->assertOk();
    $inline = $first->getContent();
    $first->assertCookie(AdminConsoleAssets::COOKIE, AdminConsoleAssets::buildId());
    expect($inline)->toContain('const $=(s,r=document)=>r.querySelector(s);')
        ->and($inline)->toContain('l.rel="prefetch"')
        ->and($inline)->not->toContain('<script src="/build/assets/admin-');

    // A later visit with the cookie: the blocks the build holds are files.
    $warm = $this->withCookie(AdminConsoleAssets::COOKIE, AdminConsoleAssets::buildId())->get('/admin')->assertOk()->getContent();
    preg_match_all('#<(?:script src|link rel="stylesheet" href)="[^"]*/build/assets/admin-[^"]+"#', $warm, $m);
    expect(count($m[0]))->toBeGreaterThan(0)
        ->and(strlen($warm))->toBeLessThan(intdiv(strlen($inline), 2));

    // Every file it names exists in the build.
    foreach ($m[0] as $tag) {
        preg_match('#/build/(assets/admin-[^"]+)#', $tag, $f);
        expect(is_file(public_path('build/'.$f[1])))->toBeTrue($f[1].' is named but not in public/build');
    }

    // A hard refresh says no-cache and bypasses the browser's cache, so it is
    // served inline whatever the cookie says.
    $hard = $this->withCookie(AdminConsoleAssets::COOKIE, AdminConsoleAssets::buildId())
        ->withHeaders(['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'])
        ->get('/admin')->assertOk()->getContent();
    expect($hard)->toContain('const $=(s,r=document)=>r.querySelector(s);')
        ->and($hard)->not->toContain('/build/assets/admin-');

    // A cookie for another build is no cookie at all.
    $stale = $this->withCookie(AdminConsoleAssets::COOKIE, 'not-this-one')->withHeaders(['Cache-Control' => 'max-age=0'])->get('/admin')->getContent();
    expect($stale)->toContain('l.rel="prefetch"');

    // And the switch keeps everything inline.
    config(['kbb.admin_external_assets' => false]);
    $off = $this->withCookie(AdminConsoleAssets::COOKIE, AdminConsoleAssets::buildId())->get('/admin')->getContent();
    expect($off)->not->toContain('/build/assets/admin-')->and($off)->not->toContain('l.rel="prefetch"');
});

it('keeps the console\'s own big blocks static, so the build can hold them', function () {
    /*
     * The head stylesheet and the two console script blocks are 1.45 MB of the
     * document. They can be served as files only while they render byte for
     * byte as written -- no @json island inside them. Each must appear in the
     * rendered console exactly as it appears in the template.
     */
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $owner = AdminUser::create(['name' => 'AA Static', 'email' => 'aa-static@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    $html = $this->actingAs($owner, 'admin')->get('/admin')->getContent();

    $big = array_values(array_filter(AdminConsoleAssets::blocks($html), fn ($b) => ($b[2] === 'style' && strlen($b[3]) > 150000)
        || str_starts_with($b[3], "\nconst \$=(s,r=document)=>r.querySelector(s);")
        || str_contains(substr($b[3], 0, 300), 'KBB admin — live wiring')));
    expect($big)->toHaveCount(3);
    foreach ($big as [, , $kind, $content]) {
        expect(str_contains($src, '<'.$kind.'>'.$content.'</'.$kind.'>'))
            ->toBeTrue("a {$kind} block of ".strlen($content).' bytes renders differently from its source, so no build file can match it');
    }

    // And the committed build holds them (rebuild with `npx vite build` after editing them).
    $manifest = AdminConsoleAssets::manifest();
    foreach ($big as [, , $kind, $content]) {
        expect(isset($manifest[AdminConsoleAssets::key($kind, $content)]))->toBeTrue();
    }
})->skip(fn () => ! is_file(public_path('build/manifest.json')), 'no build');
