{{--
    The owner app's shell (Lane MAC). The same bytes for everybody: no name, no
    order, no token. Everything a member sees is fetched after the PIN.

    No inline script and no inline style (the CSP forbids both), one stylesheet
    and one script from the build. The icons are an inline SVG sprite — markup,
    not script — the Petal set from docs/owner-app-preview. The one font, Plus
    Jakarta Sans (Latin, variable, self-hosted), is preloaded: nothing is asked
    of Google, and the app opens offline once installed. With "System font"
    chosen under Owner app → Customise app (Lane OA4) there is no preload and
    <html> carries oa-sys, whose --font names no web font: the file is never
    requested.
--}}
<!doctype html>
<html lang="{{ \App\Support\Locale::htmlLang() }}" dir="{{ \App\Support\Locale::direction() }}"@if($sysFont ?? false) class="oa-sys"@endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="{{ $top ?? '#FBE3EA' }}">
<meta name="color-scheme" content="light">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="KBB Owner">
<meta name="apple-mobile-web-app-status-bar-style" content="{{ ($fullscreen ?? true) ? 'default' : 'black-translucent' }}">
<meta name="format-detection" content="telephone=no">
<title>K-Beauty Bliss Owner</title>
<link rel="manifest" href="{{ $base }}/manifest.webmanifest">
@forelse($fav ?? [] as $f)
<link rel="icon" type="image/png" sizes="{{ $f['sizes'] }}" href="{{ $f['href'] }}">
@empty
<link rel="icon" type="image/png" href="{{ $a['icon-192'] }}">
@endforelse
<link rel="apple-touch-icon" href="{{ $a['apple-180'] }}">
@unless($sysFont ?? false)
<link rel="preload" href="{{ $a['font'] }}" as="font" type="font/woff2" crossorigin>
@endunless
<link rel="stylesheet" href="{{ $a['css'] }}">
<script type="module" src="{{ $a['js'] }}"></script>
</head>
<body data-base="{{ $base }}">
<svg class="sprite" aria-hidden="true" focusable="false">
<symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 4v16h16M8 15l3.5-4 3 3L20 7"/></symbol>
<symbol id="i-receipt" viewBox="0 0 24 24"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3"/></symbol>
<symbol id="i-box" viewBox="0 0 24 24"><path d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/></symbol>
<symbol id="i-grid" viewBox="0 0 24 24"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><path d="M11 18a7 7 0 100-14 7 7 0 000 14zM20 20l-4-4"/></symbol>
<symbol id="i-scan" viewBox="0 0 24 24"><path d="M4 8V5a1 1 0 011-1h3M16 4h3a1 1 0 011 1v3M20 16v3a1 1 0 01-1 1h-3M8 20H5a1 1 0 01-1-1v-3M8 9v6M11 9v6M14 9v6M17 9v6"/></symbol>
<symbol id="i-filter" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="i-back" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></symbol>
<symbol id="i-chev" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></symbol>
<symbol id="i-up" viewBox="0 0 24 24"><path d="M6 15l6-6 6 6"/></symbol>
<symbol id="i-down" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
<symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 9a6 6 0 0112 0c0 6 3 8 3 8H3s3-2 3-8M10 21h4"/></symbol>
<symbol id="i-sliders" viewBox="0 0 24 24"><path d="M4 7h9M17 7h3M4 17h3M11 17h9M15 9a2 2 0 100-4 2 2 0 000 4zM9 19a2 2 0 100-4 2 2 0 000 4z"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><path d="M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0"/></symbol>
<symbol id="i-users" viewBox="0 0 24 24"><path d="M9 11a4 4 0 100-8 4 4 0 000 8zM2 21a7 7 0 0114 0M16 3.5a4 4 0 010 7.5M22 21a7 7 0 00-4-6.3"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
<symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16zM14 6l4 4"/></symbol>
<symbol id="i-share" viewBox="0 0 24 24"><path d="M12 3v12M7 8l5-5 5 5M5 13v6a2 2 0 002 2h10a2 2 0 002-2v-6"/></symbol>
<symbol id="i-dots" viewBox="0 0 24 24"><path d="M12 5h.01M12 12h.01M12 19h.01" stroke-width="3.2"/></symbol>
<symbol id="i-cal" viewBox="0 0 24 24"><path d="M4 6h16v14H4zM4 10h16M8 3v5M16 3v5"/></symbol>
<symbol id="i-tag" viewBox="0 0 24 24"><path d="M3 12V4h8l10 10-8 8zM7.5 7.5h.01"/></symbol>
<symbol id="i-stack" viewBox="0 0 24 24"><path d="M12 3l9 5-9 5-9-5zM3 13l9 5 9-5"/></symbol>
<symbol id="i-text" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/></symbol>
<symbol id="i-truck" viewBox="0 0 24 24"><path d="M3 6h11v10H3zM14 10h4l3 3v3h-7M7 19a2 2 0 100-4 2 2 0 000 4zM17 19a2 2 0 100-4 2 2 0 000 4z"/></symbol>
<symbol id="i-card" viewBox="0 0 24 24"><path d="M3 6h18v12H3zM3 10h18M7 15h4"/></symbol>
<symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 11a8 8 0 10-2.3 5.7M20 4v7h-7"/></symbol>
<symbol id="i-lock" viewBox="0 0 24 24"><path d="M6 11h12v10H6zM8 11V7a4 4 0 018 0v4"/></symbol>
<symbol id="i-device" viewBox="0 0 24 24"><path d="M8 3h8a1 1 0 011 1v16a1 1 0 01-1 1H8a1 1 0 01-1-1V4a1 1 0 011-1zM11 18h2"/></symbol>
<symbol id="i-logout" viewBox="0 0 24 24"><path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10"/></symbol>
<symbol id="i-homeadd" viewBox="0 0 24 24"><path d="M3 11l9-7 9 7M5 10v10h14V10M12 12v6M9 15h6"/></symbol>
<symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
<symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3M6 7l1 14h10l1-14"/></symbol>
<symbol id="i-link" viewBox="0 0 24 24"><path d="M10 14a5 5 0 007 0l3-3a5 5 0 00-7-7l-1 1M14 10a5 5 0 00-7 0l-3 3a5 5 0 007 7l1-1"/></symbol>
<symbol id="i-mail" viewBox="0 0 24 24"><path d="M3 5h18v14H3zM3 6l9 7 9-7"/></symbol>
<symbol id="i-print" viewBox="0 0 24 24"><path d="M7 9V3h10v6M7 17H4v-8h16v8h-3M7 14h10v7H7z"/></symbol>
<symbol id="i-up-r" viewBox="0 0 24 24"><path d="M4 17l6-6 4 4 7-7M15 8h6v6"/></symbol>
<symbol id="i-phone" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2"/></symbol>
<symbol id="i-chat" viewBox="0 0 24 24"><path d="M4 20l1.4-4.2A8 8 0 1112 20a8 8 0 01-3.8-1zM9 10h6M9 13h4"/></symbol>
<symbol id="i-del" viewBox="0 0 24 24"><path d="M9 5h11v14H9l-6-7zM13 9l5 6M18 9l-5 6"/></symbol>
<symbol id="i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 019.5 4a8 8 0 1010.5 10.5z"/></symbol>
<symbol id="i-undo" viewBox="0 0 24 24"><path d="M9 14l-5-5 5-5M4 9h11a5 5 0 010 10h-3"/></symbol>
<symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 3L5 13h6l-1 8 8-10h-6z"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><path d="M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2"/></symbol>
<symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-7-6-7-12a7 7 0 0114 0c0 6-7 12-7 12zM12 11a2 2 0 100-4 2 2 0 000 4z"/></symbol>
<symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 100-6 3 3 0 000 6z"/></symbol>
</svg>
<div id="oa-app" class="app pinapp"><div class="pin"><i class="logo" aria-hidden="true">KB</i><p class="kick">K-Beauty Bliss · Owner</p></div></div>
<noscript><p class="pin-msg">This app needs JavaScript.</p></noscript>
</body>
</html>
