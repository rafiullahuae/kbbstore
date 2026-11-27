<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;
use Carbon\CarbonImmutable;

/**
 * THE TIME DEAL STRIP -- a slim bar above the header with a live countdown. (Lane TDS)
 *
 * The owner, 27 Nov 2026: "i need strip on the top of the site, should not sticky. with Black Friday theme with count
 * down from now till mid night 12. heading BLACK FRIDAY TIME DEAL sub heading: Last chance to get amazing deals
 * button: Shop Now." He chose design K, Holographic, from the previews, and then: "the font i don't like, the sub
 * line must come under main heading, and must not overlap in any screen size. it should adjust auto as per the
 * screensize. also give controls to manage and on / off."
 *
 * So it SHIPS ON, at his words, counting to midnight UAE time tonight (28 Nov 2026 00:00, Asia/Dubai), in the shop's
 * own Outfit (no new font request) -- and every word, the link, the end, phones / computers and the motion are his to
 * change in Appearance -> Time deal strip, with an on/off switch at the top.
 *
 * ONE settings key, read through SettingsService's per-request map: the strip costs no query (StorefrontQueryBudgetTest).
 * When it is off, or its time is up, the page prints NOTHING of it -- no markup, no style, no script.
 *
 * Rule 5: every word is printed escaped; the link is a path on this shop or an https:// address, checked when saved
 * and again when printed; a select stores one of its own options.
 */
final class TimeDealStrip
{
    public const KEY = 'time_deal_strip';

    /** UAE time, all year (no daylight saving). */
    public const ZONE = 'Asia/Dubai';

    public const MODES = ['until' => 'Until a date and time', 'daily' => 'Every day, until midnight'];

    public const TEXT_MAX = 80;

    /** The strip's whole stylesheet: a constant (rule 5), printed by the partial and sent to the admin preview, so both draw the same. */
    public const CSS = '.kds{position:relative;overflow:hidden;isolation:isolate;display:flex;align-items:center;justify-content:center;gap:clamp(8px,2.2vw,24px);min-height:46px;padding:5px max(10px,env(safe-area-inset-right,0px)) 5px max(10px,env(safe-area-inset-left,0px));background:linear-gradient(110deg,#ffd6e8,#d9c7ff,#c2f0ff,#fff1c7,#ffc7dc,#d9c7ff);background-size:300% 100%;color:#120c14;font-family:Outfit,system-ui,-apple-system,"Segoe UI",sans-serif;animation:kds-holo 8s linear infinite}.kds::before{content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(105deg,transparent 35%,rgba(255,255,255,.7) 50%,transparent 65%);transform:translateX(-120%);animation:kds-sweep 4s ease-in-out infinite}.kds-t{display:flex;flex-direction:column;gap:2px;min-width:0;flex:0 1 auto}.kds-h{font-weight:800;font-size:clamp(12px,3.3vw,19px);line-height:1.12;letter-spacing:.02em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.kds-s{font-weight:500;font-size:clamp(9.5px,2.6vw,12.5px);line-height:1.2;color:#3a2a3f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.kds-c{display:flex;align-items:center;gap:2px;flex:none;font-variant-numeric:tabular-nums;direction:ltr}.kds-c b{min-width:1.9em;text-align:center;font-weight:800;font-size:clamp(11px,3vw,15px);line-height:1;padding:.36em .2em;border-radius:5px;background:#120c14;color:#fff}.kds-c i{font-style:normal;font-weight:800;font-size:clamp(10px,2.6vw,13px)}.kds-b{flex:none;font-weight:800;font-size:clamp(10px,2.7vw,12.5px);line-height:1;letter-spacing:.05em;text-transform:uppercase;color:#fff;background:#120c14;border-radius:999px;padding:.75em 1.2em;text-decoration:none;white-space:nowrap}.kds-b:hover{background:#3a2a3f}.kds-b:focus-visible{outline:2px solid #120c14;outline-offset:2px}.kds[dir=rtl] .kds-h,.kds[dir=rtl] .kds-b{letter-spacing:0;text-transform:none}@keyframes kds-holo{to{background-position:300% 0}}@keyframes kds-sweep{0%,55%{transform:translateX(-120%)}100%{transform:translateX(120%)}}@media (max-width:900px){.kds{justify-content:space-between}.kds.kds-np{display:none}}@media (min-width:901px){.kds.kds-nd{display:none}}.kds.kds-still,.kds.kds-still::before{animation:none}@media (prefers-reduced-motion:reduce){.kds,.kds::before{animation:none}}@media (max-width:359px){.kds{gap:6px;padding-left:8px;padding-right:8px}.kds-h{letter-spacing:0;font-size:11px}.kds-s{font-size:9px}.kds-c b{min-width:1.7em;font-size:10.5px}.kds-b{padding:.7em .75em;letter-spacing:.02em;font-size:9.5px}}';

    /** key => [type, default] -- the shipped state is the owner's own words, switched on. */
    public const SCHEMA = [
        'on' => ['bool', true],
        'show_phone' => ['bool', true],
        'show_desktop' => ['bool', true],
        'motion' => ['bool', true],
        'mode' => ['select', 'until'],
        'ends_at' => ['datetime', '2026-11-28T00:00'],
        'heading_en' => ['text', 'BLACK FRIDAY TIME DEAL'],
        'sub_en' => ['text', 'Last chance to get amazing deals'],
        'button_en' => ['text', 'Shop Now'],
        'heading_ar' => ['text', 'الجمعة البيضاء · عرض لفترة محدودة'],
        'sub_ar' => ['text', 'فرصتك الأخيرة للحصول على عروض مذهلة'],
        'button_ar' => ['text', 'تسوّقي الآن'],
        'link' => ['link', '/super-sale/'],
    ];

