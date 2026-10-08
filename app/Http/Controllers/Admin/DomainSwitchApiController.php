<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DomainMove\ContentRewrite;
use App\Services\DomainMove\DnsLookup;
use App\Services\DomainMove\DomainReadiness;
use App\Services\DomainMove\DomainSwitch;
use App\Services\DomainMove\SwitchInstaller;
use App\Services\Import\MediaSideloader;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\SecurityModule;
use App\Services\SettingsService;
use App\Support\AdminRoles;
use App\Support\SiteHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform -> Domain switch. (Lane DW)
 *
 * Every button on the screen is one `action` on POST /admin-api/domain-switch/run.
 * Each one CALLS THE EXISTING ACTION -- SiteAddressApiController::save,
 * SiteUrlApiController::adopt, CacheApiController::clear, the Stripe, Tabby
 * and Tamara webhook controllers, MediaSideloader::batch -- so the rules each
 * of those enforces (validation, "forwarding needs a main address", "APP_URL
 * comes from the request, never the body", the provider allowlists) are the
 * rules here. Nothing is reimplemented.
 *
 * ── WHO ──────────────────────────────────────────────────────────────────
 *
 * The routes carry `platform.domain_switch` (owner-only in the map, failing
 * closed), AND every method here refuses anyone who is not a Full Admin. The
 * second check is not decoration: the actions behind these buttons are guarded
 * on their own routes by platform.site_url, cache.manage, payments.manage,
 * data.import and the Full-Admin-only owner-app address. A custom role handed
 * this one capability must not thereby reach all of those through a side door.
 *
 * ── WHAT IS LOGGED ───────────────────────────────────────────────────────
 *
 * Every POST, through SecurityModule::record(), like every other admin act:
 * the action, and for the writes the before and after of what moved.
 */
final class DomainSwitchApiController extends Controller
{
    /** The buttons, and nothing else. */
    public const ACTIONS = [
        'check_old_dns', 'check_dns', 'check_tls', 'set_names', 'switch_address',
        'stripe', 'tabby', 'tamara', 'fetch_pictures', 'forward_on', 'remove_old',
        // Lane DS: old links in the shop's text.
        'rewrite_content', 'undo_rewrite',
        // Lane DW2: the installer's own buttons, each calling an existing action.
        'coming_soon_on', 'coming_soon_off', 'clear_caches', 'indexnow',
    ];

    /** What the installer's step endpoint accepts. */
    public const STEP_DOS = ['verify', 'done', 'skip', 'undo', 'reset'];

    /** Typed to pass step 8's gate without green DNS and certificate checks. */
    public const OVERRIDE = 'CONFIRM';

    public const AUDIT_EVENT = 'domain_switch';

    public function __construct(private DomainSwitch $switch, private SwitchInstaller $installer) {}

    /**
     * GET /admin-api/domain-switch -- the installer as it stands: every step's
     * stored progress and the shop's own settings. Reads the database only;
     * no DNS, no TLS, no provider is asked when the page opens.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->refuse($request) ?? response()->json($this->full($request));
    }

    /**
     * POST /admin-api/domain-switch/step {step, do: verify|done|skip|undo} and
     * {do: reset, confirm: RESET}. (Lane DW2)
     *
     * `step` is checked against SwitchInstaller::STEPS; nothing in the body
     * becomes a host, a URL or a column. Verify is the only path that reaches
     * the network, and only towards the shop's own names and the payment APIs.
     */
    public function step(Request $request): JsonResponse
    {
        if ($refused = $this->refuse($request)) {
            return $refused;
        }

        $data = $request->validate([
            'do' => ['required', 'string', 'in:'.implode(',', self::STEP_DOS)],
            'step' => ['required_unless:do,reset', 'nullable', 'string', 'in:'.implode(',', array_keys(SwitchInstaller::STEPS))],
            'confirm' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        if (! SwitchInstaller::ready()) {
            return $this->no(409, 'The installer’s progress table is not there yet. Run the package’s migrations (Store → Core Updates applies them), then reload.');
        }

        $do = (string) $data['do'];
        $key = (string) ($data['step'] ?? '');

        if ($do === 'reset') {
            if (($data['confirm'] ?? '') !== 'RESET') {
                return $this->no(422, 'Confirm first: type RESET. Only the ticks on this page are forgotten; the shop itself is not changed.');
            }

            $this->installer->reset();
            $this->audit('installer_reset', true, 'progress reset');

            return response()->json(['ok' => true, 'message' => 'Progress reset. The shop itself was not changed; Verify each step again to see where it stands.'] + $this->full($request));
        }

        if ($do === 'verify') {
            if (SwitchInstaller::STEPS[$key][1] !== 'verify') {
                return $this->no(422, 'Step '.SwitchInstaller::num($key).' cannot be checked by the shop. Do it, then press “Mark as done”.');
            }

            try {
                $r = $this->installer->verify($key, $request);
            } catch (\Throwable) {
                return $this->no(500, 'The check stopped unexpectedly. Nothing was changed; press Verify again in a minute.');
            }

            $this->audit('verify_'.$key, true, $r['level'].': '.$r['message']);

            return response()->json(['ok' => true, 'level' => $r['level'], 'message' => $r['message'], 'fix' => $r['fix']] + $this->full($request));
        }

        $r = $this->installer->mark($key, $do);
        $this->audit($do.'_'.$key, $r['ok'], $r['message']);

        return response()->json($r + $this->full($request), $r['ok'] ? 200 : 422);
    }

    /** GET /admin-api/domain-switch/readiness?offset=N -- read-only, time-boxed. */
    public function readiness(Request $request): JsonResponse
    {
        if ($refused = $this->refuse($request)) {
            return $refused;
        }

        $data = $request->validate(['offset' => ['sometimes', 'integer', 'min:0', 'max:100000']]);

        return response()->json($this->switch->readiness((int) ($data['offset'] ?? 0)));
    }

    /** GET /admin-api/domain-switch/pictures -- read-only. */
    public function pictures(Request $request): JsonResponse
    {
        return $this->refuse($request) ?? response()->json($this->switch->pictures());
    }

    /**
     * GET /admin-api/domain-switch/rewrite -- the old-links step's preview: every place an
     * absolute link to the domain being left would change, counted, with a
     * sample each. Reads only. (Lane DS)
     */
    public function rewritePreview(Request $request): JsonResponse
    {
        if ($refused = $this->refuse($request)) {
            return $refused;
        }

        return response()->json(['ok' => true, 'can_apply' => $this->switch->addressSwitched()]
            + ContentRewrite::forSwitch($this->switch)->preview());
    }

    /** POST /admin-api/domain-switch/run {action, ...} */
    public function run(Request $request): JsonResponse
    {
        if ($refused = $this->refuse($request)) {
            return $refused;
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'confirm' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'override' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        $action = (string) $data['action'];

        $response = match ($action) {
            'check_old_dns' => $this->ok($this->switch->checkOldDns(app(DnsLookup::class))),
            'check_dns' => $this->ok($this->switch->checkDns(app(DnsLookup::class))),
            'check_tls' => $this->ok($this->switch->checkTls()),
            'set_names' => $this->setNames((string) ($data['domain'] ?? '')),
            'switch_address' => $this->switchAddress($request, (string) ($data['override'] ?? '')),
            'stripe', 'tabby', 'tamara' => $this->payment($action),
            'fetch_pictures' => $this->fetchPictures(),
            'forward_on' => $this->forwardOn(),
            'remove_old' => $this->removeOld((string) ($data['confirm'] ?? '')),
            'rewrite_content' => $this->rewriteContent((string) ($data['confirm'] ?? '')),
            'undo_rewrite' => $this->undoRewrite(),
            'coming_soon_on' => $this->comingSoon(true),
            'coming_soon_off' => $this->comingSoon(false),
            'clear_caches' => $this->clearCaches(),
            'indexnow' => $this->indexNow(),
        };

        $body = (array) $response->getData(true);
        $this->audit($action, $response->getStatusCode() < 400 && ($body['ok'] ?? true) !== false, (string) ($body['message'] ?? ''));

        // The screen redraws from one answer: what happened, and every step as it now is.
        \App\Models\Setting::flushMap();
        SiteHost::forget();

        // A step whose check reads only the shop is verified straight away, so
        // the owner sees the change land without pressing anything else.
        $step = SwitchInstaller::ACTION_STEP[$action] ?? null;
        $ok = $response->getStatusCode() < 400 && ($body['ok'] ?? true) !== false;

        if ($ok && $step !== null && in_array($step, SwitchInstaller::LOCAL, true) && SwitchInstaller::ready()) {
            try {
                $this->installer->verify($step, $request);
            } catch (\Throwable) {
                // The action happened; the owner can press Verify.
            }
        }

        return response()->json($body + ['state' => $this->full($request)], $response->getStatusCode());
    }

    /**
     * Everything the installer screen draws: the shop's state, the stored
     * progress, and the Coming Soon preview link when the page is on.
     *
     * @return array<string, mixed>
     */
    private function full(Request $request): array
    {
        $state = $this->switch->state($request);
        $state['installer'] = $this->installer->view();
        $state['coming_soon_link'] = null;

        if (($state['coming_soon']['on'] ?? false) === true) {
            $cs = (array) app(ComingSoonApiController::class)->show($request)->getData(true);
            $state['coming_soon_link'] = is_array($cs['link'] ?? null) ? ['url' => (string) $cs['link']['url'], 'until' => (string) $cs['link']['until']] : null;
        }

        $state['emergency'] = \App\Support\ComingSoon::EMERGENCY;
        $state['old_admin'] = 'https://'.$this->switch->primaryOld();

        return $state;
    }

    /* ═════════════════════════════════════════════════════════ actions ══ */

    /** The new name: main address = the new domain, old addresses = the ones being left, forwarding off. */
    private function setNames(string $typed): JsonResponse
    {
        $domain = SiteHost::normalise($this->stripScheme($typed));

        if (! DomainSwitch::isName($domain)) {
            return $this->no(422, 'Type just the new domain, like kbeautybliss.com — no https://, no slash.');
        }

        $bare = DomainReadiness::bare($domain);

        if ($this->switch->oldRemoved() && $domain === SiteHost::canonical()) {
            // Pressing it again after the old address was removed would list it again.
            return $this->ok(['message' => 'Nothing to do: '.$domain.' is the main address and '.$this->switch->primaryOld().' was removed in step '.SwitchInstaller::num('done').'.']);
        }

        if ($bare === DomainSwitch::DEFAULT_OLD || $bare === DomainReadiness::bare($this->switch->appHost())) {
            return $this->no(422, $domain.' is the address the shop is leaving. Type the new one.');
        }

        $before = SiteHost::canonical();
        $old = [];

        // What was typed before stays; the domains being left are added.
        foreach (preg_split('/[\r\n,]+/', (string) (\App\Models\Setting::map()[SiteHost::KEY_ALIASES] ?? '')) ?: [] as $line) {
            $host = SiteHost::normalise((string) $line);

            if ($host !== '' && DomainReadiness::bare($host) !== $bare) {
                $old[$host] = true;
            }
        }

        foreach ([...DomainReadiness::derivedOld($bare), DomainSwitch::DEFAULT_OLD] as $host) {
            $host = DomainReadiness::bare((string) $host);

            if ($host !== '' && $host !== $bare && DomainSwitch::isName($host)) {
                $old[$host] = true;
            }
        }

        /*
         * FORWARDING OFF -- unless this exact main address was already set, so a
         * second press after forwarding is on does not quietly switch the forwarding
         * back off. The first press is the one the checklist describes.
         */
        $redirect = $before === $domain ? SiteHost::redirectEnabled() : false;

        $result = $this->siteAddress($domain, implode("\n", array_keys($old)), $redirect);

        if ($result !== null) {
            return $result;
        }

        return $this->ok([
            'message' => 'Done. Main address: '.$domain.'. Old addresses: '.implode(', ', array_keys($old))
                .'. Forwarding: '.($redirect ? 'on' : 'off').'. Nothing visible changed: '.$this->switch->primaryOld().' works exactly as before.',
        ]);
    }

    /**
     * The "main address" step: APP_URL, Site URL, caches. Only on the new
     * address, as "Use this address" always was -- and only once the DNS and
     * certificate steps have verified green, unless the owner types CONFIRM.
     */
    private function switchAddress(Request $request, string $override): JsonResponse
    {
        if (! $this->switch->servedOnTarget($request)) {
            return $this->no(409, 'Open https://'.$this->switch->target().' (the link above), sign in there, and press this button on that page. '
                .'It only works on the new address, so the shop can never be pointed at an address that does not reach it.');
        }

        if (! $this->installer->networkGreen() && $override !== self::OVERRIDE) {
            return $this->no(409, 'Not yet: steps '.SwitchInstaller::num('dns_wait').' (DNS) and '.SwitchInstaller::num('ssl').' (certificate) have not both verified green. '
                .'Press Verify on each first. If you are sure, type '.self::OVERRIDE.' to switch anyway — customers whose DNS has not caught up would not reach the shop.');
        }

        $adopt = app(SiteUrlApiController::class)->adopt($request);
        $a = (array) $adopt->getData(true);

        if ($adopt->getStatusCode() >= 400 || ($a['ok'] ?? false) !== true) {
            return $this->no($adopt->getStatusCode() >= 400 ? $adopt->getStatusCode() : 422,
                (string) ($a['reason'] ?? 'The shop’s address could not be changed. Nothing else was touched.'));
        }

        $siteUrl = 'https://'.$this->switch->target();
        $previous = (string) (\App\Models\Setting::map()['site_url'] ?? '');

        if ($previous !== $siteUrl) {
            // set() drops the settings map Setting::map() and SeoSettings::map() share.
            app(SettingsService::class)->set('site_url', $siteUrl);
        }

        $clear = app(CacheApiController::class)->clear(Request::create('/', 'POST', ['target' => 'all']));

        return $this->ok([
            'message' => 'Done. The shop now calls itself '.$siteUrl.' in emails, payment notices and links; Site URL is '.$siteUrl
                .'; the shop’s caches were cleared. Now press Purge Varnish in Cloudways.',
            'changed' => (bool) ($a['changed'] ?? false),
            'caches_cleared' => $clear->getStatusCode() < 400,
        ]);
    }

    /** The payments step: the existing provider actions, after the address has moved. */
    private function payment(string $provider): JsonResponse
    {
        if (! $this->switch->addressSwitched()) {
            return $this->no(409, 'Do step '.SwitchInstaller::num('switch').' first. The payment providers are told the shop’s own address, and it is still '
                .($this->switch->appHost() ?: 'not set').'.');
        }

        try {
            [$ok, $message] = match ($provider) {
                'stripe' => $this->stripe(),
                'tabby' => $this->tabby(),
                'tamara' => $this->tamara(),
            };
        } catch (\Throwable) {
            [$ok, $message] = [false, ucfirst($provider).' could not be reached just now. Nothing was changed; try again in a minute.'];
        }

        $this->switch->remember('pay_'.$provider, [
            'ok' => $ok, 'message' => $message, 'at' => now()->toIso8601String(), 'host' => $this->switch->target(),
        ]);

        return $ok ? $this->ok(['message' => $message]) : $this->no(422, $message);
    }

    /** @return array{0: bool, 1: string} */
    private function stripe(): array
    {
        $r = (array) app()->call([app(StripeSettingsController::class), 'setup'])->getData(true);

        if (($r['ok'] ?? false) !== true) {
            return [false, 'Stripe: '.(string) ($r['error'] ?? 'Stripe refused. Nothing was changed.')];
        }

        $removed = (int) ($r['stale_removed'] ?? 0);

        return [true, 'Stripe will now send payment notices to '.$this->switch->target().'.'
            .($removed > 0 ? ' '.$removed.' old webhook(s) removed.' : '')
            .' Now add '.$this->switch->newBare().' under Payment method domains in the Stripe dashboard.'];
    }

    /** @return array{0: bool, 1: string} */
    private function tabby(): array
    {
        $response = app()->call([app(TabbyWebhookController::class), 'sync']);
        $r = (array) $response->getData(true);
        $notes = [];
        $states = [];

        foreach ((array) ($r['countries'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $states[] = (string) ($row['state'] ?? '');

            if (($row['message'] ?? '') !== '') {
                $notes[] = ($row['country'] ?? '').': '.$row['message'];
            }
        }

        /*
         * TabbyWebhookController answers 200 with `ok` true whenever the call
         * RAN, and puts each country's outcome in its own row -- right for the
         * Payments screen, which draws every row. A ✓ here has to mean Tabby
         * holds this address: no country refused or unreadable, and at least
         * one registered (a run where every country is "not authorised" has
         * registered nothing).
         */
        $failed = array_intersect($states, ['failed', 'unreadable']) !== [];
        $landed = array_intersect($states, ['registered', 'current', 'updated']) !== [];

        if ($response->getStatusCode() >= 400 || ($r['ok'] ?? false) !== true || $failed || ! $landed) {
            return [false, 'Tabby: '.trim((string) ($r['message'] ?? 'Tabby refused. Nothing was changed.').' '.implode(' ', $notes))];
        }

        return [true, trim('Tabby will now send notices to '.$this->switch->target().'. '.implode(' ', $notes))];
    }

    /** @return array{0: bool, 1: string} Remove, then Register -- Tamara offers no "replace". */
    private function tamara(): array
    {
        $controller = app(TamaraAdminController::class);
        $removed = $controller->unregisterWebhook();
        $r = (array) $removed->getData(true);

        if ($removed->getStatusCode() >= 400 || ($r['ok'] ?? false) !== true) {
            return [false, 'Tamara: '.(string) ($r['message'] ?? 'Tamara refused. Nothing was changed.')];
        }

        $registered = $controller->registerWebhook();
        $r = (array) $registered->getData(true);

        if ($registered->getStatusCode() >= 400 || ($r['ok'] ?? false) !== true) {
            return [false, 'Tamara: the old registration was removed, but registering again failed: '
                .(string) ($r['message'] ?? 'Tamara refused.').' Press this button again.'];
        }

        return [true, 'Tamara will now send notices to '.$this->switch->target().'.'];
    }

    /** Before you start: one bounded batch of the importer's own picture fetcher. Press again to continue. */
    private function fetchPictures(): JsonResponse
    {
        $r = app(MediaSideloader::class)->batch();
        $plan = (array) ($r['plan'] ?? []);
        $left = max(0, (int) ($plan['remaining'] ?? 0) - (int) ($r['fetched'] ?? 0));

        $message = ($r['ok'] ?? false)
            ? 'Fetched '.(int) ($r['fetched'] ?? 0).' picture(s)'
                .((int) ($r['failed'] ?? 0) > 0 ? ', '.(int) $r['failed'].' could not be fetched' : '')
                .'. '.($left > 0 ? 'Press again to fetch the next batch.' : 'Nothing left to fetch.')
            : 'Nothing was fetched: '.(string) ($r['stopped'] ?? 'the fetcher could not start.');

        return ($r['ok'] ?? false)
            ? $this->ok(['message' => $message, 'pictures' => $this->switch->pictures()])
            : $this->no(422, $message);
    }

    /** Forward = ON. Never while the Coming Soon page still hides the address everyone would be sent to. */
    private function forwardOn(): JsonResponse
    {
        if (! $this->switch->addressSwitched() || SiteHost::canonical() !== $this->switch->target()) {
            return $this->no(409, 'Do step '.SwitchInstaller::num('switch').' first: old links can only be sent to the new address once the shop is using it.');
        }

        $map = \App\Models\Setting::map();

        if (\App\Support\ComingSoon::on($map) && \App\Support\ComingSoon::hides($this->switch->target(), $map)) {
            return $this->no(409, 'Not yet: the Coming Soon page is still on for '.DomainReadiness::bare($this->switch->target()).'. Forwarding now would send every '
                .$this->switch->primaryOld().' visitor — your customers — to the Coming Soon page instead of the shop. Turn it off in step '
                .SwitchInstaller::num('cs_off').' first.');
        }

        if (SiteHost::redirectEnabled()) {
            return $this->ok(['message' => 'Forwarding is already on: every old '.$this->switch->primaryOld().' link lands on '.$this->switch->target().'.']);
        }

        $result = $this->siteAddress(SiteHost::canonical(), (string) (\App\Models\Setting::map()[SiteHost::KEY_ALIASES] ?? ''), true);

        return $result ?? $this->ok([
            'message' => 'Done. Every old '.$this->switch->primaryOld().' link, bookmark and installed app now lands on '.$this->switch->target().'. Leave it like this for 2–4 weeks.',
        ]);
    }

    /** In 2–4 weeks (the last step): clear the old addresses and an owner-app host on them. Only when nothing still depends on them. */
    private function removeOld(string $confirm): JsonResponse
    {
        if ($confirm !== 'REMOVE') {
            return $this->no(422, 'Confirm first: this step cannot be undone from this screen.');
        }

        if (! $this->switch->addressSwitched() || SiteHost::canonical() !== $this->switch->target()) {
            return $this->no(409, 'Do steps '.SwitchInstaller::num('switch').' and '.SwitchInstaller::num('forward').' first. The shop still uses the old address.');
        }

        $check = $this->switch->fullRisk();

        if (! $check['complete']) {
            return $this->no(409, 'The safety check could not finish in time, so nothing was removed. Try again in a minute.');
        }

        if ($check['risk'] > 0) {
            return $this->no(409, 'Nothing was removed: the readiness check (step '.SwitchInstaller::num('start').') still finds '.$check['risk']
                .' problem(s) that would break without '.$this->switch->primaryOld().'. Fix them first.');
        }

        $result = $this->siteAddress(SiteHost::canonical(), '', SiteHost::redirectEnabled());

        if ($result !== null) {
            return $result;
        }

        $owner = (string) OwnerAppPath::host();
        $ownerCleared = false;

        foreach ($this->switch->oldHosts() as $host) {
            if ($owner !== '' && ($owner === $host || str_ends_with($owner, '.'.$host))) {
                $ownerCleared = OwnerAppPath::setHost('');
                break;
            }
        }

        return $this->ok([
            'message' => 'Done. The shop no longer knows '.$this->switch->primaryOld().'.'
                .($ownerCleared ? ' The owner app’s own address ('.$owner.') was cleared, so phones sign in again at the new address.' : '')
                .' Now do the clicks listed below in Cloudways, at the registrar and in Stripe.',
        ]);
    }

    /**
     * Old links in the shop's text -> the main address. (Lane DS)
     *
     * Only once the shop has switched: before that, kbeautybliss.com
     * still opens WordPress, and a link pointed at it would leave this shop.
     * The confirm value is the number of links the preview showed, so the
     * button can only apply what the owner has just looked at.
     */
    private function rewriteContent(string $confirm): JsonResponse
    {
        if (! $this->switch->addressSwitched()) {
            return $this->no(409, 'Do step '.SwitchInstaller::num('switch').' first. Until the shop has switched, '.$this->switch->newBare()
                .' does not open this shop yet, and links pointed at it would send shoppers away.');
        }

        $rewrite = ContentRewrite::forSwitch($this->switch);
        $preview = $rewrite->preview(0);

        if ($preview['links'] === 0) {
            return $this->ok(['message' => 'Nothing to change: no link in the shop’s text points at '.implode(', ', $rewrite->oldHosts()).'.']);
        }

        if ($confirm !== (string) $preview['links']) {
            return $this->no(409, 'The shop changed since you looked. Press “Show what would change” again, then confirm.');
        }

        $result = $rewrite->apply();
        app(CacheApiController::class)->clear(Request::create('/', 'POST', ['target' => 'all']));

        return $this->ok([
            'message' => 'Done. '.$result['links'].' link(s) in '.$result['rows'].' place(s) now point at https://'.$rewrite->newHost()
                .'. Orders, customers and payment settings were not touched. “Undo” puts every one back.',
            'rewrite' => $result,
        ]);
    }

    private function undoRewrite(): JsonResponse
    {
        $result = ContentRewrite::forSwitch($this->switch)->undo();

        if ($result['batch'] === null) {
            return $this->ok(['message' => 'Nothing to undo.']);
        }

        app(CacheApiController::class)->clear(Request::create('/', 'POST', ['target' => 'all']));

        return $this->ok([
            'message' => 'Undone: '.$result['restored'].' place(s) put back as they were.'
                .($result['kept'] > 0 ? ' '.$result['kept'].' were edited since and were left as they are now.' : ''),
            'rewrite' => $result,
        ]);
    }

    /**
     * Coming Soon on (for the new address only) or off, through the Coming
     * Soon screen's own save -- its validation, its preview-link minting. (Lane DW2)
     * Idempotent: pressing it in the state it already has writes nothing.
     */
    private function comingSoon(bool $on): JsonResponse
    {
        $map = \App\Models\Setting::map();
        $host = \App\Support\ComingSoon::bare($this->switch->target());
        $isOn = \App\Support\ComingSoon::on($map);

        if (! $on && ! $isOn) {
            return $this->ok(['message' => 'The Coming Soon page is already off: every address shows the shop.']);
        }

        if ($on && $isOn && \App\Support\ComingSoon::scope($map) === \App\Support\ComingSoon::SCOPE_HOST && \App\Support\ComingSoon::host($map) === $host) {
            return $this->ok(['message' => 'The Coming Soon page is already on for '.$host.'. Nothing changed.']);
        }

        $body = $on ? ['on' => true, 'scope' => \App\Support\ComingSoon::SCOPE_HOST, 'host' => $host] : ['on' => false];
        $response = app(ComingSoonApiController::class)->save(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body)));
        $r = (array) $response->getData(true);

        if ($response->getStatusCode() >= 400 || ($r['ok'] ?? false) !== true) {
            return $this->no(422, (string) ($r['error'] ?? 'The Coming Soon page could not be saved. Nothing was changed.'));
        }

        return $this->ok(['message' => $on
            ? 'Done. '.$host.' (and www.'.$host.') now shows the Coming Soon page; '.$this->switch->primaryOld().' shows the shop as always. Signed in, you see the shop there.'
            : 'Done. The Coming Soon page is off: every address shows the shop.']);
    }

    /** Platform → Cache → Clear everything, the same call. */
    private function clearCaches(): JsonResponse
    {
        $clear = app(CacheApiController::class)->clear(Request::create('/', 'POST', ['target' => 'all']));

        return $clear->getStatusCode() < 400
            ? $this->ok(['message' => 'Done. The shop’s own caches were cleared. Now purge Varnish in Cloudways (the line below), then press Verify.'])
            : $this->no(422, 'The caches could not be cleared just now. Nothing else was touched; press it again.');
    }

    /**
     * Tell Bing and the other IndexNow engines the shop's home and shop pages
     * are at the new address, through the existing IndexNow::submit() (which
     * refuses on a private install and when instant indexing is off).
     */
    private function indexNow(): JsonResponse
    {
        if (! $this->switch->addressSwitched()) {
            return $this->no(409, 'Do step '.SwitchInstaller::num('switch').' first: the search engines would be told the old address.');
        }

        if (! \App\Services\Seo\IndexNow::enabled()) {
            return $this->no(409, 'Instant indexing is off (Store → SEO & Meta → Settings), or the shop is hidden from search engines, so nothing was sent. Google does not use IndexNow anyway: the sitemap is what it reads.');
        }

        $base = 'https://'.$this->switch->target();
        $sent = \App\Services\Seo\IndexNow::submit([$base.'/', $base.'/shop/'], $this->switch->target());

        if (SwitchInstaller::ready()) {
            $this->installer->write('google', ['data' => ['indexnow' => ['ok' => $sent, 'at' => now()->toIso8601String()]]]);
        }

        return $sent
            ? $this->ok(['message' => 'Sent. Bing and the other IndexNow search engines were told about '.$base.'/.'])
            : $this->no(422, 'IndexNow did not accept it just now. Nothing else is affected; press it again later.');
    }

    /* ═════════════════════════════════════════════════════════ helpers ══ */

    /** SiteAddressApiController::save, with its validation; null on success. */
    private function siteAddress(string $canonical, string $aliases, bool $redirect): ?JsonResponse
    {
        $response = app(SiteAddressApiController::class)->save(Request::create('/', 'POST', [
            'canonical_host' => $canonical,
            'aliases' => $aliases,
            'redirect_enabled' => $redirect,
            'visibility' => SiteHost::visibilityIsPrivate() ? 'private' : 'public',
        ]));

        if ($response->getStatusCode() < 400) {
            return null;
        }

        $errors = (array) ($response->getData(true)['errors'] ?? []);

        return $this->no(422, (string) (reset($errors) ?: 'The address could not be saved. Nothing was changed.'));
    }

    /** Full Admin, or a 403 -- see the class comment. */
    private function refuse(Request $request): ?JsonResponse
    {
        $user = $request->user('admin');

        return $user instanceof \App\Models\AdminUser && AdminRoles::isFull($user)
            ? null
            : response()->json(['ok' => false, 'message' => 'Only the owner can move the shop to another domain.'], Response::HTTP_FORBIDDEN);
    }

    private function audit(string $action, bool $ok, string $message): void
    {
        app(SecurityModule::class)->record(self::AUDIT_EVENT, 'Domain switch: '.$action.($ok ? '' : ' (refused)'), [
            'subject' => 'domain_switch.'.$action,
            'after' => mb_substr($message, 0, 500),
            'severity' => in_array($action, ['switch_address', 'remove_old', 'forward_on', 'set_names', 'rewrite_content', 'undo_rewrite', 'coming_soon_on', 'coming_soon_off', 'installer_reset'], true) && $ok ? 'alert' : 'notice',
        ]);
    }

    /** @param  array<string, mixed>  $body */
    private function ok(array $body): JsonResponse
    {
        return response()->json(['ok' => true] + $body);
    }

    private function no(int $status, string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }

    private function stripScheme(string $value): string
    {
        $value = trim($value);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        return trim($value, "/ \t\n\r\0\x0B");
    }
}
