<?php

declare(strict_types=1);

use App\Services\Translation\FrontEndStrings;
use App\Services\Translation\InterfaceStrings;
use Tests\Support\BladeProse;

/**
 * No shopper-facing Blade may still carry a bare English sentence (Lane EU, T2).
 *
 * ── WHY A SCANNER AND NOT A LIST ────────────────────────────────────────────
 *
 * The conversion is finished the day it is done and unfinished the day after,
 * because the next lane writes a new page and types the words straight into it.
 * A test that asserted "these 95 files contain no English" would pass forever
 * while file 96 shipped in English only. So this walks the DIRECTORY: every
 * shopper-facing Blade in the tree, including the ones nobody has written yet.
 *
 * Tests\Support\BladeProse does the reading, and its header explains why the
 * order of the stripping is the whole job.
 *
 * ── THE EXCLUSIONS ──────────────────────────────────────────────────────────
 *
 * Every one is named below with its reason, and the list is short on purpose:
 * an exclusion is a promise that the words behind it are not words a shopper
 * reads in English, and each one is the sort of promise that has to be argued
 * rather than assumed. Two are whole files that a shopper cannot reach; the
 * rest are the shop's own wordmark, the names of payment companies, and one set
 * of initials.
 *
 * ── AND THE PROOF THAT IT FAILS ─────────────────────────────────────────────
 *
 * The second test in this file puts a raw sentence into a real storefront
 * template on disk, runs the scanner over it, and asserts the sentence is
 * reported — then puts the file back. Four guards on this repository were found
 * asserting nothing while being counted as coverage; this one says out loud
 * what it would catch.
 */

/** Files a shopper cannot reach, so their English is nobody's to read. */
function keyedExcludedFiles(): array
{
    return [
        'welcome.blade.php'
            => "Laravel's own install page. No route renders it — checked against routes/*.php — and it talks about Laracasts.",
        'store/app.blade.php'
            => '/app, served to an authenticated admin and 404 to everybody else (PublicPagesQuoteRealPricesTest pins both halves). It is a second, invented storefront with invented prices; translating it would be translating a fixture.',
        'invoices/partials/page-dispatch-label.blade.php'
            => "One @page rule and nothing else. It used to sit inside @section('page') in invoices/shipping-label.blade.php, which BladeProse blanks by name; Lane GC moved it into a partial so the single label and a BULK run of labels cannot end up with two answers to how big A6 is. Same bytes, same output, no prose — the scanner reads a CSS at-rule as a sentence, which is the failure this exclusion is for.",
        'invoices/partials/style-dispatch-label.blade.php'
            => "The dispatch label's stylesheet, 26 lines of CSS and not one word. Moved out of @section('style') in invoices/shipping-label.blade.php by Lane GC for the same reason as the file above, and excluded for the same reason: BladeProse blanks that section by name and cannot tell that a file holding only its body is still CSS. docs/invoice-previews/dispatch-label.html regenerates byte-for-byte across the move, which is the check that it is the same stylesheet.",
        'store/skin-quiz.blade.php'
            => "The script is converted now (Lane FB): ~90 keys under store.quiz.js_*, its own window.KBB_T, and the invented catalogue deleted. Still excluded for what REMAINS English on purpose — the skin types, concerns, ages, depths, budgets and allergens the shopper picks, which are compared by recommend() and POSTed to /api/quiz as the lead's answers. QuizScriptStringsAreKeyedTest pins both halves: the converted chrome, and those values staying English.",
    ];
}

/**
 * Runs of text that are not English words, keyed by the exact text.
 *
 * @return array<string, string> text => why it is not translated
 */
