<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\HomepageSections;
use App\Services\SettingsService;

/**
 * Switch homepage sections the owner took off the page back ON, for a test
 * that is about one of them.                                (Row 55, Lane HA)
 *
 * HomepageSections::OFF_BY_DEFAULT ships the old homepage's sections switched
 * off ("don't include anything from our existing homepage ... except banner").
 * Their code, their row on Appearance → Homepage and their switches all stay,
 * and so do the tests that pin how each one behaves when it IS on. Those tests
 * now say so first — the same thing the owner would do on the screen — rather
 * than relying on a default that moved.
 */
final class LegacyHomeSections
{
    /** @param  list<string>|null  $keys  null = every OFF_BY_DEFAULT section */
    public static function on(?array $keys = null): void
    {
        $settings = app(SettingsService::class);
        $saved = $settings->get('homepage_sections');
        $saved = is_array($saved) ? $saved : [];

        foreach ($keys ?? HomepageSections::OFF_BY_DEFAULT as $key) {
            $saved[$key] = ['desktop' => true, 'mobile' => true] + (is_array($saved[$key] ?? null) ? $saved[$key] : []);
            $saved[$key]['desktop'] = true;
            $saved[$key]['mobile'] = true;
        }

        $settings->set('homepage_sections', $saved);
        SettingsService::forgetMemo();
    }
}
