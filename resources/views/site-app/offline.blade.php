{{--
    The shop's offline page (Lane PW, App -> Site App).

    Precached by the service worker and shown ONLY when a page could not be
    fetched at all. The same bytes for everybody: no bag, no name, no CSRF
    token, no session (the route sits in the stateless group), so it is safe
    to keep on a phone. The page the shopper asked for is not known here, so
    "Try again" is a plain link to the home page; the browser's own reload
    works too.
--}}
@php
    $ar = \App\Support\Locale::current() === 'ar';
    $t = $ar
        ? ['title' => 'أنت غير متصل', 'body' => 'تعذّر تحميل هذه الصفحة. تحقّق من اتصالك بالإنترنت ثم حاول مجددًا.', 'again' => 'حاول مجددًا']
        : ['title' => 'You are offline', 'body' => 'This page could not load. Check your connection, then try again.', 'again' => 'Try again'];
@endphp
<!DOCTYPE html>
<html lang="{{ \App\Support\Locale::htmlLang() }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $t['title'] }} · {{ $name }}</title>
<style>
html,body{margin:0;min-height:100%;background:#FFF8F5;color:#2A2228;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Tahoma,sans-serif}
main{min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:24px 16px;box-sizing:border-box;text-align:center}
img{width:72px;height:72px;border-radius:18px;display:block;margin:0 auto 18px}
h1{font-size:22px;margin:0 0 8px;font-weight:700}
p{margin:0 auto 20px;max-width:30ch;color:#6B5F68}
a{display:inline-block;background:#E0567B;color:#fff;text-decoration:none;font-weight:700;border-radius:999px;padding:12px 26px}
</style>
</head>
<body>
<main><div><img src="{{ $icon }}" alt="" width="72" height="72"><h1>{{ $t['title'] }}</h1><p>{{ $t['body'] }}</p><a href="{{ $home }}">{{ $t['again'] }}</a></div></main>
</body>
</html>
