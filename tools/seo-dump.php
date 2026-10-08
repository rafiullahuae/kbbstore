<?php
// Lane SEO: write the rendered HTML of home/product/category/brand (+ sitemap) to SEO_DUMP_DIR, for a before/after byte diff.
$dir = getenv('SEO_DUMP_DIR'); @mkdir($dir, 0775, true);
$pages = ['home' => '/', 'product' => '/product/seo-product-5/', 'product-simple' => '/product/seo-product-4/', 'category' => '/collections/seo-skincare/seo-toners/', 'brand' => '/brands/seo-lab/', 'sitemap' => '/sitemap.xml'];
foreach ($pages as $k => $p) {
    \App\Services\SettingsService::forgetMemo(); app()->forgetScopedInstances();
    $r = app(\Illuminate\Contracts\Http\Kernel::class)->handle(\Illuminate\Http\Request::create($p, 'GET'));
    file_put_contents("$dir/$k.html", preg_replace('/(name="_token" value="|csrf-token" content=")[^"]+/', '$1X', (string) $r->getContent()));
    echo "$k ".$r->getStatusCode()."\n";
}
