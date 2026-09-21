<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CacheHeaders;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\CacheSettings;
use App\Support\Url;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Platform → Cache.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE OWNER ASKED FOR, AND WHAT HE ACTUALLY NEEDED
 * ---------------------------------------------------------------------------
 *
 * He asked for "a full cache settings ... give full control of caching etc."
 * after opening the shop in an incognito window and being shown a DigitalOcean
 * default page. That page was a vhost matter on the host and is being handled
 * elsewhere -- it was never caching. But it is why he asked, and it says what
 * the screen has to do first: he could not tell, from anywhere in his own admin
 * panel, what his shop was doing. A screen of switches would have answered a
 * question he did not ask.
 *
 * So `show()` is mostly a READING, and the expensive part of it is deliberate:
 * it fetches a storefront page through this application's own kernel and
 * reports the Cache-Control that came back. Not the policy, not the constant,
 * not what the settings imply -- the header, off a real response, now. The rest
 * of the payload says which of the compiled caches exist on disk, whether the
 * middleware is in the `web` group at all, and whether it is switched on; those
 * are three different ways for caching to be "not working" and they look
 * identical from outside.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS COSTS, AND WHY THE ROUTES ARE THROTTLED
 * ---------------------------------------------------------------------------
 *
 * One `show()` renders ONE storefront page inside the worker serving the admin
 * request. HealthApiController measured eight pages at 276ms cold / 69ms warm
 * on a 24-product SQLite catalogue and carries `throttle:6,1` for it; this is
 * an eighth of that work and the screen loads it once on open, never on a
 * timer. routes/cache-admin.php throttles it anyway, because the cost is real
 * and a held F5 on a host with a handful of PHP workers is the pool rendering
 * the shop at itself.
 *
 * ---------------------------------------------------------------------------
 * WHAT A SUB-REQUEST DISTURBS, AND WHAT IS PUT BACK
 * ---------------------------------------------------------------------------
 *
 * The same two things HealthApiController documents, and put back the same way,
 * because they are properties of Kernel::handle() and not of that controller.
 * `sendRequestThroughRouter()` opens with `$this->app->instance('request', ...)`
 * and clears the resolved `request` facade, and nothing restores them -- left
 * alone, `request()` inside the rest of THIS admin request answers '/'. And the
 * cookies are copied, so the probe resolves the same session, and StartSession
 * stores the URL it just served as that session's previous URL -- left alone,
 * opening this screen makes `back()` in the admin mean "the shop homepage".
 *
 * ---------------------------------------------------------------------------
 * WHAT IS RETURNED, AND WHAT IS NOT
 * ---------------------------------------------------------------------------
 *
 * Every field below is written out by hand. Nothing here serialises a model, a
 * config array or an environment value: `config('cache.default')` is read for
 * the STORE NAME and the store's `driver`, and nothing else from that array --
 * a store's host, port, database index or password is a credential and the
 * screen has no use for one. CacheControlScreenTest asserts the response
 * against an explicit key list and greps the body for the app key and the
 * database password, because an allowlist that is not pinned is a comment.
 *
 * These routes are mounted inside the `admin-api` group in routes/web.php --
 * `web`, `auth:admin` and NoStoreAdminApi -- and are mapped to `cache.manage`
 * in AdminCapabilities. Nothing here may move to routes/api.php: CLAUDE.md
 * records that everything under /api/* is unauthenticated by design, and
 * `clear` drops this shop's compiled routes on demand.
 */
final class CacheApiController extends Controller
{
    /** The compiled artefacts, as [label => absolute path or glob]. */
    private const COMPILED = [
        'config' => 'bootstrap/cache/config.php',
        'routes' => 'bootstrap/cache/routes-*.php',
        'services' => 'bootstrap/cache/services.php',
        'packages' => 'bootstrap/cache/packages.php',
        'events' => 'bootstrap/cache/events.php',
    ];

