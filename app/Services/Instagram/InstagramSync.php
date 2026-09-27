<?php

declare(strict_types=1);

namespace App\Services\Instagram;

use App\Models\InstagramPost;
use App\Services\InstagramFeed;
use App\Services\InstagramSettings;

/**
 * One fetch: the token refreshed if it needs it, the profile, the media, the
 * thumbnails brought onto our own disk, and the rows written.
 *
 * Phase 21, Lane IG. Called from ADMIN ENDPOINTS ONLY — never from a page render.
 * docs/IG-PROFILE.md §2 and §10 are the argument.
 *
 * ── THIS CLASS DOES NOT THROW EITHER ────────────────────────────────────────
 *
 * Every method returns the client's three-valued shape. An admin presses a button
 * and gets a sentence; the worst outcome of a completely broken Instagram is a
 * section that shows what it showed yesterday.
 *
 * ── IT IS AN UPSERT, AND PARTIAL SUCCESS IS THE NORMAL CASE ─────────────────
 *
 * Twelve posts fetched, ten thumbnails downloaded, two refused by a CDN having a
 * bad minute: the right outcome is ten drawable posts and a sentence saying two
 * pictures could not be fetched, NOT a refusal that leaves the shop with the nine
 * it had. So each post is handled on its own and the result counts what happened.
 *
 * A post whose thumbnail fails keeps its row — with `local_path` left as it was, so
 * a re-download that fails does not blank a tile that is working — and simply is
 * not drawable until a later refresh gets the picture.
 */
class InstagramSync
{
    public function __construct(
        private InstagramClient $client,
        private InstagramSettings $settings,
    ) {}

    /**
     * How many posts a fetch asks Instagram for.
     *
     * More than the section shows, on purpose: the appearance setting caps what is
     * DRAWN at 24, and a fetch that asked for exactly that many would leave the grid
     * short the moment a couple of thumbnails failed. Twenty-five is one Graph page
     * and costs the same as one.
     */
    public const FETCH = 25;

    /**
     * Refresh the stored token if it is inside the window, and say what happened.
     *
     * ── OPPORTUNISTIC, BECAUSE THERE IS NOWHERE ELSE TO PUT IT ──────────────
     *
     * Meta's long-lived token lasts sixty days and can only be refreshed WHILE IT IS
     * STILL VALID. docs/UGC-ENGAGEMENT.md established that this host has no cron and
     * no queue worker, so a nightly refresh would be a job that never ran — which is
     * worse than none, because it would look like one. So every admin call that
     * touches Instagram does this first, and the screen prints the expiry date so the
     * owner can see the one thing that requires him to show up.
     *
     * A FAILED REFRESH IS NOT AN ERROR HERE. It returns `refreshed => false` and the
     * caller carries on with the token it has: a network blip while the token still
     * has fifty days on it is nothing at all, and turning it into a red banner on the
     * screen teaches the owner to ignore red banners.
     *
     * @return array{refreshed: bool, reason?: string}
     */
    public function refreshTokenIfDue(): array
    {
        if (! InstagramCredentials::hasToken() || ! InstagramCredentials::needsRefresh()) {
            return ['refreshed' => false];
        }

        // Already lapsed. The refresh endpoint will refuse it and only a full
        // reconnect will do, so the call is not made at all — an outbound request we
        // know the answer to is an outbound request not worth the owner's wait.
        if (InstagramCredentials::expired()) {
            return ['refreshed' => false, 'reason' => 'expired'];
        }

        $token = InstagramCredentials::token();

        if ($token === null) {
            return ['refreshed' => false, 'reason' => 'no_token'];
        }

        $answer = $this->client->refresh($token);

        if (! ($answer['ok'] ?? false)) {
            return ['refreshed' => false, 'reason' => (string) ($answer['reason'] ?? 'refused')];
        }

        $data = $answer['data'] ?? [];

        InstagramCredentials::saveToken(
            (string) ($data['access_token'] ?? ''),
            (int) ($data['expires_in'] ?? 60 * 86400),
        );

        return ['refreshed' => true];
    }

