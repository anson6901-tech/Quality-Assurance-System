<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('university_role', 'upload_only')
            ->update(['university_role' => 'qa_officer']);

        DB::table('quality_role_permissions')
            ->where('role', 'upload_only')
            ->delete();
    }

    public function down(): void
    {
        DB::table('quality_role_permissions')->updateOrInsert(
            ['role' => 'upload_only'],
            [
                'label' => 'Upload Only',
                'permissions' => json_encode(['view_reports', 'upload_reports']),
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};
