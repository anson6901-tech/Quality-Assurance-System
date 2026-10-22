<?php

namespace App\Http\Controllers\Quality;

use App\Enums\Placement;
use App\Enums\UniversityRole;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\User;
use App\Support\Area;
use App\Support\Placements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organizational structure: who is placed at university, school and department level.
 * A user's placement decides which part of the university they can see; their role decides
 * what they can do there.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor->canManageUsers(), 403);

        $users = Area::users(User::query(), $actor)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'university_role', 'school_id', 'department_id', 'placement', 'is_active']);
        $roleLabels = collect(QualityRolePermission::roleOptions())->pluck('label', 'value');
        $member = fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->university_role,
            'roleLabel' => $roleLabels[$user->university_role] ?? $user->university_role,
            'isActive' => $user->is_active,
            'isCurrentUser' => $user->is($actor),
        ];
        $placed = fn (Placement $placement) => $users->filter(fn (User $user) => $user->placement === $placement);
        $head = fn (Collection $members, UniversityRole $role) => $members
            ->first(fn (User $user) => $user->university_role === $role->value && $user->is_active);

        $departments = Area::departments(Department::query(), $actor)->orderBy('name')->get(['id', 'school_id', 'name', 'code']);

        $schools = Area::schools(School::query(), $actor)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(function (School $school) use ($departments, $placed, $head, $member) {
                $schoolMembers = $placed(Placement::School)->where('school_id', $school->id);

                return [
                    'id' => $school->id,
                    'name' => $school->name,
                    'code' => $school->code,
                    'head' => ($dean = $head($schoolMembers, UniversityRole::Dean)) ? $member($dean) : null,
                    'members' => $schoolMembers->map($member)->values(),
                    'departments' => $departments
                        ->where('school_id', $school->id)
                        ->map(function (Department $department) use ($placed, $head, $member) {
                            $departmentMembers = $placed(Placement::Department)->where('department_id', $department->id);

                            return [
                                'id' => $department->id,
                                'schoolId' => $department->school_id,
                                'name' => $department->name,
                                'code' => $department->code,
                                'head' => ($hod = $head($departmentMembers, UniversityRole::Hod)) ? $member($hod) : null,
                                'members' => $departmentMembers->map($member)->values(),
                            ];
                        })
                        ->values(),
                ];
            });

        return Inertia::render('quality/organization/Index', [
            'canPlaceAtUniversity' => $actor->isPlacedAtUniversity(),
            'canPlaceAtSchool' => $actor->placement !== Placement::Department,
            'actorPlacementLabel' => $actor->placementLabel(),
            'university' => $actor->isPlacedAtUniversity()
                ? $placed(Placement::University)->map($member)->values()
                : null,
            'schools' => $schools,
            'unplaced' => $users->whereNull('placement')->map($member)->values(),
            'people' => $users->map(fn (User $user) => [
                ...$member($user),
                'placement' => $user->placement?->value,
                'placementLabel' => $user->placementLabel(),
            ])->values(),
        ]);
    }

    public function place(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeTarget($actor, $user);

        $validated = $request->validate([
            'placement' => ['required', Rule::enum(Placement::class)],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        Placements::place(
            $actor,
            $user,
            Placement::from($validated['placement']),
            isset($validated['school_id']) ? (int) $validated['school_id'] : null,
            isset($validated['department_id']) ? (int) $validated['department_id'] : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name placed at :place.', [
            'name' => $user->name,
            'place' => $user->refresh()->placementLabel(),
        ])]);

        return to_route('quality.organization.index');
    }

    public function remove(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeTarget($actor, $user);

        Placements::place($actor, $user, null, null, null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed from the structure and can now see only their own work.', [
            'name' => $user->name,
        ])]);

        return to_route('quality.organization.index');
    }

    /**
     * Admins manage placements inside their own area, and never their own, so they cannot
     * lock themselves out.
     */
    private function authorizeTarget(User $actor, User $user): void
    {
        abort_unless($actor->canManageUsers(), 403);
        abort_if($user->is($actor), 422, __('You cannot change your own placement.'));
        abort_unless(Area::users(User::query(), $actor)->whereKey($user->id)->exists(), 403);
    }
}
