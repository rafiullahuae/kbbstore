<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Support\AdminCapabilities;
use App\Support\AdminRoles;
use Illuminate\Support\Str;
use Tests\Support\CatalogProductsAdminRoutes;

/*
 * DELETE A PRODUCT, AND THE TRASH. (Lane PD)
 *
 * What it looked like (owner, 23 October): "we don't have delete option for
 * the products. i want a red delete button (will ask confirmation) and upon
 * deletion the product will go to trash ... only for admin (me) by default."
 * Catalog -> Products had a Trash chip that could only ever be empty: nothing
 * in the console moved a product into it.
 *
 * Mutation notes: drop the two catalog.delete rules from AdminCapabilities
 * and the manager case is red (the catalog.manage wildcard lets them in); make
 * trash() call forceDelete and the restore case is red; remove can_delete from
 * the list and the screen case is.
 */
function ptrProduct(string $name): Product
{
    return Product::create(['slug' => Str::slug($name) . '-' . Str::random(5), 'name' => $name, 'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock']);
}

function ptrAdmin(string $role): AdminUser
{
    return AdminUser::create(['name' => 'PT ' . $role, 'email' => 'pt-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

beforeEach(fn () => CatalogProductsAdminRoutes::wire(app()));

it('moves a product to the trash, off the shop, and restores it exactly as it was', function () {
    $p = ptrProduct('Trash Me Serum');
    $keep = ptrProduct('Keep Me Cream');
    $this->actingAs(ptrAdmin('owner'), 'admin');

    $this->postJson('/admin-api/catalog-products-trash', ['ids' => [$p->id]])->assertOk()->assertJson(['changed' => 1]);

    expect(Product::find($p->id))->toBeNull()
        ->and(Product::withTrashed()->find($p->id)?->trashed())->toBeTrue()
        ->and(Product::find($keep->id))->not->toBeNull();
    $this->get('/product/' . $p->slug . '/')->assertNotFound();

    $trash = $this->getJson('/admin-api/catalog-products-list?filter=trashed')->assertOk()->json();
    expect(array_column($trash['products'], 'id'))->toBe([$p->id])->and($trash['counts']['trashed'])->toBe(1);

    $this->postJson('/admin-api/catalog-products-restore', ['ids' => [$p->id]])->assertOk()->assertJson(['changed' => 1]);
    expect(Product::find($p->id)?->only(['name', 'status', 'price']))->toBe(['name' => 'Trash Me Serum', 'status' => 'publish', 'price' => 9900]);
});

it('is the owner\'s alone by default, and a role can be given it', function () {
    $p = ptrProduct('Guarded Toner');

    expect(AdminCapabilities::CAPABILITIES['catalog.delete'])->toBe(['owner']);

    foreach (['manager', 'editor', 'support'] as $role) {
        $this->actingAs(ptrAdmin($role), 'admin');
        $this->postJson('/admin-api/catalog-products-trash', ['ids' => [$p->id]])->assertForbidden();
        $this->postJson('/admin-api/catalog-products-restore', ['ids' => [$p->id]])->assertForbidden();
        expect($this->getJson('/admin-api/catalog-products-list')->json('can_delete'))->toBe($role === 'manager' || $role === 'editor' ? false : null);
    }
    expect(Product::find($p->id))->not->toBeNull();

    $labels = array_merge(...array_map(static fn ($s) => $s[2], AdminRoles::SECTIONS));
    expect($labels)->toHaveKey('catalog.delete');
});

it('draws a red Delete that asks Yes / No first, Restore in the trash, and a Trash button', function () {
    $this->actingAs(ptrAdmin('owner'), 'admin');
    expect($this->getJson('/admin-api/catalog-products-list')->json('can_delete'))->toBeTrue();

    $js = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect($js)->toContain('data-cpdel="')
        // Delete asks Yes / No in the admin's box before its handler runs ...
        ->and($js)->toContain('data-cpdel="\' + p.id + \'" data-kbb-sure="')
        ->and($js)->toContain("b.onclick = function(){ cpRunTrash('trash', [+b.dataset.cpdel]); };")
        // ... and so does Restore, from the guard's own list.
        ->and((string) file_get_contents(resource_path('views/admin/partials/reset-guard.blade.php')))->toContain("['[data-cprestore]', 'Take this product out of the trash?")
        ->and($js)->toContain('data-cprestore="')
        ->and($js)->toContain('id="cplTrashBtn"')
        ->and($js)->toContain("'/admin-api/catalog-products-trash'")
        ->and($js)->toContain("'/admin-api/catalog-products-restore'");
});
