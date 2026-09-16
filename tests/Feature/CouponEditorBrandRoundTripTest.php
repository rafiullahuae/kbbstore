<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Coupon;

/**
 * The brand pickers, end to end through the screen's own endpoints.
 *
 * The enforcement and the editor were built by two different lanes against two
 * different trees: one added brand_ids to the pricing code, the other drew the
 * picker. Each is tested on its own side. This is the join — the lookup the
 * picker searches with, the save it posts, and the read-back that repopulates
 * the chips when the coupon is reopened.
 *
 * That join is exactly where the coupon editor has already gone wrong once:
 * "Limit usage to X items" was a field on a screen with no column behind it,
 * and a restriction the owner sets which the till ignores is worse than no
 * field at all.
 */
function couponAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'coupon-brand-' . uniqid() . '@example.com',
        'password' => bcrypt('secret-secret'),
        'role' => 'owner',
    ]);
}

it('finds brands through the picker lookup the editor calls', function () {
    $brand = Brand::create(['name' => 'Anua Test', 'slug' => 'anua-test-' . uniqid()]);

    $body = $this->actingAs(couponAdmin(), 'admin')
        ->getJson('/admin-api/coupons/manage/lookup?kind=brand&q=Anua')
        ->assertOk()
        ->json();

    $ids = collect($body['results'] ?? $body['items'] ?? $body)
        ->pluck('id')
        ->all();

    expect(in_array($brand->id, $ids, true))
        ->toBeTrue('the brand lookup did not return a brand matching the search');
});

it('saves brand restrictions and reads them back as chips', function () {
    $only = Brand::create(['name' => 'Only Brand', 'slug' => 'only-' . uniqid()]);
    $never = Brand::create(['name' => 'Never Brand', 'slug' => 'never-' . uniqid()]);

    $admin = couponAdmin();

    $created = $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/coupons/manage', [
            'code' => 'BRANDS' . random_int(1000, 9999),
            'type' => 'percent',
            'amount' => '10',
            'brand_ids' => [$only->id],
            'excluded_brand_ids' => [$never->id],
            'limit_usage_to_x_items' => 2,
            'free_shipping' => true,
        ])
        ->assertCreated()
        ->json();

    $id = $created['coupon']['id'] ?? $created['id'];

    // Stored as the pricing code expects to read them.
    $row = Coupon::findOrFail($id);

    expect($row->brand_ids)->toBe([$only->id], 'brand_ids did not reach the column')
        ->and($row->excluded_brand_ids)->toBe([$never->id], 'excluded_brand_ids did not reach the column')
        ->and((int) $row->limit_usage_to_x_items)->toBe(2, 'the item cap did not reach the column')
        ->and((bool) $row->free_shipping)->toBeTrue('free shipping did not reach the column');

    // And read back in the shape the editor repopulates its chips from.
    $show = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/coupons/manage/' . $id)
        ->assertOk()
        ->json();

    $coupon = $show['coupon'] ?? $show;

    expect(collect($coupon['brands'] ?? [])->pluck('id')->all())
        ->toBe([$only->id], 'reopening the coupon would show no brand chip');
    expect(collect($coupon['excluded_brands'] ?? [])->pluck('id')->all())
        ->toBe([$never->id], 'reopening the coupon would show no excluded-brand chip');
    expect($coupon['limit_usage_to_x_items'])
        ->toBe(2, 'reopening the coupon would show an empty item cap');
});

it('leaves free shipping alone when the form does not post it', function () {
    /*
     * The hazard that made this field dangerous to enable. A DISABLED checkbox
     * posts nothing, and boolean() reads a missing key as false — so while the
     * box was greyed out, an unconditional write would have cleared the flag on
     * every save, silently, on the only rows that carry it (all imported from
     * WooCommerce). Pinned here so nobody re-greys the box and reintroduces it.
     */
    $admin = couponAdmin();

    $coupon = Coupon::create([
        'code' => 'KEEPSHIP' . random_int(1000, 9999),
        'type' => 'percent',
        'amount' => 1000,
        'free_shipping' => true,
    ]);

    $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/coupons/manage/' . $coupon->id, [
            'code' => $coupon->code,
            'type' => 'percent',
            'amount' => '15',
            // free_shipping deliberately absent, as a disabled input would send it
        ])
        ->assertOk();

    expect((bool) $coupon->fresh()->free_shipping)
        ->toBeTrue('a save that did not mention free shipping cleared it');
});
