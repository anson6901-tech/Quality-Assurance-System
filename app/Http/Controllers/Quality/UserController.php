<?php

namespace App\Http\Controllers\Quality;

use App\Actions\Teams\CreateTeam;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageUsers(), 403);

        return Inertia::render('quality/users/Index', [
            'users' => User::query()
                ->with(['school:id,name', 'department:id,name'])
                ->latest()
                ->get(['id', 'name', 'email', 'university_role', 'school_id', 'department_id', 'created_at'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->university_role,
                    'roleLabel' => QualityRolePermission::labelFor($user->university_role),
                    'school' => $user->school ? ['id' => $user->school->id, 'name' => $user->school->name] : null,
                    'department' => $user->department ? ['id' => $user->department->id, 'name' => $user->department->name] : null,
                ]),
            'roles' => QualityRolePermission::roleOptions(),
            'schools' => School::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'departments' => Department::query()
                ->with('school:id,name')
                ->orderBy('name')
                ->get(['id', 'school_id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        [$validated, $schoolId, $departmentId] = $this->validatedUserData($request);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
            'university_role' => $validated['university_role'],
            'school_id' => $schoolId,
            'department_id' => $departmentId,
        ]);

        app(CreateTeam::class)->handle($user, "{$user->name}'s Team", true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('quality.users.index');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        [$validated, $schoolId, $departmentId] = $this->validatedUserData($request, $user);

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'university_role' => $validated['university_role'],
            'school_id' => $schoolId,
            'department_id' => $departmentId,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('quality.users.index');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password reset.')]);

        return to_route('quality.users.index');
    }

    /**
     * @return array{0: array<string, mixed>, 1: int|null, 2: int|null}
     */
    private function validatedUserData(Request $request, ?User $user = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'university_role' => ['required', 'string', Rule::in(array_column(QualityRolePermission::roleOptions(), 'value'))],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'password' => [$user ? 'sometimes' : 'required', 'confirmed', Password::defaults()],
        ]);

        $role = $validated['university_role'];
        $permissions = QualityRolePermission::permissionsFor($role);
        $schoolId = null;
        $departmentId = null;

        if (in_array('view_school_reports', $permissions, true)) {
            $request->validate([
                'school_id' => ['required', 'integer', Rule::exists('schools', 'id')],
            ]);

            $schoolId = (int) $validated['school_id'];
        }

        if (array_intersect($permissions, ['view_department_reports', 'view_own_reports'])) {
            $request->validate([
                'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            ]);

            $department = Department::findOrFail($validated['department_id']);
            $schoolId = $department->school_id;
            $departmentId = $department->id;
        }

        return [$validated, $schoolId, $departmentId];
    }
}
