<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_analyses', function (Blueprint $table) {
            // The course part and assigned lecturer chosen in the report header, if any.
            $table->string('assignment_key')->nullable()->after('lecturer_id');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_analyses', function (Blueprint $table) {
            $table->dropColumn('assignment_key');
        });
    }
};
