<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use Illuminate\Support\Facades\DB;

/**
 * The owner app's look, screens and functions (Lane OA4) — one validated JSON
 * setting, `owner_app_ui`, edited under Platform → Users & Roles → Owner app →
 * Customise app (Full Admin: `ownerapp.manage`).
 *
 * The owner: "i would like to control everything from the main admin for the
 * owner app, like fonts, sizes, etc etc and any function / screen turn on/off".
 *
 * EVERY DEFAULT IS TODAY'S APP. Nobody asked for the app to look or behave any
 * differently, so with no row (or a row equal to DEFAULTS) the app is byte-
 * and pixel-identical to the build before this card existed: no class lands
 * on <html>, no variable is set, every screen and function is on, the live
 * check runs every 25 s.
 *
 * HOW IT REACHES THE PHONE. forApp() rides inside the state / enrol / unlock
 * answer the app already asks for — no request of its own. The app turns it
 * into a few classes and one custom property on <html> before it draws the
 * unlocked frame. The font choice is also read by the shell itself
 * (AppController::shell), so "System font" stops the font file being preloaded,
 * precached or requested at all.
 *
 * ENFORCED, NOT ONLY HIDDEN. A screen switched off refuses its endpoints and
 * a function switched off refuses its action, for every member, with 403
 * `off` (App\Http\Middleware\OwnerAppUiGate). The role capabilities still
 * apply on top: both must allow.
 *
 * VALIDATION. Selects store one of their own options or the default; numbers
 * are clamped; the accent must be #rrggbb and readable under white text
 * (WCAG 4.5:1 — the app draws white on it); unknown keys are dropped; the
 * dashboard order is a permutation of the five sections however it arrives.
 *
 * One query per request, memoised for that request only (a queue worker or a
 * test process never carries a switch from one request into the next); put()
 * clears it.
 */
final class OwnerAppUi
{
    public const KEY = 'owner_app_ui';

    /** The store name used to be its own setting (2.60.401); still read when this row has none. */
    public const LEGACY_STORE = 'owner_app_store_name';

    public const STORE_DEFAULT = 'K-Beauty Bliss';

    public const ACCENT_DEFAULT = '#A8475C';

    /** Petal presets, every one readable under white text (OwnerAppUiTest pins ≥ 4.5:1). */
    public const PRESETS = [
        '#A8475C' => 'Petal rose (default)',
        '#9D2759' => 'Berry',
        '#7A3E8E' => 'Plum',
        '#B3452E' => 'Terracotta',
        '#0F766E' => 'Teal',
        '#2F5DA8' => 'Ink blue',
        '#3F3A44' => 'Charcoal',
    ];

    public const MIN_CONTRAST = 4.5;

    public const SCREENS = ['store' => 'My store', 'orders' => 'Orders', 'products' => 'Products', 'customers' => 'Customers', 'notifications' => 'Notifications'];

    public const SECTIONS = ['hero' => 'Today: sales and 7-day chart', 'needs' => 'Needs you', 'avg' => 'Average order', 'returning' => 'Returning customers', 'top' => 'Top performers'];

    public const FUNCTIONS = [
        'bulk' => 'Bulk status change on orders',
        'mark_paid' => 'Mark as paid',
        'order_notes' => 'Order notes',
        'edit_price' => 'Edit price',
        'edit_stock' => 'Edit stock',
        'edit_catalogue' => 'Edit categories and visibility',
        'contact' => 'Call, WhatsApp and email buttons',
        'fullscreen' => 'Full-screen icon',
        'sync' => 'Sync now icon',
        'live' => 'Live check for new orders',
        'gross_net' => 'Show Gross and Net revenue on My store',
        'range' => 'Date range on My store (Today … Last month)',
        'top_period' => 'Top performers: 7 days | This month',
    ];

    /** Functions that ship OFF: the owner asked for Total alone ("from backend i will enable the net/gross etc when i need it"). */
    public const FUNCTIONS_OFF = ['gross_net'];

    /** Each select: its options, the first being today's look. */
    public const CHOICES = [
        'font' => ['jakarta', 'system'],
        'text' => ['m', 's', 'l'],
        'title' => ['m', 's', 'l'],
        'figure' => ['m', 's', 'l'],
        'density' => ['comfortable', 'compact'],
        'corners' => ['soft', 'medium', 'square'],
        'header' => ['compact', 'standard'],
    ];

    public const LIVE_BOUNDS = [15, 120];

    public const LIVE_DEFAULT = 25;

    /** @var array<string,mixed>|null */
    private static ?array $memo = null;

    /** The request the memo was read for: a new request reads again, so a long-lived process never serves a stale switch. */
    private static ?object $memoFor = null;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $out = ['store_name' => self::STORE_DEFAULT, 'initials' => 'KB', 'accent' => self::ACCENT_DEFAULT];
        foreach (self::CHOICES as $k => $options) {
            $out[$k] = $options[0];
        }
        $out['screens'] = array_fill_keys(array_keys(self::SCREENS), true);
        $out['sections'] = array_keys(self::SECTIONS);
        $out['sections_off'] = [];
        $out['functions'] = array_fill_keys(array_keys(self::FUNCTIONS), true);
        foreach (self::FUNCTIONS_OFF as $k) {
            $out['functions'][$k] = false;
        }
        $out['live_seconds'] = self::LIVE_DEFAULT;

