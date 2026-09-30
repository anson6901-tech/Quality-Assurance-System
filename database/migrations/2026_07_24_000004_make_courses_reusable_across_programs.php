<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('course_id')->constrained()->cascadeOnDelete();
        });

        DB::table('course_offerings')->orderBy('id')->each(function (object $offering): void {
            $programId = DB::table('courses')->where('id', $offering->course_id)->value('program_id');
            DB::table('course_offerings')->where('id', $offering->id)->update(['program_id' => $programId]);
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->unique(['course_id', 'program_id', 'semester_id']);
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropUnique(['course_id', 'semester_id']);
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('course_id')->constrained()->nullOnDelete();
        });

        DB::table('course_lecturer')->orderBy('id')->each(function (object $assignment): void {
            $programId = DB::table('courses')->where('id', $assignment->course_id)->value('program_id');
            DB::table('course_lecturer')->where('id', $assignment->id)->update(['program_id' => $programId]);
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->unique(['course_id', 'program_id', 'lecturer_id', 'semester_id'], 'course_program_lecturer_semester_unique');
        });

        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->dropUnique(['course_id', 'lecturer_id', 'semester_id']);
        });
    }

    public function down(): void
    {
        Schema::table('course_lecturer', function (Blueprint $table) {
            $table->dropUnique('course_program_lecturer_semester_unique');
            $table->unique(['course_id', 'lecturer_id', 'semester_id']);
            $table->dropConstrainedForeignId('program_id');
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropUnique(['course_id', 'program_id', 'semester_id']);
            $table->unique(['course_id', 'semester_id']);
            $table->dropConstrainedForeignId('program_id');
        });
    }
};
