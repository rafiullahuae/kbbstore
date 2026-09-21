<?php

declare(strict_types=1);

/**
 * KBB first-run installer — standalone, then Laravel.
 *
 * Upload the files, open this page, answer five screens, watch the bar.
 *
 * =============================================================================
 * WHY THIS FILE DOES NOT BOOT LARAVEL AT THE TOP
 * =============================================================================
 *
 * On a fresh upload there is no `.env`, so there is no APP_KEY, so the session
 * and encrypter throw the moment anything touches them — and there may be no
 * `vendor/` either, in which case there is no framework at all. An installer
 * that needs the application in order to install the application is no
 * installer.
 *
 * So the first three steps are plain PHP with no dependencies, exactly like
 * kbb-recover.php. Laravel is booted only from step 5, once `.env` exists and
 * `vendor/` has been checked — and by then booting it is the point, because the
 * migrations are its own.
 *
 * =============================================================================
 * THE SECURITY MODEL, WHICH IS THE WHOLE RISK
 * =============================================================================
 *
 * Between "files uploaded" and "installed" this file can write `.env` and
 * create an owner account. That is total control of the site. The domain is
 * live and reachable by anyone the moment DNS resolves, and whoever completes
 * the installer owns the shop — the installer, not the person who uploaded the
 * files. WordPress has shipped that window for twenty years and it is regularly
 * exploited.
 *
 * Four things close it:
 *
 *   1. IT REFUSES ONCE INSTALLED. A marker file, an `.env` with a real APP_KEY,
 *      and an existing owner account are each enough to make every request here
 *      a flat 404. Three independent signals, because any one of them could be
 *      missing for an innocent reason and none of them can be present for one.
 *
 *   2. IT DEMANDS PROOF OF FILESYSTEM ACCESS BEFORE IT WILL DO ANYTHING.
 *      On the first visit it writes a random 32-byte token to
 *      `storage/INSTALL-TOKEN.txt` in the application folder and asks for it
 *      back. Reading that file requires the hosting panel, which requires the
 *      hosting password. A visitor who merely found the URL cannot proceed, and
 *      the answer is the same whether they guess wrong or the token does not
 *      exist yet.
 *
 *   3. EVERY COMPARISON IS hash_equals AND EVERY FAILURE IS RATE LIMITED.
 *      Ten wrong tokens and this file stops answering for fifteen minutes,
 *      recorded on disk rather than in a session, because there is no session.
 *
 *   4. IT TAKES ITSELF OUT. On success it writes the marker, then unlinks
 *      itself. If the unlink fails — read-only file, odd permissions — the final
 *      screen says so in red and the refusal in (1) still holds.
 *
 * It also sends `X-Robots-Tag: noindex` on every response and never echoes back
 * a password, a token, or a database credential.
 *
 * =============================================================================
 * WHY THE WORK IS BATCHED
 * =============================================================================
 *
 * There are 355 migrations. Shared hosting kills a request at 30 seconds and
 * `max_execution_time` cannot be discovered reliably from inside PHP, so
 * "install everything in one POST" is a coin toss that gets worse as the schema
 * grows — and a migration run killed halfway is the worst possible outcome,
 * because the next attempt starts from a half-built database.
 *
 * Instead each POST runs work until a TIME BUDGET is spent, then returns what it
 * finished and what is left. The browser calls back immediately. A fast host
 * does forty migrations a request and a slow one does three; both finish, and
 * both show a real bar rather than a spinner that means nothing. This is the
 * same shape as App\Services\ImportConsole\ImportDriver, for the same reason.
 *
 * Every step is also IDEMPOTENT: the migrator skips what it has already run, the
 * seeders are re-runnable, and the admin account is created only if the email is
 * not already present. Reloading mid-install costs nothing.
 */

/* ────────────────────────────────────────────────────────── boot, no framework */

@set_time_limit(0);
@ini_set('memory_limit', '512M');
@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

const KBB_TIME_BUDGET = 8.0;      // seconds of work per POST
const KBB_MAX_ATTEMPTS = 10;      // wrong tokens before the door shuts
const KBB_LOCKOUT = 900;          // seconds

/**
 * Find the application folder.
 *
 * The same candidate list index.php uses, minus its `vendor/autoload.php`
 * requirement: the whole point here is to be useful when vendor/ is what is
 * missing, so this matches on bootstrap/app.php alone.
 */
function kbb_app_base(): ?string
{
    foreach ([
        __DIR__.'/..',
        __DIR__.'/../../kbb-upgrade-app',
        __DIR__.'/../kbb-upgrade-app',
        __DIR__.'/../../../kbb-upgrade-app',
        __DIR__.'/../kbb-app',
        __DIR__.'/../../kbb-app',
        /*
         * Cloudways, and any host that gives you a `private_html` beside the web
         * root. It is the ONLY writable folder there that is not served -- the
         * application directory itself is owned by root, so the app physically
         * cannot live beside public_html the way it does on cPanel. Found by
         * installing on one: git refused with "could not create work tree dir:
         * Permission denied", and `private_html` was the answer sitting next to it.
         *
         * Both spellings, because the repo can be cloned into private_html itself
         * or into a kbb-app folder inside it.
         */
        __DIR__.'/../private_html',
        __DIR__.'/../private_html/kbb-app',
        __DIR__.'/../laravel-app',
        __DIR__.'/../../laravel-app',
    ] as $candidate) {
        if (is_file($candidate.'/bootstrap/app.php') && is_file($candidate.'/composer.json')) {
            return realpath($candidate) ?: $candidate;
        }
    }

    return null;
}

$BASE = kbb_app_base();

if ($BASE === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "KBB installer: could not find the application folder.\n\n";
    echo "This file must sit in your web root, with the application folder\n";
    echo "beside it or one level up. Looked from:\n  ".__DIR__."\n";
    exit;
}

$STORAGE = $BASE.'/storage';
$ENV_FILE = $BASE.'/.env';
$TOKEN_FILE = $STORAGE.'/INSTALL-TOKEN.txt';
$STATE_FILE = $STORAGE.'/install-state.json';
$THROTTLE_FILE = $STORAGE.'/install-throttle.json';
$MARKER = $STORAGE.'/.kbb-installed';

@mkdir($STORAGE, 0775, true);

/* ──────────────────────────────────────────────────── is it already installed? */

