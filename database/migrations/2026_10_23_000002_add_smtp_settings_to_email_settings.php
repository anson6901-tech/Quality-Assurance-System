<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Microsoft 365 sends through SMTP set up like the UHAS Keycloak mail settings (host, port,
     * SSL or StartTLS, optional authentication by password or OAuth 2.0 token, Allow UTF-8), or
     * through the Microsoft Graph API.
     */
    public function up(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            $table->string('microsoft_method', 10)->default('smtp')->after('provider');
            $table->string('smtp_host')->default('smtp.office365.com')->after('microsoft_method');
            $table->unsignedInteger('smtp_port')->default(587)->after('smtp_host');
            $table->string('smtp_encryption', 10)->default('starttls')->after('smtp_port');
            $table->boolean('smtp_auth')->default(true)->after('smtp_encryption');
            $table->string('smtp_username')->nullable()->after('smtp_auth');
            $table->string('smtp_auth_type', 10)->default('token')->after('smtp_username');
            $table->text('smtp_password')->nullable()->after('smtp_auth_type');
            $table->string('token_url')->nullable()->after('smtp_password');
            $table->string('token_scope')->default('https://outlook.office365.com/.default')->after('token_url');
            $table->boolean('smtp_allow_utf8')->default(true)->after('token_scope');
        });

        DB::table('email_settings')->orderBy('id')->each(function (object $settings): void {
            DB::table('email_settings')->where('id', $settings->id)->update([
                'smtp_username' => $settings->sender_address,
                'token_url' => $settings->tenant_id
                    ? 'https://login.microsoftonline.com/'.$settings->tenant_id.'/oauth2/v2.0/token'
                    : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            $table->dropColumn([
                'microsoft_method', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_auth',
                'smtp_username', 'smtp_auth_type', 'smtp_password', 'token_url', 'token_scope', 'smtp_allow_utf8',
            ]);
        });
    }
};
