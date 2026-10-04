<?php

declare(strict_types=1);

namespace App\Services\Mail\Kit;

use Illuminate\Contracts\Translation\Loader;

/**
 * The real translation loader with the editor's unsaved words laid over it.
 *
 * @internal KitWords::withDraft()
 */
final class KitWordsOverlay implements Loader
{
    /** @param array<string, array<string, string>> $overlay locale => full key => line */
    public function __construct(private Loader $inner, private array $overlay) {}

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if ($namespace !== null && $namespace !== '*') {
            return $lines;
        }

        foreach ($this->overlay[$locale] ?? [] as $key => $line) {
            if (str_starts_with($key, $group . '.')) {
                $lines[substr($key, strlen($group) + 1)] = $line;
            }
        }

        return $lines;
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
