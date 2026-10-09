<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The contact page's card switches move into the card list (Lane CT2).
 *
 * Store → Inquiries → Contact page stored four booleans at the top of the
 * `contact_page` row: wa, ig, email, phone. The cards are now an ordered list
 * in the same row, `cards`, edited from Pages → User pages → Contact Us →
 * Edit → Contact cards as well, and each switch is that list's `on` flag — one
 * stored value for both screens. This copies each switch into its card and
 * drops the old key, so nothing reads two answers.
 *
 * THE INSTAGRAM CARD IS SWITCHED ON. The owner, 9 October: "i have added the
 * instagram link, but not showing the third block. please add manually". It is
 * the one value this moves rather than copies, and the only one he asked for.
 *
 * No row: nothing to move — ContactPage::cardsFrom() reads the shipped
 * defaults (Instagram on, phone off). A row that already has `cards`: left
 * alone, so running this twice is the same as running it once. A row from
 * before the list, read before this has run (a package's files land before its
 * database step), still reads its old switches: cardsFrom() understands both.
 */
return new class extends Migration
{
    private const KEY = 'contact_page';

    private const CARDS = ['wa', 'ig', 'email', 'phone'];

    public function up(): void
    {
        $row = $this->row();
        if ($row === null || isset($row['cards'])) {
            return;
        }

        $cards = [];
        foreach (self::CARDS as $k) {
            $on = $k === 'ig' ? true : (array_key_exists($k, $row) ? (bool) $row[$k] : $k !== 'phone');
            $cards[] = ['k' => $k, 'on' => $on, 'title' => '', 'note' => '', 'value' => '', 'action' => '', 'link' => ''];
            unset($row[$k]);
        }
        $row['cards'] = $cards;

        app(SettingsService::class)->set(self::KEY, $row);
    }

    /**
     * Puts the four switches back at the top of the row, for the build that
     * reads them there, and keeps the list (that build ignores the key).
     */
    public function down(): void
    {
        $row = $this->row();
        if ($row === null || ! isset($row['cards']) || ! is_array($row['cards'])) {
            return;
        }

        foreach ($row['cards'] as $card) {
            if (is_array($card) && in_array($card['k'] ?? null, self::CARDS, true)) {
                $row[$card['k']] = (bool) ($card['on'] ?? false);
            }
        }

        app(SettingsService::class)->set(self::KEY, $row);
    }

    /** The row as an array, or null when there is none (or no table). */
    private function row(): ?array
    {
        if (! Schema::hasTable('settings')) {
            return null;
        }

        $value = DB::table('settings')->where('key', self::KEY)->value('value');
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
};
