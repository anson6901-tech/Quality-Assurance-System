<?php

use App\Enums\UniversityRole;
use App\Models\QualityRolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $role = QualityRolePermission::query()
            ->where('role', UniversityRole::Hod->value)
            ->first();

        if (! $role) {
            return;
        }

        $role->update([
            'permissions' => collect($role->permissions)
                ->push('view_print_queue')
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    public function down(): void
    {
        $role = QualityRolePermission::query()
            ->where('role', UniversityRole::Hod->value)
            ->first();

        if (! $role) {
            return;
        }

        $role->update([
            'permissions' => collect($role->permissions)
                ->reject(fn (string $permission): bool => $permission === 'view_print_queue')
                ->values()
                ->all(),
        ]);
    }
};
