<?php

declare(strict_types=1);

/**
 * Lane PS -- "Minify CSS: Est savings of 3 KiB".
 *
 * WHAT GOOGLE REPORTED (PageSpeed Insights, extrabeauty.ae, 5 Oct 2026, both
 * tabs): the inline stylesheet that starts
 *
 *   /* Prefix kbbs-, used nowhere else in this application. (The cards banner…
 *
 * -- the homepage picture slider's own <style>, 4.0 KiB on the wire of which
 * 2.5 KiB was comments. Measured on the Lane PS preview the slider's <style>
 * and <script> carried 16,224 bytes of CSS/JS comments to every visitor, inside
 * the HTML document itself: 30,411 -> 23,423 bytes gzipped once they became
 * Blade comments, the rendered page otherwise byte-identical (compared with the
 * old output minus exactly those comments).
 *
 * The comments are kept -- as {{-- --}}, which Blade drops at compile time.
 *
 * MUTATION NOTE: turn any one of them back into a CSS or JS comment and the
 * first case is red, naming the line.
 */
function sicBlocks(): array
{
    $src = (string) file_get_contents(resource_path('views/partials/home/slider-banner.blade.php'));
    preg_match_all('#^<(style|script)>$(.*?)^</\1>#ms', $src, $m, PREG_SET_ORDER);

    return $m;
}

it('ships the slider\'s own <style> and <script> without CSS or JS comments', function () {
    $blocks = sicBlocks();
    expect($blocks)->toHaveCount(2, 'expected the slider partial\'s one <style> and one <script>');

    foreach ($blocks as [, $tag, $body]) {
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $body);
        expect(preg_match('#/\*#', $code, $hit, PREG_OFFSET_CAPTURE))->toBe(0,
            "a /* comment is back in the slider's <{$tag}> and is sent to every visitor: "
            .($hit ? substr($code, (int) $hit[0][1], 80) : ''));
    }
});

it('still carries every rule and the whole script', function () {
    [$style, $script] = sicBlocks();

    expect($style[2])->toContain('.kbbs{--kbbs-gut:var(--site-gutter,18px);position:relative}')
        ->and($style[2])->toContain('@media (min-width:768px){.kbbs-vp{aspect-ratio:var(--kbbs-ar,16 / 9);max-height:var(--kbbs-hd,none)}}')
        ->and(substr_count($style[2], '{'))->toBe(substr_count($style[2], '}'))
        ->and($script[2])->toContain('(function');
});
