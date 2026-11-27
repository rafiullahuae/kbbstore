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

    /** At most this many turning brand names; each at most TEXT_MAX / 2 characters. */
    public const BRANDS_MAX = 20;

    /** "Medicube, Anua ,, Dr.Althea" -> ["Medicube", "Anua", "Dr.Althea"]: trimmed, empties and over-long names dropped. */
    public static function brandList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,\n]+/u', $raw) ?: [] as $b) {
            $b = trim(preg_replace('/\s+/u', ' ', $b) ?? '');
            if ($b !== '' && mb_strlen($b) <= self::TEXT_MAX / 2) {
                $out[] = $b;
            }
        }

        return array_values(array_unique($out));
    }

    /** The strip's whole stylesheet: a constant (rule 5), printed by the partial and sent to the admin preview, so both draw the same. */
    public const CSS = '/* the thin bar (Lane TDS2): T2 on computers, M5 chips on phones */.kdt{position:relative;overflow:hidden;isolation:isolate;display:flex;align-items:center;justify-content:center;gap:14px;height:44px;padding:0 52px 0 16px;background:linear-gradient(110deg,#ffd6e8,#d9c7ff,#c2f0ff,#fff1c7,#ffc7dc,#d9c7ff);background-size:300% 100%;animation:kdt-holo 8s linear infinite;color:#120c14;font-family:Outfit,system-ui,-apple-system,"Segoe UI",sans-serif;white-space:nowrap}.kdt[dir=rtl]{padding:0 16px 0 52px}.kdt-a{position:absolute;inset:0;z-index:1}.kdt>*{position:relative;z-index:2}.kdt>.kdt-a{position:absolute;z-index:1}.kdt>:not(.kdt-a):not(.kdt-go):not(.kdt-x){pointer-events:none}.kdt-off{font-weight:600;font-size:12px;line-height:1;font-style:normal;background:#120c14;color:#fff;padding:5px 8px;border-radius:6px}.kdt-clk{font-weight:800;font-size:14px;line-height:1;font-variant-numeric:tabular-nums;direction:ltr}.kdt-clk small{font-size:10px;font-weight:600;opacity:.7;margin:0 4px 0 1px}.kdt-clk small:last-child{margin-right:0}.kdt-pill{font-weight:800;font-size:13px;line-height:1;letter-spacing:.03em;color:#fff;background:#120c14;padding:6px 10px;border-radius:6px;overflow:hidden}.kdt-pill::after{content:"";position:absolute;inset:0;background:linear-gradient(105deg,transparent 30%,rgba(255,255,255,.55) 50%,transparent 70%);transform:translateX(-120%);animation:kdt-sw 3s ease-in-out infinite}.kdt-txt{font-weight:500;font-size:13.5px;line-height:1;color:#3a2a3f;display:inline-flex;align-items:center;gap:5px;min-width:0;overflow:hidden}.kdt-ls,.kdt-chip{display:none}.kdt-rot{display:inline-grid;justify-items:start;font-weight:800;color:#c13e63;overflow:hidden;padding:2px 0}.kdt-rot span{grid-area:1/1;opacity:0;transform:translateY(90%);transition:opacity .35s,transform .35s}.kdt-rot span.on{opacity:1;transform:none}.kdt-rot span.out{opacity:0;transform:translateY(-90%)}.kdt-go{font-weight:700;font-size:12.5px;line-height:1;padding:8px 14px;border-radius:8px;background:#120c14;color:#fff;text-decoration:none}.kdt-go:hover{background:#3a2a3f}.kdt-x{position:absolute;z-index:3;top:50%;right:12px;margin-top:-12px;width:24px;height:24px;padding:0;border:0;border-radius:50%;display:grid;place-items:center;background:rgba(18,12,20,.08);color:#120c14;cursor:pointer}.kdt[dir=rtl] .kdt-x{right:auto;left:12px}.kdt-x svg{width:11px;height:11px}.kdt-x:hover{background:rgba(18,12,20,.16)}.kdt-go:focus-visible,.kdt-x:focus-visible,.kdt-a:focus-visible{outline:2px solid #120c14;outline-offset:-2px}.kdt[dir=rtl] .kdt-pill{letter-spacing:0}@keyframes kdt-holo{to{background-position:300% 0}}@keyframes kdt-sw{0%,55%{transform:translateX(-120%)}100%{transform:translateX(120%)}}@media (min-width:901px) and (max-width:1120px){.kdt-lead{display:none}.kdt-ls{display:inline}}@media (min-width:901px){.kdt.kdt-nd{display:none}}@media (max-width:900px){.kdt{gap:6px;padding:0 38px 0 8px}.kdt[dir=rtl]{padding:0 8px 0 38px}.kdt-off,.kdt-pill,.kdt-go,.kdt-lead,.kdt-ls{display:none}.kdt-chip,.kdt-clk,.kdt-txt{display:inline-flex;align-items:center;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:700;line-height:1}.kdt-chip{background:#120c14;color:#fff}.kdt-clk,.kdt-txt{background:rgba(255,255,255,.7);border:1px solid rgba(18,12,20,.08)}.kdt-clk{font-size:11.5px}.kdt-clk small{font-size:8.5px;margin:0 2px 0 1px}.kdt-txt{flex:0 1 auto}.kdt-x{right:8px;width:22px;height:22px;margin-top:-11px}.kdt[dir=rtl] .kdt-x{left:8px}.kdt-x svg{width:10px;height:10px}.kdt-rot{display:inline-block;position:relative}.kdt-rot span{position:absolute;top:2px;inset-inline-start:0}.kdt-rot span.on{position:relative;top:0}.kdt.kdt-np{display:none}}@media (max-width:359px){.kdt{gap:4px;padding-left:6px}.kdt[dir=rtl]{padding-right:6px}.kdt-chip,.kdt-clk,.kdt-txt{padding:5px 7px;font-size:10px}}.kdt.kdt-still,.kdt.kdt-still .kdt-pill::after{animation:none}@media (prefers-reduced-motion:reduce){.kdt,.kdt-pill::after{animation:none}.kdt-rot span{transition:none}}';

    /** key => [type, default] -- the shipped state is the owner's own words, switched on. */
    public const SCHEMA = [
        'on' => ['bool', true],
        'show_phone' => ['bool', true],
        'show_desktop' => ['bool', true],
        'motion' => ['bool', true],
        // (27 Nov) "the corner cross icon to hide / close this strip if user want": on as he asked
        'closable' => ['bool', true],
        'mode' => ['select', 'until'],
        'ends_at' => ['datetime', '2026-11-28T00:00'],
        /* (27 Nov, "make our top strip like this, thin and similar, and by changing brands names continuously";
           T2 chosen for computers, M5 "Chips" for phones) -- the thin bar: "Offer ends in" + clock, the pill, the
           line before the turning brand, the button; on phones the chip, the clock chip and the brand chip. */
        'offer_en' => ['text', 'Offer ends in'],
        'heading_en' => ['text', 'BLACK FRIDAY TIME DEAL'],
        'sub_en' => ['text', 'Last chance to get amazing deals on'],
        'short_en' => ['text', 'Deals on'],
        'chip_en' => ['text', 'BLACK FRIDAY'],
        'button_en' => ['text', 'Shop Now'],
        'offer_ar' => ['text', 'ينتهي العرض خلال'],
        'heading_ar' => ['text', 'الجمعة البيضاء · عرض لفترة محدودة'],
        'sub_ar' => ['text', 'فرصتك الأخيرة لعروض مذهلة على'],
        'short_ar' => ['text', 'عروض على'],
        'chip_ar' => ['text', 'الجمعة البيضاء'],
        'button_ar' => ['text', 'تسوّقي الآن'],
        'brands' => ['list', 'Medicube, Anua, Dr.Althea, numbuzin, celimax, Beauty of Joseon, SKIN1004, COSRX, d\'Alba, AXIS-Y'],
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
            case 'list':
                if (! is_scalar($raw) || is_bool($raw)) {
                    return null;
                }
                $items = self::brandList((string) $raw);

                return count($items) <= self::BRANDS_MAX && mb_strlen((string) $raw) <= 600 ? implode(', ', $items) : null;
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
     * @return array{offer:string, heading:string, sub:string, short:string, chip:string, brands:list<string>, units:list<string>, button:string, href:string, end:int, daily:bool, left:int, rtl:bool, phone:bool, desktop:bool, motion:bool, closable:bool}
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
            'offer' => $pick('offer'),
            'heading' => $pick('heading'),
            'sub' => $pick('sub'),
            'short' => $pick('short'),
            'chip' => $pick('chip'),
            'brands' => array_slice(self::brandList((string) $v['brands']), 0, self::BRANDS_MAX),
            'units' => $ar ? [':', ':', ''] : ['h', 'm', 's'],   // Arabic: 06:24:52 (Arabic unit letters read in a confusing order inside a left-to-right clock)
            'button' => $pick('button'),
            'href' => $link,
            'end' => (int) ($end->getTimestamp() * 1000),
            'daily' => $v['mode'] === 'daily',
            'left' => max(0, $end->getTimestamp() - $now->getTimestamp()),
            'rtl' => $ar,
            'phone' => (bool) $v['show_phone'],
            'desktop' => (bool) $v['show_desktop'],
            'motion' => (bool) $v['motion'],
            'closable' => (bool) $v['closable'],
        ];
    }
}
