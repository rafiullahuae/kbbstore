<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * What every page actually costs, measured.
 *
 * WHY THIS IS A COMMAND AND NOT A TEST.
 *
 * The suite runs against a fixture of a few dozen rows, which is the wrong
 * instrument for this question entirely: a query that scans the whole table is
 * indistinguishable from one that seeks a single row when the table has thirty
 * rows in it. This measures against a catalogue the size of a real one, on
 * MySQL, because that is what the live host runs.
 *
 * WHY EVERY MEASUREMENT IS ITS OWN OPERATING-SYSTEM PROCESS.
 *
 * This application memoises in process-level statics in at least seven places
 * -- Setting::map(), SettingsService, Facets, Money, Url, AdminPathService --
 * and CLAUDE.md calls the first of them out by name as a trap. Under PHP-FPM a
 * request IS a process, so every one of those memos starts cold on every page
 * view a shopper ever performs. Measuring two pages in one PHP process would
 * hand the second one the first one's warm memos and report a number no
 * visitor will ever experience.
 *
 * So the parent builds a scenario list and spawns `artisan kbb:page-cost
 * --probe=<name>` once per repetition. The child boots ONE fresh application,
 * handles ONE request through the real HTTP kernel -- real middleware, real
 * session, real Blade -- and prints what it cost. Nothing is shared between
 * two measurements except the database and the file cache, which is exactly
 * what is shared between two requests on the live host.
 *
 * THE CONFIGURATION IS THE HOST'S, not the suite's: SESSION_DRIVER=database
 * and CACHE_STORE=file, both read straight off env.staging.txt. Those two
 * decide real query counts -- a database session is a SELECT and an UPDATE on
 * every authenticated page -- and measuring with the suite's array drivers
 * would quietly delete them from the totals.
 *
 * HOW TO RUN IT. See docs/page-cost.md; it carries the exact invocation and
 * the numbers this produced.
 */
