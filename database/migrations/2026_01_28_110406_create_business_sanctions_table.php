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
        Schema::create('business_sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('non_compliance_id')->nullable();

            $table->enum('tier', ['tier_1', 'tier_2', 'tier_3']);
            $table->string('reason');
            $table->timestamp('ends_at')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['business_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_sanctions');
    }
};
