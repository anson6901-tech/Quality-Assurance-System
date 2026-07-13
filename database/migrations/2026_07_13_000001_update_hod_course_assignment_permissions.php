<?php

use App\Enums\UniversityRole;
use App\Models\QualityRolePermission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        QualityRolePermission::query()
            ->where('role', UniversityRole::Hod->value)
            ->update([
                'permissions' => json_encode(QualityRolePermission::defaults()[UniversityRole::Hod->value]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        QualityRolePermission::query()
            ->where('role', UniversityRole::Hod->value)
            ->update([
                'permissions' => json_encode(['view_department_reports', 'view_approval_queue', 'view_print_queue']),
                'updated_at' => now(),
            ]);
    }
};
