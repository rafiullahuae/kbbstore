<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\FrontEndStrings;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

/**
 * Lane FB — the skin quiz's inline script, and the half of it that must stay
 * English on purpose.
 *
 * ── THE PAGE IS NOT SHAPED LIKE THE REST OF THE SHOP ────────────────────────
 *
 * /skin-quiz is a standalone document. It does not extend layouts.store, so it
 * never included partials/js-strings.blade.php and window.KBB_T did not exist
 * on it; and it builds its entire interface inside one inline <script>, which
 * cannot `import` resources/js/kbb/i18n.js because that is an ES module
 * compiled into the bundle. So the conversion needed two things the rest of the
 * storefront did not: a copy of t() in the page, and a table of its own.
 *
 * The table is its own for a reason worth stating. FrontEndStrings::forLocale()
 * ships EVERY store.js.* key to EVERY page — 33 of them today. The quiz has ~90.
 * Putting them in the shared group would nearly quadruple that table on every
 * Arabic page in the shop, to carry strings only this one page says.
 *
 * ── AND THE HALF THAT IS DELIBERATELY NOT CONVERTED ─────────────────────────
 *
 * Every value the shopper PICKS — skin type, concerns, age, routine depth,
 * budget, allergens — is used three ways at once:
 *
 *   as the label drawn on the option;
 *   as the comparison that drives the recommendation
 *     (`state.concerns.includes('Hydration')`, `/Oily|Combination/.test(...)`);
 *   and as the ANSWER POSTED to /api/quiz and kept in quiz_submissions.
 *
 * Translating them breaks recommend() outright, and writes Arabic answers into
 * a table the owner reads in English — two shoppers who picked the same thing
 * would be recorded as having picked different things. This is the defect
 * store/blog.blade.php has with its 'All' chip, one order of magnitude larger
 * and with a persistence contract attached. It is reported, not fixed here, and
 * the last case below PINS the English so a later lane cannot translate them by
 * accident without this test going red.
 */
function fbQuizSource(): string
{
    return (string) file_get_contents(resource_path('views/store/skin-quiz.blade.php'));
}

