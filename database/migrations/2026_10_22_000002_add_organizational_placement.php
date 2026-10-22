<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles keep deciding what a user can do; placement on the organizational structure
     * (university, school or department) now decides where they can see.
     */
    private const UNIVERSITY_ROLES = ['ict_admin', 'qa_director', 'qa_supervisor', 'qa_officer', 'appointment_committee'];

    private const OLD_AREA_PERMISSIONS = ['view_all_reports', 'view_school_reports', 'view_department_reports'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('placement', 20)->nullable()->after('department_id');
        });

        DB::table('schools')->whereNotNull('dean_id')->orderBy('id')->each(function (object $school): void {
            DB::table('users')->where('id', $school->dean_id)->where('university_role', 'dean')->update([
                'school_id' => $school->id,
                'department_id' => null,
                'placement' => 'school',
            ]);
        });

        DB::table('departments')->whereNotNull('hod_id')->orderBy('id')->each(function (object $department): void {
            DB::table('users')->where('id', $department->hod_id)->where('university_role', 'hod')->update([
                'school_id' => $department->school_id,
                'department_id' => $department->id,
                'placement' => 'department',
            ]);
        });

        $universityRoles = DB::table('quality_role_permissions')
            ->get(['role', 'permissions'])
            ->filter(fn (object $role) => in_array('view_all_reports', json_decode($role->permissions ?? '[]', true) ?: [], true))
            ->pluck('role')
            ->merge(self::UNIVERSITY_ROLES)
            ->unique()
            ->all();

        DB::table('users')->whereNull('placement')->orderBy('id')->each(function (object $user) use ($universityRoles): void {
            $placement = match (true) {
                $user->department_id !== null => 'department',
                $user->school_id !== null => 'school',
                in_array($user->university_role, $universityRoles, true) => 'university',
                default => null,
            };

            $schoolId = $user->department_id !== null
                ? DB::table('departments')->where('id', $user->department_id)->value('school_id')
                : $user->school_id;

            DB::table('users')->where('id', $user->id)->update([
                'placement' => $placement,
                'school_id' => $schoolId,
            ]);
        });

        DB::table('quality_role_permissions')->orderBy('id')->each(function (object $role): void {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];
            $hadArea = array_intersect($permissions, self::OLD_AREA_PERMISSIONS) !== [] || in_array($role->role, ['dean', 'hod'], true);
            $permissions = array_values(array_diff($permissions, self::OLD_AREA_PERMISSIONS));

            if ($hadArea && ! in_array('view_area_reports', $permissions, true)) {
                $permissions[] = 'view_area_reports';
            }

            DB::table('quality_role_permissions')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dean_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hod_id');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('dean_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('hod_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('users')->where('university_role', 'dean')->where('placement', 'school')->orderBy('id')->each(function (object $user): void {
            DB::table('schools')->where('id', $user->school_id)->update(['dean_id' => $user->id]);
        });

        DB::table('users')->where('university_role', 'hod')->where('placement', 'department')->orderBy('id')->each(function (object $user): void {
            DB::table('departments')->where('id', $user->department_id)->update(['hod_id' => $user->id]);
        });

        DB::table('quality_role_permissions')->orderBy('id')->each(function (object $role): void {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];

            if (in_array('view_area_reports', $permissions, true)) {
                $permissions = array_values(array_diff($permissions, ['view_area_reports']));
                $permissions[] = match ($role->role) {
                    'dean' => 'view_school_reports',
                    'hod' => 'view_department_reports',
                    default => 'view_all_reports',
                };
            }

            DB::table('quality_role_permissions')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('placement');
        });
    }
};
