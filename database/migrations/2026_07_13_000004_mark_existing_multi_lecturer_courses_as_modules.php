<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $courseIds = DB::table('course_lecturer')
            ->select('course_id')
            ->groupBy('course_id', 'semester_id')
            ->havingRaw('COUNT(DISTINCT lecturer_id) > 1')
            ->pluck('course_id');

        DB::table('courses')->whereIn('id', $courseIds)->update(['course_type' => 'module']);
    }

    public function down(): void
    {
        // Existing course type choices should be preserved when rolling back.
    }
};
