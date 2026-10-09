<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\InstagramEmbeds;
use App\Services\ModuleSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content → Instagram embeds.                                       (Lane IGE)
 *
 *   GET  admin-api/ig-embeds         the list, the options and the preview kit
 *   POST admin-api/ig-embeds/parse   read a paste; stores nothing
 *   POST admin-api/ig-embeds         save the options and the list
 *
 * All three are `igembeds.manage` (AdminCapabilities), inside the guarded
 * admin-api group. Nothing here is under /api/*.
 *
 * The list arrives as {c, k, l, on} rows and goes through
 * InstagramEmbeds::clean(), which keeps only a kind from a two-word set and a
 * shortcode that matched CODE_RE — a hand-made POST cannot store a URL, a host
 * or markup, because there is no column for one.
 */
final class InstagramEmbedsApiController extends Controller
{
    public function __construct(private InstagramEmbeds $embeds) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function parse(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:20000']]);

        return response()->json(InstagramEmbeds::parseMany($data['text']));
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'options' => ['sometimes', 'array'],
            'items' => ['sometimes', 'array', 'max:'.InstagramEmbeds::MAX_ITEMS],
        ]);

        $result = $this->embeds->save($data['options'] ?? [], $data['items'] ?? null);

        if ($result['rejected'] !== []) {
            return response()->json([
                'error' => 'Not saved: '.implode(', ', $result['rejected']).'.',
                'rejected' => $result['rejected'],
            ] + $this->payload(), 422);
        }

        return response()->json(['saved' => true] + $this->payload());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $values = $this->embeds->options();
        $fields = [];

        foreach (ModuleSchema::normalise(InstagramEmbeds::SCHEMA, InstagramEmbeds::POLICY) as $key => $field) {
            $fields[] = [
                'key' => $key,
                'type' => $field['type'],
                'label' => $field['label'],
                'help' => (string) ($field['help'] ?? ''),
                'default' => $field['default'],
                'options' => $field['type'] === 'select' ? $this->options($field['options']) : null,
                'value' => $values[$key],
            ];
        }

        return [
            'fields' => $fields,
            'items' => $this->embeds->items(),
            'max_items' => InstagramEmbeds::MAX_ITEMS,
            'label_max' => InstagramEmbeds::LABEL_MAX,
            'styles' => InstagramEmbeds::STYLES,
            'css' => InstagramEmbeds::css(),
            'glyph' => InstagramEmbeds::GLYPH,
            'origin' => InstagramEmbeds::ORIGIN,
            'sandbox' => InstagramEmbeds::SANDBOX,
            'shortcode' => '[kbb_instagram_embeds]',
        ];
    }

    /**
     * Options as an ordered list: a JSON object with numeric keys ('-60', '0',
     * '3') is re-ordered by the browser, so the screen would show 0 before -60.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function options(array $options): array
    {
        $out = [];

        foreach ($options as $value => $label) {
            $out[] = [(string) $value, (string) $label];
        }

        return $out;
    }
}