function keyedAllowedText(): array
{
    return [
        // partials/footer, partials/drawers, partials/header-slim,
        // store/blog, store/post, store/checkout-success
        'K-Beauty      Bliss'
            => "The wordmark, split across a <span> so the second half takes the brand colour. A logo is not translated — docs/BILINGUAL-PLAN.md says so in the owner's half.",
        'KB'
            => 'store/account/login: the two initials drawn in the sign-in card, the same wordmark shortened.',
        // partials/footer, store/cart-inner, store/product
        'Tabby             Tamara             Visa             Mastercard             Apple Pay'
            => 'The payment badges. Company names: a translated one is a different company. "COD" beside them IS translated, because it is an English abbreviation.',
        'Tabby             Tamara             Visa             Mastercard             Apple Pay             COD'
            => 'As above.',
        'Visa             Mastercard             Tabby             Tamara             Apple Pay'
            => 'As above, in the cart page\'s order.',
        'Apple&nbsp;Pay'
            => 'store/checkout: the express-pay button. A company name.',
        'G                          o                          o                          g                           l                          e           &nbsp;Pay'
            => 'store/checkout: the Google Pay wordmark, one <span> per letter so each takes its own colour.',
        // invoices/document — the SAMPLE banner, which only ever renders on an
        // order created from Safety → Demo Content → Sample order.
        'SAMPLE ORDER &mdash; NOT A REAL ORDER. NOTHING WAS BOUGHT, PAID FOR OR SHIPPED.'
            => "No shopper ever reads it. A sample order is never sent to one — OrderMailer refuses every send for it — so this sheet is only ever read by the operator, in the operator's language, which is the same argument the print toolbar above it carries. Keying it would make it worse: on an Arabic order with no Arabic published the key would fall back to English anyway, and on one with Arabic published the shop's most important warning would print in a language the person holding it may not read. It is a literal for the reason CLAUDE.md gives for constants, and it carries dir=\"ltr\" so an RTL sheet does not move its full stop.",
        '&middot; created from Safety &rarr; Demo Content &rarr; Sample order, and removed from the same place.'
            => 'The second line of the same banner: where the operator goes to delete it. Same argument, and it names an admin path, which is English on every screen of this console.',
    ];
}

