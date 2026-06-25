<?php

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
        if (! Schema::hasTable('myinvois_documents')) return;
        if (Schema::hasColumn('myinvois_documents', 'is_inbound')) return;

        Schema::table('myinvois_documents', function (Blueprint $table) {
            // true when the e-invoice was issued by a counterparty and pulled from
            // LHDN (received), as opposed to submitted by this org (outbound).
            $table->boolean('is_inbound')->nullable()->default(false)->after('is_preprod');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('myinvois_documents', 'is_inbound')) return;

        Schema::table('myinvois_documents', function (Blueprint $table) {
            $table->dropColumn('is_inbound');
        });
    }
};
