<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email is configured from the app rather than from .env, so ICT can change it without
     * touching the server. It sends through either Microsoft 365 (Microsoft Graph) or a
     * Gmail account (SMTP with an app password).
     */
    public function up(): void
    {
        Schema::create('email_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 20)->default('microsoft365');
            $table->string('tenant_id')->nullable();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->string('sender_address')->nullable();
            $table->string('gmail_address')->nullable();
            $table->text('gmail_app_password')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('app_url')->nullable();
            $table->boolean('send_welcome')->default(true);
            $table->boolean('send_review_requests')->default(true);
            $table->boolean('send_report_published')->default(true);
            $table->boolean('send_report_returned')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_succeeded')->nullable();
            $table->text('last_test_message')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('quality_role_permissions')->where('role', 'ict_admin')->orderBy('id')->each(function (object $role): void {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];

            if (! in_array('manage_email_settings', $permissions, true)) {
                $permissions[] = 'manage_email_settings';
                DB::table('quality_role_permissions')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('quality_role_permissions')->orderBy('id')->each(function (object $role): void {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];
            DB::table('quality_role_permissions')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_diff($permissions, ['manage_email_settings']))),
            ]);
        });

        Schema::dropIfExists('email_settings');
    }
};
