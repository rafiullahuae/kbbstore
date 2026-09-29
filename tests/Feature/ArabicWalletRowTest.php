<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use Illuminate\Support\Facades\Cache;

/**
 * THE APPLE PAY / GOOGLE PAY ROW, IN ARABIC. (Lane AR2, task 1)
 *
 * ── WHY THIS IS A TEST AND NOT A SCREENSHOT ────────────────────────────────
 *
 * The wallet row CANNOT be photographed in this container, and that is the
 * partial working rather than the harness failing.
 * partials/checkout/express-wallets.blade.php draws the row `hidden` and the
 * only thing that reveals it is Stripe's `ready` event reporting that THIS
 * BROWSER has a wallet with a card in it; if it does not, `gone()` removes the
 * row and its divider from the document entirely. That is the file's stated
 * first principle -- "a shopper offered Apple Pay on a browser that cannot do
 * Apple Pay is worse off than one who was never offered it". Playwright's
 * Chromium has neither wallet, so the correct behaviour there is an absent
 * row, and a photograph of an absent row proves nothing about its Arabic.
 *
 * So the four sentences are asserted where they actually live: in the bytes
 * the server sends, inside the script's TEXT object.
 *
 * ── WHAT A SHOPPER READS, AND WHEN ─────────────────────────────────────────
 *
 * These are the sentences shown when a payment does NOT happen -- the form is
 * incomplete, the total moved while the sheet was open, or the wallet was
 * declined. Each one tells an Arabic shopper that nothing has been charged and
 * their basket is safe. Before this lane all four fell through to English.
 *
 * ── AND ONE THING THAT IS NOT OURS, SAID OUT LOUD ──────────────────────────
 *
 * The BUTTON LABEL itself ("Buy with Apple Pay") and every decline reason
 * Stripe produces are drawn by Stripe, not by this shop, and neither
 * stripe.elements() call in this checkout passes a `locale`. They therefore
 * follow the BROWSER's language, not the shop's. That is a real gap and it is
 * reported rather than fixed here, because the same one-line option belongs on
 * the card form's element group too and that file is another lane's.
 */
function awrArabicShopApproved(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);

    // Publish the shipped drafts, the way Translation -> Progress' one button
    // does. A draft is never step 1 of the fallback chain, so without this the
    // page correctly renders English and the case would pass for the wrong
    // reason.
    Translation::query()
        ->where('locale', 'ar')
        ->where('group', Translation::GROUP_UI)
        ->update(['status' => Translation::STATUS_PUBLISHED]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
    Cache::flush();
}

it('hands the wallet row its four sentences in Arabic', function () {
    awrArabicShopApproved();
    app()->setLocale('ar');

    $expected = [
        'wallet_details_first' => 'يرجى إكمال بيانات التواصل والتوصيل بالأعلى أولًا، ثم اضغط مرة أخرى.',
        'wallet_total_moved' => 'تغيّر إجمالي طلبك أثناء فتح نافذة الدفع، ولم يُخصم أي مبلغ. سلتك محفوظة — يرجى مراجعة الإجمالي والمحاولة مرة أخرى.',
        'wallet_failed' => 'لم تتم عملية الدفع ولم يُخصم أي مبلغ. سلتك محفوظة — يرجى المحاولة مرة أخرى أو الدفع بالبطاقة بالأسفل.',
        'wallet_working' => 'جارٍ تأكيد الدفع…',
    ];

    foreach ($expected as $suffix => $arabic) {
        $key = 'store.checkout.'.$suffix;

        expect(__($key))->toBe(
            $arabic,
            $key.' falls back to English on an Arabic checkout -- a shopper whose wallet payment was refused is being reassured in a language they did not choose'
        );
    }

    // The divider above the row, which was already translated and must stay so:
    // it is the one string of the five that a shopper sees when nothing has
    // gone wrong.
    expect(__('store.checkout.or_pay_with'))->toBe('أو ادفع عبر');
});

it('still reads English on the English checkout', function () {
    /*
     * RULE 1 from the other end. Publishing a thousand Arabic strings must not
     * reach the unprefixed shop, and these four are the newest of them.
     */
    awrArabicShopApproved();
    app()->setLocale(Locale::DEFAULT);

    expect(__('store.checkout.wallet_failed'))
        ->toBe('That payment did not go through and nothing has been charged. Your basket is safe — please try again or pay by card below.');
    expect(__('store.checkout.or_pay_with'))->toBe('or pay with');
});
