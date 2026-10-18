{{--
    PHONES SET TO LARGE TEXT SEE THE NORMAL SHOP.                     (Lane BT)

    The owner, 17 October: a customer opened a product from Instagram on an
    Android phone set to large text, and every word on the page was huge -- the
    tagline on three lines, the wordmark over the Arabic chip, the badge, the
    brand chip and the title -- while the pictures and icons stayed their size.
    "can we restrict our site to not zoom and open the normal site which we have
    in actual" ... "keep that fully separate and the site should auto detect".

    THE MECHANISM, read in Chromium 141's own source, not guessed:
      AwSettings.java    mTextSizePercent = 100 x Configuration.fontScale
                         ("By default, scale the text size by the system font
                         scale factor") -- every WebView, Instagram's included.
      aw_settings.cc     layout algorithm is not TEXT_AUTOSIZING (the default),
                         so SetTextZoomFactor(text_size_percent / 100).
      font_builder.cc    computed font-size = specified x zoom x
                         frame->TextZoomFactor(), unconditionally.
      style_builder_converter.cc  a px / calc line-height x TextZoomFactor too.
    Nothing else moves: widths, paddings, em lengths, pictures, icons. And
    -webkit-text-size-adjust is consulted ONLY when text autosizing is on, so
    the `html{-webkit-text-size-adjust:100%}` the shop already has cannot stop
    it, and no CSS property or meta tag can. Measured by calling the real
    blink::LocalFrame::SetTextZoomFactor in Chromium 141 (tools/bt-shots.cjs):
    a 16px line becomes 25.6px at 1.6, a 20px line-height 32px, an image 50px.

    DETECTION: this <script> carries its own font-size:16px. It renders nothing
    (a script is display:none), so reading its computed size is a style read,
    not a layout read, and it is 16px on every browser that is not enlarging
    text -- page zoom, pinch zoom and desktop zoom all report 16px. Read ONCE.
    (em media queries cannot see text zoom: measured, they answer the same.)

    A NORMAL VISITOR: that one read and nothing else -- no class, no rule, no
    request. ~300 bytes of HTML.

    AN ENLARGED VISITOR (more than 4%): the corrections live in
    resources/js/kbb/big-text.js, written in with document.write so the file is
    parser-blocking and runs before the rest of <body> paints -- that phone
    never sees a frame of huge text -- while a normal phone never requests it.
    It puts the shop's own sizes back (font-size and px line-height / zoom,
    em lengths x zoom, SVG text alone, float32-exact) and adds `kbb-bt` to
    <html>; its header has every measured reason. The viewport tag is
    untouched: a shopper can still pinch to zoom.

    No "<" in the script below: the string escape \x3c writes the tag, so
    strip_tags() and anything else that reads a page's words still read them
    (a "<" here swallowed homepage text in TrustClaimsAreTheOwnersTest).

    Appearance -> Site layout -> Large phone text. "Let the phone enlarge the
    text" prints nothing here, which is the shop byte for byte as it was.
    BigTextGuardTest pins all of it.
--}}<script style="font-size:16px">(function(d){var s=d.currentScript,r=s?parseFloat(getComputedStyle(s).fontSize)/16:1,l='\x3c';if(r>1.04)d.write(l+'script src="{{ \Illuminate\Support\Facades\Vite::asset('resources/js/kbb/big-text.js') }}" data-kbb-zoom="'+r+'">'+l+'/script>')})(document)</script>