    public function __construct(private SettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        $values = CacheSettings::all($this->settings);
        $htmlMaxAge = (int) $values['html_max_age'];
        $assetMaxAge = (int) $values['asset_max_age'];
        $registered = $this->middlewareRegistered();
        $enabled = (bool) $values['headers_enabled'];

        return response()->json([
            'ok' => true,

            // Keyed by CacheSettings::FIELDS and not by the storage keys. The
            // long note on that constant says why, and it is not cosmetic: a
            // dot in a field name is a PATH to Laravel's validator, and the
            // first version of save() below validated nothing at all because
            // of it.
            'settings' => $values,

            'limits' => [
                'html_max_age_ceiling' => CacheSettings::HTML_MAX_AGE_CEILING,
                'asset_max_age_ceiling' => CacheSettings::ASSET_MAX_AGE_CEILING,
            ],

            /*
             * THREE STATES, NOT ONE BOOLEAN. A middleware that is not in the
             * group, a middleware that is in the group and switched off, and a
             * middleware doing its job are three different situations that
             * produce the same header, and only the first is a fault. `active`
             * is the conjunction, spelled out so the screen does not have to
             * invent the same `&&` a second time.
             */
            'middleware' => [
                'registered' => $registered,
                'enabled' => $enabled,
                'active' => $registered && $enabled,
            ],

            'live' => $this->probe($request, '/'),

            /*
             * Not a control, and drawn as a statement. See the head of
             * App\Support\CacheSettings: a shop that lets a shared cache keep a
             * signed-in shopper's cart page hands one customer's basket to the
             * next, so there is no key for this and no way to ask for one.
             */
            'private_pages' => [
                'prefixes' => array_values(CacheHeaders::PRIVATE_PREFIXES),
                'cache_control' => CacheHeaders::NO_STORE,
                'enforced' => $registered && $enabled,
            ],

            'storefront_policy' => CacheSettings::storefrontHeader($htmlMaxAge),

            'compiled' => $this->compiled(),

            'store' => $this->store(),

            'assets' => [
                'cache_control' => CacheSettings::assetHeader($assetMaxAge),
                'htaccess' => CacheSettings::htaccess($assetMaxAge),
                /*
                 * NOT the web root itself. docs/IMAGE-PIPELINE-AND-CACHE.md
                 * §9.4: the file at the web root is the one that routes the
                 * whole site, and replacing it is the 2.60.102-.106 failure
                 * with a different file.
                 */
                'directories' => ['build/', 'img-cache/'],
                'sample_url' => $this->sampleAssetUrl(),
            ],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            /*
             * `sometimes` throughout: the screen posts the whole form, but a
             * partial save is a legal request and must not reset what it did
             * not mention.
             *
             * AND THE RULES REFUSE AN OUT-OF-RANGE NUMBER RATHER THAN CLAMPING
             * IT. normalise() clamps as well, because it has to -- it reads
             * whatever is in a longText column -- but a screen that answers 200
             * to a max-age of 99999 and then stores 3600 has told the owner his
             * setting was accepted. He would go looking for the effect of a
             * number the shop never agreed to.
             */
            'headers_enabled' => ['sometimes', 'boolean'],
            'html_max_age' => ['sometimes', 'integer', 'min:0', 'max:' . CacheSettings::HTML_MAX_AGE_CEILING],
            'asset_max_age' => ['sometimes', 'integer', 'min:0', 'max:' . CacheSettings::ASSET_MAX_AGE_CEILING],
        ]);

        foreach ($data as $field => $value) {
            $key = CacheSettings::FIELDS[$field];

            // Through the SAME normalise() the middleware reads with, so a
            // value that survives this method is the value the storefront will
            // honour. A screen that can store something its reader clamps is a
            // screen that reads back its own value and looks fine.
            $clean = CacheSettings::normalise($key, $value);

            $this->settings->set($key, is_bool($clean) ? ($clean ? '1' : '0') : (string) $clean);
        }

        /*
         * NO MEMO CLEAR HERE, AND THAT IS A MEASUREMENT RATHER THAN AN
         * OVERSIGHT.
         *
         * The obvious worry is real: Setting::map() memoises in a process-level
         * static as well as in the cache store and SettingsService keeps a
         * snapshot of its own, so a save followed by a read in the same process
         * can answer with the value from before the save -- and show() below
         * RE-PROBES the shop, so getting this wrong would mean the owner
         * switches caching on and is shown the old header as proof it did not
         * work.
         *
         * SettingsService::set() already does it. It forgets the cached
         * autoload map, drops the key from its own memo and calls
         * Setting::flushMap(), which is the whole set. A second clear here was
         * written first and then DELETED after running the mutation: removing
         * it left every case in CacheControlScreenTest green, which means it
         * was protecting nothing and would have read to the next person as if
         * set() could not be trusted to do its own job.
         *
         * The OUTCOME is pinned either way -- "it reads the new header back
         * after the switch it just saved" asserts the re-probe rather than the
         * clearing -- so if set() ever stops flushing, that case goes red here
         * rather than on a shop.
         */
        return $this->show($request);
    }

