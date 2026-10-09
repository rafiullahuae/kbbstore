<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * The Analytics board's block order and hidden blocks, per admin. (Lane AN2)
 *
 * ALLOWLISTED BOTH WAYS. Only ids in BLOCKS are kept, each once; an id the
 * board no longer has is dropped, and a block added after the layout was saved
 * is appended where the default order puts it -- so a future block always
 * shows. Hidden ids are a subset of the same list. The console and the owner
 * app read the same row (both sign in as the same admin user).
 */
final class BoardLayout
{
    public const BOARD = 'analytics';

    /** Every block, in the default order. */
    public const BLOCKS = [
        'live', 'feed', 'pagesnow', 'srcnow', 'ccnow', 'strip',
        'daily', 'pages', 'sources', 'revsrc', 'campaigns', 'utm', 'funnel', 'entry',
        'search', 'google', 'referrers', 'devices', 'langs',
    ];

    /**
     * @return array{order: list<string>, hidden: list<string>, custom: bool}
     */
    public static function sanitize(mixed $order, mixed $hidden): array
    {
        $keep = [];
        foreach (is_array($order) ? $order : [] as $id) {
            if (is_string($id) && in_array($id, self::BLOCKS, true) && ! in_array($id, $keep, true)) {
                $keep[] = $id;
            }
        }

        // Missing blocks go back in at their default place: after the block
        // that precedes them in BLOCKS, or first.
        foreach (self::BLOCKS as $i => $id) {
            if (in_array($id, $keep, true)) {
                continue;
            }
            $after = $i > 0 ? array_search(self::BLOCKS[$i - 1], $keep, true) : false;
            array_splice($keep, $after === false ? ($i === 0 ? 0 : count($keep)) : $after + 1, 0, [$id]);
        }

        $off = [];
        foreach (is_array($hidden) ? $hidden : [] as $id) {
            if (is_string($id) && in_array($id, self::BLOCKS, true) && ! in_array($id, $off, true)) {
                $off[] = $id;
            }
        }

        return ['order' => $keep, 'hidden' => $off, 'custom' => $keep !== self::BLOCKS || $off !== []];
    }

    /** @return array{order: list<string>, hidden: list<string>, custom: bool} */
    public static function get(?int $adminId): array
    {
        $raw = $adminId === null ? null : DB::table('admin_board_layouts')
            ->where('admin_user_id', $adminId)->where('board', self::BOARD)->value('layout');
        $j = is_string($raw) ? json_decode($raw, true) : null;

        return self::sanitize($j['order'] ?? null, $j['hidden'] ?? null);
    }

    /** @return array{order: list<string>, hidden: list<string>, custom: bool} */
    public static function put(int $adminId, mixed $order, mixed $hidden): array
    {
        $clean = self::sanitize($order, $hidden);
        DB::table('admin_board_layouts')->upsert(
            [['admin_user_id' => $adminId, 'board' => self::BOARD, 'layout' => json_encode(['order' => $clean['order'], 'hidden' => $clean['hidden']]),
                'created_at' => now(), 'updated_at' => now()]],
            ['admin_user_id', 'board'],
            ['layout', 'updated_at'],
        );

        return $clean;
    }

    /** @return array{order: list<string>, hidden: list<string>, custom: bool} */
    public static function reset(int $adminId): array
    {
        DB::table('admin_board_layouts')->where('admin_user_id', $adminId)->where('board', self::BOARD)->delete();

        return self::sanitize(null, null);
    }
}