        return $out;
    }

    /** @return array<string,mixed> the saved settings, cleaned; defaults when nothing is saved */
    public static function all(): array
    {
        $request = app()->bound('request') ? app('request') : null;
        if (self::$memo !== null && self::$memoFor === $request) {
            return self::$memo;
        }
        self::$memoFor = $request;

        $raw = [];
        try {
            $rows = DB::table('settings')->whereIn('key', [self::KEY, self::LEGACY_STORE])->pluck('value', 'key');
            $decoded = json_decode((string) ($rows[self::KEY] ?? ''), true);
            $raw = is_array($decoded) ? $decoded : [];
            if (! array_key_exists('store_name', $raw) && isset($rows[self::LEGACY_STORE])) {
                $raw['store_name'] = (string) $rows[self::LEGACY_STORE];
            }
        } catch (\Throwable) {
        }

        return self::$memo = self::clean($raw);
    }

    public static function storeName(): string
    {
        return (string) self::all()['store_name'];
    }

    public static function screenOn(string $screen): bool
    {
        return (bool) (self::all()['screens'][$screen] ?? false);
    }

    public static function functionOn(string $function): bool
    {
        return (bool) (self::all()['functions'][$function] ?? false);
    }

    public static function sectionOn(string $section): bool
    {
        return ! in_array($section, self::all()['sections_off'], true);
    }

    public static function systemFont(): bool
    {
        return self::all()['font'] === 'system';
    }

    /**
     * What the app is sent, inside the state / enrol / unlock answer: the
     * cleaned settings minus the store name (it already travels as `store`).
     *
     * @return array<string,mixed>
     */
    public static function forApp(): array
    {
        $ui = self::all();
        unset($ui['store_name']);

        return $ui;
    }

    /**
     * Validate and save. Returns null on success, else field => message.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,string>|null
     */
    public static function put(array $input): ?array
    {
        $accent = $input['accent'] ?? self::ACCENT_DEFAULT;
        if (! is_string($accent) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            return ['accent' => 'The accent colour must be a hex colour like #A8475C.'];
        }
        $ratio = self::contrast($accent);
        if ($ratio < self::MIN_CONTRAST) {
            return ['accent' => sprintf('White text on %s reads at %.1f:1; it needs at least 4.5:1. Choose a darker colour.', strtoupper($accent), $ratio)];
        }

        $clean = self::clean($input);

        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'autoload' => false, 'updated_at' => now(), 'created_at' => now()],
        );

        self::forget();
        \App\Models\Setting::flushMap();

        return null;
    }

    public static function forget(): void
    {
        self::$memo = null;
        self::$memoFor = null;
    }

    /** WCAG contrast of white text on $hex (#rrggbb). */
    public static function contrast(string $hex): float
    {
        $l = 0.0;
        foreach ([[1, 0.2126], [3, 0.7152], [5, 0.0722]] as [$at, $w]) {
            $c = hexdec(substr($hex, $at, 2)) / 255;
            $l += $w * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
        }

        return 1.05 / ($l + 0.05);
    }

    /**
     * Whatever arrives, the full shape back: known keys only, each one of its
     * own values or its default.
     *
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    private static function clean(array $in): array
    {
        $d = self::defaults();
        $out = $d;

        $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string) (is_scalar($in['store_name'] ?? null) ? $in['store_name'] : ''))) ?? '');
        $out['store_name'] = $name !== '' ? mb_substr($name, 0, 60) : $d['store_name'];

        $ini = mb_strtoupper(mb_substr((string) preg_replace('/[^\p{L}\p{N}]/u', '', (string) (is_scalar($in['initials'] ?? null) ? $in['initials'] : '')), 0, 3));
        $out['initials'] = $ini !== '' ? $ini : $d['initials'];

        $acc = is_string($in['accent'] ?? null) ? strtoupper($in['accent']) : '';
        $out['accent'] = preg_match('/^#[0-9A-F]{6}$/', $acc) && self::contrast($acc) >= self::MIN_CONTRAST ? $acc : $d['accent'];

        foreach (self::CHOICES as $k => $options) {
            $v = $in[$k] ?? null;
            $out[$k] = is_string($v) && in_array($v, $options, true) ? $v : $options[0];
        }

        foreach (['screens' => self::SCREENS, 'functions' => self::FUNCTIONS] as $group => $known) {
            $given = is_array($in[$group] ?? null) ? $in[$group] : [];
            foreach (array_keys($known) as $k) {
                if (array_key_exists($k, $given)) {
                    $out[$group][$k] = filter_var($given[$k], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $d[$group][$k];
                }
            }
        }

        // The order: known sections in the order given, each once, then any
        // left out in their default order — always all five.
        $order = [];
        foreach (is_array($in['sections'] ?? null) ? $in['sections'] : [] as $s) {
            if (is_string($s) && isset(self::SECTIONS[$s]) && ! in_array($s, $order, true)) {
                $order[] = $s;
            }
        }
        $out['sections'] = array_values(array_merge($order, array_diff(array_keys(self::SECTIONS), $order)));

        $off = [];
        foreach (is_array($in['sections_off'] ?? null) ? $in['sections_off'] : [] as $s) {
            if (is_string($s) && isset(self::SECTIONS[$s]) && ! in_array($s, $off, true)) {
                $off[] = $s;
            }
        }
        // Stored in the dashboard's order, so equal settings compare equal.
        $out['sections_off'] = array_values(array_intersect($out['sections'], $off));

        $live = $in['live_seconds'] ?? null;
        $n = is_numeric($live) ? (int) $live : $d['live_seconds'];
        $out['live_seconds'] = max(self::LIVE_BOUNDS[0], min(self::LIVE_BOUNDS[1], $n));

        return $out;
    }
}