    /**
     * The buttons. There is no shell on a customer's host, so this is the only
     * way any of these caches can be dropped.
     */
    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'in:compiled,application,all'],
        ]);

        $target = (string) $data['target'];
        $ran = [];

        if ($target === 'compiled' || $target === 'all') {
            /*
             * EXACTLY THE COMMANDS THE UPDATER ALREADY RUNS on every package
             * application (UpdateRunner::clearCaches), which is what makes this
             * button a known quantity rather than a new risk on a live shop.
             *
             * `config:clear` deserves its own sentence, because it is the one
             * that could in principle be unrecoverable: with the config cache
             * gone, Laravel goes back to reading .env, and a server without one
             * would have no database credentials and no way to be given any.
             * It is safe HERE because the updater has been doing it on this
             * host for the life of the project -- a .env that was not there
             * would have taken the shop down at the first update, not at this
             * button.
             *
             * NOT `route:cache` or `config:cache` afterwards. UpdateRunner
             * carries the record of why: re-caching routes after an update was
             * a self-benchmarked zero-benefit optimisation that answered the
             * live homepage with a 405 and was never reproduced anywhere else.
             * Clearing is the whole operation.
             */
            foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
                $ran[$command] = $this->artisan($command);
            }

            /*
             * And then the files, by hand. Not belt-and-braces for its own
             * sake: `route:clear` deletes bootstrap/cache/routes-*.php through
             * the filesystem and returns 0 whether or not it managed to, and a
             * compiled route table that outlives the command is precisely the
             * failure every clear_caches_* migration in database/migrations
             * exists to prevent -- the package lands complete and the new
             * endpoint still 404s. The migrations unlink the same list.
             */
            $ran['files_removed'] = $this->unlinkCompiled();

            // The half that matters for new PHP on a host with no shell: the
            // file a package writes is not the file the server runs until
            // OPcache lets go of the old one.
            $ran['opcache_reset'] = function_exists('opcache_reset') ? (bool) @opcache_reset() : false;
        }

        if ($target === 'application' || $target === 'all') {
            $ran['cache:clear'] = $this->artisan('cache:clear');

            // cache:clear drops `kbb.settings.map` from the store; the two
            // process-level memos in front of it survive and would go on
            // answering with what they read before the button was pressed.
            $this->forgetSettingMemos();
        }

        return response()->json([
            'ok' => true,
            'target' => $target,
            'ran' => $ran,
            'compiled' => $this->compiled(),
        ]);
    }

    /**
     * Is the web server already applying the asset policy?
     *
     * The .htaccess half cannot be answered from inside PHP by reasoning: on
     * this host a request for /build/assets/kbb-NawQuIF5.css never reaches this
     * application at all. The only honest answer is to ask for one over HTTP
     * and read what comes back, which is what SiteAddressApiController::check
     * does for the address and for the same reason.
     */
    public function probeAssets(): JsonResponse
    {
        $url = $this->sampleAssetUrl();

        if ($url === null) {
            return response()->json([
                'ok' => false,
                'reason' => 'This install has no built assets to test — public/build/manifest.json is missing or empty, so there is nothing under /build/ to ask for.',
            ]);
        }

        try {
            $response = Http::timeout(8)->get($url);
        } catch (Throwable) {
            /*
             * The message is not echoed back, for the reason the site-address
             * check gives: a Guzzle connection error carries the resolved IP
             * and the local certificate path, which is server detail that does
             * not belong on a browser screen.
             */
            return response()->json([
                'ok' => false,
                'reason' => 'Nothing answered at that address from the server itself. That usually means the shop cannot reach its own public URL, not that caching is wrong.',
            ]);
        }

        $header = (string) $response->header('Cache-Control');
        $assetMaxAge = (int) CacheSettings::normalise(
            CacheSettings::ASSET_MAX_AGE,
            $this->settings->get(CacheSettings::ASSET_MAX_AGE, CacheSettings::ASSET_MAX_AGE_CEILING)
        );

        return response()->json([
            'ok' => true,
            'url' => $url,
            'status' => $response->status(),
            'cache_control' => $header,
            'applied' => $this->directives($header) === $this->directives(CacheSettings::assetHeader($assetMaxAge)),
        ]);
    }

    /* ------------------------------------------------------------ readings */

    /**
     * Fetch a page through this application's own kernel and report its header.
     *
     * @return array{path: string, status: int|null, cache_control: string|null, matches_policy: bool, error: string|null}
     */
    private function probe(Request $original, string $path): array
    {
        $captured = app()->bound('request') ? app('request') : $original;
        $session = $original->hasSession() ? $original->session() : null;
        $previousUrl = $session?->previousUrl();

        try {
            $sub = Request::create(Url::to($path), 'GET');
            $sub->headers->replace($original->headers->all());
            $sub->cookies->replace($original->cookies->all());

            /*
             * AND THEN ASK LIKE A BROWSER. The admin request that got here came
             * from fetch(), so its headers say `Accept: application/json` and
             * `X-Requested-With: XMLHttpRequest`, and copying the lot -- which
             * is what HealthApiController does, rightly, because it wants the
             * page a visitor gets with a visitor's cookies -- would have this
             * probe asking the shop for JSON.
             *
             * It matters HERE and not there, because the one thing this method
             * reads is a header that CacheHeaders only sets on a text/html
             * response. A probe that talked the shop into answering JSON would
             * report "your caching is not working" about a page that is fine.
             * The cookies are still the admin's, so it is still the same
             * session and the same settings.
             */
            $sub->headers->set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');
            $sub->headers->remove('X-Requested-With');
            $sub->headers->remove('Content-Type');

            $response = app()->handle($sub);

            $header = $response->headers->get('Cache-Control');
            $header = $header === null ? null : (string) $header;

            return [
                'path' => $path,
                'status' => $response->getStatusCode(),
                'cache_control' => $header,
                'matches_policy' => $header !== null && $this->directives($header) === $this->directives(
                    CacheSettings::storefrontHeader((int) CacheSettings::normalise(
                        CacheSettings::HTML_MAX_AGE,
                        $this->settings->get(CacheSettings::HTML_MAX_AGE, 0)
                    ))
                ),
                'error' => null,
            ];
        } catch (Throwable $e) {
            /*
             * Kernel::handle() catches what it dispatches, so reaching here
             * takes a failure in the kernel itself. The class name and not the
             * message: an exception message from a page render can carry a
             * query, a table name or a file path, and this screen is not the
             * error log (which is owner-only under system.diagnostics).
             */
            return [
                'path' => $path,
                'status' => null,
                'cache_control' => null,
                'matches_policy' => false,
                'error' => 'The shop could not be fetched from inside itself (' . class_basename($e) . ').',
            ];
        } finally {
            app()->instance('request', $captured);
            Facade::clearResolvedInstance('request');

            // setPreviousUrl(null) removes the key rather than leaving it
            // alone, so only write back something that was there.
            if ($session !== null && $previousUrl !== null) {
                $session->setPreviousUrl($previousUrl);
            }
        }
    }

    /** @return array<string, bool|int> */
    private function compiled(): array
    {
        $out = [];

        foreach (self::COMPILED as $label => $pattern) {
            $out[$label] = (glob(base_path($pattern)) ?: []) !== [];
        }

        // Views are many files rather than one, so the count is the answer the
        // owner can act on: "142 compiled views" and "none" mean different
        // things and a boolean flattens them.
        $out['views'] = count(glob(storage_path('framework/views/*.php')) ?: []);

        return $out;
    }

    /**
     * The cache store's name and driver, and nothing else from that config.
     *
     * A store's host, port, database index, username and password are all in
     * the same array and none of them is any business of a browser screen.
     */
    private function store(): array
    {
        $name = (string) config('cache.default', 'file');

        return [
            'store' => $name,
            'driver' => (string) config('cache.stores.' . $name . '.driver', $name),
        ];
    }

    private function middlewareRegistered(): bool
    {
        $kernel = app(Kernel::class);

        if (! method_exists($kernel, 'getMiddlewareGroups')) {
            return false;
        }

        return in_array(CacheHeaders::class, $kernel->getMiddlewareGroups()['web'] ?? [], true);
    }

    /** The first built file the manifest names, as an absolute URL. */
    private function sampleAssetUrl(): ?string
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return null;
        }

        $entries = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            $file = is_array($entry) ? (string) ($entry['file'] ?? '') : '';

            if ($file !== '') {
                return Url::to('/build/' . ltrim($file, '/'));
            }
        }

        return null;
    }

    /* ------------------------------------------------------------- actions */

    private function artisan(string $command): bool
    {
        try {
            Artisan::call($command);

            return true;
        } catch (Throwable) {
            // A cache that will not clear must not 500 the screen that asked.
            // It is reported as false and the owner is told which one.
            return false;
        }
    }

    private function unlinkCompiled(): int
    {
        $removed = 0;

        foreach (self::COMPILED as $pattern) {
            foreach (glob(base_path($pattern)) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function forgetSettingMemos(): void
    {
        Setting::flushMap();
        SettingsService::forgetMemo();
        $this->settings->flush();
    }

    /**
     * A Cache-Control as the SET OF DIRECTIVES it is.
     *
     * Symfony rewrites the header on the way out -- `private, no-cache,
     * max-age=0, must-revalidate` leaves as `max-age=0, must-revalidate,
     * no-cache, private` -- so comparing the literal would be comparing
     * Symfony's sort order. CacheHeaderPolicyTest says the same thing about
     * the same header and for the same reason.
     *
     * @return list<string>
     */
    private function directives(string $header): array
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', strtolower($header))),
            static fn ($p) => $p !== ''
        ));

        sort($parts);

        return $parts;
    }
}
