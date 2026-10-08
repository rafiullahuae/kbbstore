<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\OldServerPictures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Fetch missing pictures from the old server" (Lane PX). Shown in
 * Store → Import and in Platform → Domain switch, step "Done".
 *
 * Routes live in routes/media-sideload-admin.php, under /urls-media/, so the
 * capability is AdminCapabilities' existing ['*', 'admin-api/urls-media/**',
 * 'data.import'] -- the sideloader's own, failing closed for anyone without it.
 * Writes bytes into the web root and talks to another server, so every action
 * is a POST; the action, the address and the flags are validated here.
 */
class OldServerPicturesApiController extends Controller
{
    /** GET /admin-api/urls-media/old-server -- the counts and the list. No network, no scan. */
    public function show(): JsonResponse
    {
        $p = $this->service();

        return response()->json($p->summary() + ['missing_list' => $p->missingList(200)]);
    }

    /** POST /admin-api/urls-media/old-server {action: check|fetch|stop|retry} */
    public function act(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:check,fetch,stop,retry'],
            'ip' => ['sometimes', 'nullable', 'string', 'max:64'],
            'http' => ['sometimes', 'boolean'],
            'continue' => ['sometimes', 'boolean'],
            'gone' => ['sometimes', 'boolean'],
        ]);

        $p = $this->service();

        if ($data['action'] === 'fetch') {
            $r = $p->batch([
                'ip' => (string) ($data['ip'] ?? $p->defaultIp()),
                'http' => (bool) ($data['http'] ?? false),
                'continue' => (bool) ($data['continue'] ?? false),
            ]);

            return response()->json($r + ['missing_list' => $p->missingList(200)], ($r['ok'] ?? false) ? 200 : 422);
        }

        $message = match ($data['action']) {
            'check' => (function () use ($p): string {
                $s = $p->scan();

                return 'Checked: '.$s['referenced'].' picture(s) named, '.$s['missing'].' missing on this server.';
            })(),
            'stop' => (function () use ($p): string {
                $p->stop();

                return 'Stopped. Press Resume to carry on from where it stopped.';
            })(),
            'retry' => $p->retry((bool) ($data['gone'] ?? false)).' picture(s) put back in the queue. Press Fetch.',
        };

        return response()->json(['ok' => true, 'message' => $message] + $p->summary() + ['missing_list' => $p->missingList(200)]);
    }

    /** GET /admin-api/urls-media/old-server.csv -- every picture still missing, why, and who names it. */
    public function csv(): Response
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['path', 'state', 'http_status', 'reason', 'named_by']);

        foreach ($this->service()->missingList(100000) as $row) {
            fputcsv($out, array_map([self::class, 'cell'], [$row['path'], $row['state'], (string) ($row['status'] ?? ''), $row['reason'], implode(' | ', $row['owners'])]));
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="missing-pictures.csv"',
        ]);
    }

    /** A cell a spreadsheet will not run as a formula. */
    private static function cell(string $v): string
    {
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    private function service(): OldServerPictures
    {
        return app(OldServerPictures::class);
    }
}
