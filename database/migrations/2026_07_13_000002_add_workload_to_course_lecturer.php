<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->unsignedInteger('student_count')->nullable()->after('lecturer_id');
            $table->unsignedSmallInteger('allocated_hours')->nullable()->after('student_count');
        });
    }

    public function down(): void
    {
        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->dropColumn(['student_count', 'allocated_hours']);
        });
    }
};
