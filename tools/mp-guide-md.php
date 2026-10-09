<?php

declare(strict_types=1);

/*
 * Lane MP: write docs/MARKETING-PIXELS-GUIDE.md from App\Services\Pixels\PixelGuide,
 * the same data the admin Guide tab renders, so the two cannot drift.
 *
 *     php tools/mp-guide-md.php
 *
 * MarketingPixelsConnectTest checks every guide URL is in the file.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\Pixels\PixelGuide;

$md = "# Marketing Pixels — the complete guide\n\n"
    ."Meta, Google (Analytics 4, Ads, Merchant Center) and TikTok, end to end. Checked on ".PixelGuide::CHECKED.".\n\n"
    ."Everything is in the admin at **".PixelGuide::HERE."**. This file is a copy of the **Guide** tab there.\n\n";

foreach (PixelGuide::sections() as $section) {
    $md .= "## {$section['title']}\n\n{$section['intro']}\n\n";

    foreach ($section['blocks'] as [$heading, $steps, $links, $where, $verify, $errors]) {
        $md .= "### {$heading}\n\n";
        foreach ($steps as $i => $step) {
            $md .= ($i + 1).'. '.$step."\n";
        }
        $md .= "\n";
        if ($where) {
            $md .= "**Paste it here:** {$where}\n\n";
        }
        if ($verify) {
            $md .= "**How to check:** {$verify}\n\n";
        }
        if ($errors) {
            $md .= "**Common errors:**\n\n";
            foreach ($errors as $err) {
                $md .= "- {$err}\n";
            }
            $md .= "\n";
        }
        if ($links) {
            foreach ($links as [$label, $url]) {
                $md .= "- [{$label}]({$url})\n";
            }
            $md .= "\n";
        }
    }
}

$md .= "## The Connect wizards, step by step\n\n";

foreach (PixelGuide::WIZARD as $platform) {
    $md .= "### {$platform['title']}\n\n";
    foreach ($platform['steps'] as $i => [$title, $text, $link, $fields]) {
        $md .= ($i + 1).". **{$title}.** {$text}".($link ? " [{$link[0]}]({$link[1]})" : '')."\n";
    }
    $md .= "\n";
}

$md .= "## Addresses this shop serves\n\n"
    ."- Google Merchant Center feed: `https://<your shop>/feeds/google-merchant.xml`\n"
    ."- Meta catalog feed: `https://<your shop>/feeds/meta-catalog.xml`\n"
    ."- TikTok catalog feed: `https://<your shop>/feeds/tiktok-catalog.xml`\n"
    ."- Connect with Facebook redirect URI: `https://<your shop>/admin-api/marketing-pixels/meta/callback`\n\n"
    ."The exact addresses for this shop, with Copy buttons, are on each Connect tab.\n";

file_put_contents(dirname(__DIR__).'/docs/MARKETING-PIXELS-GUIDE.md', $md);
echo strlen($md)." bytes written to docs/MARKETING-PIXELS-GUIDE.md\n";
