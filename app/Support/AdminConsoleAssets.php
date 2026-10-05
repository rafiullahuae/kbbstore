<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The admin console's large static <script> and <style> blocks, served as
 * cached files instead of inline. Lane AP.
 *
 * ── WHY ───────────────────────────────────────────────────────────────────
 *
 * The console is one document, never cached (PageController sends no-store,
 * on purpose: a stale console is a stale menu). Measured on the preview it is
 * 4.76 MB raw / 1.35 MB gzip, and almost all of it is inline script and style
 * that is the same on every load: the head stylesheet, the console's two
 * script blocks and the screen partials' own blocks. On a 2 Mbit/s line that
 * is about 5.4 s of download on EVERY visit, the hundredth as much as the first.
 *
 * So each such block is also built into public/build by `npx vite build` (the
 * `kbb-admin-console-assets` plugin in vite.config.js), and served from there
 * with the far-future cache the build directory already has. The document
 * keeps its order and its classic-script semantics: an inline <script> becomes
 * a parser-blocking <script src> in the same place, so every global, every
 * hoisted function and every "runs before the next block" relationship is
 * exactly what it was. An inline <style> becomes a <link rel="stylesheet">.
 *
 * ── WHY IT CANNOT SERVE THE WRONG CODE ────────────────────────────────────
 *
 * A block is swapped ONLY when the build holds a file whose manifest key is
 * the SHA-1 of the block's RENDERED bytes. The build hashes what it read from
 * the template source; the server hashes what the template actually rendered.
 * They agree only when the two are byte-identical, so:
 *
 *   - a block with Blade inside it (@json, {{ }}) renders differently from its
 *     source and is never swapped -- it stays inline, as before;
 *   - a template edited after the last asset build no longer matches its old
 *     file and stays inline until the next build -- stale code is never served;
 *   - a block the build never saw stays inline.
 *
 * The failure mode of every mistake here is "inline, as it was". There is also
 * a switch: KBB_ADMIN_EXTERNAL_ASSETS=false keeps every block inline.
 *
 * ── WHEN: ONLY FOR A BROWSER THAT ALREADY HOLDS THE FILES ─────────────────
 *
 * Measured on the throttled profile (4x CPU, 2 Mbit/s): served as files, a
 * normal visit is ready in about 1.0 s instead of 6.2 s, because the files
 * come from the browser's cache. But a browser WITHOUT them -- a first visit,
 * or a hard refresh (Ctrl+Shift+R), which bypasses the cache -- fetches ninety
 * files at once, they share the line, and the dashboard drew at 4.4 s instead
 * of 2.0 s. The owner's own report is about a hard refresh.
 *
 * So the document is served inline exactly as before when the browser cannot
 * have the files: no `kbb_aa` cookie naming this build, or a request that
 * says no-cache (what a hard refresh sends). That first inline load then asks
 * for the files once, AFTER the page has loaded, as prefetches -- lowest
 * priority, nothing on screen waits -- and the cookie says so. Every later
 * normal visit gets the small document and the cached files.
 *
 * ── WHAT COUNTS AS A BLOCK ────────────────────────────────────────────────
 *
 * `<script>` and `<style>` with NO attributes (classic script, all-media
 * style), at least MIN_BYTES long. A typed script (application/json, module)
 * is never touched, nor is a block that could put the parser in its
 * double-escaped state (see doubleEscapes()). The scan skips HTML
 * comments, so "<script>" written inside one is not mistaken for a tag;
 * vite-admin-console-assets.mjs runs the same scan over the template source.
 */
final class AdminConsoleAssets
{
    /** Below this a block stays inline: a request costs more than the bytes. */
    public const MIN_BYTES = 8192;

    /** Manifest keys are PREFIX + sha1(block) + '.js' | '.css'. */
    public const PREFIX = 'admin-console/';

    /**
     * Every candidate block: [start, end, kind, content], start/end spanning
     * the whole element including its tags.
     *
     * @return list<array{0: int, 1: int, 2: string, 3: string}>
     */
    public static function blocks(string $html): array
    {
        $out = [];
        $len = strlen($html);
        $i = 0;

        while (($lt = strpos($html, '<', $i)) !== false) {
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $end = strpos($html, '-->', $lt + 4);
                if ($end === false) {
                    break;
                }
                $i = $end + 3;

                continue;
            }

            $kind = null;
            if (substr_compare($html, '<script', $lt, 7, true) === 0) {
                $kind = 'script';
            } elseif (substr_compare($html, '<style', $lt, 6, true) === 0) {
                $kind = 'style';
            }
            if ($kind === null) {
                $i = $lt + 1;

                continue;
            }

            $after = $html[$lt + strlen($kind) + 1] ?? '';
            if ($after !== '>' && $after !== ' ' && $after !== "\t" && $after !== "\n" && $after !== '/') {
                $i = $lt + 1;   // <scripts>, <styleX>: not this tag

                continue;
            }
            $tagEnd = strpos($html, '>', $lt);
            $close = $tagEnd === false ? false : stripos($html, '</'.$kind.'>', $tagEnd);
            if ($close === false) {
                break;
            }
            $end = $close + strlen($kind) + 3;
            $open = substr($html, $lt, $tagEnd - $lt + 1);
            if ($open === '<'.$kind.'>') {
                $content = substr($html, $tagEnd + 1, $close - $tagEnd - 1);
                if (strlen($content) >= self::MIN_BYTES && ! self::doubleEscapes($content)) {
                    $out[] = [$lt, $end, $kind, $content];
                }
            }
            $i = $end;
            if ($i >= $len) {
                break;
            }
        }