/**
 * Three independent signals, and any one of them closes this file.
 *
 * The marker alone is not enough: a restored backup can carry a database full
 * of orders and no marker, and letting the installer run against that would
 * offer a stranger the chance to add themselves as an owner.
 */
function kbb_installed_reason(string $marker, string $envFile, string $stateFile): ?string
{
    /*
     * AN INSTALL IN FLIGHT IS NOT AN INSTALLED SHOP, and forgetting that is a
     * bug that closes the door with the installer still inside the room.
     *
     * `begin` writes .env -- it has to, because nothing can migrate without one
     * -- and .env carrying a real APP_KEY is one of the signals below. So the
     * very next POST, the first `step`, would 404 and the install would die
     * between writing the config and creating a single table. There is no error
     * to show, because the file that would show it has stopped answering.
     *
     * The state file is the proof that this is the same run: it can only exist
     * if somebody already passed the token gate, so honouring it grants nothing
     * an attacker did not already have. It is ignored once STALE, so an install
     * abandoned halfway cannot hold the door open indefinitely.
     */
    if (is_file($stateFile) && (time() - (int) @filemtime($stateFile)) < 7200) {
        return null;
    }

    if (is_file($marker)) {
        return 'marker';
    }

    if (is_file($envFile)) {
        $env = (string) @file_get_contents($envFile);

        if (preg_match('/^APP_KEY\s*=\s*base64:[A-Za-z0-9+\/=]{20,}/m', $env)) {
            return 'env';
        }
    }

    return null;
}

if (($reason = kbb_installed_reason($MARKER, $ENV_FILE, $STATE_FILE)) !== null) {
    /*
     * A flat 404, not an explanation. "This shop is already installed" tells a
     * stranger the software, the state and that the path is real. The owner who
     * genuinely needs to re-run this knows to delete the marker; nobody else
     * needs to know anything.
     */
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}

/* ───────────────────────────────────────────────────────────────── throttling */

function kbb_throttle_read(string $file): array
{
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($data) ? $data + ['fails' => 0, 'until' => 0] : ['fails' => 0, 'until' => 0];
}

function kbb_throttle_locked(string $file): int
{
    $t = kbb_throttle_read($file);

    return max(0, ((int) $t['until']) - time());
}

function kbb_throttle_fail(string $file): void
{
    $t = kbb_throttle_read($file);
    $t['fails'] = ((int) $t['fails']) + 1;

    if ($t['fails'] >= KBB_MAX_ATTEMPTS) {
        $t['until'] = time() + KBB_LOCKOUT;
        $t['fails'] = 0;
    }

    @file_put_contents($file, json_encode($t), LOCK_EX);
}

function kbb_throttle_clear(string $file): void
{
    @unlink($file);
}

/* ───────────────────────────────────────────────────────────────── the token */

/**
 * The token, created on first sight and never regenerated while it exists.
 *
 * Regenerating per request would make it unusable: the owner opens the file in
 * one tab and pastes into another, and a token that changed in between would
 * never match. It lives until the install completes, then goes with the rest.
 */
function kbb_token(string $file): string
{
    /*
     * THE FIRST LINE, not the whole file.
     *
     * This file deliberately carries a sentence of explanation after the key,
     * so that an owner who opens it in File Manager knows what they are looking
     * at. Reading it back with a bare trim() therefore compares the key PLUS
     * that sentence against a 64-character pattern, which never matches -- so
     * every request decided there was no valid token and minted a new one,
     * rewriting the file underneath the person who was mid-copy. The symptom is
     * the correct key being refused, every time, with no way to ever get in.
     *
     * Caught by driving the installer in a browser; no amount of reading it
     * would have shown it, because both halves look right on their own.
     */
    $raw = is_file($file) ? (string) @file_get_contents($file) : '';
    $existing = trim(strtok($raw, "\r\n") ?: '');

    if (preg_match('/^[a-f0-9]{64}$/', $existing)) {
        return $existing;
    }

    $token = bin2hex(random_bytes(32));

    @file_put_contents(
        $file,
        $token."\n\nThis is the setup key for your shop.\n"
        ."Paste it into the installer page, then this file is deleted automatically.\n",
        LOCK_EX
    );

    /*
     * 0640, NOT 0600 — and the difference is the whole installer.
     *
     * This file is written by the PHP process and READ BY A HUMAN over SSH or
     * in a file manager. Those are two different users on most hosting: PHP
     * runs as www-data (or nobody, or the pool user) and the owner logs in as
     * the account that owns the files. 0600 means "the creating user only", so
     * the owner ran the `cat` command this very page prints and got:
     *
     *     cat: .../storage/INSTALL-TOKEN.txt: Permission denied
     *
     * And that is a TOTAL lockout, not an inconvenience. The key gates screen
     * one of five, nothing past it runs, there is no recovery flow, and the
     * installer itself has just told him to read a file it made unreadable.
     * Found on Cloudways on a real install, at the point where every other
     * requirement had already passed.
     *
     * 0640 gives the group read, and the group is the one the web server and
     * the login account share on every host where this matters — it is how the
     * owner's own files already look there (`-rw-rw-r-- master www-data`).
     *
     * Not a loosening of anything real. `.env` twenty lines further down has
     * been 0640 since it was written, and it carries the database password and
     * the APP_KEY that every stored payment credential is encrypted with. A
     * single-use setup key, in a folder that is not web-served, being *stricter*
     * than that was an inconsistency, not a policy.
     *
     * The state file keeps 0600 deliberately: PHP writes it and PHP reads it,
     * and no human ever needs to open it.
     */
    @chmod($file, 0640);

    return $token;
}

function kbb_check_token(string $given, string $tokenFile, string $throttleFile): bool
{
    if (kbb_throttle_locked($throttleFile) > 0) {
        return false;
    }

    $expected = kbb_token($tokenFile);

    // hash_equals on both, and the same work whether the length matches or not.
    $ok = strlen($given) === strlen($expected) && hash_equals($expected, $given);

    if (! $ok) {
        usleep(250000);
        kbb_throttle_fail($throttleFile);
    }

    return $ok;
}

/* ───────────────────────────────────────────────────────────────────── state */

function kbb_state_read(string $file): array
{
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($data) ? $data : [];
}