function fbQuizArabicOn(): void
{
    Setting::query()->updateOrCreate(
        ['key' => Locale::SETTING_ENABLED],
        ['value' => '1', 'autoload' => true]
    );

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

it('emits no string table on the English quiz, so the page is what it was', function () {
    $html = $this->get('/skin-quiz')->assertOk()->getContent();

    expect($html)->not->toContain('window.KBB_T = {');
});

it('gives the Arabic quiz its own table and says the chrome in Arabic', function () {
    fbQuizArabicOn();

    TranslationStore::put('ar', 'ui', 0, 'store.quiz.js_start_title', 'اكتشفي إشراقتك.',
        Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL);
    TranslationStore::put('ar', 'ui', 0, 'store.quiz.js_start_cta', 'ابدئي الاختبار',
        Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL);

    $html = $this->get('/ar/skin-quiz')->assertOk()->getContent();

    expect($html)->toContain('window.KBB_T = {');

    /*
     * DECODED, NOT GREPPED. Blade's @json() uses json_encode's default flags,
     * so every Arabic character is emitted as a \uXXXX escape — correct
     * JavaScript, and invisible to a substring search for the word itself.
     * partials/js-strings.blade.php emits the shared table the same way. A test
     * that looked for the Arabic literal here would fail against a page that is
     * working perfectly, which is how this one was first written.
     */
    preg_match('/window\.KBB_T = (\{.*?\});<\/script>/s', $html, $m);

    $table = json_decode($m[1] ?? '{}', true);

    expect($table)->toBeArray()
        ->and($table['store.quiz.js_start_title'] ?? null)->toBe('اكتشفي إشراقتك.')
        ->and($table['store.quiz.js_start_cta'] ?? null)->toBe('ابدئي الاختبار')
        // and a key the owner has NOT translated still carries its English, so
        // the page is never blank where a translation is missing.
        ->and($table['store.quiz.js_continue'] ?? null)->toBe('Continue');
});

it('keeps the quiz out of the table every other page downloads', function () {
    /*
     * The bloat guard. A quiz key that leaks into the store.js.* group is
     * shipped to every Arabic page in the shop.
     */
    $shared = array_keys(FrontEndStrings::forPrefix('js.', 'ar'));

    $leaked = array_values(array_filter(
        $shared,
        fn (string $key): bool => str_contains($key, 'quiz')
    ));

    expect($leaked)->toBe([], 'Quiz strings have leaked into the shared front-end table: '
        . implode(', ', $leaked));

    // And the quiz's own table is not empty, or the case above passes for the
    // wrong reason.
    expect(FrontEndStrings::forPrefix('quiz.js_', 'ar'))->not->toBe([]);
});

it('writes the same English at the call site as in the strings table', function () {
    /*
     * THE DRIFT GUARD, and on this page it is the one that matters most: t()
     * carries its English as the fallback, so a call site whose English has
     * been edited and whose key has not goes on rendering correctly in English
     * and renders a translation of a sentence nobody says any more in Arabic.
     * Nothing would show that but this.
     */
    $source = fbQuizSource();

    preg_match_all(
        '/t\(\'(store\.quiz\.js_[a-z0-9_]+)\',\s*(\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/',
        $source,
        $m,
        PREG_SET_ORDER
    );

    expect(count($m))->toBeGreaterThan(40);

    $wrong = [];

    foreach ($m as $hit) {
        $key = $hit[1];
        $raw = $hit[3] !== '' || ! isset($hit[4]) ? $hit[3] : $hit[4];
        $english = str_replace(["\\'", '\\"'], ["'", '"'], $raw);

        $table = InterfaceStrings::english($key);

        if ($table !== $english) {
            $wrong[$key] = sprintf('call site %s / table %s', var_export($english, true), var_export($table, true));
        }
    }

    expect($wrong)->toBe([], "A quiz call site's English does not match InterfaceStrings:\n"
        . implode("\n", array_map(fn ($k, $v) => "  {$k}: {$v}", array_keys($wrong), $wrong)));
});

it('has a key for every routine and every step the script can render', function () {
    /*
     * These two are looked up by a key BUILT AT RUNTIME
     * ('store.quiz.js_step_' + step.k + '_name'), so the scan above cannot see
     * them and a missing one would simply fall back to English for ever.
     */
    $source = fbQuizSource();

    preg_match_all("/(?:nk|dk|tk|gk):'(store\.quiz\.js_[a-z0-9_]+)'/", $source, $keys);

    // 11 steps x 2 + 3 routines x 3 = 31.
    expect($keys[1])->toHaveCount(31);

    $missing = [];

    foreach (array_unique($keys[1]) as $key) {
        if (InterfaceStrings::english($key) === null) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe([], 'Runtime-built quiz keys with no English source: ' . implode(', ', $missing));
});

it('leaves every answer the shopper picks in English, because it is a stored value', function () {
    /*
     * THE SAFETY PROPERTY. These are the option arrays. If a later lane wraps
     * them in t() the quiz stops recommending correctly and the leads table
     * starts collecting two vocabularies for one answer. This case is what
     * stops that being a silent change.
     */
    $source = fbQuizSource();

    foreach (["const SKINS=[", "const CONCERNS=[", "const AGES=[", "const DEPTHS=[", "const BUDGETS=[", "const ALLERGENS=["] as $marker) {
        expect($source)->toContain($marker);
    }

    // The literal values the recommendation branches compare against.
    foreach (['Hydration', 'Fine lines & aging', 'Redness & sensitivity', 'Oily', 'Combination', 'Dry', 'Sensitive'] as $value) {
        expect($source)->toContain("'" . $value . "'");
    }

    // None of the option arrays may route through t().
    preg_match('/const SKINS=\[.*?\];/s', $source, $skins);
    preg_match('/const CONCERNS=\[.*?\];/s', $source, $concerns);

    expect($skins[0])->not->toContain('t(')
        ->and($concerns[0])->not->toContain('t(');
});
