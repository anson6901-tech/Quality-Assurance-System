<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_reports', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::create('quality_report_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 50);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['quality_report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_report_reviews');

        Schema::table('quality_reports', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
