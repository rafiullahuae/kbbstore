<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Security\CountryDb;
use App\Services\Security\Firewall;
use App\Services\Security\FirewallConfig;
use App\Services\Security\FirewallLog;
use App\Services\Security\FirewallStore;
use App\Services\Security\GoodBots;
use App\Services\Security\IpBlockList;
use App\Services\SecurityModule;
use App\Support\Countries;
use App\Support\IpRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Store → Security → Firewall, the admin half.                     (Lane FW)
 *
 * Every endpoint sits under /admin-api/security/firewall behind `auth:admin`
 * and NoStoreAdminApi, and AdminCapabilities maps them — above the
 * `security/**` wildcard — to two owner-only capabilities:
 *
 *   firewall.view    GET  the screen and the live view
 *   firewall.manage  POST everything that changes what is refused
 *
 * Any other role gets 403 from EnforceAdminCapability before this runs, and an
 * unmapped route would be owner-only too (AdminCapabilities fails closed).
 *
 * Every change is written to the Security audit trail.
 */
final class FirewallApiController extends Controller
{
    public function __construct(private SecurityModule $security) {}

    /** GET security/firewall */
    public function show(Request $request): JsonResponse
    {
        $all = FirewallConfig::all();
        FirewallStore::use((string) $all['settings']['store']);

        return response()->json([
            'fields' => $this->fields($all['settings']),
            'countries' => $this->countries($all['countries']),
            'actions' => FirewallConfig::COUNTRY_ACTIONS,
            'bots' => $this->bots($all['bots']),
            'allow' => $all['allow'],
            'allow_max' => FirewallConfig::ALLOW_MAX,
            'my_ip' => IpRange::normalise($request->ip()),
            'my_ip_allowed' => $this->allowed($all['allow'], (string) $request->ip()),
            'blocks' => $this->blockCount(),
            'store' => ['name' => FirewallStore::name(), 'driver' => (string) config('cache.stores.'.FirewallStore::name().'.driver', FirewallStore::name()), 'shop' => (string) config('cache.default')],
            'data' => [
                'country' => CountryDb::verify(),
                'bots' => GoodBots::about(),
                'attribution' => ['text' => CountryDb::ATTRIBUTION, 'url' => CountryDb::ATTRIBUTION_URL],
            ],
            'live' => $this->live(),
        ]);
    }

    /** GET security/firewall/live */
    public function liveView(): JsonResponse
    {
        FirewallStore::use((string) FirewallConfig::all()['settings']['store']);

        return response()->json(['live' => $this->live()]);
    }

