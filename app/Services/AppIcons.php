<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The owner's own app icon and favicon (Lane IC), for the shop's Home Screen
 * app (App → Site App) and the owner app (App → Owner App).
 *
 * The owner, 5 October: "for the app, allow me to upload our own icon and
 * advise the icon size etc and guide. also site favicon option i need in main
 * site and also in apps sites too."
 *
 * ONE IMAGE IN, EVERY SIZE OUT. He uploads one square picture; GD re-encodes
 * it to each file a phone or a browser asks for. Nothing he uploaded is ever
 * served: only the PNGs drawn here, so a hostile file (an SVG with a script, a
 * PNG with a payload in a text chunk, EXIF with his location) leaves nothing
 * behind but pixels. The type is read from the bytes (getimagesize), never
 * from the name; SVG is refused outright.
 *
 * SQUARE-ISH, CENTRE-CROPPED. Up to 1.2 : 1 the middle square is used and the
 * answer says so; anything wider is refused, because a centre crop of a
 * banner is not his icon and he should choose the square himself.
 *
 * WHERE THE FILES LIVE. storage/app/app-icons/<scope>/<kind>-<hash>/, which a
 * package never writes (packages overwrite resources/, where the shipped
 * icons are) and the web server never serves directly: every file goes out
 * through a route that names it from an allowlist. The directory is named by
 * a hash of the upload, written to a temporary directory and renamed into
 * place, so a half-written set is never served; the setting is moved after
 * the rename, and the old set is removed only then.
 *
 * ONE SETTING PER APP. `site_app_icon` is autoloaded — the shop's head reads
 * it on every page and must cost no query; `owner_app_icon` is not, because
 * no shop page needs it. Both hold {app, favicon, bg}: two hashes or null and
 * the background colour the app icon was flattened on. Nothing uploaded means
 * no row at all, which is how every page stays byte-identical until he
 * uploads.
 */
final class AppIcons
{
    public const SETTINGS = ['site' => 'site_app_icon', 'owner' => 'owner_app_icon'];

    public const KINDS = ['app', 'favicon'];

    /** Hard limits. The guide on the screen advises far less (1024 px, under 1 MB). */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MIN_SIDE = 512;

    public const BEST_SIDE = 1024;

    public const MAX_SIDE = 4096;

    /** Up to this long side : short side, the middle square is used. */
    public const MAX_RATIO = 1.2;

    /** Android's safe zone is a circle 80% across; the maskable icon draws the artwork inside it. */
    public const SAFE = 0.8;

    /**
     * What an app icon upload draws. mode: 'any' keeps transparency, 'flat'
     * is opaque on the background (iOS paints a clear pixel black), 'mask' is
     * opaque and the artwork shrunk into the safe zone (Android crops it).
     */
    public const APP_SET = [
        'icon-192' => [192, 'any'],
        'icon-512' => [512, 'any'],
        'maskable-512' => [512, 'mask'],
        'apple-180' => [180, 'flat'],
    ];

    /** The browser tab and Google Search: multiples of 48 px, as Google asks. */
    public const FAVICON_SET = [
        'favicon-48' => [48, 'any'],
        'favicon-96' => [96, 'any'],
        'favicon-192' => [192, 'any'],
    ];

    /** Bumped when the drawing changes, so the same upload makes a new address. */
    private const ALGO = 'ic1';

    /** @return array{app: ?string, favicon: ?string, bg: ?string} */
    public static function state(string $scope): array
    {
        $raw = app(SettingsService::class)->get(self::SETTINGS[$scope]);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $raw = is_array($raw) ? $raw : [];
        $hash = static fn ($v) => is_string($v) && preg_match('/\A[0-9a-f]{10}\z/', $v) ? $v : null;
        $bg = $raw['bg'] ?? null;

        return [
            'app' => $hash($raw['app'] ?? null),
            'favicon' => $hash($raw['favicon'] ?? null),
            'bg' => is_string($bg) && preg_match('/\A#[0-9A-F]{6}\z/', $bg) ? $bg : null,
        ];
    }

