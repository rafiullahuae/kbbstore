<?php
/*
 * Apply the two lines Lane IM asks the integrator to put in Lane SF's
 * resources/views/store/product.blade.php -- to a COPY, never to the tracked
 * file -- and then check what the copy renders.
 *
 *   php tools/im-sf-apply.php apply <path to the copy's product.blade.php>
 *   php tools/im-sf-apply.php check <path to the fetched html>
 *
 * CLAUDE.md makes a file another lane owns off limits, and a patch handed over
 * as "apply this line" that nobody has run is a patch handed over untested.
 * tools/im-sf-lines.sh drives this against the throwaway application the
 * preview already builds under storage/.
 */
$mode = $argv[1] ?? '';
$path = $argv[2] ?? '';

if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "usage: php tools/im-sf-apply.php apply|check <file>\n");
    exit(1);
}

/** The two substitutions, exactly as the integrator is asked to make them. */
const PAIRS = [
    // product.blade.php:105 -- feeds $mainBg, the sticky bar's 46px square
    // (.sth, kbb-product.css:408).
    [
        '$mainCss = CssUrl::value($gallery[0][\'image\'] ?? null);',
        '$mainCss = CssUrl::value(\App\Support\ImageVariants::variantUrl((string) ($gallery[0][\'image\'] ?? \'\'), 400));',
    ],
    // product.blade.php:308 -- the option swatch, a 22px circle
    // (.vsw, kbb-product.css:194), and the worst ratio on this site.
    [
        '@if (($vImgCss = CssUrl::value($v->image)) !== \'\')',
        '@if (($vImgCss = CssUrl::value(\App\Support\ImageVariants::variantUrl((string) $v->image, 400))) !== \'\')',
    ],
];

if ($mode === 'apply') {
    $source = (string) file_get_contents($path);

    foreach (PAIRS as [$from, $to]) {
        if (substr_count($source, $from) !== 1) {
            fwrite(STDERR, "not found exactly once in product.blade.php:\n  ".$from."\n");
            exit(1);
        }

        $source = str_replace($from, $to, $source);
    }

    file_put_contents($path, $source);
    echo "both lines applied to the preview copy\n";
    exit(0);
}

$html = (string) file_get_contents($path);
$plain = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
$ok = true;

$want = function (bool $condition, string $message) use (&$ok): void {
    if (! $condition) {
        fwrite(STDERR, $message."\n");
        $ok = false;
    }
};

// The sticky bar draws the featured shot, which HAS a 400px copy.
$want(str_contains($plain, "url('/img-cache/400/uploads/im/shot-1.jpg') center/contain"),
    'the sticky bar is not drawing the 400px copy');
$want(! str_contains($plain, "url('/uploads/im/shot-1.jpg') center/contain"),
    'the sticky bar is still drawing the full-size photograph');

// The option swatch, from the variant's own photograph.
$want(str_contains($plain, 'class="vsw"'),
    'no option swatch rendered, so this proved nothing');
$want(str_contains($plain, "background-image:url('/img-cache/400/uploads/im/shot-3.jpg')"),
    'the option swatch is not drawing the 400px copy');

// And the page still renders its gallery.
$want(substr_count($html, 'gthumb-img') >= 5, 'the gallery did not render');

if ($ok) {
    echo "sticky-bar thumbnail (.sth, 46px): 400px copy\n";
    echo "option swatch        (.vsw, 22px): 400px copy\n";
}

exit($ok ? 0 : 1);