function kbb_state_write(string $file, array $state): void
{
    @file_put_contents($file, json_encode($state), LOCK_EX);
    @chmod($file, 0600);
}

/* ──────────────────────────────────────────────────────────────── the checks */

function kbb_requirements(string $base): array
{
    $out = [];

    $out[] = [
        'label' => 'PHP 8.2 or newer',
        'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
        'detail' => 'You have '.PHP_VERSION,
        'fix' => 'Change the PHP version in your hosting panel.',
    ];

    foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'curl', 'fileinfo', 'zip'] as $ext) {
        $out[] = [
            'label' => 'PHP extension: '.$ext,
            'ok' => extension_loaded($ext),
            'detail' => extension_loaded($ext) ? 'present' : 'missing',
            'fix' => 'Enable '.$ext.' in your hosting panel’s PHP settings.',
        ];
    }

    /*
     * RECOMMENDED, NOT REQUIRED — and that distinction is the whole reason this
     * row exists rather than joining the loop above.
     *
     * ImageVariants::available() is `extension_loaded('gd') &&
     * function_exists('imagecreatetruecolor')`, and every caller of it already
     * degrades on its own: generate() returns `reason: 'no image library'`,
     * srcsetFor() falls back to the original file, and the Media Library screen
     * says so in as many words. Without gd the shop sells exactly as it does
     * with it — it just sends a 2MB photograph to a phone that needed a 187px
     * one. Slower pages, not a broken shop.
     *
     * So it must never block an install, and it does not: `ok` is true whatever
     * the answer, because `passed` is computed from that column. A host without
     * gd is a host that still sells, and refusing to install there would turn a
     * page-weight regression into "you cannot use this software" — the wrong
     * trade, and the one an over-eager requirements list makes by reflex.
     *
     * It is still SHOWN, because the failure it causes is invisible. Nothing
     * errors, nothing is logged, no page breaks; the copies are simply never
     * made and the owner is left wondering why the catalogue is heavy on
     * mobile. This is the one moment in the whole life of the shop when the
     * PHP settings are already open in another tab, so it is the one moment
     * worth spending on it.
     */
    $gd = extension_loaded('gd') && function_exists('imagecreatetruecolor');
    $out[] = [
        'label' => 'PHP extension: gd (recommended)',
        'ok' => true,
        'warn' => ! $gd,
        'detail' => $gd
            ? 'present — product photographs get phone-sized copies'
            : 'missing — the shop works, but every photograph is sent at full size',
        'fix' => 'Turn gd on in your hosting panel’s PHP settings and press Check again. '
            .'Nothing has to be reinstalled if you do it later: copies are made when an image '
            .'is uploaded, and Content → Media Library → “Make phone-sized copies” builds them '
            .'for photographs already in the shop.',
    ];

    foreach ([
        'storage' => $base.'/storage',
        'bootstrap/cache' => $base.'/bootstrap/cache',
        'the application folder' => $base,
    ] as $label => $path) {
        $out[] = [
            'label' => 'Writable: '.$label,
            'ok' => is_dir($path) && is_writable($path),
            'detail' => is_dir($path) ? (is_writable($path) ? 'writable' : 'not writable') : 'missing',
            'fix' => 'Set this folder to 755 in File Manager.',
        ];
    }

    $vendor = is_file($base.'/vendor/autoload.php');
    $out[] = [
        'label' => 'Dependencies installed (vendor/)',
        'ok' => $vendor,
        'detail' => $vendor ? 'present' : 'missing — build it before continuing',
        'fix' => 'vendor/ is built by a cron job. Upload composer.phar next to composer.json, '
            .'then add a cron that runs every minute: php '.$base.'/run-composer.php — '
            .'watch storage/logs/composer-run.log and delete the cron when it says DONE.',
    ];

    return $out;
}

/**
 * Connect, and say what is already in there.
 *
 * `existing` is the important half. A database that already carries this shop's
 * tables is a restore, not a fresh install, and the two want opposite things:
 * a restore must keep its APP_KEY (or every encrypted payment credential in it
 * becomes unreadable) and must not be seeded over.
 */
function kbb_db_probe(array $c): array
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['name']);

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => kbb_db_reason($e)];
    }

    $tables = [];
    try {
        foreach ($pdo->query('SHOW TABLES') as $row) {
            $tables[] = (string) array_values($row)[0];
        }
    } catch (Throwable $e) {
        // A user without SHOW privileges is unusual but survivable: treat it as
        // empty and let the migrator be the judge.
        $tables = [];
    }

    $hasShop = in_array('settings', $tables, true) && in_array('migrations', $tables, true);
    $orders = 0;

    if ($hasShop && in_array('orders', $tables, true)) {
        try {
            $orders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        } catch (Throwable $e) {
            $orders = 0;
        }
    }

    return [
        'ok' => true,
        'existing' => $hasShop,
        'tables' => count($tables),
        'orders' => $orders,
    ];
}

/** A cause the owner can act on, never the raw driver message. */
function kbb_db_reason(Throwable $e): string
{
    $m = $e->getMessage();

    if (str_contains($m, 'Unknown database')) {
        return 'That database does not exist. Create it in your hosting panel first, then try again.';
    }

    if (str_contains($m, 'Access denied')) {
        return 'The username or password was refused. Check them, and that the user is attached to this database.';
    }

    if (str_contains($m, 'getaddrinfo') || str_contains($m, 'Connection refused') || str_contains($m, 'timed out')) {
        return 'Could not reach the database server at that host. On most shared hosting it is localhost.';
    }

    return 'Could not connect. Check the host, database name, username and password.';
}

/* ───────────────────────────────────────────────────────────────── .env writing */

function kbb_env_escape(string $v): string
{
    return str_contains($v, ' ') || str_contains($v, '"') || str_contains($v, '#') || $v === ''
        ? '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"'
        : $v;
}