    public function __construct(private SettingsService $settings) {}

    /** Every value, saved or default, each already cast and checked. */
    public function all(): array
    {
        $saved = $this->settings->get(self::KEY, []);
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::SCHEMA as $key => [$type, $default]) {
            $v = array_key_exists($key, $saved) ? self::cast($type, $saved[$key]) : null;
            $out[$key] = $v ?? $default;
        }

        return $out;
    }

    /**
     * Saves the posted values, all or nothing. Unknown keys and bad values are refused by name.
     *
     * @return array{ok:bool, rejected:list<string>, values:array<string,mixed>}
     */
    public function save(array $input): array
    {
        $clean = $this->all();
        $rejected = [];
        foreach ($input as $key => $raw) {
            if (! isset(self::SCHEMA[$key])) {
                $rejected[] = (string) $key;

                continue;
            }
            $v = self::cast(self::SCHEMA[$key][0], $raw);
            if ($v === null) {
                $rejected[] = (string) $key;

                continue;
            }
            $clean[$key] = $v;
        }
        if ($rejected !== []) {
            return ['ok' => false, 'rejected' => $rejected, 'values' => $this->all()];
        }
        $this->settings->set(self::KEY, $clean);

        return ['ok' => true, 'rejected' => [], 'values' => $clean];
    }

    /** A value of this type, or null when it cannot be stored. */
    public static function cast(string $type, mixed $raw): mixed
    {
        switch ($type) {
            case 'bool':
                return is_bool($raw) ? $raw : (in_array($raw, [1, 0, '1', '0', 'true', 'false'], true) ? in_array($raw, [1, '1', 'true'], true) : null);
            case 'select':
                return is_string($raw) && isset(self::MODES[$raw]) ? $raw : null;
            case 'datetime':
                if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw) !== 1) {
                    return null;
                }
                try {
                    $d = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $raw, self::ZONE);
                } catch (\Throwable) {
                    return null;
                }

                // a date that overflows (month 13, 99:99) reads back as another date: refused
                return $d !== false && $d->format('Y-m-d\TH:i') === $raw ? $raw : null;
            case 'text':
                if (! is_scalar($raw) || is_bool($raw)) {
                    return null;
                }
                $t = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');

                return mb_strlen($t) <= self::TEXT_MAX ? $t : null;
            case 'link':
                return is_string($raw) && self::safeLink(trim($raw)) ? trim($raw) : null;
        }

        return null;
    }

    /** A path on this shop (/super-sale/) or an https:// address with a host -- nothing else becomes an href. */
    public static function safeLink(string $v): bool
    {
        if ($v === '' || preg_match('/[\s\x00-\x1F\x7F<>"\'`\\\\]/', $v) === 1) {
            return false;
        }
        if (str_starts_with($v, '/') && ! str_starts_with($v, '//')) {
            return true;
        }

        return preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/.*)?$#i', $v) === 1;
    }

    /** The moment the countdown reaches zero, or null when the strip has nothing left to count. */
    public function endsAt(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $v = $this->all();
        $now ??= CarbonImmutable::now(self::ZONE);
        $now = $now->setTimezone(self::ZONE);
        if ($v['mode'] === 'daily') {
            return $now->startOfDay()->addDay();
        }
        $end = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $v['ends_at'], self::ZONE);

        return $end !== false && $end->greaterThan($now) ? $end : null;
    }

    /** Is there anything to print on this request? Off, or past its end: no. */
    public function visible(?CarbonImmutable $now = null): bool
    {
        $v = $this->all();

        return $v['on'] && ($v['show_phone'] || $v['show_desktop']) && $this->endsAt($now) !== null;
    }

    /**
     * What the partial prints, in the language of the request. Every string is printed escaped by Blade.
     *
     * @return array{heading:string, sub:string, button:string, href:string, end:int, daily:bool, left:int, rtl:bool, phone:bool, desktop:bool, motion:bool}
     */
    public function view(?CarbonImmutable $now = null): array
    {
        $v = $this->all();
        $ar = Locale::current() === 'ar';
        $now ??= CarbonImmutable::now(self::ZONE);
        $end = $this->endsAt($now) ?? $now;
        $link = self::safeLink((string) $v['link']) ? (string) $v['link'] : '/super-sale/';
        if (str_starts_with($link, '/') && ! str_starts_with($link, '//')) {
            $link = Locale::withSegment($link);
        }
        $pick = static fn (string $k) => trim((string) ($ar && trim((string) $v[$k.'_ar']) !== '' ? $v[$k.'_ar'] : $v[$k.'_en']));

        return [
            'heading' => $pick('heading'),
            'sub' => $pick('sub'),
            'button' => $pick('button'),
            'href' => $link,
            'end' => (int) ($end->getTimestamp() * 1000),
            'daily' => $v['mode'] === 'daily',
            'left' => max(0, $end->getTimestamp() - $now->getTimestamp()),
            'rtl' => $ar,
            'phone' => (bool) $v['show_phone'],
            'desktop' => (bool) $v['show_desktop'],
            'motion' => (bool) $v['motion'],
        ];
    }
}
