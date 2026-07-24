<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('quality_role_permissions')->updateOrInsert(
            ['role' => 'qa_supervisor'],
            [
                'label' => 'QA Supervisor',
                'permissions' => json_encode(['view_reports', 'view_approval_queue', 'review_reports']),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $this->removePermission('qa_officer', 'view_approval_queue');
        $this->removePermission('dean', 'view_approval_queue');
    }

    public function down(): void
    {
        DB::table('quality_role_permissions')
            ->where('role', 'qa_supervisor')
            ->delete();
    }

    private function removePermission(string $role, string $permission): void
    {
        $record = DB::table('quality_role_permissions')
            ->where('role', $role)
            ->first();

        if (! $record) {
            return;
        }

        $permissions = array_values(array_filter(
            json_decode($record->permissions, true) ?: [],
            fn (string $value): bool => $value !== $permission,
        ));

        DB::table('quality_role_permissions')
            ->where('role', $role)
            ->update([
                'permissions' => json_encode($permissions),
                'updated_at' => now(),
            ]);
    }
};
