<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_role_permissions', function (Blueprint $table): void {
            $table->string('label')->nullable()->after('role');
            $table->boolean('is_system')->default(false)->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('quality_role_permissions', function (Blueprint $table): void {
            $table->dropColumn(['label', 'is_system']);
        });
    }
};
