<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_reports', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });

        DB::table('quality_reports')->orderBy('id')->each(function (object $report): void {
            $courseId = DB::table('course_quality_report')
                ->where('quality_report_id', $report->id)
                ->value('course_id');
            $programId = $courseId
                ? DB::table('courses')->where('id', $courseId)->value('program_id')
                : null;
            DB::table('quality_reports')->where('id', $report->id)->update(['program_id' => $programId]);
        });
    }

    public function down(): void
    {
        Schema::table('quality_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
        });
    }
};