function kbb_write_env(string $file, array $s, string $publicPath): bool
{
    $lines = [
        'APP_NAME='.kbb_env_escape($s['shop_name']),
        'APP_ENV=production',
        'APP_KEY='.$s['app_key'],
        'APP_DEBUG=false',
        'APP_URL='.kbb_env_escape($s['app_url']),
        '',
        '# Empty unless the shop is served from a sub-folder.',
        'KBB_BASE_PATH=',
        '',
        'LOG_CHANNEL=stack',
        'LOG_LEVEL=error',
        '',
        'DB_CONNECTION=mysql',
        'DB_HOST='.kbb_env_escape($s['db']['host']),
        'DB_PORT='.(int) $s['db']['port'],
        'DB_DATABASE='.kbb_env_escape($s['db']['name']),
        'DB_USERNAME='.kbb_env_escape($s['db']['user']),
        'DB_PASSWORD='.kbb_env_escape($s['db']['pass']),
        '',
        'SESSION_DRIVER=file',
        'SESSION_LIFETIME=120',
        'CACHE_STORE=file',
        'QUEUE_CONNECTION=sync',
        '',
        'MAIL_MAILER=log',
        'MAIL_FROM_ADDRESS='.kbb_env_escape($s['admin_email']),
        'MAIL_FROM_NAME='.kbb_env_escape($s['shop_name']),
        '',
        '# Set this before you need it — it is the way back in if an update breaks the site.',
        'KBB_HEALTH_TOKEN='.bin2hex(random_bytes(16)),
        '',
    ];

    $ok = @file_put_contents($file, implode("\n", $lines)."\n", LOCK_EX) !== false;

    if ($ok) {
        @chmod($file, 0640);
    }

    /*
     * The public path, written as a tiny PHP file rather than into .env.
     *
     * bootstrap/app.php resolves it with getenv() while the Application is being
     * CONSTRUCTED, which is before LoadEnvironmentVariables has read .env — so a
     * KBB_BASE_PATH-style .env entry is read too late and is silently ignored.
     * Measured, not assumed: setting KBB_PUBLIC_PATH in .env leaves
     * publicPath() at the hard-coded fallback.
     */
    $phpPath = dirname($file).'/bootstrap/public-path.php';
    @file_put_contents(
        $phpPath,
        "<?php\n\n// Written by install.php. The folder this shop is served from.\n\nreturn ".var_export($publicPath, true).";\n",
        LOCK_EX
    );

    /*
     * And the same fact in the other direction, for index.php.
     *
     * The front controller finds the application by trying a list of likely
     * folder names. That list is a guess, and when the owner names the folder
     * something it has not heard of the guess fails silently and totally: the
     * install completes, reports success, and every page of the new shop is
     * "could not find the Laravel application". It happened here, in the test
     * for this very function, with the folder named `kbb-app` -- the name this
     * installer itself suggests.
     *
     * So the thing that KNOWS writes it down. index.php reads this first and
     * re-verifies it before using it.
     */
    @file_put_contents(
        $publicPath.'/kbb-app-path.php',
        "<?php\n\n// Written by install.php. Where the application folder is.\n\nreturn ".var_export(dirname($file), true).";\n",
        LOCK_EX
    );

    return $ok;
}

/* ──────────────────────────────────────────────────────── the work, batched */

/**
 * One POST's worth of installing. Returns progress; never throws past the caller.
 *
 * Phases in order: migrate -> seed -> admin -> finish. Each returns when the
 * time budget is spent, and the browser calls straight back.
 */
function kbb_run_step(string $base, array &$state): array
{
    require_once $base.'/vendor/autoload.php';

    /** @var \Illuminate\Foundation\Application $app */
    $app = require $base.'/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $phase = $state['phase'] ?? 'migrate';
    $started = microtime(true);

    if ($phase === 'migrate') {
        $migrator = $app->make('migrator');
        $migrator->setConnection(config('database.default'));

        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $files = $migrator->getMigrationFiles($base.'/database/migrations');
        $ran = $migrator->getRepository()->getRan();
        $pending = array_diff(array_keys($files), $ran);

        $state['migrate_total'] = $state['migrate_total'] ?? (count($files));
        $doneBefore = $state['migrate_total'] - count($pending);

        $did = 0;
        foreach ($pending as $name) {
            $migrator->runPending([$files[$name]], ['pretend' => false, 'step' => false]);
            $did++;

            if ((microtime(true) - $started) > KBB_TIME_BUDGET) {
                break;
            }
        }

        $doneNow = $doneBefore + $did;

        if ($doneNow >= $state['migrate_total'] || $did === 0) {
            $state['phase'] = $state['fresh'] ? 'seed' : 'admin';

            return ['done' => $state['migrate_total'], 'total' => $state['migrate_total'],
                'label' => 'Database structure', 'message' => 'All tables are in place.', 'finished' => false];
        }

        return ['done' => $doneNow, 'total' => $state['migrate_total'],
            'label' => 'Database structure', 'message' => 'Creating tables…', 'finished' => false];
    }

    if ($phase === 'seed') {
        /*
         * Seeders run as one call rather than batched. They are a handful of
         * classes and they have ordering dependencies -- DemoReviewsSeeder must
         * follow DemoCatalogueSeeder or every demo product advertises no
         * reviews while carrying them, which DatabaseSeeder's own comment
         * records. Splitting them across requests would break that for no gain.
         */
        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
        $state['phase'] = 'admin';

        return ['done' => 1, 'total' => 1, 'label' => 'Starter content',
            'message' => 'Settings, pages and a sample catalogue.', 'finished' => false];
    }

    if ($phase === 'admin') {
        $existing = \App\Models\AdminUser::query()->where('email', $state['admin_email'])->first();

        if ($existing === null) {
            \App\Models\AdminUser::create([
                'name' => $state['admin_name'],
                'email' => $state['admin_email'],
                'password' => $state['admin_password'],
                'role' => 'owner',
            ]);
        }

        if (($state['shop_name'] ?? '') !== '') {
            try {
                \App\Models\Setting::query()->updateOrCreate(
                    ['key' => 'store_name'],
                    ['value' => $state['shop_name']]
                );
                \App\Models\Setting::flushMap();
            } catch (Throwable $e) {
                // A missing settings row must not fail an otherwise good install.
            }
        }

        $state['phase'] = 'finish';

        return ['done' => 1, 'total' => 1, 'label' => 'Your account',
            'message' => 'Owner account ready.', 'finished' => false];
    }

    // finish
    foreach ([
        $base.'/bootstrap/cache/config.php',
        $base.'/bootstrap/cache/services.php',
        $base.'/bootstrap/cache/packages.php',
    ] as $f) {
        @unlink($f);
    }
    foreach (glob($base.'/bootstrap/cache/routes-*.php') ?: [] as $f) {
        @unlink($f);
    }

    $adminPath = 'admin';
    try {
        $adminPath = (string) (\App\Models\Setting::query()->where('key', 'admin_path')->value('value') ?: 'admin');
    } catch (Throwable $e) {
        $adminPath = 'admin';
    }

    $state['phase'] = 'done';
    $state['admin_path'] = $adminPath;

    return ['done' => 1, 'total' => 1, 'label' => 'Finishing', 'message' => 'Done.', 'finished' => true];
}

