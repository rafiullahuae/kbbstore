<?php

declare(strict_types=1);

namespace App\Services\Update;

use Illuminate\Support\Facades\Blade;

/**
 * Refuses a package that installs code whose classes are not there yet.
 *
 * WHY THIS EXISTS, 24 September 2026. Package 2.60.260 shipped three Blade
 * templates that resolve `App\Services\VariantPricing`. The class itself
 * shipped in 2.60.259 -- which had not been applied. The templates landed, the
 * class did not, and every page that rendered one of them fatal-errored. The
 * package reported "applied". Nothing anywhere in the updater noticed that it
 * had just installed a caller without its callee, because nothing anywhere in
 * the updater had ever looked inside a packaged file.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES
 * ---------------------------------------------------------------------------
 *
 * For every PHP and Blade file a package carries, it collects the `App\` class
 * names that file names, and requires each one to be EITHER shipped in the same
 * package OR already present on the server. Anything else is a class that will
 * not exist at the moment the new code runs, which is a fatal error on a page,
 * not a warning in a log.
 *
 * Nothing is executed. The package's files are read as text and handed to PHP's
 * own tokeniser; Blade templates are compiled to PHP first, which is a pure
 * string transformation and runs none of the template. A package is inspected
 * with exactly as much authority as `cat` has.
 *
 * Presence on the server is decided by the PSR-4 path -- `App\Services\Foo` is
 * `app/Services/Foo.php` -- and NOT by class_exists(), which autoloads, which
 * means including and executing the top level of a file this check has not
 * vetted. A verifier that runs the code it is verifying is not a verifier.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES NOT COVER, AND THIS LIST IS THE POINT
 * ---------------------------------------------------------------------------
 *
 * This is a token-level scan. It sees names, not meanings. It is correct about
 * what it reports and silent about a great deal, and it is worth having on
 * exactly those terms -- 2.60.260 is in the half it catches. What it misses:
 *
 *   - DYNAMIC REFERENCES. `app('some.binding')`, `new $class`, a class name
 *     assembled from a string or read out of config or the database. None of
 *     these are a name in the source and none can be resolved without running
 *     the program.
 *
 *   - UNQUALIFIED NAMES RESOLVED BY THE CURRENT NAMESPACE. A file in
 *     `namespace App\Services;` that writes `new Helper()` with no `use` is
 *     naming `App\Services\Helper`, and this does not see it. Only `use`
 *     statements and names written out in full are collected.
 *
 *   - MEMBERS. It checks that a CLASS will exist, never that it carries the
 *     method, constant or property being used. A package that calls a method
 *     added in a package that was never applied still passes.
 *
 *   - ANYTHING OUTSIDE `App\`. Framework and vendor classes are not shipped by
 *     packages -- UpdateGuard forbids writing vendor/ at all -- so they are
 *     assumed present. A package that needs a newer Composer dependency is a
 *     different problem and this is not its check.
 *
 *   - NON-CLASS DEPENDENCIES. A view a controller renders, a route it names, a
 *     config key it reads, a migration it assumes has run. All invisible here.
 *
 *   - A BLADE TEMPLATE THIS PROJECT'S BLADE CANNOT COMPILE. It is then scanned
 *     with a regex instead, which cannot tell a live reference from one in a
 *     comment, so such files are scanned for FULLY QUALIFIED names only and
 *     listed in `degraded` so the fallback is visible rather than assumed.
 *
 * The honest summary: a clean result means "no packaged file names an `App\`
 * class that is missing", which is a smaller claim than "this package will
 * work". It is still the claim that would have stopped the outage.
 */
final class ClassDependencyScan
{
    /** Files whose contents are worth reading. */
    private const SCANNED_EXTENSIONS = ['php'];

    /** Never report more than this, for the same reason UpdateGuard caps its list. */
    private const MAX_REPORTED = 25;

    public function __construct(private string $appRoot) {}

    /**
     * @param  array<string, string>  $files  relative path => absolute path of the extracted copy
     * @return array{
     *     missing: array<int, array{class: string, referenced_by: string}>,
     *     provided: array<int, string>,
     *     scanned: int,
     *     degraded: array<int, string>,
     * }
     */
    public function run(array $files): array
    {
        $php = array_filter(
            $files,
            static fn (string $absolute, string $relative): bool => in_array(
                strtolower(pathinfo($relative, PATHINFO_EXTENSION)),
                self::SCANNED_EXTENSIONS,
                true,
            ),
            ARRAY_FILTER_USE_BOTH,
        );

        $provided = [];
        $sources = [];
        $degraded = [];

        foreach ($php as $relative => $absolute) {
            $source = @file_get_contents($absolute);

            if (! is_string($source)) {
                continue;
            }

            $sources[$relative] = $source;

            // What the package itself brings. Read out of the file rather than
            // inferred from its path, so a file whose name and class disagree
            // is judged on what it actually declares.
            foreach ($this->declaredClasses($source) as $class) {
                $provided[$class] = true;
            }
        }

        $missing = [];
        $seen = [];

        foreach ($sources as $relative => $source) {
            [$references, $wasDegraded] = $this->referencesIn($relative, $source);

            if ($wasDegraded) {
                $degraded[] = $relative;
            }

            foreach ($references as $class) {
                if (! str_starts_with($class, 'App\\')) {
                    continue;
                }

                if (isset($provided[$class]) || isset($seen[$class])) {
                    continue;
                }

                if ($this->existsOnServer($class)) {
                    continue;
                }

                $seen[$class] = true;
                $missing[] = ['class' => $class, 'referenced_by' => $relative];
            }
        }

        usort($missing, static fn (array $a, array $b): int => $a['class'] <=> $b['class']);

        return [
            'missing' => array_slice($missing, 0, self::MAX_REPORTED),
            'provided' => array_keys($provided),
            'scanned' => count($sources),
            'degraded' => $degraded,
        ];
    }

