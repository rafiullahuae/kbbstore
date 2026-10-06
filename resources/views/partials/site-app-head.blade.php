{{-- PW:BEGIN  The shop as a Home Screen app (Lane PW). App -> Site App.
     Nothing at all when the app is off. Every value through the escaping
     echo; the script is deferred and registers the worker after load.
     NO NEWLINE OUTSIDE THE TAGS, and none at the end of this file: the
     include line in layouts/store.blade.php supplies the one newline, so the
     page gains exactly these eight lines and nothing else
     (StorefrontEnglishUnchangedTest cuts them out by that shape).
     theme-color (Lane IC) is the colour at the top of the page (the header
     bar's background), so the installed app's status bar
     continues it to the top edge. media="(display-mode: standalone)": only the
     installed app takes it; a shopper in the browser keeps the browser's own
     bar, as before. iPhone stays "default" -- a dark clock on the light bar.
     IN (Lane IN): one inline line, only while the footer's app row is
     switched on, so Chrome's install offer can never fire before the deferred
     script is listening. It takes the offer only on a page that drew the row
     (the check runs when the offer fires, after the page has loaded), so a
     page without the row keeps Chrome's own behaviour. ~150 bytes, no
     request, no settings read beyond the snapshot. --}}@php($kbbSiteApp = app(\App\Services\SiteApp::class)->head())@if ($kbbSiteApp)<link rel="manifest" href="{{ $kbbSiteApp['manifest'] }}">
<link rel="apple-touch-icon" href="{{ $kbbSiteApp['apple'] }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="theme-color" media="(display-mode: standalone)" content="{{ $kbbSiteApp['theme'] }}">
<meta name="apple-mobile-web-app-title" content="{{ $kbbSiteApp['name'] }}">
<script src="{{ $kbbSiteApp['js'] }}" data-sw="{{ $kbbSiteApp['sw'] }}" data-scope="{{ $kbbSiteApp['scope'] }}" defer></script>
@if (app(\App\Services\SiteFooter::class)->appRowOn())<script>addEventListener('beforeinstallprompt',function(e){if(document.querySelector('.kfa[data-kfa]')){e.preventDefault();window.__kbbBip=e}})</script>
@endif
@endif
{{-- IC: the favicon (App -> Site App -> App icon, Lane IC). Nothing at all until the owner uploads an icon; then one line per tag, the same shape as above. The newline after @endif is PHP's own: one directly after a closing tag is never printed, and Blade needs a non-word character before the next directive. --}}@php($kbbFavicon = app(\App\Services\SiteApp::class)->favicon())@foreach ($kbbFavicon as $kbbFav)<link rel="{{ $kbbFav['rel'] }}"@if ($kbbFav['sizes']) type="image/png" sizes="{{ $kbbFav['sizes'] }}"@endif href="{{ $kbbFav['href'] }}">
@endforeach{{-- PW:END --}}