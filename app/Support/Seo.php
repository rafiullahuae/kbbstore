<?php

namespace App\Support;

use App\Services\Seo\MetaBuilder;
use App\Services\Seo\SeoSettings;

/**
 * The storefront's <head> SEO block: title, meta description, canonical, Open
 * Graph, Twitter cards, verification tokens and JSON-LD.
 *
 * Every value comes from the settings the "SEO & Meta" admin screen writes.
 * The work happens in App\Services\Seo\MetaBuilder; this is the entry point the
 * layout calls.
 *
 * tags() returns data and the layout prints it with Blade's {{ }}. render()
 * builds the same block as an HTML string and survives only for callers
 * outside this repo (an update package, a one-off script) — nothing in the
 * storefront uses it, and new code should not.
 */
class Seo
{
    /**
     * Everything the layout needs, escaped by Blade at the point of printing.
     *
     * @param  array<string, mixed>  $ctx  type,title,title_is_final,description,image,url,noindex,product,article,breadcrumb
     * @return array{title: string, meta: array<int, array{attr: string, key: string, content: string}>, canonical: ?string, jsonld: array<int, array<string, mixed>>, ga: ?string}
     */
    public static function tags(array $ctx = []): array
    {
        return (new MetaBuilder(new SeoSettings))->build($ctx);
    }

    /**
     * @deprecated Use tags() and print the values through Blade.
     *
     * @param  array<string, mixed>  $ctx
     */
    public static function render(array $ctx = []): string
    {
        $data = self::tags($ctx);
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $out = ['<title>'.$e($data['title']).'</title>'];

        foreach ($data['meta'] as $tag) {
            $out[] = '<meta '.$tag['attr'].'="'.$e($tag['key']).'" content="'.$e($tag['content']).'">';
        }

        if ($data['canonical'] !== null) {
            $out[] = '<link rel="canonical" href="'.$e($data['canonical']).'">';
        }

        foreach ($data['jsonld'] as $node) {
            $out[] = '<script type="application/ld+json">'.self::json($node).'</script>';
        }

        if ($data['ga'] !== null) {
            $out[] = '<script async src="https://www.googletagmanager.com/gtag/js?id='.$e($data['ga']).'"></script>';
            $out[] = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','".$data['ga']."');</script>";
        }

        return "\n".implode("\n", $out)."\n";
    }

    /**
     * JSON-LD, encoded so it cannot escape its own <script> element.
     *
     * JSON_HEX_TAG is the load-bearing flag: without it an org_name of
     * "</script><script>…" closes the block and the rest of the setting is
     * executed as JavaScript. The previous encoder passed JSON_UNESCAPED_SLASHES,
     * which switched off even the \/ escaping that would have stopped it.
     *
     * @param  array<string, mixed>  $node
     */
    public static function json(array $node): string
    {
        return (string) json_encode(
            $node,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }
}