    /**
     * Is this class already installed?
     *
     * PSR-4 only: composer.json maps `App\` onto `app/` and nothing else, so
     * the file a class must live in is arithmetic rather than a guess. No
     * autoload, no class_exists — see the class docblock.
     */
    private function existsOnServer(string $class): bool
    {
        $relative = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';

        return is_file(rtrim($this->appRoot, '/').'/'.$relative);
    }

    /**
     * The classes, interfaces, traits and enums a source file declares.
     *
     * @return array<int, string>
     */
    private function declaredClasses(string $source): array
    {
        $tokens = @token_get_all($source);

        if (! is_array($tokens)) {
            return [];
        }

        $namespace = '';
        $declared = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = trim($this->readName($tokens, $i + 1, $count), '\\');

                continue;
            }

            if (! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            // `Foo::class` and an anonymous `new class {}` are both T_CLASS and
            // neither declares a name.
            $previous = $this->significantBefore($tokens, $i);

            if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                continue;
            }

            $name = $this->readName($tokens, $i + 1, $count);

            if ($name === '') {
                continue;
            }

            $declared[] = $namespace === '' ? $name : $namespace.'\\'.$name;
        }

        return $declared;
    }

    /**
     * Every class name a source file mentions.
     *
     * @return array{0: array<int, string>, 1: bool}  names, and whether the
     *                                                regex fallback was used
     */
    private function referencesIn(string $relative, string $source): array
    {
        if (str_ends_with(strtolower($relative), '.blade.php')) {
            try {
                // A pure string transformation. Nothing in the template runs.
                $source = Blade::compileString($source);
            } catch (\Throwable) {
                return [$this->fullyQualifiedByRegex($source), true];
            }
        }

        $tokens = @token_get_all($source);

        if (! is_array($tokens)) {
            return [$this->fullyQualifiedByRegex($source), true];
        }

        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAME_FULLY_QUALIFIED) {
                $found[] = ltrim($token[1], '\\');

                continue;
            }

            if ($token[0] === T_USE) {
                $i = $this->collectUse($tokens, $i + 1, $count, $found);
            }
        }

        return [array_values(array_unique($found)), false];
    }

    /**
     * Walks one `use` statement and adds every class it imports.
     *
     * Handles the plain form, the comma form, the braced group form and `as`
     * aliases. Skips `use function` and `use const`, which import no class, and
     * a closure's `use (...)`, which imports no name at all. A trait `use`
     * inside a class body is NOT skipped — a trait the server has not got is
     * every bit as fatal as a missing parent class.
     *
     * @param  array<int, string>  $found
     * @return int  the index to continue the outer loop from
     */
    private function collectUse(array $tokens, int $start, int $count, array &$found): int
    {
        $i = $start;

        while ($i < $count && is_array($tokens[$i]) && $this->isTrivia($tokens[$i][0])) {
            $i++;
        }

        if ($i >= $count) {
            return $count;
        }

        // A closure's `use (...)`, or an importless `use function` / `use const`.
        if ($tokens[$i] === '(') {
            return $i;
        }

        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_FUNCTION, T_CONST], true)) {
            return $i;
        }

        $prefix = '';
        $current = '';
        $afterAs = false;

        $flush = static function () use (&$prefix, &$current, &$afterAs, &$found): void {
            $name = ltrim($prefix.$current, '\\');

            if ($name !== '' && ! str_ends_with($name, '\\')) {
                $found[] = $name;
            }

            $current = '';
            $afterAs = false;
        };

        for (; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ';' || ($token === '{' && $current === '')) {
                // A `{` with nothing collected is a class body, not a group —
                // this was a trait use with no import list left to read.
                $flush();

                return $i;
            }

            if ($token === '{') {
                $prefix = $current;
                $current = '';
                $afterAs = false;

                continue;
            }

            if ($token === '}') {
                $flush();
                $prefix = '';

                return $i;
            }

            if ($token === ',') {
                $flush();

                continue;
            }

            if (! is_array($token)) {
                continue;
            }

            if ($this->isTrivia($token[0])) {
                continue;
            }

            if ($token[0] === T_AS) {
                $afterAs = true;

                continue;
            }

            if ($afterAs) {
                continue;
            }

            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                $current .= $token[1];
            }
        }

        $flush();

        return $count;
    }

    /**
     * The fallback for a file PHP's tokeniser will not take.
     *
     * Fully qualified names only. Without tokens there is no way to tell a
     * reference from a mention in a comment, and a check that refuses a package
     * over a docblock is a check that gets switched off.
     *
     * @return array<int, string>
     */
    private function fullyQualifiedByRegex(string $source): array
    {
        preg_match_all('/\\\\App(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $source, $matches);

        return array_values(array_unique(array_map(
            static fn (string $name): string => ltrim($name, '\\'),
            $matches[0],
        )));
    }

    private function readName(array $tokens, int $start, int $count): string
    {
        $name = '';

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                break;
            }

            if ($this->isTrivia($token[0])) {
                if ($name !== '') {
                    break;
                }

                continue;
            }

            if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                break;
            }

            $name .= $token[1];
        }

        return $name;
    }

    private function significantBefore(array $tokens, int $index): mixed
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && $this->isTrivia($tokens[$i][0])) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    private function isTrivia(int $type): bool
    {
        return in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
