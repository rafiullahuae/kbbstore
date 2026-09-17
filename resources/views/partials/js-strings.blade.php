{{--
    The front-end's string table — emitted only when the page is not English.

    WHY THE GUARD. Two reasons, and both are load-bearing.

    The first is the acceptance bar this lane is held to: an English page must
    render exactly the bytes it rendered before the conversion. A <script> block
    added to every page would fail that on every page at once, for a table an
    English reader has no use for — every value in it would be the English the
    bundle already carries as its fallback.

    The second is the shipped bundle. Assets are built off-server and uploaded
    (CLAUDE.md: package.json has no build script, CI does not build assets), so
    the JavaScript running on the shop is routinely older than this repository.
    resources/js/kbb/i18n.js is written for that: every call carries its own
    English, so a bundle that predates a key simply keeps saying what it always
    said. The table is an override, never a dependency.

    Included from layouts/store.blade.php immediately after window.KBB, so the
    modules can read it whenever they run.
--}}
@php
    $kbbJsStrings = \App\Services\Translation\FrontEndStrings::forLocale(app()->getLocale());
@endphp
@if ($kbbJsStrings !== [])
<script>window.KBB_T = @json($kbbJsStrings);</script>
@endif
