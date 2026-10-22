<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bulk upload: a batch holds the PDFs uploaded for one semester; each item is one file,
     * matched to a course part and assigned lecturer before draft reports are created.
     */
    public function up(): void
    {
        Schema::create('report_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('category', 100);
            $table->timestamps();
        });

        Schema::create('report_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->string('status', 20);
            $table->string('problem')->nullable();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lecturer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('part_key')->nullable();
            $table->json('cells')->nullable();
            $table->json('candidates')->nullable();
            $table->json('report_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_import_items');
        Schema::dropIfExists('report_import_batches');
    }
};