    /** POST security/firewall — mode, scope, limits, switches. */
    public function save(Request $request): JsonResponse
    {
        $values = array_intersect_key((array) $request->input('values', []), FirewallConfig::SCHEMA);

        if (isset($values['store']) && FirewallConfig::cast('store', $values['store']) !== 'auto'
            && ! FirewallStore::works((string) FirewallConfig::cast('store', $values['store']))) {
            return response()->json(['ok' => false, 'error' => 'That counter storage does not answer on this server. Switch it on in the hosting panel first (Cloudways: Server → Settings & Packages → Packages), then choose it here.'], 422);
        }

        $before = FirewallConfig::all()['settings'];
        FirewallConfig::save($values, $this->who());
        $after = FirewallConfig::all()['settings'];

        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $this->security->record('firewall.setting', 'Firewall: '.FirewallConfig::SCHEMA[$key][1], [
                    'subject' => 'firewall.'.$key,
                    'before' => is_bool($before[$key]) ? ($before[$key] ? 'on' : 'off') : (string) $before[$key],
                    'after' => is_bool($value) ? ($value ? 'on' : 'off') : (string) $value,
                    'severity' => $key === 'mode' ? 'notice' : 'info',
                ]);
            }
        }

        return response()->json(['ok' => true, 'fields' => $this->fields($after)]);
    }

    /** POST security/firewall/countries — {rules: {CC: action}} */
    public function countriesSave(Request $request): JsonResponse
    {
        $rules = (array) $request->input('rules', []);

        if (count($rules) > 300) {
            return response()->json(['ok' => false, 'error' => 'Too many countries in one save.'], 422);
        }

        $n = FirewallConfig::setCountries($rules, $this->who());

        if ($n > 0) {
            $this->security->record('firewall.countries', 'Firewall: '.$n.' country rule'.($n === 1 ? '' : 's').' changed', [
                'subject' => 'firewall.countries',
                'after' => mb_substr(json_encode(array_slice($rules, 0, 20)) ?: '', 0, 300),
                'severity' => 'notice',
            ]);
        }

        return response()->json(['ok' => true, 'changed' => $n, 'countries' => $this->countries(FirewallConfig::all()['countries'])]);
    }

    /** POST security/firewall/bots — {bots: {family: bool}} */
    public function botsSave(Request $request): JsonResponse
    {
        FirewallConfig::setBots((array) $request->input('bots', []), $this->who());
        $this->security->record('firewall.bots', 'Firewall: good-bot switches changed', ['subject' => 'firewall.bots', 'severity' => 'info']);

        return response()->json(['ok' => true, 'bots' => $this->bots(FirewallConfig::all()['bots'])]);
    }

    /** POST security/firewall/allow — {target, note} or {mine: true} */
    public function allowAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['nullable', 'string', 'max:49'],
            'note' => ['nullable', 'string', 'max:190'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $target = ! empty($data['mine']) ? (string) $request->ip() : trim((string) ($data['target'] ?? ''));
        $note = ! empty($data['mine']) && empty($data['note']) ? 'My address ('.($this->who() ?? 'admin').')' : ($data['note'] ?? null);
        $result = FirewallConfig::allow($target, $note, $this->who());

        if ($result['ok']) {
            $this->security->record('firewall.allow', 'Firewall: always allow '.$result['cidr'], ['subject' => $result['cidr'], 'severity' => 'notice']);
            Firewall::unban(bin2hex((string) IpRange::pack(explode('/', $result['cidr'])[0])));
        }

        return response()->json($result + ['allow' => FirewallConfig::all()['allow']], $result['ok'] ? 200 : 422);
    }

    /** POST security/firewall/allow/remove — {cidr} */
    public function allowRemove(Request $request): JsonResponse
    {
        $data = $request->validate(['cidr' => ['required', 'string', 'max:49']]);
        $ok = FirewallConfig::disallow($data['cidr']);

        if ($ok) {
            $this->security->record('firewall.allow_removed', 'Firewall: no longer always allowed '.$data['cidr'], ['subject' => $data['cidr'], 'severity' => 'notice']);
        }

        return response()->json(['ok' => $ok, 'allow' => FirewallConfig::all()['allow']], $ok ? 200 : 404);
    }

    /** POST security/firewall/unban — {subject} */
    public function unban(Request $request): JsonResponse
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:49']]);
        FirewallStore::use((string) FirewallConfig::all()['settings']['store']);
        $ok = Firewall::unban($data['subject']);

        if ($ok) {
            $this->security->record('firewall.unban', 'Firewall: ban lifted', ['subject' => $data['subject'], 'severity' => 'notice']);
        }

        return response()->json(['ok' => $ok, 'bans' => Firewall::bans()], $ok ? 200 : 404);
    }

    /** POST security/firewall/data — {what: countries|bots}. Throttled 6/min. */
    public function refreshData(Request $request): JsonResponse
    {
        $what = (string) $request->input('what');
        @ignore_user_abort(true);

        if ($what === 'countries') {
            $r = CountryDb::download();
            $this->security->record('firewall.data', 'Firewall: country database '.($r['ok'] ? 'downloaded' : 'download failed'), ['subject' => 'firewall.country', 'after' => $r['ok'] ? $r['v4'].' + '.$r['v6'].' ranges' : (string) $r['error'], 'severity' => $r['ok'] ? 'info' : 'warning']);

            return response()->json(['ok' => $r['ok'], 'country' => CountryDb::verify(), 'error' => $r['error']], $r['ok'] ? 200 : 502);
        }

        if ($what === 'bots') {
            $report = GoodBots::refresh();
            $ok = array_filter($report, static fn (array $r): bool => $r['ok']) !== [];
            $this->security->record('firewall.data', 'Firewall: good-bot address lists '.($ok ? 'refreshed' : 'not refreshed'), ['subject' => 'firewall.bots', 'severity' => $ok ? 'info' : 'warning']);

            return response()->json(['ok' => $ok, 'report' => $report, 'bots' => GoodBots::about()], $ok ? 200 : 502);
        }

        return response()->json(['ok' => false, 'error' => 'Unknown data set.'], 422);
    }

    /* ─────────────────────────────────────────────────────────── helpers */

    private function live(): array
    {
        return FirewallLog::report() + ['bans' => Firewall::bans(), 'mode' => IpBlockList::compiled()['fw']['mode'] ?? 'off'];
    }

    private function fields(array $settings): array
    {
        $out = [];

        foreach (FirewallConfig::SCHEMA as $key => $def) {
            $row = ['key' => $key, 'type' => $def[0], 'label' => $def[1], 'default' => $def[2], 'help' => $def[3], 'value' => $settings[$key]];

            if ($def[0] === 'select') {
                $row['options'] = $def[4];
            } elseif ($def[0] === 'range') {
                $row += $def[4];
            }

            $out[] = $row;
        }

        return $out;
    }

    /** Every country the database knows (or the shop's list before it is downloaded). */
    private function countries(array $rules): array
    {
        $codes = array_unique(array_merge(CountryDb::codes(), array_keys(Countries::NAMES), array_keys($rules)));
        sort($codes);
        $out = [];

        foreach ($codes as $cc) {
            $name = Countries::NAMES[$cc] ?? null;

            if ($name === null && class_exists(\Locale::class)) {
                $name = \Locale::getDisplayRegion('-'.$cc, 'en');
                $name = $name === '' || $name === $cc ? null : $name;
            }

            $out[] = ['code' => $cc, 'name' => $name ?? $cc, 'action' => $rules[$cc] ?? 'allow', 'preset' => FirewallConfig::COUNTRY_DEFAULTS[$cc] ?? 'allow'];
        }

        return $out;
    }

    private function bots(array $switches): array
    {
        $about = GoodBots::about()['families'];
        $out = [];

        foreach (GoodBots::FAMILIES as $family => $def) {
            $out[] = ['family' => $family, 'label' => $def[0], 'on' => (bool) ($switches[$family] ?? true)] + $about[$family];
        }

        return $out;
    }

    private function allowed(array $allow, string $ip): bool
    {
        foreach ($allow as $a) {
            if (IpRange::contains($a['cidr'], $ip)) {
                return true;
            }
        }

        return false;
    }

    private function blockCount(): int
    {
        try {
            return (int) DB::table('ip_blocks')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function who(): ?string
    {
        $admin = Auth::guard('admin')->user();

        return $admin ? (string) ($admin->name ?: $admin->email) : null;
    }
}
