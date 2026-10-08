{{--
    The category banner's stylesheet (Lane CB): the brand page's own sheet,
    and the few rules that live in the brand view rather than in that sheet
    (the logo circle and the description's paragraphs, store/brands), scoped
    to the category banner's wrapper. Pushed to the head only when a category
    draws the banner -- a category without one gains no byte -- and inline, so
    it is no new request.

    .kbb-cbw is the brand page's .brw for the header: the same 22px above it
    that the header's "space above" counts from, so every control means on a
    category what it means on a brand. It sits in its OWN box below the
    breadcrumb's, so however small "space above" is set, the banner can never
    rise over the trail (the brand page's overlap, fixed in HeaderSettings::
    breadcrumbCss for that page).
--}}
@vite('resources/css/kbb/kbb-brand-header.css')
<style>{!! \App\Support\BrandPanel::CATEGORY_CSS !!}</style>
