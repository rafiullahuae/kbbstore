{{--
    Appearance → Header → Breadcrumbs.                            (Lane PI-B)

    The trail's two switches and four spacing sliders, as one <style>. Included
    from layouts/store.blade.php and from store/post.blade.php — the one
    standalone document that draws a trail; the journal index and the review
    wall have none (their "Home" is a navigation link, not a breadcrumb).

    {{ }} AND NOT {!! !!}: HeaderSettings::breadcrumbCss() is literals and
    clamped integers, with none of the five characters escaping rewrites, so
    escaping it costs nothing and makes "a setting printed raw" impossible
    here by construction. BreadcrumbControlsTest pins that the escaped output is
    byte-identical to the method's.

    The JSON-LD BreadcrumbList is not in this file and not in the trail's
    markup: App\Support\Seo prints it from the controller, so hiding the
    visible line never takes the structured data with it.
--}}<style id="kbb-crumbs">{{ app(\App\Services\HeaderSettings::class)->breadcrumbCss() }}</style>
