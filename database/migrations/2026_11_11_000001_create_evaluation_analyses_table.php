<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            // Parsed data file: variables (name, label, type, value labels) and coded rows.
            $table->json('dataset');
            // Last Frequencies run: chosen variables and Statistics/Charts/Format options.
            $table->json('frequencies')->nullable();
            // analysed → waiting (for a lecturer) → drafted
            $table->string('status')->default('analysed');
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lecturer_id')->nullable()->constrained('users')->nullOnDelete();
            // Report header, editable; filled from the course when one is chosen.
            $table->string('course_code')->nullable();
            $table->string('course_title')->nullable();
            $table->string('lecturer_name')->nullable();
            $table->string('level')->nullable();
            $table->string('school_label')->nullable();
            $table->json('quality_report_ids')->nullable();
            $table->timestamp('drafted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_analyses');
    }
};
