<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('sequence');
            $table->timestamps();

            $table->unique(['academic_year_id', 'sequence']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('course_type')->default('normal')->after('title');
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->foreignId('semester_id')->nullable()->after('course_id')->constrained()->cascadeOnDelete();
            $table->unique(['course_id', 'lecturer_id', 'semester_id']);
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->dropUnique(['course_id', 'lecturer_id']);
        });

        foreach (DB::table('academic_years')->get(['id']) as $academicYear) {
            DB::table('semesters')->insert([
                ['academic_year_id' => $academicYear->id, 'name' => 'Semester 1', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['academic_year_id' => $academicYear->id, 'name' => 'Semester 2', 'sequence' => 2, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $semesterId = DB::table('semesters')
            ->join('academic_years', 'academic_years.id', '=', 'semesters.academic_year_id')
            ->where('academic_years.status', 'active')
            ->where('semesters.sequence', 1)
            ->value('semesters.id');

        if ($semesterId) {
            DB::table('course_lecturer')->update(['semester_id' => $semesterId]);
        }

        Schema::table('quality_reports', function (Blueprint $table) {
            $table->foreignId('semester_id')->nullable()->after('academic_year_id')->constrained()->nullOnDelete();
        });

        DB::table('quality_reports')->orderBy('id')->each(function ($report): void {
            $semester = DB::table('semesters')
                ->where('academic_year_id', $report->academic_year_id)
                ->where('sequence', 1)
                ->value('id');

            DB::table('quality_reports')->where('id', $report->id)->update(['semester_id' => $semester]);
        });
    }

    public function down(): void
    {
        Schema::table('quality_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('semester_id');
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->dropUnique(['course_id', 'lecturer_id', 'semester_id']);
            $table->unique(['course_id', 'lecturer_id']);
            $table->dropConstrainedForeignId('semester_id');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('course_type');
        });

        Schema::dropIfExists('semesters');
    }
};
