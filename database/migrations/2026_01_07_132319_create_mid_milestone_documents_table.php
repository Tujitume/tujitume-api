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
        Schema::create('mid_milestone_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mid_milestone_id')
                ->constrained('mid_milestones')
                ->cascadeOnDelete();

            $table->string('type'); // photo, video, receipt, work_log, supplier_doc, screenshot
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
        Schema::dropIfExists('mid_milestone_documents');
    }
};
