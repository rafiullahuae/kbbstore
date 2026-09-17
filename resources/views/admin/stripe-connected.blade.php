{{--
    The page the Stripe Connect popup ends on.

    It exists so the popup never sits there showing a JSON body. It tells the
    window that opened it what happened, closes itself, and — if the opener has
    gone away, which happens when the admin tab was closed mid-flow — leaves a
    readable sentence and a Close button behind instead of a blank frame.

    NO CREDENTIAL REACHES THIS FILE. The controller builds $payload from the
    service's return value, which carries the account report and the warnings
    and nothing else. There is no key here to leak into the browser's history,
    into an extension with host access, or into a screenshot.

    postMessage is addressed to this application's own origin rather than to
    '*', so the message cannot be read by a page on another origin that happens
    to be the opener.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $ok ? 'Stripe connected' : 'Stripe not connected' }}</title>
<style>
  :root { color-scheme: light dark; }
  body {
    margin: 0; min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: #f6f7f9; color: #1d2430; padding: 24px;
  }
  .box { max-width: 460px; text-align: center; background: #fff; border-radius: 14px; padding: 32px 28px; box-shadow: 0 1px 3px rgba(16,24,40,.1), 0 8px 24px rgba(16,24,40,.06); }
  .mark { width: 48px; height: 48px; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 26px; }
  .ok .mark { background: #e7f6ed; color: #1a7f45; }
  .no .mark { background: #fdecec; color: #b42318; }
  h1 { font-size: 18px; margin: 0 0 8px; }
  p { margin: 0 0 18px; color: #4a5567; }
  button { font: inherit; padding: 9px 18px; border-radius: 8px; border: 1px solid #d0d5dd; background: #fff; cursor: pointer; }
  @media (prefers-color-scheme: dark) {
    body { background: #14171c; color: #e7eaf0; }
    .box { background: #1c2027; box-shadow: none; }
    p { color: #a6b0c0; }
    button { background: #232830; border-color: #333a45; color: inherit; }
  }
</style>
</head>
<body class="{{ $ok ? 'ok' : 'no' }}">
  <div class="box">
    <div class="mark">{!! $ok ? '&check;' : '!' !!}</div>
    <h1>{{ $ok ? 'Stripe is connected' : 'Stripe was not connected' }}</h1>
    <p>{{ $ok
        ? 'You can close this window — the payments screen has been updated.'
        : ($message !== '' ? $message : 'Nothing was changed. Close this window and try again.') }}</p>
    <button type="button" onclick="window.close()">Close</button>
  </div>
<script>
(function () {
  var payload = @json($payload);

  try {
    if (window.opener && !window.opener.closed) {
      /* This page's own origin, which is the admin console's origin — the
         popup was opened by it and Stripe redirected back to it. An ORIGIN,
         not a URL: postMessage compares scheme, host and port, and a path on
         the end makes the comparison fail and the message vanish. */
      window.opener.postMessage({ source: 'kbb.stripe.connect', result: payload }, window.location.origin);
      /* Give the opener a tick to repaint before this window disappears; a
         popup that vanished instantly used to read as "nothing happened". */
      setTimeout(function () { window.close(); }, 900);
    }
  } catch (e) { /* An opener on another origin, or none. The page stands on its own. */ }
})();
</script>
</body>
</html>
