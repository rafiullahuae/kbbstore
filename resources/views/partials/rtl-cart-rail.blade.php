{{--
    The cart page's "you may also like" rail arrows, in a right-to-left document
    (Lane AR round 2). Emitted ONLY when the page is mirrored -- the include in
    partials/address-sheet.blade.php sits inside @if (Locale::isRtl()) -- so an
    English page carries not one byte of it.

    THE DEFECT, measured on /ar/cart/ at 1280: the rail's scrollLeft starts at 0
    and runs NEGATIVE in a right-to-left scroller, and address-sheet's script
    reads it as left-to-right. "Next" scrolled by +0.86 of the rail -- towards
    the start, where there is nothing -- so it did nothing at all; "Previous"
    was dimmed for good (scrollLeft <= 0 is always true), and "Next" never
    dimmed at the end. And the chevrons pointed the English way.

    Registered in the CAPTURE phase on the document, so an arrow press is
    answered here first and stopped before the left-to-right handler runs; the
    dimming listeners run after address-sheet's own and set the right state.
    No measuring of layout: scrollBy and scrollLeft only, as the original.
--}}
<script>
(function () {
  function dim(rail) {
    var box = rail.parentNode, l = box.querySelector('.cpg-arr-l'), r = box.querySelector('.cpg-arr-r');
    var max = rail.scrollWidth - rail.clientWidth, at = -Math.round(rail.scrollLeft);
    if (l) l.disabled = at <= 0;
    if (r) r.disabled = at >= Math.round(max) - 1;
  }
  function all() { Array.prototype.forEach.call(document.querySelectorAll('.cpg-rail'), dim); }
  document.addEventListener('click', function (e) {
    var arrow = e.target.closest && e.target.closest('[data-cpg-rail]');
    if (!arrow) { return; }
    var rail = arrow.parentNode.querySelector('.cpg-rail');
    if (!rail) { return; }
    e.stopPropagation();
    rail.scrollBy({ left: -rail.clientWidth * 0.86 * Number(arrow.dataset.cpgRail), behavior: 'smooth' });
  }, true);
  document.addEventListener('scroll', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('cpg-rail')) { dim(e.target); }
  }, true);
  document.addEventListener('kbb:cart-updated', all);
  all();
})();
</script>
