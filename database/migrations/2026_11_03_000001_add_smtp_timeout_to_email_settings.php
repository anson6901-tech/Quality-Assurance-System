<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds an adjustable SMTP timeout (Keycloak's connection/socket timeouts, in seconds), and
     * scrubs sign-in lines that an earlier version could leave in the saved test result.
     */
    public function up(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('smtp_timeout')->default(10)->after('smtp_allow_utf8');
        });

        DB::table('email_settings')
            ->where(fn ($query) => $query->where('last_test_message', 'like', '%AUTH%')->orWhere('last_test_message', 'like', '%eyJ%'))
            ->update(['last_test_message' => 'Earlier test log removed because it contained sign-in details. Run the test again to see a new log.']);
    }

    public function down(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            $table->dropColumn('smtp_timeout');
        });
    }
};
