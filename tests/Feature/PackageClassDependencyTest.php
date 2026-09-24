<?php

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * THE DEFECT, ON THE SHOP, 24 SEPTEMBER 2026
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Package 2.60.260 shipped three Blade templates that resolve
 * `App\Services\VariantPricing`. The class itself shipped in 2.60.259, which
 * had not been applied. The callers landed on the server without the callee.
 * Every page that rendered one of those templates fatal-errored -- the home
 * page, /shop, every category, every brand and every product page -- and the
 * package reported "applied", because nothing in the updater had ever read the
 * contents of a file it was about to install.
 *
 * UpdatePackage::verify() now scans the PHP and Blade files a package carries
 * and requires every `App\` class they name to be either in the package or
 * already on the server.
 *
 * MUTATION, run: remove `&& $this->checkClassDependencies()` from verify()'s
 * chain. `it refuses a package whose Blade templates outrun their class` and
 * three others go green-to-red on the assertion that verify() returned false.
 *
 * SECOND MUTATION, run: make ClassDependencyScan::referencesIn() return
 * fullyQualifiedByRegex() instead of walking PHP's tokens -- the shortcut
 * anyone writing this check would reach for first. Four tests go red, and the
 * informative one is `it does not refuse a package over a name in a docblock`:
 * a regex cannot tell a live reference from a mention in a comment, and a
 * verifier that refuses a package because of a docblock is a verifier that gets
 * switched off within the week. The other three are `use` statements, which a
 * search for fully qualified names never sees at all.
 *
 * (isTrivia() is NOT what protects docblocks -- dropping T_DOC_COMMENT from it
 * changes nothing, because a doc comment is one opaque token and no name is
 * ever tokenised out of it. Checked, because the note was wrong the first time
 * it was written.)
 * ═══════════════════════════════════════════════════════════════════════════
 */

use App\Services\Update\ClassDependencyScan;
use App\Services\Update\UpdateGuard;
use App\Services\Update\UpdatePackage;

/**
 * A package containing exactly the files given, declared correctly, plus a
 * server root the check treats as "what is already installed".
 *
 * @param  array<string, string>  $files  relative path => contents
 * @param  array<int, string>  $installed  relative paths that already exist on the server
 */
function dependencyPackage(array $files, array $installed = []): UpdatePackage
{
    $dir = sys_get_temp_dir().'/kbb-dep-'.bin2hex(random_bytes(6));
    @mkdir($dir.'/server', 0775, true);

    foreach ($installed as $relative) {
        @mkdir(dirname($dir.'/server/'.$relative), 0775, true);
        file_put_contents($dir.'/server/'.$relative, "<?php // already installed\n");
    }

    $zipPath = $dir.'/package.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    $declared = [];

    foreach ($files as $relative => $body) {
        $zip->addFromString('files/'.$relative, $body);
        $declared[$relative] = hash('sha256', $body);
    }

    $zip->addFromString('update.json', json_encode([
        'name' => 'KBB Storefront',
        'version' => '9.99.900',
        'requires_php' => '8.2',
        'notes' => '',
        'files' => $declared,
        'signature' => '',
    ]));
    $zip->close();

    return new UpdatePackage($zipPath, $dir.'/scratch', new UpdateGuard(), $dir.'/server');
}

/* ═══════════════════════════════════════════ 2.60.260, reproduced ═════════ */

it('refuses a package whose Blade templates outrun their class', function () {
    // Exactly what 2.60.260 was: the templates, and not the class they call.
    $package = dependencyPackage([
        'resources/views/components/product-card.blade.php' =>
            '<div class="price">{{ \App\Services\VariantPricing::display($product) }}</div>',
    ]);

    expect($package->verify())->toBeFalse(
        'A package installing a template that calls a class nobody has was accepted. '
        .'On the server that is a fatal error on every page rendering it.'
    );

    expect(implode(' ', $package->errors))
        ->toContain('App\Services\VariantPricing')
        ->toContain('product-card.blade.php')
        ->toContain('not been applied')
        ->toContain('kbb:package');
});

it('accepts the same package once it carries the class too', function () {
    // 2.60.259 and 2.60.260 built as one package, which is what should have
    // happened.
    $package = dependencyPackage([
        'resources/views/components/product-card.blade.php' =>
            '<div class="price">{{ \App\Services\VariantPricing::display($product) }}</div>',
        'app/Services/VariantPricing.php' =>
            "<?php\n\nnamespace App\Services;\n\nfinal class VariantPricing {}\n",
    ]);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

it('accepts a package whose class is already installed on the server', function () {
    // The ordinary case: a template patch on a shop that has the class.
    $package = dependencyPackage(
        files: [
            'resources/views/components/product-card.blade.php' =>
                '<div class="price">{{ \App\Services\VariantPricing::display($product) }}</div>',
        ],
        installed: ['app/Services/VariantPricing.php'],
    );

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

/* ═══════════════════════════════════════════ plain PHP, use statements ════ */

it('refuses a use statement naming a class nobody has', function () {
    $package = dependencyPackage([
        'app/Http/Controllers/Store/ThingController.php' => <<<'PHP'
            <?php

            namespace App\Http\Controllers\Store;

            use App\Services\NotShippedAnywhere;

            final class ThingController
            {
                public function show(): void
                {
                    (new NotShippedAnywhere())->run();
                }
            }
            PHP,
    ]);

    expect($package->verify())->toBeFalse()
        ->and(implode(' ', $package->errors))->toContain('App\Services\NotShippedAnywhere');
});

it('reads a braced group use', function () {
    $package = dependencyPackage([
        'app/Support/Thing.php' => <<<'PHP'
            <?php

            namespace App\Support;

            use App\Services\{Present, Absent};

            final class Thing {}
            PHP,
    ], installed: ['app/Services/Present.php']);

    expect($package->verify())->toBeFalse()
        ->and(implode(' ', $package->errors))
        ->toContain('App\Services\Absent')
        ->not->toContain('App\Services\Present');
});

it('reads a trait imported into a class body', function () {
    // A missing trait is every bit as fatal as a missing parent class.
    $package = dependencyPackage([
        'app/Support/Thing.php' => <<<'PHP'
            <?php

            namespace App\Support;

            use App\Concerns\MissingTrait;

            final class Thing
            {
                use MissingTrait;
            }
            PHP,
    ]);

    expect($package->verify())->toBeFalse()
        ->and(implode(' ', $package->errors))->toContain('App\Concerns\MissingTrait');
});

/* ═══════════════════════════════ what must NOT be refused ═════════════════ */

it('does not refuse a package over a name in a docblock', function () {
    /*
     * The false positive that would sink this check. Half the files in this
     * repository carry `@param \App\Services\Something` or an @see in a
     * docblock, and a package refused because of a comment is a check that
     * gets switched off within the week. The scan runs on PHP's own tokeniser
     * precisely so a comment is a comment.
     */
    $package = dependencyPackage([
        'app/Support/Thing.php' => <<<'PHP'
            <?php

            namespace App\Support;

            /**
             * Replaces \App\Services\LongGoneHelper, which was deleted in 2.60.9.
             *
             * @see \App\Services\AlsoNotHere
             */
            final class Thing {}
            PHP,
    ]);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

it('does not refuse a package over a class name in a string', function () {
    $package = dependencyPackage([
        'app/Support/Thing.php' => <<<'PHP'
            <?php

            namespace App\Support;

            final class Thing
            {
                public const LEGACY = 'App\Services\GoneForGood';
            }
            PHP,
    ]);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

it('leaves vendor and framework classes alone', function () {
    // UpdateGuard forbids writing vendor/ at all, so a package never ships a
    // framework class and never needs to be judged on one.
    $package = dependencyPackage([
        'app/Support/Thing.php' => <<<'PHP'
            <?php

            namespace App\Support;

            use Illuminate\Support\Facades\Cache;
            use Symfony\Component\HttpFoundation\Response;

            final class Thing
            {
                public function run(): void
                {
                    Cache::get(\Illuminate\Support\Str::random());
                }
            }
            PHP,
    ]);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

it('is not confused by a closure use or an imported function', function () {
    $package = dependencyPackage([
        'app/Support/Thing.php' => <<<'PHP'
            <?php

            namespace App\Support;

            use function array_map;
            use const PHP_EOL;

            final class Thing
            {
                public function run(array $rows): array
                {
                    $prefix = 'x';

                    return array_map(function ($row) use ($prefix) {
                        return $prefix . $row . PHP_EOL;
                    }, $rows);
                }
            }
            PHP,
    ]);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

it('does not judge a package on a name it never writes down', function () {
    /*
     * A documented limit, pinned so that nobody later reads a green result as a
     * promise the scan never made. `app('...')` and `new $class` are resolved
     * at run time from data; there is no name in the source to check and this
     * accepts the package. The class docblock on ClassDependencyScan lists this
     * and the rest of what it cannot see.
     */
    $package = dependencyPackage([
        'app/Support/Thing.php' => <<<'PHP'
            <?php

            namespace App\Support;

            final class Thing
            {
                public function run(): mixed
                {
                    $class = 'App\Services\ResolvedAtRunTime';

                    return app($class);
                }
            }
            PHP,
    ]);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

/* ═══════════════════════════════════════════ the scan, directly ═══════════ */

it('reports what it scanned and what it could not scan properly', function () {
    $dir = sys_get_temp_dir().'/kbb-dep-scan-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);

    file_put_contents($dir.'/a.php', "<?php\n\nnamespace App\Support;\n\nfinal class A {}\n");
    file_put_contents($dir.'/b.blade.php', '{{ \App\Support\A::go() }}');

    $result = (new ClassDependencyScan($dir))->run([
        'app/Support/A.php' => $dir.'/a.php',
        'resources/views/b.blade.php' => $dir.'/b.blade.php',
    ]);

    expect($result['scanned'])->toBe(2)
        ->and($result['provided'])->toContain('App\Support\A')
        ->and($result['missing'])->toBe([])
        ->and($result['degraded'])->toBe([]);
});

it('checks the server by PSR-4 path and never by autoloading', function () {
    /*
     * class_exists() autoloads, which includes and executes the top level of a
     * file this check has not vetted. A verifier that runs the code it is
     * verifying is not a verifier -- and a package is, by definition, code
     * that has not been trusted yet.
     */
    $code = dependencyScanCodeWithoutComments();

    expect($code)->not->toContain('class_exists')
        ->and($code)->not->toContain('include')
        ->and($code)->not->toContain('require')
        ->and($code)->not->toContain('eval(');
});

/** The scan's source with every comment removed, so the prose in its docblocks
 *  explaining what it does NOT do cannot be mistaken for it doing it. */
function dependencyScanCodeWithoutComments(): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents(app_path('Services/Update/ClassDependencyScan.php'))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

it('states its own limits where somebody reading the result will find them', function () {
    // Rule 7, and the brief: a check that is right about what it covers and
    // says what it does not is worth more than one that implies completeness.
    $source = file_get_contents(app_path('Services/Update/ClassDependencyScan.php'));

    expect($source)
        ->toContain('WHAT IT DOES NOT COVER')
        ->toContain('DYNAMIC REFERENCES')
        ->toContain('UNQUALIFIED NAMES RESOLVED BY THE CURRENT NAMESPACE')
        ->toContain('MEMBERS');
});

/* ══════════════════════════ the real tree must pass its own check ═════════ */

it('accepts a package built out of this repository', function () {
    /*
     * The check has to be true of real files, not only of fixtures. These are
     * genuine storefront sources with genuine imports; if the scan cannot read
     * this project's own code without complaining, it would refuse every
     * package the project ships.
     */
    $real = [
        'app/Services/Update/StorefrontHealth.php',
        'app/Services/Update/UpdateRunner.php',
        'app/Http/Controllers/Store/ProductController.php',
        'resources/views/layouts/store.blade.php',
        'resources/views/components/product-card.blade.php',
    ];

    $files = [];

    foreach ($real as $relative) {
        $files[$relative] = base_path($relative);
    }

    $result = (new ClassDependencyScan(base_path()))->run($files);

    expect($result['missing'])->toBe([], 'the scan flagged a class this repository actually has')
        ->and($result['degraded'])->toBe([], 'a real Blade template in this project fell back to the regex')
        ->and($result['scanned'])->toBe(count($real));
});