    /**
     * The uploaded file for one key, or null when there is none (the caller
     * then uses its shipped icon, or, for a favicon, prints nothing). A
     * favicon is the separate favicon when he uploaded one, else the one drawn
     * from his app icon.
     */
    public static function file(string $scope, string $key): ?string
    {
        $s = self::state($scope);
        if (isset(self::APP_SET[$key])) {
            $dir = $s['app'] !== null ? 'app-'.$s['app'] : null;
        } elseif (isset(self::FAVICON_SET[$key])) {
            $dir = $s['favicon'] !== null ? 'favicon-'.$s['favicon'] : ($s['app'] !== null ? 'app-'.$s['app'] : null);
        } else {
            return null;
        }
        if ($dir === null) {
            return null;
        }
        $path = self::root($scope).'/'.$dir.'/'.$key.'.png';

        return is_file($path) ? $path : null;
    }

    public static function root(string $scope): string
    {
        return storage_path('app/app-icons/'.$scope);
    }

    /**
     * Validate, draw every size and move the setting. One upload, one answer:
     * ok with what was done in a sentence, or the refusal in a sentence that
     * names the number he has to change.
     *
     * @return array{ok: bool, message: string}
     */
    public static function store(string $scope, string $kind, string $path): array
    {
        if (! isset(self::SETTINGS[$scope]) || ! in_array($kind, self::KINDS, true)) {
            return ['ok' => false, 'message' => 'Unknown icon.'];
        }

        $drawn = self::draw($path, $kind === 'app' ? self::APP_SET + self::FAVICON_SET : self::FAVICON_SET);
        if (! $drawn['ok']) {
            return ['ok' => false, 'message' => $drawn['message']];
        }

        $hash = substr(sha1(self::ALGO.'|'.$kind.'|'.$drawn['source']), 0, 10);
        $root = self::root($scope);
        $final = $root.'/'.$kind.'-'.$hash;

        if (! is_dir($final)) {
            $tmp = $root.'/.tmp-'.bin2hex(random_bytes(6));
            if (! @mkdir($tmp, 0755, true) && ! is_dir($tmp)) {
                return ['ok' => false, 'message' => 'The shop could not create its icon folder (storage/app/app-icons). The web server needs write permission on storage/app.'];
            }
            foreach ($drawn['files'] as $key => $png) {
                if (@file_put_contents($tmp.'/'.$key.'.png', $png) === false) {
                    self::remove($tmp);

                    return ['ok' => false, 'message' => 'The shop could not write the icon files into storage/app/app-icons. Check the disk is not full.'];
                }
            }
            if (! @rename($tmp, $final)) {
                self::remove($tmp);
                if (! is_dir($final)) {
                    return ['ok' => false, 'message' => 'The shop could not move the new icon into place. Try again.'];
                }
            }
        }

        $state = self::state($scope);
        $state[$kind] = $hash;
        if ($kind === 'app') {
            $state['bg'] = $drawn['bg'];
        }
        self::put($scope, $state);
        self::prune($scope);

        $crop = $drawn['cropped'] ? ' It was not square, so the middle '.$drawn['side'].' × '.$drawn['side'].' was used.' : '';

        return ['ok' => true, 'message' => ($kind === 'app'
            ? 'Your icon is live: '.count($drawn['files']).' sizes made from one '.$drawn['w'].' × '.$drawn['h'].' image, on '.$drawn['bg'].'.'
            : 'Your favicon is live: 48, 96 and 192 px made from one '.$drawn['w'].' × '.$drawn['h'].' image.').$crop];
    }

    /** Back to the shipped icon (app) or to the favicon drawn from the app icon (favicon). */
    public static function reset(string $scope, string $kind): void
    {
        $state = self::state($scope);
        $state[$kind] = null;
        if ($kind === 'app') {
            $state['bg'] = null;
        }
        self::put($scope, $state);
        self::prune($scope);
    }

