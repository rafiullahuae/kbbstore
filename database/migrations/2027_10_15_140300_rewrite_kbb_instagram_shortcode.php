<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane IGR: every stored [kbb_instagram …] becomes [kbb_instagram_embeds].
 *
 * The API-fed grid's shortcode was retired with the module (it now renders
 * nothing), so a page, a post or an HTML block that carried it would have lost
 * its Instagram section. Rewritten instead to Instagram embeds, the section the
 * owner now fills by pasting addresses.
 *
 * ONE ATTRIBUTE CARRIES OVER: `title`, which means the same thing in both. The
 * old `layout` (five API layouts), `limit` and `profile` have no equal in the
 * new shortcode and are dropped, so the section takes the look chosen at
 * Content → Instagram embeds — the owner's own choice, rather than a guess.
 *
 * WHERE: pages.content, posts.body, blocks.content, and the Arabic copies in
 * translations.value. A page whose English changes would otherwise mark its
 * Arabic STALE (the translation's source_hash is sha1 of the English), so a
 * translation whose hash matched the old English is moved to the new one —
 * the same repair App\Services\Seo\BrandRename makes for a brand rename.
 *
 * `\b` after "instagram" does not match before "_embeds", so a tag that is
 * already the new one is never touched, and running this twice changes nothing.
 */
return new class extends Migration
{
    public const OLD = '/\[kbb_instagram\b([^\]]*)\]/';

    public function up(): void
    {
        $changed = 0;

        foreach ([['pages', 'content', true], ['posts', 'body', true], ['blocks', 'content', false], ['translations', 'value', false]] as [$table, $column, $english]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $rows = DB::table($table)->where($column, 'like', '%[kbb_instagram%')->get(['id', $column]);

            foreach ($rows as $row) {
                $old = (string) $row->{$column};
                $new = self::rewrite($old);

                if ($new === $old) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $new]);
                $changed++;

                if ($english && Schema::hasTable('translations')) {
                    DB::table('translations')->where('source_hash', sha1($old))->update(['source_hash' => sha1($new)]);
                }
            }
        }

        try {
            \App\Support\Shortcodes::flush();
        } catch (\Throwable) {
        }

        if (app()->runningInConsole()) {
            echo "Rewrote [kbb_instagram] to [kbb_instagram_embeds] in {$changed} row(s).\n";
        }
    }

    public static function rewrite(string $text): string
    {
        return (string) preg_replace_callback(self::OLD, function (array $m): string {
            preg_match('/\btitle\s*=\s*("[^"]*"|\'[^\']*\')/i', $m[1], $t);

            return '[kbb_instagram_embeds'.(isset($t[1]) ? ' title='.$t[1] : '').']';
        }, $text);
    }

    public function down(): void {}
};
