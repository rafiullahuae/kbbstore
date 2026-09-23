<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SlimFooter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Footer.
 *
 * The field/tab loop is the one every module API controller in this project
 * has, kept in that shape deliberately: the admin screens that draw it are
 * generic, and a controller answering in a different shape would need a
 * renderer of its own.
 *
 * Two endpoints and nothing else. This screen picks no products and reads no
 * model — every value that crosses is a string, an integer or a boolean from a
 * schema both sides already know.
 */
class SlimFooterApiController extends Controller
{
    public function __construct(private SlimFooter $footer) {}

    public function show(): JsonResponse
    {
        $values = $this->footer->all();
        $fields = [];

        foreach (SlimFooter::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (SlimFooter::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

        return response()->json(['tabs' => $tabs]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(SlimFooter::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $this->footer->save($data['settings']);

        return response()->json(['ok' => true]);
    }
}
