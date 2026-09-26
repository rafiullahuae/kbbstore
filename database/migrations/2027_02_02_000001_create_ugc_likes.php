<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shoppable UGC video — OUR OWN LIKES. Phase 20, Lane V3.
 *
 * The owner asked for two things here: "The video must display the real likes,
 * comments etc from the original source, and if uploaded, then count itself and
 * users can like etc."
 *
 * ── THE FIRST HALF WAS CUT, AND NOTHING OF IT IS LEFT BEHIND ───────────────
 *
 * Mid-round: "okay, leave the counts for now, just get the videos from there."
 * So there is NO column in this migration for a third party's like or comment
 * count, no provider, no credential and no endpoint that asks anybody for one.
 * An earlier draft of this file had six metrics_* columns and they were removed
 * rather than shipped nullable-and-unwritten: a column nothing writes is a column
 * the next reader assumes something writes. docs/UGC-ENGAGEMENT.md records what
 * each platform would have cost, so the decision does not have to be researched
 * again.
 *
 * ── WHAT IS HERE IS THE SECOND HALF: `likes`, A REAL COUNT OF REAL CLICKS ───
 *
 * `ugc_videos.likes` is maintained by increment() from Api\UgcController::like()
 * and by nothing else. `ugc_video_likes` is the one-per-browser ledger behind it.
 *
 * WHAT THE LEDGER STORES, AND WHAT IT DELIBERATELY DOES NOT. One column:
 * `token_hash`, a SHA-256 of a random 32-hex token this shop minted itself and
 * put in a cookie. No IP, no user agent, no customer id, no timestamp beyond
 * created_at. The reviews module's helpful-vote keeps the whole voted list in the
 * cookie instead, which works and grows without bound; a hashed token is the same
 * protection with a fixed-size cookie, and it is the shape that carries no
 * personal data at all. Rate limiting is per-IP in the RateLimiter and therefore
 * never written down.
 *
 * IT SHIPS SWITCHED OFF. Appearance → Video rail → Likes → "Let
 * shoppers like an uploaded clip" defaults to FALSE, R3 draws no like button, and
 * Api\UgcController::like() 404s while it is off. So this column exists and stays
 * at 0 on every install until somebody turns it on.
 *
 * ── NO ->after(), hasColumn() GUARDS ────────────────────────────────────────
 *
 * SQLite does not implement ->after() and the whole set runs on the test
 * database. Each column is guarded on its own rather than the table, so a
 * half-applied package (this is a zip applied through Store → Core Updates, not
 * a git deploy) can be re-applied.
 */
return new class extends Migration
{
    /** column => the closure that adds it. Guarded one at a time. */
    private function columns(): array
    {
        return [
            // NOT NULL with a zero default: zero clicks IS zero, and a count we
            // maintain ourselves is never unknown. That is precisely the property
            // a third party's count would NOT have had, which is why one would
            // have needed a nullable column and this does not.
            'likes' => fn (Blueprint $t) => $t->unsignedInteger('likes')->default(0),
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('ugc_videos')) {
            foreach ($this->columns() as $name => $add) {
                if (! Schema::hasColumn('ugc_videos', $name)) {
                    Schema::table('ugc_videos', function (Blueprint $t) use ($add) {
                        $add($t);
                    });
                }
            }
        }

        if (! Schema::hasTable('ugc_video_likes')) {
            Schema::create('ugc_video_likes', function (Blueprint $t) {
                $t->id();

                $t->foreignId('ugc_video_id')->constrained('ugc_videos')->cascadeOnDelete();

                /*
                 * SHA-256 of a token this shop minted and handed the browser in
                 * a cookie. 64 hex characters, fixed width.
                 *
                 * HASHED RATHER THAN STORED PLAIN for the reason any bearer
                 * token is hashed: the cookie value is what lets a browser spend
                 * its one like, and a readable dump of this table would be a
                 * list of usable tokens. It is not a secret worth much — the
                 * worst a stolen one buys is a like already spent — but the
                 * habit is the point, and hashing costs nothing here because
                 * nothing ever needs the original back.
                 */
                $t->char('token_hash', 64);

                $t->timestamps();

                // The whole of the one-per-browser rule, enforced by the
                // database rather than by a read-then-write that two clicks in
                // the same second would both pass.
                $t->unique(['ugc_video_id', 'token_hash'], 'ugc_video_likes_once');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_video_likes');

        if (Schema::hasTable('ugc_videos')) {
            foreach (array_keys($this->columns()) as $name) {
                if (Schema::hasColumn('ugc_videos', $name)) {
                    Schema::table('ugc_videos', fn (Blueprint $t) => $t->dropColumn($name));
                }
            }
        }
    }
};
