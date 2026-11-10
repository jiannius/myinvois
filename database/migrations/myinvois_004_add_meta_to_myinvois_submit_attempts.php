<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who closed an attempt by hand and why (Myinvois::releasePendingAttempts()): the
     * caller's meta (e.g. user_id, reason) plus released_at.
     */
    public function up(): void
    {
        if (! Schema::hasTable('myinvois_submit_attempts')) return;
        if (Schema::hasColumn('myinvois_submit_attempts', 'meta')) return;

        Schema::table('myinvois_submit_attempts', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('closed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('myinvois_submit_attempts')) return;
        if (! Schema::hasColumn('myinvois_submit_attempts', 'meta')) return;

        Schema::table('myinvois_submit_attempts', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
