<?php

declare(strict_types=1);

namespace App\Services\Ugc;

use App\Models\ModuleSetting;
use App\Models\UgcSection;
use App\Services\UgcRail;
use App\Services\UgcSettings;

/**
 * What the storefront rail will actually DO, said in words on the screen that
 * owns the settings behind it.
 *
 * ── WHY THIS CLASS EXISTS ───────────────────────────────────────────────────
 *
 * Three rounds of "on front-end it still not auto play". Each round measured a
 * seeded rail, found it looping, and reported that it worked. It did work — on
 * that data. On HIS data four `(Demo)` clips sat at the head of the section and
 * `max_playing` is 4, so the cap was spent before the two clips he had uploaded
 * were ever looked at. The storefront had no way to say so and the admin had
 * nothing to say it with, so the only instrument left was the owner's eyes, and
 * what his eyes could see — a play disc — was being drawn by a CSS rule that
 * keyed off a class set at MOUNT rather than at playback.
 *
 * The rail itself is fixed (ugc/assets.blade.php: a real clip now outranks a
 * placeholder, a tile that cannot play gives its slot back, and `is-playing`
 * means playing). THIS class is the other half, and it is the half that stops
 * the NEXT one of these being silent: Content → Shoppable video → Appearance →
 * Motion now states, from his own rows and his own settings, how many tiles
 * will move, which ones will not, and why.
 *
 * ── IT DESCRIBES A WIDE SCREEN, AND SAYS SO ─────────────────────────────────
 *
 * How much of a tile is on screen is a fact about a browser window, and this
 * runs on a server. So the verdict is the DESKTOP one — every tile visible at
 * once, which is the case the cap actually binds in and the case his screenshot
 * is — and the two shopper-side switches that can still stop a clip
 * (prefers-reduced-motion and Save Data) are named as such rather than guessed
 * at. Saying "4 will move" and meaning "on a phone, 2" would be a new way of
 * being wrong.
 *
 * ── AND IT IS READ-ONLY ─────────────────────────────────────────────────────
 *
 * Nothing here writes a setting, and nothing here runs on the storefront: it is
 * reached from the admin appearance endpoint only. `file_exists` is called once
 * per tile, bounded by the section's own cap, on a screen an owner opens by
 * hand.
 */
final class RailPlayback
{
    /** A tile that will move. */
    public const WHY_PLAYS = 'plays';

    /** Refused because the cap was already spent on higher-ranked tiles. */
    public const WHY_CAP = 'cap';

    /** The row carries no usable video path at all. */
    public const WHY_NO_MEDIA = 'no_media';

    /** The row carries a path and the file is not on this server. */
    public const WHY_NO_FILE = 'no_file';

    /** The loop is switched off for the whole shop. */
    public const WHY_TEASER_OFF = 'teaser_off';

    public function __construct(
        private UgcSettings $settings,
        private UgcRail $rail,
    ) {}

