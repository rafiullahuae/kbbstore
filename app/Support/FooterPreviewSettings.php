<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The settings the footer preview renders from: the shop's own, with the
 * values the owner has typed and NOT saved laid over them.        (Lane FT)
 *
 * Bound in place of SettingsService for the length of one preview render, so
 * SiteFooter and SlimFooter — and the two partials the shop itself includes —
 * read exactly what they read on the storefront, except for the keys on top.
 * Each value still goes through its own service's cast() on the way out, so a
 * preview can draw nothing the save would not have stored.
 *
 * IT CANNOT WRITE. A preview that persisted anything would be a save with no
 * Save button, so set() refuses outright rather than quietly passing through.
 */
final class FooterPreviewSettings extends SettingsService
{
    /** @param array<string, mixed> $over full setting name => raw value */
    public function __construct(private SettingsService $inner, private array $over) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->over) ? $this->over[$key] : $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, bool $autoload = true): void
    {
        throw new \LogicException('The footer preview never writes a setting.');
    }
}
