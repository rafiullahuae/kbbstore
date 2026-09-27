<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramPost;
use App\Services\Instagram\IgPath;
use App\Services\Instagram\InstagramAuth;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramCredentials;
use App\Services\Instagram\InstagramSync;
use App\Services\InstagramFeed;
use App\Services\InstagramSettings;
use App\Services\ModuleSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Content → Instagram. The connection, the look, and the fetch.
 *
 * Phase 21, Lane IG. docs/IG-PROFILE.md is the research behind every decision
 * here — §1 for what Meta permits for OUR OWN account, §7 for the six things the
 * owner must do himself, §8 for what "Configure now" actually does.
 *
 * ── NOT ONE METHOD HERE CAN RETURN A CREDENTIAL ─────────────────────────────
 *
 * That is the single most important property of this file and it is enforced by
 * construction rather than by care: InstagramCredentials exposes hasSecret(),
 * hasToken(), expiresAt() and daysLeft(), and its two getters — secret() and
 * token() — are called from exactly two places, neither of which is a payload
 * (InstagramAuth::authorizeUrl(), which puts the APP ID in a URL and only asks
 * whether the secret exists, and InstagramSync, which sends the token to Meta).
 * status() below therefore cannot leak one by accident, because it has nothing to
 * leak: it is assembled from booleans and a date.
 *
 * `app_id` IS returned, and that is deliberate rather than an oversight. A Meta
 * app id is public by construction — it travels in the authorisation URL in the
 * owner's own address bar — and showing it is how he checks he pasted the right
 * one. The SECRET beside it is reported as a boolean and nothing else, ever.
 * InstagramSecurityTest asserts both halves by name.
 *
 * ── EVERY METHOD IS ITS OWN CAPABILITY, AND THE TWO GETs THAT WRITE ─────────
 *
 * App\Support\AdminCapabilities::RULES maps `instagram.view` to the status read
 * and `instagram.manage` to everything else — including start() and callback(),
 * which are GETs that write, and which are therefore named INDIVIDUALLY above the
 * general GET rule in a first-match-wins array. That block carries the reasoning.
 *
 * The callback is CSRF-defended by the single-use `state`, because the
 * VerifyCsrfToken middleware cannot apply to a GET arriving from a third party.
 */
class InstagramController extends Controller
{
    public function __construct(
        private InstagramSettings $settings,
        private InstagramSync $sync,
    ) {}

    /* ------------------------------------------------------------------- reading */

