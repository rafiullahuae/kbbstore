<?php

declare(strict_types=1);

namespace App\Services\Pixels;

use App\Models\Order;
use App\Services\Analytics;

/**
 * Google Ads conversion tracking and Consent Mode, on the one gtag.js the
 * Analytics loader already emits. (Lane MP)
 *
 *   config()    the lines that go INTO Analytics' gtag block: the Consent Mode
 *               default (first, before any config, as Google requires) and
 *               gtag('config','AW-…'). '' when neither is set, so a shop
 *               without Google Ads prints exactly what it printed before.
 *   purchase()  the thank-you page's conversion event, with the order number
 *               as transaction_id (Google Ads drops a second conversion with
 *               the same one) and, if the owner switched enhanced conversions
 *               on, the customer's email and phone — normalised and SHA-256
 *               hashed HERE, so no readable address is printed into the page.
 *
 * CONSENT MODE. The shop has no cookie banner, so there is nothing to grant
 * consent with. "eea" therefore denies the four signals for visitors in the
 * EEA, the UK and Switzerland only (Google's `region` parameter), where the
 * Digital Markets Act makes Consent Mode v2 a requirement for ads
 * measurement, and leaves the rest of the world — the UAE included — as it
 * is. Google then measures those visitors without cookies (modelled). "off"
 * (the default) prints nothing.
 */
final class GoogleAds
{
    /** EEA + UK + Switzerland, as Google's consent docs list them. */
    public const CONSENT_REGIONS = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU',
        'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH'];

    public function __construct(private Analytics $analytics, private PixelConfig $config) {}

    public function adsId(): ?string
    {
        $id = strtoupper($this->config->get('ads_id'));

        return preg_match(PixelConfig::SHAPES['ads_id'][0], $id) === 1 ? $id : null;
    }

    public function label(): ?string
    {
        $label = $this->config->get('ads_label');

        return preg_match(PixelConfig::SHAPES['ads_label'][0], $label) === 1 ? $label : null;
    }

    /** Consent default — printed before gtag('js') — or ''. */
    public function consentDefault(): string
    {
        if ($this->config->get('consent_mode') !== 'eea') {
            return '';
        }

        return "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',region:"
            . json_encode(self::CONSENT_REGIONS) . ",wait_for_update:500});";
    }

    /** gtag('config','AW-…') or ''. */
    public function config(): string
    {
        $id = $this->adsId();

        if ($id === null) {
            return '';
        }

        $ec = $this->config->get('ads_ec') === '1' ? ',{allow_enhanced_conversions:true}' : '';

        return "gtag('config'," . json_encode($id) . $ec . ');';
    }

    public function purchase(Order $order, string $totalJson, string $currencyJson): string
    {
        $id = $this->adsId();
        $label = $this->label();

        if (! $this->analytics->enabled() || $id === null || $label === null) {
            return '';
        }

        $out = '';

        if ($this->config->get('ads_ec') === '1') {
            $billing = is_array($order->billing_address) ? $order->billing_address : [];
            $data = array_filter([
                'sha256_email_address' => UserData::hashed(UserData::email((string) $order->email, true)),
                'sha256_phone_number' => UserData::hashed(UserData::phoneE164((string) ($order->phone ?: ($billing['phone'] ?? '')))),
            ]);

            if ($data !== []) {
                $out .= '<script>gtag(\'set\',\'user_data\',' . json_encode($data) . ');</script>' . "\n";
            }
        }

        $out .= '<script>gtag(\'event\',\'conversion\',{send_to:' . json_encode($id . '/' . $label) . ',value:' . $totalJson
            . ',currency:' . $currencyJson . ',transaction_id:' . json_encode((string) $order->order_number) . '});</script>' . "\n";

        return $out;
    }
}
