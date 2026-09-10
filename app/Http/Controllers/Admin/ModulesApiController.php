<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Store → Modules. */
class ModulesApiController extends Controller
{
    public function __construct(private ModuleRegistry $modules) {}

    public function show(): JsonResponse
    {
        $state = $this->modules->all();
        $groups = [];

        foreach (ModuleRegistry::GROUPS as $key => $label) {
            $groups[$key] = ['key' => $key, 'label' => $label, 'modules' => []];
        }

        foreach (ModuleRegistry::REGISTRY as $key => [$group, $name, $desc, $default, $screen, $route, $surface, $band, $where, $status]) {
            $groups[$group]['modules'][] = [
                'key' => $key,
                'name' => $name,
                'desc' => $desc,
                'default' => $default,
                'screen' => $screen,
                // The console route that screen answers to, so the row can link
                // straight there. Empty where the screen does not exist yet —
                // a link to nowhere is worse than saying so.
                'route' => $route,
                // For the hover card: which wireframe to draw, which strip of it
                // to light up, and the sentence under it.
                'surface' => $surface,
                'band' => $band,
                'where' => $where,
                // live | elsewhere | todo — see ModuleRegistry. The screen only
                // offers a working toggle for the live ones.
                'status' => $status,
                'on' => $state[$key]['on'],
                'device' => $state[$key]['device'],
            ];
        }

        return response()->json([
            'groups' => array_values($groups),
            'devices' => ModuleRegistry::DEVICES,
            'counts' => $this->modules->counts(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['modules' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['modules']), array_keys(ModuleRegistry::REGISTRY));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown module: ' . implode(', ', $unknown)], 422);
        }

        $this->modules->save($data['modules']);

        return response()->json(['ok' => true, 'counts' => $this->modules->counts()]);
    }
}
