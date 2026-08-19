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
        Schema::create('deal_room_documents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('milestone_id');
            $table->unsignedBigInteger('uploaded_by');

            $table->enum('document_type', [
                'approved_budget',
                'supplier_invoice',
                'supplier_quotation',
                'payment_receipt',
                'delivery_confirmation',
                'completion_photo',
                'completion_video',
                'delivery_note',
                'completion_report',
                'communication',
                'other',
            ]);

            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->text('description')->nullable();

            // 3-way visibility (section 10)
            $table->tinyInteger('visible_to_program_owner')->default(1);
            $table->tinyInteger('visible_to_business_owner')->default(1);
            $table->tinyInteger('visible_to_supplier')->default(0);     // opt-in for suppliers

            $table->foreign('milestone_id')
                ->references('id')->on('program_milestones')->onDelete('restrict');
            $table->foreign('uploaded_by')
                ->references('id')->on('users')->onDelete('restrict');

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deal_room_documents');
    }
};
