{{-- PW:BEGIN  The shop as a Home Screen app (Lane PW). App -> Site App.
     Nothing at all when the app is off. Every value through the escaping
     echo; the script is deferred and registers the worker after load.
     NO NEWLINE OUTSIDE THE TAGS, and none at the end of this file: the
     include line in layouts/store.blade.php supplies the one newline, so the
     page gains exactly these seven lines and nothing else
     (StorefrontEnglishUnchangedTest cuts them out by that shape). --}}@php($kbbSiteApp = app(\App\Services\SiteApp::class)->head())@if ($kbbSiteApp)<link rel="manifest" href="{{ $kbbSiteApp['manifest'] }}">
<link rel="apple-touch-icon" href="{{ $kbbSiteApp['apple'] }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $kbbSiteApp['name'] }}">
<script src="{{ $kbbSiteApp['js'] }}" data-sw="{{ $kbbSiteApp['sw'] }}" data-scope="{{ $kbbSiteApp['scope'] }}" defer></script>
@endif{{-- PW:END --}}