/* ═══════════════════════════════════════════════════════════════════ routing */

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$token = (string) ($_POST['token'] ?? '');

function kbb_json(array $payload, int $status = 200): void
{
    /*
     * DISCARD EVERYTHING PRINTED BEFORE THIS POINT.
     *
     * 283 of this project's 355 migrations contain an `echo`, and 331 of those
     * lines are not behind a runningInConsole() guard -- they were written for
     * `php artisan migrate`, where printing what happened is the whole point.
     * Run from a web request that text lands in the response body, in front of
     * the JSON, and the browser's JSON.parse fails on the first one. The
     * installer then reports "the server returned something unexpected" while
     * the migration it is complaining about has in fact succeeded.
     *
     * It is not a thing to fix migration by migration: they are right, this
     * caller is the unusual one. So the unusual caller cleans up after itself,
     * here, where every response funnels through.
     */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if ($action !== '') {
    if (($wait = kbb_throttle_locked($THROTTLE_FILE)) > 0) {
        kbb_json(['ok' => false, 'reason' => 'Too many wrong keys. Try again in '.ceil($wait / 60).' minutes.'], 429);
    }

    if (! kbb_check_token($token, $TOKEN_FILE, $THROTTLE_FILE)) {
        kbb_json(['ok' => false, 'reason' => 'That setup key is not right.'], 403);
    }

    kbb_throttle_clear($THROTTLE_FILE);
    $state = kbb_state_read($STATE_FILE);

    if ($action === 'requirements') {
        $checks = kbb_requirements($BASE);
        kbb_json([
            'ok' => true,
            'checks' => $checks,
            'passed' => ! in_array(false, array_column($checks, 'ok'), true),
        ]);
    }

    if ($action === 'db') {
        $probe = kbb_db_probe([
            'host' => trim((string) ($_POST['host'] ?? 'localhost')),
            'port' => (int) ($_POST['port'] ?? 3306) ?: 3306,
            'name' => trim((string) ($_POST['name'] ?? '')),
            'user' => trim((string) ($_POST['user'] ?? '')),
            'pass' => (string) ($_POST['pass'] ?? ''),
        ]);

        kbb_json($probe);
    }

    if ($action === 'begin') {
        $db = [
            'host' => trim((string) ($_POST['host'] ?? 'localhost')),
            'port' => (int) ($_POST['port'] ?? 3306) ?: 3306,
            'name' => trim((string) ($_POST['name'] ?? '')),
            'user' => trim((string) ($_POST['user'] ?? '')),
            'pass' => (string) ($_POST['pass'] ?? ''),
        ];

        $probe = kbb_db_probe($db);

        if (! ($probe['ok'] ?? false)) {
            kbb_json(['ok' => false, 'reason' => $probe['reason']], 422);
        }

        $shopName = trim((string) ($_POST['shop_name'] ?? ''));
        $adminName = trim((string) ($_POST['admin_name'] ?? ''));
        $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
        $existingKey = trim((string) ($_POST['app_key'] ?? ''));

        $errors = [];

        if ($shopName === '' || mb_strlen($shopName) > 120) {
            $errors['shop_name'] = 'Give your shop a name.';
        }

        if ($adminName === '') {
            $errors['admin_name'] = 'Your name, so the shop knows who you are.';
        }

        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'That does not look like an email address.';
        }

        if (strlen($password) < 10) {
            $errors['password'] = 'Use at least 10 characters.';
        }

        if (! preg_match('#^https?://[^\s/]+#i', $appUrl)) {
            $errors['app_url'] = 'Include https:// at the front.';
        }

        /*
         * A restore MUST bring its old APP_KEY. PaymentProvider and
         * MailCredential store their values encrypted, so a new key does not
         * log anyone out -- it makes the Stripe keys and the SMTP password
         * permanently unreadable, and the symptom is payments and email failing
         * silently on a shop that otherwise looks perfectly healthy.
         */
        $fresh = ! ($probe['existing'] ?? false);

        if (! $fresh) {
            if (! preg_match('/^base64:[A-Za-z0-9+\/=]{20,}$/', $existingKey)) {
                $errors['app_key'] = 'This database already holds a shop, so paste the APP_KEY from its old .env '
                    .'— exactly, starting with base64:. A new key would make the saved payment and email '
                    .'credentials unreadable.';
            }
        }

        if ($errors !== []) {
            kbb_json(['ok' => false, 'errors' => $errors], 422);
        }

        $state = [
            'phase' => 'migrate',
            'fresh' => $fresh,
            'db' => $db,
            'shop_name' => $shopName,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'admin_password' => $password,
            'app_url' => $appUrl,
            'app_key' => $fresh ? 'base64:'.base64_encode(random_bytes(32)) : $existingKey,
        ];

        if (! kbb_write_env($ENV_FILE, $state, __DIR__)) {
            kbb_json(['ok' => false, 'reason' => 'Could not write the .env file. Set the application folder to 755.'], 500);
        }

        // The password is needed once, by the admin phase. It is not kept
        // beyond that -- see the 'done' branch, which deletes the state file.
        kbb_state_write($STATE_FILE, $state);

        kbb_json(['ok' => true, 'fresh' => $fresh]);
    }

    if ($action === 'step') {
        if ($state === []) {
            kbb_json(['ok' => false, 'reason' => 'Setup has not been started. Reload this page.'], 409);
        }

        /*
         * Capture rather than merely discard: what the migrations print is the
         * best diagnosis there is when one of them fails on a host nobody can
         * shell into, so it goes to a log file beside the framework's own.
         */
        ob_start();

        try {
            $progress = kbb_run_step($BASE, $state);
            $printed = ob_get_clean();
        } catch (Throwable $e) {
            $printed = ob_get_clean();
            kbb_state_write($STATE_FILE, $state);

            @file_put_contents(
                $STORAGE.'/logs/install.log',
                gmdate('c').' FAILED in phase '.($state['phase'] ?? '?').': '.$e->getMessage()
                ."\n  at ".$e->getFile().':'.$e->getLine()."\n".($printed !== '' ? "  output: ".$printed."\n" : ''),
                FILE_APPEND
            );

            kbb_json([
                'ok' => false,
                'reason' => 'Step failed: '.$e->getMessage(),
                'where' => basename($e->getFile()).':'.$e->getLine(),
            ], 500);
        }

        if (($progress['finished'] ?? false) === true) {
            @file_put_contents($MARKER, gmdate('c')."\n");
            @unlink($STATE_FILE);
            @unlink($TOKEN_FILE);

            $selfGone = @unlink(__FILE__);

            kbb_json([
                'ok' => true, 'finished' => true,
                'admin_path' => $state['admin_path'] ?? 'admin',
                'app_url' => $state['app_url'] ?? '',
                'self_deleted' => $selfGone,
            ]);
        }

        kbb_state_write($STATE_FILE, $state);
        kbb_json(['ok' => true] + $progress);
    }

    kbb_json(['ok' => false, 'reason' => 'Unknown action.'], 400);
}

