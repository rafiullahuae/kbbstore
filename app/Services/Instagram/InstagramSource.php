<?php

declare(strict_types=1);

namespace App\Services\Instagram;

/**
 * Where the account's profile, posts and insights are READ from — one interface,
 * two hosts (Lane IG2).
 *
 *   InstagramClient       Instagram API with Instagram Login — graph.instagram.com,
 *                         `/me` is the Instagram account itself.
 *   FacebookGraphClient   Instagram API with Facebook Login — graph.facebook.com,
 *                         the account is `/{ig-user-id}` read with a Page token.
 *
 * Only the token's source and the host differ. Everything downstream — the rows,
 * the pictures on our disk, the cache, the shop section — is InstagramSync's and
 * InstagramFeed's, written once, and does not know which of the two it was fed by.
 * That is the point of this seam: the feed is NOT forked per route.
 *
 * Every method returns the same three-valued shape InstagramClient always has —
 * ['ok' => true, 'data' => …] or ['ok' => false, 'reason' => …, 'error' => …,
 * 'detail' => …] — and never throws. `reason` is 'expired' when Meta has stopped
 * accepting the stored token, which is what InstagramSync turns into the
 * Reconnect warning.
 *
 * profile() answers with the SAME keys whichever host it asked: id, username,
 * name, account_type, profile_picture_url, followers_count, media_count.
 */
interface InstagramSource
{
    /** @return array{ok: bool, data?: array<string, mixed>, error?: string, reason?: string, detail?: string} */
    public function profile(string $token): array;

    /** @return array{ok: bool, data?: array<string, mixed>, error?: string, reason?: string, detail?: string} */
    public function media(string $token, int $limit, ?string $after = null): array;

    /** @return array{ok: bool, shares?: ?int, views?: ?int, error?: string, reason?: string} */
    public function insights(string $token, string $mediaId, bool $video): array;
}
