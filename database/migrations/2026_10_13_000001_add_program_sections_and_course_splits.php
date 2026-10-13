<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->boolean('is_weekend')->default(false);
            $table->timestamps();

            $table->unique(['program_id', 'type', 'is_weekend']);
        });

        Schema::create('course_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 30);
            $table->timestamps();

            $table->unique(['course_id', 'semester_id']);
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('program_id')->constrained()->restrictOnDelete();
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->unique(
                ['course_id', 'program_id', 'section_id', 'lecturer_id', 'semester_id'],
                'course_program_section_lecturer_semester_unique',
            );
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->dropUnique('course_program_lecturer_semester_unique');
        });

        Schema::table('quality_reports', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('program_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quality_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->unique(['course_id', 'program_id', 'lecturer_id', 'semester_id'], 'course_program_lecturer_semester_unique');
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->dropUnique('course_program_section_lecturer_semester_unique');
            $table->dropConstrainedForeignId('section_id');
        });

        Schema::dropIfExists('course_splits');
        Schema::dropIfExists('sections');
    }
};
