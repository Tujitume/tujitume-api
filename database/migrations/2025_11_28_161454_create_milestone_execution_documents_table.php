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
        Schema::create('milestone_execution_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('milestone_id');
            $table->text('execution_proof')->nullable();
            $table->integer('approve_count')->default(0);
            $table->integer('reject_count')->default(0);
            $table->integer('audit_count')->default(0);
            $table->string('status')->default('submitted');
            $table->json('voted_ids')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_execution_documents');
    }
};
