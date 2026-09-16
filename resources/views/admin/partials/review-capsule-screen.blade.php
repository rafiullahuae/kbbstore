{{--
    Reviews - Rating Capsule (Lane BE) - MERGED INTO Rating Badge by Lane CL.

    THIS SCREEN IS NOW A TAB. Everything it drew lives in
    admin/partials/review-badges-screen.blade.php, as the "Rating capsule" tab
    of the screen the sidebar calls Rating Badge.

    WHY. Badge Themes and Rating Capsule were two sidebar rows over ONE set of
    seven settings, saving through ONE endpoint (/admin-api/review-badges).
    Neither row told the owner which of the two did the thing they wanted, and
    this file's own warning card used to tell them the other screen held the
    same settings -- which is a screen apologising for the menu. Two rows that
    do not distinguish themselves are worse than a missing screen, so they are
    one screen with two tabs, the way Blog/Posts and the bulk review screens
    were merged before it.

    NOTHING WAS DELETED AND 'rev-capsule' STILL ROUTES. It keeps its entry in
    REV_SRC, in TITLES and in LIVE_RENDERED; what it gave up is its sidebar row,
    which is the whole point. /admin?go=rev-capsule and #rev-capsule open the
    merged screen with the Rating capsule tab selected and the Rating Badge row
    marked in the sidebar. The two halves that makes necessary are implemented
    and explained in review-badges-screen.blade.php:

      - both ids mark the SAME sidebar row, because go() marks the row whose
        data-go matches and a retired id has no row to mark;
      - that partial reads ?go= / # itself at boot, because app.blade.php's
        deep-link block navigates before this file or that one is parsed.

    tests/Feature/AdminNavAndIdsTest.php records the alias in ALIASED_SCREENS
    and re-checks all of it: that 'rev-capsule' still routes, that the screen it
    aliases has a row, that 'rev-capsule' does NOT have one of its own, and that
    both ids title the page the same.

    WHY THIS FILE STILL EXISTS RATHER THAN BEING DELETED. It is the record of
    where the screen went -- for the next person who greps 'rev-capsule', and
    for the console, which still @includes it by name. It registers no sidebar
    row, defines no style, claims no screen id and does not touch window.go:
    wrapping go() here would fight the merged screen for the same two ids.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<script>
/* =========================================================================
   Reviews -> Rating Capsule — merged into Rating Badge.

   Deliberately inert, and deliberately still here. The screen is the "Rating
   capsule" tab of admin/partials/review-badges-screen.blade.php, which owns
   both 'rev-badge' and 'rev-capsule'.

   This block does nothing on purpose. It must not wrap window.go, register a
   sidebar row or declare a SCREEN: any of those would give the console a second
   claimant for an id the merged screen already answers, which is the two-rows-
   for-one-screen bug this merge exists to remove.
   ========================================================================= */
(function(){
  'use strict';
  /* Left as a breadcrumb for anyone reading the console in devtools and
     wondering where a screen they remember went. */
  try {
    window.KBB_MERGED_SCREENS = window.KBB_MERGED_SCREENS || {};
    window.KBB_MERGED_SCREENS['rev-capsule'] = 'rev-badge';
  } catch (e) {}
})();
</script>
@endverbatim
