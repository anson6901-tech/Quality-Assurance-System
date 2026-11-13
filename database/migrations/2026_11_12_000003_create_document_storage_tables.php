<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLES = ['ict_admin', 'qa_director', 'qa_supervisor', 'qa_officer'];

    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['parent_id', 'name']);
        });

        Schema::create('document_files', function (Blueprint $table) {
            $table->id();
            // Null means the top level of the storage.
            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['folder_id', 'name']);
        });

        // Give the new right to the roles that handle QA documents.
        DB::table('quality_role_permissions')->whereIn('role', self::ROLES)->orderBy('id')->each(function (object $role): void {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];

            if (! in_array('manage_documents', $permissions, true)) {
                $permissions[] = 'manage_documents';
                DB::table('quality_role_permissions')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('quality_role_permissions')->orderBy('id')->each(function (object $role): void {
            $permissions = json_decode($role->permissions ?? '[]', true) ?: [];
            DB::table('quality_role_permissions')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_diff($permissions, ['manage_documents']))),
            ]);
        });

        Schema::dropIfExists('document_files');
        Schema::dropIfExists('document_folders');
    }
};
