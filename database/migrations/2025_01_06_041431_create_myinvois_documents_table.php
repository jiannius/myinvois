<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('myinvois_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('document_uuid')->nullable();
            $table->string('submissiong_uid')->nullable();
            $table->string('document_number')->nullable();
            $table->string('status')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->datetime('polled_at')->nullable();
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