/** Every shopper-facing Blade in the tree. */
function keyedStorefrontBlades(): array
{
    $root = resource_path('views');
    $files = [];

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($walk as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());

        // The back office is T8 and the owner has deferred it.
        if (str_starts_with($relative, 'admin' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $files[$relative] = $file->getPathname();
    }

    ksort($files);

    return $files;
}

it('has no bare English left in any shopper-facing Blade', function () {
    $excluded = keyedExcludedFiles();
    $allowed = keyedAllowedText();
    $files = keyedStorefrontBlades();

    // The walk has to be walking the storefront, not an empty directory.
    expect(count($files))->toBeGreaterThan(90);

    $misses = [];
    $scanned = 0;

    foreach ($files as $relative => $path) {
        if (isset($excluded[$relative])) {
            continue;
        }

        $scanned++;

        foreach (BladeProse::find($path) as $hit) {
            if (isset($allowed[$hit['text']])) {
                continue;
            }

            $misses[] = sprintf(
                '%s:%d (%s) — %s',
                $relative,
                $hit['line'],
                $hit['kind'],
                mb_substr($hit['text'], 0, 120)
            );
        }
    }

    expect($scanned)->toBeGreaterThan(90);

    expect($misses)->toBe([], sprintf(
        "These read as English to a shopper and do not go through __():\n  %s\n\n"
        . "Put each one in App\\Services\\Translation\\InterfaceStrings and call __() for it.\n"
        . 'If it is genuinely not a word a shopper reads, add it to keyedAllowedText() WITH ITS REASON.',
        implode("\n  ", $misses)
    ));
});

/**
 * The guard has to be able to fail. This puts a raw sentence into a real
 * storefront template, scans it, and asserts it is reported — then restores the
 * file byte for byte.
 */
it('reports a raw English sentence put back into a converted template', function () {
    $path = resource_path('views/store/cart-inner.blade.php');
    $original = file_get_contents($path);

    expect($original)->toBeString()->and($original)->toContain("__('store.cart.empty_heading')");

    // Clean before: the file is converted, so the only thing the scanner has to
    // say about it is the payment-badge row, which is on the allow list.
    $before = array_filter(
        BladeProse::find($path),
        fn (array $hit): bool => ! isset(keyedAllowedText()[$hit['text']])
    );
    expect($before)->toBe([], 'This test needs a file the guard is quiet about to start from.');

    $sentinel = 'Free gift with every order this week';

    try {
        file_put_contents($path, str_replace(
            "<b>{{ __('store.cart.empty_heading') }}</b>",
            "<b>{{ __('store.cart.empty_heading') }}</b>\n        <p>" . $sentinel . '</p>',
            $original
        ));

        $after = BladeProse::find($path);
        $texts = array_column($after, 'text');

        expect(in_array($sentinel, $texts, true))
            ->toBeTrue('The guard did not see a raw English sentence, so it is asserting nothing.');
    } finally {
        file_put_contents($path, $original);
    }

    // And the file really is back.
    expect(file_get_contents($path))->toBe($original);
});

/**
 * Every key a template asks for must exist, and every front-end key must carry
 * the same English at its call site as it does here.
 *
 * The first half is what the byte-identity tests cannot cover on their own: a
 * key used only on a page no test renders would show the shopper the key
 * itself. The second is the one duplication resources/js/kbb/i18n.js forces —
 * see App\Services\Translation\FrontEndStrings — and it is checked rather than
 * trusted.
 */
it('defines every key the templates and the scripts ask for', function () {
    $defined = InterfaceStrings::flat();

    $sources = [];
    foreach (keyedStorefrontBlades() as $relative => $path) {
        $sources[$relative] = file_get_contents($path);
    }
    foreach (glob(resource_path('js/kbb/*.js')) as $path) {
        $sources['js/' . basename($path)] = file_get_contents($path);
    }

    $missing = [];
    $used = [];

    foreach ($sources as $where => $source) {
        if (! preg_match_all(
            "/(?:__|trans_choice|\\bt)\\(\\s*'((?:store|email|invoice)\\.[a-z0-9_.]+)'/",
            (string) $source,
            $found
        )) {
            continue;
        }

        foreach ($found[1] as $key) {
            $used[$key] = true;

            if (! array_key_exists($key, $defined)) {
                $missing[] = $where . ' → ' . $key;
            }
        }
    }

    expect(count($used))->toBeGreaterThan(400);
    expect(array_values(array_unique($missing)))->toBe([], "These keys are asked for and not defined:\n  "
        . implode("\n  ", array_unique($missing)));

    /*
     * And the front-end's fallbacks. Each t() call carries the English as its
     * second argument, because the shipped bundle is routinely older than this
     * repository; if the two ever disagree, the shop says one thing and the
     * admin screen offers to translate another.
     */
    $drift = [];

    foreach (glob(resource_path('js/kbb/*.js')) as $path) {
        $source = (string) file_get_contents($path);

        if (! preg_match_all("/\\bt\\(\\s*'([a-z0-9_.]+)'\\s*,\\s*'((?:\\\\.|[^'\\\\])*)'/s", $source, $found, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($found as [$whole, $key, $english]) {
            $english = str_replace(["\\'", '\\\\'], ["'", '\\'], $english);

            if (! array_key_exists($key, $defined)) {
                $drift[] = basename($path) . ' → ' . $key . ' is not defined at all';

                continue;
            }

            if ($defined[$key] !== $english) {
                $drift[] = sprintf(
                    '%s → %s: the script says %s, InterfaceStrings says %s',
                    basename($path),
                    $key,
                    var_export($english, true),
                    var_export($defined[$key], true)
                );
            }
        }
    }

    expect($drift)->toBe([], "The front-end fallbacks and the English source have drifted apart:\n  "
        . implode("\n  ", $drift));

    // And the table the browser is sent is the js.* group and nothing else.
    expect(FrontEndStrings::forLocale('en'))->toBe([], 'An English page must be sent no string table at all.');
    expect(count(FrontEndStrings::keys()))->toBeGreaterThan(25);
    foreach (FrontEndStrings::keys() as $key) {
        expect($key)->toStartWith('store.js.');
    }
});
