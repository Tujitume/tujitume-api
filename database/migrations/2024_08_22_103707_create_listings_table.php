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
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique()->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('name', 300)->nullable();
            $table->string('category', 255)->nullable();
            $table->string('image', 255)->nullable();
            $table->string('details', 1500)->nullable();
            $table->string('location', 300)->nullable();
            $table->string('lat', 100)->nullable();
            $table->string('lng', 100)->nullable();
            $table->string('contact', 255)->nullable();
            $table->string('contact_mail', 100)->nullable();

            $table->integer('investment_needed')->nullable();
            $table->decimal('amount_collected', 10, 2)->default(0); // NOTE: updated to decimal
            $table->integer('invest_count')->default(0)->nullable();

            $table->tinyInteger('share')->nullable();
            $table->string('y_turnover', 255)->nullable();
            $table->string('pin', 200)->nullable();
            $table->string('identification', 200)->nullable();
            $table->string('document', 200)->nullable();
            $table->string('video', 200)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('stage', 70)->nullable();
            $table->json('social_impact_areas')->nullable();
            $table->integer('investors_fee')->nullable();
            $table->string('yeary_fin_statement', 200)->nullable();
            $table->string('id_no', 255)->nullable();
            $table->string('tax_pin', 255)->nullable();

            $table->decimal('rating', 10, 2)->default(0.00);
            $table->integer('rating_count')->default(0); // NOTE: updated to integer

            $table->unsignedTinyInteger('active')->nullable()->default(null);
            $table->unsignedTinyInteger('threshold_met')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
