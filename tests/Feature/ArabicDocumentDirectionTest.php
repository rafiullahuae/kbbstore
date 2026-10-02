<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Illuminate\Support\Facades\Cache;

/**
 * WHICH WAY EVERY SHOPPER-FACING DOCUMENT SAYS IT READS. (Lane AR2, task 2)
 *
 * ── THE QUESTION THIS SETTLES ──────────────────────────────────────────────
 *
 * Lane AR was carrying "does /ar render dir=ltr somewhere it should not?" and
 * was killed before it answered. Answered here, by rendering the pages rather
 * than by reading them:
 *
 *   ON /ar ITSELF, NO. With both switches on, every storefront document
 *   declares dir="rtl", and the ONLY elements carrying dir="ltr" anywhere in
 *   any of them are the price spans -- `.woocommerce-Price-amount`, which is
 *   deliberate and is recorded as deliberate in docs/UGC-RAIL-R3.md:108-113.
 *   A price is a run of digits and a currency code; it reads left-to-right in
 *   an Arabic sentence exactly as it does in an English one. Case 2 below is
 *   what stops a SECOND, undeliberate dir="ltr" joining them unnoticed.
 *
 *   THE DEFECT WAS ONE DOCUMENT FURTHER OUT. Order email bodies had no
 *   direction at all -- not an attribute, not a `direction:` declaration,
 *   nothing, anywhere under resources/views/emails. emails/layout.blade.php
 *   carries no <html> element by design, so the place the rest of the shop
 *   says which way it reads simply did not exist for a message. Meanwhile
 *   OrderMailer renders the body through OrderLocale in the language the
 *   ORDER was placed in. So an Arabic customer's order confirmation arrived
 *   in Arabic words, laid out left to right, in every mail client -- because
 *   left-to-right is what a client assumes when nothing tells it otherwise.
 *
 *   That is the same root as the question: this application declares
 *   direction in exactly three places -- layouts/store.blade.php, and the two
 *   invoice documents -- and a fourth kind of document had been added without
 *   one.
 *
 * ── MUTATIONS ──────────────────────────────────────────────────────────────
 *
 *   - delete `{!! $kbbMailDir !!}` from the outer table in
 *     emails/layout.blade.php     -> case 3 red ("an Arabic order email
 *     declares no direction at all")
 *   - make $kbbMailDir unconditional (drop the === 'rtl' test)
 *                                 -> case 4 red: the English email grew a dir
 *   - hard-code dir="ltr" on any storefront element
 *                                 -> case 2 red, naming the tag
 *   - take dir off <html> in layouts/store.blade.php, or point it at the
 *     language instead of Locale::direction()
 *                                 -> case 1 red
 */

/** Both switches on: Arabic words AND the mirrored layout. */
function adirOn(bool $rtl = true): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => $rtl ? '1' : '0', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    Cache::flush();
}

/**
 * The storefront surfaces this case walks, as uri => name.
 *
 * Only pages that answer 200 to a visitor with no basket and no session, so
 * that a redirect is a failure rather than a skip.
 */
function adirPages(): array
{
    return [
        '/ar/' => 'the home page',
        '/ar/shop/' => 'the shop',
        '/ar/cart/' => 'the basket',
        '/ar/blog/' => 'the Journal',
        '/ar/skin-quiz/' => 'the skin quiz',
        '/ar/reviews/' => 'the review wall',
    ];
}

/* ═══════════════ 1. every Arabic document says it reads right to left ══════ */

it('declares right-to-left on every Arabic storefront document', function () {
    adirOn();

    foreach (adirPages() as $uri => $name) {
        $html = $this->get($uri)->assertOk()->getContent();

        expect(preg_match('#<html\b[^>]*>#', $html, $m))->toBe(1, "No <html> element on {$name}.");
        // Lane RD's press-feedback attribute rides on the same tag; it is not
        // direction, and PressFeedbackTest pins it. Only its exact shape is
        // taken out, so anything else added to <html> is still seen here.
        $tag = (string) preg_replace('/ data-press="[a-e]"(?=>$)/', '', $m[0]);
        expect($tag)->toBe('<html lang="ar" dir="rtl">', "{$name} does not declare itself right-to-left: {$m[0]}");
    }
});

