<?php

use App\Enums\UniversityRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->timestamps();

            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'code']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('university_role')->default(UniversityRole::Lecturer->value)->after('password');
            $table->foreignId('school_id')->nullable()->after('university_role')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('school_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('school_id');
            $table->dropColumn('university_role');
        });

        Schema::dropIfExists('departments');
        Schema::dropIfExists('schools');
    }
};
