<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use Illuminate\Contracts\Translation\Loader;

/**
 * Lays the template builder's stored words over the interface strings as a
 * group loads — Lane EK. Wraps the loader AppServiceProvider already wraps
 * (DatabaseTranslationLoader), so the order is: file → code → the
 * `translations` table → an email's own words in `email_templates`. With no
 * row in that table this returns exactly what the inner loader returned.
 *
 * Only the `email` group is touched, and only the keys EmailWording::overlay()
 * names (a key used by exactly one email): the storefront never pays for it.
 */
final class EmailWordingLoader implements Loader
{
    public function __construct(private Loader $inner) {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if ($group !== 'email' || ($namespace !== null && $namespace !== '*')) {
            return $lines;
        }

        try {
            return array_replace($lines, EmailWording::overlay((string) $locale, 'email'));
        } catch (\Throwable) {
            return $lines;
        }
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->inner->namespaces();
    }
}
