<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('dean_id')->nullable()->after('code')->constrained('users')->nullOnDelete();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('hod_id')->nullable()->after('code')->constrained('users')->nullOnDelete();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('title');
            $table->string('level')->nullable();
            $table->unsignedTinyInteger('credit_hours')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'code']);
        });

        Schema::create('course_lecturer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'lecturer_id']);
        });

        Schema::table('quality_reports', function (Blueprint $table) {
            $table->foreignId('lecturer_id')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('course_quality_report', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_report_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'quality_report_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_quality_report');

        Schema::table('quality_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lecturer_id');
        });

        Schema::dropIfExists('course_lecturer');
        Schema::dropIfExists('courses');

        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hod_id');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dean_id');
        });
    }
};
