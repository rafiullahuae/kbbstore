<?php

declare(strict_types=1);

namespace App\Services\Pixels;

use App\Services\SecurityModule;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Custom code: the owner's own head / body-start / footer snippets, for a tag
 * this shop has no built-in support for (Microsoft Clarity, Snap, Pinterest…).
 * (Lane MP)
 *
 * The owner: "also give facility to use header code, footer or body code, for
 * pixels etc, in case custom code placement. but make sure site speed must not
 * be disturb in any case."
 *
 * ── THE ONE SANCTIONED PLACE FOR OWNER-SUPPLIED RAW HTML ───────────────────
 *
 * Everything else in this shop prints constants or escaped settings. This
 * prints what the owner typed, as typed, because that is the feature. So it is
 * fenced the other way round:
 *
 *   - its own capability, `marketing.customcode`, held by the owner role only
 *     (a manager or an editor cannot reach the endpoint at all);
 *   - every save is CSRF-checked (admin-api's web middleware), kept as a
 *     version with who saved it and when (marketing_custom_code), and written
 *     to the security audit log;
 *   - it prints only from the shop layout (layouts/store.blade.php) — never in
 *     the admin, the owner app, /api or a feed, which do not use that layout,
 *     and render() refuses those paths again in case one ever does;
 *   - the admin screen shows it back escaped, in a textarea.
 *
 * ── SPEED ───────────────────────────────────────────────────────────────────
 *
 *   off or empty     prints NOTHING — not a comment, not a newline. A shop
 *                    that never uses this is byte-identical.
 *   deferred (the    the code is printed inside <template>, which the browser
 *   default)         parses but never runs; one ~500-byte inline loader clones
 *                    it into the page after the `load` event, in an idle
 *                    callback, re-creating each <script> so it executes. Code
 *                    in here cannot delay first paint, LCP or the load event.
 *   immediately      printed live, for a tag that must see the very first
 *   (opt-in)         moment of the page — but never render-blocking: an
 *                    external <script src> with neither async nor defer gets
 *                    `async`, and a <link rel=stylesheet> is loaded as
 *                    media=print and switched to all on load.
 *
 * Reading it costs nothing on a page: the live copy is one key in the
 * settings map every page already reads once (SettingsRequestMemo).
 *
 * ── PREFETCH ────────────────────────────────────────────────────────────────
 *
 * The code IS present in a prefetched page's HTML, deliberately. Instant
 * navigation shows the prefetched response when the shopper clicks, so a page
 * fetched without it would be shown without it, and every tag in here would
 * miss most page views. Nothing runs on a prefetch: a prefetch fetches the
 * HTML and executes none of it (InstantNav's own note), and the deferred
 * loader waits for a `load` event a prefetch never has.
 */
final class CustomCode
{
    public const SETTING = 'pixels_custom_code';

    public const SLOTS = ['head' => 'Head', 'body' => 'Body start', 'footer' => 'Footer'];

    public const WHERE = ['all' => 'All shop pages', 'thankyou' => 'Only the order-received (thank-you) page', 'not_checkout' => 'All shop pages except checkout'];

    public const LOAD = ['deferred' => 'After the page loads (recommended)', 'immediate' => 'Immediately (can slow the page)'];

    public const MAX_BYTES = 20480;

    private const LOADER_FLAG = 'kbb.customcode.loader';

    /** Never, whatever the setting says. */
    private const NEVER_PREFIXES = ['api/', 'admin-api/', 'feeds/'];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, array{on: bool, where: string, load: string, code: string}> */
    public function current(): array
    {
        $raw = $this->settings->get(self::SETTING, '');
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : []);

        return self::normalise(is_array($data) ? $data : []);
    }

    /** @return array<string, array{on: bool, where: string, load: string, code: string}> */
    public static function normalise(array $data): array
    {
        $out = [];

        foreach (array_keys(self::SLOTS) as $slot) {
            $s = is_array($data[$slot] ?? null) ? $data[$slot] : [];
            $out[$slot] = [
                'on' => filter_var($s['on'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'where' => isset(self::WHERE[$s['where'] ?? '']) ? (string) $s['where'] : 'all',
                'load' => isset(self::LOAD[$s['load'] ?? '']) ? (string) $s['load'] : 'deferred',
                'code' => is_string($s['code'] ?? null) ? (string) $s['code'] : '',
            ];
        }

        return $out;
    }

    /**
     * Check a submission. Errors refuse the save; warnings are shown and saved.
     *
     * @return array{errors: array<string, string>, warnings: array<string, string>}
     */
    public static function validate(array $slots): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::SLOTS as $slot => $label) {
            $s = $slots[$slot] ?? null;

            if ($s === null) {
                continue;
            }

            if (! is_array($s)) {
                $errors[$slot] = "{$label}: not a valid box.";

                continue;
            }

            foreach (['where' => self::WHERE, 'load' => self::LOAD] as $field => $options) {
                if (isset($s[$field]) && ! isset($options[$s[$field]])) {
                    $errors[$slot] = "{$label}: “{$field}” must be one of its own options.";
                }
            }

            $code = $s['code'] ?? '';

            if (! is_string($code)) {
                $errors[$slot] = "{$label}: the code must be text.";

                continue;
            }

            if (strlen($code) > self::MAX_BYTES) {
                $errors[$slot] = "{$label}: " . number_format(strlen($code) / 1024, 1) . ' KB is over the 20 KB limit. Paste only the tag itself.';
            }

            if (stripos($code, '</template') !== false) {
                $errors[$slot] = "{$label}: the code may not contain </template> — it would break out of the deferred wrapper.";
            }

            if (preg_match('/document\s*\.\s*write(ln)?\s*\(/i', $code) === 1) {
                $warnings[$slot] = "{$label}: this code calls document.write(). Code that runs after the page has loaded cannot use it (it would wipe the page), so the shop skips those calls and that part will not work — ask the vendor for their async snippet, or set Load to Immediately.";
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Save, keeping the version it replaces. The caller has already checked
     * the capability; this audit-logs who did it.
     *
     * @return array{ok: bool, errors: array<string, string>, warnings: array<string, string>}
     */
    public function save(array $slots, string $by, string $note = 'Saved'): array
    {
        $check = self::validate($slots);

        if ($check['errors'] !== []) {
            return ['ok' => false] + $check;
        }

        $next = self::normalise(array_replace($this->current(), $slots));
        $this->write($next, $by, $note);

        return ['ok' => true] + $check;
    }

    /** One-click restore: a saved version becomes live, itself kept as a version. */
    public function restore(int $versionId, string $by): bool
    {
        $row = DB::table('marketing_custom_code')->where('id', $versionId)->first();

        if ($row === null) {
            return false;
        }

        $data = json_decode((string) $row->snapshot, true);

        if (! is_array($data)) {
            return false;
        }

        $this->write(self::normalise($data), $by, 'Restored version #' . $versionId);

        return true;
    }

    /** @return list<array{id: int, saved_by: ?string, note: ?string, created_at: ?string, summary: string}> */
    public function history(int $limit = 10): array
    {
        $out = [];

        foreach (DB::table('marketing_custom_code')->orderByDesc('id')->limit($limit)->get() as $row) {
            $data = self::normalise((array) (json_decode((string) $row->snapshot, true) ?: []));
            $bits = [];
            foreach ($data as $slot => $s) {
                if ($s['code'] !== '') {
                    $bits[] = self::SLOTS[$slot] . ($s['on'] ? ' on' : ' off') . ' (' . number_format(strlen($s['code']) / 1024, 1) . ' KB)';
                }
            }
            $out[] = ['id' => (int) $row->id, 'saved_by' => $row->saved_by, 'note' => $row->note, 'created_at' => $row->created_at,
                'summary' => $bits === [] ? 'Empty' : implode(' · ', $bits)];
        }

        return $out;
    }

    private function write(array $next, string $by, string $note): void
    {
        $json = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        DB::table('marketing_custom_code')->insert([
            'snapshot' => $json, 'saved_by' => mb_substr($by, 0, 191), 'note' => mb_substr($note, 0, 120), 'created_at' => now(),
        ]);

        // Keep the newest 30 versions.
        $floor = (int) DB::table('marketing_custom_code')->max('id') - 30;
        if ($floor > 0) {
            DB::table('marketing_custom_code')->where('id', '<=', $floor)->delete();
        }

        $this->settings->set(self::SETTING, $json);

        try {
            $summary = [];
            foreach ($next as $slot => $s) {
                $summary[] = self::SLOTS[$slot] . ': ' . ($s['on'] && $s['code'] !== '' ? $s['load'] . ', ' . $s['where'] : 'off');
            }
            app(SecurityModule::class)->record('marketing.customcode', $note . ' custom code — ' . implode('; ', $summary), ['severity' => 'warning']);
        } catch (\Throwable) {
        }
    }

    // ------------------------------------------------------------- printing

    /** What one slot prints on this request: '' unless it is on and allowed here. */
    public function render(string $slot, ?Request $request = null): string
    {
        $all = $this->current();
        $s = $all[$slot] ?? null;

        if ($s === null || ! $s['on'] || trim($s['code']) === '') {
            return '';
        }

        $request ??= app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request || ! self::allowedHere($s['where'], $request)) {
            return '';
        }

        if ($s['load'] === 'immediate') {
            return "\n" . self::nonBlocking($s['code']) . "\n";
        }

        $out = "\n<template data-kbb-cc=\"{$slot}\">" . $s['code'] . "</template>\n";

        if ($request->attributes->get(self::LOADER_FLAG) !== true) {
            $request->attributes->set(self::LOADER_FLAG, true);
            $out .= self::LOADER . "\n";
        }

        return $out;
    }

    public static function allowedHere(string $where, Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        foreach (self::NEVER_PREFIXES as $prefix) {
            if (str_starts_with($path . '/', $prefix)) {
                return false;
            }
        }

        $route = $request->route();
        $name = $route instanceof \Illuminate\Routing\Route ? (string) $route->getName() : '';

        if ($name === 'admin' || str_starts_with($name, 'admin.')) {
            return false;
        }

        return match ($where) {
            'thankyou' => $name === 'checkout.success',
            'not_checkout' => $name !== 'checkout',
            default => true,
        };
    }

    /**
     * Make "Immediately" code unable to block rendering: async on any external
     * script that has neither async nor defer (an inline script cannot block
     * on the network), and a stylesheet loaded as print then switched to all.
     */
    public static function nonBlocking(string $code): string
    {
        $code = (string) preg_replace_callback('/<script\b([^>]*)>/i', static function (array $m): string {
            $attrs = $m[1];

            if (preg_match('/\bsrc\s*=/i', $attrs) !== 1 || preg_match('/\b(async|defer)\b/i', $attrs) === 1
                || preg_match('/\btype\s*=\s*["\']?module/i', $attrs) === 1) {
                return $m[0];
            }

            return '<script async' . $attrs . '>';
        }, $code);

        return (string) preg_replace_callback('/<link\b([^>]*)>/i', static function (array $m): string {
            $attrs = $m[1];

            if (preg_match('/\brel\s*=\s*["\']?stylesheet\b/i', $attrs) !== 1 || preg_match('/\bmedia\s*=/i', $attrs) === 1) {
                return $m[0];
            }

            $selfClosing = str_ends_with(rtrim($attrs), '/');
            $attrs = rtrim(rtrim($attrs), '/');

            return '<link' . $attrs . ' media="print" onload="this.media=\'all\'"' . ($selfClosing ? ' /' : '') . '>';
        }, $code);
    }

    /**
     * After `load`, when the browser is idle: clone each template into place,
     * re-creating its <script> elements (a script parsed inside a template, or
     * inserted with innerHTML, never runs). ~600 bytes, printed once.
     *
     * document.write is switched off while the inline scripts run. After the
     * page has loaded, a write does not add to the page — it implicitly calls
     * document.open() and REPLACES the whole page with what was written. One
     * old vendor snippet would blank the shop; caught in Chromium on the Lane MP
     * preview, where it did exactly that. Saving such code also warns.
     */
    public const LOADER = '<script>(function(){function r(){var d=document;d.write=d.writeln=function(){};try{var t=d.querySelectorAll("template[data-kbb-cc]");for(var i=0;i<t.length;i++){var f=t[i].content.cloneNode(true),s=f.querySelectorAll("script");for(var j=0;j<s.length;j++){var o=s[j],n=d.createElement("script");for(var k=0;k<o.attributes.length;k++)n.setAttribute(o.attributes[k].name,o.attributes[k].value);n.text=o.text;o.parentNode.replaceChild(n,o)}t[i].parentNode.insertBefore(f,t[i]);t[i].parentNode.removeChild(t[i])}}finally{delete d.write;delete d.writeln}}function g(){window.requestIdleCallback?requestIdleCallback(r,{timeout:3000}):setTimeout(r,1)}document.readyState==="complete"?g():addEventListener("load",g,{once:true})})();</script>';
}
