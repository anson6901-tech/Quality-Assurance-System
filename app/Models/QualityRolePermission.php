<?php

namespace App\Models;

use App\Enums\UniversityRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['role', 'label', 'permissions', 'is_system'])]
class QualityRolePermission extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function availablePermissions(): array
    {
        return [
            'manage_users' => 'Create users and assign roles',
            'manage_structure' => 'Manage schools, departments, courses, and academic years',
            'upload_reports' => 'Upload lecturer evaluation reports',
            'view_approval_queue' => 'View reports waiting for approval',
            'approve_reports' => 'Approve and unapprove reports',
            'view_all_reports' => 'View all approved reports',
            'view_school_reports' => 'View approved reports in assigned school',
            'view_department_reports' => 'View approved reports in assigned department',
            'view_own_reports' => 'View own approved lecturer reports',
            'view_print_queue' => 'Access print queue',
            'view_audit_logs' => 'View audit logs',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function defaults(): array
    {
        return [
            UniversityRole::QaOfficer->value => ['upload_reports', 'view_approval_queue'],
            UniversityRole::QaDirector->value => ['manage_users', 'manage_structure', 'upload_reports', 'view_approval_queue', 'approve_reports', 'view_all_reports', 'view_print_queue', 'view_audit_logs'],
            UniversityRole::IctAdmin->value => ['manage_users', 'manage_structure', 'view_all_reports', 'view_print_queue', 'view_audit_logs'],
            UniversityRole::Dean->value => ['view_school_reports', 'view_print_queue'],
            UniversityRole::Hod->value => ['view_department_reports', 'view_print_queue'],
            UniversityRole::Lecturer->value => ['view_own_reports', 'view_print_queue'],
            UniversityRole::AppointmentCommittee->value => ['view_all_reports', 'view_print_queue'],
        ];
    }

    public static function ensureDefaults(): void
    {
        foreach (UniversityRole::cases() as $role) {
            $record = self::query()->firstOrCreate(
                ['role' => $role->value],
                [
                    'label' => $role->label(),
                    'permissions' => self::defaults()[$role->value] ?? [],
                    'is_system' => true,
                ],
            );

            if ($record->is_system) {
                $permissions = array_values(array_unique([
                    ...($record->permissions ?? []),
                    ...(self::defaults()[$role->value] ?? []),
                ]));

                $record->forceFill([
                    'label' => $role->label(),
                    'permissions' => $permissions,
                ])->save();
            }
        }
    }

    /**
     * @return array<int, array{value: string, label: string, permissions: array<int, string>}>
     */
    public static function roleOptions(): array
    {
        self::ensureDefaults();

        $records = self::query()
            ->get()
            ->keyBy('role');

        $systemRoles = collect(UniversityRole::cases())
            ->map(fn (UniversityRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
                'permissions' => $records[$role->value]?->permissions ?? self::defaults()[$role->value] ?? [],
            ]);

        $customRoles = $records
            ->filter(fn (self $role): bool => ! $role->is_system)
            ->sortBy(fn (self $role): string => $role->displayLabel())
            ->map(fn (self $role): array => [
                'value' => $role->role,
                'label' => $role->displayLabel(),
                'permissions' => $role->permissions ?? [],
            ]);

        return $systemRoles->merge($customRoles)->values()->all();
    }

    /**
     * @return array<int, string>
     */
    public static function rolesWith(string $permission): array
    {
        self::ensureDefaults();

        return self::query()
            ->get(['role', 'permissions'])
            ->filter(fn (self $role): bool => in_array($permission, $role->permissions ?? [], true))
            ->pluck('role')
            ->all();
    }

    public static function permissionsFor(UniversityRole|string|null $role): array
    {
        if (! $role) {
            return [];
        }

        $roleValue = $role instanceof UniversityRole ? $role->value : $role;
        $systemRole = UniversityRole::tryFrom($roleValue);

        $record = self::query()->firstOrCreate(
            ['role' => $roleValue],
            [
                'label' => $systemRole?->label() ?? Str::headline($roleValue),
                'permissions' => self::defaults()[$roleValue] ?? [],
                'is_system' => (bool) $systemRole,
            ],
        );

        if ($systemRole && $record->is_system) {
            $permissions = array_values(array_unique([
                ...($record->permissions ?? []),
                ...(self::defaults()[$roleValue] ?? []),
            ]));

            if ($permissions !== ($record->permissions ?? [])) {
                $record->forceFill(['permissions' => $permissions])->save();
            }
        }

        return $record->permissions ?? [];
    }

    public static function roleHas(UniversityRole|string|null $role, string $permission): bool
    {
        return in_array($permission, self::permissionsFor($role), true);
    }

    public static function labelFor(UniversityRole|string|null $role): ?string
    {
        if (! $role) {
            return null;
        }

        $roleValue = $role instanceof UniversityRole ? $role->value : $role;
        $systemRole = UniversityRole::tryFrom($roleValue);

        if ($systemRole) {
            return $systemRole->label();
        }

        return self::query()->where('role', $roleValue)->value('label') ?? Str::headline($roleValue);
    }

    public function displayLabel(): string
    {
        return $this->label
            ?? UniversityRole::tryFrom($this->role)?->label()
            ?? Str::headline($this->role);
    }
}
