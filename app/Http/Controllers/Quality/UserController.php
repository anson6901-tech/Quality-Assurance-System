<?php

namespace App\Http\Controllers\Quality;

use App\Enums\UniversityRole;
use App\Http\Controllers\Controller;
use App\Models\Department;
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
        abort_unless($request->user()->canManageQualityStructure(), 403);

        return Inertia::render('quality/users/Index', [
            'users' => User::query()
                ->with(['school:id,name', 'department:id,name'])
                ->latest()
                ->get(['id', 'name', 'email', 'university_role', 'school_id', 'department_id', 'created_at'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->university_role?->value,
                    'roleLabel' => $user->university_role?->label(),
                    'school' => $user->school ? ['id' => $user->school->id, 'name' => $user->school->name] : null,
                    'department' => $user->department ? ['id' => $user->department->id, 'name' => $user->department->name] : null,
                ]),
            'roles' => UniversityRole::userOptions(),
            'departments' => Department::query()
                ->with('school:id,name')
                ->orderBy('name')
                ->get(['id', 'school_id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageQualityStructure(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'university_role' => ['required', 'string', Rule::in(array_column(UniversityRole::userOptions(), 'value'))],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $role = UniversityRole::from($validated['university_role']);
        $department = null;

        if ($role === UniversityRole::Lecturer) {
            $request->validate([
                'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            ]);

            $department = Department::findOrFail($validated['department_id']);
        }

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
            'university_role' => $role,
            'school_id' => $department?->school_id,
            'department_id' => $department?->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('quality.users.index');
    }
}
