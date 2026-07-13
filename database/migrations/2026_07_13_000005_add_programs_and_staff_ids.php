<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
            $table->unique(['department_id', 'code']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['staff_id'])->dropColumn('staff_id'));
        Schema::table('courses', fn (Blueprint $table) => $table->dropConstrainedForeignId('program_id'));
        Schema::dropIfExists('programs');
    }
};