        return $out;
    }

    /**
     * Could the HTML parser read past this block's first '</script>'? Only in
     * its "double escaped" state: '<!--', then '<script' before the matching
     * '-->'. Such a block is never swapped, so where this scan says a block
     * ends is always where the browser says it does.
     */
    public static function doubleEscapes(string $content): bool
    {
        $i = 0;
        while (($open = stripos($content, '<!--', $i)) !== false) {
            $close = strpos($content, '-->', $open + 4);
            $tag = stripos($content, '<script', $open + 4);
            if ($tag !== false && ($close === false || $tag < $close)) {
                return true;
            }
            if ($close === false) {
                return false;
            }
            $i = $close + 3;
        }

        return false;
    }

    public static function key(string $kind, string $content): string
    {
        return self::PREFIX.sha1($content).($kind === 'style' ? '.css' : '.js');
    }

    /** The cookie that says "this browser has fetched this build's files". */
    public const COOKIE = 'kbb_aa';

    /**
     * The console for this request, and the cookie to set with it (or null).
     *
     * @return array{0: string, 1: \Symfony\Component\HttpFoundation\Cookie|null}
     */
    public static function serve(string $html, \Illuminate\Http\Request $request): array
    {
        if (! config('kbb.admin_external_assets', true)) {
            return [$html, null];
        }
        $manifest = self::manifest();
        if ($manifest === []) {
            return [$html, null];
        }

        $build = self::buildId();
        $hard = str_contains(strtolower((string) $request->headers->get('Cache-Control')), 'no-cache')
            || str_contains(strtolower((string) $request->headers->get('Pragma')), 'no-cache');
        $holds = $request->cookie(self::COOKIE) === $build;

        if ($holds && ! $hard) {
            return [self::externalize($html, $manifest), null];
        }
        if ($holds) {
            return [$html, null];   // a hard refresh: inline, the files are already cached
        }

        return [self::withPrefetch($html, $manifest), cookie(self::COOKIE, $build, 60 * 24 * 365, null, null, null, true, false, 'lax')];
    }

    /** A short id of the build the manifest describes. */
    public static function buildId(): string
    {
        static $cache = [];
        $path = public_path('build/manifest.json');
        $stamp = (string) @filemtime($path).':'.(string) @filesize($path);

        return $cache[$stamp] ??= substr(sha1((string) @file_get_contents($path)), 0, 12);
    }

    /**
     * The console as it is, plus one small script that, once the page has
     * LOADED, asks for the files this document's blocks would be served from,
     * as prefetches: idle-time, lowest priority, into the browser cache.
     */
    public static function withPrefetch(string $html, ?array $manifest = null): string
    {
        $manifest ??= self::manifest();
        $urls = [];
        foreach (self::blocks($html) as [, , $kind, $content]) {
            $key = self::key($kind, $content);
            if (isset($manifest[$key]['file'])) {
                $urls[self::url($manifest[$key]['file'])] = true;
            }
        }
        $at = strripos($html, '</body>');
        if ($urls === [] || $at === false) {
            return $html;
        }

        $tag = '<script>addEventListener("load",function(){setTimeout(function(){'
            .'var h=document.head;'.json_encode(array_keys($urls), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            .'.forEach(function(u){var l=document.createElement("link");l.rel="prefetch";l.href=u;h.appendChild(l);});},1500);});</script>';

        return substr($html, 0, $at).$tag.substr($html, $at);
    }

    /** The console with every block the build holds swapped for its file. */
    public static function externalize(string $html, ?array $manifest = null): string
    {
        $manifest ??= self::manifest();
        if ($manifest === []) {
            return $html;
        }

        $out = '';
        $at = 0;
        foreach (self::blocks($html) as [$start, $end, $kind, $content]) {
            $key = self::key($kind, $content);
            if (! isset($manifest[$key]['file'])) {
                continue;
            }
            $url = e(self::url($manifest[$key]['file']));
            $out .= substr($html, $at, $start - $at)
                .($kind === 'style' ? '<link rel="stylesheet" href="'.$url.'">' : '<script src="'.$url.'"></script>');
            $at = $end;
        }

        return $at === 0 ? $html : $out.substr($html, $at);
    }

    /** @return array<string, array<string, mixed>> the build manifest, read once per process and file version */
    public static function manifest(): array
    {
        static $cache = [];
        $path = public_path('build/manifest.json');
        $stamp = @filemtime($path);
        if ($stamp === false) {
            return [];
        }
        if (! isset($cache[$path]) || $cache[$path][0] !== $stamp) {
            $data = json_decode((string) @file_get_contents($path), true);
            $cache[$path] = [$stamp, is_array($data) ? $data : []];
        }

        return $cache[$path][1];
    }

    /**
     * The file's address, made the way the storefront's own build assets get
     * theirs (AppServiceProvider's Vite::createAssetPathsUsing): asset(), then
     * root-relative unless an ASSET_URL points at another host.
     */
    private static function url(string $file): string
    {
        $absolute = asset('build/'.$file);
        if (config('app.asset_url')) {
            return $absolute;
        }
        $path = parse_url($absolute, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $absolute;
    }
}
