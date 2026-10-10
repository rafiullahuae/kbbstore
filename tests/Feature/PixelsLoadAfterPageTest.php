<?php

declare(strict_types=1);

/**
 * 2.60.463. The owner, 10 October: "the loading bar is not finishing
 * instantly, and due to that images don't load mostly ... tested from
 * multiple devices, multiple browsers".
 *
 * WHAT IT LOOKED LIKE ON THE SHOP. Every page printed
 * `<script async src="…gtag/js…">` and inserted fbevents.js from the <head>.
 * An async script still holds the load event, and the browser's loading bar
 * is the load event, so the bar sat part-way across until Meta's and Google's
 * servers answered, while those files shared the phone's line with the
 * shop's own photos. Measured on the live shop with both answering 4 s late:
 * load 5.5-6.7 s before, 3.45 s after.
 *
 * What is pinned: by default no pixel FILE is requested from the head -- each
 * is added by Analytics::LATE after the load event -- while every queue
 * (fbq, dataLayer/gtag, ttq) still exists at once, so no event is lost; and
 * "now" restores the old tags byte for byte.
 *
 * MUTATION: make Analytics::loadsLate() return false and the first case is red.
 */

use App\Services\Analytics;
use App\Services\Pixels\PixelConfig;
use App\Models\Setting;
use App\Services\SettingsService;

function plpConfigure(?string $load = null): void
{
    $settings = app(SettingsService::class);
    $settings->setModule(Analytics::MODULE, true);
    $settings->setModuleSetting(Analytics::MODULE, 'ga4_id', 'G-PLPLANE123');
    $settings->setModuleSetting(Analytics::MODULE, 'meta_id', '111122223333555');
    $settings->setModuleSetting(Analytics::MODULE, 'tiktok_id', 'CPLPLANE0000');

    if ($load !== null) {
        expect(app(PixelConfig::class)->save(['load_scripts' => $load]))->toBe([]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

it('adds the Meta, Google and TikTok files only after the page has loaded, with every queue ready at once', function () {
    plpConfigure();

    $html = test()->get('/')->assertOk()->getContent();

    // No pixel file requested by the head: no element, and no insert outside the late wrapper.
    expect(preg_match('#<script[^>]+src="[^"]*googletagmanager\.com/gtag/js#i', $html))->toBe(0)
        ->and(substr_count($html, Analytics::LATE))->toBe(3)
        ->and($html)->toContain("(" . Analytics::LATE . ")(function(){t=b.createElement(e);t.async=!0;t.src=v;")
        ->and($html)->toContain("s.src='https://www.googletagmanager.com/gtag/js?id=G-PLPLANE123'")
        ->and($html)->toContain("(" . Analytics::LATE . ")(function(){a.parentNode.insertBefore(o,a)})")
        // The queues, so events fired before the files arrive are kept.
        ->and($html)->toContain("n.queue=[];")
        ->and($html)->toContain("fbq('init',\"111122223333555\");fbq('track','PageView');")
        ->and($html)->toContain("window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}")
        ->and($html)->toContain("gtag('config',\"G-PLPLANE123\")")
        ->and($html)->toContain('ttq.page();');
});

it('puts the old head tags back exactly when the owner picks "Immediately"', function () {
    plpConfigure('now');

    $html = test()->get('/')->assertOk()->getContent();

    expect(substr_count($html, Analytics::LATE))->toBe(0)
        ->and($html)->toContain('<script async src="https://www.googletagmanager.com/gtag/js?id=G-PLPLANE123"></script>')
        ->and($html)->toContain("n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');")
        ->and($html)->toContain("var a=d.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load(");
});

it('stores only its own two answers', function () {
    plpConfigure();

    expect(app(PixelConfig::class)->save(['load_scripts' => 'later-maybe']))->toHaveKey('load_scripts')
        ->and(app(PixelConfig::class)->save(['load_scripts' => 'late']))->toBe([])
        ->and(Analytics::loadsLate())->toBeTrue();
});
