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
        Schema::create('capital_offers', function (Blueprint $table) {
            $table->increments('id'); // int(11) primary auto-increment
            $table->unsignedInteger('user_id')->nullable();
            $table->string('offer_title', 255);
            $table->decimal('total_capital_available', 15, 2);
            $table->float('available_amount')->nullable();
            $table->decimal('per_startup_allocation', 15, 2);
            $table->text('milestone_requirements')->nullable();
            $table->json('startup_stage')->nullable();
            $table->json('sectors')->nullable();
            $table->json('regions')->nullable();
            $table->string('impact_objectives', 700)->nullable();
            $table->string('required_docs', 200)->nullable();
            $table->string('offer_brief_file', 255)->nullable();
            $table->string('start_date', 100)->nullable();
            $table->string('end_date', 100)->nullable();
            $table->tinyInteger('visible')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_offers');
    }
};
