/*
 * Lane AP -- the admin console's large static <script>/<style> blocks, built
 * as cacheable files. The runtime half, and the reason this cannot serve the
 * wrong code, is App\Support\AdminConsoleAssets: read its docblock first.
 *
 * At build time this reads the console template and its partials, finds every
 * attribute-less <script>/<style> block of at least MIN_BYTES that Blade will
 * print byte for byte (inside @verbatim, or holding no Blade syntax), and emits
 * each as an asset whose MANIFEST KEY is `admin-console/<sha1 of the block>`
 * + .js or .css. The file itself gets Vite's usual hashed name and the build
 * directory's far-future cache.
 *
 * The server swaps a rendered block for its file only when the sha1 of what it
 * RENDERED is a key here, so a guess wrong in this file costs one unused asset,
 * never a wrong script. AdminConsoleAssetsTest reports unused ones.
 *
 * The scan is the same one AdminConsoleAssets::blocks() runs: HTML comments
 * are skipped, a block that could put the parser in its double-escaped state
 * is never a candidate (doubleEscapes()), a tag must be exactly `<script>` / `<style>`, the block ends at
 * the first matching close tag. In template source it also skips Blade
 * comments outside @verbatim, which Blade removes before the browser sees them.
 */
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

export const MIN_BYTES = 8192;
export const PREFIX = 'admin-console/';

const CSS_AT = /@(?:media|keyframes|-webkit-keyframes|supports|font-face|import|container|layer|property|page|charset|namespace)\b/g;

/** Would Blade print this block's text unchanged, outside @verbatim? */
function plainOutsideVerbatim(text) {
    if (text.includes('{{') || text.includes('{!!') || text.includes('<?')) return false;
    return !/@[A-Za-z]/.test(text.replace(CSS_AT, ''));
}

/**
 * Could the HTML parser read past this block's first '</script>'? Only in its
 * "double escaped" state: '<!--', then '<script' before the matching '-->'.
 * A block that can reach it is never a candidate, so the scan's idea of where
 * a block ends is always the browser's.
 */
export function doubleEscapes(content) {
    const lower = content.toLowerCase();
    let i = 0;
    for (;;) {
        const open = lower.indexOf('<!--', i);
        if (open < 0) return false;
        const close = lower.indexOf('-->', open + 4);
        const tag = lower.indexOf('<script', open + 4);
        if (tag >= 0 && (close < 0 || tag < close)) return true;
        if (close < 0) return false;
        i = close + 3;
    }
}

export function sourceBlocks(src) {
    const lowerSrc = src.toLowerCase();
    const out = [];
    let i = 0;
    let verbatim = false;
    for (;;) {
        // The next thing of interest: a tag, a comment, or a verbatim switch.
        const lt = src.indexOf('<', i);
        const at = src.indexOf('@', i);
        const bc = verbatim ? -1 : src.indexOf('{{--', i);
        const next = [lt, at, bc].filter((n) => n >= 0).sort((a, b) => a - b)[0];
        if (next === undefined) break;

        if (next === bc) {
            const end = src.indexOf('--}}', bc + 4);
            if (end < 0) break;
            i = end + 4;
            continue;
        }
        if (next === at) {
            if (src.startsWith('@endverbatim', at)) { verbatim = false; i = at + 12; continue; }
            if (src.startsWith('@verbatim', at)) { verbatim = true; i = at + 9; continue; }
            i = at + 1;
            continue;
        }
        if (src.startsWith('<!--', lt)) {
            const end = src.indexOf('-->', lt + 4);
            if (end < 0) break;
            i = end + 3;
            continue;
        }
        const lower = src.slice(lt, lt + 8).toLowerCase();
        const kind = lower.startsWith('<script') ? 'script' : lower.startsWith('<style') ? 'style' : null;
        const after = kind ? src[lt + kind.length + 1] : '';
        if (!kind || !['>', ' ', '\t', '\n', '/'].includes(after)) { i = lt + 1; continue; }
        const tagEnd = src.indexOf('>', lt);
        const close = tagEnd < 0 ? -1 : lowerSrc.indexOf('</' + kind + '>', tagEnd);
        if (close < 0) break;
        const end = close + kind.length + 3;
        if (src.slice(lt, tagEnd + 1) === '<' + kind + '>') {
            const content = src.slice(tagEnd + 1, close);
            // Inside @verbatim everything prints as written, except an
            // @endverbatim, which would end the region part-way through.
            const printsAsIs = verbatim ? !content.includes('@endverbatim') : plainOutsideVerbatim(content);
            if (content.length >= MIN_BYTES && printsAsIs && !doubleEscapes(content)) out.push({ kind, content });
        }
        i = end;
    }
    return out;
}

export function key(kind, content) {
    return PREFIX + createHash('sha1').update(content, 'utf8').digest('hex') + (kind === 'style' ? '.css' : '.js');
}

export default function adminConsoleAssets({ views = 'resources/views/admin' } = {}) {
    return {
        name: 'kbb-admin-console-assets',
        apply: 'build',
        generateBundle() {
            const files = [join(views, 'app.blade.php')].concat(
                readdirSync(join(views, 'partials')).filter((f) => f.endsWith('.blade.php')).sort().map((f) => join(views, 'partials', f)),
            );
            const seen = new Set();
            for (const file of files) {
                for (const { kind, content } of sourceBlocks(readFileSync(file, 'utf8'))) {
                    const k = key(kind, content);
                    if (seen.has(k)) continue;
                    seen.add(k);
                    this.emitFile({ type: 'asset', name: 'admin-' + k.slice(PREFIX.length, PREFIX.length + 12) + (kind === 'style' ? '.css' : '.js'), originalFileName: k, source: content });
                }
            }
        },
    };
}
