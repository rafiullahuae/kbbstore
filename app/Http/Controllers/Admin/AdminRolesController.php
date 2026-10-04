<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Services\SecurityModule;
use App\Support\AdminRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Platform → Users & Roles — the Roles tab and the Members tab. (Lane RL)
 *
 * Capabilities (AdminCapabilities::RULES): everything under /roles/members is
 * users.manage, the rest of /roles is roles.manage. Both are Full-Admin-only by
 * default, and both fail closed for every other role.
 *
 * WHAT NOBODY CAN DO HERE, WHATEVER THEY HOLD — AdminRoles::refusal() and the
 * role guards below:
 *   - change their own role or permissions (unless they are a Full Admin);
 *   - tick, grant or assign a capability they do not hold themselves;
 *   - touch a role or an account that holds anything they do not;
 *   - demote or delete the last Full Admin;
 *   - store a capability key the map does not know (422, named).
 *
 * Every write records one row in the security log (Store → Security).
 */
final class AdminRolesController extends Controller
{
    /** The audit event a role write records. Account changes use SecurityModule::E_ACCOUNT. */
    public const AUDIT_ROLE = 'admin.role';

    /* ================================================================ roles */

    /** GET /admin-api/roles */
    public function index(Request $request): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }

        return response()->json($this->payload($this->actor($request)));
    }

    /** POST /admin-api/roles — a custom role, started from an existing one. */
    public function store(Request $request): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }
        $actor = $this->actor($request);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'from' => 'required|integer',
            'description' => 'sometimes|nullable|string|max:255',
            'capabilities' => 'sometimes|array|max:200',
            'capabilities.*' => 'string|max:64',
        ]);

        $from = AdminRoles::roles()[(int) $data['from']] ?? null;
        if ($from === null) {
            return $this->fail(422, 'unknown_role', 'Choose a role to start from.');
        }
        $caps = array_key_exists('capabilities', $data) ? $this->keys($data['capabilities']) : AdminRoles::roleCapabilities($from);
        if ($caps instanceof JsonResponse) {
            return $caps;
        }
        if ($r = $this->nameTaken($data['name'])) {
            return $r;
        }
        if ($r = $this->beyond($actor, $caps)) {
            return $r;
        }

        $role = AdminRole::create([
            'slug' => $this->slug($data['name']),
            'name' => $this->text($data['name']),
            'description' => $this->text($data['description'] ?? $from['description'] ?? null),
            'tier' => $from['tier'] === 'owner' ? 'manager' : $from['tier'],
            'is_preset' => false,
            'capabilities' => $caps,
        ]);
        $this->audit('Role created: '.$role->name, null, 'from '.$from['name'].'; '.count($caps).' permissions', 'notice');

        return response()->json(['ok' => true, 'id' => $role->id] + $this->payload($actor));
    }

    /** PUT /admin-api/roles/{id} — rename, describe, re-tick. */
    public function update(Request $request, int $id): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }
        $actor = $this->actor($request);
        $data = $request->validate([
            'name' => 'sometimes|string|min:2|max:60',
            'description' => 'sometimes|nullable|string|max:255',
            'capabilities' => 'sometimes|array|max:200',
            'capabilities.*' => 'string|max:64',
        ]);
        $role = AdminRole::find($id);
        if ($role === null) {
            return $this->fail(404, 'not_found', 'That role no longer exists.');
        }
        $row = AdminRoles::roles()[$id] ?? null;
        $before = $row === null ? [] : AdminRoles::roleCapabilities($row);

        if ($r = $this->mayEdit($actor, $role, $before)) {
            return $r;
        }

        $after = $before;
        if (array_key_exists('capabilities', $data)) {
            if ($role->slug === 'full-admin') {
                return $this->fail(422, 'locked', 'Full Admin always holds every permission. Make a custom role instead.');
            }
            $after = $this->keys($data['capabilities']);
            if ($after instanceof JsonResponse) {
                return $after;
            }
            if ($r = $this->beyond($actor, $after)) {
                return $r;
            }
        }
        if (isset($data['name']) && ($r = $this->nameTaken($data['name'], $role->id))) {
            return $r;
        }

        $oldName = $role->name;
        if (isset($data['name'])) {
            $role->name = $this->text($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $role->description = $this->text($data['description']);
        }
        if (array_key_exists('capabilities', $data)) {
            // A preset ticked back to exactly its default keeps following the code.
            $role->capabilities = ($role->is_preset && $this->same($after, AdminRoles::presetDefault($role->slug))) ? null : $after;
        }
        $role->save();

        $this->audit('Role changed: '.$role->name, $this->diffLine($before, $after, $oldName, $role->name, true), $this->diffLine($before, $after, $oldName, $role->name, false), $after === $before ? 'notice' : 'alert');

        return response()->json(['ok' => true] + $this->payload($actor));
    }

    /** POST /admin-api/roles/{id}/restore — a preset back to its default name and permissions. */
    public function restore(Request $request, int $id): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }
        $actor = $this->actor($request);
        $role = AdminRole::find($id);
        if ($role === null || ! $role->is_preset || ! isset(AdminRoles::PRESETS[$role->slug])) {
            return $this->fail(422, 'not_preset', 'Only a predefined role has a default to restore.');
        }
        $before = AdminRoles::roleCapabilities(AdminRoles::roles()[$id] ?? ['slug' => $role->slug, 'is_preset' => true, 'capabilities' => null]);
        $after = AdminRoles::presetDefault($role->slug);
        if ($r = $this->mayEdit($actor, $role, $before) ?? $this->beyond($actor, $after)) {
            return $r;
        }

        [$name, , $description] = AdminRoles::PRESETS[$role->slug];
        $old = $role->name;
        $role->forceFill(['name' => $name, 'description' => $description, 'capabilities' => null])->save();
        $this->audit('Role restored to default: '.$name, $this->diffLine($before, $after, $old, $name, true), $this->diffLine($before, $after, $old, $name, false), 'notice');

        return response()->json(['ok' => true] + $this->payload($actor));
    }

    /** DELETE /admin-api/roles/{id} — custom roles only, and only when nobody is on one. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }
        $actor = $this->actor($request);
        $role = AdminRole::find($id);
        if ($role === null) {
            return $this->fail(404, 'not_found', 'That role no longer exists.');
        }
        if ($role->is_preset) {
            return $this->fail(422, 'preset', 'Predefined roles cannot be deleted. Restore its default instead.');
        }
        $before = AdminRoles::roleCapabilities(AdminRoles::roles()[$id] ?? ['slug' => '', 'is_preset' => false, 'capabilities' => []]);
        if ($r = $this->mayEdit($actor, $role, $before)) {
            return $r;
        }
        $using = AdminUser::query()->where('role_id', $id)->count();
        if ($using > 0) {
            return $this->fail(409, 'in_use', $using === 1
                ? '1 person is on this role. Move them to another role first.'
                : $using.' people are on this role. Move them to another role first.');
        }

        $role->delete();
        $this->audit('Role deleted: '.$role->name, count($before).' permissions', null, 'alert');

        return response()->json(['ok' => true] + $this->payload($actor));
    }

    /* ============================================================== members */

    /** GET /admin-api/roles/members */
    public function members(Request $request): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }
        $actor = $this->actor($request);
        // "Online now" from the edit-presence heartbeats: one query for everybody.
        $online = \App\Support\EditPresence::onlineMap();

        // One query for every account; access is worked out from the cached
        // role table, so the list costs the same for two people or two hundred.
        $members = AdminUser::query()
            ->select(['id', 'name', 'email', 'role', 'role_id', 'role_title', 'grants', 'revokes', 'created_at'])
            ->orderBy('id')
            ->get()
            ->map(function (AdminUser $u) use ($actor, $online) {
                $role = AdminRoles::roleOf($u);
                $full = AdminRoles::isFull($u);
                if ($full && ($role === null || $role['slug'] !== 'full-admin')) {
                    $role = AdminRoles::bySlug('full-admin') ?? $role;
                }
                // Tweaks as they act on THIS role: a grant it already has, or a
                // revoke of something it never had, is not shown as a change.
                $base = $role === null ? [] : AdminRoles::roleCapabilities($role);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role_id' => $role['id'] ?? null,
                    'role_name' => $role['name'] ?? null,
                    'role_title' => $u->role_title,
                    'full' => $full,
                    'grants' => $full ? [] : array_values(array_diff(AdminRoles::clean($u->grants), $base)),
                    'revokes' => $full ? [] : array_values(array_intersect(AdminRoles::clean($u->revokes), $base)),
                    'effective' => AdminRoles::resolve($u),
                    'is_self' => (int) $u->id === (int) $actor->id,
                    'online' => isset($online[(int) $u->id]),
                    'editing' => $online[(int) $u->id]['editing'] ?? null,
                    'created_at' => $u->created_at?->toDateString(),
                ];
            })
            ->values();

        return response()->json(['members' => $members] + $this->payload($actor));
    }

    /** POST /admin-api/roles/members — a new staff account on a role. */
    public function createMember(Request $request): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }
        $actor = $this->actor($request);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:admin_users,email',
            'password' => 'required|string|min:8|max:255',
        ] + $this->accessRules(true));

        $u = new AdminUser(['name' => $data['name'], 'email' => strtolower(trim($data['email'])), 'password' => $data['password']]);
        if ($r = $this->applyAccess($actor, null, $u, $data)) {
            return $r;
        }
        $u->save();
        $this->audit('Back-office account created: '.$u->email, null,
            $this->accessLine(AdminRoles::roleOf($u)['name'] ?? '—', AdminRoles::clean($u->grants), AdminRoles::clean($u->revokes), $u->role_title),
            'alert', SecurityModule::E_ACCOUNT, (string) $u->email);

        return response()->json(['ok' => true, 'id' => $u->id]);
    }

    /** PUT /admin-api/roles/members/{id} — role, per-person tweaks, title, name, password. */
    public function updateMember(Request $request, int $id): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }
        $actor = $this->actor($request);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'password' => 'sometimes|nullable|string|min:8|max:255',
        ] + $this->accessRules(false));
        $u = AdminUser::find($id);
        if ($u === null) {
            return $this->fail(404, 'not_found', 'That account no longer exists.');
        }

        if ($r = $this->applyAccess($actor, $u, $u, $data)) {
            return $r;
        }
        if (isset($data['name'])) {
            $u->name = $data['name'];
        }
        if (! empty($data['password'])) {
            $u->password = $data['password'];
        }
        $u->save();

        return response()->json(['ok' => true, 'id' => $u->id]);
    }

    /** DELETE /admin-api/roles/members/{id} */
    public function deleteMember(Request $request, int $id): JsonResponse
    {
        if ($r = $this->notMigrated()) {
            return $r;
        }
        $actor = $this->actor($request);
        $u = AdminUser::find($id);
        if ($u === null) {
            return $this->fail(404, 'not_found', 'That account no longer exists.');
        }
        if ((int) $u->id === (int) $actor->id) {
            return $this->fail(422, 'self', 'You cannot delete your own account.');
        }
        if ($refused = AdminRoles::refusal($actor, $u, null)) {
            return $this->fail(...$refused);
        }
        $u->delete();
        $this->audit('Back-office account deleted: '.$u->email, $this->accessLine(AdminRoles::roleOf($u)['name'] ?? '—', [], [], $u->role_title), null,
            'alert', SecurityModule::E_ACCOUNT, (string) $u->email);

        return response()->json(['ok' => true]);
    }

    /* ============================================================ internals */

    private function accessRules(bool $creating): array
    {
        return [
            'role_id' => ($creating ? 'required' : 'sometimes').'|integer',
            'role_title' => 'sometimes|nullable|string|max:60',
            'grants' => 'sometimes|nullable|array|max:200',
            'grants.*' => 'string|max:64',
            'revokes' => 'sometimes|nullable|array|max:200',
            'revokes.*' => 'string|max:64',
        ];
    }

    /**
     * Put the requested role, tweaks and title on $u (unsaved), or refuse.
     * $target is the account as it stands (null when creating).
     */
    private function applyAccess(AdminUser $actor, ?AdminUser $target, AdminUser $u, array $data): ?JsonResponse
    {
        $current = $target === null ? null : AdminRoles::roleOf($target);
        $roleId = array_key_exists('role_id', $data) ? (int) $data['role_id'] : ($current['id'] ?? null);
        $role = $roleId === null ? null : (AdminRoles::roles()[$roleId] ?? null);
        if ($role === null) {
            return $this->fail(422, 'unknown_role', 'Choose one of the roles in the list.');
        }

        $grants = array_key_exists('grants', $data) ? $this->keys($data['grants'] ?? []) : AdminRoles::clean($target?->grants);
        $revokes = array_key_exists('revokes', $data) ? $this->keys($data['revokes'] ?? []) : AdminRoles::clean($target?->revokes);
        foreach ([$grants, $revokes] as $k) {
            if ($k instanceof JsonResponse) {
                return $k;
            }
        }

        $full = $role['slug'] === 'full-admin';
        $base = AdminRoles::roleCapabilities($role);
        // Tidy: a grant the role already has, or a revoke it never had, is noise.
        $grants = $full ? [] : array_values(array_diff($grants, $base));
        $revokes = $full ? [] : array_values(array_intersect($revokes, $base));

        $probe = new AdminUser();
        $probe->forceFill(['role' => $full ? 'owner' : ($role['tier'] === 'owner' ? 'manager' : $role['tier']), 'role_id' => $role['id'], 'grants' => $grants, 'revokes' => $revokes]);
        $after = ['full' => $full, 'caps' => AdminRoles::resolve($probe)];

        $changes = $target === null
            || (int) ($current['id'] ?? 0) !== (int) $role['id']
            || AdminRoles::isFull($target) !== $full
            || ! $this->same($grants, AdminRoles::clean($target->grants))
            || ! $this->same($revokes, AdminRoles::clean($target->revokes));

        if ($refused = AdminRoles::refusal($actor, $target, $after, $changes)) {
            return $this->fail(...$refused);
        }

        $titleBefore = $target?->role_title;
        $u->forceFill([
            'role' => $probe->role,
            'role_id' => $role['id'],
            'grants' => $grants === [] ? null : $grants,
            'revokes' => $revokes === [] ? null : $revokes,
        ]);
        if (array_key_exists('role_title', $data)) {
            $u->role_title = $this->text($data['role_title']);
        }

        if ($target !== null && ($changes || $titleBefore !== $u->role_title)) {
            $this->audit('Back-office access changed: '.$target->email,
                $this->accessLine($current['name'] ?? '—', AdminRoles::clean($target->grants), AdminRoles::clean($target->revokes), $titleBefore),
                $this->accessLine($role['name'], $grants, $revokes, $u->role_title),
                $changes ? 'alert' : 'notice', SecurityModule::E_ACCOUNT, (string) $target->email);
        }

        return null;
    }

    /** The screen's shared half: sections, roles with member counts, and who is asking. */
    private function payload(AdminUser $actor): array
    {
        $counts = [];
        foreach (AdminUser::query()->select('role_id', 'role', DB::raw('count(*) as n'))->groupBy('role_id', 'role')->get() as $row) {
            $probe = new AdminUser();
            $probe->forceFill(['role' => $row->role, 'role_id' => $row->role_id]);
            $role = AdminRoles::isFull($probe) ? AdminRoles::bySlug('full-admin') : AdminRoles::roleOf($probe);
            if ($role !== null) {
                $counts[$role['id']] = ($counts[$role['id']] ?? 0) + (int) $row->n;
            }
        }

        $roles = [];
        foreach (AdminRoles::roles() as $r) {
            $default = isset(AdminRoles::PRESETS[$r['slug']]) && $r['is_preset'] ? AdminRoles::PRESETS[$r['slug']] : null;
            $roles[] = [
                'id' => $r['id'],
                'slug' => $r['slug'],
                'name' => $r['name'],
                'description' => $r['description'],
                'preset' => $r['is_preset'],
                'locked' => $r['slug'] === 'full-admin',
                'edited' => $default !== null && ($r['capabilities'] !== null || $r['name'] !== $default[0]),
                'capabilities' => AdminRoles::roleCapabilities($r),
                'members' => $counts[$r['id']] ?? 0,
            ];
        }

        $mine = AdminRoles::roleOf($actor);

        return [
            'sections' => AdminRoles::sections(),
            'roles' => $roles,
            'me' => [
                'id' => $actor->id,
                'full' => AdminRoles::isFull($actor),
                'role_id' => AdminRoles::isFull($actor) ? (AdminRoles::bySlug('full-admin')['id'] ?? null) : ($mine['id'] ?? null),
                'capabilities' => AdminRoles::for($actor),
            ],
        ];
    }

    /** Non-Full-Admins may not edit their own role, or a role reaching past their own access. */
    private function mayEdit(AdminUser $actor, AdminRole $role, array $current): ?JsonResponse
    {
        if (AdminRoles::isFull($actor)) {
            return null;
        }
        if ($role->slug === 'full-admin') {
            return $this->fail(403, 'outranks', 'Only a Full Admin can change the Full Admin role.');
        }
        if ((int) (AdminRoles::roleOf($actor)['id'] ?? 0) === (int) $role->id) {
            return $this->fail(403, 'own_role', 'You cannot change the role you are on. Ask a Full Admin.');
        }
        if (array_diff($current, AdminRoles::for($actor)) !== []) {
            return $this->fail(403, 'outranks', 'This role can do things your role cannot, so you cannot change it.');
        }

        return null;
    }

    private function beyond(AdminUser $actor, array $caps): ?JsonResponse
    {
        if (AdminRoles::isFull($actor)) {
            return null;
        }
        $beyond = array_values(array_diff($caps, AdminRoles::for($actor)));

        return $beyond === [] ? null
            : $this->fail(403, 'beyond_your_access', 'You cannot give permissions you do not have yourself: '.AdminRoles::describe($beyond).'.');
    }

    /** Every key known to the map, or a 422 naming the ones that are not. */
    private function keys(array $given): array|JsonResponse
    {
        $given = array_values(array_unique(array_map('strval', $given)));
        $unknown = array_values(array_diff($given, AdminRoles::known()));
        if ($unknown !== []) {
            return response()->json([
                'error' => 'unknown_capability',
                'message' => 'Not a permission this shop knows: '.implode(', ', array_slice($unknown, 0, 5)),
                'unknown' => array_slice($unknown, 0, 20),
            ], 422);
        }

        return AdminRoles::clean($given);
    }

    private function nameTaken(string $name, ?int $except = null): ?JsonResponse
    {
        $name = mb_strtolower($this->text($name) ?? '');
        foreach (AdminRoles::roles() as $r) {
            if ($r['id'] !== $except && mb_strtolower($r['name']) === $name) {
                return $this->fail(422, 'name_taken', 'There is already a role called “'.$r['name'].'”.');
            }
        }

        return null;
    }

    private function slug(string $name): string
    {
        $base = 'custom-'.(Str::slug($name) ?: 'role');
        $slug = substr($base, 0, 56);
        for ($i = 2; AdminRole::query()->where('slug', $slug)->exists(); $i++) {
            $slug = substr($base, 0, 52).'-'.$i;
        }

        return $slug;
    }

    /** Trimmed, control characters removed, null when empty. Escaped on output, never trusted. */
    private function text(?string $v): ?string
    {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v) ?? '');

        return $v === '' ? null : $v;
    }

    private function same(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    private function diffLine(array $before, array $after, string $oldName, string $newName, bool $old): string
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if ($old) {
            return 'name: '.$oldName.'; '.count($before).' permissions';
        }

        return 'name: '.$newName.'; '.count($after).' permissions'
            .($added ? '; added: '.AdminRoles::describe($added) : '')
            .($removed ? '; removed: '.AdminRoles::describe($removed) : '');
    }

    private function accessLine(string $role, array $grants, array $revokes, ?string $title): string
    {
        return 'role: '.$role
            .($grants ? '; plus: '.AdminRoles::describe($grants) : '')
            .($revokes ? '; minus: '.AdminRoles::describe($revokes) : '')
            .($title ? '; title: '.$title : '');
    }

    private function audit(string $summary, ?string $before, ?string $after, string $severity, string $event = self::AUDIT_ROLE, ?string $subject = null): void
    {
        app(SecurityModule::class)->record($event, $summary, [
            'subject' => $subject ?? $summary,
            'before' => $before,
            'after' => $after,
            'severity' => $severity,
        ]);
    }

    private function actor(Request $request): AdminUser
    {
        /** @var AdminUser $actor */
        $actor = $request->user('admin');

        return $actor;
    }

    private function fail(int $status, string $error, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }

    /** The code is here before its migration has run: say so rather than 500. */
    private function notMigrated(): ?JsonResponse
    {
        return Schema::hasTable('admin_roles') ? null
            : $this->fail(503, 'not_migrated', 'Roles are installed but their database tables are not. Apply the update’s migrations (Platform → Core Updates), then reload.');
    }
}
