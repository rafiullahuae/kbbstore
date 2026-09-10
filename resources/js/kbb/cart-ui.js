/**
 * Superseded — intentionally empty.
 *
 * This module briefly bound the drawer and cart-page controls a second time. It
 * was written against a reconstructed copy of the app in which cart.js was an
 * older, much smaller file and the drawer sat inside `<div id="cartDrawer">`.
 * Neither is true here: cart.js already binds every one of those hooks, and the
 * fragment is a direct child of `.drawer` — so this module's repaint targeted an
 * element that does not exist while its handlers fired a duplicate request on
 * every click.
 *
 * Left in place rather than deleted because an update package copies files and
 * cannot remove them. Nothing imports it.
 */
