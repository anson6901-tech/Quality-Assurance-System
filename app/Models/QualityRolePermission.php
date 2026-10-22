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
            'manage_course_assignments' => 'Assign lecturers to courses in their department',
            'view_reports' => 'View the report module',
            'upload_reports' => 'Upload lecturer evaluation reports',
            'view_approval_queue' => 'View reports waiting for approval',
            'review_reports' => 'Review reports as a QA supervisor',
            'approve_reports' => 'Approve and unapprove reports',
            'view_area_reports' => 'View all approved reports in their placement area',
            'view_own_reports' => 'View own approved lecturer reports',
            'view_print_queue' => 'Access print queue',
            'view_audit_logs' => 'View audit logs',
            'manage_email_settings' => 'Configure email sending (Microsoft 365) and notifications',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function defaults(): array
    {
        return [
            UniversityRole::QaOfficer->value => ['view_reports', 'upload_reports'],
            UniversityRole::QaSupervisor->value => ['view_reports', 'view_approval_queue', 'review_reports'],
            UniversityRole::QaDirector->value => ['manage_users', 'manage_structure', 'view_reports', 'upload_reports', 'view_approval_queue', 'approve_reports', 'view_area_reports', 'view_print_queue', 'view_audit_logs'],
            UniversityRole::IctAdmin->value => [
                'manage_users',
                'manage_structure',
                'manage_course_assignments',
                'view_reports',
                'upload_reports',
                'view_approval_queue',
                'review_reports',
                'approve_reports',
                'view_area_reports',
                'view_own_reports',
                'view_print_queue',
                'view_audit_logs',
                'manage_email_settings',
            ],
            UniversityRole::Dean->value => ['view_area_reports', 'view_own_reports', 'view_print_queue'],
            UniversityRole::Hod->value => ['manage_course_assignments', 'view_area_reports', 'view_own_reports', 'view_print_queue'],
            UniversityRole::Lecturer->value => ['view_own_reports'],
            UniversityRole::AppointmentCommittee->value => ['view_area_reports', 'view_print_queue'],
        ];
    }

    /**
     * @return array<int, array{label: string, description: string, permissions: array<int, array{value: string, label: string}>}>
     */
    public static function permissionGroups(): array
    {
        $permissions = self::availablePermissions();

        return collect([
            [
                'label' => 'Upload Reports',
                'description' => 'Open assigned courses and use report upload and submission actions',
                'permissions' => ['view_reports', 'upload_reports'],
            ],
            [
                'label' => 'Review Workflow',
                'description' => 'Use supervisor review, return, forward, approve, and publish actions',
                'permissions' => ['view_approval_queue', 'review_reports', 'approve_reports'],
            ],
            [
                'label' => 'Published Reports',
                'description' => 'Open published reports and define which report records are visible',
                'permissions' => ['view_print_queue', 'view_area_reports', 'view_own_reports'],
            ],
            [
                'label' => 'User Management',
                'description' => 'Create, edit, activate, deactivate, delete, and assign roles to users',
                'permissions' => ['manage_users'],
            ],
            [
                'label' => 'Academic Structure',
                'description' => 'Create and maintain schools, departments, programs, courses, and academic years',
                'permissions' => ['manage_structure'],
            ],
            [
                'label' => 'Course Assignments',
                'description' => 'Mount courses and assign, split, or combine lecturer course offerings',
                'permissions' => ['manage_course_assignments'],
            ],
            [
                'label' => 'Audit Logs',
                'description' => 'Open and export the record of administrative actions',
                'permissions' => ['view_audit_logs'],
            ],
            [
                'label' => 'Email Settings',
                'description' => 'Connect Microsoft 365 email and choose which notifications are sent',
                'permissions' => ['manage_email_settings'],
            ],
        ])
            ->map(fn (array $group): array => [
                'label' => $group['label'],
                'description' => $group['description'],
                'permissions' => collect($group['permissions'])
                    ->map(fn (string $permission): array => [
                        'value' => $permission,
                        'label' => $permissions[$permission],
                    ])
                    ->all(),
            ])
            ->all();
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
                self::syncSystemRole($record, $role);
            } elseif (! $record->wasRecentlyCreated) {
                self::syncSystemRole($record, $role);
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
            ->filter(fn (self $role): bool => ! $role->is_system && ! UniversityRole::tryFrom($role->role))
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
            self::syncSystemRole($record, $systemRole);
        } elseif ($systemRole && ! $record->wasRecentlyCreated) {
            self::syncSystemRole($record, $systemRole);
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

    private static function syncSystemRole(self $record, UniversityRole $role): void
    {
        $record->forceFill([
            'label' => $role->label(),
            'permissions' => $record->permissions ?? self::defaults()[$role->value] ?? [],
            'is_system' => true,
        ])->save();
    }
}
