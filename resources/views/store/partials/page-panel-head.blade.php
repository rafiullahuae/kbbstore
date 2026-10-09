{{--
    The page header's stylesheet (Lane PH): the brand page's own sheet and the
    category banner's few rules (store/partials/category-panel-head says why
    each is there), plus BrandPanel::PAGE_CSS for the content page's
    `.kbb-home h1` letter-spacing -- one <style>, inline, so it is no new
    request. Pushed to the head only when the page draws the header; a page on
    "Normal page banner" gains no byte. Raw output is the two constants only.
--}}
@vite('resources/css/kbb/kbb-brand-header.css')
<style>{!! \App\Support\BrandPanel::CATEGORY_CSS.\App\Support\BrandPanel::PAGE_CSS !!}</style>
