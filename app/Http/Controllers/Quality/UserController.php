<?php

namespace App\Http\Controllers\Quality;

use App\Actions\Teams\CreateTeam;
use App\Enums\Placement;
use App\Enums\UniversityRole;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmailSetting;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Support\Area;
use App\Support\Placements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
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
                ->tap(fn ($query) => Area::users($query, $request->user()))
                ->with(['school:id,name', 'department:id,school_id,name', 'department.school:id,name'])
                ->latest()
                ->get(['id', 'name', 'email', 'staff_id', 'university_role', 'school_id', 'department_id', 'placement', 'is_active', 'created_at'])
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
                        'placement' => $user->placement?->value,
                        'placementLabel' => $user->placementLabel(),
                        'isActive' => $user->is_active,
                        'isCurrentUser' => $user->is($request->user()),
                    ];
                }),
            'roles' => QualityRolePermission::roleOptions(),
            'placesNewUsers' => ! $request->user()->isPlacedAtUniversity(),
            'emailEnabled' => EmailSetting::allows('enabled'),
            'welcomeEmails' => EmailSetting::allows('send_welcome'),
            'actorPlacementLabel' => $request->user()->placementLabel(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);

        $validated = $this->validatedUserData($request);
        $target = $this->defaultPlacementFor($request->user());
        $welcome = EmailSetting::allows('send_welcome');
        $password = $welcome ? self::temporaryPassword() : $validated['password'];
        Placements::ensureHeadRules($validated['university_role'], $target);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'staff_id' => $validated['staff_id'] ?? null,
            'password' => Hash::make($password),
            'must_change_password' => true,
            'email_verified_at' => now(),
            'university_role' => $validated['university_role'],
            'school_id' => $target['school_id'],
            'department_id' => $target['department_id'],
            'placement' => $target['placement'],
        ]);

        app(CreateTeam::class)->handle($user, "{$user->name}'s Team", true);

        if ($welcome) {
            $user->notify(new WelcomeNotification($password));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => collect([
            $welcome ? __('User created. Their temporary password has been emailed to :email.', ['email' => $user->email]) : __('User created.'),
            $target['placement'] === null ? __('Place them on the Organizational Structure to give them access.') : null,
        ])->filter()->implode(' ')]);

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
        ];
        $welcome = EmailSetting::allows('send_welcome');

        if (! $welcome) {
            $requiredColumns[] = 'temporary_password';
        }

        foreach ($requiredColumns as $requiredColumn) {
            abort_unless($columns->has($requiredColumn), 422, __('Missing CSV column: :column', ['column' => $requiredColumn]));
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
                'temporary_password' => [$welcome ? 'nullable' : 'required', Password::defaults()],
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
            if (
                ($data['school_code'] !== '' && ! $school)
                || ($data['department_code'] !== '' && ! $department)
                || ($school && $department && $school->id !== $department->school_id)
            ) {
                $skipped++;

                continue;
            }

            $target = match (true) {
                $department !== null => Placements::resolve(Placement::Department, null, $department->id),
                $school !== null => Placements::resolve(Placement::School, $school->id, null),
                default => $this->defaultPlacementFor($request->user()),
            };

            if (
                ($target['placement'] !== null && ! Area::contains($request->user(), $target['placement'], $target['school_id'], $target['department_id']))
                || ! $this->passesHeadRules($data['university_role'], $target)
            ) {
                $skipped++;

                continue;
            }

            $user = User::create([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['email'],
                'staff_id' => $data['staff_id'],
                'password' => Hash::make($password = $data['temporary_password'] ?: self::temporaryPassword()),
                'must_change_password' => true,
                'email_verified_at' => now(),
                'university_role' => $data['university_role'],
                'school_id' => $target['school_id'],
                'department_id' => $target['department_id'],
                'placement' => $target['placement'],
            ]);

            app(CreateTeam::class)->handle($user, "{$user->name}'s Team", true);

            if ($welcome) {
                $user->notify(new WelcomeNotification($password));
            }

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

        $this->authorizeTarget($request, $user);
        $validated = $this->validatedUserData($request, $user);
        Placements::ensureHeadRules($validated['university_role'], [
            'placement' => $user->placement,
            'school_id' => $user->school_id,
            'department_id' => $user->department_id,
        ], $user);

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'staff_id' => $validated['staff_id'] ?? null,
            'university_role' => $validated['university_role'],
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('quality.users.index');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);
        $this->authorizeTarget($request, $user);

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

    /**
     * Emails the user a link to choose a new password, instead of an admin typing one.
     */
    public function sendResetLink(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);
        $this->authorizeTarget($request, $user);

        $status = EmailSetting::allows('enabled')
            ? PasswordBroker::broker()->sendResetLink(['email' => $user->email])
            : null;

        Inertia::flash('toast', match ($status) {
            PasswordBroker::RESET_LINK_SENT => ['type' => 'success', 'message' => __('Password reset email sent to :email.', ['email' => $user->email])],
            null => ['type' => 'error', 'message' => __('Email sending is switched off. Turn it on in Email Settings first.')],
            default => ['type' => 'error', 'message' => __($status)],
        });

        return to_route('quality.users.index');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageUsers(), 403);
        $this->authorizeTarget($request, $user);
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
        $this->authorizeTarget($request, $user);
        abort_if($user->is($request->user()), 422, __('You cannot delete your own account.'));

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('quality.users.index');
    }

    /**
     * @return array<string, mixed>
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
            'password' => [
                $user ? 'sometimes' : (EmailSetting::allows('send_welcome') ? 'nullable' : 'required'),
                'confirmed',
                Password::defaults(),
            ],
        ]);

        $validated['name'] = filled($validated['first_name'] ?? null)
            || filled($validated['last_name'] ?? null)
            ? trim(($validated['first_name'] ?? '').' '.($validated['last_name'] ?? ''))
            : $validated['name'];

        return $validated;
    }

    /**
     * @param  array{placement: Placement|null, school_id: int|null, department_id: int|null}  $target
     */
    private function passesHeadRules(string $role, array $target): bool
    {
        try {
            Placements::ensureHeadRules($role, $target);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * Letters and digits only, so it is easy to read from an email and type in.
     */
    private static function temporaryPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    /**
     * User managers placed below university level only manage users in their own area.
     */
    private function authorizeTarget(Request $request, User $user): void
    {
        abort_unless(Area::users(User::query(), $request->user())->whereKey($user->id)->exists(), 403);
    }

    /**
     * New users start unplaced when a university admin creates them (they are then placed on
     * the organizational structure); otherwise they join the creator's own unit.
     *
     * @return array{placement: Placement|null, school_id: int|null, department_id: int|null}
     */
    private function defaultPlacementFor(User $actor): array
    {
        return $actor->isPlacedAtUniversity()
            ? Placements::resolve(null, null, null)
            : Placements::resolve($actor->placement, $actor->school_id, $actor->department_id);
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
