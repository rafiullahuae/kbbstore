{{--
    THE ARABIC TYPEFACE FOR A DOCUMENT THAT CARRIES ITS OWN <head>.

    Five storefront views do not extend layouts/store.blade.php — store/blog,
    store/post, store/skin-quiz, store/app and store/review-wall. Lane FK gave
    them a real <html lang> and <html dir>, so they are bilingual documents now,
    and measured what that exposed: none of them linked an Arabic-capable webfont
    and none of them named one. /ar/skincare-guide/, /ar/<post>/, /ar/skin-quiz/
    and /ar/reviews/ linked Poppins only; /ar/app/ linked Fraunces and Hanken
    Grotesk; all five named Cairo ZERO times against five font-family rules on
    the /ar/shop/ control. Poppins, Fraunces and Hanken Grotesk carry no Arabic
    glyphs at all, so every Arabic word on those pages fell through to whatever
    face the device happened to have.

    ONE PARTIAL RATHER THAN FIVE COPIES, because the two things that are easy to
    get wrong are the same two things in every document:

      APPEND, NEVER SUBSTITUTE. App\Support\ArabicFace::append() INSERTS Cairo
      after the document’s own first family. The caller passes its Latin stack
      verbatim and there is no spelling of the argument that puts Cairo first, so
      Latin still renders in the brand face by per-codepoint selection — the
      wordmark, the prices and every English word on a mixed page.

      GATED ON THE LANGUAGE, NEVER ON THE DIRECTION. Locale::current(), not
      Locale::isRtl(). Arabic with the mirrored layout switched off is a state
      the owner can choose from the Translation console, and Arabic words need
      Arabic glyphs in that state too.

    NOTHING IS EMITTED ON AN ENGLISH PAGE — not the link, not the style block,
    not a newline. The @if wraps the whole file, the file ends at @endif with no
    trailing newline, and each caller glues the include between @endverbatim and
    @verbatim so no whitespace is introduced either. Measured: the five English
    documents come back byte-identical, which StorefrontEnglishUnchangedTest also
    pins. The rules are additionally scoped to html[lang="ar"], which cannot
    match an English document.

    SPECIFICITY, so this wins wherever the document’s own <style> sits. These
    documents set their tokens on `:root` (0,1,0) and their body font on `body`
    (0,0,1); html[lang="ar"] is (0,1,1) and html[lang="ar"] body is (0,1,2), so
    source order is not relied on.

    Parameters:
      $weights  Cairo weight list, matching the document’s own Latin link. Every
                weight maps to the SAME variable WOFF2 — measured in
                App\Support\ArabicFace — so a weight costs stylesheet bytes on
                Arabic pages and no font bytes at all.
      $stacks   selector => [property => the document’s own Latin stack]. The key
                ":root" is emitted as html[lang="ar"]; anything else is scoped
                under it.
--}}@if (\App\Support\Locale::current() !== \App\Support\Locale::DEFAULT)
@php
    $kbbFaceRules = [];

    foreach ($stacks as $kbbFaceSelector => $kbbFaceDecls) {
        $kbbFaceScoped = $kbbFaceSelector === ":root"
            ? "html[lang=\"ar\"]"
            : "html[lang=\"ar\"] " . $kbbFaceSelector;

        $kbbFaceBody = [];

        foreach ($kbbFaceDecls as $kbbFaceProperty => $kbbFaceStack) {
            $kbbFaceBody[] = $kbbFaceProperty . ":" . \App\Support\ArabicFace::append($kbbFaceStack);
        }

        $kbbFaceRules[] = $kbbFaceScoped . "{" . implode(";", $kbbFaceBody) . "}";
    }
@endphp

{{-- CAIRO IS SERVED BY THIS SHOP, NOT BY GOOGLE — Lane PERF.

     Same change as layouts/store.blade.php, and it belongs here for a reason
     beyond consistency: these five documents are the ones a shopper reads, and
     the round trip this removes is the same two-hop one that cost the homepage
     most of its Speed Index (App\Support\WebFonts carries the measurement).
     Cairo's four weights are ONE variable file per subset, and an Arabic page
     fetches exactly the `arabic` one.

     $weights IS NO LONGER READ, and the parameter stays in every caller on
     purpose: it is the DOCUMENT's own Latin weight list, the callers pass it to
     say which weights their stacks use, and App\Support\ArabicFace's measurement
     that a weight costs no font bytes is what makes shipping all twelve faces
     free. Taking it out of five call sites to delete one argument is five files
     of churn for nothing.

     THEIR LATIN FACES ARE STILL GOOGLE'S. Poppins at 300 and 500, Fraunces and
     Hanken Grotesk are linked by these documents' own <head> at weights this
     package does not carry, so their preconnect hints are still doing work and
     are deliberately left alone. --}}
{!! \App\Support\WebFonts::preloadTags(\App\Support\WebFonts::CAIRO) !!}<style id="kbb-cairo">{!! \App\Support\WebFonts::faceCss(\App\Support\WebFonts::CAIRO) !!}</style>
<style id="kbb-arabic-face">
{!! implode("\n", $kbbFaceRules) !!}
</style>@endif