<?php

declare(strict_types=1);

namespace App\Services\ConcernSync;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Concern suggestions from the Claude API (Anthropic Messages API). (Lane SC)
 *
 * Plain HTTP through Laravel's client -- no new dependency. One request per
 * batch of BATCH products, the batches sent together (Http::pool), so a Sync
 * of 40 products is two requests in parallel and nothing else. Structured
 * output (output_config.format json_schema) asks for JSON only; the answer is
 * still parsed defensively, and every slug must be one of the shop's concerns
 * and every id one this batch asked about, or it is dropped.
 *
 * WHAT LEAVES THE SHOP: product id, name, brand and short plain text of the
 * short description, ingredients and description (tags stripped, each capped
 * at ConcernSync::TEXT_CAP), plus the concern names. No price, stock, sku,
 * customer or order data, and the key goes only in the x-api-key header.
 */
final class ClaudeConcernSuggester
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const BATCH = 20;

    public const MAX_TOKENS = 4000;

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, array{id:int, name:string}>  $concerns
     * @return array{suggestions: array<int, list<array{slug:string, reason:string}>>, failed: list<int>, error: ?string}
     *         failed = product ids whose batch got no usable answer (the caller falls back to rules for them)
     */
    public static function suggest(array $products, array $concerns, string $key, string $model): array
    {
        $batches = array_chunk($products, self::BATCH);
        $bodies = [];
        foreach ($batches as $i => $batch) {
            $bodies[$i] = self::body($batch, $concerns, $model);
        }

        $responses = Http::pool(function (Pool $pool) use ($bodies, $key) {
            $out = [];
            foreach ($bodies as $i => $body) {
                $out[] = $pool->as((string) $i)
                    ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                    ->acceptJson()->asJson()->timeout(90)->connectTimeout(10)
                    ->post(self::ENDPOINT, $body);
            }

            return $out;
        });

        $suggestions = [];
        $failed = [];
        $error = null;

        foreach ($batches as $i => $batch) {
            $ids = array_map(static fn ($p) => (int) $p['id'], $batch);
            $res = $responses[(string) $i] ?? null;

            [$parsed, $why] = $res instanceof Response ? self::parse($res, $ids, $concerns) : [null, 'no answer (network)'];

            if ($parsed === null) {
                $failed = array_merge($failed, $ids);
                $error ??= $why;
                Log::error('Concern sync: Claude API batch failed — '.$why);
                continue;
            }

            $suggestions += $parsed;
        }

        return ['suggestions' => $suggestions, 'failed' => $failed, 'error' => $error];
    }

    /** The request body for one batch. Public for the tests. */
    public static function body(array $batch, array $concerns, string $model): array
    {
        $list = [];
        foreach ($concerns as $slug => $c) {
            $list[] = ['slug' => $slug, 'name' => $c['name']];
        }

        $items = array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'brand' => (string) ($p['brand'] ?? ''),
            'short_description' => (string) ($p['short'] ?? ''),
            'ingredients' => (string) ($p['ingredients'] ?? ''),
            'description' => (string) ($p['description'] ?? ''),
        // (Lane LT) The shop's own category names, when the caller sends them
        // (Catalog -> Translate & categorise does; Sync does not, so its
        // request is byte-identical to before): tells a cleanser from a serum.
        ] + (isset($p['type']) ? ['product_type' => (string) $p['type']] : []), $batch);
        $typed = array_filter($batch, static fn (array $p): bool => isset($p['type'])) !== [];

        $system = 'You file products of a Korean-beauty shop into its "Shop by Skin Concern" collections. '
            .'Use only the collections listed, by slug. Assign a collection only when the product\'s own ingredients or claims '
            .'clearly support it (for example salicylic acid or BHA for acne and pores; niacinamide, arbutin, tranexamic acid, '
            .'kojic acid or vitamin C for pigmentation; retinol, retinal, peptides, PDRN, collagen or adenosine for anti-aging; '
            .'hyaluronic acid for hydration; centella, panthenol, ceramides or heartleaf for redness and sensitive skin; '
            .'sebum or oil control for oily skin). Hair, scalp, body, lip, make-up, tools and devices get no collection. '
            .'Be conservative: no collection is better than a wrong one. Give each assignment one short reason (under 20 words) '
            .'that names the ingredient or claim. Return every product id you were given, with an empty list when nothing fits.';
        if ($typed) {
            $system .= ' product_type is the shop\'s own category for the product (cleanser, serum, sunscreen...): read it with the ingredients, '
                .'and judge each product on its key actives at their likely strength, not on a single word in a long ingredient list.';
        }

        $user = "Collections:\n".json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nProducts:\n".json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema(array_keys($concerns))]],
        ];
    }

    /**
     * Parse one response. Returns [id => suggestions, null] or [null, why].
     *
     * @param  list<int>  $ids
     * @return array{0: ?array<int, list<array{slug:string, reason:string}>>, 1: ?string}
     */
    public static function parse(Response $res, array $ids, array $concerns): array
    {
        if (! $res->successful()) {
            $type = $res->json('error.type');

            return [null, 'HTTP '.$res->status().(is_string($type) ? ' '.preg_replace('/[^a-z_]/', '', $type) : '')];
        }

        $stop = $res->json('stop_reason');
        if ($stop === 'refusal' || $stop === 'max_tokens') {
            return [null, 'the answer stopped early ('.$stop.')'];
        }

        $text = '';
        foreach ((array) $res->json('content') as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        $data = json_decode(trim($text), true);
        if (! is_array($data) && preg_match('/\{.*\}/s', $text, $m) === 1) {
            $data = json_decode($m[0], true);
        }

        if (! is_array($data) || ! is_array($data['products'] ?? null)) {
            return [null, 'the answer was not the JSON asked for'];
        }

        $allowed = array_flip($ids);
        $out = [];

        foreach ($data['products'] as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || ! isset($allowed[$row['id']]) || ! is_array($row['concerns'] ?? null)) {
                continue;
            }

            $list = [];
            foreach ($row['concerns'] as $c) {
                $slug = is_array($c) ? ($c['slug'] ?? null) : null;
                if (! is_string($slug) || ! isset($concerns[$slug]) || isset($list[$slug])) {
                    continue;
                }

                $reason = ConcernSync::plain(is_string($c['reason'] ?? null) ? $c['reason'] : '', 200);
                $list[$slug] = ['slug' => $slug, 'reason' => $reason];
            }

            $out[$row['id']] = array_values($list);
        }

        // Every id asked about gets an entry, so "Claude found nothing" is not
        // mistaken for "Claude was not asked".
        foreach ($ids as $id) {
            $out[$id] ??= [];
        }

        return [$out, null];
    }

    /** @param list<string> $slugs */
    private static function schema(array $slugs): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'products' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'concerns' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'slug' => ['type' => 'string', 'enum' => array_values($slugs)],
                                        'reason' => ['type' => 'string'],
                                    ],
                                    'required' => ['slug', 'reason'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['id', 'concerns'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['products'],
            'additionalProperties' => false,
        ];
    }
}
