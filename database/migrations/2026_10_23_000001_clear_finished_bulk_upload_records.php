<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bulk upload batches no longer keep files once they become draft reports. Remove the
     * records left by the earlier behaviour; the reports and their PDFs are untouched.
     */
    public function up(): void
    {
        DB::table('report_import_items')->where('status', 'created')->delete();

        DB::table('report_import_batches')
            ->whereNotExists(fn ($query) => $query
                ->from('report_import_items')
                ->whereColumn('report_import_items.report_import_batch_id', 'report_import_batches.id'))
            ->delete();
    }

    public function down(): void
    {
        // The removed records only described finished uploads.
    }
};