    /**
     * Everything the screen draws, and not one secret.
     *
     * ── THE SIX STEPS ARE CHECKED, NOT ASKED ────────────────────────────────
     *
     * docs/IG-PROFILE.md §7 lists six things the owner must do himself, and the
     * screen prints them as a checklist. The four that this server can OBSERVE are
     * derived from what is actually saved rather than from a box he ticked: an app
     * id is saved or it is not, a token is held or it is not, a profile came back
     * professional or it did not. A checklist a user ticks himself is a checklist
     * that says "done" about the step he skipped, which is the one thing it exists
     * to catch.
     *
     * The two that cannot be observed from here — whether he registered the exact
     * redirect URI in the Meta dashboard, and whether he accepted a tester
     * invitation — are marked `observable: false` and the screen draws them as
     * instructions rather than as ticks. Saying "we cannot see this one" is honest;
     * drawing an empty tick beside it would imply this server checked and found it
     * undone.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'tabs' => ModuleSchema::tabs(
                InstagramSettings::SCHEMA,
                InstagramSettings::TABS,
                $this->settings->all(),
                InstagramSettings::POLICY,
            ),
            /*
             * Said on the screen, because every control here is inert without it.
             * "I picked a layout and nothing happened" is the support call this one
             * line prevents — the same reason UgcAppearanceController carries it.
             */
            'module_on' => $this->settings->enabled(),
            'connection' => $this->connection(),
            'profile' => $this->publicProfile(),
            'content' => $this->content(),
        ]);
    }

    /**
     * The connection's state, as booleans and one date.
     *
     * @return array<string, mixed>
     */
    private function connection(): array
    {
        $expires = InstagramCredentials::expiresAt();

        return [
            // Public by construction — see the class docblock.
            'app_id' => InstagramCredentials::appId(),
            /*
             * A BOOLEAN AND NOT A MASK. A row of asterisks the length of the value
             * discloses the length, and the length of a Meta app secret is a fact
             * about the format rather than about this shop — but the habit is the
             * thing being avoided here, because the next secret this pattern is
             * copied onto may be one whose length matters. SecretStore's contract
             * makes the same argument.
             */
            'secret_saved' => InstagramCredentials::hasSecret(),
            'connected' => InstagramCredentials::hasToken(),
            'expires_at' => $expires === null ? null : gmdate('c', $expires),
            'days_left' => InstagramCredentials::daysLeft(),
            'needs_refresh' => InstagramCredentials::needsRefresh(),
            'expired' => InstagramCredentials::expired(),
            /*
             * The exact string the owner pastes into Meta's OAuth redirect URIs
             * box, with a Copy button beside it on the screen. docs/IG-PROFILE.md
             * §7 item 4: a single character of difference is the commonest way this
             * whole flow fails, and "type this carefully" is how that happens.
             */
            'redirect_uri' => InstagramAuth::redirectUri(),
            // Printed on the screen so the owner can see what he is granting,
            // before he grants it. A constant, from InstagramClient.
            'scope' => InstagramClient::SCOPE,
            'steps' => $this->steps(),
        ];
    }

    /**
     * The §7 checklist, each item observed where it can be.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(): array
    {
        $profile = $this->settings->profile();
        $type = strtoupper((string) ($profile['account_type'] ?? ''));

        return [
            [
                'key' => 'professional',
                'text' => 'Make @kbeauty.bliss a professional account — Instagram app → Settings → Account '
                    .'type and tools → Switch to professional account → Business or Creator. A personal '
                    .'account returns nothing from any of these endpoints, at any price.',
                'observable' => true,
                // Only knowable once a profile has come back, which is why an
                // unconnected shop reports this as not-yet-known rather than failed.
                'done' => $type !== '' && $type !== 'PERSONAL',
            ],
            [
                'key' => 'app',
                'text' => 'Create a Meta app — developers.facebook.com → My Apps → Create app → use case '
                    .'“Other” → type Business → add the Instagram product → API setup with Instagram login.',
                'observable' => true,
                'done' => InstagramCredentials::hasAppId(),
            ],
            [
                'key' => 'keys',
                'text' => 'Paste the Instagram app ID and app secret into the boxes below. They are on that '
                    .'same “API setup with Instagram login” panel. The secret is stored encrypted and is '
                    .'never shown again.',
                'observable' => true,
                'done' => InstagramCredentials::hasAppId() && InstagramCredentials::hasSecret(),
            ],
            [
                'key' => 'redirect',
                'text' => 'Register the redirect URI in Meta, exactly as printed above — Business login '
                    .'settings → OAuth redirect URIs. One character of difference is the error Meta reports '
                    .'as a redirect_uri mismatch, and it is the commonest way this fails.',
                /*
                 * This server cannot see the owner's Meta dashboard, so it says so
                 * rather than drawing an unticked box that implies it looked.
                 */
                'observable' => false,
                'done' => null,
            ],
            [
                'key' => 'tester',
                'text' => 'If the app is in Live mode, add yourself under App roles → Instagram testers and '
                    .'accept the invitation in the Instagram app (Settings → Apps and websites → Tester '
                    .'invites). In Development mode an app admin needs no invitation.',
                'observable' => false,
                'done' => null,
            ],
            [
                'key' => 'connect',
                'text' => 'Press Configure now. Everything after this point is automated: the authorisation, '
                    .'the 60-day token, the profile, the posts and their pictures.',
                'observable' => true,
                'done' => InstagramCredentials::hasToken(),
            ],
        ];
    }

    /**
     * The profile box's own data for the SCREEN — the same allowlist the storefront
     * gets, plus the account type and the fetch time the owner needs and a shopper
     * does not.
     *
     * Deliberately not `$this->settings->profile()` raw: that blob is whatever the
     * last fetch wrote, so a key Meta adds next year would ride straight onto an
     * admin payload. An allowlist with a type per key, exactly as
     * InstagramFeed::profileBox() does it for the shop.
     *
     * @return array<string, mixed>
     */
    private function publicProfile(): array
    {
        $raw = $this->settings->profile();

        if ($raw === []) {
            return [];
        }

        return [
            'username' => (string) ($raw['username'] ?? ''),
            'name' => (string) ($raw['name'] ?? ''),
            'avatar' => \App\Services\Instagram\IgPath::stored(
                is_string($raw['avatar'] ?? null) ? $raw['avatar'] : null
            ),
            'followers' => isset($raw['followers']) && is_numeric($raw['followers']) ? (int) $raw['followers'] : null,
            'posts' => isset($raw['posts']) && is_numeric($raw['posts']) ? (int) $raw['posts'] : null,
            'account_type' => strtoupper((string) ($raw['account_type'] ?? '')),
            'fetched_at' => isset($raw['fetched_at']) && is_numeric($raw['fetched_at'])
                ? gmdate('c', (int) $raw['fetched_at'])
                : null,
        ];
    }

    /**
     * How much there is to draw, which is the question "why is my grid empty?"
     * answered before it is asked.
     *
     * TWO NUMBERS AND NOT ONE, because they fail differently and the remedies
     * differ: rows that exist but have no picture mean the thumbnail downloads are
     * failing (a disk, a permission, a CDN), where no rows at all means no fetch has
     * ever succeeded. One combined count would hide the first case entirely.
     *
     * And `tiles`, which is the PREVIEW's content — the same posts the shop would
     * draw, through their own narrow allowlist. It rides on this payload rather than
     * on an endpoint of its own because the screen is already asking for this exact
     * thing: the preview has to redraw on every keystroke and every drag, and a
     * fetch per keystroke is the cost the brief's "light weight" rules out. One read,
     * everything the drawing needs, and then the drawing is CSS.
     *
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $total = InstagramPost::query()->count();

        return [
            'posts' => $total,
            'drawable' => InstagramPost::query()->drawable()->count(),
            'newest' => InstagramPost::query()->drawable()->recent()->value('posted_at'),
            'tiles' => $this->previewTiles(),
        ];
    }

    /**
     * The pictures the screen's preview draws, and NOTHING a preview cannot use.
     *
     * ── ITS OWN ALLOWLIST, NARROWER THAN THE STOREFRONT'S ───────────────────
     *
     * Not `InstagramPost::toTile()`, which is the SHOP's allowlist and carries three
     * fields a drawing has no use for: `permalink`, `embed` and the row `id`. Rule 5
     * is "allowlist what a model returns, never the model", and the honest reading of
     * it here is the narrowest list that draws the picture — six keys, each one
     * something the preview actually paints. A preview that carries a permalink is a
     * preview one careless edit away from being a link, and `id` is a row number this
     * screen never asks the server about.
     *
     * The caption is TRUNCATED here rather than in the browser. `.igs-pvcap` clamps
     * it to four lines whatever arrives, so a 2,200-character caption would be 2,100
     * characters of payload nobody can see — on a screen that ships up to 24 of them.
     * 160 is comfortably more than four lines at the size it is drawn.
     *
     * ── 24 AND NOT `posts` ──────────────────────────────────────────────────
     *
     * The cap is the MAXIMUM of the `posts` range, not its current value, because the
     * slider moves without asking this server anything: the preview redraws on input
     * (rule 4's "measured rather than asserted" has a sibling here — a preview that
     * needed a fetch per keystroke would be one). Dragging from 9 to 24 has to have
     * 24 tiles already in hand or it draws placeholders for posts that exist.
     *
     * One query, with a named column list. The screen read is an admin request and
     * not on StorefrontQueryBudgetTest's path, but `*` here would load `caption`,
     * `remote_id` and `seen_at` to draw nine squares.
     *
     * @return list<array<string, mixed>>
     */
    private function previewTiles(): array
    {
        $cap = (int) (InstagramSettings::SCHEMA['posts']['options']['max'] ?? 24);

        return InstagramPost::query()
            ->drawable()
            ->recent()
            ->limit($cap)
            ->get(['id', 'media_type', 'caption', 'local_path', 'like_count', 'comments_count'])
            ->map(fn (InstagramPost $p) => [
                // Our own file under the web root, through the same checker the
                // storefront uses — null for a row whose stored path does not
                // resolve, which the next line drops.
                'image' => IgPath::stored($p->local_path),
                'video' => $p->isVideo(),
                'carousel' => $p->media_type === 'CAROUSEL_ALBUM',
                'caption' => Str::limit((string) ($p->caption ?? ''), 160, ''),
                // NULL STAYS NULL, the same three-valued rule the shop draws by: a
                // post Instagram gave us no number for draws no number, and a post
                // with a real zero draws 0.
                'likes' => $p->like_count === null ? null : (int) $p->like_count,
                'comments' => $p->comments_count === null ? null : (int) $p->comments_count,
            ])
            // The scope can only say the column is not null; a file that has since
            // gone is dropped here, exactly as InstagramFeed::build() drops it.
            ->filter(fn (array $tile) => $tile['image'] !== null)
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------- writing */

    /** The look and what a tile shows — the generic schema save. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(InstagramSettings::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $result = $this->settings->save($data['settings']);

        return response()->json([
            'ok' => true,
            'written' => $result['written'],
            // Reported rather than dropped in silence — the fault ModuleSchema
            // exists to remove.
            'rejected' => $result['rejected'],
        ]);
    }

    /**
     * Step 2 of the wizard: the app id and the app secret.
     *
     * ── VALIDATED HERE, BEFORE ANYTHING LEAVES THIS SERVER ──────────────────
     *
     * A malformed app id produces an authorisation URL Meta answers with an error
     * page the owner cannot read, so it is refused here with a sentence he can. The
     * patterns are deliberately a little wider than today's formats:
     *
     *   app id  digits only. Meta app ids are numeric and this one goes into a URL
     *           query string, so digits is both the true format and the strongest
     *           possible guarantee about what can reach that URL.
     *   secret  32 hex characters is what Meta issues. ACCEPTED AS 16–64
     *           alphanumeric, which is wider on purpose: the security property
     *           being bought is "no quote, no space, no control character, no
     *           newline", and pinning the exact length would refuse a perfectly
     *           good secret the day Meta changes the format — turning this box into
     *           a wall with no way round it. A wrong secret already fails honestly,
     *           at Meta, with a message the screen prints.
     *
     * AN EMPTY SECRET BOX MEANS "LEAVE THE STORED ONE ALONE", which is
     * InstagramCredentials::saveApp()'s documented third state and the reason the
     * box is drawn empty with a placeholder rather than holding asterisks.
     */
    public function saveApp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:64'],
            'app_secret' => ['nullable', 'string', 'max:200'],
        ]);

        $appId = trim((string) $data['app_id']);
        $secret = trim((string) ($data['app_secret'] ?? ''));

        if (preg_match('/^[0-9]{6,32}$/', $appId) !== 1) {
            return response()->json([
                'ok' => false,
                'error' => 'That does not look like an Instagram app ID. It is a number, usually 15 or 16 '
                    .'digits, on the “API setup with Instagram login” panel in your Meta app.',
            ], 422);
        }

        if ($secret !== '' && preg_match('/^[A-Za-z0-9]{16,64}$/', $secret) !== 1) {
            return response()->json([
                'ok' => false,
                'error' => 'That does not look like an Instagram app secret. It is 32 letters and digits with '
                    .'no spaces — copy it with the Show button on that same panel.',
            ], 422);
        }

        if ($secret === '' && ! InstagramCredentials::hasSecret()) {
            return response()->json([
                'ok' => false,
                'error' => 'The app secret is needed the first time. Leave the box empty only when you are '
                    .'changing the app ID and want to keep the secret you already saved.',
            ], 422);
        }

        InstagramCredentials::saveApp($appId, $secret === '' ? null : $secret);

        return response()->json(['ok' => true, 'connection' => $this->connection()]);
    }

    /**
     * Step 3: mint a state and send the owner to Instagram.
     *
     * A REDIRECT AND NOT JSON, because the browser has to arrive at Instagram's own
     * authorisation screen as a top-level navigation — an XHR cannot log somebody in
     * to a third party. The screen therefore opens this as a plain link, which also
     * means an owner with JavaScript trouble can still finish the connection.
     *
     * A refusal comes BACK to the screen as a query string rather than as a bare
     * error page, because this endpoint is reached by navigation and there is nothing
     * on the other side to read a JSON body.
     */
    public function start(Request $request): RedirectResponse
    {
        $answer = InstagramAuth::authorizeUrl();

        if (! ($answer['ok'] ?? false)) {
            return redirect()->to($this->screenUrl($request, [
                'ig_error' => (string) ($answer['error'] ?? 'Instagram could not be reached.'),
            ]));
        }

        InstagramAuth::remember($request, (string) $answer['state']);

        return redirect()->away((string) $answer['url']);
    }

    /**
     * Step 3, the way back: verify, exchange, store, fetch, and land on the screen.
     *
     * ── THE STATE IS SPENT FIRST, BEFORE `code` IS EVEN READ ────────────────
     *
     * InstagramAuth::consume() calls `pull()`, so the state is consumed by being
     * looked at and every refusal below has already spent it. That is what stops a
     * replayed callback getting a second code exchange out of one state, and it is
     * why this call is the FIRST thing in the method rather than the first thing
     * after the happy-path checks.
     *
     * ── AND META'S OWN REFUSAL IS READ BEFORE THE STATE IS TRUSTED ──────────
     *
     * A denied authorisation arrives as `error=access_denied` with no `code`. It is
     * reported as itself — "you pressed Cancel" is not "something went wrong" — but
     * only AFTER consume() has spent the state, so a cancelled attempt cannot leave
     * a live state behind in the session.
     */
    public function callback(Request $request): RedirectResponse
    {
        $check = InstagramAuth::consume($request, (string) $request->query('state', ''));

        if (! ($check['ok'] ?? false)) {
            return redirect()->to($this->screenUrl($request, ['ig_error' => (string) $check['error']]));
        }

        if ($request->query('error') !== null || $request->query('error_reason') !== null) {
            /*
             * Meta's own words are NOT printed back into the page. `error_description`
             * is a remote string on a redirect this server does not control, and
             * echoing it into the admin — even escaped — is how a phishing sentence
             * gets to appear inside the shop's own console. The reason CODE is a
             * short token from Meta's fixed set, so it is matched against the one
             * case worth telling apart and otherwise reported generically.
             */
            $denied = $request->query('error') === 'access_denied'
                || $request->query('error_reason') === 'user_denied';

            return redirect()->to($this->screenUrl($request, [
                'ig_error' => $denied
                    ? 'You did not grant access on Instagram’s screen, so nothing was changed. Press '
                        .'Configure now again when you are ready.'
                    : 'Instagram refused the authorisation. Check that the redirect URI above is registered '
                        .'in your Meta app exactly as it is printed, then try again.',
            ]));
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect()->to($this->screenUrl($request, [
                'ig_error' => 'Instagram sent no authorisation code back, so nothing was changed. Press '
                    .'Configure now again.',
            ]));
        }

        $done = $this->sync->connect($code);

        return redirect()->to($this->screenUrl($request, $done['ok']
            ? ['ig_done' => (string) $done['message']]
            : ['ig_error' => (string) $done['error'], 'ig_detail' => (string) ($done['detail'] ?? '')]));
    }

    /**
     * Fetch the profile and the recent media again, now.
     *
     * The one button an owner presses regularly, and the one place the token is
     * opportunistically refreshed — InstagramSync::run() does that first. There is
     * no cron and no queue worker on this host (docs/UGC-ENGAGEMENT.md), so this
     * button and the screen's own load are the whole refresh schedule; §5 of
     * docs/IG-PROFILE.md says so and the screen prints the expiry date.
     */
    public function refresh(): JsonResponse
    {
        $result = $this->sync->run();

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'Instagram could not be reached.'),
                // Meta's own sentence, kept SEPARATE from ours so the screen can
                // print ours and offer his. Already scrubbed of the secret and the
                // token by InstagramClient::scrub().
                'detail' => (string) ($result['detail'] ?? ''),
                'reason' => (string) ($result['reason'] ?? 'refused'),
                'connection' => $this->connection(),
                'profile' => $this->publicProfile(),
                'content' => $this->content(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'stored' => (int) ($result['stored'] ?? 0),
            'pictures' => (int) ($result['pictures'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'pruned' => (int) ($result['pruned'] ?? 0),
            'connection' => $this->connection(),
            'profile' => $this->publicProfile(),
            'content' => $this->content(),
        ]);
    }

    /**
     * Disconnect: forget the token, keep the app registration.
     *
     * ── AND THE POSTS ARE KEPT ──────────────────────────────────────────────
     *
     * Disconnecting stops this shop READING the account. It is not "delete
     * everything", and conflating the two is how a button meant to revoke access
     * empties a homepage section as a side effect. The rows and their local pictures
     * stay, so the section goes on rendering what it rendered yesterday until the
     * owner either reconnects or clears the posts — which is its own button, with its
     * own sentence, so that destroying content is always something he asked for.
     *
     * `?posts=1` is that second button. It is a separate flag and not a separate
     * endpoint so the capability map has one rule to cover both.
     */
    public function disconnect(Request $request): JsonResponse
    {
        InstagramCredentials::forgetToken();

        $removed = 0;

        if ($request->boolean('posts')) {
            $removed = $this->sync->forgetEverything();
            $this->settings->forgetProfile();
        }

        InstagramFeed::flush();

        return response()->json([
            'ok' => true,
            'removed' => $removed,
            'connection' => $this->connection(),
            'profile' => $this->publicProfile(),
            'content' => $this->content(),
        ]);
    }

    /* ------------------------------------------------------------------ plumbing */

    /**
     * Back to Content → Instagram, with one sentence in the query string.
     *
     * ── BUILT FROM THE REQUEST'S OWN PATH, NEVER FROM A PARAMETER ───────────
     *
     * The admin path is a setting the owner can change (`admin_path`, treated as
     * semi-secret and not on SettingController::PUBLIC_KEYS), so the screen's URL
     * cannot be a constant — but it must not be taken from the request either,
     * because a redirect target that arrives in a query string is an open redirect
     * with extra steps.
     *
     * So it is derived: the callback's own path is `<admin>/admin-api/instagram/
     * callback`, and the screen is `<admin>#instagram`. Cutting the known suffix off
     * the path this request actually arrived at gives the admin root without reading
     * a setting and without trusting an input — and if the suffix is not there, which
     * cannot happen for a request that reached this method, the fall-back is the
     * site root rather than anything an attacker chose.
     *
     * The fragment is `#instagram`, which is the console's own routing: `window.go`
     * reads it, so the owner lands on this screen with the banner showing rather than
     * on the dashboard having to find his way back.
     */
    private function screenUrl(Request $request, array $params): string
    {
        $path = '/'.ltrim($request->path(), '/');
        $suffix = '/admin-api/instagram/callback';

        if (str_ends_with($path, $suffix)) {
            $root = substr($path, 0, -strlen($suffix));
        } else {
            $suffix = '/admin-api/instagram/start';
            $root = str_ends_with($path, $suffix) ? substr($path, 0, -strlen($suffix)) : '';
        }

        $query = http_build_query(array_filter($params, fn ($v) => (string) $v !== ''));

        return url(($root === '' ? '/' : $root).($query === '' ? '' : '?'.$query)).'#instagram';
    }
}