/* ═══════════════════════════════════════════════════════════════════ the page */

$TOKEN_EXISTS = is_file($TOKEN_FILE);
kbb_token($TOKEN_FILE);   // create it on first sight
$guessUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    .'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
$tokenPathForHumans = $STORAGE.'/INSTALL-TOKEN.txt';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Set up your shop</title>
<style>
:root{
  --ink:#16181d; --ink-soft:#5d6470; --line:#e4e7ec; --bg:#f6f7f9; --card:#fff;
  --brand:#166a4d; --brand-soft:#e8f3ee; --warn:#b45309; --bad:#96271f; --good:#166a4d;
  --mono:ui-monospace,SFMono-Regular,Menlo,monospace;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --ink:#eef1f5; --ink-soft:#9aa3b2; --line:#2a2f3a; --bg:#12141a; --card:#181b22;
  --brand:#4ec49a; --brand-soft:#16302a;
}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px 16px 64px}
.wrap{max-width:660px;margin:0 auto}
h1{font-size:22px;margin:0 0 4px;letter-spacing:-.01em}
.sub{color:var(--ink-soft);font-size:13px;margin:0 0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;margin-bottom:14px}
label{display:block;font-size:13px;font-weight:600;margin:12px 0 4px}
input{width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);font:inherit}
input:focus{outline:2px solid var(--brand);outline-offset:1px}
.hint{font-size:12px;color:var(--ink-soft);margin:4px 0 0}
.btn{appearance:none;border:0;background:var(--brand);color:#fff;font:inherit;font-weight:600;padding:10px 18px;border-radius:8px;cursor:pointer}
.btn[disabled]{opacity:.5;cursor:default}
.btn.ghost{background:transparent;color:var(--ink);border:1px solid var(--line)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:520px){.grid2{grid-template-columns:1fr}}
.chk{display:flex;gap:9px;padding:7px 0;font-size:13px;align-items:flex-start;border-bottom:1px solid var(--line)}
.chk:last-child{border-bottom:0}
.dot{flex:0 0 auto;width:16px;height:16px;border-radius:50%;margin-top:2px}
.dot.y{background:var(--good)} .dot.n{background:var(--bad)} .dot.w{background:var(--warn)}
.err{color:var(--bad);font-size:12px;margin-top:4px}
.note{background:var(--brand-soft);border-radius:8px;padding:12px;font-size:13px;margin-top:12px}
/* A note that lists alternatives needs its labels legible and its paragraphs
   apart; .hint's default 4px top margin runs three of them together. */
.note .hint{margin-top:9px} .note .hint b{color:var(--ink)}
.note.warn{background:#fdf3e4;color:#7a4b07}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .note.warn{background:#3a2c14;color:#f0c27a}}
code{font-family:var(--mono);font-size:12px;background:rgba(127,127,127,.14);padding:1px 5px;border-radius:4px;word-break:break-all}
/* Multi-line commands: a block, wrapping at the line breaks it was written
   with, so a copied paste runs as two commands and not one mangled one. */
code.cmd{display:block;padding:8px 9px;margin-top:5px;white-space:pre-wrap;line-height:1.5}
.bar{height:10px;background:var(--line);border-radius:99px;overflow:hidden;margin:10px 0 6px}
.bar>i{display:block;height:100%;background:var(--brand);width:0;transition:width .25s ease}
.steps{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap}
.steps span{font-size:11px;padding:3px 9px;border-radius:99px;background:var(--line);color:var(--ink-soft)}
.steps span.on{background:var(--brand);color:#fff}
.log{font-family:var(--mono);font-size:12px;color:var(--ink-soft);max-height:170px;overflow:auto;margin-top:10px}
</style>
</head>
<body>
<div class="wrap">
  <h1>Set up your shop</h1>
  <p class="sub">Five short screens. Nothing is written until the last one.</p>

  <div class="steps" id="steps">
    <span data-s="1">1 · Setup key</span><span data-s="2">2 · Server</span>
    <span data-s="3">3 · Database</span><span data-s="4">4 · Your shop</span>
    <span data-s="5">5 · Install</span>
  </div>

  <div class="card" id="pane"></div>
</div>

<script>
const $=s=>document.querySelector(s);
let TOKEN='', DB=null, FRESH=true;

function steps(n){document.querySelectorAll('#steps span').forEach(e=>e.classList.toggle('on',+e.dataset.s===n));}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

async function post(action,data){
  const b=new URLSearchParams(Object.assign({action,token:TOKEN},data||{}));
  const r=await fetch(location.pathname,{method:'POST',body:b,headers:{'Accept':'application/json'}});
  let j=null; const t=await r.text();
  try{ j=JSON.parse(t); }catch(e){ return {ok:false,reason:'The server returned something unexpected. '+t.slice(0,160)}; }
  return j;
}

/* ── 1 · the setup key ─────────────────────────────────────────────────── */
function paneToken(msg){
  steps(1);
  $('#pane').innerHTML=
    '<b>First, prove this server is yours</b>'
    +'<p class="hint">A file has been placed on your server. Open it and paste what is inside. '
    +'This stops anyone who finds this page before you do from setting up your shop for themselves.</p>'
    /*
     * TWO WAYS TO READ IT, BECAUSE THE FIRST ONE IS NOT ALWAYS THERE.
     *
     * This used to say "open it in your hosting panel's File Manager" and
     * nothing else, which is a complete instruction on cPanel and a dead end
     * everywhere else. It was found on Cloudways, which gives you SSH and no
     * file manager at all: the owner reached this screen, read the only route
     * offered, went looking for a feature his panel does not have, and was
     * stuck on the first of five steps with the shop one paste away.
     *
     * A VPS, a DigitalOcean or Vultr box, anything behind SFTP-only, and every
     * panel that calls its file manager something else are all the same case.
     * So both routes are named, and neither is described as the normal one.
     */
    +'<div class="note"><b>The file is here:</b><br><code><?= htmlspecialchars($tokenPathForHumans, ENT_QUOTES) ?></code>'
    +'<p class="hint"><b>If your host has a File Manager:</b> browse to that path and open the file.</p>'
    +'<p class="hint"><b>If you have SSH or a terminal:</b> run this and copy what it prints —<br>'
    +'<code>cat <?= htmlspecialchars($tokenPathForHumans, ENT_QUOTES) ?></code></p>'
    +'<p class="hint">Either way, you want the <b>long line at the top</b>. Everything under it is just an '
    +'explanation of what the file is for.</p>'
    /*
     * THE LOCKOUT, AND THE WAY OUT OF IT.
     *
     * The file is created by PHP and read by a person, and on most hosting
     * those are different users. It is written 0640 so the shared group can
     * read it, which covers the hosts where the web server and the login
     * account share one -- but not a host where PHP runs as `nobody`, or where
     * the login account sits in a group of its own. There the owner gets
     * "Permission denied" from the very command this page just gave him, on
     * screen one of five, with no recovery flow and nothing past it.
     *
     * So the way out is printed rather than left to support. kbb_token() takes
     * the first line of this file if it matches ^[a-f0-9]{64}$ and only mints a
     * new one otherwise, which means an owner-written key is a first-class key
     * -- this is not a workaround bolted on, it is the function's existing
     * contract, said out loud.
     *
     * `rm` works where `cat` did not, and that surprises people: deleting a
     * file is governed by write permission on its DIRECTORY, not on the file.
     * storage/ belongs to the owner, so the unlink succeeds even though the
     * read failed.
     *
     * Nothing is weakened by saying it. Anyone who can write into storage/
     * already has the application -- they do not need a setup key, they can
     * read .env. The only thing this changes is whether a locked-out owner has
     * a way back in.
     */
    +'<p class="hint">If that says <b>Permission denied</b>, the web server made the file and your '
    +'login cannot read it. Make your own key instead — it is accepted exactly the same way:'
    /*
     * ONE LINE, AND THE `;` AFTER THE ASSIGNMENT IS LOAD-BEARING.
     *
     * This was two lines -- `T=<path>` then `rm -f "$T" && ...` -- and a
     * terminal that joins a pasted block turns that into
     *
     *     T=<path> rm -f "$T"
     *
     * which is not an assignment at all. It is an environment prefix scoped to
     * the `rm` process, so $T expands to EMPTY in the shell that is running the
     * line, and the owner sees `bash: : No such file or directory`. Reported
     * from a real paste, on the recovery route, by someone already locked out
     * of the normal one -- the second dead end on the same screen.
     *
     * A single line with `;` separators cannot break that way however it is
     * pasted, so the whole class of failure goes rather than being explained.
     * `;` and not `&&`: if the file does not exist yet the `rm` is still a
     * success (-f), but chaining on truth is a promise about a shape this line
     * does not need to make.
     */
    +'<code class="cmd">T=<?= htmlspecialchars($tokenPathForHumans, ENT_QUOTES) ?>; '
    +'rm -f "$T"; openssl rand -hex 32 &gt; "$T"; cat "$T"</code></p></div>'
    +'<label for="tk">Setup key</label>'
    +'<input id="tk" autocomplete="off" spellcheck="false" placeholder="paste the long line from that file">'
    +(msg?'<div class="err">'+esc(msg)+'</div>':'')
    +'<div class="row"><button class="btn" id="go">Continue</button></div>';
  $('#tk').focus();
  $('#tk').onkeydown=e=>{if(e.key==='Enter')$('#go').click();};
  $('#go').onclick=async()=>{
    TOKEN=$('#tk').value.trim().split(/\s+/)[0]||'';
    const r=await post('requirements');
    if(!r.ok){ TOKEN=''; return paneToken(r.reason||'That setup key is not right.'); }
    paneReq(r);
  };
}

/* ── 2 · the server ────────────────────────────────────────────────────── */
function paneReq(r){
  steps(2);
  /* Three states, not two. A `warn` row is amber, carries its advice the way a
     red one does, and does NOT disable Continue -- `r.passed` never counted it.
     A warning that blocked would just be a red line with a softer colour. */
  const rows=r.checks.map(c=>
    '<div class="chk"><span class="dot '+(c.ok?(c.warn?'w':'y'):'n')+'"></span><div><b>'+esc(c.label)+'</b> — '+esc(c.detail)
    +((!c.ok||c.warn)?'<div class="hint">'+esc(c.fix)+'</div>':'')+'</div></div>').join('');
  $('#pane').innerHTML='<b>What this server can do</b><div style="margin-top:10px">'+rows+'</div>'
    +(r.passed?'':'<div class="note warn">Fix the red lines above, then press Check again. '
      +'Everything else waits until they pass.</div>')
    +'<div class="row"><button class="btn ghost" id="again">Check again</button>'
    +'<button class="btn" id="next"'+(r.passed?'':' disabled')+'>Continue</button></div>';
  $('#again').onclick=async()=>{ const x=await post('requirements'); if(x.ok) paneReq(x); };
  if(r.passed) $('#next').onclick=()=>paneDb();
}

/* ── 3 · the database ──────────────────────────────────────────────────── */
function paneDb(msg,vals){
  steps(3); const v=vals||{host:'localhost',port:'3306',name:'',user:'',pass:''};
  $('#pane').innerHTML='<b>Your database</b>'
    +'<p class="hint">Create a MySQL database and user in your hosting panel first, then paste the details here. '
    +'Nothing is written to it yet — this only checks the connection.</p>'
    +'<div class="grid2"><div><label for="h">Host</label><input id="h" value="'+esc(v.host)+'"></div>'
    +'<div><label for="p">Port</label><input id="p" value="'+esc(v.port)+'"></div></div>'
    +'<label for="n">Database name</label><input id="n" value="'+esc(v.name)+'" autocomplete="off">'
    +'<label for="u">Username</label><input id="u" value="'+esc(v.user)+'" autocomplete="off">'
    +'<label for="w">Password</label><input id="w" type="password" value="'+esc(v.pass)+'" autocomplete="new-password">'
    +(msg?'<div class="err">'+esc(msg)+'</div>':'')
    +'<div class="row"><button class="btn" id="test">Test connection</button></div>';
  $('#test').onclick=async()=>{
    const d={host:$('#h').value,port:$('#p').value,name:$('#n').value,user:$('#u').value,pass:$('#w').value};
    $('#test').disabled=true; $('#test').textContent='Testing…';
    const r=await post('db',d);
    $('#test').disabled=false; $('#test').textContent='Test connection';
    if(!r.ok) return paneDb(r.reason,d);
    DB=d; FRESH=!r.existing;
    paneShop(null,r);
  };
}

/* ── 4 · your shop ─────────────────────────────────────────────────────── */
function paneShop(errs,probe){
  steps(4); const e=errs||{};
  const restoring=!FRESH;
  $('#pane').innerHTML='<b>'+(restoring?'Restoring an existing shop':'Your shop')+'</b>'
    +(restoring
      ?'<div class="note warn"><b>This database already holds a shop</b>'
        +(probe&&probe.orders?' with '+probe.orders+' orders':'')+'. Nothing in it will be overwritten — '
        +'only missing tables are added. Because of that it needs its original security key.</div>'
      :'<p class="hint">This is the account you will sign in with. You can change any of it later.</p>')
    +'<label for="sn">Shop name</label><input id="sn" placeholder="ExtraBeauty">'
    +(e.shop_name?'<div class="err">'+esc(e.shop_name)+'</div>':'')
    +'<label for="an">Your name</label><input id="an" autocomplete="name">'
    +(e.admin_name?'<div class="err">'+esc(e.admin_name)+'</div>':'')
    +'<label for="ae">Email</label><input id="ae" type="email" autocomplete="username">'
    +(e.admin_email?'<div class="err">'+esc(e.admin_email)+'</div>':'')
    +'<label for="pw">Password</label><input id="pw" type="password" autocomplete="new-password">'
    +'<p class="hint">At least 10 characters.</p>'
    +(e.password?'<div class="err">'+esc(e.password)+'</div>':'')
    +'<label for="au">Shop address</label><input id="au" value="<?= htmlspecialchars($guessUrl, ENT_QUOTES) ?>">'
    +'<p class="hint">Include https://. Everything the shop links to is built from this.</p>'
    +(e.app_url?'<div class="err">'+esc(e.app_url)+'</div>':'')
    +(restoring
      ?'<label for="ak">Original security key (APP_KEY)</label>'
        +'<input id="ak" placeholder="base64:…" autocomplete="off" spellcheck="false">'
        +'<p class="hint">Copy it from the old site’s <code>.env</code>. Your saved card-payment and email '
        +'passwords are encrypted with it — a new key cannot read them back.</p>'
        +(e.app_key?'<div class="err">'+esc(e.app_key)+'</div>':'')
      :'')
    +'<div class="row"><button class="btn ghost" id="back">Back</button><button class="btn" id="start">Install now</button></div>';
  $('#back').onclick=()=>paneDb(null,DB);
  $('#start').onclick=async()=>{
    $('#start').disabled=true;
    const r=await post('begin',Object.assign({},DB,{
      shop_name:$('#sn').value, admin_name:$('#an').value, admin_email:$('#ae').value,
      password:$('#pw').value, app_url:$('#au').value.trim(),
      app_key:restoring?$('#ak').value.trim():'',
    }));
    $('#start').disabled=false;
    if(!r.ok){ if(r.errors) return paneShop(r.errors,probe); return paneShop({shop_name:r.reason},probe); }
    paneRun();
  };
}

/* ── 5 · install, with a real bar ──────────────────────────────────────── */
function paneRun(){
  steps(5);
  $('#pane').innerHTML='<b>Installing</b>'
    +'<p class="hint" id="lbl">Starting…</p><div class="bar"><i id="fill"></i></div>'
    +'<div class="hint" id="pct">0%</div><div class="log" id="log"></div>';
  const log=(m)=>{const d=document.createElement('div');d.textContent=m;$('#log').prepend(d);};
  let guard=0;

  (async function loop(){
    if(guard++>4000){ log('Stopped: too many steps.'); return; }
    const r=await post('step');
    if(!r.ok){
      $('#lbl').textContent='Something went wrong.';
      log(r.reason||'Unknown error'); if(r.where) log('at '+r.where);
      $('#pane').insertAdjacentHTML('beforeend',
        '<div class="note warn">Nothing is lost. Fix the cause and reload this page — '
        +'setup carries on from where it stopped.</div>');
      return;
    }
    if(r.finished) return paneDone(r);
    const pc=r.total?Math.round(r.done/r.total*100):0;
    $('#fill').style.width=pc+'%'; $('#pct').textContent=pc+'% — '+r.done+' of '+r.total;
    $('#lbl').textContent=r.label+' — '+r.message;
    setTimeout(loop,0);
  })();
}

function paneDone(r){
  const base=(r.app_url||'').replace(/\/$/,'');
  $('#pane').innerHTML='<b>Your shop is ready.</b>'
    +'<div class="note">Sign in at<br><code>'+esc(base+'/'+(r.admin_path||'admin'))+'</code></div>'
    +(r.self_deleted
      ?'<p class="hint">This installer has deleted itself.</p>'
      :'<div class="note warn"><b>Delete <code>install.php</code> from your web root now.</b> '
        +'It could not remove itself. It will refuse to run again, but do not leave it there.</div>')
    +'<div class="row"><a class="btn" href="'+esc(base+'/'+(r.admin_path||'admin'))+'">Go to your shop</a></div>';
  document.querySelectorAll('#steps span').forEach(e=>e.classList.add('on'));
}

paneToken(null);
</script>
</body>
</html>
