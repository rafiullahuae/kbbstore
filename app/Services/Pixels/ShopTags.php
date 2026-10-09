<?php

declare(strict_types=1);

namespace App\Services\Pixels;

/**
 * The three print points Marketing Pixels adds to the shop layout. (Lane MP)
 *
 *   head()       Meta's domain-verification tag, then the custom Head code
 *   bodyStart()  the custom Body-start code
 *   footer()     the custom Footer code
 *
 * Each returns '' — not a newline — when there is nothing to print, so a shop
 * that uses none of it renders byte-identical pages.
 *
 * The verification tag is rebuilt from constants around a code that has
 * already passed PixelConfig::GOOGLE_VERIFY_SHAPE (letters, digits, - and _),
 * so nothing the owner pasted is ever printed as markup. Google's own
 * verification tag is printed by App\Support\Seo from the same SEO setting the
 * wizard writes, so it is not printed twice.
 */
final class ShopTags
{
    public function __construct(private PixelConfig $config, private CustomCode $code) {}

    public function head(): string
    {
        $out = '';
        $meta = $this->config->metaVerification();

        if ($meta !== null) {
            $out .= '<meta name="facebook-domain-verification" content="' . $meta . '">' . "\n";
        }

        return $out . $this->code->render('head');
    }

    public function bodyStart(): string
    {
        return $this->code->render('body');
    }

    public function footer(): string
    {
        return $this->code->render('footer');
    }
}
