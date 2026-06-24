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
        Schema::create('milestone_budget_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('milestone_id');
            $table->unsignedBigInteger('supplier_id')->nullable();      // links item to a supplier

            $table->string('item_description', 500);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('total_cost', 15, 2);                       // unit_cost × quantity

            $table->enum('purpose_type', [
                'capex', 'opex', 'services', 'mixed',
            ])->nullable();

            $table->enum('added_by', ['grant_owner', 'applicant'])->default('grant_owner');

            $table->foreign('milestone_id')
                ->references('id')->on('grant_milestones')->onDelete('cascade');
            $table->foreign('supplier_id')
                ->references('id')->on('milestone_suppliers')->onDelete('set null');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_budget_items');
    }
};