    /**
     * The whole of "Configure now", from the code Meta handed back to a grid.
     *
     * ── FOUR STEPS, AND THE ORDER IS THE ONLY SAFE ONE ──────────────────────
     *
     * The code becomes a ONE-HOUR token; that becomes a SIXTY-DAY one; only then is
     * anything stored; and only then is a fetch attempted. Storing the short token
     * on the way past would leave a shop that reports itself connected and stops
     * working an hour later with no explanation — which is the failure this ordering
     * exists to make impossible. Nothing is written until there is a long-lived
     * token in hand.
     *
     * ── AND A FAILED FIRST FETCH IS NOT A FAILED CONNECTION ─────────────────
     *
     * If the token exchange succeeded, THE SHOP IS CONNECTED, and that fact is kept
     * even when the media call then fails — a rate limit, a timeout, a scope Meta
     * has not approved yet. Throwing the token away because the first fetch was
     * unlucky would send the owner back through the whole authorisation for a
     * problem the Refresh button fixes. So the token is stored, and the message says
     * honestly which half worked.
     *
     * `$code` is Meta's authorisation code, single-use and already matched against a
     * single-use `state` by InstagramAuth::consume() before this is reached. It is
     * never logged: it goes into a form body and nowhere else.
     *
     * @return array{ok: bool, message?: string, error?: string, detail?: string, reason?: string}
     */
    public function connect(string $code): array
    {
        $short = $this->client->exchangeCode($code, InstagramAuth::redirectUri());

        if (! ($short['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($short['reason'] ?? 'refused'),
                'error' => (string) ($short['error'] ?? ''),
                /*
                 * This is where a redirect-URI mismatch surfaces, and Meta names it
                 * in as many words. docs/IG-PROFILE.md §7 item 4 calls it the
                 * commonest way this flow fails, so its own sentence is passed
                 * through — scrubbed of the secret and the token by
                 * InstagramClient::scrub() before it ever left that class.
                 */
                'detail' => (string) ($short['detail'] ?? ''),
            ];
        }

        $long = $this->client->exchangeForLongLived((string) ($short['data']['access_token'] ?? ''));

        if (! ($long['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($long['reason'] ?? 'refused'),
                'error' => (string) ($long['error'] ?? ''),
                'detail' => (string) ($long['detail'] ?? ''),
            ];
        }

        $data = $long['data'] ?? [];

        InstagramCredentials::saveToken(
            (string) ($data['access_token'] ?? ''),
            (int) ($data['expires_in'] ?? 60 * 86400),
            // Meta returns the IG user id on the CODE exchange, not on the token
            // exchange, so it is carried across from the first answer.
            isset($short['data']['user_id']) ? (string) $short['data']['user_id'] : null,
        );

        // Nothing was stored if the token came back empty, and saveToken() refuses
        // an empty one silently — so this is checked rather than assumed.
        if (! InstagramCredentials::hasToken()) {
            return [
                'ok' => false,
                'reason' => 'malformed',
                'error' => InstagramClient::REASONS['malformed'] ?? 'Instagram sent an answer this shop could not read.',
                'detail' => 'The long-lived token came back empty.',
            ];
        }

        $fetch = $this->run();

        if (! ($fetch['ok'] ?? false)) {
            return [
                'ok' => true,
                'message' => 'Connected to Instagram — the account is authorised and the connection is stored. '
                    .'The first fetch did not finish, though: '
                    .lcfirst((string) ($fetch['error'] ?? 'Instagram could not be reached.'))
                    .' Press Refresh posts to try again; you do not have to reconnect.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Connected to Instagram. '.(int) ($fetch['stored'] ?? 0).' posts fetched, '
                .(int) ($fetch['pictures'] ?? 0).' pictures stored on this shop. Pick a layout below, then '
                .'turn the module on in Store → Modules → Instagram Profile.',
        ];
    }

    /**
     * Delete every stored post and every picture we downloaded for one.
     *
     * ── SEPARATE FROM DISCONNECTING, ON PURPOSE ─────────────────────────────
     *
     * Disconnecting revokes this shop's ACCESS. This throws away CONTENT. Doing both
     * from one button is how an owner who wanted to change Meta apps finds his
     * homepage section empty, so they are two buttons with two sentences and this one
     * says how many rows it is about to remove before it removes them.
     *
     * The file is unlinked BEFORE the row, and a file that will not unlink does not
     * stop the row going: a leftover file is 40 KB nobody sees, where a leftover row
     * is a tile pointing at a picture that is not there.
     */
    public function forgetEverything(): int
    {
        $removed = 0;

        foreach (InstagramPost::query()->get(['id', 'local_path']) as $post) {
            $file = IgPath::absolute($post->local_path);

            if ($file !== null && is_file($file)) {
                @unlink($file);
            }

            $post->delete();
            $removed++;
        }

        InstagramFeed::flush();

        return $removed;
    }

    /**
     * Fetch the profile and the recent media, and write everything down.
     *
     * @return array{ok: bool, error?: string, reason?: string, detail?: string, stored?: int, pictures?: int, failed?: int, pruned?: int}
     */
    public function run(): array
    {
        if (! InstagramCredentials::hasSecret() || ! InstagramCredentials::hasAppId()) {
            return ['ok' => false, 'reason' => 'no_app', 'error' => InstagramClient::REASONS['no_app']];
        }

        $this->refreshTokenIfDue();

        $token = InstagramCredentials::token();

        if ($token === null) {
            return ['ok' => false, 'reason' => 'no_token', 'error' => InstagramClient::REASONS['no_token']];
        }

        /* ── the profile ─────────────────────────────────────────────────── */

        $profile = $this->client->profile($token);

        if (! ($profile['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($profile['reason'] ?? 'refused'),
                'error' => (string) ($profile['error'] ?? ''),
                'detail' => (string) ($profile['detail'] ?? ''),
            ];
        }

        $p = $profile['data'] ?? [];

        /*
         * ── THE ONE CHECK THAT SAVES A SUPPORT CALL ─────────────────────────
         *
         * A personal account returns nothing useful from any of these endpoints at
         * any price (docs/IG-PROFILE.md §1), and the symptom is an empty grid with
         * a successful fetch behind it — which reads exactly like a bug in this
         * shop. So the account type is checked and named: `account_type` comes back
         * as BUSINESS, MEDIA_CREATOR or PERSONAL, and the third one has a remedy
         * that takes two minutes in the phone app.
         */
        $type = strtoupper((string) ($p['account_type'] ?? ''));

        if ($type === 'PERSONAL') {
            return [
                'ok' => false,
                'reason' => 'not_professional',
                'error' => InstagramClient::REASONS['not_professional'],
                'detail' => 'In the Instagram app: Settings → Account type and tools → Switch to professional '
                    .'account → Business or Creator. Then press Refresh here; you do not have to reconnect.',
            ];
        }

        $avatar = $this->storeImage(
            'profile-'.(string) ($p['id'] ?? 'me'),
            (string) ($p['profile_picture_url'] ?? ''),
            // The avatar is replaced in place on every refresh rather than being
            // kept alongside the old one. One file, one account, and an avatar
            // nobody can reach is a file nobody deletes.
            $this->currentAvatar(),
        );

        $this->settings->saveProfile([
            'username' => (string) ($p['username'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            // The LOCAL path, never Meta's CDN URL — that one expires.
            'avatar' => $avatar,
            'followers' => isset($p['followers_count']) ? (int) $p['followers_count'] : null,
            'posts' => isset($p['media_count']) ? (int) $p['media_count'] : null,
            'account_type' => $type,
            'fetched_at' => time(),
        ]);

        /* ── the media ───────────────────────────────────────────────────── */

        $media = $this->client->media($token, self::FETCH);

        if (! ($media['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($media['reason'] ?? 'refused'),
                'error' => (string) ($media['error'] ?? ''),
                'detail' => (string) ($media['detail'] ?? ''),
            ];
        }

        $rows = is_array($media['data']['data'] ?? null) ? $media['data']['data'] : [];

        $stored = 0;
        $pictures = 0;
        $failed = 0;
        $seen = [];
        $now = now();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $remoteId = trim((string) ($row['id'] ?? ''));

            // No id, no upsert key, no row. A media object without one is not
            // something to guess at.
            if ($remoteId === '' || strlen($remoteId) > 64) {
                $failed++;

                continue;
            }

            $seen[] = $remoteId;

            $post = InstagramPost::query()->firstOrNew(['remote_id' => $remoteId]);

            $type = strtoupper((string) ($row['media_type'] ?? 'IMAGE'));

            $post->media_type = in_array($type, InstagramPost::TYPES, true) ? $type : 'IMAGE';
            $post->permalink = $this->permalink($row['permalink'] ?? null);
            $post->shortcode = $this->shortcodeFrom($post->permalink);
            $post->caption = $this->caption($row['caption'] ?? null);

            /*
             * ── A NUMBER WE WERE NOT GIVEN IS LEFT EXACTLY AS IT WAS ────────
             *
             * `array_key_exists` and not `??`, and the difference is the whole of
             * docs/UGC-ENGAGEMENT.md's finding applied to a write. Instagram omits
             * `like_count` entirely when the owner has hidden likes on the post —
             * and a `?? null` would then overwrite yesterday's real figure with
             * "we do not know", on every refresh, for a reason that has nothing to
             * do with the number having changed.
             *
             * That document put it as "the deleted design wrote numbers only when
             * the answer carried some, so an old post could not null out
             * yesterday's real figures". Same rule, one layer along.
             */
            if (array_key_exists('like_count', $row)) {
                $post->like_count = is_numeric($row['like_count']) ? (int) $row['like_count'] : null;
            }

            if (array_key_exists('comments_count', $row)) {
                $post->comments_count = is_numeric($row['comments_count']) ? (int) $row['comments_count'] : null;
            }

            $post->posted_at = $this->timestamp($row['timestamp'] ?? null);
            $post->seen_at = $now;

            /*
             * ── WHICH URL THE THUMBNAIL COMES FROM, AND WHY IT IS NOT media_url ─
             *
             * For a VIDEO, `media_url` is the MP4 and `thumbnail_url` is the poster
             * frame — so asking for media_url on a reel downloads a video file into
             * an image directory, which IgPath would then refuse to serve and
             * nobody would understand why.
             *
             * For a CAROUSEL_ALBUM the parent carries NEITHER, which is a Graph
             * quirk rather than an error: the pictures are on `children`. So the
             * first child's own thumbnail is used, which is the frame Instagram's
             * own grid shows for an album.
             */
            $source = $this->thumbnailUrl($row);

            $before = (string) ($post->local_path ?? '');
            $path = $this->storeImage($remoteId, $source, $before === '' ? null : $before);

            if ($path !== null) {
                $post->local_path = $path;
                $pictures++;
            } elseif ($before === '') {
                // No picture now and none before, so this post is not drawable. The
                // row is still written: the next refresh may get the picture, and
                // the counts are worth keeping in the meantime.
                $failed++;
            }

            $post->save();
            $stored++;
        }

        $pruned = $this->prune($seen);

        InstagramFeed::flush();

        return [
            'ok' => true,
            'stored' => $stored,
            'pictures' => $pictures,
            'failed' => $failed,
            'pruned' => $pruned,
        ];
    }

    /* ---------------------------------------------------------------- the pieces */

    /**
     * Download one image and put it on our own disk, or null.
     *
     * ── THE BYTES DECIDE WHAT THE FILE IS, NOT THE URL ──────────────────────
     *
     * `getimagesizefromstring()` on the downloaded body is what picks the extension.
     * A remote server's URL path, its Content-Type header and its filename are all
     * things it controls and all things it is wrong about whenever it wants to be;
     * the image header is the one reading that cannot be talked out of. UgcMedia's
     * five-step upload check makes the same argument for an operator's own upload,
     * where the file is at least coming from somebody we trust.
     *
     * Only the four raster types IgPath will serve. Anything else — an SVG, an
     * HTML error page a CDN returned with a 200, a zero-byte body — is refused and
     * nothing is written.
     *
     * `$replacing` is the path this post had before, deleted only AFTER the new file
     * is safely on disk. Deleting first would mean a failed download took out a tile
     * that was working.
     */
    private function storeImage(string $key, string $url, ?string $replacing = null): ?string
    {
        $safe = \App\Services\UgcPath::link($url);

        if ($safe === null) {
            return null;
        }

        $answer = $this->client->fetchImage($safe);

        if (! ($answer['ok'] ?? false)) {
            return null;
        }

        $body = (string) ($answer['body'] ?? '');

        $info = @getimagesizefromstring($body);

        if ($info === false) {
            return null;
        }

        $extension = match ((int) ($info[2] ?? 0)) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };

        if ($extension === null) {
            return null;
        }

        $dir = IgPath::directory();

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $name = IgPath::fileName($key, $extension);
        $absolute = $dir.'/'.$name;

        if (@file_put_contents($absolute, $body) === false) {
            return null;
        }

        $stored = '/'.IgPath::ROOT.$name;

        /*
         * A LAST CHECK ON OUR OWN OUTPUT, and it is not paranoia about the filename:
         * IgPath::stored() is what the storefront will apply on the way out, so
         * anything it would refuse must never be written to the column in the first
         * place. Otherwise the failure shows up later as a tile that silently is not
         * drawn, with a row in the database that looks fine.
         */
        if (IgPath::stored($stored) === null) {
            @unlink($absolute);

            return null;
        }

        if ($replacing !== null && $replacing !== $stored) {
            $old = IgPath::absolute($replacing);

            if ($old !== null && is_file($old)) {
                @unlink($old);
            }
        }

        return $stored;
    }

    /**
     * Where a post's still picture comes from. See the long note at the call site.
     *
     * @param  array<string, mixed>  $row
     */
    private function thumbnailUrl(array $row): string
    {
        $type = strtoupper((string) ($row['media_type'] ?? 'IMAGE'));

        if ($type === 'VIDEO') {
            // The poster frame. `media_url` on a VIDEO is the MP4.
            return (string) ($row['thumbnail_url'] ?? '');
        }

        if ($type === 'CAROUSEL_ALBUM') {
            $first = is_array($row['children']['data'][0] ?? null) ? $row['children']['data'][0] : [];

            $childType = strtoupper((string) ($first['media_type'] ?? 'IMAGE'));

            return (string) ($childType === 'VIDEO'
                ? ($first['thumbnail_url'] ?? '')
                : ($first['media_url'] ?? ''));
        }

        return (string) ($row['media_url'] ?? '');
    }

    /** A permalink we are willing to turn into an href, or null. */
    private function permalink(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $url = \App\Services\UgcPath::link($raw);

        /*
         * AND IT HAS TO BE ON INSTAGRAM. UgcPath::link() answers "is this a safe
         * http(s) URL", which is the right question for an operator-typed source
         * link and not enough here: this value arrived from a remote server and is
         * printed as "view on Instagram", so a shopper who clicks it is entitled to
         * arrive at Instagram. Host-checked rather than prefix-matched, because
         * `https://www.instagram.com.evil.test/` passes a str_starts_with and is not
         * Instagram.
         */
        if ($url === null || strlen($url) > 255) {
            return null;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $host === 'instagram.com' || $host === 'www.instagram.com' ? $url : null;
    }

    /**
     * The shortcode out of a permalink — the `DEF456` in instagram.com/p/DEF456/.
     *
     * Matched rather than split, and against InstagramPost::SHORTCODE_RE's own
     * alphabet, because this value's whole job is to be interpolated into an iframe
     * `src`. `/p/`, `/reel/` and `/tv/` are the three forms Instagram uses.
     */
    private function shortcodeFrom(?string $permalink): ?string
    {
        if ($permalink === null) {
            return null;
        }

        if (preg_match('#/(?:p|reel|reels|tv)/([A-Za-z0-9_-]{1,64})#', $permalink, $m) !== 1) {
            return null;
        }

        return preg_match(InstagramPost::SHORTCODE_RE, $m[1]) === 1 ? $m[1] : null;
    }

    /**
     * The caption, capped.
     *
     * Capped because an Instagram caption may be 2,200 characters and a `text`
     * column will happily take all of it into every cached feed payload on the shop.
     * The tile shows a line; the cap is generous enough that nothing a tile draws is
     * ever truncated by it.
     *
     * NOT sanitised beyond that, deliberately: it is printed through `{{ }}`, which
     * is the correct and complete answer for text, and a stripped-down copy stored
     * alongside would be a second version of the truth to keep in step.
     */
    private function caption(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return mb_substr($raw, 0, 600);
    }

    /** Instagram's ISO-8601 timestamp, or null. */
    private function timestamp(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->toDateTimeString();
        } catch (\Throwable) {
            // A date we cannot read is not a reason to lose the post. It sorts last
            // — see InstagramPost::scopeRecent — which is where a post with no
            // known date belongs.
            return null;
        }
    }

    /** The avatar path currently stored, or null. */
    private function currentAvatar(): ?string
    {
        $stored = $this->settings->profile()['avatar'] ?? null;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * Delete the posts Instagram no longer returns, and their files.
     *
     * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────
     *
     * A post deleted on Instagram would otherwise stay on this shop's homepage
     * forever, with a picture on our disk nothing points at. That is the worse half:
     * an owner who takes a post down has usually taken it down for a reason, and a
     * shop that keeps showing it is the shop doing something he explicitly undid.
     *
     * ── AND WHY IT IS CAREFUL ABOUT WHAT "NO LONGER RETURNS" MEANS ──────────
     *
     * A fetch that came back EMPTY is not evidence that every post is gone — it is
     * far more likely a permission that lapsed or a page of results that failed. So
     * an empty `$seen` prunes NOTHING. Getting this wrong deletes the whole section
     * on a bad afternoon and there is no undo, because the pictures go with it.
     *
     * And only the FETCH window is pruned. We asked for the 25 most recent, so a post
     * older than that is absent from the answer for the most ordinary reason there
     * is; deleting it would mean this shop could never hold more than one page of
     * history. Rows are therefore pruned only when they are newer than the oldest
     * post the fetch actually returned.
     *
     * @param  list<string>  $seen
     */
    private function prune(array $seen): int
    {
        if ($seen === []) {
            return 0;
        }

        $oldestKept = InstagramPost::query()
            ->whereIn('remote_id', $seen)
            ->min('posted_at');

        if ($oldestKept === null) {
            return 0;
        }

        $doomed = InstagramPost::query()
            ->whereNotIn('remote_id', $seen)
            ->where('posted_at', '>=', $oldestKept)
            ->get(['id', 'local_path']);

        foreach ($doomed as $post) {
            $file = IgPath::absolute($post->local_path);

            if ($file !== null && is_file($file)) {
                @unlink($file);
            }

            $post->delete();
        }

        return $doomed->count();
    }
}
