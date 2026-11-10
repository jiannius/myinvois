<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per document and parent, written right before a submission POST leaves and
     * closed once LHDN answers definitely. A row left pending (a timeout, a 5xx, a process
     * that died) is looked up before that parent is submitted again.
     */
    public function up(): void
    {
        if (Schema::hasTable('myinvois_submit_attempts')) return;

        Schema::create('myinvois_submit_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('parent_type');
            $table->string('parent_id');
            $table->boolean('is_preprod')->default(false);
            $table->string('document_number');
            $table->string('document_type')->nullable();
            $table->boolean('is_consolidate')->default(false);
            $table->json('row_numbers')->nullable();
            $table->string('issued_at')->nullable();
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->string('submission_uid')->nullable();
            $table->string('document_uuid')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['parent_type', 'parent_id', 'is_preprod', 'status'], 'myinvois_submit_attempts_parent_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('myinvois_submit_attempts');
    }
};
