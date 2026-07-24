<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->string('level')->nullable()->after('semester_id');
        });

        DB::table('course_offerings')->orderBy('id')->each(function (object $offering): void {
            $level = DB::table('courses')->where('id', $offering->course_id)->value('level');
            DB::table('course_offerings')->where('id', $offering->id)->update(['level' => $level]);
        });
    }

    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
