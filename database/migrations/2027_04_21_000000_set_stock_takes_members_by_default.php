<?php

declare(strict_types=1);

use App\Services\StockSetRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SELLING A SET NOW TAKES ITS MEMBERS OFF THE SHELF — AND THIS IS A DEFAULT THE
 * OWNER ASKED FOR IN AS MANY WORDS.
 *
 * His decision, verbatim: *"Yes if the product sold inside set or individual,
 * the stock should be minus in any case."*
 *
 * CLAUDE.md rule 1 says a NEW setting ships at the value the page already has,
 * so applying a package moves nothing until somebody moves a slider. The
 * exception it names is a default the owner asked for by name, called out in
 * the commit rather than buried. This is that, and it is the loudest kind: it
 * changes what the shop counts as stock on every order containing a set.
 *
 * ── WHY A MIGRATION AND NOT ONLY A CHANGE OF DEFAULT IN THE CODE ───────────
 *
 * Both are done, and they answer different questions.
 *
 * StockSetRule::mode() defaults to MODE_MEMBERS now, which is what a shop
 * installed AFTER this package gets. But a shop that already exists may have
 * had an operator visit Catalog -> Sets -> Stock and deliberately choose "take
 * it off the set's own stock only" -- and `settings.set_stock_mode` would then
 * hold the string 'set'. A code default cannot tell that apart from "nobody has
 * ever opened that screen", because both read back as the default.
 *
 * So the row is written ONLY WHEN THE KEY IS ABSENT, checked against the table
 * rather than through the service, because SettingsService::get() hands back
 * the default for a missing key and would make the two states identical again.
 * After this runs the key EXISTS and says members; if the owner switches it
 * back the screen writes 'set' over it and nothing ever puts it back.
 *
 * ── WHAT IT MEANS ON THE SHOP ──────────────────────────────────────────────
 *
 * StockClaim expands a set line into that line PLUS one per member (member
 * quantity x sets sold) BEFORE perShelf(), so a basket holding both the set and
 * one of its members takes that shelf down once for the total rather than
 * twice. A member that is out of stock therefore makes the set unsellable,
 * which is the point: the box cannot be packed.
 *
 * ▲ THE ONE THING TO WATCH after this applies is a set whose members were
 * already low. Nothing is retro-counted -- past orders are not re-applied --
 * but the NEXT order for such a set will be refused where it would previously
 * have gone through. That is the correct behaviour and it is also the surprise,
 * so it is in the changelog in those words.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')->where('key', StockSetRule::KEY)->exists();

        if ($exists) {
            return;
        }

        DB::table('settings')->insert([
            'key' => StockSetRule::KEY,
            'value' => StockSetRule::MODE_MEMBERS,
            'autoload' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Deliberately empty. down() cannot know whether the row it would delete is
     * the one this wrote or one the owner has since chosen, and removing a
     * setting an operator set by hand is a worse outcome than leaving a row
     * that says what the shop is already doing.
     */
    public function down(): void {}
};
