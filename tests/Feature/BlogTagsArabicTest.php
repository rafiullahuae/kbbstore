<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Post;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\BlogTags;
use App\Support\Locale;

/**
 * The Journal's post tags on the Arabic blog.
 *
 * 18 October 2026, the owner on kbeautybliss.com/ar/blog/: "why the few
 * things are not translated on the blog page". Every cover photo carried its
 * English tag ("News", "Blog", "Acne Treatments") and the filter chips above
 * the grid were the same English words: tags are free text from WordPress,
 * and ArabicWording::of() only knows the shop's own shipped sentences.
 *
 * Now: the tag's LABEL is Arabic on /ar (the owner's own from Translation ->
 * Language settings -> Blog tags in Arabic, else the shipped Arabic), while
 * the tag stays the filter VALUE (data-tag), so the chips still filter.
 *
 * MUTATION: print $p->tag in .ptag again, or drop data-tag-label, or give the
 * chip `chip(t, t)` back -- each is red below.
 */
function bltSet(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function bltPosts(): void
{
    foreach (['News', 'Acne Treatments', 'Glass Skin Club'] as $i => $tag) {
        Post::create([
            'slug' => 'bt-post-'.$i.'-'.uniqid(), 'title' => 'Post '.$i, 'tag' => $tag,
            'excerpt' => 'Line.', 'body' => 'Body.', 'status' => 'published', 'published_at' => now()->subDays($i + 1),
        ]);
    }
}

function bltTags(string $html): array
{
    preg_match_all('~<span class="ptag">([^<]*)</span>~u', $html, $m);

    return array_map('html_entity_decode', $m[1]);
}

beforeEach(function () {
    bltSet([Locale::SETTING_ENABLED => '1', Locale::SETTING_RTL => '1']);
});

it('prints each post tag in Arabic on /ar/blog/, keeps the English tag as the filter value, and labels the chips from it', function () {
    bltPosts();

    $ar = $this->get('/ar/blog/')->assertOk()->getContent();

    expect(bltTags($ar))->toContain('الأخبار')->toContain('علاج حب الشباب')
        ->and(bltTags($ar))->not->toContain('News')->not->toContain('Acne Treatments')
        // A tag nobody has translated stays itself rather than vanishing.
        ->and(bltTags($ar))->toContain('Glass Skin Club')
        ->and($ar)->toContain('data-tag="News" data-tag-label="الأخبار"')
        ->and($ar)->toContain('data-tag="Acne Treatments" data-tag-label="علاج حب الشباب"')
        ->and($ar)->not->toContain('data-tag="Glass Skin Club" data-tag-label')
        // The chips take their label from the card, their value from data-tag.
        ->and($ar)->toContain('labels[tag] = a.dataset.tagLabel || tag;')
        ->and($ar)->toContain('chips.appendChild(chip(labels[t], t));');
});

it('leaves the English blog as it was: English tags, no label attribute', function () {
    bltPosts();

    $en = $this->get('/blog/')->assertOk()->getContent();

    expect(bltTags($en))->toContain('News')->toContain('Acne Treatments')
        ->and($en)->not->toContain('data-tag-label="');
});

it('lets the owner set the Arabic for any tag, which wins over the shipped Arabic', function () {
    bltPosts();
    bltSet([BlogTags::SETTING => json_encode(['glass skin club' => 'نادي البشرة الزجاجية', 'News' => 'أخبار كي بيوتي'], JSON_UNESCAPED_UNICODE)]);

    $ar = $this->get('/ar/blog/')->assertOk()->getContent();

    expect(bltTags($ar))->toContain('نادي البشرة الزجاجية')->toContain('أخبار كي بيوتي')->not->toContain('الأخبار');
});

it('lists the tags in Translation -> Language settings and saves only short plain pairs, admin only', function () {
    bltPosts();

    $this->getJson('/admin-api/translations/settings')->assertUnauthorized();
    $this->actingAs(AdminUser::create(['name' => 'BT', 'email' => 'bt-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    $tags = collect($this->getJson('/admin-api/translations/settings')->assertOk()->json('blog_tags'))->keyBy('en');
    expect($tags['News']['shipped'])->toBe('الأخبار')
        ->and($tags['Glass Skin Club']['shipped'])->toBe('')
        ->and($tags['News']['ar'])->toBe('');

    $this->postJson('/admin-api/translations/settings', ['blog_tags_ar' => ['Glass Skin Club' => '<b>نادي</b>', 'News' => '']])
        ->assertOk();
    expect(json_decode((string) Setting::query()->where('key', BlogTags::SETTING)->value('value'), true))
        ->toBe(['glass skin club' => 'نادي']);

    $this->postJson('/admin-api/translations/settings', ['blog_tags_ar' => ['News' => str_repeat('ا', 61)]])->assertStatus(422);
    $this->postJson('/admin-api/translations/settings', ['blog_tags_ar' => 'News'])->assertStatus(422);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/translation-screens.blade.php'));
    expect(substr_count($screen, 'data-tr-act="save-tags"'))->toBe(1);
});
