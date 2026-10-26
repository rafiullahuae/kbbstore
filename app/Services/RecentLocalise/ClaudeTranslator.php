<?php

declare(strict_types=1);

namespace App\Services\RecentLocalise;

use App\Services\ConcernSync\ClaudeConcernSuggester;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Skincare English -> Arabic through the Claude API, on the owner's own key. (Lane LT)
 *
 * The key and model are the ones Catalog -> Shop by Concern already stores
 * (ConcernSyncCredentials): one Claude key for the shop, no second secret.
 *
 * WHAT LEAVES THE SHOP, per item: an index, the field's kind (name, short
 * description, description, ingredients, how to use) and its English text.
 * No product id, price, stock, SKU, customer or order data. Pinned by
 * RecentLocaliseTest ("sends only index, kind and text").
 *
 * Every answer is checked by HtmlStructure::problem() before anything is
 * stored: the tag/attribute sequence must be identical to the English, every
 * number must survive, and the answer must be Arabic. A failing item is
 * reported with its reason and never written.
 */
final class ClaudeTranslator
{
    public const ENDPOINT = ClaudeConcernSuggester::ENDPOINT;

    /** Source characters per request, and items per request. */
    public const BATCH_CHARS = 6000;

    public const BATCH_ITEMS = 25;

    /** A single field longer than this is not sent: it would not fit one answer. */
    public const MAX_FIELD_CHARS = 12000;

    public const MAX_TOKENS = 20000;

    public const KINDS = [
        'name' => 'product name',
        'short_description' => 'short description (HTML)',
        'description' => 'full description (HTML)',
        'ingredients' => 'ingredient list (HTML)',
        'how_to_use' => 'how to use (HTML)',
    ];

    public const SYSTEM = 'You translate product copy for a Korean-beauty skincare shop in the UAE from English into Arabic. '
        .'Write natural, warm Modern Standard Arabic that Gulf shoppers read easily, addressed to women (use feminine forms such as "استخدمي" and "بشرتكِ" where the text addresses the reader). '
        .'Keep brand names, product line names and INCI ingredient names exactly as written in Latin letters (for example COSRX, Anua, Niacinamide, Centella Asiatica Extract, Sodium Hyaluronate); you may add a common Arabic word before them, never replace them. '
        .'Copy every number exactly, in Western digits (0-9), with its unit and symbol (50ml, 2.5%, SPF50+, PA++++, 1,000 ppm) - you may write the unit word in Arabic only when the number is unchanged. '
        .'The text may contain HTML. Keep every tag and every attribute exactly as it is, in the same order, with the same values; translate only the words between tags. Do not add, remove, merge or reorder tags, and do not translate attribute values, URLs or class names. '
        .'Do not add claims, warnings or words that are not in the English, and do not leave anything out. '
        .'A product name: translate the descriptive words, keep the brand and line names in Latin. '
        .'Return one entry per item, with the same index, and nothing else.';

    /**
     * Split items into request-sized batches, in order.
     *
     * @param  list<array{key:string, field:string, english:string}>  $items
     * @return list<list<array{key:string, field:string, english:string}>>
     */
    public static function batches(array $items): array
    {
        $out = [];
        $cur = [];
        $chars = 0;

        foreach ($items as $item) {
            $len = mb_strlen($item['english'], 'UTF-8');

            if ($cur !== [] && ($chars + $len > self::BATCH_CHARS || count($cur) >= self::BATCH_ITEMS)) {
                $out[] = $cur;
                $cur = [];
                $chars = 0;
            }

            $cur[] = $item;
            $chars += $len;
        }

        if ($cur !== []) {
            $out[] = $cur;
        }

        return $out;
    }

    /** The request body for one batch. Public for the tests. */
    public static function body(array $batch, string $model): array
    {
        $items = [];
        foreach (array_values($batch) as $i => $item) {
            $items[] = ['i' => $i, 'kind' => self::KINDS[$item['field']] ?? 'text', 'text' => (string) $item['english']];
        }

        return [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => self::SYSTEM,
            'messages' => [['role' => 'user', 'content' => "Translate each item's text into Arabic.\n\n"
                .json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'output_config' => [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => [
                    'type' => 'object',
                    'properties' => ['translations' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => ['i' => ['type' => 'integer'], 'ar' => ['type' => 'string']],
                        'required' => ['i', 'ar'],
                        'additionalProperties' => false,
                    ]]],
                    'required' => ['translations'],
                    'additionalProperties' => false,
                ]],
            ],
        ];
    }

    /**
     * Send the batches together and check every answer.
     *
     * @param  list<list<array{key:string, field:string, english:string}>>  $batches
     * @return array{ok: array<string,string>, rejected: array<string,string>, error: ?string}
     *         ok = key => Arabic that passed every check; rejected = key => why
     */
    public static function translate(array $batches, string $key, string $model): array
    {
        if ($batches === []) {
            return ['ok' => [], 'rejected' => [], 'error' => null];
        }

        $bodies = array_map(static fn (array $b): array => self::body($b, $model), $batches);

        $responses = Http::pool(function (Pool $pool) use ($bodies, $key) {
            $out = [];
            foreach ($bodies as $i => $body) {
                $out[] = $pool->as((string) $i)
                    ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                    ->acceptJson()->asJson()->timeout(170)->connectTimeout(10)
                    ->post(self::ENDPOINT, $body);
            }

            return $out;
        });

        $ok = [];
        $rejected = [];
        $error = null;

        foreach ($batches as $i => $batch) {
            $res = $responses[(string) $i] ?? null;
            [$answers, $why] = $res instanceof Response ? self::parse($res, count($batch)) : [null, 'no answer (network)'];

            if ($answers === null) {
                // Not "rejected": nothing came back to judge. These stay pending
                // and the next press sends them again.
                $error ??= $why;
                Log::error('Recent localise: Claude API batch failed — '.$why);
                continue;
            }

            foreach (array_values($batch) as $j => $item) {
                $ar = $answers[$j] ?? null;
                if ($ar === null) {
                    $rejected[$item['key']] = 'no translation came back for it';
                    continue;
                }

                $problem = HtmlStructure::problem($item['english'], $ar);
                if ($problem !== null) {
                    $rejected[$item['key']] = $problem;
                    continue;
                }

                $ok[$item['key']] = $ar;
            }
        }

        return ['ok' => $ok, 'rejected' => $rejected, 'error' => $error];
    }

    /**
     * One response: [index => Arabic] or [null, why]. An index outside the
     * batch, or a duplicate, is ignored -- an answer is matched to its item by
     * the index it carries, never by position.
     *
     * @return array{0: ?array<int,string>, 1: ?string}
     */
    public static function parse(Response $res, int $count): array
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
        if (! is_array($data) || ! is_array($data['translations'] ?? null)) {
            return [null, 'the answer was not the JSON asked for'];
        }

        $out = [];
        foreach ($data['translations'] as $row) {
            if (! is_array($row) || ! is_int($row['i'] ?? null) || ! is_string($row['ar'] ?? null)) {
                continue;
            }
            if ($row['i'] < 0 || $row['i'] >= $count || isset($out[$row['i']])) {
                continue;
            }
            $out[$row['i']] = $row['ar'];
        }

        return [$out, null];
    }
}
