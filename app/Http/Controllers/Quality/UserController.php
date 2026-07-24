<?php

namespace App\Http\Controllers\Quality;

use App\Actions\Teams\CreateTeam;
use App\Enums\UniversityRole;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canManageUsers(), 403);

        return Inertia::render('quality/users/Index', [
            'users' => User::query()
                ->with(['school:id,name', 'department:id,school_id,name', 'department.school:id,name'])
                ->latest()
                ->get(['id', 'name', 'email', 'staff_id', 'university_role', 'school_id', 'department_id', 'is_active', 'created_at'])
                ->map(function (User $user) use ($request): array {
                    $school = $user->school ?? $user->department?->school;
                    [$firstName, $lastName] = $this->splitName($user->name);

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                        'email' => $user->email,
                        'staffId' => $user->staff_id,
                        'role' => $user->university_role,
                        'roleLabel' => QualityRolePermission::labelFor($user->university_role),
                        'school' => $school ? ['id' => $school->id, 'name' => $school->name] : null,
                        'department' => $user->department ? ['id' => $user->department->id, 'name' => $user->department->name] : null,
                        'isActive' => $user->is_active,
                        'isCurrentUser' => $user->is($request->user()),
                    ];
                }),
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
            'staff_id' => $validated['staff_id'] ?? null,
            'password' => Hash::make($validated['password']),
            'must_change_password' => true,
            'email_verified_at' => now(),
            'university_role' => $validated['university_role'],
            'school_id' => $schoolId,
            'department_id' => $departmentId,
        ]);

        app(CreateTeam::class)->handle($user, "{$user->name}'s Team", true);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('quality.users.index');
    }

    public function downloadImportTemplate(Request $request): StreamedResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        $departments = Department::query()
            ->with('school:id,code')
            ->orderBy('name')
            ->get(['id', 'school_id', 'code']);

        return response()->streamDownload(function () use ($departments): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'first_name',
                'last_name',
                'email',
                'staff_id',
                'university_role',
                'school_code',
                'department_code',
                'temporary_password',
            ]);

            foreach ($departments as $department) {
                fputcsv($output, [
                    '',
                    '',
                    '',
                    '',
                    UniversityRole::Lecturer->value,
                    $department->school?->code,
                    $department->code,
                    '',
                ]);
            }

            fclose($output);
        }, 'user-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        $request->validate([
            'users_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $handle = fopen($request->file('users_file')->getRealPath(), 'r');
        abort_unless($handle !== false, 422);

        $header = fgetcsv($handle);
        abort_unless(is_array($header), 422);

        $columns = collect($header)
            ->mapWithKeys(fn ($column, $index) => [strtolower(trim((string) $column)) => $index]);
        $requiredColumns = [
            'first_name',
            'last_name',
            'email',
            'university_role',
            'temporary_password',
        ];

        foreach ($requiredColumns as $requiredColumn) {
            abort_unless($columns->has($requiredColumn), 422, __("Missing CSV column: :column", ['column' => $requiredColumn]));
        }

        $imported = 0;
        $skipped = 0;
        $roleValues = array_column(QualityRolePermission::roleOptions(), 'value');

        while (($row = fgetcsv($handle)) !== false) {
            $value = fn (string $column): string => $columns->has($column)
                ? trim((string) ($row[$columns[$column]] ?? ''))
                : '';
            $data = [
                'first_name' => $value('first_name'),
                'last_name' => $value('last_name'),
                'email' => strtolower($value('email')),
                'staff_id' => $value('staff_id') ?: null,
                'university_role' => $value('university_role'),
                'temporary_password' => $value('temporary_password'),
                'school_code' => $value('school_code'),
                'department_code' => $value('department_code'),
            ];

            if ($data['first_name'] === '' && $data['last_name'] === '' && $data['email'] === '') {
                continue;
            }

            $validator = Validator::make($data, [
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'staff_id' => ['nullable', 'string', 'max:50', Rule::unique('users', 'staff_id')],
                'university_role' => ['required', Rule::in($roleValues)],
                'temporary_password' => ['required', Password::defaults()],
            ]);

            if ($validator->fails()) {
                $skipped++;
                continue;
            }

            $school = $data['school_code'] !== ''
                ? School::query()->where('code', $data['school_code'])->first()
                : null;
            $department = $data['department_code'] !== ''
                ? Department::query()
                    ->where('code', $data['department_code'])
                    ->when($school, fn ($query) => $query->where('school_id', $school->id))
                    ->first()
                : null;
            $permissions = QualityRolePermission::permissionsFor($data['university_role']);
            $needsSchool = in_array('view_school_reports', $permissions, true);
            $needsDepartment = $data['university_role'] === UniversityRole::Lecturer->value
                || array_intersect($permissions, ['view_department_reports', 'manage_course_assignments']);

            if (
                ($data['school_code'] !== '' && ! $school)
                || ($data['department_code'] !== '' && ! $department)
                ||
                ($needsSchool && ! $school)
                || ($needsDepartment && ! $department)
                || ($school && $department && $school->id !== $department->school_id)
            ) {
                $skipped++;
                continue;
            }

            if ($department) {
                $school = $department->school;
            }

            $user = User::create([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['email'],
                'staff_id' => $data['staff_id'],
                'password' => Hash::make($data['temporary_password']),
                'must_change_password' => true,
                'email_verified_at' => now(),
                'university_role' => $data['university_role'],
                'school_id' => $school?->id,
                'department_id' => $department?->id,
            ]);

            app(CreateTeam::class)->handle($user, "{$user->name}'s Team", true);
            $imported++;
        }

        fclose($handle);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count users imported; :skipped rows skipped.', [
                'count' => $imported,
                'skipped' => $skipped,
            ]),
        ]);

        return to_route('quality.users.index');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        [$validated, $schoolId, $departmentId] = $this->validatedUserData($request, $user);

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'staff_id' => $validated['staff_id'] ?? null,
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
            'must_change_password' => true,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password reset.')]);

        return to_route('quality.users.index');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);
        abort_if($user->is($request->user()), 422, __('You cannot deactivate your own account.'));

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user->update(['is_active' => $validated['is_active']]);

        if (! $user->is_active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $user->is_active ? __('Account activated.') : __('Account deactivated.'),
        ]);

        return to_route('quality.users.index');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);
        abort_if($user->is($request->user()), 422, __('You cannot delete your own account.'));

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('quality.users.index');
    }

    /**
     * @return array{0: array<string, mixed>, 1: int|null, 2: int|null}
     */
    private function validatedUserData(Request $request, ?User $user = null): array
    {
        $validated = $request->validate([
            'first_name' => ['required_without:name', 'nullable', 'string', 'max:100'],
            'last_name' => ['required_without:name', 'nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'staff_id' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'staff_id')->ignore($user),
            ],
            'university_role' => ['required', 'string', Rule::in(array_column(QualityRolePermission::roleOptions(), 'value'))],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'password' => [$user ? 'sometimes' : 'required', 'confirmed', Password::defaults()],
        ]);

        $validated['name'] = filled($validated['first_name'] ?? null)
            || filled($validated['last_name'] ?? null)
            ? trim(($validated['first_name'] ?? '').' '.($validated['last_name'] ?? ''))
            : $validated['name'];

        $role = $validated['university_role'];
        $permissions = QualityRolePermission::permissionsFor($role);
        $schoolId = isset($validated['school_id']) ? (int) $validated['school_id'] : null;
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;

        if (in_array('view_school_reports', $permissions, true)) {
            $request->validate([
                'school_id' => ['required', 'integer', Rule::exists('schools', 'id')],
            ]);

            $schoolId = (int) $validated['school_id'];
        }

        $needsDepartment = $role === UniversityRole::Lecturer->value
            || array_intersect($permissions, ['view_department_reports', 'manage_course_assignments']);

        if ($needsDepartment) {
            $request->validate([
                'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            ]);

            $department = Department::findOrFail($validated['department_id']);
            $schoolId = $department->school_id;
            $departmentId = $department->id;
        }

        if ($departmentId && ! $needsDepartment) {
            $department = Department::findOrFail($departmentId);
            $schoolId = $department->school_id;
        }

        return [$validated, $schoolId, $departmentId];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
