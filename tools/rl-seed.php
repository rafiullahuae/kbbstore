<?php
// Lane RL preview seed: an owner to sign in as, and a staff list that shows
// every shape the Members tab draws -- a preset, a custom role, a custom title
// and per-person tweaks. Run by tools/rl-preview.sh through artisan tinker.
use App\Models\AdminRole;
use App\Models\AdminUser;

$owner = AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Rafi (Owner)', 'password' => 'preview-secret-1', 'role' => 'owner']);
$id = fn (string $slug) => AdminRole::query()->where('slug', $slug)->value('id');

$weekend = AdminRole::updateOrCreate(['slug' => 'custom-weekend-lead'], [
    'name' => 'Weekend lead', 'tier' => 'manager', 'is_preset' => false,
    'description' => 'Covers Friday and Saturday: orders, customers and reviews, no money.',
    'capabilities' => ['admin.access', 'dashboard.view', 'orders.view', 'orders.manage', 'orders.edit', 'invoices.view', 'customers.view', 'reviews.view', 'reviews.moderate'],
]);

$people = [
    ['Sara Ahmed', 'sara@preview.test', 'seo-manager', 'Head of SEO', ['analytics.view', 'banners.manage'], ['catalog.export']],
    ['Omar Haddad', 'omar@preview.test', 'store-manager', null, [], []],
    ['Lina Park', 'lina@preview.test', 'customer-support', null, [], []],
    ['Ravi Menon', 'ravi@preview.test', 'inventory-manager', 'Stock & purchasing', [], []],
    ['Noor Ali', 'noor@preview.test', null, null, [], []],
];
foreach ($people as [$name, $email, $slug, $title, $grants, $revokes]) {
    $u = AdminUser::updateOrCreate(['email' => $email], ['name' => $name, 'password' => 'preview-secret-1', 'role' => 'editor']);
    $role = $slug ? AdminRole::query()->where('slug', $slug)->first() : $weekend;
    $u->forceFill(['role' => $role->tier, 'role_id' => $role->id, 'role_title' => $title, 'grants' => $grants ?: null, 'revokes' => $revokes ?: null])->save();
}
AdminRole::query()->where('slug', 'marketing-manager')->update(['name' => 'Growth Manager']);
App\Support\AdminRoles::flush();
echo "rl seed done\n";
