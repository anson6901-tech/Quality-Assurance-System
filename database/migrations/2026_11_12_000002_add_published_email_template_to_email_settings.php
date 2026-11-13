<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            // The "report published" email to lecturers, written on the Email Settings page.
            // Empty means the built-in wording is used.
            $table->string('published_email_subject')->nullable();
            $table->text('published_email_body')->nullable();
            $table->boolean('published_attach_pdf')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('email_settings', function (Blueprint $table) {
            $table->dropColumn(['published_email_subject', 'published_email_body', 'published_attach_pdf']);
        });
    }
};
