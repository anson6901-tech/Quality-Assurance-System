<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('quality_role_permissions')
            ->whereIn('role', ['hod', 'dean'])
            ->orderBy('id')
            ->each(function (object $role): void {
                $permissions = json_decode($role->permissions ?: '[]', true) ?: [];

                if (! in_array('view_own_reports', $permissions, true)) {
                    $permissions[] = 'view_own_reports';
                }

                DB::table('quality_role_permissions')
                    ->where('id', $role->id)
                    ->update([
                        'permissions' => json_encode(array_values(array_unique($permissions))),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('quality_role_permissions')
            ->whereIn('role', ['hod', 'dean'])
            ->orderBy('id')
            ->each(function (object $role): void {
                $permissions = array_values(array_filter(
                    json_decode($role->permissions ?: '[]', true) ?: [],
                    fn (string $permission): bool => $permission !== 'view_own_reports',
                ));

                DB::table('quality_role_permissions')
                    ->where('id', $role->id)
                    ->update([
                        'permissions' => json_encode($permissions),
                        'updated_at' => now(),
                    ]);
            });
    }
};