    /**
     * For the admin card: what is uploaded and the limits the guide quotes.
     *
     * @return array<string,mixed>
     */
    public static function describe(string $scope): array
    {
        $s = self::state($scope);

        return [
            'app' => $s['app'] !== null,
            'favicon' => $s['favicon'] !== null,
            'favicon_from' => $s['favicon'] !== null ? 'own' : ($s['app'] !== null ? 'app' : 'none'),
            'bg' => $s['bg'],
            'limits' => [
                'min' => self::MIN_SIDE, 'best' => self::BEST_SIDE, 'max' => self::MAX_SIDE,
                'bytes' => self::MAX_BYTES, 'ratio' => self::MAX_RATIO, 'safe' => self::SAFE,
            ],
        ];
    }

    /** @param  array{app: ?string, favicon: ?string, bg: ?string}  $state */
    private static function put(string $scope, array $state): void
    {
        $empty = $state['app'] === null && $state['favicon'] === null;
        $settings = app(SettingsService::class);
        if ($empty) {
            // No row at all: the shop is exactly what it was before any upload.
            \App\Models\Setting::query()->where('key', self::SETTINGS[$scope])->delete();
            $settings->flush();
            SettingsService::forgetMemo(self::SETTINGS[$scope]);
            \App\Models\Setting::flushMap();

            return;
        }
        $settings->set(self::SETTINGS[$scope], $state, $scope === 'site');
    }

    /** Remove every set the setting no longer names, and any abandoned temporary folder. */
    private static function prune(string $scope): void
    {
        $s = self::state($scope);
        $keep = array_filter(['app-'.$s['app'] => $s['app'] !== null, 'favicon-'.$s['favicon'] => $s['favicon'] !== null]);
        $root = self::root($scope);
        $dirs = array_merge(glob($root.'/app-*', GLOB_ONLYDIR) ?: [], glob($root.'/favicon-*', GLOB_ONLYDIR) ?: [], glob($root.'/.tmp-*', GLOB_ONLYDIR) ?: []);
        foreach ($dirs as $dir) {
            if (! isset($keep[basename($dir)])) {
                self::remove($dir);
            }
        }
    }

