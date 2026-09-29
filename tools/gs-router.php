<?php
/**
 * Lane GS · the preview rig's router. Not shipped; see docs/GS-PRODUCT-GRIDS.md.
 *
 * `-t public-web-root` IS PART OF THE COMMAND AND NOT A DETAIL. Without it,
 * `php -S` resolves both its own static handler and this script's `return false`
 * against the CWD, so every /build/assets/*.css 404'd — Chromium refused both
 * stylesheets and the sweep reported a page with no layout at all:
 * `.kbb-pgrid` computed `display:block` on the shop's OWN four rails as well as
 * on this lane's two. A sweep that cannot see the stylesheet cannot fail, and is
 * not evidence. tools/gs-shoot.mjs re-asserts that the stylesheets arrived
 * before any row is believed.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path !== '/' && is_file(dirname(__DIR__).'/public-web-root'.$path)) {
    return false;
}

require dirname(__DIR__).'/public-web-root/index.php';
