<?php

namespace App\Http\Controllers\Quality;

use App\Enums\UniversityRole;
use App\Http\Controllers\Controller;
use App\Models\QualityRolePermission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleRightController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        return to_route('quality.roles.index');
    }

    public function roles(Request $request): Response
    {
        abort_unless($request->user()->canManageUsers(), 403);

        QualityRolePermission::ensureDefaults();

        $userCounts = User::query()
            ->selectRaw('university_role, COUNT(*) as aggregate')
            ->groupBy('university_role')
            ->pluck('aggregate', 'university_role');

        $roles = collect(QualityRolePermission::roleOptions())
            ->map(fn (array $role): array => [
                ...$role,
                'isSystem' => (bool) UniversityRole::tryFrom($role['value']),
                'permissionCount' => count($role['permissions']),
                'userCount' => (int) ($userCounts[$role['value']] ?? 0),
            ])
            ->values();

        return Inertia::render('quality/roles/Index', [
            'roles' => $roles,
        ]);
    }

    public function permissions(Request $request): Response
    {
        abort_unless($request->user()->canManageUsers(), 403);

        QualityRolePermission::ensureDefaults();

        return Inertia::render('quality/permissions/Index', [
            'roles' => collect(QualityRolePermission::roleOptions())
                ->map(fn (array $role): array => [
                    ...$role,
                    'isSystem' => (bool) UniversityRole::tryFrom($role['value']),
                ])
                ->values(),
            'permissionGroups' => QualityRolePermission::permissionGroups(),
            'selectedRole' => $request->string('role')->toString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(QualityRolePermission::availablePermissions()))],
        ]);

        $role = str($validated['label'])->slug('_')->toString();

        $request->validate([
            'label' => [Rule::unique('quality_role_permissions', 'label')],
        ]);

        abort_if(QualityRolePermission::query()->where('role', $role)->exists(), 422, __('A role with this name already exists.'));

        QualityRolePermission::query()->create([
            'role' => $role,
            'label' => $validated['label'],
            'permissions' => array_values(array_unique($validated['permissions'] ?? [])),
            'is_system' => false,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route(
            $request->routeIs('quality.roles-rights.*')
                ? 'quality.roles-rights.index'
                : 'quality.roles.index',
        );
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::exists('quality_role_permissions', 'role')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(QualityRolePermission::availablePermissions()))],
        ]);

        abort_unless($validated['role'] === $role, 422);

        QualityRolePermission::query()->updateOrCreate(
            ['role' => $role],
            [
                'permissions' => array_values(array_unique($validated['permissions'] ?? [])),
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role rights updated.')]);

        return to_route('quality.roles-rights.index');
    }

    public function updatePermissions(Request $request, string $role): RedirectResponse
    {
        $this->savePermissions($request, $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role permissions updated.')]);

        return to_route('quality.permissions.index', ['role' => $role]);
    }

    public function destroy(Request $request, string $role): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);
        abort_if(UniversityRole::tryFrom($role), 409, __('System roles cannot be deleted.'));

        $roleRight = QualityRolePermission::query()
            ->where('role', $role)
            ->where('is_system', false)
            ->firstOrFail();

        abort_if(
            User::query()->where('university_role', $role)->exists(),
            409,
            __('Move users out of this role before deleting it.'),
        );

        $roleRight->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route(
            $request->routeIs('quality.roles-rights.*')
                ? 'quality.roles-rights.index'
                : 'quality.roles.index',
        );
    }

    private function savePermissions(Request $request, string $role): void
    {
        abort_unless($request->user()->canManageUsers(), 403);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::exists('quality_role_permissions', 'role')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(array_keys(QualityRolePermission::availablePermissions()))],
        ]);

        abort_unless($validated['role'] === $role, 422);

        QualityRolePermission::query()->updateOrCreate(
            ['role' => $role],
            ['permissions' => array_values(array_unique($validated['permissions'] ?? []))],
        );
    }
}