    private static function remove(string $dir): void
    {
        foreach (glob($dir.'/*.png') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /**
     * Read the upload and draw each size. Every refusal says what arrived and
     * what to change.
     *
     * @param  array<string,array{0:int,1:string}>  $set
     * @return array{ok: true, files: array<string,string>, bg: string, w: int, h: int, side: int, cropped: bool, source: string}|array{ok: false, message: string}
     */
    public static function draw(string $path, array $set): array
    {
        $bytes = is_file($path) ? (int) filesize($path) : 0;
        if ($bytes === 0) {
            return ['ok' => false, 'message' => 'No file arrived. Choose an image and try again.'];
        }
        if ($bytes > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'That file is '.round($bytes / 1048576, 1).' MB; the limit is '.(self::MAX_BYTES / 1048576).' MB. A 1024 × 1024 PNG is usually well under 1 MB.'];
        }

        $head = (string) file_get_contents($path, false, null, 0, 512);
        if (preg_match('/<svg|<\?xml/i', $head)) {
            return ['ok' => false, 'message' => 'SVG files are not accepted: they can carry scripts. Export the icon as a PNG (1024 × 1024) and upload that.'];
        }

        $info = @getimagesize($path);
        $types = [IMAGETYPE_PNG => 'PNG', IMAGETYPE_JPEG => 'JPEG', IMAGETYPE_WEBP => 'WebP'];
        if (! is_array($info) || ! isset($types[$info[2]])) {
            return ['ok' => false, 'message' => 'That file is not a PNG, JPEG or WebP image. Upload a square PNG, 1024 × 1024.'];
        }

        [$w, $h] = [(int) $info[0], (int) $info[1]];
        $side = min($w, $h);
        if ($side < self::MIN_SIDE) {
            return ['ok' => false, 'message' => 'That image is '.$w.' × '.$h.' pixels; an app icon needs at least '.self::MIN_SIDE.' × '.self::MIN_SIDE.' (1024 × 1024 is best), or it looks blurry on a phone.'];
        }
        if (max($w, $h) > self::MAX_SIDE) {
            return ['ok' => false, 'message' => 'That image is '.$w.' × '.$h.' pixels, more than '.self::MAX_SIDE.' on a side. Save it at 1024 × 1024 and try again.'];
        }
        if (max($w, $h) / $side > self::MAX_RATIO) {
            return ['ok' => false, 'message' => 'That image is '.$w.' × '.$h.' pixels, not square. Crop it to a square (1024 × 1024 is best) so you choose what shows, then upload it.'];
        }

        // A decode needs ~5 bytes a pixel; refuse rather than die half-way.
        $limit = self::memoryLimit();
        if ($limit > 0 && memory_get_usage() + $w * $h * 5 + 16 * 1048576 > $limit) {
            return ['ok' => false, 'message' => 'That image is too large for this server to resize ('.$w.' × '.$h.'). Save it at 1024 × 1024 and try again.'];
        }

        $data = (string) file_get_contents($path);
        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return ['ok' => false, 'message' => 'That '.$types[$info[2]].' could not be read; it may be damaged. Export it again as a PNG and upload that.'];
        }
        if (! imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        $sx = intdiv($w - $side, 2);
        $sy = intdiv($h - $side, 2);
        $bg = self::background($src, $sx, $sy, $side);

        $files = [];
        foreach ($set as $key => [$px, $mode]) {
            $files[$key] = self::render($src, $sx, $sy, $side, $px, $mode, $bg);
        }
        imagedestroy($src);

        return ['ok' => true, 'files' => $files, 'bg' => sprintf('#%02X%02X%02X', ...$bg), 'w' => $w, 'h' => $h,
            'side' => $side, 'cropped' => $w !== $h, 'source' => sha1($data)];
    }

    /** @param  array{0:int,1:int,2:int}  $bg */
    private static function render(\GdImage $src, int $sx, int $sy, int $side, int $px, string $mode, array $bg): string
    {
        $im = imagecreatetruecolor($px, $px);
        if ($mode === 'any') {
            imagealphablending($im, false);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagecopyresampled($im, $src, 0, 0, $sx, $sy, $px, $px, $side, $side);
            imagesavealpha($im, true);
        } else {
            imagealphablending($im, true);
            imagefill($im, 0, 0, imagecolorallocate($im, $bg[0], $bg[1], $bg[2]));
            $inner = $mode === 'mask' ? (int) round($px * self::SAFE) : $px;
            $off = intdiv($px - $inner, 2);
            imagecopyresampled($im, $src, $off, $off, $sx, $sy, $inner, $inner, $side, $side);
            imagesavealpha($im, false);
        }

        ob_start();
        imagepng($im, null, 9);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /**
     * The colour behind the artwork: the four corners when they are opaque
     * and agree (his white lotus: white), else white. The iPhone and Android
     * icons are filled with it, so a padded maskable icon has no visible edge.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function background(\GdImage $src, int $sx, int $sy, int $side): array
    {
        $in = max(1, intdiv($side, 100));
        $pts = [[$sx + $in, $sy + $in], [$sx + $side - 1 - $in, $sy + $in], [$sx + $in, $sy + $side - 1 - $in], [$sx + $side - 1 - $in, $sy + $side - 1 - $in]];
        $c = [];
        foreach ($pts as [$x, $y]) {
            $rgba = imagecolorsforindex($src, imagecolorat($src, $x, $y));
            if ($rgba['alpha'] > 8) {
                return [255, 255, 255];
            }
            $c[] = [$rgba['red'], $rgba['green'], $rgba['blue']];
        }
        for ($i = 0; $i < 3; $i++) {
            $col = array_column($c, $i);
            if (max($col) - min($col) > 24) {
                return [255, 255, 255];
            }
        }

        return [
            (int) round(array_sum(array_column($c, 0)) / 4),
            (int) round(array_sum(array_column($c, 1)) / 4),
            (int) round(array_sum(array_column($c, 2)) / 4),
        ];
    }

    private static function memoryLimit(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
