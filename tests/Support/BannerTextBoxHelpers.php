<?php

declare(strict_types=1);

/*
 * Lane HB: the render helpers BannerTextBoxTest and BannerTextBoxPositionTest
 * share. Functions, so they are loaded once with require_once.
 */

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\Setting;
use App\Services\Banners;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

if (function_exists('hbSet')) {
    return;
}

/** A slider set with $n published pictures; $cards[i] overrides picture i (1-based). */
function hbSet(array $set = [], int $n = 2, array $cards = []): BannerSet
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $s = BannerSet::create($set + [
        'name' => 'Hero', 'slug' => 'hb-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => 'slider',
    ]);

    foreach (range(1, $n) as $i) {
        BannerCard::create(($cards[$i] ?? []) + [
            'banner_set_id' => $s->id,
            'image' => 'uploads/banners/hb-'.$i.'.webp',
            'image_m' => 'uploads/banners/hb-'.$i.'-m.webp',
            'image_w' => 1920, 'image_h' => 550, 'image_m_w' => 500, 'image_m_h' => 600,
            'alt' => 'Picture '.$i,
            'button_url' => '/shop/',
            'position' => $i,
            'status' => 'publish',
        ]);
    }

    return $s;
}

/** The words a picture shows when its switch is on. */
function hbWords(array $extra = []): array
{
    return $extra + [
        'box_on' => true,
        'eyebrow' => 'NEW IN EYEBROW',
        'heading' => 'Glass skin starts here',
        'body' => 'SHORT TEXT LINE',
        'button_label' => 'Shop the Glow Edit',
    ];
}

/** Render the slider exactly as the homepage does, from the homepage's loader. */
function hbRender(BannerSet $set): string
{
    [$loaded, $cards] = app(Banners::class)->forPreview($set->id);

    $factory = app('view');
    $factory->flushState();
    $factory->incrementRender();

    try {
        $html = view($loaded->homePartial(), [
            'set' => $loaded, 'cards' => $cards,
            'sections' => app(\App\Services\HomepageSections::class),
        ])->render();
        $head = $factory->yieldPushContent('head');
    } finally {
        $factory->decrementRender();
        $factory->flushState();
    }

    return $head.$html;
}

function hbBox(string $html, int $nth = 0): string
{
    preg_match_all('#<div class="hb-box[^"]*">.*?</div>#s', $html, $m);

    return $m[0][$nth] ?? '';
}

function hbArabic(): void
{
    foreach ([Locale::SETTING_ENABLED, Locale::SETTING_RTL] as $key) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => '1', 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
    app()->setLocale('ar');
}

