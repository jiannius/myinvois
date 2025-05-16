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
        if (Schema::hasTable('myinvois_documents')) return;

        Schema::create('myinvois_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('document_uuid')->nullable();
            $table->string('submission_uid')->nullable();
            $table->string('document_number')->nullable();
            $table->string('status')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->boolean('is_preprod')->nullable()->default(false);
            $table->string('parent_type')->nullable();
            $table->ulid('parent_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('myinvois_documents');
    }
};
