<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Handover blocks that edited the sidebar's old JavaScript literals. (Lane AP)
 *
 * Several lanes delivered their sidebar row as an anchor/replacement block
 * against `const NAV=[...]` / `const LATE_NAV=[...]` in admin/app.blade.php,
 * and the lane that settled the sidebar order as one against the pin in
 * AdminSidebarIsCompleteAtBuildTest. Lane AP retired both literals: the
 * sidebar is App\Support\AdminNav, server-rendered, and the pin was rewritten
 * to read it. Those blocks were applied long ago; their rows are in AdminNav
 * (AdminSidebarIsCompleteAtBuildTest pins every one, in its settled place).
 *
 * superseded() says which blocks those are, so a handover reader can skip
 * them instead of reporting an anchor that can no longer exist. Everything
 * else in each handover is still checked as before.
 */
final class RetiredNavLiterals
{
    public static function superseded(string $file, string $replacement): bool
    {
        if ($file === 'resources/views/admin/app.blade.php') {
            // A LATE_NAV row, a NAV section, or a NAV item: ['id','Label','<svg paths…
            return (bool) preg_match("/\\{screen:'|\\{sec:'|\\['[a-z0-9-]+','[^']*','</", $replacement);
        }

        if ($file === 'tests/Feature/AdminSidebarIsCompleteAtBuildTest.php') {
            return (bool) preg_match("/'(Appearance|Catalog|Store|Content|Platform|Growth & Marketing)' => \\[/", $replacement);
        }

        return false;
    }
}