    /**
     * @return array<string, mixed>  always the same keys, so the screen never
     *                               has to branch on their absence
     */
    public function describe(?string $handle = null, string $locale = 'en'): array
    {
        $conf = $this->settings->all();
        $handle = $handle ?? $this->pickSection();

        $out = [
            'module_on' => $this->settings->enabled(),
            'section' => $handle,
            'teaser_on' => (bool) ($conf['teaser'] ?? true),
            /*
             * NEVER SET, OR SWITCHED OFF — and they are not the same sentence.
             *
             * A bool that stores '1'/'' cannot tell them apart, which is the
             * exact trap 2027_03_21_000000_tamara_auto_capture_on_by_default was
             * written about. `module_settings` has one row per key and
             * SettingsService::setModuleSetting() is its only writer, so the
             * ROW'S EXISTENCE is the answer: no row means nobody has ever
             * touched this control and the value is the shipped default.
             */
            'teaser_chosen' => ModuleSetting::query()
                ->where('module', UgcSettings::MODULE)
                ->where('key', 'teaser')
                ->exists(),
            'max' => (int) ($conf['max_playing'] ?? 4),
            'tiles' => [],
            'moving' => 0,
            'total' => 0,
            'demo_moving' => 0,
        ];

        if ($handle === null) {
            return $out;
        }

        $rail = $this->rail->section($handle, $locale);
        $tiles = $rail['tiles'];
        $out['total'] = count($tiles);

        /*
         * THE SAME RANKING THE STOREFRONT USES, and it has to stay the same
         * ranking or this screen becomes a second thing to keep in step. A real
         * clip before a placeholder, then the order they are in. The storefront
         * inserts "and then whichever the shopper can see most of" between those
         * two, which is a browser fact — on the wide screen this describes every
         * visible tile is at ratio 1 and that term drops out.
         */
        $ranked = [];

        foreach ($tiles as $i => $tile) {
            /* What the tile would actually mount: its cut teaser when it has
               one, otherwise the full clip. The same `teaser || full` the
               storefront picks, so the two cannot disagree about which file
               this verdict is about. */
            $src = ($tile['teaser'] ?? null) ?? ($tile['src'] ?? null);

            $why = null;

            if ($src === null) {
                $why = self::WHY_NO_MEDIA;
            } elseif (! $this->onDisk($src)) {
                $why = self::WHY_NO_FILE;
            } elseif (! $out['teaser_on']) {
                $why = self::WHY_TEASER_OFF;
            }

            $ranked[] = [
                'n' => $i + 1,
                'title' => (string) ($tile['title'] !== '' ? $tile['title'] : $tile['caption']),
                'demo' => (bool) ($tile['demo'] ?? false),
                'why' => $why,
            ];
        }

        $order = array_keys($ranked);

        usort($order, function (int $a, int $b) use ($ranked): int {
            $ra = $ranked[$a]['demo'] ? 0 : 1;
            $rb = $ranked[$b]['demo'] ? 0 : 1;

            return $ra === $rb ? $a <=> $b : $rb <=> $ra;
        });

        $slots = $out['max'];

        foreach ($order as $idx) {
            if ($ranked[$idx]['why'] !== null) {
                continue;
            }

            if ($slots > 0) {
                $ranked[$idx]['why'] = self::WHY_PLAYS;
                $slots--;
                $out['moving']++;

                if ($ranked[$idx]['demo']) {
                    $out['demo_moving']++;
                }

                continue;
            }

            $ranked[$idx]['why'] = self::WHY_CAP;
        }

        $out['tiles'] = $ranked;

        return $out;
    }

    /**
     * The section the owner is most likely looking at: the one the homepage
     * draws, or else the first published one.
     *
     * The homepage first because that is where this feature is placed by a
     * setting rather than by a shortcode somebody has to remember writing, and
     * it is the rail in his screenshot.
     */
    private function pickSection(): ?string
    {
        $home = $this->settings->homeSection();

        if ($home !== '') {
            return $home;
        }

        $first = UgcSection::query()
            ->where('status', 'publish')
            ->orderBy('position')
            ->orderBy('id')
            ->value('handle');

        return $first === null ? null : (string) $first;
    }

    /**
     * Is the file really there?
     *
     * THE ONE QUESTION THE BROWSER CANNOT ANSWER OUT LOUD. A tile whose path
     * 404s used to mount a video, take a playback slot and keep it for the life
     * of the page, looking — to the CSS and to a screenshot — exactly like a
     * tile that was playing. The storefront now gives that slot back; this is
     * how the ADMIN gets to name the clip before a shopper ever meets it.
     *
     * THROUGH ClipFile, which is the same decider UgcVideo::publishWarnings()
     * and UgcTranscoder::derive() ask. This panel exists to stop three screens
     * describing one row three different ways, so it must not be a fourth
     * reading of the same column.
     *
     * The path has already been through UgcPath::stored(), so it is
     * `/uploads/ugc/<one segment>` and nothing else: no traversal reaches this,
     * and it is a read of a public file either way.
     */
    private function onDisk(string $path): bool
    {
        return ClipFile::state($path) === ClipFile::OK;
    }
}
