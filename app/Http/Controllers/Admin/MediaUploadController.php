<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Generic image upload for admin settings fields (SEO share image, org logo,
 * and anywhere else a URL field is replaced with a real upload widget).
 *
 * Writes directly under public/uploads/ rather than through the `public`
 * disk + storage:link — that symlink has to be created with `artisan
 * storage:link`, which depends on shell/artisan access this host has
 * repeatedly not reliably had this project. Writing straight into the real
 * public web root has no such dependency: the file is reachable the moment
 * it's written, nothing else has to run first.
 */
class MediaUploadController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB

    /**
     * The media types this endpoint serves, and the extension each is stored
     * under.
     *
     * Keyed by the type the file's own BYTES report, not by anything the
     * browser or the operator supplied -- see the note on $ext below. Both
     * jpeg spellings are listed because finfo reports image/jpeg and the
     * conventional extension is jpg.
     */
    private const TYPE_EXT = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'image/svg' => 'svg',
    ];

    /** What to call the accepted formats when refusing one that is not. */
    private const ACCEPTED_LABEL = 'JPG, PNG, WebP, GIF or SVG';

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:' . (self::MAX_BYTES / 1024)],
            'folder' => ['nullable', 'string', 'regex:/^[a-z0-9\-]{1,40}$/'],
        ], [
            // The default reads "must not be greater than 5120 kilobytes",
            // which is the number in the rule rather than the number on the
            // operator's file dialog. A refusal that does not say what the
            // limit is in the units the person is looking at is a refusal they
            // cannot act on.
            'file.max' => 'That image is larger than ' . (self::MAX_BYTES / 1024 / 1024) . 'MB. Save it smaller and try again.',
            'file.required' => 'No file arrived — choose an image and try again.',
        ]);

        $file = $request->file('file');
        $folder = $request->string('folder', 'seo')->toString() ?: 'seo';

        /*
         * THE EXTENSION COMES FROM THE FILE'S CONTENT, NOT FROM ITS NAME.
         *
         * This used to be getClientOriginalExtension() -- a string the browser
         * copied off whatever the operator happened to call the file -- and it
         * decided two things: what the file is saved as, and therefore what
         * Content-Type this origin later serves it with, and whether the SVG
         * safety scan below runs at all. Both were wrong in the same direction.
         * A malicious SVG uploaded as `photo.png` was stored as `.png` and the
         * scan was skipped entirely, because the scan was gated on the NAME
         * ending in .svg while the accept check underneath was gated on the
         * CONTENT. The two disagreed, and the gap between them was the whole
         * attack.
         *
         * getMimeType() is finfo over the bytes on disk -- not
         * getClientMimeType(), which is another browser-supplied string. So the
         * type that decides acceptance, the type that decides the stored
         * extension and the type that decides whether to scan are now one type,
         * read once, from the only source that cannot be renamed.
         *
         * The `mimes:` rule that used to sit in the validator did check content
         * and was not the defect; it is gone only because doing the check here
         * is what lets the refusal name the type that actually arrived. A
         * message that lists what was wanted, without saying what was sent, is
         * how somebody ends up uploading the same HEIC four times.
         */
        $detected = strtolower((string) ($file->getMimeType() ?: ''));
        $ext = self::TYPE_EXT[$detected] ?? null;

        if ($ext === null) {
            return response()->json([
                'ok' => false,
                'message' => 'That file is ' . ($detected !== '' ? $detected : 'of a type this server could not read')
                    . '. Images have to be ' . self::ACCEPTED_LABEL . '.',
            ], 422);
        }

        // SVG is XML, not a bitmap: it can carry <script>, on* handlers,
        // javascript: URLs and external entities. Written into the public web
        // root and served from this origin, that is stored XSS -- and script
        // running on this origin can reach /admin-api with whatever session
        // the viewer has, which for the media library is an admin's.
        //
        // Rejected rather than stripped. Sanitising SVG properly means a real
        // XML parser and an element allowlist, and a half-built stripper that
        // misses one vector is worse than a clear refusal: the operator
        // re-exports as PNG, or exports a clean SVG, and knows where they
        // stand. `mimes:svg` does not help here -- it checks the media type,
        // and a malicious SVG is a perfectly valid image/svg+xml.
        //
        // Gated on the DETECTED type, so a hostile SVG cannot skip this scan by
        // arriving under any other name. That was the bug.
        if ($ext === 'svg' && ($reason = $this->unsafeSvg($file->getRealPath())) !== null) {
            return response()->json([
                'ok' => false,
                'message' => 'This SVG contains ' . $reason . ', which cannot be served safely. '
                    . 'Re-export it without scripting, or upload a PNG.',
            ], 422);
        }

        $filename = date('Ymd-His') . '-' . Str::random(8) . '.' . $ext;
        $dir = public_path('uploads/' . $folder);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return response()->json(['ok' => false, 'message' => 'Could not create upload directory.'], 500);
        }

        $destination = $dir . '/' . $filename;

        if (!$file->move($dir, $filename) || !is_file($destination)) {
            return response()->json(['ok' => false, 'message' => 'Upload failed — check folder permissions.'], 500);
        }

        // Same double base-path bug already caught and fixed in the
        // IndexNow submission hook: site_url already includes any
        // subfolder the app lives under (e.g. /kbb-upgrade), so combining
        // it with Url::to() — which adds that same prefix a second time —
        // produces a broken, 404-ing URL. Built by hand instead, matching
        // that same fix.
        $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? config('app.url')), '/');
        $url = $base . '/uploads/' . $folder . '/' . $filename;

        return response()->json(['ok' => true, 'url' => $url, 'filename' => $filename]);
    }

    /**
     * Names what makes an SVG unsafe to serve, or null if nothing does.
     *
     * Read as raw text on purpose. Parsing it first would mean handing a
     * hostile document to an XML parser before deciding whether to trust it,
     * which is the entity-expansion problem in miniature.
     */
    private function unsafeSvg(string $path): ?string
    {
        $svg = @file_get_contents($path);

        if ($svg === false) {
            return 'unreadable content';
        }

        // Entity and CDATA tricks are used to hide the patterns below from a
        // naive scan, so decoding first is part of the check rather than a
        // convenience.
        $probe = html_entity_decode($svg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = str_replace(["\0", "\r"], '', $probe);

        foreach ([
            '/<\s*script/i' => 'a script element',
            '/<\s*foreignObject/i' => 'a foreignObject element',
            '/<\s*(iframe|embed|object)/i' => 'an embedded document',
            '/<!ENTITY/i' => 'an XML entity declaration',
            '/\son[a-z]+\s*=/i' => 'an event handler attribute',
            '/javascript\s*:/i' => 'a javascript: URL',
            '/data\s*:\s*text\/html/i' => 'an embedded HTML document',
            '/<\s*(set|animate)[^>]*attributeName\s*=\s*["\']?(href|xlink:href)/i' => 'an animated link attribute',
        ] as $pattern => $reason) {
            if (preg_match($pattern, $probe) === 1) {
                return $reason;
            }
        }

        return null;
    }
}
