<?php

use App\Enums\UniversityRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'test@example.com')
            ->update(['university_role' => UniversityRole::QaDirector->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->where('email', 'test@example.com')
            ->update(['university_role' => UniversityRole::QaOfficer->value]);
    }
};