/* ═══════════ 2. and nothing inside them claims otherwise but the prices ════ */

it('carries no dir="ltr" on an Arabic page except the price spans', function () {
    /*
     * THE ASSERTION LANE AR WAS AFTER. A hard-coded dir="ltr" on any storefront
     * element pins that element to English inside a mirrored page, and it is
     * invisible in every English screenshot -- the English page is ltr anyway,
     * so the attribute changes nothing there and everything on /ar.
     *
     * The price spans are the documented exception and are matched by CLASS
     * rather than counted, so adding or removing a price from a page does not
     * move this assertion, while a dir="ltr" on anything else fails it.
     */
    adirOn();

    foreach (adirPages() as $uri => $name) {
        $html = $this->get($uri)->assertOk()->getContent();

        preg_match_all('#<[a-zA-Z][^>]*\bdir\s*=\s*"ltr"[^>]*>#', $html, $m);

        $unexpected = array_values(array_filter(
            $m[0],
            static fn (string $tag): bool => ! str_contains($tag, 'woocommerce-Price-amount')
        ));

        expect($unexpected)->toBe(
            [],
            "{$name} pins an element to left-to-right inside a right-to-left page: "
            .implode(' || ', array_slice($unexpected, 0, 3))
        );
    }
});

/* ══════════════════════ 3. and so does an order email ═════════════════════ */

it('declares right-to-left on an Arabic order email', function () {
    /*
     * THE DEFECT. Rendered rather than grepped, so it is the bytes a customer's
     * mail client receives that are being checked.
     *
     * The <table> is asserted rather than the whole body because the shell
     * carries no <html> of its own -- the outer table IS this document's root
     * element, and a dir on it is inherited by every row, cell and partial
     * inside it.
     */
    adirOn();
    app()->setLocale('ar');

    $body = (string) view('emails.layout', [
        'brand' => ['wordmark' => ['', '']],
        'slot' => '<p>مرحبًا</p>',
    ])->render();

    expect(preg_match('#<table\b[^>]*>#', $body, $m))->toBe(1, 'the email shell has no outer table');

    /*
     * str_contains() and toBeTrue(), NOT toContain(). Pest's toContain() is
     * VARIADIC -- every argument after the first is a further needle, not a
     * failure message -- so `toContain('dir="rtl"', 'explanation...')` quietly
     * asserts that the tag also contains the explanation, and fails naming the
     * sentence rather than the attribute. It cost this lane a debugging round:
     * the case went red, the code was right, and the message printed was the
     * message itself.
     */
    expect(str_contains($m[0], 'dir="rtl"'))->toBeTrue(
        'an Arabic order email declares no direction at all, so every mail client lays it out left to right: '.$m[0]
    );
});

/* ═════════ 4. and an English one is exactly the email it always was ═══════ */

it('leaves an English order email with no direction attribute at all', function () {
    /*
     * RULE 1. The fix must be invisible to an English shop, and "invisible"
     * here means literally zero bytes: $kbbMailDir appends an empty string, so
     * the outer table is character for character the one that shipped before.
     * A `dir="ltr"` written unconditionally would ALSO be correct HTML and
     * would churn every committed preview under docs/email-previews.
     */
    adirOn(false);
    app()->setLocale(Locale::DEFAULT);

    $body = (string) view('emails.layout', [
        'brand' => ['wordmark' => ['', '']],
        'slot' => '<p>Hello</p>',
    ])->render();

    expect(preg_match('#<table\b[^>]*>#', $body, $m))->toBe(1);

    // str_contains() for the reason case 3 gives: a variadic toContain() under
    // ->not passes for the wrong reason and would pin nothing at all here.
    expect(str_contains($m[0], 'dir='))->toBeFalse(
        'an English order email grew a direction attribute it never had: '.$m[0]
    );
});