class PageCost extends Command
{
    protected $signature = 'kbb:page-cost
        {--fresh : drop and rebuild the schema on the current connection first}
        {--seed : (re)build the measurement dataset}
        {--products=3000 : products to seed}
        {--orders=6000 : orders to seed}
        {--reviews=6000 : reviews to seed, on top of the hero product\'s own}
        {--runs=5 : repetitions per page; the median is reported}
        {--label=before : the column this run fills in docs/page-cost.md}
        {--only= : comma-separated substrings; measure only matching pages}
        {--json= : write the raw measurements to this file}
        {--explain : print EXPLAIN for each page\'s slowest query}
        {--queries : print every statement a page ran, slowest first}
        {--dump= : write each page\'s response body into this directory}
        {--probe= : internal. Measure one page in this process and print JSON.}
        {--state= : internal. The cookie jar written by --prep.}
        {--dump-to= : internal. Where the probe writes its response body.}
        {--prep : internal. Sign the fixtures in and print their cookies.}';

    protected $description = 'Measure query count, query time and peak memory for every page, at production data volume.';

    /** @var array<string,string>|null */
    private ?array $slugs = null;

    /**
     * The rows the scenarios point at, looked up rather than hard-coded.
     *
     * The parent and every child process resolve these independently, so they
     * have to be a FUNCTION OF THE DATA rather than of the order the seeder
     * happened to run in -- otherwise a child measures a 404 and reports it as
     * a cheap page. Each one is the busiest row of its kind, because the
     * cheapest category page in a catalogue is not what makes a shop feel slow.
     */
    private function fixtureSlugs(): array
    {
        if ($this->slugs !== null) {
            return $this->slugs;
        }

        $category = DB::table('category_product')
            ->join('categories', 'categories.id', '=', 'category_product.category_id')
            ->groupBy('categories.id', 'categories.slug')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('categories.id')
            ->value('categories.slug');

        $brand = DB::table('products')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->where('products.status', 'publish')
            ->groupBy('brands.id', 'brands.slug')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('brands.id')
            ->value('brands.slug');

        $product = DB::table('products')->where('slug', PageCostDataset::HERO_SLUG)->value('slug')
            ?: DB::table('products')->where('status', 'publish')->orderByDesc('review_count')->value('slug');

        $customerId = DB::table('customers')->where('email', PageCostDataset::CUSTOMER_EMAIL)->value('id');

        $order = $customerId
            ? DB::table('orders')->where('customer_id', $customerId)->whereNull('deleted_at')->orderByDesc('id')->value('id')
            : DB::table('orders')->orderByDesc('id')->value('id');

        return $this->slugs = [
            'category' => (string) ($category ?: 'none'),
            'brand' => (string) ($brand ?: 'none'),
            'product' => (string) ($product ?: 'none'),
            'order' => (string) ($order ?: '0'),
        ];
    }

    /** Pages measured, in the order docs/page-cost.md lists them. */
    private function scenarios(): array
    {
        $s = $this->fixtureSlugs();

        return [
            'home' => ['GET', '/', 'none'],
            'shop-p1' => ['GET', '/shop', 'none'],
            'shop-deep' => ['GET', '/shop?paged=100', 'none'],
            'category-filtered' => ['GET', '/product-category/'.$s['category'].'?brand='.$s['brand'].'&price=54-150&instock=1&orderby=plow', 'none'],
            'brand-page' => ['GET', '/korean-skincare-brands/'.$s['brand'], 'none'],
            'brand-facet' => ['GET', '/shop?filter_brands='.$s['brand'], 'none'],
            'product' => ['GET', '/product/'.$s['product'], 'none'],
            'search' => ['GET', '/shop?s=serum', 'none'],
            'cart' => ['GET', '/cart', 'cart'],
            'checkout' => ['GET', '/checkout', 'cart'],
            'account-orders' => ['GET', '/my-account/orders', 'customer'],
            'account-order' => ['GET', '/my-account/orders/'.$s['order'], 'customer'],
            'admin-dashboard' => ['GET', '/admin-api/stats', 'admin'],
            'admin-orders' => ['GET', '/admin-api/orders-list?per_page=25', 'admin'],
            'admin-products' => ['GET', '/admin-api/catalog-products-list?per_page=25', 'admin'],
        ];
    }

    public function handle(): int
    {
        if ($this->option('prep')) {
            return $this->runPrep();
        }

        if ($this->option('probe')) {
            return $this->runProbe();
        }

        return $this->runParent();
    }

    /* ---------------------------------------------------------------- parent */

    private function runParent(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->warn('Running against '.DB::connection()->getDriverName().'. Production is MySQL; see docs/page-cost.md.');
        }

        $this->line('Database: '.DB::connection()->getDriverName().' / '.DB::connection()->getDatabaseName());

        if ($this->option('fresh')) {
            $this->call('migrate:fresh', ['--force' => true]);
            // The base install: settings, shipping zones, payment providers and
            // the module toggles. /checkout has nothing to render without them.
            $this->call('db:seed', ['--force' => true]);
        }

        if ($this->option('seed')) {
            $this->callSilent('cache:clear');
            (new PageCostDataset($this))->build(
                (int) $this->option('products'),
                (int) $this->option('orders'),
                (int) $this->option('reviews'),
            );
        }

        $this->reportVolume();

        $state = storage_path('app/page-cost-state.json');
        $this->line('Preparing fixture sessions...');
        $prep = $this->child(['--prep' => true, '--state' => $state]);

        if ($prep === null) {
            $this->error('The preparation pass failed. Nothing measured.');

            return self::FAILURE;
        }

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $runs = max(1, (int) $this->option('runs'));
        $results = [];

        foreach ($this->scenarios() as $name => $scenario) {
            if ($only !== [] && ! $this->matches($name, $only)) {
                continue;
            }

            // One discarded pass so the file cache and the InnoDB buffer pool
            // are in the state a page on a live site meets, not the state a
            // page on a just-restarted server meets. Both numbers matter; the
            // warm one is the one a shopper gets.
            $this->child(array_filter([
                '--probe' => $name,
                '--state' => $state,
                '--dump-to' => $this->option('dump') ? rtrim((string) $this->option('dump'), '/').'/'.$name.'.body' : null,
            ]));

            $samples = [];

            for ($i = 0; $i < $runs; $i++) {
                $sample = $this->child(['--probe' => $name, '--state' => $state]);

                if ($sample === null) {
                    break;
                }

                $samples[] = $sample;
            }

            if ($samples === []) {
                $this->error(sprintf('%-18s FAILED', $name));
                $results[$name] = ['error' => 'probe failed'];

                continue;
            }

            $row = $this->median($samples);
            $row['page'] = $name;
            $row['path'] = $scenario[1];
            $results[$name] = $row;

            $this->line(sprintf(
                '%-18s %s  %3d queries  %8s ms sql  slowest %8s ms  peak %7s',
                $name,
                $row['status'] == 200 ? 'ok ' : 'HTTP '.$row['status'],
                $row['queries'],
                number_format($row['sql_ms'], 2),
                number_format($row['slowest_ms'], 2),
                $this->bytes($row['peak_bytes']),
            ));

            if ($this->option('queries')) {
                foreach ($row['all'] ?? [] as $q) {
                    $this->line(sprintf('    %8s ms  %s', number_format($q['ms'], 3), substr($q['sql'], 0, 170)));
                }
            }

            if ($this->option('explain') && ! empty($row['slowest_sql_full'])) {
                $this->explain($row['slowest_sql_full'], $row['slowest_bindings'] ?? []);
            }
        }

        if ($this->option('json')) {
            $path = (string) $this->option('json');
            @mkdir(dirname($path), 0o755, true);
            file_put_contents($path, json_encode([
                'label' => $this->option('label'),
                'measured_at' => date('c'),
                'driver' => DB::connection()->getDriverName(),
                'php' => PHP_VERSION,
                'runs' => $runs,
                'volume' => $this->volume(),
                'pages' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Wrote '.$path);
        }

        $this->newLine();
        $this->line($this->markdownTable($results));

        return self::SUCCESS;
    }

    private function matches(string $name, array $only): bool
    {
        foreach ($only as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Run this command again as a child process and decode its JSON line. */
    private function child(array $options): ?array
    {
        $args = [PHP_BINARY, base_path('artisan'), 'kbb:page-cost'];

        foreach ($options as $key => $value) {
            $args[] = $value === true ? $key : $key.'='.$value;
        }

        $process = new Process($args, base_path(), null, null, 600.0);
        $process->run();

        $out = trim($process->getOutput());

        if ($out === '' || ! $process->isSuccessful()) {
            $this->line('<fg=red>child failed:</> '.substr(trim($process->getErrorOutput()).' '.$out, 0, 900));

            return null;
        }

        // The child prints exactly one JSON object; anything a provider echoed
        // before it is ignored rather than allowed to poison the decode.
        $start = strpos($out, '{');
        $decoded = $start === false ? null : json_decode(substr($out, $start), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The middle sample by query time.
     *
     * Median rather than mean: one sample in ten on a shared box is an
     * outlier by a factor of five for reasons that have nothing to do with the
     * page, and a mean lets that one sample write the documentation.
     */
    private function median(array $samples): array
    {
        usort($samples, fn ($a, $b) => $a['sql_ms'] <=> $b['sql_ms']);

        $middle = $samples[intdiv(count($samples), 2)];
        $middle['samples'] = count($samples);
        $middle['sql_ms_min'] = $samples[0]['sql_ms'];
        $middle['sql_ms_max'] = $samples[count($samples) - 1]['sql_ms'];

        return $middle;
    }

    private function explain(string $sql, array $bindings): void
    {
        $this->line('    SQL      '.$this->trim($sql));

        if (! str_starts_with(strtolower(ltrim($sql)), 'select')) {
            return;
        }

        try {
            foreach (DB::select('EXPLAIN '.$sql, $bindings) as $row) {
                $r = (array) $row;
                $this->line('    EXPLAIN  '.($r['table'] ?? '?').'  type='.($r['type'] ?? '?')
                    .'  key='.($r['key'] ?? 'NULL').'  rows='.($r['rows'] ?? '?').'  '.($r['Extra'] ?? ''));
            }
        } catch (\Throwable $e) {
            $this->line('    EXPLAIN failed: '.substr($e->getMessage(), 0, 200));
        }
    }

    /* ----------------------------------------------------------------- child */

    /**
     * Sign the fixtures in and write their cookies out.
     *
     * The cookies are the real, encrypted ones a browser would hold, produced
     * by real requests through the real middleware: a GET to collect a CSRF
     * token, then the login POST. Forging a session row by hand would measure
     * a session shape the application never actually writes.
     */
    private function runPrep(): int
    {
        $jar = [
            'none' => [],
            'cart' => $this->cartCookies(),
            'customer' => $this->login('/my-account', '/my-account/login', [
                'email' => PageCostDataset::CUSTOMER_EMAIL,
                'password' => PageCostDataset::PASSWORD,
            ]),
            'admin' => $this->login(
                '/'.\App\Services\AdminPathService::current().'/login',
                '/'.\App\Services\AdminPathService::current().'/login',
                ['email' => PageCostDataset::ADMIN_EMAIL, 'password' => PageCostDataset::PASSWORD],
            ),
        ];

        $path = (string) $this->option('state');
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, json_encode($jar));

        $this->line(json_encode(['ok' => true, 'sets' => array_map('count', $jar)]));

        return self::SUCCESS;
    }

    /**
     * The basket cookie.
     *
     * The cart is built through the model rather than through six POSTs,
     * because what is being measured is the cost of RENDERING a basket, not
     * the cost of filling one. The cookie itself is encrypted exactly as
     * EncryptCookies encrypts it, prefix included -- a cookie that fails to
     * decrypt is dropped silently, and /cart then renders the much cheaper
     * empty page under the name of the full one.
     */
    private function cartCookies(): array
    {
        $cart = \App\Models\Cart::query()->where('status', 'active')->latest('id')->first();

        if (! $cart) {
            return [];
        }

        return [\App\Services\CartService::COOKIE => $this->encryptCookie(\App\Services\CartService::COOKIE, (string) $cart->token)];
    }

    private function encryptCookie(string $name, string $value): string
    {
        return Crypt::encrypt(CookieValuePrefix::create($name, Crypt::getKey()).$value, false);
    }

    /** @return array<string,string> the cookies the login left behind */
    private function login(string $formPath, string $postPath, array $credentials): array
    {
        $kernel = app(HttpKernel::class);

        $form = Request::create($this->prefixed($formPath), 'GET');
        $formResponse = $kernel->handle($form);
        $cookies = $this->harvest([], $formResponse);

        $token = $form->hasSession() ? $form->session()->token() : csrf_token();

        $post = Request::create($this->prefixed($postPath), 'POST', $credentials + ['_token' => $token], $cookies);
        $postResponse = $kernel->handle($post);

        return $this->harvest($cookies, $postResponse);
    }

    /** @return array<string,string> */
    private function harvest(array $cookies, $response): array
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === null || $cookie->getValue() === '') {
                unset($cookies[$cookie->getName()]);

                continue;
            }

            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    /**
     * Measure one page, in this process, and print the result as one JSON line.
     *
     * The meters are started AFTER the application is built and immediately
     * before the kernel is handed the request, so what is reported is the
     * page's own cost and not the framework's boot. Boot is identical for
     * every page and is not what a page can be blamed for.
     */
    private function runProbe(): int
    {
        $name = (string) $this->option('probe');
        $scenarios = $this->scenarios();

        if (! isset($scenarios[$name])) {
            $this->line(json_encode(['error' => 'unknown page '.$name]));

            return self::FAILURE;
        }

        [$method, $path, $set] = $scenarios[$name];

        $jar = json_decode((string) @file_get_contents((string) $this->option('state')), true) ?: [];
        $cookies = $jar[$set] ?? [];

        $queries = [];
        DB::flushQueryLog();
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'ms' => (float) $query->time, 'bindings' => $query->bindings];
        });

        $request = Request::create($this->prefixed($path), $method, [], $cookies, [], [
            'HTTP_ACCEPT' => 'text/html,application/json',
            'HTTP_USER_AGENT' => 'kbb-page-cost',
        ]);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $resident = memory_get_usage(true);
        $started = hrtime(true);

        try {
            $response = app(HttpKernel::class)->handle($request);
            $status = $response->getStatusCode();
            $body = (string) $response->getContent();
            $bytes = strlen($body);

            if ($this->option('dump-to')) {
                @mkdir(dirname((string) $this->option('dump-to')), 0o755, true);
                file_put_contents((string) $this->option('dump-to'), $body);
            }
        } catch (\Throwable $e) {
            $status = 500;
            $bytes = 0;
            $queries[] = ['sql' => 'EXCEPTION: '.$e->getMessage(), 'ms' => 0.0, 'bindings' => []];
        }

        $wall = (hrtime(true) - $started) / 1e6;
        $peak = memory_get_peak_usage(true);

        usort($queries, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $slowest = $queries[0] ?? ['sql' => '', 'ms' => 0.0, 'bindings' => []];

        $this->line(json_encode([
            'status' => $status,
            'queries' => count($queries),
            'sql_ms' => round(array_sum(array_column($queries, 'ms')), 3),
            'slowest_ms' => round((float) $slowest['ms'], 3),
            'slowest_sql' => $this->trim($slowest['sql']),
            // Untrimmed, and used for nothing but EXPLAIN: a statement cut off
            // at 600 characters is not valid SQL and EXPLAIN answers a syntax
            // error instead of a plan.
            'slowest_sql_full' => $slowest['sql'],
            'all' => array_map(fn ($q) => ['ms' => round((float) $q['ms'], 3), 'sql' => $this->trim($q['sql'])], $queries),
            'slowest_bindings' => array_map(fn ($b) => is_scalar($b) ? $b : (string) $b, (array) $slowest['bindings']),
            'wall_ms' => round($wall, 3),
            'peak_bytes' => $peak,
            'peak_over_resident' => $peak - $resident,
            'response_bytes' => $bytes,
        ]));

        return self::SUCCESS;
    }

    /** KBB_BASE_PATH prefixes every route; a probe that ignores it measures a 404. */
    private function prefixed(string $path): string
    {
        return \App\Support\Url::to($path);
    }

    private function trim(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return strlen($sql) > 600 ? substr($sql, 0, 600).' ...' : $sql;
    }

    /* ------------------------------------------------------------- reporting */

    private function volume(): array
    {
        $counts = [];

        foreach (['products', 'categories', 'brands', 'orders', 'order_items', 'reviews', 'customers'] as $table) {
            $counts[$table] = \Illuminate\Support\Facades\Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $counts;
    }

    private function reportVolume(): void
    {
        $parts = [];

        foreach ($this->volume() as $table => $n) {
            $parts[] = number_format($n).' '.$table;
        }

        $this->line('Volume: '.implode(', ', $parts));
        $this->newLine();
    }

    private function markdownTable(array $results): string
    {
        $out = ["| Page | Queries | SQL ms | Slowest query ms | Peak memory |", "| --- | ---: | ---: | ---: | ---: |"];

        foreach ($results as $name => $row) {
            if (isset($row['error'])) {
                $out[] = "| {$name} | — | — | — | — |";

                continue;
            }

            $out[] = sprintf(
                '| %s | %d | %s | %s | %s |',
                $name,
                $row['queries'],
                number_format($row['sql_ms'], 2),
                number_format($row['slowest_ms'], 2),
                $this->bytes($row['peak_bytes']),
            );
        }

        return implode("\n", $out);
    }

    private function bytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }
}
