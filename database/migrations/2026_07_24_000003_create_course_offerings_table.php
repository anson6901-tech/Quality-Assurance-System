<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['course_id', 'semester_id']);
        });

        DB::table('course_lecturer')
            ->whereNotNull('semester_id')
            ->select(['course_id', 'semester_id'])
            ->distinct()
            ->orderBy('course_id')
            ->each(fn (object $assignment) => DB::table('course_offerings')->insertOrIgnore([
                'course_id' => $assignment->course_id,
                'semester_id' => $assignment->semester_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('course_offerings');
    }
};
