<?php

namespace App\Support;

use App\Enums\Placement;
use App\Enums\UniversityRole;
use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Validates and applies a user's position on the organizational structure. Deans head a
 * school and HODs head a department, and each unit has at most one head.
 */
class Placements
{
    /**
     * Resolves a requested placement into the user columns it implies.
     *
     * @return array{placement: Placement|null, school_id: int|null, department_id: int|null}
     */
    public static function resolve(?Placement $placement, ?int $schoolId, ?int $departmentId): array
    {
        return match ($placement) {
            Placement::University => ['placement' => $placement, 'school_id' => null, 'department_id' => null],
            Placement::School => $schoolId && School::query()->whereKey($schoolId)->exists()
                ? ['placement' => $placement, 'school_id' => $schoolId, 'department_id' => null]
                : throw ValidationException::withMessages(['school_id' => __('Choose the school to place this user in.')]),
            Placement::Department => ($department = $departmentId ? Department::query()->find($departmentId) : null)
                ? ['placement' => $placement, 'school_id' => $department->school_id, 'department_id' => $department->id]
                : throw ValidationException::withMessages(['department_id' => __('Choose the department to place this user in.')]),
            null => ['placement' => null, 'school_id' => null, 'department_id' => null],
        };
    }

    /**
     * Enforces the head-of-unit rules for a role at a resolved placement.
     *
     * @param  array{placement: Placement|null, school_id: int|null, department_id: int|null}  $target
     */
    public static function ensureHeadRules(string $role, array $target, ?User $ignore = null): void
    {
        if ($role === UniversityRole::Dean->value && $target['placement'] !== null) {
            if ($target['placement'] !== Placement::School) {
                throw ValidationException::withMessages(['placement' => __('A Dean must be placed at a school.')]);
            }

            $current = self::headOf(UniversityRole::Dean, Placement::School, 'school_id', $target['school_id'], $ignore);

            if ($current) {
                throw ValidationException::withMessages([
                    'placement' => __(':name is already the Dean of this school. Move them first.', ['name' => $current->name]),
                ]);
            }
        }

        if ($role === UniversityRole::Hod->value && $target['placement'] !== null) {
            if ($target['placement'] !== Placement::Department) {
                throw ValidationException::withMessages(['placement' => __('A Head of Department must be placed at a department.')]);
            }

            $current = self::headOf(UniversityRole::Hod, Placement::Department, 'department_id', $target['department_id'], $ignore);

            if ($current) {
                throw ValidationException::withMessages([
                    'placement' => __(':name is already the Head of this department. Move them first.', ['name' => $current->name]),
                ]);
            }
        }
    }

    /**
     * Places a user, checking that the actor may place people there.
     */
    public static function place(User $actor, User $user, ?Placement $placement, ?int $schoolId, ?int $departmentId): void
    {
        $target = self::resolve($placement, $schoolId, $departmentId);

        if (
            ! Area::contains($actor, $target['placement'], $target['school_id'], $target['department_id'])
            && $target['placement'] !== null
        ) {
            throw ValidationException::withMessages(['placement' => __('You can only place users inside your own area.')]);
        }

        self::ensureHeadRules($user->university_role, $target, $user);

        $user->forceFill($target)->save();
    }

    private static function headOf(UniversityRole $role, Placement $placement, string $column, ?int $id, ?User $ignore): ?User
    {
        return User::query()
            ->where('university_role', $role->value)
            ->where('placement', $placement->value)
            ->where($column, $id)
            ->where('is_active', true)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->first();
    }
}
