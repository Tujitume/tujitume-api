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
        Schema::create('final_approval_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_execution_id')
                ->constrained('milestone_execution_documents')
                ->cascadeOnDelete();

            $table->string('type'); // photo, video, receipt, work_log, installation_doc, report
            $table->string('file_path');
            //$table->string('original_name')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('final_approval_documents');
    }
};
