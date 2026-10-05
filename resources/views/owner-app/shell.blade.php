{{--
    The owner app's shell (Lane MAC). The same bytes for everybody: no name, no
    order, no token. Everything a member sees is fetched after the PIN.

    No inline script and no inline style (the CSP forbids both), one stylesheet
    and one script from the build. The icons are an inline SVG sprite — markup,
    not script. The design lane restyles this app by replacing
    resources/css/owner-app/owner-app.css; every element carries an `oa-` class.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#E0567B">
<meta name="color-scheme" content="light">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="KBB Owner">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">
<title>K-Beauty Bliss Owner</title>
<link rel="manifest" href="{{ $base }}/manifest.webmanifest">
<link rel="icon" type="image/png" href="{{ $a['icon-192'] }}">
<link rel="apple-touch-icon" href="{{ $a['apple-180'] }}">
<link rel="stylesheet" href="{{ $a['css'] }}">
<script type="module" src="{{ $a['js'] }}"></script>
</head>
<body class="oa" data-base="{{ $base }}">
<svg class="oa-sprite" aria-hidden="true" focusable="false">
<symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 19h16M6 15l4-5 3 3 5-7"/></symbol>
<symbol id="i-bag" viewBox="0 0 24 24"><path d="M6 8h12l-1 12H7L6 8zM9 8V6a3 3 0 0 1 6 0v2"/></symbol>
<symbol id="i-box" viewBox="0 0 24 24"><path d="M4 8l8-4 8 4v8l-8 4-8-4V8zM4 8l8 4 8-4M12 12v8"/></symbol>
<symbol id="i-dots" viewBox="0 0 24 24"><path d="M6 6h.01M18 6h.01M6 18h.01M18 18h.01" stroke-width="4"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4"/></symbol>
<symbol id="i-filter" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
<symbol id="i-back" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></symbol>
<symbol id="i-chev" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></symbol>
<symbol id="i-up" viewBox="0 0 24 24"><path d="M6 15l6-6 6 6"/></symbol>
<symbol id="i-down" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
<symbol id="i-edit" viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16v4z"/></symbol>
<symbol id="i-mail" viewBox="0 0 24 24"><path d="M4 6h16v12H4zM4 7l8 6 8-6"/></symbol>
<symbol id="i-phone" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1z"/></symbol>
<symbol id="i-chat" viewBox="0 0 24 24"><path d="M4 20l1.5-4A8 8 0 1 1 8 19l-4 1z"/></symbol>
<symbol id="i-ext" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/></symbol>
<symbol id="i-tag" viewBox="0 0 24 24"><path d="M3 12V4h8l10 10-8 8L3 12zM7.5 7.5h.01"/></symbol>
<symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/></symbol>
<symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3l10 18H2L12 3zM12 10v5M12 18h.01"/></symbol>
<symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 12a8 8 0 1 1-2.3-5.7L20 9M20 4v5h-5"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2"/></symbol>
<symbol id="i-pause" viewBox="0 0 24 24"><path d="M9 5v14M15 5v14"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="i-share" viewBox="0 0 24 24"><path d="M12 15V3M8 7l4-4 4 4M5 11v9h14v-9"/></symbol>
<symbol id="i-user" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0"/></symbol>
<symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4l2-2zM10 21h4"/></symbol>
<symbol id="i-lock" viewBox="0 0 24 24"><path d="M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3"/></symbol>
</svg>
<div id="oa-app" class="oa-root"><main class="oa-auth"><div class="oa-brand"><span class="oa-brand__mark" aria-hidden="true">KB</span><span class="oa-brand__name">K-Beauty Bliss <b>Owner</b></span></div></main></div>
<div id="oa-sheet" class="oa-sheet" hidden></div>
<div id="oa-toast" class="oa-toast" role="status" aria-live="polite"></div>
<div id="oa-refresh" class="oa-refresh" role="status" aria-live="polite" aria-hidden="false"><div class="oa-refresh__card"><span class="oa-refresh__spin" aria-hidden="true"></span><p>Refreshing the app</p></div></div>
<noscript><p class="oa-noscript">This app needs JavaScript.</p></noscript>
</body>
</html>
