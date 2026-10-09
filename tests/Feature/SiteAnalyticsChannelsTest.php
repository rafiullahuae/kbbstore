<?php

declare(strict_types=1);

use App\Services\Analytics\Channels;

/*
 * Where a visit came from: one row per channel the owner named ("google
 * organic, instagram, tiktok, direct visit etc etc. and also track the
 * campaign if paid"). (Lane AN)
 *
 * Each row is [referrer domain, utm_source, utm_medium, click-id type,
 * expected channel]. MUTATION notes, per family:
 *   - drop 'g' from CLICKS            -> the gclid rows read Direct
 *   - drop the instagram HOSTS line   -> l.instagram.com reads Referral
 *   - make 'f' paid in CLICKS         -> the organic fbclid row reads Facebook Ads
 *   - drop l.wl.co from the WhatsApp line -> that row reads Referral
 *   - drop the email rule             -> the newsletter row reads Referral
 *   - drop PAID_MEDIUM                -> every "… Ads" row from utm reads organic
 */
dataset('channels', [
    'Google organic (referrer)' => ['google.com', '', '', '', 'google'],
    'Google organic (country domain)' => ['google.ae', '', '', '', 'google'],
    'Google organic (Android app)' => ['com.google.android.googlequicksearchbox', '', '', '', 'google'],
    'Google Ads (gclid)' => ['', '', '', 'g', 'google_ads'],
    'Google Ads (gclid with google referrer)' => ['google.com', '', '', 'g', 'google_ads'],
    'Google Ads (utm cpc)' => ['', 'google', 'cpc', '', 'google_ads'],
    'Instagram (referrer)' => ['l.instagram.com', '', '', '', 'instagram'],
    'Instagram (utm ig, social)' => ['', 'ig', 'social', '', 'instagram'],
    'Instagram Ads (utm paid)' => ['', 'instagram', 'paid', '', 'instagram_ads'],
    'Instagram Ads (fbclid + referrer + paid_social)' => ['instagram.com', 'ig', 'paid_social', 'f', 'instagram_ads'],
    'Facebook (referrer)' => ['m.facebook.com', '', '', '', 'facebook'],
    'Facebook (organic fbclid only)' => ['', '', '', 'f', 'facebook'],
    'Facebook Ads (utm fb cpc)' => ['', 'fb', 'cpc', '', 'facebook_ads'],
    'TikTok (referrer)' => ['tiktok.com', '', '', '', 'tiktok'],
    'TikTok Ads (ttclid)' => ['', '', '', 't', 'tiktok_ads'],
    'WhatsApp (wa.me)' => ['wa.me', '', '', '', 'whatsapp'],
    'WhatsApp (api.whatsapp.com)' => ['api.whatsapp.com', '', '', '', 'whatsapp'],
    'WhatsApp (l.wl.co)' => ['l.wl.co', '', '', '', 'whatsapp'],
    'Snapchat (referrer)' => ['snapchat.com', '', '', '', 'snapchat'],
    'Snapchat Ads (utm)' => ['', 'snapchat', 'paid', '', 'snapchat_ads'],
    'Bing organic' => ['bing.com', '', '', '', 'bing'],
    'Bing Ads (msclkid)' => ['', '', '', 'm', 'bing_ads'],
    'Yahoo organic' => ['search.yahoo.com', '', '', '', 'yahoo'],
    'DuckDuckGo organic' => ['duckduckgo.com', '', '', '', 'duckduckgo'],
    'Email (utm_medium email)' => ['', 'newsletter_oct', 'email', '', 'email'],
    'Email (newsletter beats a known source)' => ['', 'instagram', 'newsletter', '', 'email'],
    'Other Ads (paid, unknown source)' => ['', 'someadnet', 'cpc', '', 'other_ads'],
    'Referral (another site)' => ['beautyblog.example', '', '', '', 'referral'],
    'Referral (utm_source only)' => ['', 'partner', '', '', 'referral'],
    'Direct (nothing)' => ['', '', '', '', 'direct'],
]);

it('classifies every channel the owner named from one constant table', function (string $host, string $src, string $med, string $click, string $want) {
    expect(Channels::classify($host, $src, $med, $click))->toBe($want)
        ->and(Channels::valid($want))->toBeTrue()
        ->and(strlen($want))->toBeLessThanOrEqual(16);
})->with('channels');

it('labels every key, and reads Unknown for an order nothing recorded', function () {
    expect(Channels::label('instagram_ads'))->toBe('Instagram Ads')
        ->and(Channels::label('google'))->toBe('Google Organic')
        ->and(Channels::label(null))->toBe('Unknown')
        ->and(Channels::label('nonsense'))->toBe('Unknown');

    foreach (Channels::MAP as $key => $label) {
        expect(strlen($key))->toBeLessThanOrEqual(16)->and($label)->not->toBe('');
    }
});
